<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\CashierShiftPoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Buka/tutup shift kasir dari layar kasir (Addendum 50, PRD §18).
 *
 * Bukan prasyarat berjualan — lihat SalePoster. Shift hanya menaut transaksi
 * yang terjadi selama ia terbuka supaya bisa direkonsiliasi di akhir.
 */
class CashierShiftController extends Controller
{
    /** Gudang tidak melayani penjualan, jadi tidak buka shift juga (Addendum 59.2). */
    private const PERAN_DILARANG = [User::ROLE_GUDANG];

    private const PERAN_PENGAWAS = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    public function __construct(private CashierShiftPoster $poster) {}

    public function buka(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (in_array($user->role, self::PERAN_DILARANG, true)) {
            throw new HttpException(403, 'Peran Anda tidak berwenang membuka shift kasir.');
        }

        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'opening_cash' => ['nullable', 'numeric', 'min:0'],
        ], [], ['opening_cash' => 'kas awal']);

        $shift = $this->poster->open($user, $branch, $data);

        return back()->with('success', "Shift {$shift->shift_number} dibuka.");
    }

    public function tutup(Request $request, CashierShift $shift): RedirectResponse
    {
        $user = $request->user();

        if ($shift->cashier_id !== $user->user_id && ! in_array($user->role, self::PERAN_PENGAWAS, true)) {
            throw new HttpException(403, 'Hanya kasir pemegang shift ini, atau Manajer ke atas, yang dapat menutupnya.');
        }

        abort_unless($shift->branch->owner_id === $user->owner_id, 404);

        $data = $request->validate([
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'close_note' => ['nullable', 'string', 'max:500'],
        ], [], ['actual_cash' => 'hasil hitung kas fisik']);

        $this->poster->close($user, $shift, (float) $data['actual_cash'], $data['close_note'] ?? null);

        return back()->with('success', "Shift {$shift->shift_number} ditutup.");
    }
}

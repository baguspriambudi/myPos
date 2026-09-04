<?php

namespace App\Http\Controllers\Penjualan;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\User;
use App\Services\SalesReturnPoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Retur penjualan (Addendum 48).
 *
 * Kasir boleh memprosesnya sama seperti transaksi penjualan sehari-hari,
 * tapi HPP (`cost_at_return`) tetap disembunyikan darinya — angka itu bukan
 * harga jual, melainkan biaya perolehan (Addendum 50).
 */
class SalesReturnController extends Controller
{
    private const PERAN_INPUT = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER, User::ROLE_KASIR];

    private const PERAN_LIHAT_HARGA = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    public function __construct(private SalesReturnPoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);
        $bolehLihatHarga = in_array($user->role, self::PERAN_LIHAT_HARGA, true);

        $returns = SalesReturn::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id))
            ->with(['sale', 'items.product', 'items.uom', 'author'])
            ->orderByDesc('return_id')
            ->paginate(self::PER_PAGE)
            ->through(fn (SalesReturn $r) => [
                'id' => $r->return_id,
                'number' => $r->return_number,
                'date' => $r->transaction_date->toDateString(),
                'saleNumber' => $r->sale?->sale_number,
                'status' => $r->status,
                'refundTotal' => (float) $r->refund_total,
                'author' => $r->author?->full_name,
                'items' => $r->items->map(fn ($i) => [
                    'name' => $i->product->product_name,
                    'sku' => $i->product->sku,
                    'qty' => (float) $i->qty,
                    'uom' => $i->uom->uom_name,
                    'costAtReturn' => $bolehLihatHarga ? (float) $i->cost_at_return : null,
                    'refundAmount' => (float) $i->refund_amount,
                ])->all(),
            ]);

        $cari = trim((string) $request->string('cari'));

        return Inertia::render('penjualan/retur/index', [
            'returns' => $returns,
            'saleLookup' => $branch && $cari !== '' ? $this->cariNota($branch->branch_id, $cari) : null,
            'query' => $cari,
            'canInput' => in_array($user->role, self::PERAN_INPUT, true),
            'canSeeCost' => $bolehLihatHarga,
            'canVoid' => $bolehLihatHarga,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_INPUT, true)) {
            throw new HttpException(403, 'Petugas gudang tidak dapat memproses retur penjualan.');
        }

        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'sale_id' => ['required', 'integer'],
            'transaction_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ], [], [
            'transaction_date' => 'tanggal retur',
            'lines' => 'barang yang diretur',
        ]);

        $return = $this->poster->post($user, $branch, $data);

        return back()->with('success', "Retur {$return->return_number} tersimpan dan stok sudah kembali.");
    }

    public function void(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_LIHAT_HARGA, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat membatalkan retur.');
        }

        abort_unless($salesReturn->branch->owner_id === $user->owner_id, 404);

        $this->poster->void($user, $salesReturn);

        return back()->with('success', "Retur {$salesReturn->return_number} dibatalkan; stoknya ditarik kembali.");
    }

    /** @return array<string, mixed> */
    private function cariNota(int $branchId, string $nomor): array
    {
        $sale = Sale::where('branch_id', $branchId)
            ->posted()
            ->where('sale_number', 'like', "%{$nomor}%")
            ->with(['items.product', 'items.uom', 'customer'])
            ->orderByDesc('sale_id')
            ->first();

        if ($sale === null) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'id' => $sale->sale_id,
            'number' => $sale->sale_number,
            'date' => $sale->transaction_date->toDateString(),
            'customer' => $sale->customer?->customer_name,
            'items' => $sale->items->map(function ($i) {
                $factor = (float) $i->uom->conversion_factor;
                $sisaBase = round((float) $i->qty_base - SalesReturnItem::sudahDiretur($i->sale_item_id), 4);

                return [
                    'saleItemId' => $i->sale_item_id,
                    'name' => $i->product->product_name,
                    'sku' => $i->product->sku,
                    'uom' => $i->uom->uom_name,
                    'qty' => (float) $i->qty,
                    'sisa' => $factor > 0 ? round($sisaBase / $factor, 4) : 0,
                ];
            })->values()->all(),
        ];
    }
}

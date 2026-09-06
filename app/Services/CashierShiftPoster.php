<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuka dan menutup shift kasir (Addendum 50, PRD §18).
 *
 * Satu kasir hanya boleh punya satu shift OPEN dalam satu lokasi. Opening
 * cash rollover otomatis dari actual_cash shift terakhir yang CLOSED di
 * lokasi yang sama — uang di laci memang tidak berpindah hanya karena shift
 * berganti — kecuali diisi manual.
 */
class CashierShiftPoster
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
        private CashDifferenceResolver $differences,
    ) {}

    /**
     * @param  array{opening_cash?: float|null}  $payload
     */
    public function open(User $user, Branch $branch, array $payload): CashierShift
    {
        $owner = Owner::findOrFail($user->owner_id);

        if (! $branch->can_sell) {
            throw ValidationException::withMessages([
                'branch_id' => "{$branch->branch_name} bukan lokasi penjualan sehingga tidak dapat membuka shift.",
            ]);
        }

        $existing = CashierShift::where('branch_id', $branch->branch_id)
            ->where('cashier_id', $user->user_id)
            ->where('status', CashierShift::STATUS_OPEN)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages(['shift' => 'Anda sudah punya shift terbuka di lokasi ini.']);
        }

        $date = now()->toDateString();

        return DB::transaction(function () use ($owner, $user, $branch, $payload, $date) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);

            $terakhir = CashierShift::where('branch_id', $branch->branch_id)
                ->where('status', CashierShift::STATUS_CLOSED)
                ->orderByDesc('shift_id')
                ->first();

            $inputManual = $payload['opening_cash'] ?? null;
            $openingCash = $inputManual !== null
                ? round((float) $inputManual, 2)
                : round((float) ($terakhir?->actual_cash ?? 0), 2);
            $source = $inputManual !== null || $terakhir === null
                ? CashierShift::SOURCE_MANUAL
                : CashierShift::SOURCE_ROLLOVER;

            $shift = CashierShift::create([
                'shift_number' => $this->numbers->next($owner->owner_id, $branch, $period, CashierShift::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'cashier_id' => $user->user_id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'opening_source' => $source,
                'status' => CashierShift::STATUS_OPEN,
            ]);

            CashTransaction::create([
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'shift_id' => $shift->shift_id,
                'transaction_date' => $date,
                'txn_type' => CashTransaction::TYPE_OPENING_CASH,
                'amount' => $openingCash,
                'created_by' => $user->user_id,
            ]);

            return $shift;
        });
    }

    public function close(User $user, CashierShift $shift, float $actualCash, ?string $note = null): CashierShift
    {
        if ($shift->status !== CashierShift::STATUS_OPEN) {
            throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
        }

        return DB::transaction(function () use ($user, $shift, $actualCash, $note) {
            $expected = (float) CashTransaction::where('shift_id', $shift->shift_id)->sum('amount');
            $actual = round($actualCash, 2);

            $shift->update([
                'closed_at' => now(),
                'expected_cash' => round($expected, 2),
                'actual_cash' => $actual,
                'difference' => round($actual - $expected, 2),
                'close_note' => $note,
                'status' => CashierShift::STATUS_CLOSED,
                'closed_by' => $user->user_id,
            ]);

            // Selisih uang laci menjadi perkara tersendiri yang harus
            // diselesaikan orang, bukan angka yang berhenti di kolom shift
            // (Addendum 46). Tanpa baris ini, selisih tidak pernah muncul di
            // pemeriksaan pra-tutup periode.
            $this->differences->catatDariShift($shift->fresh());

            return $shift->fresh();
        });
    }
}

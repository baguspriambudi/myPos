<?php

namespace App\Services;

use App\Models\CashDifference;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyelesaikan selisih kas shift (Addendum 46).
 *
 * Apa pun cara penyelesaiannya, satu hal selalu terjadi: buku kas disamakan
 * dengan uang fisik lewat satu baris `cash_transactions`. Yang membedakan
 * keempat pilihan adalah PENGAKUANNYA — ditanggung kasir, diakui biaya, diakui
 * pendapatan lain, atau sekadar penyesuaian — dan itu tersimpan di
 * `resolution_type` beserta alasannya.
 *
 * Piutang karyawan untuk CASHIER_CHARGE belum ada di sistem ini; sampai ada,
 * pilihan itu berhenti sebagai catatan pengakuan, bukan tagihan yang berjalan
 * sendiri. Disebut di sini supaya tidak dikira sudah lengkap.
 *
 * Baris kas dicatat pada TANGGAL PENYELESAIAN, bukan tanggal shift — uangnya
 * memang baru disepakati hari ini, dan period shift-nya mungkin sudah ditutup
 * (pola yang sama dipakai seluruh pembatalan di aplikasi ini, Addendum 41.3).
 */
class CashDifferenceResolver
{
    public function __construct(private FiscalPeriodResolver $periods) {}

    /**
     * Catat selisih saat shift ditutup. Nol berarti tidak ada apa-apa untuk dicatat.
     */
    public function catatDariShift(CashierShift $shift): ?CashDifference
    {
        $selisih = round((float) $shift->difference, 2);

        if ($selisih === 0.0) {
            return null;
        }

        return CashDifference::create([
            'fiscal_period_id' => $shift->fiscal_period_id,
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->shift_id,
            'expected_cash' => $shift->expected_cash,
            'actual_cash' => $shift->actual_cash,
            'difference' => $selisih,
            'status' => CashDifference::STATUS_OPEN,
            'note' => $shift->close_note,
        ]);
    }

    public function selesaikan(User $user, CashDifference $difference, string $resolusi, string $catatan): CashDifference
    {
        if ($difference->status !== CashDifference::STATUS_OPEN) {
            throw ValidationException::withMessages(['difference' => 'Selisih ini sudah diselesaikan.']);
        }

        if (! in_array($resolusi, CashDifference::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution_type' => 'Cara penyelesaian tidak dikenal.']);
        }

        if (trim($catatan) === '') {
            throw ValidationException::withMessages(['note' => 'Alasan penyelesaian wajib diisi.']);
        }

        // Kasir tidak boleh memutihkan selisihnya sendiri (Addendum 46.2).
        if ($difference->shift?->cashier_id === $user->user_id) {
            throw ValidationException::withMessages([
                'difference' => 'Selisih shift Anda sendiri harus diselesaikan atasan.',
            ]);
        }

        $selisih = round((float) $difference->difference, 2);
        $this->pastikanArahMasukAkal($resolusi, $selisih);

        $tanggal = now()->toDateString();

        return DB::transaction(function () use ($user, $difference, $resolusi, $catatan, $selisih, $tanggal) {
            $period = $this->periods->forDate($user->owner_id, $difference->branch_id, $tanggal);

            CashTransaction::create([
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $difference->branch_id,
                // Sengaja tidak ditaut ke shift mana pun: shift asalnya sudah
                // ditutup dan angkanya beku, sedangkan shift yang sedang
                // berjalan tidak ada urusannya dengan selisih ini.
                'shift_id' => null,
                'transaction_date' => $tanggal,
                'txn_type' => $selisih > 0 ? CashTransaction::TYPE_CASH_IN : CashTransaction::TYPE_CASH_OUT,
                'amount' => $selisih,
                'reference_type' => 'CASH_DIFFERENCE',
                'reference_id' => $difference->difference_id,
                'note' => "Penyelesaian selisih kas ({$resolusi}): {$catatan}",
                'created_by' => $user->user_id,
            ]);

            $difference->update([
                'status' => CashDifference::STATUS_RESOLVED,
                'resolution_type' => $resolusi,
                'resolved_at' => now(),
                'resolved_by' => $user->user_id,
                'note' => $catatan,
            ]);

            return $difference->fresh(['shift.cashier', 'resolver']);
        });
    }

    /** Kelebihan tidak bisa jadi biaya, dan kekurangan tidak bisa jadi pendapatan. */
    private function pastikanArahMasukAkal(string $resolusi, float $selisih): void
    {
        $hanyaKurang = [CashDifference::RESOLUTION_CASHIER, CashDifference::RESOLUTION_EXPENSE];

        if ($selisih > 0 && in_array($resolusi, $hanyaKurang, true)) {
            throw ValidationException::withMessages([
                'resolution_type' => 'Kas berlebih tidak dapat dibebankan ke kasir atau diakui sebagai biaya.',
            ]);
        }

        if ($selisih < 0 && $resolusi === CashDifference::RESOLUTION_INCOME) {
            throw ValidationException::withMessages([
                'resolution_type' => 'Kas kurang tidak dapat diakui sebagai pendapatan lain.',
            ]);
        }
    }
}

<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\OpeningBalance;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membatalkan nota penjualan yang sudah POSTED (Addendum 41).
 *
 * Void bukan hapus. Notanya tetap ada, nomornya tetap terpakai, movement
 * aslinya tetap tercatat — yang ditambahkan adalah pembalikannya. Toko yang
 * notanya bisa lenyap tanpa jejak tidak akan pernah bisa membuktikan apa pun
 * saat angkanya dipersoalkan.
 *
 * Void hanya boleh pada period yang SAMA dengan transaksi aslinya dan period
 * itu harus masih terbuka. Membalik transaksi ke period yang sudah ditutup
 * berarti mengubah angka yang sudah dilaporkan.
 */
class SaleVoider
{
    public function void(User $user, Sale $sale, string $reason): Sale
    {
        $owner = Owner::findOrFail($user->owner_id);
        $reason = trim($reason);

        if ($sale->status !== Sale::STATUS_POSTED) {
            throw ValidationException::withMessages([
                'sale' => $sale->status === Sale::STATUS_VOID
                    ? 'Nota ini sudah dibatalkan sebelumnya.'
                    : 'Hanya nota berstatus POSTED yang dapat dibatalkan.',
            ]);
        }

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'void_reason' => 'Alasan pembatalan wajib diisi dan tercatat permanen pada nota.',
            ]);
        }

        $period = $sale->period;

        if (! in_array($period->status, FiscalPeriodResolver::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'sale' => "Periode {$period->period_name} berstatus {$period->status}; pembatalan hanya dapat dilakukan selama periodenya masih terbuka.",
            ]);
        }

        // Refund dicatat pada TANGGAL VOID, bukan tanggal nota (Addendum 41.3).
        // Karena void wajib satu period dengan notanya, tanggal itu harus jatuh
        // di dalam rentang period yang sama.
        $voidDate = now()->toDateString();

        if ($voidDate < $period->start_date->toDateString() || $voidDate > $period->end_date->toDateString()) {
            throw ValidationException::withMessages([
                'sale' => "Hari ini di luar periode {$period->period_name}. Pembatalan hanya boleh dilakukan pada periode yang sama dengan notanya.",
            ]);
        }

        // Porsi tunai wajib cek saldo kas SEBELUM diproses (Addendum 41.4). Void
        // non-tunai tidak pernah ditolak karena laci kosong.
        $refundCash = (float) $sale->payments
            ->where('status', Payment::STATUS_POSTED)
            ->where('payment_type', Payment::TYPE_CASH)
            ->sum('amount');

        if ($refundCash > 0) {
            $this->assertCashBalance($sale->branch_id, $voidDate, $refundCash);
        }

        // Nota kredit yang sudah ada cicilannya wajib diselesaikan dulu lewat
        // pembatalan pembayaran piutang — void di sini hanya menangani piutang
        // yang masih murni belum dibayar (Addendum 41.3, acceptance §56.5).
        $receivable = Receivable::where('sale_id', $sale->sale_id)->first();

        if ($receivable !== null && (float) $receivable->paid_amount > 0) {
            throw ValidationException::withMessages([
                'sale' => 'Piutang nota ini sudah ada pembayarannya. Batalkan pembayaran piutangnya lebih dulu.',
            ]);
        }

        return DB::transaction(function () use ($owner, $user, $sale, $reason, $voidDate, $refundCash, $receivable) {
            $movements = StockMovement::where('reference_type', StockMovement::REF_SALES)
                ->where('reference_id', $sale->sale_id)
                ->where('movement_type', StockMovement::TYPE_SALES)
                ->get();

            foreach ($movements as $movement) {
                app(StockLedger::class)->reverse($owner, $movement, $voidDate, $user->user_id);
            }

            // Barang keluar SESUDAH nota ini terlanjur memakai rata-rata yang
            // masih memuat nota ini; hitung ulang HPP-nya (Addendum 41.2).
            if ($movements->isNotEmpty()) {
                app(HppRecalculator::class)->recalculate(
                    $sale->period,
                    $sale->branch_id,
                    $movements->pluck('product_id')->unique()->values()->all(),
                    HppRecalculator::TRIGGER_VOID,
                    $user,
                    StockMovement::REF_SALES,
                    $sale->sale_id,
                );
            }

            foreach ($sale->payments as $payment) {
                $payment->update([
                    'status' => Payment::STATUS_VOID,
                    'voided_at' => now(),
                    'voided_by' => $user->user_id,
                ]);
            }

            // Refund tunai dicatat pada TANGGAL VOID ke shift yang SEDANG
            // terbuka milik user ini (bukan shift asal notanya — shift itu bisa
            // saja sudah lama ditutup dan angkanya beku, Addendum 41.3).
            if ($refundCash > 0) {
                $period = $sale->period;
                $shift = CashierShift::where('branch_id', $sale->branch_id)
                    ->where('cashier_id', $user->user_id)
                    ->where('status', CashierShift::STATUS_OPEN)
                    ->first();

                CashTransaction::create([
                    'fiscal_period_id' => $period->fiscal_period_id,
                    'branch_id' => $sale->branch_id,
                    'shift_id' => $shift?->shift_id,
                    'transaction_date' => $voidDate,
                    'txn_type' => CashTransaction::TYPE_REFUND,
                    'amount' => -$refundCash,
                    'reference_type' => CashTransaction::REF_SALES,
                    'reference_id' => $sale->sale_id,
                    'created_by' => $user->user_id,
                ]);
            }

            // Piutang yang belum dibayar sama sekali dibatalkan, tanpa kas
            // keluar — pelanggan memang belum pernah membayar apa pun.
            $receivable?->update(['status' => Receivable::STATUS_VOID]);

            $sale->update([
                'status' => Sale::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $user->user_id,
                'void_reason' => $reason,
                'updated_by' => $user->user_id,
            ]);

            return $sale->fresh(['items', 'payments']);
        });
    }

    /** Saldo kas cabang per tanggal (akumulatif) harus cukup untuk refund tunai (Addendum 41.4). */
    private function assertCashBalance(int $branchId, string $date, float $refundAmount): void
    {
        $balance = $this->cashBalance($branchId, $date);

        if ($balance < $refundAmount) {
            throw ValidationException::withMessages([
                'sale' => sprintf(
                    'Saldo kas tanggal %s hanya Rp%s, kurang dari nilai refund tunai Rp%s. Void tidak dapat diproses.',
                    $date,
                    number_format($balance, 0, ',', '.'),
                    number_format($refundAmount, 0, ',', '.'),
                ),
            ]);
        }
    }

    /**
     * Saldo kas cabang sampai tanggal tertentu.
     *
     * Baris OPENING_CASH TIDAK dijumlah semuanya: opening_cash tiap shift
     * adalah uang laci yang digulirkan dari shift sebelumnya, jadi
     * menjumlahkannya menghitung uang yang sama sekali per shift — dulu saldo
     * ini membengkak dan void tunai yang seharusnya ditolak ikut lolos.
     * Rumusnya sama dengan FiscalPeriodFigures::kas(): saldo awal yang diketik
     * saat Initial Opening, ditambah seluruh mutasi selain OPENING_CASH. Bila
     * cabang tidak pernah mengisi saldo awal kas, uang laci shift PERTAMA-nya
     * yang menjadi saldo awal — itulah satu-satunya OPENING_CASH yang benar-benar
     * uang baru.
     */
    private function cashBalance(int $branchId, string $date): float
    {
        $mutasi = (float) CashTransaction::where('branch_id', $branchId)
            ->where('transaction_date', '<=', $date)
            ->where('txn_type', '!=', CashTransaction::TYPE_OPENING_CASH)
            ->sum('amount');

        $saldoAwal = OpeningBalance::where('branch_id', $branchId)
            ->where('balance_type', OpeningBalance::TYPE_CASH)
            ->get();

        if ($saldoAwal->isNotEmpty()) {
            return round($mutasi + (float) $saldoAwal->sum('amount'), 2);
        }

        $laciPertama = CashTransaction::where('branch_id', $branchId)
            ->where('transaction_date', '<=', $date)
            ->where('txn_type', CashTransaction::TYPE_OPENING_CASH)
            ->orderBy('transaction_date')
            ->orderBy('cash_txn_id')
            ->value('amount');

        return round($mutasi + (float) $laciPertama, 2);
    }
}

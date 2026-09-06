<?php

namespace App\Services;

use App\Models\ClosingAttemptLog;
use App\Models\FiscalClosing;
use App\Models\FiscalClosingItem;
use App\Models\FiscalPeriod;
use App\Models\FiscalPeriodLog;
use App\Models\ProductUom;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Menutup fiscal period dan membekukan angkanya jadi snapshot (PRD 20, 21,
 * Addendum 43, 44, 51.3).
 *
 * Jalannya sengaja dua tahap terpisah, bukan satu transaksi besar:
 *
 *   1. Transaksi pertama memasang status CLOSING dan langsung di-commit —
 *      supaya permintaan lain benar-benar melihatnya dan ditolak selama
 *      perhitungan berjalan (Addendum 43.2). Status yang hanya hidup di dalam
 *      transaksi yang belum commit tidak terlihat siapa pun.
 *   2. Transaksi kedua menghitung dan menulis snapshot. Bila gagal, seluruh
 *      isinya rollback dan status dikembalikan ke asalnya beserta catatan
 *      kegagalan (Addendum 44) — period tidak boleh tertinggal di CLOSING.
 *
 * Angkanya sendiri tidak dihitung di sini melainkan di FiscalPeriodFigures,
 * yang dipakai bersama layar laporan — supaya "laporan sebelum tutup" dan
 * "snapshot sesudah tutup" tidak pernah bisa memakai dua rumus berbeda.
 */
class FiscalPeriodCloser
{
    public function __construct(
        private PreClosingValidator $validator,
        private FiscalPeriodFigures $figures,
    ) {}

    /**
     * @param  array<int, string>  $disetujui  kunci pemeriksaan yang ditembus persetujuan OWNER
     *
     * @throws ValidationException
     */
    public function tutup(User $user, FiscalPeriod $period, array $disetujui = [], ?string $alasanOverride = null): FiscalClosing
    {
        $this->figures->lupakan($period);
        $statusAsal = $this->mulai($user, $period, $disetujui, $alasanOverride);

        try {
            return DB::transaction(fn () => $this->hitungDanSimpan($user, $period));
        } catch (Throwable $e) {
            $this->pulihkan($user, $period, $statusAsal, $e);

            throw $e;
        }
    }

    /**
     * Tahap 1: kunci period, periksa, lalu pasang status CLOSING (di-commit).
     *
     * @param  array<int, string>  $disetujui
     * @return string status sebelum CLOSING, untuk dikembalikan bila gagal
     */
    private function mulai(User $user, FiscalPeriod $period, array $disetujui, ?string $alasanOverride): string
    {
        return DB::transaction(function () use ($user, $period, $disetujui, $alasanOverride) {
            // Kunci barisnya lebih dulu: dua orang yang menekan "Tutup" pada
            // detik yang sama akan sama-sama lolos pemeriksaan status bila
            // dibaca tanpa kunci, dan period tertutup dua kali (Addendum 43.3).
            $terkunci = FiscalPeriod::whereKey($period->fiscal_period_id)->lockForUpdate()->firstOrFail();

            if ($terkunci->status === FiscalPeriod::STATUS_CLOSING) {
                throw ValidationException::withMessages(['period' => 'Penutupan periode ini sedang berjalan. Tunggu sampai selesai.']);
            }

            if (! $terkunci->menerimaTransaksi()) {
                throw ValidationException::withMessages([
                    'period' => $terkunci->status === FiscalPeriod::STATUS_CLOSED
                        ? 'Periode ini sudah ditutup.'
                        : "Periode berstatus {$terkunci->status} tidak dapat ditutup; buka dulu periodenya.",
                ]);
            }

            $checks = $this->validator->check($terkunci, $disetujui);
            $error = array_filter($checks, fn (array $c) => $c['status'] === PreClosingValidator::STATUS_ERROR);
            $blockers = array_filter($error, fn (array $c) => ! $c['approved']);

            if ($blockers !== []) {
                throw ValidationException::withMessages([
                    'period' => 'Penutupan diblokir: '.implode(' ', array_map(fn ($b) => $b['label'].'.', $blockers)),
                ]);
            }

            $ditembus = array_map(fn (array $c) => $c['label'], array_filter($error, fn (array $c) => $c['approved']));

            if ($ditembus !== []) {
                if (trim((string) $alasanOverride) === '') {
                    throw ValidationException::withMessages([
                        'override_reason' => 'Menutup periode dalam keadaan ini butuh alasan tertulis.',
                    ]);
                }

                $this->catat(
                    $terkunci,
                    FiscalPeriodLog::ACTION_OVERRIDE,
                    $terkunci->status,
                    $terkunci->status,
                    $user,
                    'Ditembus persetujuan: '.implode('; ', $ditembus).'. Alasan: '.$alasanOverride,
                );
            }

            $statusAsal = $terkunci->status;

            $terkunci->update(['status' => FiscalPeriod::STATUS_CLOSING, 'updated_by' => $user->user_id]);

            // Samakan instance milik pemanggil dengan baris yang baru ditulis.
            // Tanpa ini, "aslinya" menurut Eloquent masih OPEN, sehingga
            // mengembalikannya ke OPEN saat gagal dianggap tidak ada perubahan
            // dan tidak menghasilkan query sama sekali.
            $period->setRawAttributes($terkunci->getAttributes(), sync: true);

            $this->catat($terkunci, FiscalPeriodLog::ACTION_CLOSING, $statusAsal, FiscalPeriod::STATUS_CLOSING, $user);
            $this->langkah($terkunci, ClosingAttemptLog::STEP_VALIDATE, ClosingAttemptLog::STATUS_SUCCESS);

            return $statusAsal;
        });
    }

    /** Tahap 2: hitung seluruh angka, tulis snapshot, kunci period. */
    private function hitungDanSimpan(User $user, FiscalPeriod $period): FiscalClosing
    {
        $pembulatan = $this->figures->pembulatanHpp($period);

        $items = $this->figures->rincianProduk($period, $pembulatan['per_produk']);
        $this->langkah($period, ClosingAttemptLog::STEP_INVENTORY, ClosingAttemptLog::STATUS_SUCCESS);

        $penjualan = $this->figures->penjualan($period);
        $this->langkah($period, ClosingAttemptLog::STEP_SALES, ClosingAttemptLog::STATUS_SUCCESS);

        $kas = $this->figures->kas($period);
        $this->langkah($period, ClosingAttemptLog::STEP_CASH, ClosingAttemptLog::STATUS_SUCCESS);

        $persediaan = $this->figures->persediaan($period, $pembulatan['per_produk']);
        $cogs = round($persediaan['sales_cost_value'] - $persediaan['sales_return_cost_value'], 2);
        $grossProfit = round($penjualan['net_sales'] - $cogs, 2);

        // Snapshot lama (bila period ini pernah ditutup lalu dibuka kembali)
        // ditandai REPLACED, tidak ditimpa — jejak angka lama tetap ada tanpa
        // pernah ikut terbaca laporan (Addendum 25, 45.3).
        FiscalClosing::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', FiscalClosing::STATUS_VALID)
            ->update(['status' => FiscalClosing::STATUS_REPLACED]);

        $version = (int) FiscalClosing::where('fiscal_period_id', $period->fiscal_period_id)->max('version') + 1;

        $closing = FiscalClosing::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'status' => FiscalClosing::STATUS_VALID,
            'version' => $version,
            'opening_inventory_value' => $persediaan['opening_inventory_value'],
            'purchase_value' => $persediaan['purchase_value'],
            'purchase_return_value' => $persediaan['purchase_return_value'],
            'sales_cost_value' => $persediaan['sales_cost_value'],
            'sales_return_cost_value' => $persediaan['sales_return_cost_value'],
            'adjustment_value' => $persediaan['adjustment_value'],
            'ending_inventory_value' => $persediaan['ending_inventory_value'],
            'cogs_value' => $cogs,
            'gross_profit' => $grossProfit,
            'expense_total' => $penjualan['expense_total'],
            'net_profit' => round($grossProfit - $penjualan['expense_total'], 2),
            'opening_cash' => $kas['opening_cash'],
            'cash_sales' => $kas['cash_sales'],
            'cash_in' => $kas['cash_in'],
            'cash_out' => $kas['cash_out'],
            'refund' => $kas['refund'],
            'ending_cash' => $kas['ending_cash'],
            'cash_difference' => $kas['cash_difference'],
            'gross_sales' => $penjualan['gross_sales'],
            'discount_total' => $penjualan['discount_total'],
            'net_sales' => $penjualan['net_sales'],
            'tax_total' => $penjualan['tax_total'],
            'payment_rounding_total' => $penjualan['payment_rounding_total'],
            'payment_cash' => $penjualan['payment_cash'],
            'payment_qris' => $penjualan['payment_qris'],
            'payment_debit' => $penjualan['payment_debit'],
            'payment_credit_card' => $penjualan['payment_credit_card'],
            'payment_transfer' => $penjualan['payment_transfer'],
            'payment_ewallet' => $penjualan['payment_ewallet'],
            'closed_at' => now(),
            'closed_by' => $user->user_id,
        ]);

        foreach ($items as $row) {
            FiscalClosingItem::create(['fiscal_closing_id' => $closing->fiscal_closing_id] + $row);
        }

        foreach ($pembulatan['per_lokasi'] as $baris) {
            $this->catatPembulatan($period, $closing, $baris);
        }

        $period->update([
            'status' => FiscalPeriod::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $user->user_id,
            'updated_by' => $user->user_id,
        ]);

        $this->catat($period, FiscalPeriodLog::ACTION_CLOSED, FiscalPeriod::STATUS_CLOSING, FiscalPeriod::STATUS_CLOSED, $user, null, $closing->fiscal_closing_id);
        $this->langkah($period, ClosingAttemptLog::STEP_SNAPSHOT, ClosingAttemptLog::STATUS_SUCCESS);

        return $closing->fresh(['items']);
    }

    /**
     * Movement ROUNDING qty 0 sebesar selisih pembulatan (Addendum 51.3).
     *
     * Bertanda kebalikan dari selisihnya: bila persediaan yang benar-benar
     * keluar lebih besar daripada COGS yang tercatat, nilai persediaan di buku
     * besar kelebihan sebanyak itu dan harus dikeluarkan.
     *
     * Sengaja TIDAK lewat StockLedger dan tidak menyentuh `stock_balances`:
     * qty-nya nol sehingga tidak ada saldo yang berubah, dan `avg_cost`
     * operasional dibiarkan apa adanya.
     *
     * @param  array<string, mixed>  $baris
     */
    private function catatPembulatan(FiscalPeriod $period, FiscalClosing $closing, array $baris): void
    {
        $baseUom = ProductUom::where('product_id', $baris['product_id'])
            ->where('uom_type', ProductUom::TYPE_BASE)
            ->value('uom_id');

        if ($baseUom === null) {
            return;
        }

        $sequence = (int) StockMovement::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('branch_id', $baris['branch_id'])
            ->where('product_id', $baris['product_id'])
            ->max('sequence') + 1;

        StockMovement::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'branch_id' => $baris['branch_id'],
            'product_id' => $baris['product_id'],
            'movement_date' => $period->end_date->toDateString(),
            'movement_type' => 'ROUNDING',
            'reference_type' => StockMovement::REF_CLOSING,
            'reference_id' => $closing->fiscal_closing_id,
            'uom_id' => $baseUom,
            'qty' => 0,
            'qty_base' => 0,
            'unit_cost' => 0,
            'total_cost' => -$baris['selisih'],
            'sequence' => $sequence,
            'created_by' => $closing->closed_by,
        ]);
    }

    /** Kembalikan period ke status semula dan catat kegagalannya (Addendum 44). */
    private function pulihkan(User $user, FiscalPeriod $period, string $statusAsal, Throwable $e): void
    {
        // Ditulis lewat query, bukan lewat model: jalur pemulihan tidak boleh
        // bergantung pada seberapa segar keadaan model di memori.
        FiscalPeriod::whereKey($period->fiscal_period_id)->update([
            'status' => $statusAsal,
            'updated_at' => now(),
        ]);
        $period->setAttribute('status', $statusAsal)->syncOriginal();

        $this->langkah($period, ClosingAttemptLog::STEP_SNAPSHOT, ClosingAttemptLog::STATUS_FAILED, $e->getMessage());
        $this->catat($period, FiscalPeriodLog::ACTION_FAILED, FiscalPeriod::STATUS_CLOSING, $statusAsal, $user, mb_substr($e->getMessage(), 0, 1000));
    }

    private function langkah(FiscalPeriod $period, string $step, string $status, ?string $error = null): void
    {
        ClosingAttemptLog::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'step' => $step,
            'status' => $status,
            'error_message' => $error === null ? null : mb_substr($error, 0, 1000),
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    private function catat(FiscalPeriod $period, string $action, ?string $old, ?string $new, User $user, ?string $reason = null, ?int $snapshotId = null): void
    {
        FiscalPeriodLog::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'action' => $action,
            'old_status' => $old,
            'new_status' => $new,
            'action_by' => $user->user_id,
            'action_at' => now(),
            'reason' => $reason,
            'closing_snapshot_id' => $snapshotId,
        ]);
    }
}

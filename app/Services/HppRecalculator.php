<?php

namespace App\Services;

use App\Models\FiscalPeriod;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menghitung ulang HPP rata-rata bergerak (Addendum 41.2, 42.2, 45.3).
 *
 * Rata-rata bergerak bersifat berantai: HPP setiap barang keluar bergantung
 * pada seluruh barang masuk SEBELUMNYA. Begitu ada movement yang dibatalkan
 * atau disisipkan di tengah, rata-rata sesudahnya ikut berubah — dan nota yang
 * sudah terlanjur memakai rata-rata lama menyimpan HPP yang keliru.
 *
 * Cara kerjanya: putar ulang SELURUH movement produk di satu lokasi menurut
 * urutan periode lalu `sequence`, dengan rumus yang sama persis dengan
 * StockLedger, dan perbaiki HPP barang keluar yang harganya ditentukan
 * rata-rata. Movement di periode CLOSED ikut diputar supaya rata-ratanya benar,
 * tetapi TIDAK PERNAH ditulis — angka periode tertutup sudah dilaporkan dan
 * hanya boleh berubah lewat buka ulang.
 *
 * Yang ditentukan rata-rata (dan karenanya dihitung ulang): SALES,
 * ADJUSTMENT_OUT, TRANSFER_OUT — semua yang lewat StockLedger::issue().
 * Pembalikan dan retur penjualan mengikuti HPP baru transaksi asalnya.
 * Sisanya (pembelian, opening, penyesuaian masuk, retur ke pemasok yang
 * memakai harga lot) memakai harganya sendiri dan hanya menggeser rata-rata.
 *
 * Batasan yang disengaja: TRANSFER_IN di lokasi tujuan tetap memakai harga
 * yang tercatat saat transfer, walau HPP transfer keluarnya di lokasi asal
 * ikut berubah — rantai lintas lokasi itu belum ditelusuri.
 *
 * FIFO tidak perlu mesin ini: HPP-nya diambil dari lot yang benar-benar
 * terpakai, dan lot itu sudah dikembalikan apa adanya saat void.
 */
class HppRecalculator
{
    public const TRIGGER_VOID = 'VOID';

    public const TRIGGER_REOPEN = 'REOPEN';

    public const TRIGGER_MANUAL = 'CORRECTION';

    /** Barang keluar yang harganya diambil dari rata-rata berjalan. */
    private const AVG_ISSUES = [
        StockMovement::TYPE_SALES,
        StockMovement::TYPE_ADJUSTMENT_OUT,
        StockMovement::TYPE_TRANSFER_OUT,
    ];

    private const EPS = 0.00005;

    /**
     * @param  array<int, int>|null  $productIds  null = seluruh produk yang pernah bergerak di lokasi ini
     * @return array{products_affected: int, old_cogs_value: float, new_cogs_value: float, movements_changed: int}
     */
    public function recalculate(
        FiscalPeriod $period,
        int $branchId,
        ?array $productIds,
        string $trigger,
        ?User $user,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): array {
        $hasil = ['products_affected' => 0, 'old_cogs_value' => 0.0, 'new_cogs_value' => 0.0, 'movements_changed' => 0];

        if ($period->costing_method === StockLedger::METHOD_FIFO) {
            return $hasil;
        }

        $mulai = now();

        try {
            $productIds ??= StockMovement::where('branch_id', $branchId)->distinct()->pluck('product_id')->all();

            foreach ($productIds as $productId) {
                $satu = $this->product($branchId, (int) $productId);

                if ($satu['movements_changed'] > 0) {
                    $hasil['products_affected']++;
                    $hasil['movements_changed'] += $satu['movements_changed'];
                    $hasil['old_cogs_value'] += $satu['old_cogs_value'];
                    $hasil['new_cogs_value'] += $satu['new_cogs_value'];
                }
            }

            $hasil['old_cogs_value'] = round($hasil['old_cogs_value'], 2);
            $hasil['new_cogs_value'] = round($hasil['new_cogs_value'], 2);

            $this->log($period, $branchId, $trigger, $user, $referenceType, $referenceId, $hasil, 'SUCCESS', null, $mulai);
        } catch (Throwable $e) {
            $this->log($period, $branchId, $trigger, $user, $referenceType, $referenceId, $hasil, 'FAILED', $e->getMessage(), $mulai);

            throw $e;
        }

        return $hasil;
    }

    /**
     * @return array{movements_changed: int, old_cogs_value: float, new_cogs_value: float}
     */
    private function product(int $branchId, int $productId): array
    {
        $movements = StockMovement::query()
            ->join('fiscal_periods', 'fiscal_periods.fiscal_period_id', '=', 'stock_movements.fiscal_period_id')
            ->where('stock_movements.branch_id', $branchId)
            ->where('stock_movements.product_id', $productId)
            ->orderBy('fiscal_periods.start_date')
            ->orderBy('stock_movements.fiscal_period_id')
            ->orderBy('stock_movements.sequence')
            ->select('stock_movements.*', 'fiscal_periods.status as period_status')
            ->get();

        $qty = 0.0;
        $avg = 0.0;
        $hasil = ['movements_changed' => 0, 'old_cogs_value' => 0.0, 'new_cogs_value' => 0.0];

        // HPP baru per (reference_type, reference_id, |qty_base|) — supaya
        // pembalikan dan retur bisa mengikuti transaksi asalnya.
        $hppBaru = [];

        foreach ($movements as $m) {
            $qtyBase = (float) $m->qty_base;
            $besaran = abs($qtyBase);
            $boleh = $m->period_status !== FiscalPeriod::STATUS_CLOSED;
            $biaya = (float) $m->unit_cost;

            if ($qtyBase < 0 && in_array($m->movement_type, self::AVG_ISSUES, true)) {
                $biaya = round($avg, 4);
                $hppBaru[$this->kunci($m->reference_type, $m->reference_id, $besaran)] = $biaya;
                $qty = round($qty + $qtyBase, 4);
            } elseif ($qtyBase > 0) {
                $asal = $this->biayaAsal($m, $besaran, $hppBaru);
                $biaya = $asal ?? $biaya;
                $qtyBaru = round($qty + $qtyBase, 4);

                if ($qtyBaru > 0) {
                    $avg = round((max($qty, 0) * $avg + $qtyBase * $biaya) / $qtyBaru, 4);
                }

                $qty = $qtyBaru;
            } else {
                // Keluar dengan harganya sendiri: retur ke pemasok dari lot
                // tertentu, atau pembalikan sebuah penerimaan.
                $qtyBaru = round($qty + $qtyBase, 4);
                $nilai = max($qty, 0) * $avg - $besaran * $biaya;
                $avg = $qtyBaru > 0 ? round(max($nilai, 0) / $qtyBaru, 4) : 0.0;
                $qty = $qtyBaru;
            }

            if (! $boleh || abs($biaya - (float) $m->unit_cost) < self::EPS) {
                continue;
            }

            $totalLama = (float) $m->total_cost;
            $totalBaru = round(($qtyBase < 0 ? -1 : 1) * $besaran * $biaya, 2);

            DB::table('stock_movements')->where('movement_id', $m->movement_id)->update([
                'unit_cost' => $biaya,
                'total_cost' => $totalBaru,
            ]);

            $this->syncDokumen($m, $besaran, $biaya);

            $hasil['movements_changed']++;

            if ($m->movement_type === StockMovement::TYPE_SALES) {
                $hasil['old_cogs_value'] += abs($totalLama);
                $hasil['new_cogs_value'] += abs($totalBaru);
            }
        }

        // Saldo rata-rata hari ini = hasil putar ulang seluruh riwayat.
        StockBalance::where('branch_id', $branchId)->where('product_id', $productId)->update(['avg_cost' => round($avg, 4)]);

        return $hasil;
    }

    /**
     * Harga masuk yang mengikuti transaksi asal: pembalikan sebuah barang
     * keluar, dan retur penjualan (kembali seharga HPP notanya).
     *
     * @param  array<string, float>  $hppBaru
     */
    private function biayaAsal(StockMovement $m, float $besaran, array $hppBaru): ?float
    {
        if ($m->movement_type === StockMovement::TYPE_VOID_REVERSAL) {
            return $hppBaru[$this->kunci($m->reference_type, $m->reference_id, $besaran)] ?? null;
        }

        if ($m->movement_type === StockMovement::TYPE_RETURN_IN && $m->reference_type === StockMovement::REF_SALES_RETURN) {
            $saleId = DB::table('sales_returns')->where('return_id', $m->reference_id)->value('sale_id');

            if ($saleId === null) {
                return null;
            }

            // Retur boleh sebagian, jadi besarannya tidak bisa dipakai sebagai
            // kunci — cukup cari HPP baru baris penjualan produk itu di nota asal.
            foreach ($hppBaru as $kunci => $biaya) {
                if (str_starts_with($kunci, StockMovement::REF_SALES.'#'.$saleId.'#')) {
                    return $biaya;
                }
            }
        }

        return null;
    }

    /** Samakan dokumen sumbernya dengan HPP movement yang baru. */
    private function syncDokumen(StockMovement $m, float $besaran, float $biaya): void
    {
        if ($m->movement_type === StockMovement::TYPE_SALES && $m->reference_type === StockMovement::REF_SALES) {
            DB::table('sale_items')
                ->where('sale_id', $m->reference_id)
                ->where('product_id', $m->product_id)
                ->where('qty_base', $besaran)
                ->update([
                    'cost_at_sale' => $biaya,
                    'cogs_total' => round($besaran * $biaya, 2),
                ]);
        }

        if ($m->movement_type === StockMovement::TYPE_RETURN_IN && $m->reference_type === StockMovement::REF_SALES_RETURN) {
            DB::table('sales_return_items')
                ->where('return_id', $m->reference_id)
                ->where('product_id', $m->product_id)
                ->where('qty_base', $besaran)
                ->update(['cost_at_return' => $biaya]);
        }
    }

    private function kunci(?string $type, ?int $id, float $besaran): string
    {
        return $type.'#'.$id.'#'.number_format($besaran, 4, '.', '');
    }

    /** @param  array{products_affected: int, old_cogs_value: float, new_cogs_value: float, movements_changed: int}  $hasil */
    private function log(FiscalPeriod $period, int $branchId, string $trigger, ?User $user, ?string $referenceType, ?int $referenceId, array $hasil, string $status, ?string $error, $mulai): void
    {
        // Tanpa pengguna (mis. dipicu perintah terjadwal) tidak ada yang bisa
        // dicatat sebagai pemicu — kolomnya wajib, jadi lognya dilewati.
        if ($user === null) {
            return;
        }

        DB::table('hpp_recalc_logs')->insert([
            'fiscal_period_id' => $period->fiscal_period_id,
            'branch_id' => $branchId,
            'triggered_by' => $user->user_id,
            'trigger_type' => $trigger,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'products_affected' => $hasil['products_affected'],
            'old_cogs_value' => $hasil['old_cogs_value'],
            'new_cogs_value' => $hasil['new_cogs_value'],
            'status' => $status,
            'error_message' => $error,
            'started_at' => $mulai,
            'finished_at' => now(),
        ]);
    }
}

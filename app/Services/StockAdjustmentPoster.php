<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\QtyRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memposting satu penyesuaian stok.
 *
 * Penyesuaian adalah satu-satunya cara mengubah saldo tanpa dokumen jual-beli,
 * jadi ia sengaja dibuat berbiaya: wajib beralasan, tercatat bernomor, dan
 * membentuk movement seperti transaksi lain. Saldo tidak pernah boleh diketik
 * ulang begitu saja — riwayatnyalah yang menjadi dasar hitung ulang HPP.
 */
class StockAdjustmentPoster
{
    public function __construct(
        private StockLedger $stock,
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{
     *     product_id: int,
     *     uom_id: int,
     *     adjustment_type: string,
     *     qty: float,
     *     unit_cost?: float|null,
     *     reason: string,
     *     adjustment_date?: string|null
     * }  $payload
     */
    public function post(User $user, Branch $branch, array $payload): StockAdjustment
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['adjustment_date'] ?? now()->toDateString();
        $type = $this->type($payload['adjustment_type'] ?? null);
        $qty = (float) ($payload['qty'] ?? 0);

        $product = Product::where('owner_id', $owner->owner_id)->active()->find($payload['product_id'] ?? 0);

        if ($product === null) {
            throw ValidationException::withMessages(['product_id' => 'Produk tidak dikenal atau sudah tidak aktif.']);
        }

        // Jasa tidak punya persediaan untuk disesuaikan. Ditolak di sini supaya
        // pesannya menyebut sebabnya, bukan sekadar "gagal" dari buku stok.
        if (! $product->tracksStock()) {
            throw ValidationException::withMessages([
                'product_id' => "{$product->product_name} adalah jasa dan tidak memiliki stok untuk disesuaikan.",
            ]);
        }

        $uom = ProductUom::where('product_id', $product->product_id)->where('active', true)->find($payload['uom_id'] ?? 0);

        if ($uom === null) {
            throw ValidationException::withMessages([
                'uom_id' => "Satuan tersebut bukan satuan aktif milik {$product->product_name}.",
            ]);
        }

        QtyRule::assert($product, $qty, 'qty');

        $reason = trim((string) ($payload['reason'] ?? ''));

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan wajib diisi — inilah satu-satunya keterangan kenapa saldo berubah tanpa transaksi.',
            ]);
        }

        return DB::transaction(function () use ($owner, $user, $branch, $product, $uom, $qty, $type, $reason, $date, $payload) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);
            $qtyBase = round($qty * (float) $uom->conversion_factor, 4);

            // Barang masuk harus menyebut harganya. Bila tidak diisi, dipakai
            // rata-rata berjalan — dan itu bernilai nol untuk produk yang belum
            // pernah punya stok, sehingga penjualannya kelak ber-HPP nol
            // (Addendum 55.2). Yang keluar tidak perlu: harganya dari lot.
            $unitCost = $type === StockAdjustment::TYPE_IN
                ? (float) ($payload['unit_cost'] ?? 0)
                : 0.0;

            if ($type === StockAdjustment::TYPE_IN && $unitCost <= 0) {
                $unitCost = $this->avgCost($branch->branch_id, $product->product_id);
            }

            $adjustment = StockAdjustment::create([
                'adjustment_number' => $this->numbers->next($owner->owner_id, $branch, $period, StockAdjustment::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'adjustment_date' => $date,
                'product_id' => $product->product_id,
                'uom_id' => $uom->uom_id,
                'adjustment_type' => $type,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                'unit_cost' => $unitCost,
                'reason' => $reason,
                'status' => StockAdjustment::STATUS_POSTED,
                'created_by' => $user->user_id,
            ]);

            if ($type === StockAdjustment::TYPE_IN) {
                $this->stock->receive(
                    $period,
                    $branch->branch_id,
                    $date,
                    $product,
                    $uom,
                    $qty,
                    $unitCost,
                    StockMovement::TYPE_ADJUSTMENT_IN,
                    StockMovement::REF_ADJUSTMENT,
                    $adjustment->adjustment_id,
                    $user->user_id,
                );
            } else {
                $hasil = $this->stock->issue(
                    $owner,
                    $period,
                    $branch->branch_id,
                    $date,
                    $product,
                    $uom,
                    $qty,
                    StockMovement::TYPE_ADJUSTMENT_OUT,
                    StockMovement::REF_ADJUSTMENT,
                    $adjustment->adjustment_id,
                    $user->user_id,
                    'qty',
                );

                // Harga keluar baru diketahui setelah lot dibaca; disimpan agar
                // dokumennya menyebut nilai yang benar-benar keluar.
                $adjustment->update(['unit_cost' => $hasil['cost_at_sale']]);
            }

            return $adjustment->load(['product', 'uom']);
        });
    }

    private function type(?string $type): string
    {
        if (! in_array($type, [StockAdjustment::TYPE_IN, StockAdjustment::TYPE_OUT], true)) {
            throw ValidationException::withMessages(['adjustment_type' => 'Jenis penyesuaian harus masuk atau keluar.']);
        }

        return $type;
    }

    private function avgCost(int $branchId, int $productId): float
    {
        return (float) (StockBalance::where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->value('avg_cost') ?? 0);
    }
}

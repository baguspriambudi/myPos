<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mencatat dan menerapkan stock opname (Addendum 39.8, 50, 59.3).
 *
 * Dua tahap: `create()` menyimpan hitungan sebagai DRAFT (semua peran boleh),
 * `apply()` mengesahkannya menjadi StockAdjustment sungguhan (MANAGER ke
 * atas) lewat StockAdjustmentPoster yang sudah ada — bukan menulis movement
 * sendiri, supaya jalurnya tetap satu dengan penyesuaian manual.
 */
class StockOpnamePoster
{
    public function __construct(
        private StockLedger $stock,
        private StockAdjustmentPoster $adjustments,
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{
     *     opname_date?: string|null,
     *     lines: array<int, array{product_id: int, uom_id: int, actual_qty: float}>
     * }  $payload
     */
    public function create(User $user, Branch $branch, array $payload): StockOpname
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['opname_date'] ?? now()->toDateString();

        if (empty($payload['lines'])) {
            throw ValidationException::withMessages(['lines' => 'Belum ada barang yang dihitung.']);
        }

        return DB::transaction(function () use ($owner, $user, $branch, $payload, $date) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);
            $lines = $this->lines($owner->owner_id, $branch->branch_id, $payload['lines']);

            $opname = StockOpname::create([
                'opname_number' => $this->numbers->next($owner->owner_id, $branch, $period, StockOpname::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'opname_date' => $date,
                'status' => StockOpname::STATUS_DRAFT,
                'created_by' => $user->user_id,
            ]);

            foreach ($lines as $line) {
                StockOpnameItem::create([
                    'opname_id' => $opname->opname_id,
                    'product_id' => $line['product']->product_id,
                    'uom_id' => $line['uom']->uom_id,
                    'system_qty' => $line['system_qty'],
                    'system_qty_base' => $line['system_qty_base'],
                    'actual_qty' => $line['actual_qty'],
                    'actual_qty_base' => $line['actual_qty_base'],
                    'difference_qty_base' => $line['difference_qty_base'],
                    'unit_cost' => $line['unit_cost'],
                    'difference_value' => $line['difference_value'],
                ]);
            }

            return $opname->load(['items.product', 'items.uom']);
        });
    }

    /**
     * Mengesahkan opname: setiap baris yang selisih menjadi satu StockAdjustment.
     *
     * Saldo sistem dihitung ULANG saat ini juga (bukan memakai snapshot saat
     * hitungan disimpan) — kalau ada transaksi lain di antara mencatat dan
     * menerapkan, selisih yang disahkan harus mencerminkan keadaan terkini,
     * bukan keadaan basi.
     */
    public function apply(User $user, StockOpname $opname): StockOpname
    {
        if ($opname->status !== StockOpname::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'opname' => $opname->status === StockOpname::STATUS_APPLIED
                    ? 'Opname ini sudah diterapkan sebelumnya.'
                    : 'Opname ini sudah dibatalkan dan tidak dapat diterapkan.',
            ]);
        }

        if (! in_array($opname->period->status, FiscalPeriodResolver::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'opname' => "Periode {$opname->period->period_name} sudah tidak terbuka sehingga opname ini tidak dapat diterapkan.",
            ]);
        }

        $branch = $opname->branch;

        return DB::transaction(function () use ($user, $opname, $branch) {
            foreach ($opname->items as $item) {
                $product = $item->product;
                $baseUom = ProductUom::where('product_id', $product->product_id)->where('uom_type', ProductUom::TYPE_BASE)->firstOrFail();

                $systemQtyBaseTerkini = $this->stock->onHand($branch->branch_id, $product->product_id);
                $selisihTerkini = round((float) $item->actual_qty_base - $systemQtyBaseTerkini, 4);

                $adjustment = null;

                if ($selisihTerkini !== 0.0) {
                    $adjustment = $this->adjustments->post($user, $branch, [
                        'product_id' => $product->product_id,
                        'uom_id' => $baseUom->uom_id,
                        'adjustment_type' => $selisihTerkini > 0 ? StockAdjustment::TYPE_IN : StockAdjustment::TYPE_OUT,
                        'qty' => abs($selisihTerkini),
                        'reason' => "Selisih stock opname {$opname->opname_number}.",
                        'adjustment_date' => $opname->opname_date->toDateString(),
                    ]);
                }

                $factor = (float) $item->uom->conversion_factor;
                $unitCost = $adjustment !== null ? (float) $adjustment->unit_cost : (float) $item->unit_cost;

                $item->update([
                    'system_qty_base' => $systemQtyBaseTerkini,
                    'system_qty' => $factor > 0 ? round($systemQtyBaseTerkini / $factor, 4) : $systemQtyBaseTerkini,
                    'difference_qty_base' => $selisihTerkini,
                    'unit_cost' => $unitCost,
                    'difference_value' => round($selisihTerkini * $unitCost, 2),
                ]);
            }

            $opname->update([
                'status' => StockOpname::STATUS_APPLIED,
                'applied_at' => now(),
                'applied_by' => $user->user_id,
            ]);

            return $opname->fresh(['items.product', 'items.uom']);
        });
    }

    public function cancel(User $user, StockOpname $opname): StockOpname
    {
        if ($opname->status !== StockOpname::STATUS_DRAFT) {
            throw ValidationException::withMessages(['opname' => 'Hanya opname berstatus draf yang dapat dibatalkan.']);
        }

        $opname->update(['status' => StockOpname::STATUS_CANCELLED]);

        return $opname;
    }

    /**
     * @param  array<int, array{product_id: int, uom_id: int, actual_qty: float}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function lines(int $ownerId, int $branchId, array $rows): array
    {
        $lines = [];

        foreach ($rows as $i => $row) {
            $product = Product::where('owner_id', $ownerId)->active()->find($row['product_id'] ?? 0);

            if ($product === null) {
                throw ValidationException::withMessages(["lines.{$i}.product_id" => 'Produk tidak dikenal atau sudah tidak aktif.']);
            }

            if (! $product->tracksStock()) {
                throw ValidationException::withMessages([
                    "lines.{$i}.product_id" => "{$product->product_name} adalah jasa; tidak ada stok untuk dihitung.",
                ]);
            }

            $uom = ProductUom::where('product_id', $product->product_id)->where('active', true)->find($row['uom_id'] ?? 0);

            if ($uom === null) {
                throw ValidationException::withMessages([
                    "lines.{$i}.uom_id" => "Satuan tersebut bukan satuan aktif milik {$product->product_name}.",
                ]);
            }

            $actualQty = (float) ($row['actual_qty'] ?? 0);

            // Bukan QtyRule::assert biasa: hasil hitungan BOLEH nol (barang
            // benar-benar habis), sedangkan QtyRule dirancang untuk baris
            // transaksi yang wajib lebih dari nol.
            if ($actualQty < 0) {
                throw ValidationException::withMessages(["lines.{$i}.actual_qty" => 'Hasil hitungan tidak boleh negatif.']);
            }

            if (! $product->is_weighted && fmod($actualQty, 1.0) !== 0.0) {
                throw ValidationException::withMessages([
                    "lines.{$i}.actual_qty" => "{$product->product_name} tidak dihitung dalam pecahan — isi hasil hitungan dengan bilangan bulat.",
                ]);
            }

            if ($product->is_weighted && round($actualQty, 2) !== $actualQty) {
                throw ValidationException::withMessages([
                    "lines.{$i}.actual_qty" => "Hasil hitungan {$product->product_name} paling banyak dua angka di belakang koma.",
                ]);
            }

            $factor = (float) $uom->conversion_factor;
            $actualQtyBase = round($actualQty * $factor, 4);
            $systemQtyBase = $this->stock->onHand($branchId, $product->product_id);
            $unitCost = (float) (StockBalance::where('branch_id', $branchId)->where('product_id', $product->product_id)->value('avg_cost') ?? 0);
            $differenceBase = round($actualQtyBase - $systemQtyBase, 4);

            $lines[] = [
                'product' => $product,
                'uom' => $uom,
                'actual_qty' => $actualQty,
                'actual_qty_base' => $actualQtyBase,
                'system_qty' => $factor > 0 ? round($systemQtyBase / $factor, 4) : $systemQtyBase,
                'system_qty_base' => $systemQtyBase,
                'difference_qty_base' => $differenceBase,
                'unit_cost' => $unitCost,
                'difference_value' => round($differenceBase * $unitCost, 2),
            ];
        }

        return $lines;
    }
}

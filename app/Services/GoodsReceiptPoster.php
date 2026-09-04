<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseItem;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\QtyRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memposting penerimaan barang — dokumen yang menambah stok (Addendum 58.2).
 *
 * Harganya tidak diminta dari yang menerima barang. Petugas gudang dan kasir
 * mencatat qty fisik saja; cost sementaranya diisi sistem, dan selisihnya
 * terhadap faktur diselesaikan saat faktur diposting.
 *
 * Penerimaan boleh dicatat di lokasi tipe apa pun, gudang termasuk — tidak ada
 * kewajiban barang mampir ke gudang lebih dulu (Addendum 59.2).
 */
class GoodsReceiptPoster
{
    public function __construct(
        private StockLedger $stock,
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{
     *     supplier_id?: int|null,
     *     receipt_date?: string|null,
     *     supplier_do_number?: string|null,
     *     note?: string|null,
     *     lines: array<int, array{product_id: int, uom_id: int, qty: float}>
     * }  $payload
     */
    public function post(User $user, Branch $branch, array $payload): GoodsReceipt
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['receipt_date'] ?? now()->toDateString();

        if (empty($payload['lines'])) {
            throw ValidationException::withMessages(['lines' => 'Belum ada barang yang diterima.']);
        }

        $supplier = $this->supplier($owner->owner_id, $payload['supplier_id'] ?? null);

        return DB::transaction(function () use ($owner, $user, $branch, $payload, $date, $supplier) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);
            $lines = $this->lines($owner->owner_id, $branch->branch_id, $payload['lines']);

            $receipt = GoodsReceipt::create([
                'receipt_number' => $this->numbers->next($owner->owner_id, $branch, $period, GoodsReceipt::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'supplier_id' => $supplier?->supplier_id,
                'receipt_date' => $date,
                'status' => GoodsReceipt::STATUS_POSTED,
                'supplier_do_number' => $payload['supplier_do_number'] ?? null,
                'note' => $payload['note'] ?? null,
                'total_cost' => round(array_sum(array_column($lines, 'line_total')), 2),
                'created_by' => $user->user_id,
            ]);

            foreach ($lines as $line) {
                $item = GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->goods_receipt_id,
                    'product_id' => $line['product']->product_id,
                    'uom_id' => $line['uom']->uom_id,
                    'qty' => $line['qty'],
                    'qty_base' => $line['qty_base'],
                    'unit_cost' => $line['unit_cost'],
                    'unit_cost_base' => $line['unit_cost_base'],
                    'cost_source' => $line['cost_source'],
                    'line_total' => $line['line_total'],
                ]);

                // Lot dibentuk di sini, bukan saat faktur datang — lot inilah
                // yang menjadi asal barang bagi FIFO dan retur pembelian.
                $this->stock->receive(
                    $period,
                    $branch->branch_id,
                    $date,
                    $line['product'],
                    $line['uom'],
                    $line['qty'],
                    $line['unit_cost_base'],
                    StockMovement::TYPE_PURCHASE,
                    StockMovement::REF_RECEIPT,
                    $receipt->goods_receipt_id,
                    $user->user_id,
                    $item->receipt_item_id,
                );
            }

            return $receipt->load(['items.product', 'items.uom', 'supplier']);
        });
    }

    /** Membatalkan penerimaan: stok yang masuk ditarik kembali (Addendum 58.6). */
    public function void(User $user, GoodsReceipt $receipt): GoodsReceipt
    {
        $owner = Owner::findOrFail($user->owner_id);

        if ($receipt->status !== GoodsReceipt::STATUS_POSTED) {
            throw ValidationException::withMessages(['receipt' => 'Hanya penerimaan berstatus POSTED yang dapat dibatalkan.']);
        }

        // Fakturnya lebih dulu, baru penerimaannya. Kalau dibalik, faktur akan
        // menunjuk penerimaan yang barangnya sudah dianggap tidak pernah datang.
        if ($receipt->purchase_id !== null) {
            throw ValidationException::withMessages([
                'receipt' => 'Penerimaan ini sudah difakturkan. Batalkan fakturnya lebih dulu.',
            ]);
        }

        if (! in_array($receipt->period->status, FiscalPeriodResolver::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'receipt' => "Periode {$receipt->period->period_name} sudah tidak terbuka sehingga penerimaan ini tidak dapat dibatalkan.",
            ]);
        }

        return DB::transaction(function () use ($owner, $user, $receipt) {
            $movements = StockMovement::where('reference_type', StockMovement::REF_RECEIPT)
                ->where('reference_id', $receipt->goods_receipt_id)
                ->where('movement_type', StockMovement::TYPE_PURCHASE)
                ->get();

            foreach ($receipt->items as $item) {
                $movement = $movements->firstWhere('product_id', $item->product_id);

                if ($movement === null) {
                    continue;
                }

                $lot = StockLot::where('receipt_item_id', $item->receipt_item_id)->with('product')->lockForUpdate()->first();

                $this->stock->reverseReceipt($owner, $movement, $lot, now()->toDateString(), $user->user_id);
            }

            $receipt->update([
                'status' => GoodsReceipt::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $user->user_id,
            ]);

            return $receipt->fresh(['items']);
        });
    }

    /**
     * @param  array<int, array{product_id: int, uom_id: int, qty: float}>  $rows
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
                    "lines.{$i}.product_id" => "{$product->product_name} adalah jasa; tidak ada barang yang bisa diterima.",
                ]);
            }

            $uom = ProductUom::where('product_id', $product->product_id)->where('active', true)->find($row['uom_id'] ?? 0);

            if ($uom === null) {
                throw ValidationException::withMessages([
                    "lines.{$i}.uom_id" => "Satuan tersebut bukan satuan aktif milik {$product->product_name}.",
                ]);
            }

            $qty = (float) ($row['qty'] ?? 0);
            QtyRule::assert($product, $qty, "lines.{$i}.qty");

            [$costBase, $source] = $this->temporaryCost($product->product_id, $branchId);
            $factor = (float) $uom->conversion_factor;

            $lines[] = [
                'product' => $product,
                'uom' => $uom,
                'qty' => $qty,
                'qty_base' => round($qty * $factor, 4),
                'unit_cost' => round($costBase * $factor, 2),
                'unit_cost_base' => $costBase,
                'cost_source' => $source,
                'line_total' => round($qty * $costBase * $factor, 2),
            ];
        }

        return $lines;
    }

    /**
     * Cost sementara, berurutan: harga beli terakhir → rata-rata berjalan → nol
     * dengan baris ditandai belum berharga (Addendum 58.2).
     *
     * @return array{0: float, 1: string}
     */
    private function temporaryCost(int $productId, int $branchId): array
    {
        $terakhir = PurchaseItem::where('product_id', $productId)
            ->whereHas('purchase', fn ($q) => $q->where('status', 'POSTED'))
            ->orderByDesc('purchase_item_id')
            ->value('unit_cost_base');

        if ($terakhir !== null && (float) $terakhir > 0) {
            return [(float) $terakhir, GoodsReceiptItem::SOURCE_LAST_PURCHASE];
        }

        $avg = (float) (StockBalance::where('branch_id', $branchId)->where('product_id', $productId)->value('avg_cost') ?? 0);

        if ($avg > 0) {
            return [$avg, GoodsReceiptItem::SOURCE_AVG_COST];
        }

        // Nol bukan kesalahan: barang baru pertama kali datang memang belum
        // punya acuan harga. Penandanya membuat baris ini menonjol di layar
        // sampai fakturnya mengisi harga sebenarnya.
        return [0.0, GoodsReceiptItem::SOURCE_NONE];
    }

    private function supplier(int $ownerId, ?int $supplierId): ?Supplier
    {
        if ($supplierId === null) {
            return null;
        }

        $supplier = Supplier::where('owner_id', $ownerId)->find($supplierId);

        if ($supplier === null) {
            throw ValidationException::withMessages(['supplier_id' => 'Pemasok tidak dikenal.']);
        }

        return $supplier;
    }
}

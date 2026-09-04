<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Owner;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memposting retur pembelian (Addendum 48.2, 58.6).
 *
 * Mengacu pada PENERIMAAN, bukan faktur — lot persediaan lahir dari
 * penerimaan, jadi partai yang dikurangi juga harus ditelusuri lewat
 * penerimaan. Bila lot itu sudah terjual sebagian atau seluruhnya, retur
 * ditolak dan harus diselesaikan lewat penyesuaian stok (Addendum 48.2).
 */
class PurchaseReturnPoster
{
    public function __construct(
        private StockLedger $stock,
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{
     *     goods_receipt_id?: int|null,
     *     transaction_date?: string|null,
     *     lines: array<int, array{receipt_item_id: int, qty: float}>
     * }  $payload
     */
    public function post(User $user, Branch $branch, array $payload): PurchaseReturn
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['transaction_date'] ?? now()->toDateString();

        if (empty($payload['lines'])) {
            throw ValidationException::withMessages(['lines' => 'Belum ada barang yang diretur.']);
        }

        $receipt = GoodsReceipt::where('branch_id', $branch->branch_id)
            ->where('status', GoodsReceipt::STATUS_POSTED)
            ->with(['items.product', 'items.uom'])
            ->find($payload['goods_receipt_id'] ?? 0);

        if ($receipt === null) {
            throw ValidationException::withMessages([
                'goods_receipt_id' => 'Penerimaan tidak ditemukan, bukan milik lokasi ini, atau belum diposting.',
            ]);
        }

        return DB::transaction(function () use ($owner, $user, $branch, $payload, $date, $receipt) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);
            $lines = $this->lines($receipt, $payload['lines']);

            $return = PurchaseReturn::create([
                'return_number' => $this->numbers->next($owner->owner_id, $branch, $period, PurchaseReturn::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'goods_receipt_id' => $receipt->goods_receipt_id,
                'transaction_date' => $date,
                'status' => PurchaseReturn::STATUS_POSTED,
                'refund_total' => round(array_sum(array_column($lines, 'refund_amount')), 2),
                'created_by' => $user->user_id,
            ]);

            foreach ($lines as $line) {
                $receiptItem = $line['receipt_item'];

                PurchaseReturnItem::create([
                    'return_id' => $return->return_id,
                    'receipt_item_id' => $receiptItem->receipt_item_id,
                    'product_id' => $receiptItem->product_id,
                    'uom_id' => $receiptItem->uom_id,
                    'qty' => $line['qty'],
                    'qty_base' => $line['qty_base'],
                    'cost_at_return' => $line['cost_at_return'],
                    'refund_amount' => $line['refund_amount'],
                ]);

                $this->stock->issueFromLot(
                    $owner,
                    $period,
                    $date,
                    $line['lot'],
                    $receiptItem->uom,
                    $line['qty'],
                    StockMovement::TYPE_RETURN_OUT,
                    StockMovement::REF_PURCHASE_RETURN,
                    $return->return_id,
                    $user->user_id,
                );
            }

            return $return->load(['items.product', 'items.uom', 'goodsReceipt']);
        });
    }

    /** Membatalkan retur: stok yang keluar kembali masuk ke lot asalnya. */
    public function void(User $user, PurchaseReturn $return): PurchaseReturn
    {
        $owner = Owner::findOrFail($user->owner_id);

        if ($return->status !== PurchaseReturn::STATUS_POSTED) {
            throw ValidationException::withMessages(['return' => 'Hanya retur berstatus POSTED yang dapat dibatalkan.']);
        }

        if (! in_array($return->period->status, FiscalPeriodResolver::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'return' => "Periode {$return->period->period_name} sudah tidak terbuka sehingga retur ini tidak dapat dibatalkan.",
            ]);
        }

        return DB::transaction(function () use ($owner, $user, $return) {
            $movements = StockMovement::where('reference_type', StockMovement::REF_PURCHASE_RETURN)
                ->where('reference_id', $return->return_id)
                ->where('movement_type', StockMovement::TYPE_RETURN_OUT)
                ->get();

            foreach ($movements as $movement) {
                $this->stock->reverse($owner, $movement, now()->toDateString(), $user->user_id);
            }

            $return->update([
                'status' => PurchaseReturn::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $user->user_id,
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * @param  array<int, array{receipt_item_id: int, qty: float}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function lines(GoodsReceipt $receipt, array $rows): array
    {
        $lines = [];

        foreach ($rows as $i => $row) {
            $receiptItem = $receipt->items->firstWhere('receipt_item_id', (int) ($row['receipt_item_id'] ?? 0));

            if ($receiptItem === null) {
                throw ValidationException::withMessages(["lines.{$i}.receipt_item_id" => 'Baris tersebut tidak ada pada penerimaan ini.']);
            }

            $qty = (float) ($row['qty'] ?? 0);

            if ($qty <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => 'Kuantitas retur harus lebih dari nol.']);
            }

            $lot = StockLot::where('receipt_item_id', $receiptItem->receipt_item_id)->lockForUpdate()->first();

            if ($lot === null) {
                throw ValidationException::withMessages(["lines.{$i}.receipt_item_id" => 'Lot dari penerimaan ini tidak ditemukan.']);
            }

            $factor = (float) $receiptItem->uom->conversion_factor;
            $qtyBase = round($qty * $factor, 4);
            $sisa = round((float) $receiptItem->qty_base - PurchaseReturnItem::sudahDiretur($receiptItem->receipt_item_id), 4);

            if ($qtyBase > $sisa) {
                throw ValidationException::withMessages([
                    "lines.{$i}.qty" => sprintf(
                        'Qty retur %s melebihi sisa yang bisa diretur untuk %s (maksimum %s %s).',
                        $this->angka($qty),
                        $receiptItem->product->product_name,
                        $this->angka($factor > 0 ? $sisa / $factor : 0),
                        $receiptItem->uom->uom_name,
                    ),
                ]);
            }

            $costAtReturn = (float) $lot->unit_cost;

            $lines[] = [
                'receipt_item' => $receiptItem,
                'lot' => $lot,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                'cost_at_return' => $costAtReturn,
                'refund_amount' => round($qtyBase * $costAtReturn, 2),
            ];
        }

        return $lines;
    }

    private function angka(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ',');
    }
}

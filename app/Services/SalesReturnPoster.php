<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Owner;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memposting retur penjualan (Addendum 48).
 *
 * Retur dibuat DARI nota yang sudah POSTED, bukan dari daftar produk kosong —
 * supaya mustahil meretur barang yang tak pernah terjual, dan qty retur tidak
 * pernah melebihi qty nota dikurangi retur-retur sebelumnya yang masih POSTED.
 *
 * Memakai fiscal period TANGGAL RETUR, bukan period nota asal — retur boleh
 * lintas period bahkan setelah period nota CLOSED (Addendum 48.1, 48.3).
 */
class SalesReturnPoster
{
    public function __construct(
        private StockLedger $stock,
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{
     *     sale_id?: int|null,
     *     transaction_date?: string|null,
     *     lines: array<int, array{sale_item_id: int, qty: float}>
     * }  $payload
     */
    public function post(User $user, Branch $branch, array $payload): SalesReturn
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['transaction_date'] ?? now()->toDateString();

        if (empty($payload['lines'])) {
            throw ValidationException::withMessages(['lines' => 'Belum ada barang yang diretur.']);
        }

        $sale = Sale::where('branch_id', $branch->branch_id)
            ->posted()
            ->with(['items.product', 'items.uom'])
            ->find($payload['sale_id'] ?? 0);

        if ($sale === null) {
            throw ValidationException::withMessages(['sale_id' => 'Nota tidak ditemukan, bukan milik lokasi ini, atau belum diposting.']);
        }

        return DB::transaction(function () use ($owner, $user, $branch, $payload, $date, $sale) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);
            $lines = $this->lines($sale, $payload['lines']);

            $return = SalesReturn::create([
                'return_number' => $this->numbers->next($owner->owner_id, $branch, $period, SalesReturn::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'sale_id' => $sale->sale_id,
                'transaction_date' => $date,
                'status' => SalesReturn::STATUS_POSTED,
                'refund_total' => round(array_sum(array_column($lines, 'refund_amount')), 2),
                'created_by' => $user->user_id,
            ]);

            foreach ($lines as $line) {
                $saleItem = $line['sale_item'];

                $item = SalesReturnItem::create([
                    'return_id' => $return->return_id,
                    'sale_item_id' => $saleItem->sale_item_id,
                    'product_id' => $saleItem->product_id,
                    'uom_id' => $saleItem->uom_id,
                    'qty' => $line['qty'],
                    'qty_base' => $line['qty_base'],
                    'cost_at_return' => $line['cost_at_return'],
                    'refund_amount' => $line['refund_amount'],
                ]);

                // Lot baru bertanggal hari ini, bukan lot lama — barang yang
                // kembali antre di belakang seperti kedatangan baru mana pun
                // (Addendum 48.2).
                $this->stock->receive(
                    $period,
                    $branch->branch_id,
                    $date,
                    $saleItem->product,
                    $saleItem->uom,
                    $line['qty'],
                    $line['cost_at_return'],
                    StockMovement::TYPE_RETURN_IN,
                    StockMovement::REF_SALES_RETURN,
                    $return->return_id,
                    $user->user_id,
                    null,
                    $item->return_item_id,
                );
            }

            return $return->load(['items.product', 'items.uom', 'sale']);
        });
    }

    /** Membatalkan retur: lot yang terbentuk dikosongkan kembali (Addendum 41, 48). */
    public function void(User $user, SalesReturn $return): SalesReturn
    {
        $owner = Owner::findOrFail($user->owner_id);

        if ($return->status !== SalesReturn::STATUS_POSTED) {
            throw ValidationException::withMessages(['return' => 'Hanya retur berstatus POSTED yang dapat dibatalkan.']);
        }

        if (! in_array($return->period->status, FiscalPeriodResolver::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'return' => "Periode {$return->period->period_name} sudah tidak terbuka sehingga retur ini tidak dapat dibatalkan.",
            ]);
        }

        return DB::transaction(function () use ($owner, $user, $return) {
            $movements = StockMovement::where('reference_type', StockMovement::REF_SALES_RETURN)
                ->where('reference_id', $return->return_id)
                ->where('movement_type', StockMovement::TYPE_RETURN_IN)
                ->get();

            foreach ($return->items as $item) {
                $movement = $movements->firstWhere('product_id', $item->product_id);

                if ($movement === null) {
                    continue;
                }

                $lot = StockLot::where('return_item_id', $item->return_item_id)->lockForUpdate()->first();

                $this->stock->reverseReceipt($owner, $movement, $lot, now()->toDateString(), $user->user_id);
            }

            $return->update([
                'status' => SalesReturn::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $user->user_id,
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * @param  array<int, array{sale_item_id: int, qty: float}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function lines(Sale $sale, array $rows): array
    {
        $lines = [];

        foreach ($rows as $i => $row) {
            $saleItem = $sale->items->firstWhere('sale_item_id', (int) ($row['sale_item_id'] ?? 0));

            if ($saleItem === null) {
                throw ValidationException::withMessages(["lines.{$i}.sale_item_id" => 'Baris tersebut tidak ada pada nota ini.']);
            }

            $qty = (float) ($row['qty'] ?? 0);

            if ($qty <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => 'Kuantitas retur harus lebih dari nol.']);
            }

            $factor = (float) $saleItem->uom->conversion_factor;
            $qtyBase = round($qty * $factor, 4);
            $sisa = round((float) $saleItem->qty_base - SalesReturnItem::sudahDiretur($saleItem->sale_item_id), 4);

            if ($qtyBase > $sisa) {
                throw ValidationException::withMessages([
                    "lines.{$i}.qty" => sprintf(
                        'Qty retur %s melebihi sisa yang bisa diretur untuk %s (maksimum %s %s).',
                        $this->angka($qty),
                        $saleItem->product->product_name,
                        $this->angka($factor > 0 ? $sisa / $factor : 0),
                        $saleItem->uom->uom_name,
                    ),
                ]);
            }

            $lines[] = [
                'sale_item' => $saleItem,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                'cost_at_return' => (float) $saleItem->cost_at_sale,
                // Proporsional terhadap yang DIBAYAR untuk baris itu (line_total:
                // sudah dikurangi diskon dan termasuk pajak), bukan harga daftar —
                // barang yang dibeli dengan diskon tidak boleh direfund penuh.
                'refund_amount' => (float) $saleItem->qty_base > 0
                    ? round($qtyBase * (float) $saleItem->line_total / (float) $saleItem->qty_base, 2)
                    : 0.0,
            ];
        }

        return $lines;
    }

    private function angka(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ',');
    }
}

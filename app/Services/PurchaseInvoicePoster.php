<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Owner;
use App\Models\Payable;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memposting faktur pemasok (Addendum 58.3).
 *
 * Faktur TIDAK menambah stok — barangnya sudah masuk lewat penerimaan. Yang
 * dilakukan faktur adalah mengakui utang, mencatat pajak masukan, dan melepas
 * nilai Utang Belum Ditagih sebesar penerimaan yang dicakupnya.
 *
 * Satu faktur boleh mencakup beberapa penerimaan; penerimaan yang sudah
 * difakturkan tidak dapat difakturkan ulang.
 */
class PurchaseInvoicePoster
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{
     *     supplier_id: int,
     *     transaction_date?: string|null,
     *     due_date?: string|null,
     *     tax_id?: int|null,
     *     receipt_ids: array<int, int>,
     *     lines?: array<int, array{receipt_item_id: int, qty: float, unit_cost: float}>
     * }  $payload
     */
    public function post(User $user, Branch $branch, array $payload): Purchase
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['transaction_date'] ?? now()->toDateString();

        $supplier = Supplier::where('owner_id', $owner->owner_id)->find($payload['supplier_id'] ?? 0);

        if ($supplier === null) {
            throw ValidationException::withMessages(['supplier_id' => 'Pemasok wajib dipilih pada faktur.']);
        }

        if (empty($payload['receipt_ids'])) {
            throw ValidationException::withMessages(['receipt_ids' => 'Pilih dulu penerimaan barang yang difakturkan.']);
        }

        return DB::transaction(function () use ($owner, $user, $branch, $payload, $date, $supplier) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);

            $receipts = GoodsReceipt::whereIn('goods_receipt_id', $payload['receipt_ids'])
                ->where('branch_id', $branch->branch_id)
                ->with('items.uom')
                ->lockForUpdate()
                ->get();

            $this->assertReceiptsInvoiceable($receipts, $payload['receipt_ids'], $supplier->supplier_id);

            $harga = collect($payload['lines'] ?? [])->keyBy('receipt_item_id');
            $tax = $this->tax($owner->owner_id, $payload['tax_id'] ?? null);

            $purchase = Purchase::create([
                'purchase_number' => $this->numbers->next($owner->owner_id, $branch, $period, Purchase::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'supplier_id' => $supplier->supplier_id,
                'transaction_date' => $date,
                'status' => Purchase::STATUS_POSTED,
                'currency_code' => $owner->default_currency,
                'exchange_rate' => 1,
                'created_by' => $user->user_id,
            ]);

            $subtotal = 0.0;

            foreach ($receipts as $receipt) {
                foreach ($receipt->items as $item) {
                    $baris = $harga->get($item->receipt_item_id);
                    $qty = (float) ($baris['qty'] ?? $item->qty);
                    $unitCost = (float) ($baris['unit_cost'] ?? $item->unit_cost);

                    // Faktur tidak boleh menagih lebih banyak daripada yang
                    // benar-benar diterima; selisih ke atas harus diselesaikan
                    // dengan penerimaan susulan (Addendum 58.4).
                    if ($qty > (float) $item->qty) {
                        throw ValidationException::withMessages([
                            'lines' => sprintf(
                                'Faktur menagih %s %s untuk %s, padahal yang diterima hanya %s. Catat penerimaan susulannya lebih dulu.',
                                rtrim(rtrim(number_format($qty, 4, ',', '.'), '0'), ','),
                                $item->uom->uom_name,
                                $item->product->product_name,
                                rtrim(rtrim(number_format((float) $item->qty, 4, ',', '.'), '0'), ','),
                            ),
                        ]);
                    }

                    if ($qty <= 0 || $unitCost < 0) {
                        throw ValidationException::withMessages(['lines' => 'Kuantitas dan harga faktur tidak masuk akal.']);
                    }

                    $factor = (float) $item->uom->conversion_factor;
                    $lineTotal = round($qty * $unitCost, 2);
                    $subtotal += $lineTotal;

                    $purchaseItem = PurchaseItem::create([
                        'purchase_id' => $purchase->purchase_id,
                        'product_id' => $item->product_id,
                        'uom_id' => $item->uom_id,
                        'qty' => $qty,
                        'qty_base' => round($qty * $factor, 4),
                        'unit_cost' => $unitCost,
                        'unit_cost_base' => $factor > 0 ? round($unitCost / $factor, 4) : $unitCost,
                        'line_total' => $lineTotal,
                    ]);

                    // Baris penerimaan menunjuk baris faktur yang menagihnya —
                    // dari sinilah selisih harga terhadap cost sementara kelak
                    // dapat ditelusuri saat HPP dihitung ulang (Addendum 58.4).
                    $item->update(['purchase_item_id' => $purchaseItem->purchase_item_id]);
                }

                $receipt->update([
                    'purchase_id' => $purchase->purchase_id,
                    'invoiced_at' => now(),
                ]);
            }

            $subtotal = round($subtotal, 2);
            $taxTotal = $tax === null ? 0.0 : round($subtotal * (float) $tax->tax_rate / 100, 2);
            $total = round($subtotal + $taxTotal, 2);

            $purchase->update(['subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => $total]);

            if ($tax !== null && $taxTotal > 0) {
                DB::table('purchase_taxes')->insert([
                    'purchase_id' => $purchase->purchase_id,
                    'tax_id' => $tax->tax_id,
                    'base_amount' => $subtotal,
                    'tax_amount' => $taxTotal,
                ]);
            }

            // Utang usaha diakui di sini. Pembayarannya menyusul di modul kas.
            Payable::create([
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'supplier_id' => $supplier->supplier_id,
                'purchase_id' => $purchase->purchase_id,
                'due_date' => $payload['due_date'] ?? null,
                'amount' => $total,
                'paid_amount' => 0,
                'status' => Payable::STATUS_OPEN,
                'created_by' => $user->user_id,
            ]);

            return $purchase->load(['items.product', 'items.uom', 'supplier', 'receipts']);
        });
    }

    /** Membatalkan faktur: penerimaannya kembali menggantung, stok tidak tersentuh. */
    public function void(User $user, Purchase $purchase): Purchase
    {
        if ($purchase->status !== Purchase::STATUS_POSTED) {
            throw ValidationException::withMessages(['purchase' => 'Hanya faktur berstatus POSTED yang dapat dibatalkan.']);
        }

        if (! in_array($purchase->period->status, FiscalPeriodResolver::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'purchase' => "Periode {$purchase->period->period_name} sudah tidak terbuka sehingga faktur ini tidak dapat dibatalkan.",
            ]);
        }

        $payable = Payable::where('purchase_id', $purchase->purchase_id)->first();

        if ($payable !== null && (float) $payable->paid_amount > 0) {
            throw ValidationException::withMessages([
                'purchase' => 'Faktur ini sudah dibayar sebagian. Batalkan pembayarannya lebih dulu.',
            ]);
        }

        return DB::transaction(function () use ($user, $purchase, $payable) {
            foreach ($purchase->receipts as $receipt) {
                $receipt->items()->update(['purchase_item_id' => null]);
                $receipt->update(['purchase_id' => null, 'invoiced_at' => null]);
            }

            $payable?->update(['status' => Payable::STATUS_VOID]);

            $purchase->update([
                'status' => Purchase::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $user->user_id,
            ]);

            return $purchase->fresh(['items', 'receipts']);
        });
    }

    /**
     * @param  Collection<int, GoodsReceipt>  $receipts
     * @param  array<int, int>  $diminta
     */
    private function assertReceiptsInvoiceable($receipts, array $diminta, int $supplierId): void
    {
        if ($receipts->count() !== count(array_unique($diminta))) {
            throw ValidationException::withMessages(['receipt_ids' => 'Ada penerimaan yang tidak ditemukan di lokasi ini.']);
        }

        foreach ($receipts as $receipt) {
            if ($receipt->status !== GoodsReceipt::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'receipt_ids' => "Penerimaan {$receipt->receipt_number} tidak berstatus POSTED.",
                ]);
            }

            if ($receipt->purchase_id !== null) {
                throw ValidationException::withMessages([
                    'receipt_ids' => "Penerimaan {$receipt->receipt_number} sudah difakturkan sebelumnya.",
                ]);
            }

            // Faktur satu pemasok tidak boleh mencakup barang kiriman pemasok
            // lain; utangnya akan tercatat ke pihak yang salah.
            if ($receipt->supplier_id !== null && $receipt->supplier_id !== $supplierId) {
                throw ValidationException::withMessages([
                    'receipt_ids' => "Penerimaan {$receipt->receipt_number} berasal dari pemasok lain.",
                ]);
            }
        }
    }

    private function tax(int $ownerId, ?int $taxId): ?Tax
    {
        if ($taxId === null) {
            return null;
        }

        $tax = Tax::where('owner_id', $ownerId)->where('active', true)->find($taxId);

        if ($tax === null) {
            throw ValidationException::withMessages(['tax_id' => 'Pajak tidak dikenal atau sudah tidak aktif.']);
        }

        return $tax;
    }
}

<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Support\QtyRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memposting transfer stok antar-lokasi (Addendum 49).
 *
 * Kedua sisi WAJIB punya fiscal period berstatus OPEN pada tanggal transfer;
 * bila salah satu tidak, seluruh transfer ditolak (Addendum 49.1) — sengaja
 * tidak ada state IN_TRANSIT di tahap ini (49.2).
 */
class StockTransferPoster
{
    public function __construct(
        private StockLedger $stock,
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{
     *     from_branch_id: int,
     *     to_branch_id: int,
     *     transaction_date?: string|null,
     *     lines: array<int, array{product_id: int, uom_id: int, qty: float}>
     * }  $payload
     */
    public function post(User $user, array $payload): StockTransfer
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['transaction_date'] ?? now()->toDateString();

        if (empty($payload['lines'])) {
            throw ValidationException::withMessages(['lines' => 'Belum ada barang yang ditransfer.']);
        }

        $fromBranch = Branch::where('owner_id', $owner->owner_id)->active()->find($payload['from_branch_id'] ?? 0);
        $toBranch = Branch::where('owner_id', $owner->owner_id)->active()->find($payload['to_branch_id'] ?? 0);

        if ($fromBranch === null || $toBranch === null) {
            throw ValidationException::withMessages(['to_branch_id' => 'Lokasi asal atau tujuan tidak dikenal.']);
        }

        if ($fromBranch->branch_id === $toBranch->branch_id) {
            throw ValidationException::withMessages(['to_branch_id' => 'Lokasi tujuan tidak boleh sama dengan lokasi asal.']);
        }

        return DB::transaction(function () use ($owner, $user, $fromBranch, $toBranch, $payload, $date) {
            $fromPeriod = $this->periods->forDate($owner->owner_id, $fromBranch->branch_id, $date);

            try {
                $toPeriod = $this->periods->forDate($owner->owner_id, $toBranch->branch_id, $date);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'to_branch_id' => "Lokasi tujuan ({$toBranch->branch_name}): ".implode(' ', $e->errors()['transaction_date'] ?? []),
                ]);
            }

            $lines = $this->lines($owner->owner_id, $fromBranch->branch_id, $payload['lines']);

            $transfer = StockTransfer::create([
                'transfer_number' => $this->numbers->next($owner->owner_id, $fromBranch, $fromPeriod, StockTransfer::PREFIX),
                'from_branch_id' => $fromBranch->branch_id,
                'to_branch_id' => $toBranch->branch_id,
                'transaction_date' => $date,
                'status' => StockTransfer::STATUS_POSTED,
                'created_by' => $user->user_id,
            ]);

            foreach ($lines as $line) {
                $product = $line['product'];
                $uom = $line['uom'];
                $qty = $line['qty'];

                // HPP keluar ditentukan metode costing branch pengirim, lewat
                // pintu keluar generik yang sama dengan penjualan (Addendum
                // 49.3) — bukan diisi pemanggil.
                $hasil = $this->stock->issue(
                    $owner,
                    $fromPeriod,
                    $fromBranch->branch_id,
                    $date,
                    $product,
                    $uom,
                    $qty,
                    StockMovement::TYPE_TRANSFER_OUT,
                    StockMovement::REF_TRANSFER,
                    $transfer->transfer_id,
                    $user->user_id,
                    'lines',
                );

                $costPerBase = $hasil['cost_at_sale'];

                $item = StockTransferItem::create([
                    'transfer_id' => $transfer->transfer_id,
                    'product_id' => $product->product_id,
                    'uom_id' => $uom->uom_id,
                    'qty' => $qty,
                    'qty_base' => round($qty * (float) $uom->conversion_factor, 4),
                    'unit_cost' => $costPerBase,
                ]);

                // Masuk ke penerima dengan cost yang SAMA — tidak ada margin
                // pada transfer antar-lokasi (Addendum 49.3). Lot baru, bukan
                // menyambung lot lama, karena FIFO-nya berjalan sendiri per
                // lokasi.
                $this->stock->receive(
                    $toPeriod,
                    $toBranch->branch_id,
                    $date,
                    $product,
                    $uom,
                    $qty,
                    $costPerBase,
                    StockMovement::TYPE_TRANSFER_IN,
                    StockMovement::REF_TRANSFER,
                    $transfer->transfer_id,
                    $user->user_id,
                    null,
                    null,
                    $item->transfer_item_id,
                );
            }

            return $transfer->load(['items.product', 'items.uom', 'fromBranch', 'toBranch']);
        });
    }

    /** Membatalkan transfer: kedua sisi dibalik dalam satu transaksi (Addendum 49.4). */
    public function void(User $user, StockTransfer $transfer): StockTransfer
    {
        $owner = Owner::findOrFail($user->owner_id);

        if ($transfer->status !== StockTransfer::STATUS_POSTED) {
            throw ValidationException::withMessages(['transfer' => 'Hanya transfer berstatus POSTED yang dapat dibatalkan.']);
        }

        $date = $transfer->transaction_date->toDateString();
        $fromPeriod = $this->periods->forDate($owner->owner_id, $transfer->from_branch_id, $date);

        try {
            $this->periods->forDate($owner->owner_id, $transfer->to_branch_id, $date);
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'transfer' => 'Periode di lokasi tujuan sudah tidak terbuka sehingga transfer ini tidak dapat dibatalkan.',
            ]);
        }

        if (! in_array($fromPeriod->status, FiscalPeriodResolver::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'transfer' => "Periode {$fromPeriod->period_name} di lokasi asal sudah tidak terbuka sehingga transfer ini tidak dapat dibatalkan.",
            ]);
        }

        return DB::transaction(function () use ($owner, $user, $transfer) {
            $outMovements = StockMovement::where('reference_type', StockMovement::REF_TRANSFER)
                ->where('reference_id', $transfer->transfer_id)
                ->where('movement_type', StockMovement::TYPE_TRANSFER_OUT)
                ->get();

            $inMovements = StockMovement::where('reference_type', StockMovement::REF_TRANSFER)
                ->where('reference_id', $transfer->transfer_id)
                ->where('movement_type', StockMovement::TYPE_TRANSFER_IN)
                ->get();

            foreach ($transfer->items as $item) {
                // Sisi penerima lebih dulu: ditolak bila barangnya sudah
                // terjual/terpakai di sana (Addendum 49.4).
                $inMovement = $inMovements->firstWhere('product_id', $item->product_id);

                if ($inMovement !== null) {
                    $lot = StockLot::where('transfer_item_id', $item->transfer_item_id)->lockForUpdate()->first();
                    $this->stock->reverseReceipt($owner, $inMovement, $lot, now()->toDateString(), $user->user_id);
                }

                $outMovement = $outMovements->firstWhere('product_id', $item->product_id);

                if ($outMovement !== null) {
                    $this->stock->reverse($owner, $outMovement, now()->toDateString(), $user->user_id);
                }
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $user->user_id,
            ]);

            return $transfer->fresh(['items']);
        });
    }

    /**
     * @param  array<int, array{product_id: int, uom_id: int, qty: float}>  $rows
     * @return array<int, array{product: Product, uom: ProductUom, qty: float}>
     */
    private function lines(int $ownerId, int $fromBranchId, array $rows): array
    {
        $lines = [];

        foreach ($rows as $i => $row) {
            $product = Product::where('owner_id', $ownerId)->active()->find($row['product_id'] ?? 0);

            if ($product === null) {
                throw ValidationException::withMessages(["lines.{$i}.product_id" => 'Produk tidak dikenal atau sudah tidak aktif.']);
            }

            if (! $product->tracksStock()) {
                throw ValidationException::withMessages([
                    "lines.{$i}.product_id" => "{$product->product_name} adalah jasa; tidak ada barang yang bisa ditransfer.",
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

            $lines[] = ['product' => $product, 'uom' => $uom, 'qty' => $qty];
        }

        return $lines;
    }
}

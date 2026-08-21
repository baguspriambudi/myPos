<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductUom;

/**
 * Menentukan harga jual satu baris transaksi (Addendum 39.11).
 *
 * Urutan pemeriksaan, berhenti pada yang pertama cocok:
 *   1. Daftar harga milik pelanggan
 *   2. Daftar harga khusus lokasi, priority tertinggi
 *   3. Daftar harga owner (branch_id NULL)
 *   4. Daftar harga bertanda is_default
 *   5. Harga standar ProductUom::selling_price
 *
 * Dua aturan yang mudah salah kalau tidak sengaja ditulis:
 *
 * - Bila daftar harga terpilih TIDAK memuat produk tersebut, sistem langsung
 *   turun ke harga standar — tidak melompat ke daftar harga berikutnya. Ini
 *   supaya harga tidak diam-diam terambil dari daftar yang tidak diniatkan.
 * - min_qty dibandingkan terhadap qty dalam satuan baris itu, bukan qty base.
 *   Beli 6 pcs memenuhi "PCS min_qty 6"; beli 1 dus tidak.
 */
class PriceResolver
{
    /**
     * @return array{
     *     unit_price: float,
     *     price_list_id: int|null,
     *     source: string,
     *     tier_min_qty: float|null
     * }
     */
    public function resolve(
        int $ownerId,
        int $productId,
        int $uomId,
        float $qty,
        ?int $branchId = null,
        ?int $customerId = null,
        ?string $date = null,
    ): array {
        $date ??= now()->toDateString();

        $priceList = $this->pickPriceList($ownerId, $branchId, $customerId, $date);

        if ($priceList !== null) {
            $tier = $this->pickTier($priceList->price_list_id, $productId, $uomId, $qty);

            if ($tier !== null) {
                return [
                    'unit_price' => (float) $tier->unit_price,
                    'price_list_id' => $priceList->price_list_id,
                    'source' => 'PRICE_LIST',
                    'tier_min_qty' => (float) $tier->min_qty,
                ];
            }
        }

        $uom = ProductUom::find($uomId);

        return [
            'unit_price' => $uom ? (float) $uom->selling_price : 0.0,
            'price_list_id' => null,
            'source' => 'STANDARD',
            'tier_min_qty' => null,
        ];
    }

    /**
     * Seluruh tingkat harga untuk satu produk + satuan pada daftar harga yang
     * berlaku. Dipakai layar master untuk memperlihatkan tangga harganya.
     *
     * @return array<int, array{min_qty: float, unit_price: float}>
     */
    public function tiersFor(int $ownerId, int $productId, int $uomId, ?int $branchId = null, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $priceList = $this->pickPriceList($ownerId, $branchId, null, $date);

        if ($priceList === null) {
            return [];
        }

        return PriceListItem::where('price_list_id', $priceList->price_list_id)
            ->where('product_id', $productId)
            ->where('uom_id', $uomId)
            ->where('active', true)
            ->orderBy('min_qty')
            ->get()
            ->map(fn (PriceListItem $i) => [
                'min_qty' => (float) $i->min_qty,
                'unit_price' => (float) $i->unit_price,
            ])
            ->all();
    }

    private function pickPriceList(int $ownerId, ?int $branchId, ?int $customerId, string $date): ?PriceList
    {
        // 1. Daftar harga milik pelanggan.
        if ($customerId !== null) {
            $customer = Customer::find($customerId);

            if ($customer?->price_list_id !== null) {
                $customerList = PriceList::where('owner_id', $ownerId)
                    ->where('price_list_id', $customer->price_list_id)
                    ->effectiveOn($date)
                    ->first();

                if ($customerList !== null) {
                    return $customerList;
                }
            }
        }

        // Langkah 2-4 hanya memandang daftar harga umum. Daftar bertanda
        // customer_only sengaja dilewati: ia hanya boleh dijangkau lewat
        // penugasan ke pelanggan di langkah 1. Tanpa penyaringan ini, "Harga
        // Grosir" akan menang atas "Harga Umum" hanya karena prioritasnya lebih
        // tinggi, dan pembeli eceran ikut mendapat harga grosir.
        $umum = fn () => PriceList::where('owner_id', $ownerId)->where('customer_only', false);

        // 2. Daftar harga khusus lokasi.
        if ($branchId !== null) {
            $branchList = $umum()
                ->where('branch_id', $branchId)
                ->effectiveOn($date)
                ->orderByDesc('priority')
                ->orderByDesc('price_list_id')
                ->first();

            if ($branchList !== null) {
                return $branchList;
            }
        }

        // 3. Daftar harga owner, berlaku semua lokasi.
        $ownerList = $umum()
            ->whereNull('branch_id')
            ->effectiveOn($date)
            ->orderByDesc('priority')
            ->orderByDesc('price_list_id')
            ->first();

        if ($ownerList !== null) {
            return $ownerList;
        }

        // 4. Daftar harga bawaan, tanpa memandang masa berlaku.
        return $umum()
            ->where('is_default', true)
            ->where('active', true)
            ->first();
    }

    /** Baris dengan min_qty terbesar yang masih <= qty. */
    private function pickTier(int $priceListId, int $productId, int $uomId, float $qty): ?PriceListItem
    {
        return PriceListItem::where('price_list_id', $priceListId)
            ->where('product_id', $productId)
            ->where('uom_id', $uomId)
            ->where('active', true)
            ->where('min_qty', '<=', $qty)
            ->orderByDesc('min_qty')
            ->first();
    }
}

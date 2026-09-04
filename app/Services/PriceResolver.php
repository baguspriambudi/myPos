<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Support\Collection;

/**
 * Menentukan harga jual satu baris transaksi (Addendum 39.11).
 *
 * Daftar harga adalah SATU-SATUNYA sumber harga jual. Yang menentukan daftar
 * mana yang dipakai adalah JENIS pelanggan, bukan penugasan per pelanggan:
 * member memakai daftar bertanda member_only, pelanggan tunai memakai harga
 * normal. Tidak ada lagi kolom daftar harga di master pelanggan.
 *
 * Kandidatnya disusun sebagai KELOMPOK, dari yang paling khusus ke yang paling
 * umum, lalu ditelusuri sampai ketemu baris harga untuk produk + satuan + qty:
 *
 *   1. Harga member khusus lokasi transaksi — hanya bila pelanggannya member
 *   2. Harga member seluruh lokasi
 *   3. Harga normal khusus lokasi transaksi
 *   4. Harga normal seluruh lokasi
 *   5. Daftar bertanda harga normal (tanpa memandang masa berlaku DAFTARNYA)
 *
 * Kekhususan kelompok selalu menang lebih dulu: harga member mengalahkan harga
 * normal walau harga normalnya baru diubah kemarin. Yang menentukan pemenang DI
 * DALAM satu kelompok adalah start_date baris harganya, bukan lagi priority
 * daftar (Addendum 39.15) — lihat PriceListItem::scopeTerbaruDulu().
 *
 * Karena yang paling baru mulai yang menang, mengganti harga cukup menambah
 * baris baru; baris lama tidak perlu ditutup dan justru tidak boleh ditutup:
 * dialah yang membuat penjualan ber-backdate menemukan harga yang benar-benar
 * berlaku saat itu, dan yang mengembalikan harga normal begitu promo berjangka
 * habis masanya.
 *
 * Kalau tidak satu pun memuat produk itu, hasilnya source NONE dan unit_price
 * NULL: produk tersebut BELUM BOLEH DIJUAL karena harganya belum diatur. Tidak
 * ada lagi harga cadangan di master produk — dulu ada, dan akibatnya barang yang
 * lupa diberi harga terjual Rp0.
 *
 * Dua aturan yang mudah salah kalau tidak sengaja ditulis:
 *
 * - Penelusuran berlanjut ke kelompok berikutnya bila kelompok terpilih tidak
 *   memuat produk itu. Daftar promo yang hanya berisi 5 produk tidak boleh
 *   membuat 500 produk lain kehilangan harga.
 * - min_qty dibandingkan terhadap qty dalam satuan baris itu, bukan qty base.
 *   Beli 6 pcs memenuhi "PCS min_qty 6"; beli 1 dus tidak.
 */
class PriceResolver
{
    public const SOURCE_PRICE_LIST = 'PRICE_LIST';

    /** Belum ada harga di daftar harga mana pun — produk tidak boleh dijual. */
    public const SOURCE_NONE = 'NONE';

    /**
     * @return array{
     *     unit_price: float|null,
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

        foreach ($this->candidateGroups($ownerId, $branchId, $customerId, $date) as $listIds) {
            $tier = $this->pickTier($listIds, $productId, $uomId, $qty, $date);

            if ($tier !== null) {
                return [
                    'unit_price' => (float) $tier->unit_price,
                    'price_list_id' => $tier->price_list_id,
                    'source' => self::SOURCE_PRICE_LIST,
                    'tier_min_qty' => (float) $tier->min_qty,
                ];
            }
        }

        return [
            'unit_price' => null,
            'price_list_id' => null,
            'source' => self::SOURCE_NONE,
            'tier_min_qty' => null,
        ];
    }

    /** Produk + satuan ini sudah punya harga dan boleh dijual. */
    public function isSellable(
        int $ownerId,
        int $productId,
        int $uomId,
        ?int $branchId = null,
        ?int $customerId = null,
        ?string $date = null,
    ): bool {
        return $this->resolve($ownerId, $productId, $uomId, 1, $branchId, $customerId, $date)['source']
            !== self::SOURCE_NONE;
    }

    /**
     * Seluruh tingkat harga untuk satu produk + satuan, diambil dari daftar
     * harga pertama yang memuatnya. Dipakai layar master untuk memperlihatkan
     * tangga harganya.
     *
     * @return array<int, array{min_qty: float, unit_price: float}>
     */
    public function tiersFor(
        int $ownerId,
        int $productId,
        int $uomId,
        ?int $branchId = null,
        ?string $date = null,
        ?int $customerId = null,
    ): array {
        $date ??= now()->toDateString();

        foreach ($this->candidateGroups($ownerId, $branchId, $customerId, $date) as $listIds) {
            $tiers = PriceListItem::whereIn('price_list_id', $listIds)
                ->where('product_id', $productId)
                ->where('uom_id', $uomId)
                ->effectiveOn($date)
                ->terbaruDulu()
                ->get()
                // Satu tingkat bisa punya beberapa versi harga; yang tampil
                // hanya versi yang menang, yaitu yang pertama pada urutan itu.
                ->unique('min_qty')
                ->sortBy('min_qty');

            if ($tiers->isNotEmpty()) {
                return $tiers->map(fn (PriceListItem $i) => [
                    'min_qty' => (float) $i->min_qty,
                    'unit_price' => (float) $i->unit_price,
                ])->values()->all();
            }
        }

        return [];
    }

    /**
     * Kelompok daftar harga, dari yang paling khusus ke yang paling umum.
     *
     * Yang dikembalikan adalah KUMPULAN id per kelompok, bukan satu daftar per
     * langkah. Di dalam satu kelompok, pemenangnya ditentukan start_date baris
     * harganya — jadi dua daftar sederajat (misalnya promo dan harga umum yang
     * sama-sama berlaku seluruh lokasi) diadu di tingkat baris, bukan lewat
     * priority daftar.
     *
     * @return Collection<int, Collection<int, int>>
     */
    private function candidateGroups(int $ownerId, ?int $branchId, ?int $customerId, string $date): Collection
    {
        $kelompok = collect();

        // 1-2. Harga member. Satu-satunya syarat: pelanggannya member. Pelanggan
        //      tunai — termasuk pelanggan bawaan dan penjualan tanpa pelanggan —
        //      tidak pernah menyentuh daftar ini, sehingga "Harga Grosir" tidak
        //      bisa bocor ke pembeli eceran.
        if ($customerId !== null && Customer::find($customerId)?->is_member) {
            $kelompok = $kelompok->concat($this->listGroups($ownerId, true, $branchId, $date));
        }

        // 3-4. Harga normal: lokasi transaksi lebih dulu, lalu seluruh lokasi.
        //      Member pun turun ke sini untuk produk yang tidak ada di daftar
        //      member — keanggotaan tidak boleh membuat barang hilang harganya.
        $kelompok = $kelompok->concat($this->listGroups($ownerId, false, $branchId, $date));

        // 5. Daftar bertanda harga normal, walau masa berlaku DAFTARNYA sudah
        //    lewat: jaring terakhir sebelum produk dinyatakan tidak boleh
        //    dijual. Masa berlaku BARIS harganya tetap dihormati — harga yang
        //    sengaja dihentikan tidak boleh hidup lagi lewat pintu ini.
        $bawaan = PriceList::where('owner_id', $ownerId)
            ->where('member_only', false)
            ->where('is_default', true)
            ->where('active', true)
            ->value('price_list_id');

        if ($bawaan !== null) {
            $kelompok->push(collect([$bawaan]));
        }

        return $kelompok->filter(fn (Collection $ids) => $ids->isNotEmpty())->values();
    }

    /**
     * Dua kelompok untuk satu jenis harga: khusus lokasi transaksi lebih dulu,
     * baru yang berlaku seluruh lokasi.
     *
     * @return Collection<int, Collection<int, int>>
     */
    private function listGroups(int $ownerId, bool $memberOnly, ?int $branchId, string $date): Collection
    {
        $query = fn () => PriceList::where('owner_id', $ownerId)
            ->where('member_only', $memberOnly)
            ->effectiveOn($date);

        $khususLokasi = $branchId === null
            ? collect()
            : $query()->where('branch_id', $branchId)->pluck('price_list_id');

        return collect([$khususLokasi, $query()->whereNull('branch_id')->pluck('price_list_id')]);
    }

    /**
     * Baris pemenang: tingkat kuantitas tertinggi yang masih terpenuhi, lalu
     * versi harga yang paling baru mulai berlaku pada tanggal itu.
     *
     * @param  Collection<int, int>  $listIds
     */
    private function pickTier(Collection $listIds, int $productId, int $uomId, float $qty, string $date): ?PriceListItem
    {
        return PriceListItem::whereIn('price_list_id', $listIds)
            ->where('product_id', $productId)
            ->where('uom_id', $uomId)
            ->where('min_qty', '<=', $qty)
            ->effectiveOn($date)
            ->terbaruDulu()
            ->first();
    }
}

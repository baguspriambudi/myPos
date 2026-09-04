<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tingkat harga: produk + satuan + kuantitas minimum → harga, berikut masa
 * berlakunya.
 *
 * Contoh untuk Rinso (base PCS, pack DUS = 12 pcs):
 *   DUS min_qty 1 → 20.000
 *   PCS min_qty 1 →  2.500
 *   PCS min_qty 6 →  2.300
 *
 * min_qty dibandingkan terhadap qty DALAM SATUAN BARIS ITU, bukan qty base
 * (Addendum 39.12).
 *
 * Harga tidak pernah ditimpa. Menggantinya berarti menambah baris baru dengan
 * start_date lebih baru; yang paling baru mulai itulah yang menang pada tanggal
 * transaksi (Addendum 39.15). Baris lama dibiarkan agar transaksi lama —
 * termasuk yang di-backdate — tetap menemukan harga yang benar-benar berlaku
 * saat itu.
 */
class PriceListItem extends Model
{
    /** Pengganti NULL untuk "tanpa batas akhir"; satu BETWEEN, tetap ber-index. */
    public const TANPA_AKHIR = '9999-12-31';

    public $timestamps = false;

    protected $table = 'price_list_items';

    protected $primaryKey = 'price_list_item_id';

    protected $fillable = [
        'price_list_id',
        'product_id',
        'uom_id',
        'min_qty',
        'unit_price',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'min_qty' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** Masa berlakunya mencakup tanggal tersebut. */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('start_date', '<=', $date)->where('end_date', '>=', $date);
    }

    /**
     * Urutan pemenang untuk satu produk + satuan: tingkat kuantitas tertinggi
     * dulu, lalu yang paling baru mulai berlaku.
     *
     * start_date-lah yang menggantikan priority. Urutan terakhir berdasarkan id
     * hanyalah jaring pengaman: kunci uk_pli_versi sudah melarang dua versi
     * dengan tanggal mulai yang sama, dan mengganti harga dua kali dalam sehari
     * dilakukan dengan MENGUBAH baris hari ini — ia belum terkunci.
     */
    public function scopeTerbaruDulu(Builder $query): Builder
    {
        return $query->orderByDesc('min_qty')
            ->orderByDesc('start_date')
            ->orderByDesc('price_list_item_id');
    }

    /** Sudah mulai berlaku, jadi sudah menjadi riwayat dan tidak boleh diubah. */
    public function terkunci(?string $today = null): bool
    {
        return $this->start_date->toDateString() < ($today ?? now()->toDateString());
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'uom_id');
    }
}

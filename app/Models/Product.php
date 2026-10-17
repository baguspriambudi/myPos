<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Produk, termasuk yang tidak berwujud.
 *
 * `product_type` menentukan dua hal sekaligus, dan keduanya DITURUNKAN dari
 * jenisnya — sengaja tidak disimpan sebagai kolom boolean tersendiri supaya
 * tidak pernah berselisih dengan jenisnya (Addendum 39.14).
 */
class Product extends Model
{
    /** Barang dagang: dibeli untuk dijual lagi. */
    public const TYPE_TRADE = 'TRADE';

    /** Jasa: tidak berwujud, tidak punya stok. */
    public const TYPE_SERVICE = 'SERVICE';

    /** Aset/inventaris usaha: dimiliki dan dicatat, tetapi tidak dijual. */
    public const TYPE_ASSET = 'ASSET';

    /** Bahan atau hasil produksi sendiri; menunggu modul produksi. */
    public const TYPE_PRODUCTION = 'PRODUCTION';

    public const TYPES = [self::TYPE_TRADE, self::TYPE_SERVICE, self::TYPE_ASSET, self::TYPE_PRODUCTION];

    /** Hanya jenis ini yang boleh masuk daftar harga jual dan dijual kasir. */
    public const SELLABLE_TYPES = [self::TYPE_TRADE, self::TYPE_SERVICE];

    protected $table = 'products';

    protected $primaryKey = 'product_id';

    protected $fillable = [
        'owner_id',
        'category_id',
        'tax_id',
        'product_type',
        'product_name',
        'sku',
        'barcode',
        'base_uom_id',
        'is_weighted',
        'active',
    ];

    protected $attributes = [
        'product_type' => self::TYPE_TRADE,
    ];

    protected function casts(): array
    {
        return [
            'is_weighted' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** Nama jenis untuk layar; kuncinya nilai yang tersimpan di database. */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_TRADE => 'Barang dagang',
            self::TYPE_SERVICE => 'Jasa',
            self::TYPE_ASSET => 'Aset',
            self::TYPE_PRODUCTION => 'Produksi',
        ];
    }

    /** Boleh diberi harga jual dan dijual. Aset dan bahan produksi tidak. */
    public function canSell(): bool
    {
        return in_array($this->product_type, self::SELLABLE_TYPES, true);
    }

    /**
     * Punya pergerakan stok. Jasa tidak berwujud — tidak ada yang berkurang
     * saat ia terjual, jadi ia tidak pernah menyentuh kartu stok maupun HPP.
     */
    public function tracksStock(): bool
    {
        return $this->product_type !== self::TYPE_SERVICE;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    /** Seluruh satuan produk ini beserta konversinya; harga ada di daftar harga. */
    public function uoms(): HasMany
    {
        return $this->hasMany(ProductUom::class, 'product_id')->orderBy('conversion_factor');
    }

    public function baseUom(): HasOne
    {
        return $this->hasOne(ProductUom::class, 'uom_id', 'base_uom_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Barang dagang dan jasa saja. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->whereIn('product_type', self::SELLABLE_TYPES);
    }

    /** Semua yang punya kartu stok — yaitu semua kecuali jasa. */
    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('product_type', '!=', self::TYPE_SERVICE);
    }
}

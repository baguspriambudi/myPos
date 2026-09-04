<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satuan produk. conversion_factor selalu relatif terhadap base unit:
 * DUS dengan factor 12 berarti 1 dus = 12 pcs.
 *
 * uom_name adalah salinan dari master satuan (Unit) yang ditunjuk unit_id;
 * nama master dikunci begitu dipakai produk sehingga keduanya tidak bisa beda.
 *
 * Harga TIDAK disimpan di sini. Satu-satunya sumber harga jual adalah daftar
 * harga (price_list_items), lihat PriceResolver.
 */
class ProductUom extends Model
{
    public $timestamps = false;

    public const TYPE_BASE = 'BASE';

    public const TYPE_PACK = 'PACK';

    protected $table = 'product_uoms';

    protected $primaryKey = 'uom_id';

    protected $fillable = [
        'product_id',
        'unit_id',
        'uom_name',
        'uom_type',
        'conversion_factor',
        'is_default',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:6',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Nama satuan berasal dari master ini; uom_name hanyalah salinannya. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function isBase(): bool
    {
        return $this->uom_type === self::TYPE_BASE;
    }
}

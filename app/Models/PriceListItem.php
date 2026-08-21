<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tingkat harga: produk + satuan + kuantitas minimum → harga.
 *
 * Contoh untuk Rinso (base PCS, pack DUS = 12 pcs):
 *   DUS min_qty 1 → 20.000
 *   PCS min_qty 1 →  2.500
 *   PCS min_qty 6 →  2.300
 *
 * min_qty dibandingkan terhadap qty DALAM SATUAN BARIS ITU, bukan qty base
 * (Addendum 39.12).
 */
class PriceListItem extends Model
{
    public $timestamps = false;

    protected $table = 'price_list_items';

    protected $primaryKey = 'price_list_item_id';

    protected $fillable = [
        'price_list_id',
        'product_id',
        'uom_id',
        'min_qty',
        'unit_price',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'min_qty' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'active' => 'boolean',
        ];
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris nota.
 *
 * unit_price adalah SNAPSHOT: harga yang benar-benar dipakai saat itu.
 * price_list_id hanya jejak telusur asalnya — mengubah master daftar harga
 * tidak boleh mengubah angka pada nota yang sudah tercetak (Addendum 39.10).
 *
 * Begitu pula cost_at_sale: HPP per satuan base pada saat barang keluar, bukan
 * HPP hari ini.
 */
class SaleItem extends Model
{
    public $timestamps = false;

    protected $table = 'sale_items';

    protected $primaryKey = 'sale_item_id';

    protected $fillable = [
        'sale_id',
        'product_id',
        'uom_id',
        'qty',
        'qty_base',
        'unit_price',
        'unit_price_base',
        'price_list_id',
        'discount_id',
        'discount_amount',
        'cost_at_sale',
        'cogs_total',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'unit_price_base' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'cost_at_sale' => 'decimal:4',
            'cogs_total' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris faktur pemasok: harga yang benar-benar ditagihkan. */
class PurchaseItem extends Model
{
    public $timestamps = false;

    protected $table = 'purchase_items';

    protected $primaryKey = 'purchase_item_id';

    protected $fillable = [
        'purchase_id',
        'product_id',
        'uom_id',
        'qty',
        'qty_base',
        'unit_cost',
        'unit_cost_base',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'unit_cost_base' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
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

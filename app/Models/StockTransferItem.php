<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris transfer.
 *
 * `unit_cost` adalah cost dari branch PENGIRIM — barang masuk ke penerima
 * dengan nilai yang sama, tidak ada margin pada transfer antar-lokasi
 * (Addendum 49.3).
 */
class StockTransferItem extends Model
{
    public $timestamps = false;

    protected $table = 'stock_transfer_items';

    protected $primaryKey = 'transfer_item_id';

    protected $fillable = [
        'transfer_id',
        'product_id',
        'uom_id',
        'qty',
        'qty_base',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
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

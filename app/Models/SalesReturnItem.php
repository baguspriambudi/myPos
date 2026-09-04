<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris retur penjualan.
 *
 * `cost_at_return` adalah SALINAN `sale_items.cost_at_sale` dari nota asal,
 * bukan HPP hari ini — barang yang masuk kembali dinilai dengan cost saat ia
 * dulu terjual (Addendum 48.1, 48.2).
 */
class SalesReturnItem extends Model
{
    public $timestamps = false;

    protected $table = 'sales_return_items';

    protected $primaryKey = 'return_item_id';

    protected $fillable = [
        'return_id',
        'sale_item_id',
        'product_id',
        'uom_id',
        'qty',
        'qty_base',
        'cost_at_return',
        'refund_amount',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'cost_at_return' => 'decimal:4',
            'refund_amount' => 'decimal:2',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'return_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'uom_id');
    }

    /** Qty (satuan base) yang sudah diretur atas satu baris nota, hanya retur yang masih POSTED. */
    public static function sudahDiretur(int $saleItemId): float
    {
        return (float) static::where('sale_item_id', $saleItemId)
            ->whereHas('salesReturn', fn ($q) => $q->where('status', SalesReturn::STATUS_POSTED))
            ->sum('qty_base');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris retur pembelian.
 *
 * `cost_at_return` adalah SALINAN `stock_lots.unit_cost` milik lot yang
 * diretur pada saat diretur — nilai lot yang sebenarnya, bukan rata-rata
 * berjalan hari ini (Addendum 48.1, 48.2).
 */
class PurchaseReturnItem extends Model
{
    public $timestamps = false;

    protected $table = 'purchase_return_items';

    protected $primaryKey = 'return_item_id';

    protected $fillable = [
        'return_id',
        'receipt_item_id',
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

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'return_id');
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class, 'receipt_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'uom_id');
    }

    /** Qty (satuan base) yang sudah diretur atas satu baris penerimaan, hanya retur yang masih POSTED. */
    public static function sudahDiretur(int $receiptItemId): float
    {
        return (float) static::where('receipt_item_id', $receiptItemId)
            ->whereHas('purchaseReturn', fn ($q) => $q->where('status', PurchaseReturn::STATUS_POSTED))
            ->sum('qty_base');
    }
}

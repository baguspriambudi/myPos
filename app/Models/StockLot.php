<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu partai barang masuk berikut harga perolehannya.
 *
 * Dipakai penuh oleh metode FIFO; pada AVERAGE lot tetap dibentuk supaya
 * riwayat asal barang tidak hilang bila metode HPP diubah kelak.
 */
class StockLot extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_EXHAUSTED = 'EXHAUSTED';

    public $timestamps = false;

    protected $table = 'stock_lots';

    protected $primaryKey = 'lot_id';

    protected $fillable = [
        'branch_id',
        'product_id',
        'fiscal_period_id',
        'receipt_item_id',
        'return_item_id',
        'transfer_item_id',
        'lot_number',
        'received_date',
        'qty_remaining',
        'unit_cost',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'qty_remaining' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Urutan konsumsi FIFO: yang paling dulu diterima dipakai lebih dulu. */
    public function scopeFifo(Builder $query): Builder
    {
        return $query->orderBy('received_date')->orderBy('lot_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('qty_remaining', '>', 0);
    }
}

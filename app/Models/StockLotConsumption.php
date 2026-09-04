<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak lot mana yang terpakai oleh sebuah pergerakan keluar.
 *
 * Tanpa jejak ini, void dan retur tidak tahu qty harus dikembalikan ke lot yang
 * mana, dan FIFO tidak dapat dihitung ulang (Addendum 41.2).
 */
class StockLotConsumption extends Model
{
    public $timestamps = false;

    protected $table = 'stock_lot_consumptions';

    protected $primaryKey = 'consumption_id';

    protected $fillable = [
        'movement_id',
        'lot_id',
        'qty_base',
        'unit_cost',
        'total_cost',
        'reversed_by_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'qty_base' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Utang usaha kepada pemasok, lahir dari faktur yang diposting.
 *
 * Dibedakan dari Utang Belum Ditagih (penerimaan yang belum berfaktur):
 * yang itu belum punya nilai tagihan resmi, yang ini sudah.
 */
class Payable extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_PAID = 'PAID';

    public const STATUS_VOID = 'VOID';

    public $timestamps = false;

    protected $table = 'payables';

    protected $primaryKey = 'payable_id';

    protected $fillable = [
        'fiscal_period_id',
        'branch_id',
        'supplier_id',
        'purchase_id',
        'due_date',
        'amount',
        'paid_amount',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    /** Sisa yang belum dibayar. */
    public function outstanding(): float
    {
        return round((float) $this->amount - (float) $this->paid_amount, 2);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIAL]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Piutang usaha kepada pelanggan, lahir dari nota penjualan berbayar kredit.
 *
 * Mirip `Payable`, tapi sisi pelanggan: satu nota kredit = satu piutang,
 * pelunasannya kelak menjadi baris di `payments` yang ditaut lewat pivot
 * `sale_payments` milik nota yang sama (Addendum 41.3, 47).
 */
class Receivable extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_PAID = 'PAID';

    public const STATUS_VOID = 'VOID';

    public $timestamps = false;

    protected $table = 'receivables';

    protected $primaryKey = 'receivable_id';

    protected $fillable = [
        'fiscal_period_id',
        'branch_id',
        'customer_id',
        'sale_id',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shift kasir — buka/tutup laci (Addendum 50, PRD §18).
 *
 * `expected_cash` dihitung dari `cash_transactions` yang bertautan ke shift
 * ini lewat `shift_id` (termasuk baris OPENING_CASH saat shift dibuka), bukan
 * dari `opening_cash` + rumus manual — sehingga selalu konsisten dengan buku
 * kas yang sesungguhnya tertulis.
 */
class CashierShift extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    public const SOURCE_MANUAL = 'MANUAL';

    public const SOURCE_ROLLOVER = 'ROLLOVER';

    /** Prefix penomoran shift (Addendum 52.1). */
    public const PREFIX = 'SHF';

    public $timestamps = false;

    protected $table = 'cashier_shifts';

    protected $primaryKey = 'shift_id';

    protected $fillable = [
        'shift_number',
        'fiscal_period_id',
        'branch_id',
        'cashier_id',
        'opened_at',
        'closed_at',
        'opening_cash',
        'opening_source',
        'expected_cash',
        'actual_cash',
        'difference',
        'close_note',
        'status',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }
}

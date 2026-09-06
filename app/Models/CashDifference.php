<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Selisih kas shift dan penyelesaiannya (Addendum 46).
 *
 * Lahir otomatis saat shift ditutup dengan uang fisik berbeda dari buku kas.
 * Tidak pernah boleh dihapus, hanya ditandai RESOLVED beserta siapa, kapan,
 * dan alasannya: selisih kas yang bisa hilang dari daftar tidak ada gunanya
 * sebagai alat kontrol.
 */
class CashDifference extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_RESOLVED = 'RESOLVED';

    /** Saldo kas sistem disesuaikan ke fisik. */
    public const RESOLUTION_ADJUSTMENT = 'CASH_ADJUSTMENT';

    /** Kekurangan ditanggung kasir shift terkait. */
    public const RESOLUTION_CASHIER = 'CASHIER_CHARGE';

    /** Kekurangan ditutup sebagai biaya. */
    public const RESOLUTION_EXPENSE = 'OTHER_EXPENSE';

    /** Kelebihan diakui sebagai pendapatan lain. */
    public const RESOLUTION_INCOME = 'OTHER_INCOME';

    public const RESOLUTIONS = [
        self::RESOLUTION_ADJUSTMENT,
        self::RESOLUTION_CASHIER,
        self::RESOLUTION_EXPENSE,
        self::RESOLUTION_INCOME,
    ];

    public $timestamps = false;

    protected $table = 'cash_differences';

    protected $primaryKey = 'difference_id';

    protected $fillable = [
        'fiscal_period_id',
        'branch_id',
        'shift_id',
        'expected_cash',
        'actual_cash',
        'difference',
        'status',
        'resolution_type',
        'resolved_at',
        'resolved_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'shift_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}

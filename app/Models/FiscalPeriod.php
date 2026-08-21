<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fiscal period. branch_id NULL = berlaku seluruh lokasi milik owner.
 *
 * Gudang juga wajib punya period: transfer ditolak bila salah satu sisi tidak
 * punya period OPEN (Addendum 49.1 & 59.2).
 */
class FiscalPeriod extends Model
{
    protected $table = 'fiscal_periods';

    protected $primaryKey = 'fiscal_period_id';

    protected $fillable = [
        'owner_id',
        'branch_id',
        'period_code',
        'period_name',
        'start_date',
        'end_date',
        'status',
        'costing_method',
        'currency_code',
        'opened_at',
        'opened_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['OPEN', 'REOPENED']);
    }
}

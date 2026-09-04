<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Biaya operasional (Addendum 60).
 *
 * Tidak memengaruhi HPP maupun nilai persediaan — murni mengurangi laba
 * bersih. Yang bermetode CASH wajib membuat `cash_transactions` CASH_OUT
 * (Addendum 60.2); metode lain sekadar dicatat, tidak menyentuh buku kas.
 */
class Expense extends Model
{
    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    /** Prefix penomoran biaya (Addendum 52.1). */
    public const PREFIX = 'EXP';

    public $timestamps = false;

    protected $table = 'expenses';

    protected $primaryKey = 'expense_id';

    protected $fillable = [
        'expense_number',
        'fiscal_period_id',
        'branch_id',
        'shift_id',
        'expense_category_id',
        'transaction_date',
        'description',
        'amount',
        'payment_type',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCash(): bool
    {
        return $this->payment_type === Payment::TYPE_CASH;
    }
}

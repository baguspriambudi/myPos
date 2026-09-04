<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris buku kas (Addendum 41.4, 46, 60.2).
 *
 * amount BERTANDA: kas masuk positif, kas keluar negatif — sama seperti
 * qty_base pada stock_movements. `expected_cash` shift dihitung sebagai
 * SUM(amount) WHERE shift_id = X, termasuk baris OPENING_CASH-nya sendiri —
 * bukan opening_cash + rumus manual (lihat CashierShift).
 */
class CashTransaction extends Model
{
    public const TYPE_OPENING_CASH = 'OPENING_CASH';

    public const TYPE_CASH_SALE = 'CASH_SALE';

    public const TYPE_CASH_IN = 'CASH_IN';

    public const TYPE_CASH_OUT = 'CASH_OUT';

    public const TYPE_REFUND = 'REFUND';

    public const REF_SALES = 'SALES';

    public const REF_EXPENSE = 'EXPENSE';

    public const REF_PAYMENT = 'PAYMENT';

    public const REF_RECEIVABLE = 'RECEIVABLE';

    public $timestamps = false;

    protected $table = 'cash_transactions';

    protected $primaryKey = 'cash_txn_id';

    protected $fillable = [
        'fiscal_period_id',
        'branch_id',
        'shift_id',
        'transaction_date',
        'txn_type',
        'amount',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}

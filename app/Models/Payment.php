<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penerimaan uang. Satu baris = satu pembayaran dengan satu metode.
 *
 * Penjualan KREDIT tidak membuat baris di sini — piutangnya dicatat di
 * receivables, dan barulah pelunasannya kelak menjadi payment (Addendum 41.3).
 */
class Payment extends Model
{
    public const TYPE_CASH = 'CASH';

    public const TYPES = ['CASH', 'QRIS', 'DEBIT', 'CREDIT_CARD', 'TRANSFER', 'E_WALLET'];

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    /** Prefix penomoran pembayaran (Addendum 52.1). */
    public const PREFIX = 'PAY';

    public $timestamps = false;

    protected $table = 'payments';

    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'payment_number',
        'fiscal_period_id',
        'branch_id',
        'shift_id',
        'transaction_date',
        'payment_type',
        'amount',
        'currency_code',
        'exchange_rate',
        'status',
        'voided_at',
        'voided_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'voided_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    /** Metode tunai adalah satu-satunya yang terkena pembulatan (Addendum 51.4). */
    public function isCash(): bool
    {
        return $this->payment_type === self::TYPE_CASH;
    }
}

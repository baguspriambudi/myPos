<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retur penjualan — barang kembali dari pelanggan (Addendum 48).
 *
 * Memakai fiscal period TANGGAL RETUR, bukan period nota asalnya, karena
 * retur boleh terjadi lintas period bahkan setelah period asal CLOSED
 * (Addendum 48.1, 48.3). Period nota lama tidak pernah disentuh.
 */
class SalesReturn extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    public const PREFIX = 'RTJ';

    public $timestamps = false;

    protected $table = 'sales_returns';

    protected $primaryKey = 'return_id';

    protected $fillable = [
        'return_number',
        'fiscal_period_id',
        'branch_id',
        'sale_id',
        'transaction_date',
        'status',
        'refund_total',
        'voided_at',
        'voided_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'refund_total' => 'decimal:2',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'return_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
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
}

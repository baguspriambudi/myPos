<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Faktur pemasok: mengakui utang dan pajak masukan.
 *
 * Faktur TIDAK menambah stok — yang menambah stok adalah penerimaan barang
 * (Addendum 58.3). Satu faktur boleh mencakup beberapa penerimaan, dan
 * penerimaan yang sudah difakturkan tidak dapat difakturkan ulang.
 */
class Purchase extends Model
{
    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    public const PREFIX = 'PUR';

    public $timestamps = false;

    protected $table = 'purchases';

    protected $primaryKey = 'purchase_id';

    protected $fillable = [
        'purchase_number',
        'fiscal_period_id',
        'branch_id',
        'supplier_id',
        'transaction_date',
        'status',
        'subtotal',
        'tax_total',
        'total',
        'currency_code',
        'exchange_rate',
        'voided_at',
        'voided_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class, 'purchase_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'purchase_payments', 'purchase_id', 'payment_id')
            ->withPivot('amount');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }
}

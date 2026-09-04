<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penerimaan barang — dokumen yang MENAMBAH STOK (Addendum 58.2).
 *
 * Bukan fakturnya. Di toko barang hampir selalu datang lebih dulu dan
 * fakturnya menyusul, sering dengan jumlah yang tidak sama persis. Karena itu
 * kedatangan barang dan pengakuan utang dipisah menjadi dua dokumen.
 *
 * Selama `purchase_id` masih NULL, nilainya duduk di akun Utang Belum Ditagih
 * dan ikut menahan penutupan periode (Addendum 58.5).
 */
class GoodsReceipt extends Model
{
    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    public const PREFIX = 'GRN';

    public $timestamps = false;

    protected $table = 'goods_receipts';

    protected $primaryKey = 'goods_receipt_id';

    protected $fillable = [
        'receipt_number',
        'fiscal_period_id',
        'branch_id',
        'supplier_id',
        'purchase_id',
        'receipt_date',
        'status',
        'supplier_do_number',
        'note',
        'total_cost',
        'invoiced_at',
        'voided_at',
        'voided_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'total_cost' => 'decimal:2',
            'invoiced_at' => 'datetime',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
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

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sudah diterima tetapi fakturnya belum datang — inilah nilai GRNI. */
    public function scopeUninvoiced(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED)->whereNull('purchase_id');
    }
}

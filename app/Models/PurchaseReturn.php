<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retur pembelian — barang dikembalikan ke pemasok.
 *
 * Mengacu pada PENERIMAAN, bukan faktur: lot persediaan lahir dari
 * penerimaan, jadi partai yang dikurangi juga harus ditelusuri lewat
 * penerimaan (Addendum 48.2, 58.6). Faktur tidak pernah tahu lot mana yang
 * dikirim; ia hanya mengakui utang.
 */
class PurchaseReturn extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    public const PREFIX = 'RTB';

    public $timestamps = false;

    protected $table = 'purchase_returns';

    protected $primaryKey = 'return_id';

    protected $fillable = [
        'return_number',
        'fiscal_period_id',
        'branch_id',
        'goods_receipt_id',
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
        return $this->hasMany(PurchaseReturnItem::class, 'return_id');
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
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

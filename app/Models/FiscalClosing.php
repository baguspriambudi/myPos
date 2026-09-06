<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Snapshot hasil penutupan period (PRD 20, Addendum 25).
 *
 * Snapshot BUKAN cache: ia adalah angka resmi period tersebut. Menutup ulang
 * period yang dibuka kembali tidak menimpa baris lama melainkan membuat versi
 * baru, dan yang lama ditandai REPLACED supaya tetap dapat ditelusuri tanpa
 * pernah ikut terbaca laporan.
 *
 * Persamaan yang selalu dipenuhi baris ini:
 *   ending_inventory_value = opening + purchase - purchase_return
 *                            - sales_cost + sales_return_cost + adjustment
 *   net_profit             = gross_profit - expense_total
 *   ending_cash            = opening_cash + cash_sales + cash_in - cash_out - refund
 */
class FiscalClosing extends Model
{
    public const STATUS_VALID = 'VALID';

    /** Digantikan snapshot versi berikutnya setelah reopen (Addendum 45.3). */
    public const STATUS_REPLACED = 'REPLACED';

    public const STATUS_FAILED = 'FAILED';

    public $timestamps = false;

    protected $table = 'fiscal_closings';

    protected $primaryKey = 'fiscal_closing_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_inventory_value' => 'decimal:2',
            'purchase_value' => 'decimal:2',
            'purchase_return_value' => 'decimal:2',
            'sales_cost_value' => 'decimal:2',
            'sales_return_cost_value' => 'decimal:2',
            'adjustment_value' => 'decimal:2',
            'ending_inventory_value' => 'decimal:2',
            'cogs_value' => 'decimal:2',
            'gross_profit' => 'decimal:2',
            'expense_total' => 'decimal:2',
            'net_profit' => 'decimal:2',
            'opening_cash' => 'decimal:2',
            'cash_sales' => 'decimal:2',
            'cash_in' => 'decimal:2',
            'cash_out' => 'decimal:2',
            'refund' => 'decimal:2',
            'ending_cash' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'gross_sales' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'net_sales' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'payment_rounding_total' => 'decimal:2',
            'payment_cash' => 'decimal:2',
            'payment_qris' => 'decimal:2',
            'payment_debit' => 'decimal:2',
            'payment_credit_card' => 'decimal:2',
            'payment_transfer' => 'decimal:2',
            'payment_ewallet' => 'decimal:2',
            'closed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FiscalClosingItem::class, 'fiscal_closing_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALID);
    }
}

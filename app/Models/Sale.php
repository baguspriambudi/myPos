<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nota penjualan.
 *
 * Dua angka uang yang mudah tertukar:
 *   net_total   nilai penjualan resmi — dasar pajak dan laporan omzet
 *   paid_total  uang yang benar-benar diterima = net_total + rounding_amount
 *
 * Pembulatan pembayaran tunai (Addendum 51.4) hanya menyentuh paid_total.
 * Menjadikannya diskon akan mengecilkan omzet dan dasar pajak, padahal
 * barangnya tetap terjual seharga penuh.
 */
class Sale extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    /** Prefix penomoran nota penjualan (Addendum 52.1). */
    public const PREFIX = 'INV';

    /**
     * Bukan nilai `payments.payment_type` — penjualan kredit tidak pernah
     * membuat baris `payments` sama sekali, piutangnya dicatat di
     * `receivables` (Addendum 41.3). Nilai ini hanya dipakai SalePoster untuk
     * memilih jalur mana yang diambil.
     */
    public const PAYMENT_TYPE_CREDIT = 'CREDIT';

    protected $table = 'sales';

    protected $primaryKey = 'sale_id';

    protected $fillable = [
        'sale_number',
        'fiscal_period_id',
        'branch_id',
        'shift_id',
        'customer_id',
        'discount_id',
        'device_id',
        'local_uuid',
        'transaction_date',
        'status',
        'gross_total',
        'discount_total',
        'tax_total',
        'net_total',
        'rounding_amount',
        'paid_total',
        'currency_code',
        'exchange_rate',
        'voided_at',
        'voided_by',
        'void_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'gross_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'rounding_amount' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'voided_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'sale_payments', 'sale_id', 'payment_id')
            ->withPivot('amount');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    /** Total HPP nota; dijumlahkan dari baris, bukan disimpan di header. */
    public function cogsTotal(): float
    {
        return (float) $this->items->sum('cogs_total');
    }
}

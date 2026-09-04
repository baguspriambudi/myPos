<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock opname — lembar hitungan fisik (Addendum 39.8, 50, 59.3).
 *
 * Dua tahap yang sengaja terpisah: mencatat hitungan (DRAFT, terbuka untuk
 * semua peran termasuk KASIR/GUDANG) dan menerapkannya (APPLIED, hanya
 * MANAGER ke atas) — supaya siapa pun boleh menghitung fisik, tapi hanya
 * atasannya yang mengesahkan selisih menjadi perubahan saldo sungguhan.
 *
 * Menerapkan opname TIDAK menulis stock_movements sendiri; ia membuat satu
 * StockAdjustment (lewat StockAdjustmentPoster yang sudah ada) per baris yang
 * selisih, supaya jejak dan aturannya (alasan, nomor, hak akses keluar/masuk)
 * tetap satu jalur dengan penyesuaian manual.
 */
class StockOpname extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const PREFIX = 'OPN';

    public $timestamps = false;

    protected $table = 'stock_opnames';

    protected $primaryKey = 'opname_id';

    protected $fillable = [
        'opname_number',
        'fiscal_period_id',
        'branch_id',
        'opname_date',
        'status',
        'applied_at',
        'applied_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opname_date' => 'date',
            'applied_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class, 'opname_id');
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

    public function appliedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}

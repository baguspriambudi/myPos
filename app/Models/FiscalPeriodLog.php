<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak perubahan status period (PRD 24, Addendum 45).
 *
 * Termasuk persetujuan eksplisit OWNER untuk menutup period yang masih
 * bermasalah — stok negatif (Addendum 55.3) atau penerimaan belum
 * difakturkan (Addendum 56.18). Persetujuan itu wajib meninggalkan jejak,
 * bukan sekadar centang di layar yang hilang setelah halaman ditutup.
 */
class FiscalPeriodLog extends Model
{
    public const ACTION_CREATE = 'CREATE';

    public const ACTION_OPEN = 'OPEN';

    public const ACTION_CLOSING = 'CLOSING';

    public const ACTION_CLOSED = 'CLOSED';

    public const ACTION_REOPENED = 'REOPENED';

    public const ACTION_FAILED = 'FAILED';

    /** Persetujuan OWNER menembus blokir pre-closing (Addendum 55.3). */
    public const ACTION_OVERRIDE = 'OVERRIDE';

    /** Period CLOSING yang tertinggal dipulihkan job recovery (Addendum 44). */
    public const ACTION_RECOVERED = 'RECOVERED';

    public $timestamps = false;

    protected $table = 'fiscal_period_logs';

    protected $primaryKey = 'log_id';

    protected $fillable = [
        'fiscal_period_id',
        'action',
        'old_status',
        'new_status',
        'action_by',
        'action_at',
        'reason',
        'closing_snapshot_id',
    ];

    protected function casts(): array
    {
        return ['action_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_by');
    }
}

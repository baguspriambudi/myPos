<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan buka ulang period beserta persetujuannya (Addendum 45).
 *
 * Tidak ada auto-approve: yang mengajukan dan yang menyetujui selalu tercatat
 * terpisah. Pengecualiannya SELF_APPROVED — owner tunggal boleh menyetujui
 * permintaannya sendiri supaya alurnya tidak buntu, tetapi ditandai berbeda
 * agar terlihat jelas saat diaudit (Addendum 45.2).
 */
class FiscalPeriodReopenRequest extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const APPROVAL_NORMAL = 'NORMAL';

    public const APPROVAL_SELF = 'SELF_APPROVED';

    public $timestamps = false;

    protected $table = 'fiscal_period_reopen_requests';

    protected $primaryKey = 'reopen_request_id';

    protected $fillable = [
        'fiscal_period_id',
        'requested_by',
        'reason',
        'status',
        'approval_type',
        'reviewed_by',
        'reviewed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

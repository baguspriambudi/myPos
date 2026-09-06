<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak langkah proses closing untuk recovery (Addendum 44).
 *
 * Baris di sini juga menjadi penanda "masih ada progres": period berstatus
 * CLOSING lebih dari 15 menit tanpa baris baru dianggap tertinggal dan
 * dikembalikan oleh perintah pemulihan.
 */
class ClosingAttemptLog extends Model
{
    public const STATUS_STARTED = 'STARTED';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STEP_VALIDATE = 'VALIDASI PRA-TUTUP';

    public const STEP_INVENTORY = 'HITUNG PERSEDIAAN & HPP';

    public const STEP_SALES = 'HITUNG PENJUALAN & BIAYA';

    public const STEP_CASH = 'HITUNG KAS';

    public const STEP_SNAPSHOT = 'TULIS SNAPSHOT';

    public $timestamps = false;

    protected $table = 'closing_attempt_logs';

    protected $primaryKey = 'attempt_log_id';

    protected $fillable = [
        'fiscal_period_id',
        'step',
        'status',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }
}

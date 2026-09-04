<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfer stok antar-lokasi (Addendum 49).
 *
 * Tahap 1 bersifat langsung: begitu POSTED, movement TRANSFER_OUT di branch
 * pengirim dan TRANSFER_IN di branch penerima terbentuk bersamaan dalam satu
 * database transaction — tidak ada status IN_TRANSIT (Addendum 49.2).
 *
 * Nomornya di-scope ke branch PENGIRIM saja (`uk_transfers_number`); dokumen
 * ini "milik" sisi yang mengirim.
 */
class StockTransfer extends Model
{
    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    public const PREFIX = 'TRF';

    public $timestamps = false;

    protected $table = 'stock_transfers';

    protected $primaryKey = 'transfer_id';

    protected $fillable = [
        'transfer_number',
        'from_branch_id',
        'to_branch_id',
        'transaction_date',
        'status',
        'voided_at',
        'voided_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'transfer_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

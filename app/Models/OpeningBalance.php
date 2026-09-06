<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo awal period (PRD 10, Addendum 40).
 *
 * Hanya diisi lewat Initial Opening period PERTAMA. Period kedua dan
 * seterusnya mengambil saldo awalnya dari snapshot closing period sebelumnya,
 * tidak dari tabel ini (Addendum 40.4).
 *
 * Saldo kas di sini SENGAJA tidak menulis `cash_transactions`: baris
 * OPENING_CASH di buku kas dipakai shift kasir, dan shift pertama sudah
 * memasukkan uang laci lewat jalur itu. Menulis dua-duanya membuat kas
 * terhitung dobel.
 */
class OpeningBalance extends Model
{
    public const TYPE_CASH = 'CASH';

    public const TYPE_INVENTORY = 'INVENTORY';

    public const TYPE_RECEIVABLE = 'RECEIVABLE';

    public const TYPE_PAYABLE = 'PAYABLE';

    public const TYPE_OTHER = 'OTHER';

    public $timestamps = false;

    protected $table = 'opening_balances';

    protected $primaryKey = 'opening_balance_id';

    protected $fillable = [
        'fiscal_period_id',
        'branch_id',
        'balance_type',
        'amount',
        'note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}

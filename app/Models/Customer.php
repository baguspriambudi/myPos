<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pelanggan.
 *
 * Dua jenis yang sengaja dibedakan:
 *
 * - Pelanggan tunai bawaan (is_default), satu per usaha. Kasir tidak perlu
 *   memilih pelanggan untuk penjualan eceran — semuanya masuk ke sini supaya
 *   laporan per pelanggan tetap rapi.
 * - Member (is_member), yaitu pelanggan yang memang didaftarkan. Member otomatis
 *   memakai daftar harga bertanda member_only; tidak ada penugasan per pelanggan
 *   supaya harga cukup ditentukan satu kondisi saja (lihat PriceResolver).
 */
class Customer extends Model
{
    protected $table = 'customers';

    protected $primaryKey = 'customer_id';

    protected $fillable = [
        'owner_id',
        'branch_id',
        'customer_name',
        'phone',
        'is_member',
        'is_default',
        'credit_limit',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'is_member' => 'boolean',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** Pelanggan tunai bawaan milik usaha ini; tujuan seluruh penjualan tanpa pelanggan. */
    public static function defaultFor(int $ownerId): ?self
    {
        return static::where('owner_id', $ownerId)->where('is_default', true)->first();
    }

    public function scopeMembers(Builder $query): Builder
    {
        return $query->where('is_member', true);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}

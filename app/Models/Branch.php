<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lokasi: toko, depo, atau gudang (Addendum 59).
 *
 * Gudang bukan tabel terpisah — ia adalah Branch dengan branch_type WAREHOUSE.
 * Yang menentukan boleh-tidaknya menjual adalah kolom can_sell, bukan tipenya,
 * supaya gudang yang sesekali melayani grosir tidak perlu berganti tipe.
 */
class Branch extends Model
{
    use HasFactory;

    public const TYPE_STORE = 'STORE';

    public const TYPE_DEPO = 'DEPO';

    public const TYPE_WAREHOUSE = 'WAREHOUSE';

    public const TYPES = [self::TYPE_STORE, self::TYPE_DEPO, self::TYPE_WAREHOUSE];

    protected $table = 'branches';

    protected $primaryKey = 'branch_id';

    protected $fillable = [
        'owner_id',
        'branch_code',
        'branch_name',
        'branch_type',
        'can_sell',
        'address',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'can_sell' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    /** User yang lokasi utamanya di sini (users.branch_id). */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'branch_id');
    }

    /** Nilai bawaan can_sell untuk sebuah tipe (Addendum 59.2). */
    public static function defaultCanSell(string $branchType): bool
    {
        return $branchType !== self::TYPE_WAREHOUSE;
    }

    public function isWarehouse(): bool
    {
        return $this->branch_type === self::TYPE_WAREHOUSE;
    }

    /** Lokasi yang boleh melayani penjualan dan membuka shift kasir. */
    public function scopeSelling(Builder $query): Builder
    {
        return $query->where('can_sell', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function typeLabel(): string
    {
        return match ($this->branch_type) {
            self::TYPE_WAREHOUSE => 'Gudang',
            self::TYPE_DEPO => 'Depo',
            default => 'Toko',
        };
    }
}

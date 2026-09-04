<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Daftar harga jual (Addendum 39.10).
 *
 * Satu-satunya sumber harga jual. Boleh dibatasi ke satu lokasi, diberi masa
 * berlaku, dan diprioritaskan lewat kolom priority. Produk yang tidak punya
 * baris di daftar harga mana pun tidak dapat dijual (lihat PriceResolver).
 */
class PriceList extends Model
{
    protected $table = 'price_lists';

    protected $primaryKey = 'price_list_id';

    protected $fillable = [
        'owner_id',
        'branch_id',
        'price_list_code',
        'price_list_name',
        'is_default',
        'member_only',
        'priority',
        'start_date',
        'end_date',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'member_only' => 'boolean',
            'active' => 'boolean',
            'priority' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    /** NULL = berlaku untuk seluruh lokasi milik owner. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class, 'price_list_id');
    }

    /** Aktif dan masa berlakunya mencakup tanggal tersebut. */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}

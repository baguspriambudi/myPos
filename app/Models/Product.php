<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $table = 'products';

    protected $primaryKey = 'product_id';

    protected $fillable = [
        'owner_id',
        'category_id',
        'product_name',
        'sku',
        'barcode',
        'base_uom_id',
        'is_weighted',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'is_weighted' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** Seluruh satuan produk ini; harga standar melekat di sini. */
    public function uoms(): HasMany
    {
        return $this->hasMany(ProductUom::class, 'product_id')->orderBy('conversion_factor');
    }

    public function baseUom(): HasOne
    {
        return $this->hasOne(ProductUom::class, 'uom_id', 'base_uom_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}

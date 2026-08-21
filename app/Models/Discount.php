<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Discount extends Model
{
    protected $table = 'discounts';

    protected $primaryKey = 'discount_id';

    protected $fillable = [
        'owner_id',
        'discount_name',
        'discount_scope',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'product_id',
        'category_id',
        'min_qty',
        'min_amount',
        'start_date',
        'end_date',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_qty' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Owner extends Model
{
    use HasFactory;

    protected $table = 'owners';

    protected $primaryKey = 'owner_id';

    protected $fillable = [
        'owner_code',
        'owner_name',
        'default_currency',
        'costing_method',
        'allow_negative_stock',
        'payment_rounding_unit',
        'payment_rounding_mode',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'allow_negative_stock' => 'boolean',
            'active' => 'boolean',
            'payment_rounding_unit' => 'integer',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'owner_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'owner_id');
    }
}

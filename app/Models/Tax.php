<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tax extends Model
{
    protected $table = 'taxes';

    protected $primaryKey = 'tax_id';

    protected $fillable = ['owner_id', 'tax_name', 'tax_rate', 'active'];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }
}

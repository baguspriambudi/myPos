<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satuan produk. conversion_factor selalu relatif terhadap base unit:
 * DUS dengan factor 12 berarti 1 dus = 12 pcs.
 *
 * selling_price di sini adalah HARGA STANDAR — dipakai bila tidak ada baris
 * daftar harga yang cocok (Addendum 39.11 langkah 5).
 */
class ProductUom extends Model
{
    public $timestamps = false;

    public const TYPE_BASE = 'BASE';

    public const TYPE_PACK = 'PACK';

    protected $table = 'product_uoms';

    protected $primaryKey = 'uom_id';

    protected $fillable = [
        'product_id',
        'uom_name',
        'uom_type',
        'conversion_factor',
        'is_default',
        'selling_price',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:6',
            'selling_price' => 'decimal:2',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function isBase(): bool
    {
        return $this->uom_type === self::TYPE_BASE;
    }
}

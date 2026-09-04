<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penyesuaian stok: satu dokumen, satu produk.
 *
 * Dipakai untuk hal yang tidak punya dokumen sendiri — barang rusak, susut,
 * hilang, temuan selisih, dan stok awal saat sistem mulai dipakai (Addendum
 * 40.4). Wajib beralasan; itulah yang membedakannya dari mengetik ulang saldo.
 *
 * Yang MASUK menyebut harga perolehannya sendiri (`unit_cost`). Yang KELUAR
 * tidak: harganya ditentukan lot yang benar-benar terpakai, sama seperti
 * penjualan.
 */
class StockAdjustment extends Model
{
    public const TYPE_IN = 'IN';

    public const TYPE_OUT = 'OUT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    /** Prefix penomoran penyesuaian (Addendum 52.1). */
    public const PREFIX = 'ADJ';

    public $timestamps = false;

    protected $table = 'stock_adjustments';

    protected $primaryKey = 'adjustment_id';

    protected $fillable = [
        'adjustment_number',
        'fiscal_period_id',
        'branch_id',
        'adjustment_date',
        'product_id',
        'uom_id',
        'adjustment_type',
        'qty',
        'qty_base',
        'unit_cost',
        'reason',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'uom_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isIn(): bool
    {
        return $this->adjustment_type === self::TYPE_IN;
    }
}

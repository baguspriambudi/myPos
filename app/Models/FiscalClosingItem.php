<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian snapshot closing per produk (PRD 21, Addendum 51.3).
 *
 * `rounding_adjustment` menampung selisih antara COGS yang sudah dibulatkan
 * per transaksi dan nilai persediaan yang benar-benar keluar pada presisi
 * empat desimal. COGS transaksi yang sudah POSTED tidak boleh diubah proses
 * closing, jadi selisihnya diakui di sini, bukan dengan mengoreksi struk.
 */
class FiscalClosingItem extends Model
{
    public $timestamps = false;

    protected $table = 'fiscal_closing_items';

    protected $primaryKey = 'closing_item_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_qty' => 'decimal:4',
            'opening_value' => 'decimal:2',
            'purchase_qty' => 'decimal:4',
            'purchase_value' => 'decimal:2',
            'sales_qty' => 'decimal:4',
            'cogs' => 'decimal:2',
            'adjustment_qty' => 'decimal:4',
            'adjustment_value' => 'decimal:2',
            'return_qty' => 'decimal:4',
            'return_value' => 'decimal:2',
            'ending_qty' => 'decimal:4',
            'ending_value' => 'decimal:2',
            'average_cost' => 'decimal:4',
            'rounding_adjustment' => 'decimal:2',
        ];
    }

    public function closing(): BelongsTo
    {
        return $this->belongsTo(FiscalClosing::class, 'fiscal_closing_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

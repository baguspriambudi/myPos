<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris hitungan fisik.
 *
 * `system_qty*` disnapshot SERVER saat hitungan disimpan (bukan dikirim
 * layar) — angka yang dipercaya selalu dihitung ulang dari buku stok, bukan
 * dari input pengguna. Bisa berbeda dari saldo saat opname DITERAPKAN kalau
 * ada transaksi lain di antaranya; StockOpnamePoster::apply() menghitung
 * ulang saldo TERKINI saat itu, bukan memakai angka di sini mentah-mentah.
 */
class StockOpnameItem extends Model
{
    public $timestamps = false;

    protected $table = 'stock_opname_items';

    protected $primaryKey = 'opname_item_id';

    protected $fillable = [
        'opname_id',
        'product_id',
        'uom_id',
        'system_qty',
        'system_qty_base',
        'actual_qty',
        'actual_qty_base',
        'difference_qty_base',
        'unit_cost',
        'difference_value',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:4',
            'system_qty_base' => 'decimal:4',
            'actual_qty' => 'decimal:4',
            'actual_qty_base' => 'decimal:4',
            'difference_qty_base' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'difference_value' => 'decimal:2',
        ];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'opname_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'uom_id');
    }
}

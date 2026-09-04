<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris barang yang diterima.
 *
 * `unit_cost` di sini adalah cost SEMENTARA yang diisi sistem, bukan harga
 * dari pemasok — kasir dan petugas gudang memang tidak boleh menyentuh angka
 * yang memengaruhi HPP (Addendum 58.2). `cost_source` menyimpan dari mana
 * angka sementara itu diambil supaya selisihnya terhadap faktur bisa dijelaskan.
 */
class GoodsReceiptItem extends Model
{
    /** Urutan sumber cost sementara (Addendum 58.2). */
    public const SOURCE_LAST_PURCHASE = 'LAST_PURCHASE';

    public const SOURCE_AVG_COST = 'AVG_COST';

    public const SOURCE_NONE = 'NONE';

    public const SOURCE_INVOICE = 'INVOICE';

    public $timestamps = false;

    protected $table = 'goods_receipt_items';

    protected $primaryKey = 'receipt_item_id';

    protected $fillable = [
        'goods_receipt_id',
        'product_id',
        'uom_id',
        'qty',
        'qty_base',
        'unit_cost',
        'unit_cost_base',
        'cost_source',
        'line_total',
        'purchase_item_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'unit_cost_base' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
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

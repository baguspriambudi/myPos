<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo stok terkini per lokasi + produk, dalam satuan base.
 *
 * Ini cache turunan dari stock_movements, disimpan supaya layar kasir tidak
 * perlu menjumlahkan seluruh riwayat setiap kali menampilkan sisa stok.
 * avg_cost dipelihara di sini karena metode AVERAGE membacanya tiap transaksi.
 */
class StockBalance extends Model
{
    public $timestamps = false;

    protected $table = 'stock_balances';

    protected $primaryKey = 'stock_balance_id';

    protected $fillable = [
        'branch_id',
        'product_id',
        'qty_on_hand',
        'avg_cost',
    ];

    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'decimal:4',
            'avg_cost' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

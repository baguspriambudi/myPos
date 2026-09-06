<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pergerakan stok satu produk.
 *
 * qty dan qty_base BERTANDA: keluar bernilai negatif, masuk positif. Jumlah
 * seluruh qty_base sebuah produk di satu lokasi harus sama dengan saldonya —
 * itulah gunanya tabel ini sebagai dasar hitung ulang HPP (PRD 15).
 *
 * sequence berurut per (period, lokasi, produk), bukan global, supaya transaksi
 * yang dicatat mundur dapat disisipkan tanpa menomori ulang seluruh riwayat.
 */
class StockMovement extends Model
{
    public const TYPE_OPENING = 'OPENING';

    public const TYPE_SALES = 'SALES';

    /** Penambahan stok dari penerimaan barang (Addendum 58.2). */
    public const TYPE_PURCHASE = 'PURCHASE';

    public const TYPE_VOID_REVERSAL = 'VOID_REVERSAL';

    public const TYPE_ADJUSTMENT_IN = 'ADJUSTMENT_IN';

    public const TYPE_ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';

    /** Barang kembali dari pelanggan (Addendum 48.2). */
    public const TYPE_RETURN_IN = 'RETURN_IN';

    /** Barang keluar kembali ke pemasok, mengurangi lot penerimaan asal (Addendum 48.2, 58.6). */
    public const TYPE_RETURN_OUT = 'RETURN_OUT';

    /** Keluar dari branch pengirim transfer (Addendum 49). */
    public const TYPE_TRANSFER_OUT = 'TRANSFER_OUT';

    /** Masuk ke branch penerima transfer, lot baru dengan cost dari pengirim (Addendum 49.3). */
    public const TYPE_TRANSFER_IN = 'TRANSFER_IN';

    /** Saldo awal period pertama (Addendum 40.2). */
    public const REF_OPENING = 'OPENING';

    /** Koreksi pembulatan HPP yang dibuat proses closing (Addendum 51.3). */
    public const REF_CLOSING = 'CLOSING';

    public const REF_SALES = 'SALES';

    public const REF_ADJUSTMENT = 'ADJUSTMENT';

    public const REF_RECEIPT = 'RECEIPT';

    public const REF_SALES_RETURN = 'SALES_RETURN';

    public const REF_PURCHASE_RETURN = 'PURCHASE_RETURN';

    public const REF_TRANSFER = 'TRANSFER';

    public $timestamps = false;

    protected $table = 'stock_movements';

    protected $primaryKey = 'movement_id';

    protected $fillable = [
        'fiscal_period_id',
        'branch_id',
        'product_id',
        'movement_date',
        'movement_type',
        'reference_type',
        'reference_id',
        'uom_id',
        'qty',
        'qty_base',
        'unit_cost',
        'total_cost',
        'sequence',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(StockLotConsumption::class, 'movement_id');
    }
}

<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Aturan kuantitas yang berlaku di SELURUH transaksi persediaan.
 *
 * Penentu boleh-tidaknya pecahan adalah `products.is_weighted`, bukan nama
 * satuannya (Addendum 39.6): 0,25 kg sah, 0,25 pcs tidak. Aturannya dikumpulkan
 * di sini karena penjualan, penyesuaian, penerimaan, dan opname harus menolak
 * hal yang sama persis — kalau salah satu longgar, saldo stok akan berisi
 * pecahan yang tidak mungkin ada wujudnya.
 */
class QtyRule
{
    public static function assert(Product $product, float $qty, string $field): void
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages([$field => 'Kuantitas harus lebih dari nol.']);
        }

        if (! $product->is_weighted && fmod($qty, 1.0) !== 0.0) {
            throw ValidationException::withMessages([
                $field => "{$product->product_name} tidak dihitung dalam pecahan — isi kuantitasnya dengan bilangan bulat.",
            ]);
        }

        if ($product->is_weighted && round($qty, 2) !== $qty) {
            throw ValidationException::withMessages([
                $field => "Kuantitas {$product->product_name} paling banyak dua angka di belakang koma.",
            ]);
        }
    }
}

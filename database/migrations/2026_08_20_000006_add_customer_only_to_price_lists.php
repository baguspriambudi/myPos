<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menutup lubang pada resolusi harga (Addendum 39.11).
 *
 * Tanpa penanda ini, setiap daftar harga owner-level yang aktif ikut menjadi
 * kandidat harga umum. Akibatnya "Harga Grosir" yang seharusnya hanya berlaku
 * bagi pelanggan yang ditugaskan justru menang atas "Harga Umum" karena
 * prioritasnya lebih tinggi — pembeli eceran ikut dapat harga grosir.
 *
 * Daftar harga bertanda customer_only hanya dapat dijangkau lewat
 * customers.price_list_id, tidak pernah lewat pencarian umum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->boolean('customer_only')
                ->default(false)
                ->after('is_default')
                ->comment('hanya berlaku bagi pelanggan yang ditugaskan; dilewati resolusi umum');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropColumn('customer_only');
        });
    }
};

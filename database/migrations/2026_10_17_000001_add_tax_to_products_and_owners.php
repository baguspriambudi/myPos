<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pajak penjualan per produk (PRD 19, Addendum 51.2).
 *
 * Skema awal punya master `taxes` dan `sale_taxes`, tetapi tidak ada yang
 * mengaitkan produk ke pajaknya. Keputusannya: pajak melekat pada PRODUK
 * (NULL = tidak dikenai pajak), dan owner menentukan apakah harga jual di
 * daftar harga sudah termasuk pajak.
 *
 * `prices_include_tax` bawaannya TRUE: harga di rak tetap seperti tercetak
 * dan PPN dihitung dari dalamnya, jadi memasang pajak pada produk tidak
 * pernah diam-diam menaikkan harga yang dibayar pembeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('tax_id')->nullable()->after('category_id')->comment('NULL = tidak dikenai pajak');
            $table->foreign('tax_id')->references('tax_id')->on('taxes');
        });

        Schema::table('owners', function (Blueprint $table) {
            $table->boolean('prices_include_tax')->default(true)->after('payment_rounding_mode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropColumn('tax_id');
        });

        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn('prices_include_tax');
        });
    }
};

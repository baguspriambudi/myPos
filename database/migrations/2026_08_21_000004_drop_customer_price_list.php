<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harga ditentukan oleh JENIS pelanggan, bukan oleh penugasan per pelanggan.
 *
 * Sebelumnya tiap pelanggan bisa ditunjuk ke satu daftar harga sendiri. Itu
 * berarti dua tempat harus cocok (pelanggan ini member? daftar harganya yang
 * mana?) dan setiap member baru harus diingat untuk ditugaskan — kalau lupa,
 * dia diam-diam membayar harga eceran.
 *
 * Sekarang cukup satu kondisi: pelanggan member memakai daftar harga bertanda
 * member_only, pelanggan tunai memakai harga normal. Karena itu
 * customers.price_list_id dibuang.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pelanggan yang terlanjur ditugaskan ke daftar harga member jelas
        // dimaksudkan sebagai member; jangan sampai kehilangan harganya.
        DB::table('customers')
            ->whereIn('price_list_id', DB::table('price_lists')->where('member_only', true)->select('price_list_id'))
            ->update(['is_member' => true]);

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['price_list_id']);
            $table->dropColumn('price_list_id');
        });
    }

    /** Kolomnya kembali kosong: penugasan lama sudah menjadi penanda is_member. */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('price_list_id')->nullable()->after('is_default');
            $table->foreign('price_list_id')->references('price_list_id')->on('price_lists');
        });
    }
};

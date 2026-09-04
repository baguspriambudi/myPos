<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Masa berlaku pindah ke baris harga, dan priority digantikan start_date.
 *
 * Sebelumnya harga diganti dengan menimpa nilainya. Akibatnya riwayat bohong:
 * penjualan yang di-backdate ke bulan lalu ikut memakai harga hari ini, padahal
 * struk hari itu mencetak angka lain.
 *
 * Sekarang harga tidak pernah ditimpa — mengganti harga berarti menambah baris
 * baru dengan start_date lebih baru. Di antara baris yang masa berlakunya
 * mencakup tanggal transaksi, yang PALING BARU MULAI yang menang.
 *
 * Itu sekaligus menghapus kebutuhan menutup baris lama secara manual:
 *
 *   Rinso PCS  [1900-01-01 .. 9999-12-31]  2.500   <- dibiarkan apa adanya
 *   Rinso PCS  [2026-08-21 .. 9999-12-31]  2.300   <- menang sejak 21 Agu
 *
 * Transaksi 5 Januari tetap menemukan 2.500 karena baris kedua belum mulai.
 * Tidak ada operasi tulis ke baris lama yang bisa gagal separuh jalan, dan
 * promo berjangka otomatis mengembalikan harga normal begitu masanya habis —
 * hal yang justru mustahil bila baris lama ditutup permanen.
 *
 * 9999-12-31 dipakai sebagai ganti NULL supaya perbandingan tanggal cukup satu
 * BETWEEN dan tetap dapat memakai index.
 *
 * Kolom active dibuang: dengan rentang tanggal, "nonaktif" dan "sudah lewat"
 * adalah dua cara mengatakan hal yang sama, dan dua-duanya pasti berselisih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_list_items', function (Blueprint $table) {
            // Harga yang sudah ada memang berlaku sejak dulu; tanggal jauh di
            // masa lalu menjaga agar tidak ada produk yang mendadak kehilangan
            // harga hanya karena kolom ini baru ditambahkan.
            $table->date('start_date')->default('1900-01-01')->after('min_qty');
            $table->date('end_date')->default('9999-12-31')->after('start_date');
        });

        // Baris nonaktif menjadi baris yang masa berlakunya sudah habis.
        DB::table('price_list_items')->where('active', false)->update(['end_date' => '1900-01-01']);

        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropColumn('active');

            // Kunci baru dibuat LEBIH DULU, baru yang lama dibuang. Keduanya
            // diawali price_list_id, dan MySQL menolak membuang index terakhir
            // yang menopang foreign key fk_pli_price_list — dibalik urutannya,
            // migrasi ini gagal di tengah jalan.
            $table->unique(['price_list_id', 'product_id', 'uom_id', 'min_qty', 'start_date'], 'uk_pli_versi');
            $table->index(['product_id', 'uom_id', 'start_date'], 'idx_pli_riwayat');
        });

        // Kunci lama melarang dua baris dengan produk+satuan+min_qty yang sama
        // dalam satu daftar — persis yang dibutuhkan riwayat harga.
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropUnique('uk_price_list_items');
        });
    }

    public function down(): void
    {
        // Hanya baris terakhir tiap tingkat yang boleh bertahan: kunci lama
        // tidak mengenal riwayat, jadi versi lamanya harus dibuang lebih dulu.
        $bertahan = DB::table('price_list_items')
            ->selectRaw('MAX(price_list_item_id) AS id')
            ->groupBy('price_list_id', 'product_id', 'uom_id', 'min_qty')
            ->pluck('id');

        DB::table('price_list_items')->whereNotIn('price_list_item_id', $bertahan)->delete();

        Schema::table('price_list_items', function (Blueprint $table) {
            $table->boolean('active')->default(true);
            $table->unique(['price_list_id', 'product_id', 'uom_id', 'min_qty'], 'uk_price_list_items');
            $table->dropIndex('idx_pli_riwayat');
        });

        // Sama seperti up(): kunci penggantinya sudah ada, baru yang ini dibuang.
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropUnique('uk_pli_versi');
        });

        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date']);
        });
    }
};

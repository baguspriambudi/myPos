<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis produk: barang dagang, jasa, aset, produksi.
 *
 * Dua hal yang dibedakan oleh kolom ini, dan keduanya diturunkan dari jenisnya
 * — tidak ada kolom boolean terpisah supaya tidak bisa berselisih:
 *
 * - Boleh dijual? TRADE dan SERVICE saja. Aset (inventaris usaha) dan bahan
 *   produksi tidak pernah masuk daftar harga jual.
 * - Punya pergerakan stok? Semua kecuali SERVICE. Jasa tidak berwujud, jadi
 *   tidak ada yang bisa berkurang saat dijual.
 *
 * PRODUCTION sengaja disediakan sekarang walaupun modul produksinya belum ada:
 * barang yang dipakai untuk produksi harus sudah bisa dibedakan sejak awal,
 * kalau tidak seluruh katalog harus ditelusuri ulang saat modulnya dibuat.
 *
 * ENUM, bukan CHECK: MySQL 5.7 menerima sintaks CHECK tetapi mengabaikannya
 * diam-diam, sehingga nilai di luar daftar tetap masuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('product_type', ['TRADE', 'SERVICE', 'ASSET', 'PRODUCTION'])
                ->default('TRADE')
                ->after('category_id')
                ->comment('TRADE/SERVICE boleh dijual; semua kecuali SERVICE punya stok');
        });
    }

    /** Seluruh produk lama adalah barang dagang, jadi tidak ada yang perlu dipulihkan. */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('product_type');
        });
    }
};

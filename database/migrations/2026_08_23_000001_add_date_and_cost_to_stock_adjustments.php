<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian stok butuh dua hal yang belum ada di skema awal.
 *
 * 1. `adjustment_date` — sebelumnya tanggalnya hanya tersirat dari created_at.
 *    Padahal period fiskal ditentukan TANGGAL transaksi (PRD 8), dan barang
 *    yang baru ketahuan hilang hari ini bisa saja hilangnya minggu lalu. Tanpa
 *    kolom ini, penyesuaian tidak pernah bisa dicatat mundur ke period yang
 *    benar, dan angka period berjalan menanggung selisih milik period lain.
 *
 * 2. `unit_cost` — nilai perolehan per satuan dasar untuk penyesuaian MASUK.
 *    Penyesuaian keluar tidak memerlukannya karena harganya diambil dari lot
 *    yang benar-benar terpakai, tetapi barang yang masuk harus menyebut
 *    harganya. Bila tidak, satu-satunya nilai yang tersedia adalah rata-rata
 *    berjalan — dan itu bernilai NOL untuk produk yang belum pernah ada
 *    stoknya, sehingga seluruh penjualannya kelak ber-HPP nol tanpa ada yang
 *    tampak salah (Addendum 55.2).
 *
 * Ini penyimpangan dari `database-schema.sql` yang disengaja; file skema ikut
 * disesuaikan, sesuai aturan pembuka Addendum bahwa dokumen dan skema harus
 * saling menyusul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->date('adjustment_date')->after('branch_id');
            $table->decimal('unit_cost', 18, 4)->default(0)->after('qty_base')
                ->comment('perolehan per satuan dasar; hanya dipakai penyesuaian MASUK');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropColumn(['adjustment_date', 'unit_cost']);
        });
    }
};

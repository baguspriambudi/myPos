<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pelanggan tunai bawaan dan penanda member.
 *
 * Kasir tidak boleh dipaksa memilih pelanggan untuk setiap penjualan eceran.
 * Karena itu tiap usaha punya SATU pelanggan tunai bawaan: seluruh penjualan
 * yang tidak menyebut pelanggan masuk ke sana, sehingga laporan per pelanggan
 * tetap rapi tanpa baris kosong.
 *
 * Yang benar-benar didaftarkan hanyalah MEMBER, dan hanya member yang boleh
 * diberi daftar harga khusus. Karena itu price_lists.customer_only berganti
 * nama menjadi member_only — namanya sekarang mengatakan apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_member')->default(false)->after('phone')
                ->comment('TRUE = member terdaftar; hanya member yang boleh diberi daftar harga khusus');
            $table->boolean('is_default')->default(false)->after('is_member')
                ->comment('pelanggan tunai bawaan; penjualan tanpa pelanggan masuk ke sini');
        });

        // MySQL 5.7 belum mengenal RENAME COLUMN, jadi memakai CHANGE.
        DB::statement(
            'ALTER TABLE price_lists CHANGE customer_only member_only TINYINT(1) NOT NULL DEFAULT 0 '.
            "COMMENT 'hanya berlaku bagi member yang ditugaskan; dilewati resolusi umum'"
        );

        $this->tandaiPelangganLama();
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE price_lists CHANGE member_only customer_only TINYINT(1) NOT NULL DEFAULT 0 '.
            "COMMENT 'hanya berlaku bagi pelanggan yang ditugaskan; dilewati resolusi umum'"
        );

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['is_member', 'is_default']);
        });
    }

    /**
     * Pelanggan tunai bawaan diambil dari baris "Umum / Tunai" yang sudah ada
     * supaya tidak muncul dua pelanggan yang artinya sama. Sisanya ditandai
     * member: mereka sudah terlanjur didaftarkan seseorang, dan pembeli tanpa
     * nama semuanya diwakili pelanggan bawaan itu.
     */
    private function tandaiPelangganLama(): void
    {
        foreach (DB::table('owners')->pluck('owner_id') as $ownerId) {
            $milikOwner = fn () => DB::table('customers')->where('owner_id', $ownerId);

            $this->pastikanAdaBawaan($ownerId);

            $milikOwner()->where('is_default', false)->update(['is_member' => true]);
        }
    }

    private function pastikanAdaBawaan(int $ownerId): void
    {
        $milikOwner = fn () => DB::table('customers')->where('owner_id', $ownerId);

        if ($milikOwner()->where('is_default', true)->exists()) {
            return;
        }

        $bawaan = $milikOwner()
            ->whereNull('price_list_id')
            ->where(fn ($q) => $q->where('customer_name', 'like', '%umum%')->orWhere('customer_name', 'like', '%tunai%'))
            ->orderBy('customer_id')
            ->value('customer_id');

        if ($bawaan !== null) {
            DB::table('customers')->where('customer_id', $bawaan)->update([
                'is_default' => true,
                'is_member' => false,
                'price_list_id' => null,
                'credit_limit' => 0,
                'branch_id' => null,
                'active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('customers')->insert([
            'owner_id' => $ownerId,
            'branch_id' => null,
            'customer_name' => 'Umum / Tunai',
            'phone' => null,
            'is_member' => false,
            'is_default' => true,
            'credit_limit' => 0,
            'price_list_id' => null,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

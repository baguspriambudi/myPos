<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harga jual sepenuhnya pindah ke daftar harga (Addendum 39.10-39.12 revisi).
 *
 * Sebelumnya harga hidup di dua tempat: "harga standar" di master produk dan
 * daftar harga sebagai override. Dua tempat untuk satu angka membingungkan, dan
 * harga standar yang lupa diisi diam-diam menjual barang seharga Rp0.
 *
 * Sekarang satu-satunya sumber harga adalah daftar harga. Produk yang tidak
 * punya baris harga tidak dapat dijual — itu memang jawaban yang benar.
 *
 * Harga standar yang sudah terlanjur diisi tidak dibuang: dipindah menjadi baris
 * min_qty 1 pada daftar harga bawaan milik owner masing-masing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->pindahkanHargaStandar();

        Schema::table('product_uoms', function (Blueprint $table) {
            $table->dropColumn('selling_price');
        });
    }

    /**
     * Kolomnya dikembalikan kosong (0). Angkanya sudah menjadi baris daftar
     * harga dan tidak dapat dipetakan balik satu-satu.
     */
    public function down(): void
    {
        Schema::table('product_uoms', function (Blueprint $table) {
            $table->decimal('selling_price', 18, 2)->default(0)->after('is_default');
        });
    }

    private function pindahkanHargaStandar(): void
    {
        if (! Schema::hasColumn('product_uoms', 'selling_price')) {
            return;
        }

        foreach (DB::table('owners')->pluck('owner_id') as $ownerId) {
            $baris = DB::table('product_uoms')
                ->join('products', 'products.product_id', '=', 'product_uoms.product_id')
                ->where('products.owner_id', $ownerId)
                ->where('product_uoms.selling_price', '>', 0)
                ->select('product_uoms.uom_id', 'product_uoms.product_id', 'product_uoms.selling_price')
                ->get();

            if ($baris->isEmpty()) {
                continue;
            }

            $priceListId = $this->daftarBawaan($ownerId);

            foreach ($baris as $row) {
                $sudahAda = DB::table('price_list_items')
                    ->where('price_list_id', $priceListId)
                    ->where('product_id', $row->product_id)
                    ->where('uom_id', $row->uom_id)
                    ->where('min_qty', 1)
                    ->exists();

                if ($sudahAda) {
                    continue;
                }

                DB::table('price_list_items')->insert([
                    'price_list_id' => $priceListId,
                    'product_id' => $row->product_id,
                    'uom_id' => $row->uom_id,
                    'min_qty' => 1,
                    'unit_price' => $row->selling_price,
                    'active' => true,
                ]);
            }
        }
    }

    /** Daftar harga tujuan: yang bertanda bawaan, atau daftar seluruh lokasi, atau dibuat baru. */
    private function daftarBawaan(int $ownerId): int
    {
        $milikOwner = fn () => DB::table('price_lists')->where('owner_id', $ownerId);

        $id = $milikOwner()->where('is_default', true)->value('price_list_id')
            ?? $milikOwner()->whereNull('branch_id')->orderByDesc('priority')->value('price_list_id');

        if ($id !== null) {
            return (int) $id;
        }

        $kode = $milikOwner()->where('price_list_code', 'PL-UMUM')->exists() ? 'PL-STANDAR' : 'PL-UMUM';

        return (int) DB::table('price_lists')->insertGetId([
            'owner_id' => $ownerId,
            'branch_id' => null,
            'price_list_code' => $kode,
            'price_list_name' => 'Harga Umum',
            'is_default' => true,
            'customer_only' => false,
            'priority' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'price_list_id');
    }
};

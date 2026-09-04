<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master satuan (Addendum 39.2).
 *
 * Sebelumnya nama satuan diketik bebas di layar produk, sehingga "DUS", "DOS",
 * dan "Dus" bisa hidup berdampingan pada usaha yang sama dan laporan per satuan
 * jadi pecah. Sekarang nama satuan tinggal di master milik owner; product_uoms
 * hanya menunjuk ke sana.
 *
 * product_uoms.uom_name sengaja tetap ada: dipakai unique key per produk dan
 * dibaca banyak layar. Nilainya selalu disalin dari master, dan nama master
 * dikunci begitu dipakai produk, jadi keduanya tidak bisa berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->bigIncrements('unit_id');
            $table->unsignedBigInteger('owner_id');
            $table->string('unit_name', 50);
            $table->string('description', 150)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['owner_id', 'unit_name'], 'uk_units_name');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
        });

        Schema::table('product_uoms', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable()->after('product_id');
        });

        $this->isiDariDataLama();

        Schema::table('product_uoms', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable(false)->change();
            $table->foreign('unit_id')->references('unit_id')->on('units');
        });
    }

    public function down(): void
    {
        Schema::table('product_uoms', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });

        Schema::dropIfExists('units');
    }

    /**
     * Satuan yang sudah terlanjur diketik di produk diangkat menjadi master,
     * satu baris per (owner, nama). Ditulis dengan query builder — bukan
     * "INSERT ... SELECT" — supaya nama ikut dinormalkan ke huruf besar.
     */
    private function isiDariDataLama(): void
    {
        $baris = DB::table('product_uoms')
            ->join('products', 'products.product_id', '=', 'product_uoms.product_id')
            ->select('product_uoms.uom_id', 'product_uoms.uom_name', 'products.owner_id')
            ->orderBy('product_uoms.uom_id')
            ->get();

        $sekarang = now();
        $unitIds = [];

        foreach ($baris as $row) {
            $nama = strtoupper(trim($row->uom_name));
            $kunci = $row->owner_id.'|'.$nama;

            $unitIds[$kunci] ??= DB::table('units')->insertGetId([
                'owner_id' => $row->owner_id,
                'unit_name' => $nama,
                'active' => true,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ], 'unit_id');

            DB::table('product_uoms')
                ->where('uom_id', $row->uom_id)
                ->update(['unit_id' => $unitIds[$kunci], 'uom_name' => $nama]);
        }
    }
};

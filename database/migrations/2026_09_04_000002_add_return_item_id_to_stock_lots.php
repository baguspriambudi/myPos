<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot yang lahir dari retur penjualan perlu ditelusuri balik ke baris retur
 * yang membentuknya — persis seperti `receipt_item_id` menautkan lot ke
 * penerimaan asalnya — supaya void retur penjualan tahu lot mana yang harus
 * dikosongkan kembali (Addendum 48.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('return_item_id')->nullable()->after('receipt_item_id')
                ->comment('lot lahir dari retur penjualan (Addendum 48.2)');
            $table->foreign('return_item_id')->references('return_item_id')->on('sales_return_items');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropForeign(['return_item_id']);
            $table->dropColumn('return_item_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot yang lahir di branch PENERIMA transfer perlu ditelusuri balik ke baris
 * transfer yang membentuknya — pola yang sama dengan `receipt_item_id` dan
 * `return_item_id` — supaya void transfer tahu lot persis mana yang harus
 * dikosongkan kembali (Addendum 49.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_item_id')->nullable()->after('return_item_id')
                ->comment('lot lahir dari transfer antar-lokasi (Addendum 49.3)');
            $table->foreign('transfer_item_id')->references('transfer_item_id')->on('stock_transfer_items');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropForeign(['transfer_item_id']);
            $table->dropColumn('transfer_item_id');
        });
    }
};

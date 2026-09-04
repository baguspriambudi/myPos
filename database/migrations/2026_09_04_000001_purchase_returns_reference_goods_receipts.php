<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `purchase_returns`/`purchase_return_items` masih menunjuk `purchases` sejak
 * migration awal — peninggalan sebelum penerimaan barang dipisah dari faktur
 * (Addendum 58, disinkronkan 20 Agustus 2026 untuk goods_receipts/stock_lots,
 * tapi terlewat di sini). Addendum 58.6 tegas: retur pembelian mengacu pada
 * PENERIMAAN, bukan faktur, karena lot persediaan lahir dari penerimaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->dropForeign(['purchase_item_id']);
            $table->dropColumn('purchase_item_id');
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->unsignedBigInteger('receipt_item_id')->nullable()->after('return_id');
            $table->foreign('receipt_item_id')->references('receipt_item_id')->on('goods_receipt_items');
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
            $table->dropColumn('purchase_id');
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('goods_receipt_id')->nullable()->after('branch_id');
            $table->foreign('goods_receipt_id')->references('goods_receipt_id')->on('goods_receipts');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropForeign(['goods_receipt_id']);
            $table->dropColumn('goods_receipt_id');
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_id')->nullable()->after('branch_id');
            $table->foreign('purchase_id')->references('purchase_id')->on('purchases');
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->dropForeign(['receipt_item_id']);
            $table->dropColumn('receipt_item_id');
        });

        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_item_id')->nullable()->after('return_id');
            $table->foreign('purchase_item_id')->references('purchase_item_id')->on('purchase_items');
        });
    }
};

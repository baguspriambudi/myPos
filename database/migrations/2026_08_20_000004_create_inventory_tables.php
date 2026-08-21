<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok — database-schema.sql bagian 4.
 *
 * Lot lahir dari PENERIMAAN BARANG, bukan faktur (Addendum 58.2). Karena itu
 * stock_lots menunjuk goods_receipt_items, bukan purchase_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->bigIncrements('lot_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('fiscal_period_id')->nullable()->comment('period saat lot terbentuk');
            $table->unsignedBigInteger('receipt_item_id')->nullable()->comment('lot lahir dari penerimaan (Addendum 58.2)');
            $table->string('lot_number', 50);
            $table->date('received_date');
            $table->decimal('qty_remaining', 18, 4)->default(0)->comment('base unit, sisa belum terjual');
            $table->decimal('unit_cost', 18, 4)->default(0)->comment('cost per base unit');
            $table->enum('status', ['ACTIVE', 'EXHAUSTED'])->default('ACTIVE');
            $table->dateTime('created_at')->useCurrent();

            $table->index(['branch_id', 'product_id', 'received_date', 'lot_id'], 'idx_lots_fifo');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('receipt_item_id')->references('receipt_item_id')->on('goods_receipt_items');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->bigIncrements('movement_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->date('movement_date');
            $table->enum('movement_type', [
                'OPENING',
                'PURCHASE',
                'SALES',
                'ADJUSTMENT_IN',
                'ADJUSTMENT_OUT',
                'TRANSFER_IN',
                'TRANSFER_OUT',
                'RETURN_IN',
                'RETURN_OUT',
                'VOID_REVERSAL',
                'ROUNDING',
                'CORRECTION',
            ]);
            // RECEIPT untuk penambahan stok dari penerimaan barang (Addendum 58.2).
            // PURCHASE hanya tersisa untuk data lama sebelum penerimaan dipisah.
            $table->string('reference_type', 30)->nullable()
                ->comment('SALES/RECEIPT/PURCHASE/ADJUSTMENT/OPNAME/TRANSFER/RETURN/CLOSING');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('uom_id');
            $table->decimal('qty', 18, 4)->comment('signed +/-');
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->unsignedBigInteger('sequence')->comment('urutan histori untuk recalculate (PRD 29)');
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['fiscal_period_id', 'branch_id', 'product_id', 'sequence'], 'uk_movements_sequence');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        // Tanpa tabel ini, void dan retur tidak dapat mengembalikan qty ke lot asal.
        Schema::create('stock_lot_consumptions', function (Blueprint $table) {
            $table->bigIncrements('consumption_id');
            $table->unsignedBigInteger('movement_id');
            $table->unsignedBigInteger('lot_id');
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0)->comment('cost lot saat dikonsumsi');
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->unsignedBigInteger('reversed_by_movement_id')->nullable()->comment('diisi saat void');
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['movement_id', 'lot_id'], 'uk_lot_consumption');
            $table->index('lot_id', 'idx_lot_consumption_lot');
            $table->foreign('movement_id')->references('movement_id')->on('stock_movements');
            $table->foreign('lot_id')->references('lot_id')->on('stock_lots');
            $table->foreign('reversed_by_movement_id')->references('movement_id')->on('stock_movements');
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->bigIncrements('stock_balance_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty_on_hand', 18, 4)->default(0);
            $table->decimal('avg_cost', 18, 4)->default(0);
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['branch_id', 'product_id'], 'uk_stock_balances');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('product_id')->references('product_id')->on('products');
        });

        Schema::create('hpp_recalc_logs', function (Blueprint $table) {
            $table->bigIncrements('recalc_log_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('triggered_by');
            $table->string('trigger_type', 30)->comment('CORRECTION|VOID|BACKDATED|REOPEN|CLOSING|INVOICE_PRICE_DIFF');
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->integer('products_affected')->default(0);
            $table->decimal('old_cogs_value', 18, 2)->default(0);
            $table->decimal('new_cogs_value', 18, 2)->default(0);
            $table->enum('status', ['SUCCESS', 'FAILED'])->default('SUCCESS');
            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->useCurrent();
            $table->dateTime('finished_at')->nullable();

            $table->index(['fiscal_period_id', 'branch_id'], 'idx_recalc_period');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('triggered_by')->references('user_id')->on('users');
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->bigIncrements('adjustment_id');
            $table->string('adjustment_number', 40);
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('uom_id');
            $table->enum('adjustment_type', ['IN', 'OUT']);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->text('reason');
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['branch_id', 'adjustment_number'], 'uk_adjustments_number');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->bigIncrements('opname_id');
            $table->string('opname_number', 40);
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('opname_date');
            $table->enum('status', ['DRAFT', 'APPLIED', 'CANCELLED'])->default('DRAFT');
            $table->dateTime('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['branch_id', 'opname_number'], 'uk_opnames_number');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('applied_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->bigIncrements('opname_item_id');
            $table->unsignedBigInteger('opname_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('system_qty', 18, 4);
            $table->decimal('system_qty_base', 18, 4);
            $table->decimal('actual_qty', 18, 4);
            $table->decimal('actual_qty_base', 18, 4);
            $table->decimal('difference_qty_base', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('difference_value', 18, 2)->default(0);

            $table->foreign('opname_id')->references('opname_id')->on('stock_opnames')->cascadeOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('hpp_recalc_logs');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_lot_consumptions');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_lots');
    }
};

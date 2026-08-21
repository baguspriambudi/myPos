<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transaksi — database-schema.sql bagian 3.
 *
 * Urutan blok mengikuti alur dokumen, bukan urutan FK. Yang penting:
 * PENERIMAAN BARANG (3.3) menambah stok dan membentuk lot; FAKTUR PEMASOK (3.4)
 * mengakui utang dan TIDAK menyentuh stok (Addendum 58).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- 3.1
        Schema::create('sales', function (Blueprint $table) {
            $table->bigIncrements('sale_id');
            $table->string('sale_number', 40);
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('discount_id')->nullable()->comment('diskon level transaksi');
            $table->string('device_id', 50)->nullable();
            $table->char('local_uuid', 36)->nullable()->comment('kunci idempotensi retry & sync offline');
            $table->date('transaction_date');
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->decimal('gross_total', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('net_total', 18, 2)->default(0)->comment('nilai penjualan resmi, tidak terpengaruh pembulatan');
            $table->decimal('rounding_amount', 18, 2)->default(0)->comment('selisih pembulatan pembayaran tunai (Addendum 51.4)');
            $table->decimal('paid_total', 18, 2)->default(0)->comment('net_total + rounding_amount');
            $table->char('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'sale_number'], 'uk_sales_number');
            $table->unique(['branch_id', 'local_uuid'], 'uk_sales_local_uuid');
            $table->index(['fiscal_period_id', 'transaction_date'], 'idx_sales_period_date');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('shift_id')->references('shift_id')->on('cashier_shifts');
            $table->foreign('customer_id')->references('customer_id')->on('customers');
            $table->foreign('discount_id')->references('discount_id')->on('discounts');
            $table->foreign('currency_code')->references('currency_code')->on('currencies');
            $table->foreign('voided_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
            $table->foreign('updated_by')->references('user_id')->on('users');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->bigIncrements('sale_item_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_price', 18, 2)->default(0)->comment('per UOM');
            $table->decimal('unit_price_base', 18, 2)->default(0);
            // Referensi telusur saja. Harga yang berlaku tetap angka di unit_price,
            // supaya perubahan master daftar harga tidak mengubah histori.
            $table->unsignedBigInteger('price_list_id')->nullable()->comment('asal harga (Addendum 39.12)');
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('cost_at_sale', 18, 4)->default(0)->comment('HPP unit base, snapshot saat transaksi');
            $table->decimal('cogs_total', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);

            $table->index('sale_id', 'idx_sale_items_sale');
            $table->foreign('sale_id')->references('sale_id')->on('sales')->cascadeOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
            $table->foreign('price_list_id')->references('price_list_id')->on('price_lists');
            $table->foreign('discount_id')->references('discount_id')->on('discounts');
        });

        Schema::create('sale_taxes', function (Blueprint $table) {
            $table->bigIncrements('sale_tax_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('tax_id');
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);

            $table->index('sale_id', 'idx_sale_taxes_sale');
            $table->foreign('sale_id')->references('sale_id')->on('sales')->cascadeOnDelete();
            $table->foreign('tax_id')->references('tax_id')->on('taxes');
        });

        // ---------------------------------------------------------------- 3.2
        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('payment_id');
            $table->string('payment_number', 40);
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->date('transaction_date');
            // Penjualan kredit (piutang) TIDAK membuat payment; dicatat di receivables.
            $table->enum('payment_type', ['CASH', 'QRIS', 'DEBIT', 'CREDIT_CARD', 'TRANSFER', 'E_WALLET']);
            $table->decimal('amount', 18, 2);
            $table->char('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['branch_id', 'payment_number'], 'uk_payments_number');
            $table->index(['fiscal_period_id', 'transaction_date'], 'idx_payments_period_date');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('shift_id')->references('shift_id')->on('cashier_shifts');
            $table->foreign('currency_code')->references('currency_code')->on('currencies');
            $table->foreign('voided_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->bigIncrements('sale_payment_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('payment_id');
            $table->decimal('amount', 18, 2);

            $table->unique(['sale_id', 'payment_id'], 'uk_sale_payments');
            $table->foreign('sale_id')->references('sale_id')->on('sales');
            $table->foreign('payment_id')->references('payment_id')->on('payments');
        });

        // ---------------------------------------------------------------- 3.3
        // PENERIMAAN BARANG — dokumen yang MENAMBAH STOK (Addendum 58.2).
        // Boleh dicatat di gudang, dan boleh diisi dengan qty fisik saja.
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->bigIncrements('goods_receipt_id');
            $table->string('receipt_number', 40)->comment('prefix GRN');
            $table->unsignedBigInteger('fiscal_period_id')->comment('ditentukan dari receipt_date');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            // NULL = belum ditagih; nilainya duduk di akun Utang Belum Ditagih
            // (GRNI) dan memblokir penutupan periode (Addendum 58.5).
            $table->unsignedBigInteger('purchase_id')->nullable()->comment('faktur yang mencakup penerimaan ini');
            $table->date('receipt_date');
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->string('supplier_do_number', 50)->nullable()->comment('nomor surat jalan pemasok');
            $table->text('note')->nullable();
            $table->decimal('total_cost', 18, 2)->default(0)->comment('nilai sementara, dasar nilai GRNI');
            $table->dateTime('invoiced_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['branch_id', 'receipt_number'], 'uk_receipts_number');
            $table->index(['fiscal_period_id', 'receipt_date'], 'idx_receipts_period_date');
            // Pemeriksaan pra-closing: penerimaan POSTED yang purchase_id masih NULL
            $table->index(['branch_id', 'status', 'purchase_id'], 'idx_receipts_uninvoiced');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('supplier_id')->references('supplier_id')->on('suppliers');
            $table->foreign('voided_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->bigIncrements('receipt_item_id');
            $table->unsignedBigInteger('goods_receipt_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('qty', 18, 4)->comment('qty fisik dalam uom yang dipilih');
            $table->decimal('qty_base', 18, 4);
            // Cost sementara, diisi sistem bukan oleh kasir (Addendum 58.2).
            // Selisihnya terhadap faktur memicu recalculate HPP (Addendum 42.2).
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('unit_cost_base', 18, 4)->default(0);
            $table->enum('cost_source', ['LAST_PURCHASE', 'AVG_COST', 'NONE', 'INVOICE'])->default('NONE');
            $table->decimal('line_total', 18, 2)->default(0);
            $table->unsignedBigInteger('purchase_item_id')->nullable()->comment('diisi saat faktur POSTED');

            $table->index('goods_receipt_id', 'idx_receipt_items_receipt');
            $table->foreign('goods_receipt_id')->references('goods_receipt_id')->on('goods_receipts')->cascadeOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
        });

        // ---------------------------------------------------------------- 3.4
        // FAKTUR PEMASOK — mengakui utang & PPN Masukan, TIDAK menambah stok.
        Schema::create('purchases', function (Blueprint $table) {
            $table->bigIncrements('purchase_id');
            $table->string('purchase_number', 40);
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->date('transaction_date');
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->char('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['branch_id', 'purchase_number'], 'uk_purchases_number');
            $table->index(['fiscal_period_id', 'transaction_date'], 'idx_purchases_period_date');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('supplier_id')->references('supplier_id')->on('suppliers');
            $table->foreign('currency_code')->references('currency_code')->on('currencies');
            $table->foreign('voided_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->bigIncrements('purchase_item_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_cost', 18, 2)->default(0)->comment('per UOM, contoh harga per dus');
            $table->decimal('unit_cost_base', 18, 4)->default(0)->comment('cost per base unit');
            $table->decimal('line_total', 18, 2)->default(0);

            $table->index('purchase_id', 'idx_purchase_items_purchase');
            $table->foreign('purchase_id')->references('purchase_id')->on('purchases')->cascadeOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
        });

        // Pencocokan penerimaan ↔ faktur, dipasang setelah purchases ada.
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreign('purchase_id')->references('purchase_id')->on('purchases');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->foreign('purchase_item_id')->references('purchase_item_id')->on('purchase_items');
        });

        Schema::create('purchase_taxes', function (Blueprint $table) {
            $table->bigIncrements('purchase_tax_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('tax_id');
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);

            $table->index('purchase_id', 'idx_purchase_taxes_purchase');
            $table->foreign('purchase_id')->references('purchase_id')->on('purchases')->cascadeOnDelete();
            $table->foreign('tax_id')->references('tax_id')->on('taxes');
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->bigIncrements('purchase_payment_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('payment_id');
            $table->decimal('amount', 18, 2);

            $table->unique(['purchase_id', 'payment_id'], 'uk_purchase_payments');
            $table->foreign('purchase_id')->references('purchase_id')->on('purchases');
            $table->foreign('payment_id')->references('payment_id')->on('payments');
        });

        // ---------------------------------------------------------------- 3.5
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->bigIncrements('return_id');
            $table->string('return_number', 40);
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->date('transaction_date');
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->decimal('refund_total', 18, 2)->default(0);
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['branch_id', 'return_number'], 'uk_sales_returns_number');
            $table->index(['fiscal_period_id', 'transaction_date'], 'idx_returns_period_date');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('sale_id')->references('sale_id')->on('sales');
            $table->foreign('voided_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->bigIncrements('return_item_id');
            $table->unsignedBigInteger('return_id');
            $table->unsignedBigInteger('sale_item_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('cost_at_return', 18, 4)->default(0)->comment('cost dari transaksi asal (snapshot)');
            $table->decimal('refund_amount', 18, 2)->default(0);

            $table->foreign('return_id')->references('return_id')->on('sales_returns')->cascadeOnDelete();
            $table->foreign('sale_item_id')->references('sale_item_id')->on('sale_items');
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
        });

        // ---------------------------------------------------------------- 3.6
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->bigIncrements('return_id');
            $table->string('return_number', 40);
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->date('transaction_date');
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->decimal('refund_total', 18, 2)->default(0);
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['branch_id', 'return_number'], 'uk_purchase_returns_number');
            $table->index(['fiscal_period_id', 'transaction_date'], 'idx_preturns_period_date');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('purchase_id')->references('purchase_id')->on('purchases');
            $table->foreign('voided_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->bigIncrements('return_item_id');
            $table->unsignedBigInteger('return_id');
            $table->unsignedBigInteger('purchase_item_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('cost_at_return', 18, 4)->default(0);
            $table->decimal('refund_amount', 18, 2)->default(0);

            $table->foreign('return_id')->references('return_id')->on('purchase_returns')->cascadeOnDelete();
            $table->foreign('purchase_item_id')->references('purchase_item_id')->on('purchase_items');
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
        });

        // ---------------------------------------------------------------- 3.7
        // Berlaku untuk semua kombinasi tipe lokasi: gudang → toko, toko →
        // gudang, maupun antar-gudang (Addendum 59.2).
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->bigIncrements('transfer_id');
            $table->string('transfer_number', 40);
            $table->unsignedBigInteger('from_branch_id');
            $table->unsignedBigInteger('to_branch_id');
            $table->date('transaction_date');
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['from_branch_id', 'transfer_number'], 'uk_transfers_number');
            $table->foreign('from_branch_id')->references('branch_id')->on('branches');
            $table->foreign('to_branch_id')->references('branch_id')->on('branches');
            $table->foreign('voided_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->bigIncrements('transfer_item_id');
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0)->comment('cost dari branch pengirim');

            $table->foreign('transfer_id')->references('transfer_id')->on('stock_transfers')->cascadeOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
        });

        // ---------------------------------------------------------------- 3.8
        Schema::create('receivables', function (Blueprint $table) {
            $table->bigIncrements('receivable_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->enum('status', ['OPEN', 'PARTIAL', 'PAID', 'VOID'])->default('OPEN');
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->index(['customer_id', 'status'], 'idx_receivables_customer');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('customer_id')->references('customer_id')->on('customers');
            $table->foreign('sale_id')->references('sale_id')->on('sales');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('payables', function (Blueprint $table) {
            $table->bigIncrements('payable_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->enum('status', ['OPEN', 'PARTIAL', 'PAID', 'VOID'])->default('OPEN');
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->index(['supplier_id', 'status'], 'idx_payables_supplier');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('supplier_id')->references('supplier_id')->on('suppliers');
            $table->foreign('purchase_id')->references('purchase_id')->on('purchases');
            $table->foreign('created_by')->references('user_id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payables');
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_taxes');

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropForeign(['purchase_item_id']);
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
        });

        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_taxes');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};

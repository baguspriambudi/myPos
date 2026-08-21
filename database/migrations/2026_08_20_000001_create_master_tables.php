<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data — acuan: database-schema.sql bagian 1 (MASTER).
 *
 * Catatan penting soal ENUM. Acuan schema memakai VARCHAR + CHECK constraint,
 * tetapi server pengembangan memakai MySQL 5.7 yang MENGABAIKAN CHECK secara
 * diam-diam (terbukti: branch_type 'NGAWUR' lolos tanpa error). Selama masih di
 * 5.7, ENUM adalah satu-satunya cara mendapat penegakan di level database.
 * Bila kelak pindah ke MySQL 8.0.16+, ENUM boleh diganti VARCHAR + CHECK
 * mengikuti database-schema.sql tanpa mengubah kode aplikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table) {
            $table->bigIncrements('owner_id');
            // Dipakai saat login bila satu username ada di lebih dari satu owner.
            $table->string('owner_code', 20)->unique();
            $table->string('owner_name', 150);
            $table->char('default_currency', 3)->default('IDR');
            $table->enum('costing_method', ['AVERAGE', 'FIFO', 'SPECIFIC'])->default('AVERAGE');
            $table->boolean('allow_negative_stock')->default(false);
            $table->integer('payment_rounding_unit')->default(0)->comment('0 = nonaktif; 100/500/1000');
            $table->enum('payment_rounding_mode', ['NEAREST', 'DOWN', 'UP'])->default('NEAREST');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Satu tabel untuk toko, depo, dan gudang (Addendum 59).
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('branch_id');
            $table->unsignedBigInteger('owner_id');
            $table->string('branch_code', 10)->comment('dipakai pada nomor transaksi (Addendum 52)');
            $table->string('branch_name', 150);
            $table->enum('branch_type', ['STORE', 'DEPO', 'WAREHOUSE'])->default('STORE');
            // Sengaja terpisah dari branch_type: gudang yang sesekali melayani
            // grosir cukup diubah flag-nya tanpa berganti tipe (Addendum 59.1).
            $table->boolean('can_sell')->default(true);
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['owner_id', 'branch_code'], 'uk_branches_code');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('user_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = akses semua branch');
            $table->string('username', 100);
            // Nama kolom mengikuti database-schema.sql. Model User meng-override
            // getAuthPassword() supaya Laravel tetap mengenalinya.
            $table->string('password_hash', 255);
            $table->string('full_name', 150);
            $table->enum('role', ['OWNER', 'SUPER_ADMIN', 'MANAGER', 'KASIR', 'GUDANG']);
            $table->boolean('active')->default(true);
            $table->rememberToken()->comment('kebutuhan Laravel, di luar database-schema.sql');
            $table->timestamps();

            $table->unique(['owner_id', 'username'], 'uk_users_username');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
        });

        // Scope akses ke lokasi tertentu untuk MANAGER, KASIR, GUDANG (Addendum 50)
        Schema::create('user_branch_access', function (Blueprint $table) {
            $table->bigIncrements('access_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id');

            $table->unique(['user_id', 'branch_id'], 'uk_user_branch');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_id')->references('branch_id')->on('branches')->cascadeOnDelete();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->bigIncrements('category_id');
            $table->unsignedBigInteger('owner_id');
            $table->string('category_name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['owner_id', 'category_name'], 'uk_categories');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('product_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('product_name', 200);
            $table->string('sku', 50);
            $table->string('barcode', 100)->nullable();
            $table->unsignedBigInteger('base_uom_id')->nullable()->comment('FK ditambahkan setelah product_uoms dibuat');
            $table->boolean('is_weighted')->default(false)->comment('TRUE = qty desimal (kg/liter)');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['owner_id', 'sku'], 'uk_products_sku');
            $table->unique(['owner_id', 'barcode'], 'uk_products_barcode');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('category_id')->references('category_id')->on('product_categories');
        });

        // Konversi satuan (Addendum 39.2)
        Schema::create('product_uoms', function (Blueprint $table) {
            $table->bigIncrements('uom_id');
            $table->unsignedBigInteger('product_id');
            $table->string('uom_name', 50);
            $table->enum('uom_type', ['BASE', 'PACK']);
            $table->decimal('conversion_factor', 18, 6)->default(1);
            $table->boolean('is_default')->default(false);
            $table->decimal('selling_price', 18, 2)->default(0)->comment('harga standar, fallback daftar harga (Addendum 39.10)');
            $table->boolean('active')->default(true);

            $table->unique(['product_id', 'uom_name'], 'uk_uoms_name');
            $table->foreign('product_id')->references('product_id')->on('products')->cascadeOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('base_uom_id')->references('uom_id')->on('product_uoms');
        });

        // ------------------------------------------------------------------
        // DAFTAR HARGA JUAL (Addendum 39.10-39.12)
        // ------------------------------------------------------------------
        Schema::create('price_lists', function (Blueprint $table) {
            $table->bigIncrements('price_list_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = berlaku seluruh lokasi');
            $table->string('price_list_code', 20);
            $table->string('price_list_name', 150);
            $table->boolean('is_default')->default(false);
            $table->integer('priority')->default(0)->comment('makin besar makin diutamakan');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['owner_id', 'price_list_code'], 'uk_price_lists_code');
            $table->index(['owner_id', 'branch_id', 'active', 'priority'], 'idx_price_lists_lookup');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->bigIncrements('price_list_item_id');
            $table->unsignedBigInteger('price_list_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('uom_id')->comment('harga melekat pada satuan');
            $table->decimal('min_qty', 18, 4)->default(1)->comment('tingkat kuantitas; harga berlaku bila qty >= min_qty');
            $table->decimal('unit_price', 18, 2);
            $table->boolean('active')->default(true);

            $table->unique(['price_list_id', 'product_id', 'uom_id', 'min_qty'], 'uk_price_list_items');
            $table->foreign('price_list_id')->references('price_list_id')->on('price_lists')->cascadeOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('uom_id')->references('uom_id')->on('product_uoms');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('customer_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('customer_name', 150);
            $table->string('phone', 30)->nullable();
            $table->decimal('credit_limit', 18, 2)->default(0)->comment('0 = tunai saja / tanpa limit piutang');
            $table->unsignedBigInteger('price_list_id')->nullable()->comment('daftar harga khusus pelanggan (Addendum 39.11)');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('price_list_id')->references('price_list_id')->on('price_lists');
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->bigIncrements('supplier_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('supplier_name', 150);
            $table->string('phone', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
        });

        Schema::create('taxes', function (Blueprint $table) {
            $table->bigIncrements('tax_id');
            $table->unsignedBigInteger('owner_id');
            $table->string('tax_name', 100);
            $table->decimal('tax_rate', 5, 2)->comment('persen, contoh 11.00');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['owner_id', 'tax_name'], 'uk_taxes');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->bigIncrements('discount_id');
            $table->unsignedBigInteger('owner_id');
            $table->string('discount_name', 150);
            $table->enum('discount_scope', ['ITEM', 'TRANSACTION']);
            $table->enum('discount_type', ['PERCENT', 'AMOUNT']);
            $table->decimal('discount_value', 18, 2);
            $table->decimal('max_discount_amount', 18, 2)->nullable()->comment('batas atas untuk tipe PERCENT');
            $table->unsignedBigInteger('product_id')->nullable()->comment('NULL = semua product');
            $table->unsignedBigInteger('category_id')->nullable()->comment('NULL = semua kategori');
            $table->decimal('min_qty', 18, 4)->default(0);
            $table->decimal('min_amount', 18, 2)->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['owner_id', 'active', 'start_date', 'end_date'], 'idx_discounts_active');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('category_id')->references('category_id')->on('product_categories');
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->bigIncrements('currency_id');
            $table->char('currency_code', 3)->unique('uk_currencies_code');
            $table->string('currency_name', 50);
            $table->boolean('is_base')->default(false);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->bigIncrements('rate_id');
            $table->char('currency_code', 3);
            $table->date('rate_date');
            $table->decimal('rate_to_base', 18, 6);

            $table->unique(['currency_code', 'rate_date'], 'uk_rates');
            $table->foreign('currency_code')->references('currency_code')->on('currencies');
        });

        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->bigIncrements('account_id');
            $table->unsignedBigInteger('owner_id');
            $table->string('account_code', 20);
            $table->string('account_name', 150);
            $table->enum('account_type', ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE']);

            $table->unique(['owner_id', 'account_code'], 'uk_accounts');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->bigIncrements('expense_category_id');
            $table->unsignedBigInteger('owner_id');
            $table->string('category_name', 100)->comment('Listrik / Gaji / Sewa / Transport');
            // database-schema.sql membiarkan kolom ini tanpa FK karena di sana
            // chart_of_accounts baru dibuat di bagian 7. Di sini kedua tabel ada
            // dalam satu migration, jadi FK-nya sekalian dipasang.
            $table->unsignedBigInteger('account_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['owner_id', 'category_name'], 'uk_expense_category');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('account_id')->references('account_id')->on('chart_of_accounts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('chart_of_accounts');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('taxes');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['base_uom_id']);
        });

        Schema::dropIfExists('product_uoms');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('user_branch_access');
        Schema::dropIfExists('users');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('owners');
    }
};

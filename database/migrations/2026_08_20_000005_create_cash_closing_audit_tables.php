<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kas, biaya, closing, penomoran, GL, dan audit —
 * database-schema.sql bagian 5, 6, 7, dan 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------ 5. KAS
        // Hanya untuk lokasi dengan branches.can_sell = TRUE — gudang tidak
        // punya laci kasir sehingga tidak pernah punya baris di sini (Addendum 59.2).
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->bigIncrements('cash_txn_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->date('transaction_date');
            $table->enum('txn_type', ['OPENING_CASH', 'CASH_SALE', 'CASH_IN', 'CASH_OUT', 'REFUND']);
            $table->decimal('amount', 18, 2)->comment('signed +/-');
            $table->string('reference_type', 30)->nullable()
                ->comment('SALES/PURCHASE/PAYMENT/VOID/RETURN/OPNAME/EXPENSE');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->index(['fiscal_period_id', 'branch_id', 'transaction_date'], 'idx_cash_txn_period_date');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('shift_id')->references('shift_id')->on('cashier_shifts');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        Schema::create('cash_balances', function (Blueprint $table) {
            $table->bigIncrements('cash_balance_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('balance_date');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('cash_in', 18, 2)->default(0);
            $table->decimal('cash_out', 18, 2)->default(0);
            $table->decimal('closing_balance', 18, 2)->default(0);
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['branch_id', 'balance_date'], 'uk_cash_balances');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
        });

        Schema::create('cash_differences', function (Blueprint $table) {
            $table->bigIncrements('difference_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->decimal('expected_cash', 18, 2)->default(0);
            $table->decimal('actual_cash', 18, 2)->default(0);
            $table->decimal('difference', 18, 2)->default(0);
            $table->enum('status', ['OPEN', 'RESOLVED'])->default('OPEN');
            $table->enum('resolution_type', ['CASH_ADJUSTMENT', 'CASHIER_CHARGE', 'OTHER_INCOME', 'OTHER_EXPENSE'])->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('note')->nullable();

            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('shift_id')->references('shift_id')->on('cashier_shifts');
            $table->foreign('resolved_by')->references('user_id')->on('users');
        });

        // Biaya tunai POSTED wajib membuat cash_transactions CASH_OUT
        // dengan reference_type = 'EXPENSE' (Addendum 60.2).
        Schema::create('expenses', function (Blueprint $table) {
            $table->bigIncrements('expense_id');
            $table->string('expense_number', 40);
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_id')->nullable()->comment('diisi bila dibayar dari laci kasir');
            $table->unsignedBigInteger('expense_category_id');
            $table->date('transaction_date');
            $table->string('description', 255);
            $table->decimal('amount', 18, 2);
            $table->enum('payment_type', ['CASH', 'QRIS', 'DEBIT', 'CREDIT_CARD', 'TRANSFER', 'E_WALLET'])->default('CASH');
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unique(['branch_id', 'expense_number'], 'uk_expenses_number');
            $table->index(['fiscal_period_id', 'transaction_date'], 'idx_expenses_period_date');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('shift_id')->references('shift_id')->on('cashier_shifts');
            $table->foreign('expense_category_id')->references('expense_category_id')->on('expense_categories');
            $table->foreign('voided_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        // -------------------------------------------------------- 6. CLOSING
        Schema::create('fiscal_closings', function (Blueprint $table) {
            $table->bigIncrements('fiscal_closing_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->enum('status', ['VALID', 'REPLACED', 'FAILED'])->default('VALID');
            $table->integer('version')->default(1)->comment('closing ulang setelah reopen menaikkan version');

            $table->decimal('opening_inventory_value', 18, 2)->default(0);
            $table->decimal('purchase_value', 18, 2)->default(0);
            $table->decimal('purchase_return_value', 18, 2)->default(0);
            $table->decimal('sales_cost_value', 18, 2)->default(0);
            $table->decimal('sales_return_cost_value', 18, 2)->default(0);
            $table->decimal('adjustment_value', 18, 2)->default(0);
            $table->decimal('ending_inventory_value', 18, 2)->default(0);

            $table->decimal('cogs_value', 18, 2)->default(0);
            $table->decimal('gross_profit', 18, 2)->default(0)->comment('net_sales - cogs_value');
            $table->decimal('expense_total', 18, 2)->default(0);
            $table->decimal('net_profit', 18, 2)->default(0)->comment('gross_profit - expense_total');

            $table->decimal('opening_cash', 18, 2)->default(0);
            $table->decimal('cash_sales', 18, 2)->default(0);
            $table->decimal('cash_in', 18, 2)->default(0);
            $table->decimal('cash_out', 18, 2)->default(0);
            $table->decimal('refund', 18, 2)->default(0);
            $table->decimal('ending_cash', 18, 2)->default(0);
            $table->decimal('cash_difference', 18, 2)->default(0);

            $table->decimal('gross_sales', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('net_sales', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('payment_rounding_total', 18, 2)->default(0);
            $table->decimal('payment_cash', 18, 2)->default(0);
            $table->decimal('payment_qris', 18, 2)->default(0);
            $table->decimal('payment_debit', 18, 2)->default(0);
            $table->decimal('payment_credit_card', 18, 2)->default(0);
            $table->decimal('payment_transfer', 18, 2)->default(0);
            $table->decimal('payment_ewallet', 18, 2)->default(0);

            $table->dateTime('closed_at')->useCurrent();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['fiscal_period_id', 'version'], 'uk_closings_version');
            $table->index(['fiscal_period_id', 'status'], 'idx_closing_period');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('closed_by')->references('user_id')->on('users');
        });

        Schema::create('fiscal_closing_items', function (Blueprint $table) {
            $table->bigIncrements('closing_item_id');
            $table->unsignedBigInteger('fiscal_closing_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('opening_qty', 18, 4)->default(0);
            $table->decimal('opening_value', 18, 2)->default(0);
            $table->decimal('purchase_qty', 18, 4)->default(0);
            $table->decimal('purchase_value', 18, 2)->default(0);
            $table->decimal('sales_qty', 18, 4)->default(0);
            $table->decimal('cogs', 18, 2)->default(0);
            $table->decimal('adjustment_qty', 18, 4)->default(0);
            $table->decimal('adjustment_value', 18, 2)->default(0);
            $table->decimal('return_qty', 18, 4)->default(0);
            $table->decimal('return_value', 18, 2)->default(0);
            $table->decimal('ending_qty', 18, 4)->default(0);
            $table->decimal('ending_value', 18, 2)->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->decimal('rounding_adjustment', 18, 2)->default(0);

            $table->unique(['fiscal_closing_id', 'product_id'], 'uk_closing_items');
            $table->foreign('fiscal_closing_id')->references('fiscal_closing_id')->on('fiscal_closings')->cascadeOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products');
        });

        Schema::create('closing_attempt_logs', function (Blueprint $table) {
            $table->bigIncrements('attempt_log_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->string('step', 50);
            $table->enum('status', ['STARTED', 'SUCCESS', 'FAILED']);
            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->useCurrent();
            $table->dateTime('finished_at')->nullable();

            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
        });

        // ------------------------------------------- 7. PENOMORAN & AKUNTANSI
        // WAJIB SELECT ... FOR UPDATE saat mengambil nomor agar tidak ganda.
        Schema::create('document_numberings', function (Blueprint $table) {
            $table->bigIncrements('numbering_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->string('doc_prefix', 30)->comment('INV / GRN / PUR / PAY / TRF / EXP');
            $table->unsignedBigInteger('current_sequence')->default(0);
            $table->string('last_number', 40)->nullable();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['owner_id', 'branch_id', 'fiscal_period_id', 'doc_prefix'], 'uk_numberings');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->bigIncrements('journal_entry_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('entry_date');
            $table->enum('entry_type', ['CLOSING', 'OPENING', 'REOPEN_CORRECTION']);
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('created_by')->references('user_id')->on('users');
        });

        // Keseimbangan debit = credit per entry dijamin di service, bukan di tabel.
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->bigIncrements('line_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->char('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1);

            $table->foreign('journal_entry_id')->references('journal_entry_id')->on('journal_entries')->cascadeOnDelete();
            $table->foreign('account_id')->references('account_id')->on('chart_of_accounts');
            $table->foreign('currency_code')->references('currency_code')->on('currencies');
        });

        // ---------------------------------------------------------- 8. AUDIT
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('audit_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->enum('action', ['CREATE', 'UPDATE', 'DELETE', 'VOID', 'APPROVE', 'REJECT']);
            $table->string('entity_type', 50)->comment('nama tabel: products / price_lists / owners / sales');
            $table->unsignedBigInteger('entity_id');
            $table->string('field_name', 50)->nullable()->comment('NULL = perubahan seluruh record');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'created_at'], 'idx_audit_entity');
            $table->index(['owner_id', 'user_id', 'created_at'], 'idx_audit_user');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('user_id')->references('user_id')->on('users');
        });

        // TAHAP 2 — belum dipakai pada tahap 1 (Addendum 63).
        Schema::create('offline_sync_queue', function (Blueprint $table) {
            $table->bigIncrements('queue_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('device_id', 50);
            $table->char('local_uuid', 36);
            $table->string('entity_type', 30)->comment('SALES / PAYMENT / EXPENSE');
            $table->json('payload');
            $table->dateTime('local_created_at')->comment('waktu di perangkat kasir');
            $table->dateTime('received_at')->useCurrent();
            $table->enum('status', ['PENDING', 'SYNCED', 'REJECTED'])->default('PENDING');
            $table->dateTime('synced_at')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('final_number', 40)->nullable();
            $table->string('error_code', 30)->nullable()->comment('PERIOD_CLOSED / STOCK_NEGATIVE / VALIDATION');
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();

            $table->unique(['device_id', 'local_uuid'], 'uk_offline_local');
            $table->index(['branch_id', 'status', 'received_at'], 'idx_offline_status');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('resolved_by')->references('user_id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_queue');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('document_numberings');
        Schema::dropIfExists('closing_attempt_logs');
        Schema::dropIfExists('fiscal_closing_items');
        Schema::dropIfExists('fiscal_closings');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('cash_differences');
        Schema::dropIfExists('cash_balances');
        Schema::dropIfExists('cash_transactions');
    }
};

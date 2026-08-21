<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal period, shift kasir, dan saldo awal — database-schema.sql bagian 2 & 2.5.
 *
 * Gudang juga wajib punya fiscal period (Addendum 59.2): nilai persediaannya
 * masuk pembukuan, dan transfer ditolak bila salah satu sisi tidak punya
 * period OPEN. Yang tidak berlaku untuk gudang hanya shift dan rekonsiliasi kas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // branch_id NULL = Owner Level, berlaku seluruh branch milik owner (PRD 4)
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->bigIncrements('fiscal_period_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('period_code', 30);
            $table->string('period_name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['DRAFT', 'OPEN', 'CLOSING', 'CLOSED', 'REOPENED'])->default('DRAFT');
            $table->enum('costing_method', ['AVERAGE', 'FIFO', 'SPECIFIC'])->default('AVERAGE')
                ->comment('snapshot dari owners saat period dibuka (Addendum 38.1)');
            $table->char('currency_code', 3)->default('IDR');
            $table->dateTime('opened_at')->nullable();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['owner_id', 'branch_id', 'period_code'], 'uk_periods_code');
            $table->index(['owner_id', 'branch_id', 'start_date', 'end_date'], 'idx_periods_owner_dates');
            $table->foreign('owner_id')->references('owner_id')->on('owners');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('currency_code')->references('currency_code')->on('currencies');
            $table->foreign('opened_by')->references('user_id')->on('users');
            $table->foreign('closed_by')->references('user_id')->on('users');
            $table->foreign('reopened_by')->references('user_id')->on('users');
            $table->foreign('created_by')->references('user_id')->on('users');
            $table->foreign('updated_by')->references('user_id')->on('users');
        });

        Schema::create('fiscal_period_logs', function (Blueprint $table) {
            $table->bigIncrements('log_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->string('action', 30)->comment('CREATE/OPEN/CLOSING/CLOSED/REOPENED/FAILED');
            $table->string('old_status', 10)->nullable();
            $table->string('new_status', 10)->nullable();
            $table->unsignedBigInteger('action_by')->nullable();
            $table->dateTime('action_at')->useCurrent();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('closing_snapshot_id')->nullable();

            $table->index('fiscal_period_id', 'idx_period_logs');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('action_by')->references('user_id')->on('users');
        });

        Schema::create('fiscal_period_reopen_requests', function (Blueprint $table) {
            $table->bigIncrements('reopen_request_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('requested_by');
            $table->text('reason');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->enum('approval_type', ['NORMAL', 'SELF_APPROVED'])->default('NORMAL');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('requested_by')->references('user_id')->on('users');
            $table->foreign('reviewed_by')->references('user_id')->on('users');
        });

        Schema::create('opening_balances', function (Blueprint $table) {
            $table->bigIncrements('opening_balance_id');
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->enum('balance_type', ['CASH', 'INVENTORY', 'RECEIVABLE', 'PAYABLE', 'OTHER']);
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('note')->nullable();

            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
        });

        // Hanya untuk lokasi dengan branches.can_sell = TRUE (Addendum 59.2)
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->bigIncrements('shift_id');
            $table->string('shift_number', 40);
            $table->unsignedBigInteger('fiscal_period_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('cashier_id');
            $table->dateTime('opened_at')->useCurrent();
            $table->dateTime('closed_at')->nullable();
            $table->decimal('opening_cash', 18, 2)->default(0)->comment('uang di laci saat buka shift');
            $table->enum('opening_source', ['MANUAL', 'ROLLOVER'])->default('MANUAL');
            $table->decimal('expected_cash', 18, 2)->default(0);
            $table->decimal('actual_cash', 18, 2)->default(0);
            $table->decimal('difference', 18, 2)->default(0)->comment('actual - expected');
            $table->text('close_note')->nullable();
            $table->enum('status', ['OPEN', 'CLOSED'])->default('OPEN');
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->unique(['branch_id', 'shift_number'], 'uk_shifts_number');
            $table->foreign('fiscal_period_id')->references('fiscal_period_id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('cashier_id')->references('user_id')->on('users');
            $table->foreign('closed_by')->references('user_id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
        Schema::dropIfExists('opening_balances');
        Schema::dropIfExists('fiscal_period_reopen_requests');
        Schema::dropIfExists('fiscal_period_logs');
        Schema::dropIfExists('fiscal_periods');
    }
};

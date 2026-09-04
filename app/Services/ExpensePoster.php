<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memposting biaya operasional (Addendum 60).
 *
 * Biaya CASH adalah jalur PERTAMA di seluruh aplikasi yang menulis
 * `cash_transactions` — penjualan tunai sendiri belum menulisnya (menyusul
 * bersama Shift Kasir), jadi tabel itu untuk saat ini hanya mencatat biaya.
 */
class ExpensePoster
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{
     *     expense_category_id: int,
     *     transaction_date?: string|null,
     *     description: string,
     *     amount: float,
     *     payment_type: string
     * }  $payload
     */
    public function post(User $user, Branch $branch, array $payload): Expense
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['transaction_date'] ?? now()->toDateString();

        $category = ExpenseCategory::where('owner_id', $owner->owner_id)
            ->where('active', true)
            ->find($payload['expense_category_id'] ?? 0);

        if ($category === null) {
            throw ValidationException::withMessages(['expense_category_id' => 'Kategori biaya tidak dikenal atau sudah tidak aktif.']);
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nilai biaya harus lebih dari nol.']);
        }

        $paymentType = $payload['payment_type'] ?? null;

        if (! in_array($paymentType, Payment::TYPES, true)) {
            throw ValidationException::withMessages(['payment_type' => 'Metode bayar tidak dikenal.']);
        }

        $description = trim((string) ($payload['description'] ?? ''));

        if (mb_strlen($description) < 3) {
            throw ValidationException::withMessages(['description' => 'Keterangan wajib diisi.']);
        }

        return DB::transaction(function () use ($owner, $user, $branch, $date, $category, $amount, $paymentType, $description) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);

            $expense = Expense::create([
                'expense_number' => $this->numbers->next($owner->owner_id, $branch, $period, Expense::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'expense_category_id' => $category->expense_category_id,
                'transaction_date' => $date,
                'description' => $description,
                'amount' => $amount,
                'payment_type' => $paymentType,
                'status' => Expense::STATUS_POSTED,
                'created_by' => $user->user_id,
            ]);

            if ($paymentType === Payment::TYPE_CASH) {
                CashTransaction::create([
                    'fiscal_period_id' => $period->fiscal_period_id,
                    'branch_id' => $branch->branch_id,
                    'transaction_date' => $date,
                    'txn_type' => CashTransaction::TYPE_CASH_OUT,
                    'amount' => -$amount,
                    'reference_type' => CashTransaction::REF_EXPENSE,
                    'reference_id' => $expense->expense_id,
                    'created_by' => $user->user_id,
                ]);
            }

            return $expense->load('category');
        });
    }

    /** Membatalkan biaya: pembalikan kas (bila tunai) dicatat pada TANGGAL VOID (Addendum 41.3, 60.2). */
    public function void(User $user, Expense $expense, string $reason): Expense
    {
        $reason = trim($reason);

        if ($expense->status !== Expense::STATUS_POSTED) {
            throw ValidationException::withMessages([
                'expense' => $expense->status === Expense::STATUS_VOID
                    ? 'Biaya ini sudah dibatalkan sebelumnya.'
                    : 'Hanya biaya berstatus POSTED yang dapat dibatalkan.',
            ]);
        }

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['void_reason' => 'Alasan pembatalan wajib diisi dan tercatat permanen.']);
        }

        $period = $expense->period;

        if (! in_array($period->status, FiscalPeriodResolver::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'expense' => "Periode {$period->period_name} berstatus {$period->status}; pembatalan hanya dapat dilakukan selama periodenya masih terbuka.",
            ]);
        }

        $voidDate = now()->toDateString();

        if ($voidDate < $period->start_date->toDateString() || $voidDate > $period->end_date->toDateString()) {
            throw ValidationException::withMessages([
                'expense' => "Hari ini di luar periode {$period->period_name}. Pembatalan hanya boleh dilakukan pada periode yang sama dengan biayanya.",
            ]);
        }

        return DB::transaction(function () use ($user, $expense, $reason, $voidDate) {
            if ($expense->isCash()) {
                CashTransaction::create([
                    'fiscal_period_id' => $expense->fiscal_period_id,
                    'branch_id' => $expense->branch_id,
                    'transaction_date' => $voidDate,
                    'txn_type' => CashTransaction::TYPE_CASH_IN,
                    'amount' => (float) $expense->amount,
                    'reference_type' => CashTransaction::REF_EXPENSE,
                    'reference_id' => $expense->expense_id,
                    'note' => 'Pembalikan saat pembatalan biaya.',
                    'created_by' => $user->user_id,
                ]);
            }

            $expense->update([
                'status' => Expense::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $user->user_id,
                'void_reason' => $reason,
            ]);

            return $expense->fresh();
        });
    }
}

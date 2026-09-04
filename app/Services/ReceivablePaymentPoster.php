<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashTransaction;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menerima pelunasan piutang usaha dari pelanggan.
 *
 * Kebalikan dari [[PayablePaymentPoster]]: di sini kas MASUK (CASH_IN), bukan
 * keluar. Pembayarannya ditaut ke nota asal lewat pivot `sale_payments` yang
 * sudah ada — bukan tabel pivot baru — karena satu nota memang boleh punya
 * lebih dari satu baris `payments` seiring cicilan.
 */
class ReceivablePaymentPoster
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{payment_date?: string|null, amount: float, payment_type: string}  $payload
     */
    public function post(User $user, Branch $branch, Receivable $receivable, array $payload): Payment
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['payment_date'] ?? now()->toDateString();

        if (! in_array($receivable->status, [Receivable::STATUS_OPEN, Receivable::STATUS_PARTIAL], true)) {
            throw ValidationException::withMessages([
                'receivable' => $receivable->status === Receivable::STATUS_PAID
                    ? 'Piutang ini sudah lunas.'
                    : 'Piutang ini sudah dibatalkan.',
            ]);
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $outstanding = $receivable->outstanding();

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nilai pembayaran harus lebih dari nol.']);
        }

        if ($amount > $outstanding) {
            throw ValidationException::withMessages([
                'amount' => sprintf('Nilai pembayaran melebihi sisa piutang (Rp%s).', number_format($outstanding, 0, ',', '.')),
            ]);
        }

        $paymentType = $payload['payment_type'] ?? null;

        if (! in_array($paymentType, Payment::TYPES, true)) {
            throw ValidationException::withMessages(['payment_type' => 'Metode bayar tidak dikenal.']);
        }

        return DB::transaction(function () use ($owner, $user, $branch, $receivable, $date, $amount, $paymentType) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);

            $payment = Payment::create([
                'payment_number' => $this->numbers->next($owner->owner_id, $branch, $period, Payment::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'transaction_date' => $date,
                'payment_type' => $paymentType,
                'amount' => $amount,
                'currency_code' => $owner->default_currency,
                'exchange_rate' => 1,
                'status' => Payment::STATUS_POSTED,
                'created_by' => $user->user_id,
            ]);

            if ($receivable->sale_id !== null) {
                DB::table('sale_payments')->insert([
                    'sale_id' => $receivable->sale_id,
                    'payment_id' => $payment->payment_id,
                    'amount' => $amount,
                ]);
            }

            $paidAmount = round((float) $receivable->paid_amount + $amount, 2);
            $receivable->update([
                'paid_amount' => $paidAmount,
                'status' => $paidAmount >= round((float) $receivable->amount, 2) ? Receivable::STATUS_PAID : Receivable::STATUS_PARTIAL,
            ]);

            if ($paymentType === Payment::TYPE_CASH) {
                CashTransaction::create([
                    'fiscal_period_id' => $period->fiscal_period_id,
                    'branch_id' => $branch->branch_id,
                    'transaction_date' => $date,
                    'txn_type' => CashTransaction::TYPE_CASH_IN,
                    'amount' => $amount,
                    'reference_type' => CashTransaction::REF_RECEIVABLE,
                    'reference_id' => $payment->payment_id,
                    'created_by' => $user->user_id,
                ]);
            }

            return $payment;
        });
    }

    /** Membatalkan pembayaran: piutang kembali OPEN/PARTIAL, kas dibalik pada tanggal void (Addendum 41.3). */
    public function void(User $user, Payment $payment): Payment
    {
        if ($payment->status !== Payment::STATUS_POSTED) {
            throw ValidationException::withMessages(['payment' => 'Hanya pembayaran berstatus POSTED yang dapat dibatalkan.']);
        }

        $period = $payment->period;

        if (! in_array($period->status, FiscalPeriodResolver::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'payment' => "Periode {$period->period_name} berstatus {$period->status}; pembatalan hanya dapat dilakukan selama periodenya masih terbuka.",
            ]);
        }

        $voidDate = now()->toDateString();

        if ($voidDate < $period->start_date->toDateString() || $voidDate > $period->end_date->toDateString()) {
            throw ValidationException::withMessages([
                'payment' => "Hari ini di luar periode {$period->period_name}. Pembatalan hanya boleh dilakukan pada periode yang sama dengan pembayarannya.",
            ]);
        }

        $saleId = DB::table('sale_payments')->where('payment_id', $payment->payment_id)->value('sale_id');
        $receivable = $saleId !== null ? Receivable::where('sale_id', $saleId)->first() : null;

        if ($receivable === null) {
            throw ValidationException::withMessages(['payment' => 'Piutang terkait pembayaran ini tidak ditemukan.']);
        }

        return DB::transaction(function () use ($user, $payment, $receivable, $voidDate) {
            $amount = (float) $payment->amount;
            $paidAmount = round((float) $receivable->paid_amount - $amount, 2);

            $receivable->update([
                'paid_amount' => max($paidAmount, 0),
                'status' => $paidAmount <= 0 ? Receivable::STATUS_OPEN : Receivable::STATUS_PARTIAL,
            ]);

            if ($payment->isCash()) {
                CashTransaction::create([
                    'fiscal_period_id' => $payment->fiscal_period_id,
                    'branch_id' => $payment->branch_id,
                    'transaction_date' => $voidDate,
                    'txn_type' => CashTransaction::TYPE_CASH_OUT,
                    'amount' => -$amount,
                    'reference_type' => CashTransaction::REF_RECEIVABLE,
                    'reference_id' => $payment->payment_id,
                    'note' => 'Pembalikan saat pembatalan pembayaran piutang.',
                    'created_by' => $user->user_id,
                ]);
            }

            $payment->update([
                'status' => Payment::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $user->user_id,
            ]);

            return $payment->fresh();
        });
    }
}

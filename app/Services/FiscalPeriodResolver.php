<?php

namespace App\Services;

use App\Models\FiscalPeriod;
use Illuminate\Validation\ValidationException;

/**
 * Menentukan period fiskal sebuah transaksi dari tanggalnya (PRD 8, 34).
 *
 * Setiap transaksi wajib menempel pada satu period. Yang menentukan bukan
 * "period yang sedang aktif" melainkan TANGGAL transaksinya — kalau tidak,
 * transaksi yang dicatat mundur akan masuk ke period berjalan dan membuat
 * laporan period lama berubah setelah ditutup.
 */
class FiscalPeriodResolver
{
    /** Period yang boleh menerima transaksi baru. */
    public const OPEN_STATUSES = ['OPEN', 'REOPENED'];

    /**
     * @throws ValidationException bila tanggalnya tidak punya period, atau
     *                             period-nya tidak menerima transaksi.
     */
    public function forDate(int $ownerId, int $branchId, string $date): FiscalPeriod
    {
        $period = FiscalPeriod::where('owner_id', $ownerId)
            // Period boleh dibuat khusus satu lokasi (branch_id) atau berlaku
            // seluruh lokasi milik owner (branch_id NULL). Yang khusus menang.
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByRaw('branch_id IS NULL')
            ->first();

        if ($period === null) {
            throw ValidationException::withMessages([
                'transaction_date' => "Tanggal {$date} tidak berada dalam periode pembukuan mana pun. Buat periodenya lebih dulu.",
            ]);
        }

        if (! in_array($period->status, self::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'transaction_date' => "Periode {$period->period_name} berstatus {$period->status} sehingga tidak dapat menerima transaksi baru.",
            ]);
        }

        return $period;
    }
}

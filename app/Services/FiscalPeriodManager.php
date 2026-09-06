<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\FiscalPeriodLog;
use App\Models\Owner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat dan membuka fiscal period (PRD 4, Addendum 40, 56.2).
 *
 * Period dibuat berstatus DRAFT, bukan langsung OPEN. Jeda itu yang memberi
 * tempat untuk Initial Opening: begitu period menerima transaksi, saldo
 * awalnya tidak boleh diketik lagi (Addendum 40.4).
 *
 * Kode dan nama period DIBUAT SISTEM, tidak diketik pengguna. Nama yang
 * diketik tangan cepat berantakan, dan satu salah ketik tidak berhenti di layar
 * periode: `period_code` menjadi bagian nomor setiap dokumen periode itu
 * (Addendum 52.1), jadi ia ikut tercetak di seluruh nota selamanya.
 */
class FiscalPeriodManager
{
    /** Nama bulan ditulis sendiri karena locale aplikasi masih 'en'. */
    private const BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /**
     * @param  array{
     *     branch_id?: int|null,
     *     start_date: string,
     *     end_date: string
     * }  $payload
     */
    public function buat(User $user, array $payload): FiscalPeriod
    {
        $owner = Owner::findOrFail($user->owner_id);
        $branchId = $payload['branch_id'] ?? null;

        if ($branchId !== null) {
            $branch = Branch::where('owner_id', $owner->owner_id)->find($branchId);

            if ($branch === null) {
                throw ValidationException::withMessages(['branch_id' => 'Lokasi tersebut bukan milik usaha ini.']);
            }
        }

        $start = $payload['start_date'];
        $end = $payload['end_date'];

        if ($start > $end) {
            throw ValidationException::withMessages(['end_date' => 'Tanggal selesai tidak boleh mendahului tanggal mulai.']);
        }

        $this->tolakBilaTumpang($owner->owner_id, $branchId, $start, $end);

        $mulai = CarbonImmutable::parse($start);

        return DB::transaction(function () use ($owner, $user, $branchId, $start, $end, $mulai) {
            $period = FiscalPeriod::create([
                'owner_id' => $owner->owner_id,
                'branch_id' => $branchId,
                'period_code' => $this->kodeBaru($owner->owner_id, $branchId, $mulai),
                'period_name' => $this->namaBaru($mulai),
                'start_date' => $start,
                'end_date' => $end,
                'status' => FiscalPeriod::STATUS_DRAFT,
                // Metode costing dibekukan saat period dibuat (Addendum 38.1):
                // mengubah setting owner di tengah jalan tidak boleh menghitung
                // ulang HPP period yang sudah berjalan.
                'costing_method' => $owner->costing_method,
                'currency_code' => $owner->default_currency,
                'created_by' => $user->user_id,
                'updated_by' => $user->user_id,
            ]);

            $this->catat($period, FiscalPeriodLog::ACTION_CREATE, null, FiscalPeriod::STATUS_DRAFT, $user);

            return $period;
        });
    }

    /**
     * DRAFT -> OPEN. Sejak titik ini period menerima transaksi.
     */
    public function buka(User $user, FiscalPeriod $period): FiscalPeriod
    {
        if ($period->status !== FiscalPeriod::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'period' => $period->status === FiscalPeriod::STATUS_OPEN
                    ? 'Periode ini sudah terbuka.'
                    : "Hanya periode berstatus DRAFT yang dapat dibuka; yang ini {$period->status}.",
            ]);
        }

        return DB::transaction(function () use ($user, $period) {
            $period->update([
                'status' => FiscalPeriod::STATUS_OPEN,
                'opened_at' => now(),
                'opened_by' => $user->user_id,
                'updated_by' => $user->user_id,
            ]);

            $this->catat($period, FiscalPeriodLog::ACTION_OPEN, FiscalPeriod::STATUS_DRAFT, FiscalPeriod::STATUS_OPEN, $user);

            return $period->fresh();
        });
    }

    /**
     * Period tidak boleh bertindih pada scope yang sama (Addendum 56.2).
     *
     * Dua period yang bertindih membuat satu tanggal punya dua rumah, dan
     * FiscalPeriodResolver akan memilih salah satunya secara sewenang-wenang —
     * laporan dua period itu lalu memperebutkan transaksi yang sama.
     */
    private function tolakBilaTumpang(int $ownerId, ?int $branchId, string $start, string $end): void
    {
        $tumpang = FiscalPeriod::where('owner_id', $ownerId)
            ->when(
                $branchId === null,
                fn ($q) => $q->whereNull('branch_id'),
                fn ($q) => $q->where('branch_id', $branchId),
            )
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->first();

        if ($tumpang !== null) {
            throw ValidationException::withMessages([
                'start_date' => "Rentang ini bertindih dengan {$tumpang->period_code} ({$tumpang->start_date->toDateString()} s/d {$tumpang->end_date->toDateString()}).",
            ]);
        }
    }

    /**
     * Kode period dari bulan MULAI-nya: FP-2026-09.
     *
     * Period yang mulai pada bulan yang sama diberi akhiran urut supaya kodenya
     * tetap unik per scope — bukan ditolak, karena penomoran bukan urusan
     * pengguna dan tidak seharusnya menghalangi mereka membuat period.
     */
    private function kodeBaru(int $ownerId, ?int $branchId, CarbonImmutable $mulai): string
    {
        $dasar = 'FP-'.$mulai->format('Y-m');
        $kode = $dasar;
        $urutan = 1;

        while ($this->kodeDipakai($ownerId, $branchId, $kode)) {
            $urutan++;
            $kode = $dasar.'-'.$urutan;
        }

        return $kode;
    }

    /**
     * Nama period mengikuti bulan MULAI-nya, walau berakhir di bulan berikutnya.
     *
     * Period 6 September - 5 Oktober tetap bernama "September 2026": begitulah
     * orang menyebutnya, dan rentang tanggalnya sudah tampil berdampingan di
     * setiap layar sehingga tidak ada yang tersembunyi.
     */
    private function namaBaru(CarbonImmutable $mulai): string
    {
        return self::BULAN[(int) $mulai->month].' '.$mulai->year;
    }

    private function kodeDipakai(int $ownerId, ?int $branchId, string $code): bool
    {
        return FiscalPeriod::where('owner_id', $ownerId)
            ->when(
                $branchId === null,
                fn ($q) => $q->whereNull('branch_id'),
                fn ($q) => $q->where('branch_id', $branchId),
            )
            ->where('period_code', $code)
            ->exists();
    }

    /**
     * Rentang tanggal yang disarankan untuk period berikutnya pada satu scope.
     *
     * Belum ada period sama sekali: tanggal 1 sampai akhir bulan berjalan.
     *
     * Sudah ada: menyambung sehari setelah period terakhir berakhir, dan
     * panjangnya MENGIKUTI KEBIASAAN period itu. Usaha yang tutup bukunya jatuh
     * tiap tanggal 5 terus disarankan tanggal 5, tidak dipaksa balik ke akhir
     * bulan; sebaliknya period yang memang berakhir di ujung bulan tetap
     * ditawarkan sampai ujung bulan walau jumlah harinya berbeda (31 Januari
     * menyarankan 28 atau 29 Februari, bukan 31).
     *
     * @return array{startDate: string, endDate: string}
     */
    public function saranRentang(int $ownerId, ?int $branchId): array
    {
        $terakhir = FiscalPeriod::where('owner_id', $ownerId)
            ->when(
                $branchId === null,
                fn ($q) => $q->whereNull('branch_id'),
                fn ($q) => $q->where('branch_id', $branchId),
            )
            ->orderByDesc('end_date')
            ->first();

        if ($terakhir === null) {
            $mulai = CarbonImmutable::today()->startOfMonth();

            return ['startDate' => $mulai->toDateString(), 'endDate' => $mulai->endOfMonth()->toDateString()];
        }

        $akhirLalu = CarbonImmutable::parse($terakhir->end_date);
        $mulai = $akhirLalu->addDay();

        $akhir = $akhirLalu->day === $akhirLalu->daysInMonth
            ? $mulai->endOfMonth()
            : $akhirLalu->addMonthNoOverflow();

        return ['startDate' => $mulai->toDateString(), 'endDate' => $akhir->toDateString()];
    }

    private function catat(FiscalPeriod $period, string $action, ?string $old, ?string $new, User $user, ?string $reason = null): void
    {
        FiscalPeriodLog::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'action' => $action,
            'old_status' => $old,
            'new_status' => $new,
            'action_by' => $user->user_id,
            'action_at' => now(),
            'reason' => $reason,
        ]);
    }
}

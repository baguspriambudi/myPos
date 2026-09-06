<?php

namespace App\Services;

use App\Models\FiscalClosing;
use App\Models\FiscalPeriod;
use App\Models\FiscalPeriodLog;
use App\Models\FiscalPeriodReopenRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuka kembali period yang sudah ditutup (Addendum 45).
 *
 * Selalu dua langkah: mengajukan dan menyetujui. Tidak ada auto-approve, dan
 * SUPER ADMIN tidak dapat menyetujui permintaan OWNER — kalau ia bisa,
 * "persetujuan pemilik" hanya formalitas.
 *
 * Satu jalan keluar disediakan untuk usaha yang pemiliknya hanya satu orang:
 * ia boleh menyetujui permintaannya sendiri, ditandai SELF_APPROVED supaya
 * jelas terlihat saat diaudit (Addendum 45.2). Tanpa itu alurnya buntu.
 */
class FiscalPeriodReopener
{
    private const PERAN_PENGAJU = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN];

    public function ajukan(User $user, FiscalPeriod $period, string $alasan): FiscalPeriodReopenRequest
    {
        if (! in_array($user->role, self::PERAN_PENGAJU, true)) {
            throw ValidationException::withMessages([
                'period' => 'Hanya Pemilik dan Super Admin yang dapat mengajukan buka ulang periode.',
            ]);
        }

        if ($period->status !== FiscalPeriod::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'period' => "Hanya periode berstatus CLOSED yang dapat dibuka ulang; periode ini {$period->status}.",
            ]);
        }

        if (trim($alasan) === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan buka ulang wajib diisi.']);
        }

        $menggantung = $period->reopenRequests()->where('status', FiscalPeriodReopenRequest::STATUS_PENDING)->exists();

        if ($menggantung) {
            throw ValidationException::withMessages([
                'period' => 'Sudah ada permintaan buka ulang yang menunggu persetujuan untuk periode ini.',
            ]);
        }

        return FiscalPeriodReopenRequest::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'requested_by' => $user->user_id,
            'reason' => $alasan,
            'status' => FiscalPeriodReopenRequest::STATUS_PENDING,
            'approval_type' => FiscalPeriodReopenRequest::APPROVAL_NORMAL,
            'created_at' => now(),
        ]);
    }

    public function setujui(User $user, FiscalPeriodReopenRequest $request): FiscalPeriod
    {
        $this->pastikanBolehMenilai($user, $request);

        $period = $request->period;

        if ($period->status !== FiscalPeriod::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'request' => "Periode ini sudah tidak berstatus CLOSED (sekarang {$period->status}).",
            ]);
        }

        $sendiri = $request->requested_by === $user->user_id;

        return DB::transaction(function () use ($user, $request, $period, $sendiri) {
            // Snapshot lama tidak dihapus, hanya ditandai REPLACED: laporan
            // berhenti memakainya, tetapi angka yang pernah dipublikasikan
            // masih dapat ditelusuri (Addendum 25, 45.3).
            $snapshot = $period->snapshot();
            $snapshot?->update(['status' => FiscalClosing::STATUS_REPLACED]);

            $request->update([
                'status' => FiscalPeriodReopenRequest::STATUS_APPROVED,
                'approval_type' => $sendiri
                    ? FiscalPeriodReopenRequest::APPROVAL_SELF
                    : FiscalPeriodReopenRequest::APPROVAL_NORMAL,
                'reviewed_by' => $user->user_id,
                'reviewed_at' => now(),
            ]);

            $period->update([
                'status' => FiscalPeriod::STATUS_REOPENED,
                'reopened_at' => now(),
                'reopened_by' => $user->user_id,
                'updated_by' => $user->user_id,
            ]);

            FiscalPeriodLog::create([
                'fiscal_period_id' => $period->fiscal_period_id,
                'action' => FiscalPeriodLog::ACTION_REOPENED,
                'old_status' => FiscalPeriod::STATUS_CLOSED,
                'new_status' => FiscalPeriod::STATUS_REOPENED,
                'action_by' => $user->user_id,
                'action_at' => now(),
                'reason' => ($sendiri ? '[SELF_APPROVED] ' : '').$request->reason,
                'closing_snapshot_id' => $snapshot?->fiscal_closing_id,
            ]);

            return $period->fresh();
        });
    }

    public function tolak(User $user, FiscalPeriodReopenRequest $request, ?string $catatan = null): FiscalPeriodReopenRequest
    {
        $this->pastikanBolehMenilai($user, $request, menolak: true);

        $request->update([
            'status' => FiscalPeriodReopenRequest::STATUS_REJECTED,
            'reviewed_by' => $user->user_id,
            'reviewed_at' => now(),
            'reason' => $catatan === null || trim($catatan) === ''
                ? $request->reason
                : $request->reason."\n\nDitolak: ".$catatan,
        ]);

        return $request->fresh();
    }

    /**
     * Period sesudahnya yang ikut terdampak bila period ini dibuka ulang.
     *
     * Saldo awal period berikutnya diwarisi dari snapshot period ini. Begitu
     * snapshot itu diganti, period berikutnya yang sudah CLOSED memakai angka
     * pembuka yang tidak berlaku lagi dan wajib ditutup ulang (Addendum 45.3).
     * Daftarnya ditampilkan sebelum reopen dijalankan — bukan ditutup ulang
     * otomatis, karena tiap penutupan tetap perlu keputusan orang.
     */
    public function terdampak(FiscalPeriod $period): Collection
    {
        return $period->sesudahnya()->filter(
            fn (FiscalPeriod $p) => in_array($p->status, [FiscalPeriod::STATUS_CLOSED, FiscalPeriod::STATUS_REOPENED], true),
        )->values();
    }

    private function pastikanBolehMenilai(User $user, FiscalPeriodReopenRequest $request, bool $menolak = false): void
    {
        $kata = $menolak ? 'menolak' : 'menyetujui';

        // Hanya OWNER. SUPER ADMIN sengaja tidak termasuk, meski ia boleh
        // mengajukan (Addendum 45.2).
        if ($user->role !== User::ROLE_OWNER) {
            throw ValidationException::withMessages([
                'request' => "Hanya Pemilik yang dapat {$kata} permintaan buka ulang.",
            ]);
        }

        if ($request->status !== FiscalPeriodReopenRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'request' => 'Permintaan ini sudah pernah ditinjau.',
            ]);
        }

        if ($request->requested_by !== $user->user_id) {
            return;
        }

        // Menyetujui permintaan sendiri hanya boleh bila memang tidak ada
        // pemilik lain yang bisa dimintai persetujuan.
        $pemilikLain = User::where('owner_id', $user->owner_id)
            ->where('role', User::ROLE_OWNER)
            ->where('user_id', '!=', $user->user_id)
            ->where('active', true)
            ->exists();

        if ($pemilikLain) {
            throw ValidationException::withMessages([
                'request' => 'Permintaan Anda sendiri harus ditinjau pemilik lain pada usaha ini.',
            ]);
        }
    }
}

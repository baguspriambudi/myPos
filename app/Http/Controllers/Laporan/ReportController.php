<?php

namespace App\Http\Controllers\Laporan;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Support\ActiveBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bagian bersama seluruh laporan.
 *
 * Semua laporan bertumpu pada dua hal yang sama: satu periode terpilih dan
 * lokasi yang sedang dilihat. Keduanya dikumpulkan di sini supaya tiap laporan
 * tidak menafsirkan sendiri-sendiri apa arti "periode berjalan" — perbedaan
 * kecil di situ membuat dua laporan yang seharusnya cocok jadi tidak cocok.
 */
abstract class ReportController extends Controller
{
    /**
     * Laporan yang menyangkut uang dibatasi MANAGER ke atas (Addendum 50).
     *
     * GUDANG memang tidak menyentuh uang sama sekali, dan KASIR hanya berhak
     * atas angka shift-nya sendiri — bukan rekap seluruh lokasi.
     */
    protected const PERAN_UANG = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    protected function authorizeUang(Request $request, string $namaLaporan): User
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_UANG, true)) {
            throw new HttpException(403, "Hanya Pemilik, Super Admin, dan Manajer yang dapat melihat {$namaLaporan}.");
        }

        return $user;
    }

    /**
     * Periode yang sedang dilihat.
     *
     * Pilihan pengguna menang; kalau tidak ada, ambil yang sedang terbuka, dan
     * kalau tidak ada juga, yang paling akhir. Periode paling akhir dipilih
     * belakangan supaya usaha yang baru saja menutup semua periodenya tetap
     * melihat angka, bukan halaman kosong.
     */
    protected function periodePilihan(Request $request, User $user, ?Branch $branch): ?FiscalPeriod
    {
        $daftar = $this->daftarPeriode($user, $branch);

        if ($daftar->isEmpty()) {
            return null;
        }

        $diminta = $request->integer('periode');

        return $daftar->firstWhere('fiscal_period_id', $diminta)
            ?? $daftar->firstWhere('status', FiscalPeriod::STATUS_OPEN)
            ?? $daftar->firstWhere('status', FiscalPeriod::STATUS_REOPENED)
            ?? $daftar->first();
    }

    /**
     * Periode milik usaha ini yang menyangkut lokasi aktif.
     *
     * @return Collection<int, FiscalPeriod>
     */
    protected function daftarPeriode(User $user, ?Branch $branch): Collection
    {
        return FiscalPeriod::where('owner_id', $user->owner_id)
            ->when($branch, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branch->branch_id)->orWhereNull('branch_id')))
            ->orderByDesc('start_date')
            ->get();
    }

    /**
     * @param  Collection<int, FiscalPeriod>  $daftar
     * @return array<int, array<string, mixed>>
     */
    protected function pilihanPeriode(Collection $daftar): array
    {
        return $daftar->map(fn (FiscalPeriod $p) => [
            'id' => $p->fiscal_period_id,
            'code' => $p->period_code,
            'name' => $p->period_name,
            'status' => $p->status,
            'startDate' => $p->start_date->toDateString(),
            'endDate' => $p->end_date->toDateString(),
        ])->all();
    }

    /** @return array<string, mixed>|null */
    protected function metaPeriode(?FiscalPeriod $period): ?array
    {
        if ($period === null) {
            return null;
        }

        return [
            'id' => $period->fiscal_period_id,
            'code' => $period->period_code,
            'name' => $period->period_name,
            'status' => $period->status,
            'startDate' => $period->start_date->toDateString(),
            'endDate' => $period->end_date->toDateString(),
            'branch' => $period->branch?->branch_name,
        ];
    }

    protected function lokasiAktif(User $user): ?Branch
    {
        return ActiveBranch::for($user);
    }

    /** @return array<string, mixed>|null */
    protected function metaLokasi(?Branch $branch): ?array
    {
        return $branch === null ? null : ['id' => $branch->branch_id, 'name' => $branch->branch_name];
    }
}

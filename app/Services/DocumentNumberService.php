<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen transaksi (Addendum 52).
 *
 *   [PREFIX]/[BRANCH_CODE]/[PERIOD_CODE]/[SEQUENCE 6 digit]
 *   INV/BR01/FP-2026-08/000001
 *
 * Kode lokasi WAJIB ada di dalamnya: sequence di-reset per (owner, lokasi,
 * period), jadi tanpa kode lokasi dua cabang akan mencetak nomor yang sama
 * persis pada hari yang sama.
 *
 * period_code dipakai apa adanya, bukan diturunkan dari bulan transaksi, supaya
 * period non-bulanan (mingguan atau custom) tetap menghasilkan nomor yang sah.
 */
class DocumentNumberService
{
    /**
     * Ambil satu nomor baru dan naikkan penghitungnya.
     *
     * WAJIB dipanggil di dalam transaksi database. Barisnya dikunci dengan
     * SELECT ... FOR UPDATE karena dua kasir yang menekan "Bayar" pada detik
     * yang sama akan membaca penghitung yang sama dan mencetak nomor kembar —
     * dan nomor kembar baru ketahuan saat unique key menolak nota kedua, yaitu
     * setelah stok terlanjur keluar.
     */
    public function next(int $ownerId, Branch $branch, FiscalPeriod $period, string $prefix): string
    {
        $scope = [
            'owner_id' => $ownerId,
            'branch_id' => $branch->branch_id,
            'fiscal_period_id' => $period->fiscal_period_id,
            'doc_prefix' => $prefix,
        ];

        // Baris penghitung dibuat sekali per scope. insertOrIgnore dipakai agar
        // dua permintaan pertama yang datang bersamaan tidak saling menggagalkan
        // lewat unique key uk_numberings.
        DB::table('document_numberings')->insertOrIgnore($scope + ['current_sequence' => 0]);

        $row = DB::table('document_numberings')->where($scope)->lockForUpdate()->first();

        $sequence = (int) $row->current_sequence + 1;

        $number = sprintf(
            '%s/%s/%s/%06d',
            $prefix,
            $branch->branch_code,
            $period->period_code,
            $sequence,
        );

        DB::table('document_numberings')
            ->where('numbering_id', $row->numbering_id)
            ->update(['current_sequence' => $sequence, 'last_number' => $number]);

        return $number;
    }
}

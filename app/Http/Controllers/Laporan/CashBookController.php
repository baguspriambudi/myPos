<?php

namespace App\Http\Controllers\Laporan;

use App\Models\CashTransaction;
use App\Models\FiscalPeriod;
use App\Services\FiscalPeriodFigures;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Buku kas satu periode (PRD 18, Addendum 41.4).
 *
 * Saldo berjalannya dimulai dari saldo kas awal periode, lalu bergerak
 * mengikuti urutan tanggal. Baris `OPENING_CASH` tetap DITAMPILKAN karena
 * memang tertulis di buku kas, tetapi TIDAK menggerakkan saldo berjalan: itu
 * uang laci yang digulirkan dari shift sebelumnya, bukan uang yang baru masuk.
 * Menjumlahkannya berarti menghitung uang yang sama sekali per shift.
 */
class CashBookController extends ReportController
{
    private const PER_PAGE = 50;

    public function __construct(private FiscalPeriodFigures $figures) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizeUang($request, 'buku kas');
        $branch = $this->lokasiAktif($user);
        $daftar = $this->daftarPeriode($user, $branch);
        $period = $this->periodePilihan($request, $user, $branch);

        return Inertia::render('laporan/kas', [
            'periods' => $this->pilihanPeriode($daftar),
            'period' => $this->metaPeriode($period),
            'summary' => $period === null ? null : $this->ringkasan($period),
            'rows' => $period === null ? [] : $this->baris($period),
            'branch' => $this->metaLokasi($branch),
        ]);
    }

    /** @return array<string, mixed> */
    private function ringkasan(FiscalPeriod $period): array
    {
        $kas = $this->figures->kas($period);

        return [
            'opening' => $kas['opening_cash'],
            'sales' => $kas['cash_sales'],
            'in' => $kas['cash_in'],
            'out' => $kas['cash_out'],
            'refund' => $kas['refund'],
            'ending' => $kas['ending_cash'],
            'difference' => $kas['cash_difference'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function baris(FiscalPeriod $period): array
    {
        $saldo = $this->figures->saldoKasAwal($period);

        return CashTransaction::where('fiscal_period_id', $period->fiscal_period_id)
            ->with('branch')
            ->orderBy('transaction_date')
            ->orderBy('cash_txn_id')
            ->limit(self::PER_PAGE * 10)
            ->get()
            ->map(function (CashTransaction $t) use (&$saldo) {
                $menggerakkan = $t->txn_type !== CashTransaction::TYPE_OPENING_CASH;

                if ($menggerakkan) {
                    $saldo = round($saldo + (float) $t->amount, 2);
                }

                return [
                    'id' => $t->cash_txn_id,
                    'date' => $t->transaction_date->toDateString(),
                    'type' => $t->txn_type,
                    'amount' => round((float) $t->amount, 2),
                    'reference' => $t->reference_type,
                    'note' => $t->note,
                    'branch' => $t->branch?->branch_name,
                    'movesBalance' => $menggerakkan,
                    'balance' => $menggerakkan ? $saldo : null,
                ];
            })
            ->all();
    }
}

<?php

namespace App\Http\Controllers\Laporan;

use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Services\FiscalPeriodFigures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laba rugi satu periode (PRD 19, Addendum 60).
 *
 * Untuk periode yang sudah ditutup, angkanya diambil dari SNAPSHOT — bukan
 * dihitung ulang. Itulah gunanya menutup periode: kalau laporan tetap
 * menghitung ulang tiap kali dibuka, angka periode lama bisa bergeser diam-diam
 * dan penutupan kehilangan artinya. Lihat FiscalPeriodFigures::labaRugi().
 */
class ProfitLossController extends ReportController
{
    public function __construct(private FiscalPeriodFigures $figures) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizeUang($request, 'laporan laba rugi');
        $branch = $this->lokasiAktif($user);
        $daftar = $this->daftarPeriode($user, $branch);
        $period = $this->periodePilihan($request, $user, $branch);

        return Inertia::render('laporan/laba-rugi', [
            'periods' => $this->pilihanPeriode($daftar),
            'period' => $this->metaPeriode($period),
            'figures' => $period === null ? null : $this->figures->labaRugi($period),
            'expenses' => $period === null ? [] : $this->biayaPerKategori($period),
            'branch' => $this->metaLokasi($branch),
        ]);
    }

    /**
     * Rincian biaya operasional per kategori.
     *
     * Sengaja dihitung langsung dari `expenses`, bukan diambil dari snapshot:
     * snapshot hanya menyimpan totalnya, sedangkan yang dicari pemilik toko
     * biasanya justru "biaya apa yang paling besar".
     *
     * @return array<int, array<string, mixed>>
     */
    private function biayaPerKategori(FiscalPeriod $period): array
    {
        return DB::table('expenses')
            ->leftJoin('expense_categories', 'expense_categories.expense_category_id', '=', 'expenses.expense_category_id')
            ->where('expenses.fiscal_period_id', $period->fiscal_period_id)
            ->where('expenses.status', Expense::STATUS_POSTED)
            ->groupBy('expense_categories.category_name')
            ->orderByDesc(DB::raw('SUM(expenses.amount)'))
            ->selectRaw('expense_categories.category_name AS nama, COUNT(*) AS jumlah, SUM(expenses.amount) AS total')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->nama ?? 'Tanpa kategori',
                'count' => (int) $row->jumlah,
                'amount' => round((float) $row->total, 2),
            ])
            ->all();
    }
}

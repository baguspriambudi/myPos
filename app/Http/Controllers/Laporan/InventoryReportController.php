<?php

namespace App\Http\Controllers\Laporan;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nilai persediaan per lokasi (PRD 17, Addendum 59.4).
 *
 * Terbuka untuk semua peran, termasuk GUDANG dan KASIR — mereka justru yang
 * paling sering perlu tahu sisa barang (Addendum 50: "Lihat stok & kartu stok
 * ✔ branch-nya"). Yang disembunyikan dari keduanya hanya kolom harga: HPP dan
 * nilai persediaan tetap urusan MANAGER ke atas.
 *
 * Gudang WAJIB ikut terhitung di sini (Addendum 59.4) — mengecualikannya akan
 * menghilangkan nilai persediaan dari pembukuan.
 */
class InventoryReportController extends ReportController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = $this->lokasiAktif($user);
        $bolehLihatHarga = in_array($user->role, self::PERAN_UANG, true);
        $cari = trim($request->string('q')->toString());

        $rows = $this->saldo($user, $branch, $cari, $bolehLihatHarga);

        return Inertia::render('laporan/persediaan', [
            'rows' => $rows,
            'summary' => $this->ringkasan($user, $branch, $bolehLihatHarga),
            'filters' => ['q' => $cari],
            'canSeeCost' => $bolehLihatHarga,
            'branch' => $this->metaLokasi($branch),
        ]);
    }

    /**
     * Produk yang pernah punya saldo di lokasi ini.
     *
     * Produk tanpa baris `stock_balances` sengaja tidak ditampilkan: ia belum
     * pernah bergerak sama sekali di lokasi ini, jadi barisnya hanya akan
     * berisi nol dan menenggelamkan yang benar-benar dipakai.
     */
    private function saldo(User $user, ?Branch $branch, string $cari, bool $bolehLihatHarga): LengthAwarePaginator
    {
        return DB::table('stock_balances')
            ->join('products', 'products.product_id', '=', 'stock_balances.product_id')
            ->leftJoin('product_categories', 'product_categories.category_id', '=', 'products.category_id')
            ->leftJoin('product_uoms', function ($join) {
                $join->on('product_uoms.product_id', '=', 'products.product_id')->where('product_uoms.uom_type', '=', 'BASE');
            })
            ->where('products.owner_id', $user->owner_id)
            ->when($branch, fn ($q) => $q->where('stock_balances.branch_id', $branch->branch_id))
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('products.product_name', 'like', "%{$cari}%")
                ->orWhere('products.sku', 'like', "%{$cari}%")))
            ->orderBy('products.product_name')
            ->select([
                'products.product_name',
                'products.sku',
                'product_categories.category_name',
                'product_uoms.uom_name',
                'stock_balances.qty_on_hand',
                'stock_balances.avg_cost',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn ($row) => [
                'name' => $row->product_name,
                'sku' => $row->sku,
                'category' => $row->category_name ?? '—',
                'uom' => $row->uom_name ?? '—',
                'qty' => round((float) $row->qty_on_hand, 4),
                'avgCost' => $bolehLihatHarga ? round((float) $row->avg_cost, 4) : null,
                'value' => $bolehLihatHarga ? round((float) $row->qty_on_hand * (float) $row->avg_cost, 2) : null,
            ]);
    }

    /** @return array<string, mixed> */
    private function ringkasan(User $user, ?Branch $branch, bool $bolehLihatHarga): array
    {
        $row = DB::table('stock_balances')
            ->join('products', 'products.product_id', '=', 'stock_balances.product_id')
            ->where('products.owner_id', $user->owner_id)
            ->when($branch, fn ($q) => $q->where('stock_balances.branch_id', $branch->branch_id))
            ->selectRaw('COUNT(*) jumlah, COALESCE(SUM(stock_balances.qty_on_hand * stock_balances.avg_cost),0) nilai, SUM(stock_balances.qty_on_hand <= 0) habis, SUM(stock_balances.qty_on_hand < 0) negatif')
            ->first();

        return [
            'productCount' => (int) $row->jumlah,
            // Stok negatif menyalahi asas, dan menutup periode dengan saldo
            // negatif diblokir (Addendum 55.3) — jadi ia ditonjolkan di sini.
            'emptyCount' => (int) $row->habis,
            'negativeCount' => (int) $row->negatif,
            'totalValue' => $bolehLihatHarga ? round((float) $row->nilai, 2) : null,
        ];
    }
}

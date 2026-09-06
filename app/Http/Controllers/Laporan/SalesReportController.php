<?php

namespace App\Http\Controllers\Laporan;

use App\Models\FiscalPeriod;
use App\Models\Sale;
use App\Services\FiscalPeriodFigures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laporan penjualan satu periode (PRD 19).
 *
 * Bedanya dengan laba rugi: yang ini membedah penjualannya sendiri — per hari,
 * per produk, per kasir — sedangkan laba rugi berhenti di total. Keduanya
 * memakai ringkasan yang sama dari FiscalPeriodFigures supaya angka atasnya
 * tidak pernah berselisih.
 *
 * Seluruh angka di sini menghitung nota berstatus POSTED saja; nota yang
 * dibatalkan tidak pernah ikut, sedangkan retur ditampilkan terpisah agar
 * terlihat berapa yang kembali, bukan disembunyikan ke dalam total.
 */
class SalesReportController extends ReportController
{
    private const BATAS_PRODUK = 15;

    public function __construct(private FiscalPeriodFigures $figures) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizeUang($request, 'laporan penjualan');
        $branch = $this->lokasiAktif($user);
        $daftar = $this->daftarPeriode($user, $branch);
        $period = $this->periodePilihan($request, $user, $branch);

        return Inertia::render('laporan/penjualan', [
            'periods' => $this->pilihanPeriode($daftar),
            'period' => $this->metaPeriode($period),
            'summary' => $period === null ? null : $this->ringkasan($period),
            'daily' => $period === null ? [] : $this->perHari($period),
            'products' => $period === null ? [] : $this->perProduk($period),
            'cashiers' => $period === null ? [] : $this->perKasir($period),
            'branch' => $this->metaLokasi($branch),
        ]);
    }

    /** @return array<string, mixed> */
    private function ringkasan(FiscalPeriod $period): array
    {
        $angka = $this->figures->penjualan($period);
        $persediaan = $this->figures->persediaan($period, $this->figures->pembulatanHpp($period)['per_produk']);
        $cogs = round($persediaan['sales_cost_value'] - $persediaan['sales_return_cost_value'], 2);

        return [
            'saleCount' => $angka['sale_count'],
            'grossSales' => $angka['gross_sales'],
            'discountTotal' => $angka['discount_total'],
            'taxTotal' => $angka['tax_total'],
            'salesReturnTotal' => $angka['sales_return_total'],
            'netSales' => $angka['net_sales'],
            'paymentRoundingTotal' => $angka['payment_rounding_total'],
            'cogs' => $cogs,
            'grossProfit' => round($angka['net_sales'] - $cogs, 2),
            'payments' => [
                'CASH' => $angka['payment_cash'],
                'QRIS' => $angka['payment_qris'],
                'DEBIT' => $angka['payment_debit'],
                'CREDIT_CARD' => $angka['payment_credit_card'],
                'TRANSFER' => $angka['payment_transfer'],
                'E_WALLET' => $angka['payment_ewallet'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function perHari(FiscalPeriod $period): array
    {
        return DB::table('sales')
            ->leftJoin('sale_items', 'sale_items.sale_id', '=', 'sales.sale_id')
            ->where('sales.fiscal_period_id', $period->fiscal_period_id)
            ->where('sales.status', Sale::STATUS_POSTED)
            ->groupBy('sales.transaction_date')
            ->orderBy('sales.transaction_date')
            ->selectRaw('sales.transaction_date AS tanggal, COUNT(DISTINCT sales.sale_id) AS nota, COALESCE(SUM(sale_items.line_total),0) AS omzet, COALESCE(SUM(sale_items.cogs_total),0) AS hpp')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->tanggal,
                'saleCount' => (int) $row->nota,
                'netSales' => round((float) $row->omzet, 2),
                'cogs' => round((float) $row->hpp, 2),
                'grossProfit' => round((float) $row->omzet - (float) $row->hpp, 2),
            ])
            ->all();
    }

    /**
     * Produk terlaris menurut nilai penjualan.
     *
     * Qty dijumlahkan dalam SATUAN DASAR — menjumlahkan "3 dus" dengan "5 pcs"
     * menghasilkan angka yang tidak berarti apa-apa (Addendum 39.7).
     *
     * @return array<int, array<string, mixed>>
     */
    private function perProduk(FiscalPeriod $period): array
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.sale_id', '=', 'sale_items.sale_id')
            ->join('products', 'products.product_id', '=', 'sale_items.product_id')
            ->leftJoin('product_uoms', function ($join) {
                $join->on('product_uoms.product_id', '=', 'products.product_id')->where('product_uoms.uom_type', '=', 'BASE');
            })
            ->where('sales.fiscal_period_id', $period->fiscal_period_id)
            ->where('sales.status', Sale::STATUS_POSTED)
            ->groupBy('products.product_id', 'products.product_name', 'products.sku', 'product_uoms.uom_name')
            ->orderByDesc(DB::raw('SUM(sale_items.line_total)'))
            ->limit(self::BATAS_PRODUK)
            ->selectRaw('products.product_name AS nama, products.sku, product_uoms.uom_name AS satuan, SUM(sale_items.qty_base) AS qty, SUM(sale_items.line_total) AS omzet, SUM(sale_items.cogs_total) AS hpp')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->nama,
                'sku' => $row->sku,
                'uom' => $row->satuan ?? '—',
                'qty' => round((float) $row->qty, 4),
                'netSales' => round((float) $row->omzet, 2),
                'cogs' => round((float) $row->hpp, 2),
                'grossProfit' => round((float) $row->omzet - (float) $row->hpp, 2),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function perKasir(FiscalPeriod $period): array
    {
        return DB::table('sales')
            ->leftJoin('users', 'users.user_id', '=', 'sales.created_by')
            ->where('sales.fiscal_period_id', $period->fiscal_period_id)
            ->where('sales.status', Sale::STATUS_POSTED)
            ->groupBy('users.user_id', 'users.full_name')
            ->orderByDesc(DB::raw('SUM(sales.net_total)'))
            ->selectRaw('users.full_name AS nama, COUNT(*) AS nota, SUM(sales.net_total) AS omzet')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->nama ?? 'Tanpa pengguna',
                'saleCount' => (int) $row->nota,
                'netSales' => round((float) $row->omzet, 2),
            ])
            ->all();
    }
}

<?php

namespace App\Http\Controllers\Laporan;

use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\Payable;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laporan pembelian satu periode (Addendum 58).
 *
 * Penerimaan dan faktur dilaporkan TERPISAH, bukan digabung jadi satu angka
 * "pembelian". Keduanya memang dua dokumen berbeda: yang menambah stok adalah
 * penerimaan, yang menerbitkan utang adalah faktur, dan jarak waktu di antara
 * keduanya justru hal yang perlu terlihat — penerimaan yang lama tidak
 * difakturkan berarti nilai persediaannya masih memakai harga sementara.
 */
class PurchaseReportController extends ReportController
{
    public function __construct() {}

    public function index(Request $request): Response
    {
        $user = $this->authorizeUang($request, 'laporan pembelian');
        $branch = $this->lokasiAktif($user);
        $daftar = $this->daftarPeriode($user, $branch);
        $period = $this->periodePilihan($request, $user, $branch);

        return Inertia::render('laporan/pembelian', [
            'periods' => $this->pilihanPeriode($daftar),
            'period' => $this->metaPeriode($period),
            'summary' => $period === null ? null : $this->ringkasan($period),
            'suppliers' => $period === null ? [] : $this->perPemasok($period),
            'uninvoiced' => $period === null ? [] : $this->belumDifakturkan($period),
            'branch' => $this->metaLokasi($branch),
        ]);
    }

    /** @return array<string, mixed> */
    private function ringkasan(FiscalPeriod $period): array
    {
        $penerimaan = GoodsReceipt::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', GoodsReceipt::STATUS_POSTED)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total_cost),0) v')
            ->first();

        $faktur = Purchase::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', Purchase::STATUS_POSTED)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) v')
            ->first();

        $retur = PurchaseReturn::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', PurchaseReturn::STATUS_POSTED)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(refund_total),0) v')
            ->first();

        $belum = GoodsReceipt::where('fiscal_period_id', $period->fiscal_period_id)
            ->uninvoiced()
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total_cost),0) v')
            ->first();

        $utang = Payable::whereIn('branch_id', $period->branchIds())
            ->whereIn('status', [Payable::STATUS_OPEN, Payable::STATUS_PARTIAL])
            ->selectRaw('COUNT(*) c, COALESCE(SUM(amount - paid_amount),0) v')
            ->first();

        return [
            'receiptCount' => (int) $penerimaan->c,
            'receiptValue' => round((float) $penerimaan->v, 2),
            'invoiceCount' => (int) $faktur->c,
            'invoiceValue' => round((float) $faktur->v, 2),
            'returnCount' => (int) $retur->c,
            'returnValue' => round((float) $retur->v, 2),
            'uninvoicedCount' => (int) $belum->c,
            'uninvoicedValue' => round((float) $belum->v, 2),
            'payableCount' => (int) $utang->c,
            'payableOutstanding' => round((float) $utang->v, 2),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function perPemasok(FiscalPeriod $period): array
    {
        $penerimaan = DB::table('goods_receipts')
            ->join('suppliers', 'suppliers.supplier_id', '=', 'goods_receipts.supplier_id')
            ->where('goods_receipts.fiscal_period_id', $period->fiscal_period_id)
            ->where('goods_receipts.status', GoodsReceipt::STATUS_POSTED)
            ->groupBy('suppliers.supplier_id', 'suppliers.supplier_name')
            ->selectRaw('suppliers.supplier_id, suppliers.supplier_name AS nama, COUNT(*) AS jumlah, SUM(goods_receipts.total_cost) AS nilai')
            ->get()
            ->keyBy('supplier_id');

        $retur = DB::table('purchase_returns')
            ->join('goods_receipts', 'goods_receipts.goods_receipt_id', '=', 'purchase_returns.goods_receipt_id')
            ->where('purchase_returns.fiscal_period_id', $period->fiscal_period_id)
            ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
            ->groupBy('goods_receipts.supplier_id')
            ->selectRaw('goods_receipts.supplier_id, SUM(purchase_returns.refund_total) AS nilai')
            ->pluck('nilai', 'supplier_id');

        $utang = DB::table('payables')
            ->whereIn('payables.branch_id', $period->branchIds())
            ->whereIn('payables.status', [Payable::STATUS_OPEN, Payable::STATUS_PARTIAL])
            ->groupBy('payables.supplier_id')
            ->selectRaw('payables.supplier_id, SUM(payables.amount - payables.paid_amount) AS sisa')
            ->pluck('sisa', 'supplier_id');

        return $penerimaan
            ->map(fn ($row) => [
                'name' => $row->nama,
                'receiptCount' => (int) $row->jumlah,
                'receiptValue' => round((float) $row->nilai, 2),
                'returnValue' => round((float) ($retur[$row->supplier_id] ?? 0), 2),
                'outstanding' => round((float) ($utang[$row->supplier_id] ?? 0), 2),
            ])
            ->sortByDesc('receiptValue')
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function belumDifakturkan(FiscalPeriod $period): array
    {
        return GoodsReceipt::where('fiscal_period_id', $period->fiscal_period_id)
            ->uninvoiced()
            ->with('supplier')
            ->orderBy('receipt_date')
            ->get()
            ->map(fn (GoodsReceipt $r) => [
                'number' => $r->receipt_number,
                'date' => $r->receipt_date->toDateString(),
                'supplier' => $r->supplier?->supplier_name,
                'value' => round((float) $r->total_cost, 2),
            ])
            ->all();
    }
}

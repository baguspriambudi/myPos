<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashDifference;
use App\Models\CashierShift;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\Owner;
use App\Models\Sale;
use App\Models\User;
use App\Support\ActiveBranch;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ringkasan hari ini untuk lokasi yang sedang dilihat.
 *
 * Angka uang hanya untuk MANAGER ke atas (Addendum 50). KASIR dan GUDANG tetap
 * mendapat halaman ini — mereka butuh tahu periode berjalan, shift, dan stok
 * yang habis — tetapi tanpa omzet, HPP, dan laba.
 *
 * Semua angka dibatasi lokasi aktif. Dashboard yang diam-diam menjumlahkan
 * seluruh cabang akan membuat manajer toko mengira tokonya jauh lebih ramai
 * daripada kenyataannya.
 */
class DashboardController extends Controller
{
    private const PERAN_UANG = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const HARI_TREN = 14;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);
        $bolehLihatUang = in_array($user->role, self::PERAN_UANG, true);
        $hariIni = CarbonImmutable::today();

        $period = $this->periodeBerjalan($user, $branch, $hariIni);

        return Inertia::render('dashboard', [
            'branch' => $branch === null ? null : [
                'id' => $branch->branch_id,
                'name' => $branch->branch_name,
                'type' => $branch->typeLabel(),
                'canSell' => (bool) $branch->can_sell,
            ],
            'owner' => $this->pengaturanUsaha($user),
            'period' => $this->ringkasanPeriode($period, $hariIni),
            'today' => $branch === null ? null : $this->hariIni($branch, $hariIni, $bolehLihatUang),
            'trend' => $branch === null || ! $bolehLihatUang ? [] : $this->tren($branch, $hariIni),
            'paymentMix' => $branch === null || ! $bolehLihatUang ? [] : $this->bauranPembayaran($branch, $hariIni),
            'recentSales' => $branch === null ? [] : $this->notaTerakhir($branch, $bolehLihatUang),
            'topProducts' => $branch === null || ! $bolehLihatUang ? [] : $this->produkTerlaris($branch, $hariIni),
            'emptyStock' => $branch === null ? [] : $this->stokHabis($branch),
            'attention' => $branch === null ? null : $this->perluPerhatian($branch, $period),
            'canSeeMoney' => $bolehLihatUang,
            'todayLabel' => $hariIni->translatedFormat('l, j F Y'),
        ]);
    }

    /** @return array<string, mixed> */
    private function pengaturanUsaha(User $user): array
    {
        $owner = Owner::find($user->owner_id);

        return [
            'name' => $owner?->owner_name,
            'costingMethod' => $owner?->costing_method,
            'roundingUnit' => (int) ($owner?->payment_rounding_unit ?? 0),
            'roundingMode' => $owner?->payment_rounding_mode,
        ];
    }

    private function periodeBerjalan(User $user, ?Branch $branch, CarbonImmutable $hariIni): ?FiscalPeriod
    {
        if ($branch === null) {
            return null;
        }

        // Sengaja tidak memakai FiscalPeriodResolver: resolver melempar
        // pengecualian bila tanggalnya tidak punya periode, dan dashboard harus
        // tetap terbuka justru pada saat itu — di situlah pemiliknya diberi
        // tahu bahwa periodenya belum dibuat.
        return FiscalPeriod::where('owner_id', $user->owner_id)
            ->where(fn ($q) => $q->where('branch_id', $branch->branch_id)->orWhereNull('branch_id'))
            ->whereDate('start_date', '<=', $hariIni->toDateString())
            ->whereDate('end_date', '>=', $hariIni->toDateString())
            ->orderByRaw('branch_id IS NULL')
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function ringkasanPeriode(?FiscalPeriod $period, CarbonImmutable $hariIni): ?array
    {
        if ($period === null) {
            return null;
        }

        $total = $period->start_date->diffInDays($period->end_date) + 1;
        $berjalan = min(max($period->start_date->diffInDays($hariIni) + 1, 0), $total);

        return [
            'code' => $period->period_code,
            'name' => $period->period_name,
            'status' => $period->status,
            'day' => $berjalan,
            'totalDays' => $total,
            'menerimaTransaksi' => $period->menerimaTransaksi(),
        ];
    }

    /**
     * Angka hari ini beserta pembandingnya kemarin.
     *
     * @return array<string, mixed>
     */
    private function hariIni(Branch $branch, CarbonImmutable $hariIni, bool $bolehLihatUang): array
    {
        $hari = fn (string $tanggal) => DB::table('sales')
            ->leftJoin('sale_items', 'sale_items.sale_id', '=', 'sales.sale_id')
            ->where('sales.branch_id', $branch->branch_id)
            ->where('sales.status', Sale::STATUS_POSTED)
            ->whereDate('sales.transaction_date', $tanggal)
            ->selectRaw('COUNT(DISTINCT sales.sale_id) nota, COALESCE(SUM(sale_items.line_total),0) omzet, COALESCE(SUM(sale_items.cogs_total),0) hpp')
            ->first();

        $ini = $hari($hariIni->toDateString());
        $lalu = $hari($hariIni->subDay()->toDateString());

        $omzet = round((float) $ini->omzet, 2);
        $hpp = round((float) $ini->hpp, 2);
        $omzetLalu = round((float) $lalu->omzet, 2);
        $hppLalu = round((float) $lalu->hpp, 2);

        return [
            'saleCount' => (int) $ini->nota,
            'saleCountDelta' => $this->delta((float) $ini->nota, (float) $lalu->nota),
            'omzet' => $bolehLihatUang ? $omzet : null,
            'omzetDelta' => $bolehLihatUang ? $this->delta($omzet, $omzetLalu) : null,
            'cogs' => $bolehLihatUang ? $hpp : null,
            'cogsDelta' => $bolehLihatUang ? $this->delta($hpp, $hppLalu) : null,
            'grossProfit' => $bolehLihatUang ? round($omzet - $hpp, 2) : null,
            'grossProfitDelta' => $bolehLihatUang ? $this->delta($omzet - $hpp, $omzetLalu - $hppLalu) : null,
        ];
    }

    /** Perubahan dalam persen; null bila pembandingnya nol — bukan "naik 100%". */
    private function delta(float $sekarang, float $sebelum): ?float
    {
        if ($sebelum == 0.0) {
            return null;
        }

        return round(($sekarang - $sebelum) / abs($sebelum) * 100, 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function tren(Branch $branch, CarbonImmutable $hariIni): array
    {
        $mulai = $hariIni->subDays(self::HARI_TREN - 1);

        $rows = DB::table('sales')
            ->leftJoin('sale_items', 'sale_items.sale_id', '=', 'sales.sale_id')
            ->where('sales.branch_id', $branch->branch_id)
            ->where('sales.status', Sale::STATUS_POSTED)
            ->whereBetween('sales.transaction_date', [$mulai->toDateString(), $hariIni->toDateString()])
            ->groupBy('sales.transaction_date')
            ->selectRaw('sales.transaction_date tanggal, COALESCE(SUM(sale_items.line_total),0) omzet, COALESCE(SUM(sale_items.cogs_total),0) hpp')
            ->get()
            ->keyBy(fn ($row) => (string) $row->tanggal);

        $hasil = [];

        // Hari tanpa transaksi tetap ditulis sebagai nol supaya grafiknya
        // menunjukkan hari sepi, bukan memampatkan sumbu waktunya.
        for ($i = 0; $i < self::HARI_TREN; $i++) {
            $tanggal = $mulai->addDays($i)->toDateString();
            $row = $rows->get($tanggal);

            $hasil[] = [
                'date' => $tanggal,
                'omzet' => round((float) ($row->omzet ?? 0), 2),
                'hpp' => round((float) ($row->hpp ?? 0), 2),
            ];
        }

        return $hasil;
    }

    /** @return array<int, array<string, mixed>> */
    private function bauranPembayaran(Branch $branch, CarbonImmutable $hariIni): array
    {
        $label = [
            'CASH' => 'Tunai', 'QRIS' => 'QRIS', 'DEBIT' => 'Debit',
            'CREDIT_CARD' => 'Kartu Kredit', 'TRANSFER' => 'Transfer', 'E_WALLET' => 'E-Wallet',
        ];

        return DB::table('payments')
            ->join('sale_payments', 'sale_payments.payment_id', '=', 'payments.payment_id')
            ->where('payments.branch_id', $branch->branch_id)
            ->where('payments.status', 'POSTED')
            ->whereDate('payments.transaction_date', $hariIni->toDateString())
            ->groupBy('payments.payment_type')
            ->selectRaw('payments.payment_type jenis, SUM(sale_payments.amount) jumlah')
            ->get()
            ->map(fn ($row) => [
                'label' => $label[$row->jenis] ?? $row->jenis,
                'amount' => round((float) $row->jumlah, 2),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function notaTerakhir(Branch $branch, bool $bolehLihatUang): array
    {
        return Sale::where('branch_id', $branch->branch_id)
            ->with('cashier')
            ->orderByDesc('sale_id')
            ->limit(6)
            ->get()
            ->map(fn (Sale $s) => [
                'id' => $s->sale_id,
                'number' => $s->sale_number,
                'date' => $s->transaction_date->toDateString(),
                'cashier' => $s->cashier?->full_name,
                'status' => $s->status,
                'paidTotal' => $bolehLihatUang ? round((float) $s->paid_total, 2) : null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function produkTerlaris(Branch $branch, CarbonImmutable $hariIni): array
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.sale_id', '=', 'sale_items.sale_id')
            ->join('products', 'products.product_id', '=', 'sale_items.product_id')
            ->leftJoin('product_uoms', function ($join) {
                $join->on('product_uoms.product_id', '=', 'products.product_id')->where('product_uoms.uom_type', '=', 'BASE');
            })
            ->where('sales.branch_id', $branch->branch_id)
            ->where('sales.status', Sale::STATUS_POSTED)
            ->whereDate('sales.transaction_date', $hariIni->toDateString())
            ->groupBy('products.product_id', 'products.product_name', 'product_uoms.uom_name')
            ->orderByDesc(DB::raw('SUM(sale_items.line_total)'))
            ->limit(5)
            ->selectRaw('products.product_name nama, product_uoms.uom_name satuan, SUM(sale_items.qty_base) qty, SUM(sale_items.line_total) omzet')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->nama,
                'uom' => $row->satuan ?? '—',
                'qty' => round((float) $row->qty, 4),
                'omzet' => round((float) $row->omzet, 2),
            ])
            ->all();
    }

    /**
     * Produk yang saldonya habis atau negatif.
     *
     * Belum ada kolom stok minimum di master produk, jadi yang bisa dilaporkan
     * jujur hanyalah "sudah habis" — bukan "menipis".
     *
     * @return array<int, array<string, mixed>>
     */
    private function stokHabis(Branch $branch): array
    {
        return DB::table('stock_balances')
            ->join('products', 'products.product_id', '=', 'stock_balances.product_id')
            ->leftJoin('product_uoms', function ($join) {
                $join->on('product_uoms.product_id', '=', 'products.product_id')->where('product_uoms.uom_type', '=', 'BASE');
            })
            ->where('stock_balances.branch_id', $branch->branch_id)
            ->where('products.active', true)
            ->where('stock_balances.qty_on_hand', '<=', 0)
            ->orderBy('stock_balances.qty_on_hand')
            ->limit(8)
            ->selectRaw('products.product_name nama, product_uoms.uom_name satuan, stock_balances.qty_on_hand qty')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->nama,
                'uom' => $row->satuan ?? '—',
                'qty' => round((float) $row->qty, 4),
            ])
            ->all();
    }

    /**
     * Hal-hal yang menggantung dan akan menghalangi penutupan periode.
     *
     * @return array<string, int>
     */
    private function perluPerhatian(Branch $branch, ?FiscalPeriod $period): array
    {
        return [
            'openShifts' => CashierShift::where('branch_id', $branch->branch_id)
                ->where('status', CashierShift::STATUS_OPEN)
                ->count(),
            'cashDifferences' => CashDifference::where('branch_id', $branch->branch_id)
                ->where('status', CashDifference::STATUS_OPEN)
                ->count(),
            'uninvoicedReceipts' => GoodsReceipt::where('branch_id', $branch->branch_id)
                ->uninvoiced()
                ->count(),
            'negativeStock' => DB::table('stock_balances')
                ->where('branch_id', $branch->branch_id)
                ->where('qty_on_hand', '<', 0)
                ->count(),
            'periodMissing' => $period === null ? 1 : 0,
        ];
    }
}

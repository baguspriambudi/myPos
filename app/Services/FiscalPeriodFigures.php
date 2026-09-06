<?php

namespace App\Services;

use App\Models\CashDifference;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\FiscalClosing;
use App\Models\FiscalPeriod;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Angka sebuah fiscal period, dihitung dari buku besar (PRD 17-21).
 *
 * Kelas ini sengaja dipakai bersama oleh dua pihak yang berbeda kebutuhan:
 * proses penutupan (yang membekukan angkanya jadi snapshot) dan layar laporan
 * (yang menampilkan angka berjalan). Kalau keduanya menghitung sendiri-sendiri,
 * cepat atau lambat laporan "sebelum tutup" dan angka "sesudah tutup" akan
 * berbeda tanpa ada yang salah ketik — cuma karena dua rumus yang menua sendiri.
 *
 * Semuanya diturunkan dari buku besar (`stock_movements`, `cash_transactions`,
 * `sales`), bukan dari saldo berjalan seperti `stock_balances`: saldo berjalan
 * hanya tahu keadaan hari ini, sedangkan period harus tahu keadaan pada
 * akhirnya — dan keduanya berbeda begitu ada transaksi yang dicatat mundur.
 */
class FiscalPeriodFigures
{
    /** @var array<int, array<int, array<string, float>>> mutasi per period, agar satu request tidak menyapu movement berkali-kali */
    private array $cacheMutasi = [];

    /** Buang hasil hitungan yang tersimpan — dipakai setelah menulis movement baru. */
    public function lupakan(FiscalPeriod $period): void
    {
        unset($this->cacheMutasi[$period->fiscal_period_id]);
    }

    /**
     * Mutasi period per produk, sudah dipilah ke ember masing-masing.
     *
     * Pemilahan memakai pasangan (movement_type, reference_type) supaya
     * pembatalan pulang ke ember asalnya: VOID_REVERSAL sebuah nota mengurangi
     * HPP penjualan, bukan menjadi "penyesuaian" yang tidak berkaitan dengan
     * apa pun.
     *
     * @return array<int, array<string, float>>
     */
    public function mutasiPerProduk(FiscalPeriod $period): array
    {
        if (isset($this->cacheMutasi[$period->fiscal_period_id])) {
            return $this->cacheMutasi[$period->fiscal_period_id];
        }

        $hasil = [];

        StockMovement::where('fiscal_period_id', $period->fiscal_period_id)
            ->select(['product_id', 'movement_type', 'reference_type', 'qty_base', 'total_cost'])
            ->chunk(1000, function ($rows) use (&$hasil) {
                foreach ($rows as $row) {
                    $productId = (int) $row->product_id;
                    $hasil[$productId] ??= $this->emberKosong();

                    $ember = $this->ember($row->movement_type, $row->reference_type);
                    $hasil[$productId][$ember.'_qty'] += (float) $row->qty_base;
                    $hasil[$productId][$ember.'_value'] += (float) $row->total_cost;
                }
            });

        return $this->cacheMutasi[$period->fiscal_period_id] = $hasil;
    }

    /**
     * Ember sebuah pergerakan. VOID_REVERSAL tidak punya ember sendiri: ia
     * selalu pulang ke jenis transaksi yang dibatalkannya.
     */
    public function ember(string $type, ?string $reference): string
    {
        if ($type === StockMovement::TYPE_VOID_REVERSAL) {
            return match ($reference) {
                StockMovement::REF_RECEIPT, 'PURCHASE' => 'purchase',
                StockMovement::REF_PURCHASE_RETURN => 'purchase_return',
                StockMovement::REF_SALES => 'sales',
                StockMovement::REF_SALES_RETURN => 'sales_return',
                default => 'adjustment',
            };
        }

        return match ($type) {
            StockMovement::TYPE_OPENING => 'opening',
            StockMovement::TYPE_PURCHASE => 'purchase',
            StockMovement::TYPE_RETURN_OUT => 'purchase_return',
            StockMovement::TYPE_SALES => 'sales',
            StockMovement::TYPE_RETURN_IN => 'sales_return',
            default => 'adjustment',
        };
    }

    /** @return array<string, float> */
    private function emberKosong(): array
    {
        return [
            'opening_qty' => 0.0, 'opening_value' => 0.0,
            'purchase_qty' => 0.0, 'purchase_value' => 0.0,
            'purchase_return_qty' => 0.0, 'purchase_return_value' => 0.0,
            'sales_qty' => 0.0, 'sales_value' => 0.0,
            'sales_return_qty' => 0.0, 'sales_return_value' => 0.0,
            'adjustment_qty' => 0.0, 'adjustment_value' => 0.0,
        ];
    }

    /**
     * Saldo awal per produk: warisan snapshot period sebelumnya.
     *
     * Period pertama tidak punya pendahulu; saldo awalnya sudah berupa movement
     * OPENING di dalam period ini sendiri (Addendum 40), jadi tidak ditambahkan
     * dua kali di sini.
     *
     * @return array<int, array{qty: float, value: float}>
     */
    public function saldoAwalPerProduk(FiscalPeriod $period): array
    {
        $snapshot = $period->sebelumnya()?->snapshot();

        if ($snapshot === null) {
            return [];
        }

        $hasil = [];

        foreach ($snapshot->items as $item) {
            $hasil[(int) $item->product_id] = [
                'qty' => (float) $item->ending_qty,
                'value' => (float) $item->ending_value,
            ];
        }

        return $hasil;
    }

    /**
     * Selisih pembulatan HPP (Addendum 51.3).
     *
     * COGS tiap baris nota sudah dibulatkan ke rupiah saat transaksi, sedangkan
     * nilai persediaan yang benar-benar keluar berpresisi empat desimal.
     * Selisihnya tidak boleh diperbaiki dengan mengubah struk yang sudah
     * dicetak, jadi diakui sekali di akhir period.
     *
     * Dikelompokkan per produk untuk snapshot, dan per produk+lokasi untuk
     * movement ROUNDING-nya — pergerakan stok selalu terjadi di satu lokasi
     * tertentu, bahkan ketika period-nya berlaku seluruh lokasi.
     *
     * @return array{per_produk: array<int, float>, per_lokasi: array<int, array<string, mixed>>}
     */
    public function pembulatanHpp(FiscalPeriod $period): array
    {
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.sale_id', '=', 'sale_items.sale_id')
            ->where('sales.fiscal_period_id', $period->fiscal_period_id)
            ->where('sales.status', Sale::STATUS_POSTED)
            ->groupBy('sale_items.product_id', 'sales.branch_id')
            ->selectRaw('sale_items.product_id, sales.branch_id, SUM(sale_items.qty_base * sale_items.cost_at_sale) AS tepat, SUM(sale_items.cogs_total) AS tercatat')
            ->get();

        $perProduk = [];
        $perLokasi = [];

        foreach ($rows as $row) {
            $selisih = round((float) $row->tepat - (float) $row->tercatat, 2);

            if ($selisih === 0.0) {
                continue;
            }

            $productId = (int) $row->product_id;
            $perProduk[$productId] = round(($perProduk[$productId] ?? 0.0) + $selisih, 2);
            $perLokasi[] = ['product_id' => $productId, 'branch_id' => (int) $row->branch_id, 'selisih' => $selisih];
        }

        return ['per_produk' => $perProduk, 'per_lokasi' => $perLokasi];
    }

    /**
     * Rincian per produk: saldo awal, mutasi period, saldo akhir.
     *
     * @param  array<int, float>  $pembulatan
     * @return array<int, array<string, mixed>>
     */
    public function rincianProduk(FiscalPeriod $period, array $pembulatan = []): array
    {
        $mutasi = $this->mutasiPerProduk($period);
        $awal = $this->saldoAwalPerProduk($period);

        $items = [];

        foreach (array_unique(array_merge(array_keys($mutasi), array_keys($awal))) as $productId) {
            $m = $mutasi[$productId] ?? $this->emberKosong();
            $a = $awal[$productId] ?? ['qty' => 0.0, 'value' => 0.0];
            $rounding = round($pembulatan[$productId] ?? 0.0, 2);

            $openingQty = round($a['qty'] + $m['opening_qty'], 4);
            $openingValue = round($a['value'] + $m['opening_value'], 2);

            // Pembelian di tingkat produk dicatat BERSIH dari retur pembelian:
            // barisnya hanya punya satu pasang kolom pembelian, sedangkan
            // header snapshot tetap memisahkan keduanya.
            $purchaseQty = round($m['purchase_qty'] + $m['purchase_return_qty'], 4);
            $purchaseValue = round($m['purchase_value'] + $m['purchase_return_value'], 2);

            $salesQty = round(-$m['sales_qty'], 4);
            $cogs = round(-$m['sales_value'], 2);
            $returnQty = round($m['sales_return_qty'], 4);
            $returnValue = round($m['sales_return_value'], 2);

            // Koreksi pembulatan HPP menempel pada nilai penyesuaian: movement
            // ROUNDING yang ditulis proses penutupan memang masuk ember itu,
            // jadi snapshot harus sudah memperhitungkannya sejak sekarang.
            $adjustmentQty = round($m['adjustment_qty'], 4);
            $adjustmentValue = round($m['adjustment_value'] - $rounding, 2);

            $endingQty = round($openingQty + $purchaseQty - $salesQty + $returnQty + $adjustmentQty, 4);
            $endingValue = round($openingValue + $purchaseValue - $cogs + $returnValue + $adjustmentValue, 2);

            $items[] = [
                'product_id' => $productId,
                'opening_qty' => $openingQty,
                'opening_value' => $openingValue,
                'purchase_qty' => $purchaseQty,
                'purchase_value' => $purchaseValue,
                'sales_qty' => $salesQty,
                'cogs' => $cogs,
                'adjustment_qty' => $adjustmentQty,
                'adjustment_value' => $adjustmentValue,
                'return_qty' => $returnQty,
                'return_value' => $returnValue,
                'ending_qty' => $endingQty,
                'ending_value' => $endingValue,
                'average_cost' => $endingQty > 0 ? round($endingValue / $endingQty, 4) : 0,
                'rounding_adjustment' => $rounding,
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, float>  $pembulatan
     * @return array<string, float>
     */
    public function persediaan(FiscalPeriod $period, array $pembulatan = []): array
    {
        $mutasi = $this->mutasiPerProduk($period);
        $awal = $this->saldoAwalPerProduk($period);

        $total = fn (string $key) => round(array_sum(array_map(fn ($m) => $m[$key], $mutasi)), 2);

        $openingWarisan = round(array_sum(array_map(fn ($a) => $a['value'], $awal)), 2);
        $opening = round($openingWarisan + $total('opening_value'), 2);

        $purchase = $total('purchase_value');
        $purchaseReturn = round(-$total('purchase_return_value'), 2);
        $salesCost = round(-$total('sales_value'), 2);
        $salesReturnCost = $total('sales_return_value');
        $adjustment = round($total('adjustment_value') - array_sum($pembulatan), 2);

        return [
            'opening_inventory_value' => $opening,
            'purchase_value' => $purchase,
            'purchase_return_value' => $purchaseReturn,
            'sales_cost_value' => $salesCost,
            'sales_return_cost_value' => $salesReturnCost,
            'adjustment_value' => $adjustment,
            'ending_inventory_value' => round($opening + $purchase - $purchaseReturn - $salesCost + $salesReturnCost + $adjustment, 2),
        ];
    }

    /**
     * Penjualan, pembayaran, dan biaya operasional period ini.
     *
     * `net_sales` dan HPP sama-sama BERSIH dari retur, supaya laba kotor tidak
     * timpang: mengurangi biaya tanpa mengurangi pendapatannya akan membuat
     * period yang banyak retur terlihat lebih untung daripada kenyataannya.
     *
     * @return array<string, float>
     */
    public function penjualan(FiscalPeriod $period): array
    {
        $sales = Sale::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', Sale::STATUS_POSTED)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(gross_total),0) g, COALESCE(SUM(discount_total),0) d, COALESCE(SUM(net_total),0) n, COALESCE(SUM(tax_total),0) t, COALESCE(SUM(rounding_amount),0) r')
            ->first();

        $retur = (float) SalesReturn::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', SalesReturn::STATUS_POSTED)
            ->sum('refund_total');

        $expense = (float) Expense::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', Expense::STATUS_POSTED)
            ->sum('amount');

        return [
            'sale_count' => (int) $sales->c,
            'gross_sales' => round((float) $sales->g, 2),
            'discount_total' => round((float) $sales->d, 2),
            'sales_return_total' => round($retur, 2),
            'net_sales' => round((float) $sales->n - $retur, 2),
            'tax_total' => round((float) $sales->t, 2),
            'payment_rounding_total' => round((float) $sales->r, 2),
            'expense_total' => round($expense, 2),
        ] + $this->bauranPembayaran($period);
    }

    /**
     * Bauran metode bayar atas penjualan (PRD 19).
     *
     * Yang dihitung hanya pembayaran yang menempel pada nota — termasuk cicilan
     * piutang, yang memang uang masuk atas penjualan. Pembayaran utang ke
     * pemasok memakai tabel `payments` yang sama tetapi bukan bagian dari
     * bauran ini, jadi disaring lewat pivot `sale_payments`.
     *
     * @return array<string, float>
     */
    public function bauranPembayaran(FiscalPeriod $period): array
    {
        $rows = DB::table('payments')
            ->join('sale_payments', 'sale_payments.payment_id', '=', 'payments.payment_id')
            ->where('payments.fiscal_period_id', $period->fiscal_period_id)
            ->where('payments.status', Payment::STATUS_POSTED)
            ->groupBy('payments.payment_type')
            ->selectRaw('payments.payment_type, SUM(sale_payments.amount) AS jumlah')
            ->pluck('jumlah', 'payment_type');

        return [
            'payment_cash' => round((float) ($rows['CASH'] ?? 0), 2),
            'payment_qris' => round((float) ($rows['QRIS'] ?? 0), 2),
            'payment_debit' => round((float) ($rows['DEBIT'] ?? 0), 2),
            'payment_credit_card' => round((float) ($rows['CREDIT_CARD'] ?? 0), 2),
            'payment_transfer' => round((float) ($rows['TRANSFER'] ?? 0), 2),
            'payment_ewallet' => round((float) ($rows['E_WALLET'] ?? 0), 2),
        ];
    }

    /**
     * Kas period ini.
     *
     * Baris OPENING_CASH sengaja TIDAK ikut dijumlah: itu uang laci yang
     * digulirkan dari shift sebelumnya, bukan uang yang baru masuk —
     * menjumlahkannya akan menghitung uang yang sama berkali-kali, sekali per
     * shift. Saldo pembuka diambil dari sumber yang benar: snapshot period
     * sebelumnya, atau saldo awal yang diketik saat Initial Opening.
     *
     * @return array<string, float>
     */
    public function kas(FiscalPeriod $period): array
    {
        $rows = CashTransaction::where('fiscal_period_id', $period->fiscal_period_id)
            ->groupBy('txn_type')
            ->selectRaw('txn_type, SUM(amount) AS jumlah')
            ->pluck('jumlah', 'txn_type');

        $opening = $this->saldoKasAwal($period);
        $cashSales = round((float) ($rows[CashTransaction::TYPE_CASH_SALE] ?? 0), 2);
        $cashIn = round((float) ($rows[CashTransaction::TYPE_CASH_IN] ?? 0), 2);
        $cashOut = round(-(float) ($rows[CashTransaction::TYPE_CASH_OUT] ?? 0), 2);
        $refund = round(-(float) ($rows[CashTransaction::TYPE_REFUND] ?? 0), 2);

        return [
            'opening_cash' => $opening,
            'cash_sales' => $cashSales,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'refund' => $refund,
            'ending_cash' => round($opening + $cashSales + $cashIn - $cashOut - $refund, 2),
            'cash_difference' => round((float) CashDifference::where('fiscal_period_id', $period->fiscal_period_id)->sum('difference'), 2),
        ];
    }

    public function saldoKasAwal(FiscalPeriod $period): float
    {
        $snapshot = $period->sebelumnya()?->snapshot();

        if ($snapshot !== null) {
            return round((float) $snapshot->ending_cash, 2);
        }

        return round((float) $period->openingBalances()
            ->where('balance_type', OpeningBalance::TYPE_CASH)
            ->sum('amount'), 2);
    }

    /**
     * Laba rugi satu period, siap ditampilkan.
     *
     * Untuk period yang sudah ditutup, angkanya diambil dari SNAPSHOT, bukan
     * dihitung ulang — itulah gunanya menutup period. Kalau laporan tetap
     * menghitung ulang, angka period lama bisa berubah diam-diam setiap kali
     * ada transaksi mundur yang lolos, dan penutupan kehilangan artinya.
     *
     * @return array<string, mixed>
     */
    public function labaRugi(FiscalPeriod $period): array
    {
        $snapshot = $period->snapshot();

        return $snapshot !== null
            ? $this->dariSnapshot($period, $snapshot)
            : $this->berjalan($period);
    }

    /** @return array<string, mixed> */
    private function dariSnapshot(FiscalPeriod $period, FiscalClosing $s): array
    {
        return [
            'source' => 'snapshot',
            'version' => $s->version,
            'closedAt' => $s->closed_at?->toDateString(),
            'saleCount' => Sale::where('fiscal_period_id', $period->fiscal_period_id)->where('status', Sale::STATUS_POSTED)->count(),
            'grossSales' => (float) $s->gross_sales,
            'discountTotal' => (float) $s->discount_total,
            'salesReturnTotal' => round((float) SalesReturn::where('fiscal_period_id', $period->fiscal_period_id)->where('status', SalesReturn::STATUS_POSTED)->sum('refund_total'), 2),
            'netSales' => (float) $s->net_sales,
            'taxTotal' => (float) $s->tax_total,
            'paymentRoundingTotal' => (float) $s->payment_rounding_total,
            'cogs' => (float) $s->cogs_value,
            'grossProfit' => (float) $s->gross_profit,
            'expenseTotal' => (float) $s->expense_total,
            'netProfit' => (float) $s->net_profit,
            'payments' => [
                'CASH' => (float) $s->payment_cash,
                'QRIS' => (float) $s->payment_qris,
                'DEBIT' => (float) $s->payment_debit,
                'CREDIT_CARD' => (float) $s->payment_credit_card,
                'TRANSFER' => (float) $s->payment_transfer,
                'E_WALLET' => (float) $s->payment_ewallet,
            ],
            'inventory' => [
                'opening' => (float) $s->opening_inventory_value,
                'purchase' => (float) $s->purchase_value,
                'purchaseReturn' => (float) $s->purchase_return_value,
                'salesCost' => (float) $s->sales_cost_value,
                'salesReturnCost' => (float) $s->sales_return_cost_value,
                'adjustment' => (float) $s->adjustment_value,
                'ending' => (float) $s->ending_inventory_value,
            ],
            'cash' => [
                'opening' => (float) $s->opening_cash,
                'sales' => (float) $s->cash_sales,
                'in' => (float) $s->cash_in,
                'out' => (float) $s->cash_out,
                'refund' => (float) $s->refund,
                'ending' => (float) $s->ending_cash,
                'difference' => (float) $s->cash_difference,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function berjalan(FiscalPeriod $period): array
    {
        $pembulatan = $this->pembulatanHpp($period)['per_produk'];
        $persediaan = $this->persediaan($period, $pembulatan);
        $penjualan = $this->penjualan($period);
        $kas = $this->kas($period);

        $cogs = round($persediaan['sales_cost_value'] - $persediaan['sales_return_cost_value'], 2);
        $grossProfit = round($penjualan['net_sales'] - $cogs, 2);

        return [
            'source' => 'berjalan',
            'version' => null,
            'closedAt' => null,
            'saleCount' => $penjualan['sale_count'],
            'grossSales' => $penjualan['gross_sales'],
            'discountTotal' => $penjualan['discount_total'],
            'salesReturnTotal' => $penjualan['sales_return_total'],
            'netSales' => $penjualan['net_sales'],
            'taxTotal' => $penjualan['tax_total'],
            'paymentRoundingTotal' => $penjualan['payment_rounding_total'],
            'cogs' => $cogs,
            'grossProfit' => $grossProfit,
            'expenseTotal' => $penjualan['expense_total'],
            'netProfit' => round($grossProfit - $penjualan['expense_total'], 2),
            'payments' => [
                'CASH' => $penjualan['payment_cash'],
                'QRIS' => $penjualan['payment_qris'],
                'DEBIT' => $penjualan['payment_debit'],
                'CREDIT_CARD' => $penjualan['payment_credit_card'],
                'TRANSFER' => $penjualan['payment_transfer'],
                'E_WALLET' => $penjualan['payment_ewallet'],
            ],
            'inventory' => [
                'opening' => $persediaan['opening_inventory_value'],
                'purchase' => $persediaan['purchase_value'],
                'purchaseReturn' => $persediaan['purchase_return_value'],
                'salesCost' => $persediaan['sales_cost_value'],
                'salesReturnCost' => $persediaan['sales_return_cost_value'],
                'adjustment' => $persediaan['adjustment_value'],
                'ending' => $persediaan['ending_inventory_value'],
            ],
            'cash' => [
                'opening' => $kas['opening_cash'],
                'sales' => $kas['cash_sales'],
                'in' => $kas['cash_in'],
                'out' => $kas['cash_out'],
                'refund' => $kas['refund'],
                'ending' => $kas['ending_cash'],
                'difference' => $kas['cash_difference'],
            ],
        ];
    }
}

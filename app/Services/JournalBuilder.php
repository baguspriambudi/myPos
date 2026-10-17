<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\FiscalClosing;
use App\Models\FiscalPeriod;
use App\Models\OpeningBalance;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Jurnal dasar per penutupan periode (Addendum 53).
 *
 * Satu journal entry CLOSING per lokasi per penutupan, berisi ringkasan
 * seluruh kejadian periode itu menurut tabel jurnal minimal 53.1 — bukan satu
 * entry per transaksi. Bila periode pertama punya saldo awal, ada satu entry
 * OPENING tambahan. Entry diikat ke snapshot (reference FISCAL_CLOSING), jadi
 * penutupan ulang membentuk entry baru dan yang lama tetap tertaut ke snapshot
 * REPLACED-nya.
 *
 * Setiap angka dibukukan BERPASANGAN (satu debit, satu kredit, nilai sama),
 * sehingga seimbang menurut konstruksinya; keseimbangan per entry tetap
 * divalidasi sebelum disimpan (53.2).
 *
 * Akun dipetakan lewat kode standar di bagan akun owner. Akun yang belum ada
 * dibuat otomatis dengan kode bawaan — usaha yang tidak pernah mengisi bagan
 * akun tetap bisa menutup periode.
 *
 * Batasan yang disengaja:
 *   - Pembayaran yang divoid pada periode SESUDAH periode asalnya ditutup tidak
 *     dibalik di jurnal periode pembatalannya.
 *   - Refund retur penjualan dibukukan ke Kas (atau Piutang bila nota asalnya
 *     kredit); aplikasi belum mencatat cara pengembalian uangnya.
 */
class JournalBuilder
{
    /** Kode, nama, dan jenis akun standar. */
    public const ACCOUNTS = [
        'kas' => ['1-1100', 'Kas', 'ASSET'],
        'bank' => ['1-1200', 'Bank', 'ASSET'],
        'piutang' => ['1-1300', 'Piutang Usaha', 'ASSET'],
        'persediaan' => ['1-1400', 'Persediaan Barang Dagang', 'ASSET'],
        'ppn_masukan' => ['1-1500', 'PPN Masukan', 'ASSET'],
        'piutang_karyawan' => ['1-1600', 'Piutang Karyawan', 'ASSET'],
        'antar_cabang' => ['1-1900', 'Rekening Antar Lokasi', 'ASSET'],
        'utang' => ['2-1100', 'Utang Usaha', 'LIABILITY'],
        'grni' => [ChartOfAccount::CODE_GRNI, 'Utang Belum Ditagih (GRNI)', 'LIABILITY'],
        'ppn_keluaran' => ['2-1300', 'PPN Keluaran', 'LIABILITY'],
        'modal' => ['3-1100', 'Modal Pemilik', 'EQUITY'],
        'penjualan' => ['4-1100', 'Penjualan', 'REVENUE'],
        'retur_penjualan' => ['4-1300', 'Retur Penjualan', 'REVENUE'],
        'pembulatan' => ['4-9100', 'Selisih Pembulatan', 'REVENUE'],
        'pendapatan_lain' => ['4-9200', 'Pendapatan Lain-lain', 'REVENUE'],
        'hpp' => ['5-1100', 'Harga Pokok Penjualan', 'EXPENSE'],
        'selisih_hpp' => ['5-1900', 'Selisih Pembulatan HPP', 'EXPENSE'],
        'beban_lain' => ['6-1900', 'Beban Operasional Lain', 'EXPENSE'],
        'selisih_kas' => ['6-9100', 'Beban Selisih Kas', 'EXPENSE'],
        'selisih_persediaan' => ['6-9300', 'Selisih Persediaan', 'EXPENSE'],
    ];

    /** @var array<string, int> */
    private array $akun = [];

    /** @var array<int, float> account_id => debit - kredit */
    private array $saldo = [];

    /**
     * @return array<int, int> journal_entry_id yang dibentuk
     */
    public function build(FiscalPeriod $period, FiscalClosing $closing, ?User $user): array
    {
        $this->akun = [];
        $ids = [];

        foreach ($this->branches($period) as $branchId) {
            $this->saldo = [];
            $this->pembukaan($period, $branchId);
            $id = $this->simpan($period, $branchId, 'OPENING', $closing, $user);

            if ($id !== null) {
                $ids[] = $id;
            }

            $this->saldo = [];
            $this->penjualan($period, $branchId);
            $this->penagihanPiutang($period, $branchId);
            $this->pembayaranUtang($period, $branchId);
            $this->faktur($period, $branchId);
            $this->biaya($period, $branchId);
            $this->selisihKas($period, $branchId);
            $this->returPenjualan($period, $branchId);
            $this->persediaan($period, $branchId);
            $id = $this->simpan($period, $branchId, 'CLOSING', $closing, $user);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return array<int, int> */
    private function branches(FiscalPeriod $period): array
    {
        if ($period->branch_id !== null) {
            return [$period->branch_id];
        }

        return Branch::where('owner_id', $period->owner_id)->orderBy('branch_id')->pluck('branch_id')->all();
    }

    // ------------------------------------------------------------- kejadian

    private function pembukaan(FiscalPeriod $period, int $branchId): void
    {
        $saldo = OpeningBalance::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('branch_id', $branchId)
            ->get();

        foreach ($saldo as $s) {
            $nilai = (float) $s->amount;

            match ($s->balance_type) {
                OpeningBalance::TYPE_CASH => $this->pasang($period, 'kas', 'modal', $nilai),
                OpeningBalance::TYPE_RECEIVABLE => $this->pasang($period, 'piutang', 'modal', $nilai),
                OpeningBalance::TYPE_PAYABLE => $this->pasang($period, 'modal', 'utang', $nilai),
                // Persediaan awal dibukukan dari movement OPENING-nya, per nilai
                // sebenarnya, bukan dari angka ringkasan ini.
                default => null,
            };
        }

        $opening = (float) StockMovement::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('branch_id', $branchId)
            ->where('movement_type', StockMovement::TYPE_OPENING)
            ->sum('total_cost');

        $this->pasang($period, 'persediaan', 'modal', $opening);
    }

    private function penjualan(FiscalPeriod $period, int $branchId): void
    {
        $sales = DB::table('sales')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.sale_id')
            ->where('sales.fiscal_period_id', $period->fiscal_period_id)
            ->where('sales.branch_id', $branchId)
            ->where('sales.status', 'POSTED')
            ->select('sales.sale_id', 'sales.net_total', 'sales.tax_total', 'sales.rounding_amount', 'receivables.receivable_id')
            ->get();

        // Metode bayar nota non-kredit: pembayaran yang terbit bersamanya.
        $metode = DB::table('sale_payments')
            ->join('payments', 'payments.payment_id', '=', 'sale_payments.payment_id')
            ->whereIn('sale_payments.sale_id', $sales->whereNull('receivable_id')->pluck('sale_id'))
            ->where('payments.status', Payment::STATUS_POSTED)
            ->pluck('payments.payment_type', 'sale_payments.sale_id');

        foreach ($sales as $s) {
            $pendapatan = round((float) $s->net_total - (float) $s->tax_total, 2);

            if ($s->receivable_id !== null) {
                $this->pasang($period, 'piutang', 'penjualan', $pendapatan);
                $this->pasang($period, 'piutang', 'ppn_keluaran', (float) $s->tax_total);

                continue;
            }

            $debit = $this->kasAtauBank($metode[$s->sale_id] ?? Payment::TYPE_CASH);
            $this->pasang($period, $debit, 'penjualan', $pendapatan);
            $this->pasang($period, $debit, 'ppn_keluaran', (float) $s->tax_total);
            // Pembulatan ke atas = pendapatan; ke bawah = beban (53.1).
            $this->pasang($period, $debit, 'pembulatan', (float) $s->rounding_amount);
        }
    }

    private function penagihanPiutang(FiscalPeriod $period, int $branchId): void
    {
        $rows = DB::table('payments')
            ->join('sale_payments', 'sale_payments.payment_id', '=', 'payments.payment_id')
            ->join('receivables', 'receivables.sale_id', '=', 'sale_payments.sale_id')
            ->where('payments.fiscal_period_id', $period->fiscal_period_id)
            ->where('payments.branch_id', $branchId)
            ->where('payments.status', Payment::STATUS_POSTED)
            ->groupBy('payments.payment_type')
            ->selectRaw('payments.payment_type, SUM(sale_payments.amount) AS jumlah')
            ->pluck('jumlah', 'payment_type');

        foreach ($rows as $type => $jumlah) {
            $this->pasang($period, $this->kasAtauBank($type), 'piutang', (float) $jumlah);
        }
    }

    private function pembayaranUtang(FiscalPeriod $period, int $branchId): void
    {
        $rows = DB::table('payments')
            ->join('purchase_payments', 'purchase_payments.payment_id', '=', 'payments.payment_id')
            ->where('payments.fiscal_period_id', $period->fiscal_period_id)
            ->where('payments.branch_id', $branchId)
            ->where('payments.status', Payment::STATUS_POSTED)
            ->groupBy('payments.payment_type')
            ->selectRaw('payments.payment_type, SUM(purchase_payments.amount) AS jumlah')
            ->pluck('jumlah', 'payment_type');

        foreach ($rows as $type => $jumlah) {
            $this->pasang($period, 'utang', $this->kasAtauBank($type), (float) $jumlah);
        }
    }

    private function faktur(FiscalPeriod $period, int $branchId): void
    {
        $f = DB::table('purchases')
            ->where('fiscal_period_id', $period->fiscal_period_id)
            ->where('branch_id', $branchId)
            ->where('status', 'POSTED')
            ->selectRaw('COALESCE(SUM(subtotal), 0) AS subtotal, COALESCE(SUM(tax_total), 0) AS pajak')
            ->first();

        $this->pasang($period, 'grni', 'utang', (float) $f->subtotal);
        $this->pasang($period, 'ppn_masukan', 'utang', (float) $f->pajak);
    }

    private function biaya(FiscalPeriod $period, int $branchId): void
    {
        $rows = DB::table('expenses')
            ->join('expense_categories', 'expense_categories.expense_category_id', '=', 'expenses.expense_category_id')
            ->where('expenses.fiscal_period_id', $period->fiscal_period_id)
            ->where('expenses.branch_id', $branchId)
            ->where('expenses.status', 'POSTED')
            ->groupBy('expense_categories.account_id', 'expenses.payment_type')
            ->selectRaw('expense_categories.account_id, expenses.payment_type, SUM(expenses.amount) AS jumlah')
            ->get();

        foreach ($rows as $r) {
            $debit = $r->account_id !== null ? (int) $r->account_id : 'beban_lain';
            $this->pasang($period, $debit, $this->kasAtauBank($r->payment_type), (float) $r->jumlah);
        }
    }

    private function selisihKas(FiscalPeriod $period, int $branchId): void
    {
        $rows = DB::table('cash_transactions')
            ->join('cash_differences', 'cash_differences.difference_id', '=', 'cash_transactions.reference_id')
            ->where('cash_transactions.reference_type', 'CASH_DIFFERENCE')
            ->where('cash_transactions.fiscal_period_id', $period->fiscal_period_id)
            ->where('cash_transactions.branch_id', $branchId)
            ->select('cash_transactions.amount', 'cash_differences.resolution_type')
            ->get();

        foreach ($rows as $r) {
            $nilai = (float) $r->amount; // + kas lebih, - kas kurang

            $lawan = match (true) {
                $r->resolution_type === 'CASHIER_CHARGE' => 'piutang_karyawan',
                $nilai > 0 => 'pendapatan_lain',
                default => 'selisih_kas',
            };

            $this->pasang($period, 'kas', $lawan, $nilai);
        }
    }

    private function returPenjualan(FiscalPeriod $period, int $branchId): void
    {
        $rows = DB::table('sales_return_items')
            ->join('sales_returns', 'sales_returns.return_id', '=', 'sales_return_items.return_id')
            ->leftJoin('sale_items', 'sale_items.sale_item_id', '=', 'sales_return_items.sale_item_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales_returns.sale_id')
            ->where('sales_returns.fiscal_period_id', $period->fiscal_period_id)
            ->where('sales_returns.branch_id', $branchId)
            ->where('sales_returns.status', 'POSTED')
            ->select('sales_return_items.refund_amount', 'sale_items.tax_amount', 'sale_items.line_total', 'receivables.receivable_id')
            ->get();

        foreach ($rows as $r) {
            $refund = (float) $r->refund_amount;
            $pajak = (float) $r->line_total > 0 ? round($refund * (float) $r->tax_amount / (float) $r->line_total, 2) : 0.0;
            $kredit = $r->receivable_id !== null ? 'piutang' : 'kas';

            $this->pasang($period, 'retur_penjualan', $kredit, round($refund - $pajak, 2));
            $this->pasang($period, 'ppn_keluaran', $kredit, $pajak);
        }
    }

    /** Seluruh pergerakan nilai persediaan, dipilah menurut sebabnya. */
    private function persediaan(FiscalPeriod $period, int $branchId): void
    {
        $rows = DB::table('stock_movements')
            ->where('fiscal_period_id', $period->fiscal_period_id)
            ->where('branch_id', $branchId)
            ->where('movement_type', '!=', StockMovement::TYPE_OPENING)
            ->groupBy('movement_type', 'reference_type', 'reference_id')
            ->selectRaw('movement_type, reference_type, reference_id, SUM(total_cost) AS nilai')
            ->get();

        foreach ($rows as $r) {
            $nilai = (float) $r->nilai; // + persediaan bertambah, - berkurang
            $sebab = $r->movement_type === StockMovement::TYPE_VOID_REVERSAL ? $r->reference_type : $r->movement_type;

            $lawan = match ($sebab) {
                StockMovement::TYPE_SALES, StockMovement::REF_SALES,
                StockMovement::TYPE_RETURN_IN, StockMovement::REF_SALES_RETURN => 'hpp',
                StockMovement::TYPE_PURCHASE, StockMovement::REF_RECEIPT, 'PURCHASE' => 'grni',
                StockMovement::TYPE_RETURN_OUT, StockMovement::REF_PURCHASE_RETURN => $this->akunReturBeli((int) $r->reference_id),
                StockMovement::TYPE_TRANSFER_IN, StockMovement::TYPE_TRANSFER_OUT, StockMovement::REF_TRANSFER => 'antar_cabang',
                'ROUNDING', StockMovement::REF_CLOSING => 'selisih_hpp',
                default => 'selisih_persediaan',
            };

            $this->pasang($period, 'persediaan', $lawan, $nilai);
        }
    }

    /** Retur ke pemasok mengurangi utang usaha bila penerimaannya sudah difakturkan. */
    private function akunReturBeli(int $returnId): string
    {
        $diFaktur = DB::table('purchase_returns')
            ->join('goods_receipts', 'goods_receipts.goods_receipt_id', '=', 'purchase_returns.goods_receipt_id')
            ->where('purchase_returns.return_id', $returnId)
            ->value('goods_receipts.invoiced_at');

        return $diFaktur !== null ? 'utang' : 'grni';
    }

    // ------------------------------------------------------------- perkakas

    private function kasAtauBank(?string $paymentType): string
    {
        return $paymentType === Payment::TYPE_CASH ? 'kas' : 'bank';
    }

    /**
     * Bukukan sepasang debit-kredit. Nilai negatif membalik arahnya, jadi
     * pemanggil cukup mengirim angka bertanda apa adanya.
     */
    private function pasang(FiscalPeriod $period, string|int $debit, string|int $kredit, float $nilai): void
    {
        $nilai = round($nilai, 2);

        if (abs($nilai) < 0.005) {
            return;
        }

        if ($nilai < 0) {
            [$debit, $kredit, $nilai] = [$kredit, $debit, -$nilai];
        }

        $d = $this->accountId($period->owner_id, $debit);
        $k = $this->accountId($period->owner_id, $kredit);

        $this->saldo[$d] = round(($this->saldo[$d] ?? 0) + $nilai, 2);
        $this->saldo[$k] = round(($this->saldo[$k] ?? 0) - $nilai, 2);
    }

    private function accountId(int $ownerId, string|int $kunci): int
    {
        if (is_int($kunci)) {
            return $kunci;
        }

        if (isset($this->akun[$kunci])) {
            return $this->akun[$kunci];
        }

        [$kode, $nama, $jenis] = self::ACCOUNTS[$kunci];

        $akun = ChartOfAccount::firstOrCreate(
            ['owner_id' => $ownerId, 'account_code' => $kode],
            ['account_name' => $nama, 'account_type' => $jenis],
        );

        return $this->akun[$kunci] = $akun->account_id;
    }

    private function simpan(FiscalPeriod $period, int $branchId, string $type, FiscalClosing $closing, ?User $user): ?int
    {
        $baris = array_filter($this->saldo, fn (float $v) => abs($v) >= 0.005);

        if ($baris === []) {
            return null;
        }

        $debit = round(array_sum(array_filter($baris, fn ($v) => $v > 0)), 2);
        $kredit = round(-array_sum(array_filter($baris, fn ($v) => $v < 0)), 2);

        if (abs($debit - $kredit) >= 0.005) {
            throw new RuntimeException("Jurnal {$type} periode {$period->period_code} tidak seimbang: debit {$debit}, kredit {$kredit}.");
        }

        $id = DB::table('journal_entries')->insertGetId([
            'fiscal_period_id' => $period->fiscal_period_id,
            'branch_id' => $branchId,
            'entry_date' => $type === 'OPENING' ? $period->start_date->toDateString() : $period->end_date->toDateString(),
            'entry_type' => $type,
            'reference_type' => 'FISCAL_CLOSING',
            'reference_id' => $closing->fiscal_closing_id,
            'created_at' => now(),
            'created_by' => $user?->user_id,
        ]);

        ksort($baris);

        foreach ($baris as $accountId => $nilai) {
            DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $id,
                'account_id' => $accountId,
                'debit' => $nilai > 0 ? $nilai : 0,
                'credit' => $nilai < 0 ? -$nilai : 0,
                'currency_code' => 'IDR',
                'exchange_rate' => 1,
            ]);
        }

        return $id;
    }
}

<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\CashRounding;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memposting satu nota penjualan: dari keranjang kasir menjadi transaksi resmi.
 *
 * Semuanya terjadi dalam SATU transaksi database. Nota, stok keluar, HPP, dan
 * penerimaan uang harus jadi bersama atau gagal bersama — nota yang tersimpan
 * tanpa stok berkurang berarti barang hilang dari kartu stok, dan stok yang
 * berkurang tanpa nota berarti uangnya tidak pernah tercatat.
 *
 * Bila kasir sedang punya shift terbuka di lokasi ini, nota dan pembayarannya
 * ditautkan ke shift itu (`shift_id`) dan pembayaran tunai menulis
 * `cash_transactions` CASH_SALE — tapi shift TIDAK wajib: menjual tanpa shift
 * terbuka tetap diperbolehkan, sekadar tidak ikut rekonsiliasi shift mana pun.
 *
 * Penjualan KREDIT (Sale::PAYMENT_TYPE_CREDIT) tidak membuat baris `payments`
 * sama sekali — `paid_total` notanya 0, dan seluruh nilainya menjadi satu
 * `Receivable` (Addendum 41.3). Wajib pelanggan terdaftar dengan
 * `credit_limit` > 0 dan tidak melebihi sisa batasnya.
 *
 * Harga, diskon, dan pajak dihitung SaleCalculator — tempat yang sama yang
 * dipakai pratinjau layar kasir. Diskon manual (tanpa master) tercatat di
 * audit_logs (Addendum 61.2, 62).
 */
class SalePoster
{
    public function __construct(
        private SaleCalculator $calculator,
        private StockLedger $stock,
        private DocumentNumberService $numbers,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @param  array{
     *     customer_id?: int|null,
     *     payment_type: string,
     *     cash_received?: float|null,
     *     local_uuid?: string|null,
     *     device_id?: string|null,
     *     transaction_date?: string|null,
     *     discount_id?: int|null,
     *     manual_discount?: float|null,
     *     manual_discount_reason?: string|null,
     *     lines: array<int, array{product_id: int, uom_id: int, qty: float}>
     * }  $payload
     */
    public function post(User $user, Branch $branch, array $payload): Sale
    {
        $owner = Owner::findOrFail($user->owner_id);
        $date = $payload['transaction_date'] ?? now()->toDateString();
        $uuid = $payload['local_uuid'] ?? null;

        if (! $branch->can_sell) {
            throw ValidationException::withMessages([
                'branch_id' => "{$branch->branch_name} bukan lokasi penjualan sehingga tidak dapat menerbitkan nota.",
            ]);
        }

        if (empty($payload['lines'])) {
            throw ValidationException::withMessages(['lines' => 'Nota kosong — tambahkan barang lebih dulu.']);
        }

        // Idempotensi: kasir yang menekan "Bayar" dua kali karena jaringan lambat
        // tidak boleh menghasilkan dua nota. Perangkat mengirim local_uuid yang
        // sama pada percobaan ulang, dan nota yang sudah jadi dikembalikan apa
        // adanya. Kunci uk_sales_local_uuid menjadi jaring terakhirnya.
        if ($uuid !== null) {
            $existing = Sale::where('branch_id', $branch->branch_id)->where('local_uuid', $uuid)->first();

            if ($existing !== null) {
                return $existing->load('items');
            }
        }

        return DB::transaction(function () use ($owner, $user, $branch, $payload, $date, $uuid) {
            $period = $this->periods->forDate($owner->owner_id, $branch->branch_id, $date);
            $customer = $this->customerFor($owner->owner_id, $payload['customer_id'] ?? null);
            $paymentType = $this->paymentType($payload['payment_type'] ?? null);
            $isCredit = $paymentType === Sale::PAYMENT_TYPE_CREDIT;
            $shift = $this->openShiftFor($user, $branch);

            $hitung = $this->calculator->calculate($owner, $branch, $customer, $payload['lines'], $date, $user, [
                'discount_id' => $payload['discount_id'] ?? null,
                'manual_discount' => $payload['manual_discount'] ?? null,
                'manual_discount_reason' => $payload['manual_discount_reason'] ?? null,
            ]);
            $lines = $hitung['lines'];
            $netTotal = $hitung['net_total'];

            if ($isCredit) {
                if ($customer === null || $customer->is_default) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'Penjualan kredit wajib memilih pelanggan terdaftar, bukan pelanggan tunai bawaan.',
                    ]);
                }

                $this->assertCreditAllowed($customer, $netTotal);
            }

            // Pembulatan hanya menyentuh pembayaran tunai. Non-tunai (kredit
            // termasuk) dibayar/diakui persis sebesar nilai notanya (Addendum 51.4).
            $rounding = $paymentType === Payment::TYPE_CASH
                ? CashRounding::amount($netTotal, (int) $owner->payment_rounding_unit, $owner->payment_rounding_mode)
                : 0.0;

            // Kredit belum menerima uang sama sekali saat nota terbit — paid_total
            // harus mencerminkan itu, bukan nilai nota (Addendum 41.3).
            $paidTotal = $isCredit ? 0.0 : round($netTotal + $rounding, 2);

            $this->assertCashEnough($paymentType, $payload['cash_received'] ?? null, $paidTotal);

            $sale = Sale::create([
                'sale_number' => $this->numbers->next($owner->owner_id, $branch, $period, Sale::PREFIX),
                'fiscal_period_id' => $period->fiscal_period_id,
                'branch_id' => $branch->branch_id,
                'shift_id' => $shift?->shift_id,
                'customer_id' => $customer?->customer_id,
                'discount_id' => $hitung['discount_id'],
                'device_id' => $payload['device_id'] ?? null,
                'local_uuid' => $uuid,
                'transaction_date' => $date,
                'status' => Sale::STATUS_POSTED,
                'gross_total' => $hitung['gross_total'],
                'discount_total' => $hitung['discount_total'],
                'tax_total' => $hitung['tax_total'],
                'net_total' => $netTotal,
                'rounding_amount' => $rounding,
                'paid_total' => $paidTotal,
                'currency_code' => $owner->default_currency,
                'exchange_rate' => 1,
                'created_by' => $user->user_id,
                'updated_by' => $user->user_id,
            ]);

            foreach ($lines as $line) {
                // Stok keluar lebih dulu supaya HPP-nya diketahui; baris nota
                // menyimpan HPP itu sebagai snapshot dan tidak pernah membacanya
                // ulang dari kartu stok (PRD 14).
                $hasil = $this->stock->issueForSale(
                    $owner,
                    $period,
                    $branch->branch_id,
                    $date,
                    $line['product'],
                    $line['uom'],
                    $line['qty'],
                    $sale->sale_id,
                    $user->user_id,
                );

                SaleItem::create([
                    'sale_id' => $sale->sale_id,
                    'product_id' => $line['product']->product_id,
                    'uom_id' => $line['uom']->uom_id,
                    'qty' => $line['qty'],
                    'qty_base' => $line['qty_base'],
                    'unit_price' => $line['unit_price'],
                    'unit_price_base' => $line['unit_price_base'],
                    'price_list_id' => $line['price_list_id'],
                    'discount_id' => $line['discount_id'],
                    'discount_amount' => $line['discount_amount'],
                    'cost_at_sale' => $hasil['cost_at_sale'],
                    'cogs_total' => $hasil['cogs_total'],
                    'tax_amount' => $line['tax_amount'],
                    'line_total' => $line['line_total'],
                ]);
            }

            foreach ($hitung['taxes'] as $pajak) {
                DB::table('sale_taxes')->insert([
                    'sale_id' => $sale->sale_id,
                    'tax_id' => $pajak['tax_id'],
                    'base_amount' => $pajak['base_amount'],
                    'tax_amount' => $pajak['tax_amount'],
                ]);
            }

            if ($hitung['manual_discount'] > 0) {
                DB::table('audit_logs')->insert([
                    'owner_id' => $owner->owner_id,
                    'branch_id' => $branch->branch_id,
                    'user_id' => $user->user_id,
                    'action' => 'CREATE',
                    'entity_type' => 'sales',
                    'entity_id' => $sale->sale_id,
                    'field_name' => 'discount_total',
                    'new_value' => (string) $hitung['manual_discount'],
                    'reason' => 'Diskon manual: '.trim((string) $payload['manual_discount_reason']),
                    'ip_address' => request()->ip(),
                    'created_at' => now(),
                ]);
            }

            if ($isCredit) {
                Receivable::create([
                    'fiscal_period_id' => $period->fiscal_period_id,
                    'branch_id' => $branch->branch_id,
                    'customer_id' => $customer->customer_id,
                    'sale_id' => $sale->sale_id,
                    'amount' => $netTotal,
                    'paid_amount' => 0,
                    'status' => Receivable::STATUS_OPEN,
                    'created_by' => $user->user_id,
                ]);
            } elseif ($paidTotal > 0) {
                $payment = Payment::create([
                    'payment_number' => $this->numbers->next($owner->owner_id, $branch, $period, Payment::PREFIX),
                    'fiscal_period_id' => $period->fiscal_period_id,
                    'branch_id' => $branch->branch_id,
                    'shift_id' => $shift?->shift_id,
                    'transaction_date' => $date,
                    'payment_type' => $paymentType,
                    'amount' => $paidTotal,
                    'currency_code' => $owner->default_currency,
                    'exchange_rate' => 1,
                    'status' => Payment::STATUS_POSTED,
                    'created_by' => $user->user_id,
                ]);

                $sale->payments()->attach($payment->payment_id, ['amount' => $paidTotal]);

                if ($paymentType === Payment::TYPE_CASH) {
                    CashTransaction::create([
                        'fiscal_period_id' => $period->fiscal_period_id,
                        'branch_id' => $branch->branch_id,
                        'shift_id' => $shift?->shift_id,
                        'transaction_date' => $date,
                        'txn_type' => CashTransaction::TYPE_CASH_SALE,
                        'amount' => $paidTotal,
                        'reference_type' => CashTransaction::REF_SALES,
                        'reference_id' => $sale->sale_id,
                        'created_by' => $user->user_id,
                    ]);
                }
            }

            return $sale->load('items');
        });
    }

    /**
     * Pelanggan tunai bawaan dipakai bila kasir tidak memilih siapa pun, supaya
     * kasir tidak perlu memilih apa pun untuk transaksi biasa dan laporan per
     * pelanggan tetap utuh (Addendum 39.13).
     */
    private function customerFor(int $ownerId, ?int $customerId): ?Customer
    {
        if ($customerId === null) {
            return Customer::where('owner_id', $ownerId)->where('is_default', true)->where('active', true)->first();
        }

        $customer = Customer::where('owner_id', $ownerId)->where('active', true)->find($customerId);

        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => 'Pelanggan tidak dikenal atau sudah tidak aktif.']);
        }

        return $customer;
    }

    /** Shift terbuka kasir ini di lokasi ini, kalau ada — tidak wajib. */
    private function openShiftFor(User $user, Branch $branch): ?CashierShift
    {
        return CashierShift::where('branch_id', $branch->branch_id)
            ->where('cashier_id', $user->user_id)
            ->where('status', CashierShift::STATUS_OPEN)
            ->first();
    }

    private function paymentType(?string $type): string
    {
        if (! in_array($type, Payment::TYPES, true) && $type !== Sale::PAYMENT_TYPE_CREDIT) {
            throw ValidationException::withMessages(['payment_type' => 'Metode pembayaran tidak dikenal.']);
        }

        return $type;
    }

    /**
     * `credit_limit` 0 berarti pelanggan tunai saja — tidak boleh berutang
     * sama sekali (Addendum, lihat komentar kolom `customers.credit_limit`).
     * Transaksi baru + piutang yang masih terbuka tidak boleh melebihi batas.
     */
    private function assertCreditAllowed(Customer $customer, float $amount): void
    {
        $limit = (float) $customer->credit_limit;

        if ($limit <= 0) {
            throw ValidationException::withMessages([
                'customer_id' => "{$customer->customer_name} tidak diizinkan berutang (batas piutang 0 — tunai saja).",
            ]);
        }

        $terpakai = (float) Receivable::where('customer_id', $customer->customer_id)
            ->outstanding()
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')
            ->value('total');

        if (round($terpakai + $amount, 2) > $limit) {
            throw ValidationException::withMessages([
                'customer_id' => sprintf(
                    'Transaksi ini melebihi batas piutang %s. Batas Rp%s, terpakai Rp%s, sisa Rp%s.',
                    $customer->customer_name,
                    number_format($limit, 0, ',', '.'),
                    number_format($terpakai, 0, ',', '.'),
                    number_format(max($limit - $terpakai, 0), 0, ',', '.'),
                ),
            ]);
        }
    }

    /** Uang tunai yang diterima tidak boleh kurang dari yang harus dibayar. */
    private function assertCashEnough(string $paymentType, ?float $received, float $paidTotal): void
    {
        if ($paymentType !== Payment::TYPE_CASH || $received === null) {
            return;
        }

        if (round($received, 2) < $paidTotal) {
            throw ValidationException::withMessages([
                'cash_received' => 'Uang yang diterima kurang dari jumlah yang harus dibayar.',
            ]);
        }
    }
}

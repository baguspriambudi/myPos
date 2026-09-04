<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pelunasan piutang usaha.
 *
 * Yang dijaga: pelunasan CASH menulis cash_transactions CASH_IN, status
 * Receivable naik OPEN → PARTIAL → PAID sesuai akumulasi, tidak boleh
 * melebihi sisa, dan void mengembalikan status Receivable serta membalik kas
 * pada tanggal void. Pembayarannya ditaut ke NOTA asal lewat sale_payments —
 * pivot yang sama yang sudah dipakai pembayaran instan.
 */
class ReceivablePaymentTest extends TestCase
{
    use RefreshDatabase;

    /** Semua pelanggan berlimit piutang di seeder adalah member — RNS-045 jatuh ke harga grosir member Rp2.200/pcs, bukan harga umum. */
    private const HARGA_RNS045_MEMBER = 2200.0;

    private Branch $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);
        $this->pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
    }

    private function manajer(): User
    {
        return User::where('username', 'manajer')->firstOrFail();
    }

    private function kasir(): User
    {
        return User::where('username', 'kasir')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function pelanggan(string $name): Customer
    {
        return Customer::where('customer_name', $name)->firstOrFail();
    }

    /** Menjual kredit sungguhan lewat kasir supaya Receivable-nya nyata (qty 50 = Rp110.000). */
    private function jualKredit(Customer $customer, float $qty = 50): Receivable
    {
        $product = $this->produk('RNS-045');
        $periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $periode,
            $this->pusat->branch_id,
            now()->toDateString(),
            $product,
            $product->uoms->firstWhere('uom_name', 'PCS'),
            $qty,
            2000,
        ));

        $this->actingAs($this->kasir())->post('/kasir/nota', [
            'payment_type' => 'CREDIT',
            'customer_id' => $customer->customer_id,
            'lines' => [[
                'product_id' => $product->product_id,
                'uom_id' => $product->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => $qty,
            ]],
        ])->assertSessionHas('nota');

        return Receivable::orderByDesc('receivable_id')->firstOrFail();
    }

    // -------------------------------------------------------------- posting

    public function test_pelunasan_tunai_menulis_cash_transaction_dan_melunasi(): void
    {
        $receivable = $this->jualKredit($this->pelanggan('Warung Bu Aminah')); // Rp110.000
        $total = 50 * self::HARGA_RNS045_MEMBER;

        $this->actingAs($this->manajer())
            ->post("/piutang/{$receivable->receivable_id}/terima", [
                'payment_date' => now()->toDateString(),
                'amount' => $total,
                'payment_type' => 'CASH',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $receivable->refresh();
        $this->assertSame('PAID', $receivable->status);
        $this->assertSame($total, (float) $receivable->paid_amount);

        $payment = Payment::firstOrFail();
        $this->assertSame('POSTED', $payment->status);

        $this->assertDatabaseHas('cash_transactions', [
            'txn_type' => 'CASH_IN',
            'amount' => $total,
            'reference_type' => 'RECEIVABLE',
            'reference_id' => $payment->payment_id,
        ]);

        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $receivable->sale_id,
            'payment_id' => $payment->payment_id,
            'amount' => $total,
        ]);
    }

    public function test_pelunasan_sebagian_membuat_status_partial(): void
    {
        $receivable = $this->jualKredit($this->pelanggan('Warung Bu Aminah')); // Rp110.000

        $this->actingAs($this->manajer())->post("/piutang/{$receivable->receivable_id}/terima", [
            'payment_date' => now()->toDateString(),
            'amount' => 70000,
            'payment_type' => 'TRANSFER',
        ])->assertRedirect();

        $receivable->refresh();
        $this->assertSame('PARTIAL', $receivable->status);
        $this->assertSame(40000.0, $receivable->outstanding());
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_pelunasan_berkali_kali_sampai_lunas(): void
    {
        $receivable = $this->jualKredit($this->pelanggan('Warung Bu Aminah')); // Rp110.000

        $this->actingAs($this->manajer())->post("/piutang/{$receivable->receivable_id}/terima", [
            'payment_date' => now()->toDateString(),
            'amount' => 70000,
            'payment_type' => 'TRANSFER',
        ]);

        $this->actingAs($this->manajer())
            ->post("/piutang/{$receivable->receivable_id}/terima", [
                'payment_date' => now()->toDateString(),
                'amount' => 40000,
                'payment_type' => 'CASH',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $receivable->refresh();
        $this->assertSame('PAID', $receivable->status);
        $this->assertSame(2, Payment::count());

        // Nota asal punya dua baris payments lewat sale_payments yang sama.
        $sale = Sale::findOrFail($receivable->sale_id);
        $this->assertSame(2, $sale->payments()->count());
    }

    public function test_pelunasan_tidak_boleh_melebihi_sisa(): void
    {
        $receivable = $this->jualKredit($this->pelanggan('Warung Bu Aminah')); // Rp110.000

        $this->actingAs($this->manajer())
            ->post("/piutang/{$receivable->receivable_id}/terima", [
                'payment_date' => now()->toDateString(),
                'amount' => 150000,
                'payment_type' => 'CASH',
            ])
            ->assertSessionHasErrors('amount');

        $receivable->refresh();
        $this->assertSame('OPEN', $receivable->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_kasir_tidak_boleh_menerima_pelunasan_piutang(): void
    {
        $receivable = $this->jualKredit($this->pelanggan('Warung Bu Aminah'));

        $this->actingAs($this->kasir())
            ->post("/piutang/{$receivable->receivable_id}/terima", [
                'payment_date' => now()->toDateString(),
                'amount' => 50000,
                'payment_type' => 'CASH',
            ])
            ->assertForbidden();
    }

    // -------------------------------------------------------------------- void

    public function test_void_pelunasan_tunai_membalik_kas_dan_membuka_kembali_piutang(): void
    {
        $receivable = $this->jualKredit($this->pelanggan('Warung Bu Aminah')); // Rp110.000
        $total = 50 * self::HARGA_RNS045_MEMBER;

        $this->actingAs($this->manajer())->post("/piutang/{$receivable->receivable_id}/terima", [
            'payment_date' => now()->toDateString(),
            'amount' => $total,
            'payment_type' => 'CASH',
        ]);
        $payment = Payment::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/piutang/pembayaran/{$payment->payment_id}/batal")
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame('VOID', $payment->status);

        $receivable->refresh();
        $this->assertSame('OPEN', $receivable->status);
        $this->assertSame(0.0, (float) $receivable->paid_amount);

        $this->assertDatabaseHas('cash_transactions', [
            'txn_type' => 'CASH_OUT',
            'amount' => -$total,
            'reference_type' => 'RECEIVABLE',
            'reference_id' => $payment->payment_id,
        ]);

        $totalKas = (float) CashTransaction::where('reference_id', $payment->payment_id)->sum('amount');
        $this->assertSame(0.0, $totalKas);
    }

    public function test_void_pelunasan_sebagian_mengembalikan_ke_partial(): void
    {
        $receivable = $this->jualKredit($this->pelanggan('Warung Bu Aminah')); // Rp110.000

        $this->actingAs($this->manajer())->post("/piutang/{$receivable->receivable_id}/terima", [
            'payment_date' => now()->toDateString(),
            'amount' => 70000,
            'payment_type' => 'TRANSFER',
        ]);
        $pertama = Payment::firstOrFail();

        $this->actingAs($this->manajer())->post("/piutang/{$receivable->receivable_id}/terima", [
            'payment_date' => now()->toDateString(),
            'amount' => 40000,
            'payment_type' => 'CASH',
        ]);

        $receivable->refresh();
        $this->assertSame('PAID', $receivable->status);

        $this->actingAs($this->manajer())->post("/piutang/pembayaran/{$pertama->payment_id}/batal")->assertRedirect();

        $receivable->refresh();
        $this->assertSame('PARTIAL', $receivable->status);
        $this->assertSame(40000.0, (float) $receivable->paid_amount);
    }

    public function test_kasir_tidak_boleh_membatalkan_pelunasan(): void
    {
        $receivable = $this->jualKredit($this->pelanggan('Warung Bu Aminah'));
        $total = 50 * self::HARGA_RNS045_MEMBER;

        $this->actingAs($this->manajer())->post("/piutang/{$receivable->receivable_id}/terima", [
            'payment_date' => now()->toDateString(),
            'amount' => $total,
            'payment_type' => 'CASH',
        ]);
        $payment = Payment::firstOrFail();

        $this->actingAs($this->kasir())
            ->post("/piutang/pembayaran/{$payment->payment_id}/batal")
            ->assertForbidden();
    }
}

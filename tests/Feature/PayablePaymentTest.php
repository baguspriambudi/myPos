<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashTransaction;
use App\Models\GoodsReceipt;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pembayaran utang usaha.
 *
 * Yang dijaga: pembayaran CASH menulis cash_transactions CASH_OUT, status
 * Payable naik dari OPEN → PARTIAL → PAID sesuai akumulasi pembayaran, tidak
 * boleh membayar lebih dari sisa, dan void pembayaran mengembalikan status
 * Payable serta membalik kas pada tanggal void.
 */
class PayablePaymentTest extends TestCase
{
    use RefreshDatabase;

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

    private function pemasok(): Supplier
    {
        return Supplier::firstOrFail();
    }

    /** Membuat faktur POSTED senilai qty*unitCost lewat alur asli, supaya Payable-nya nyata. */
    private function fakturkan(float $qty, float $unitCost): Payable
    {
        $product = $this->produk('RNS-045');

        $this->actingAs($this->manajer())->post('/penerimaan', [
            'supplier_id' => $this->pemasok()->supplier_id,
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->product_id,
                'uom_id' => $product->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => $qty,
            ]],
        ]);
        $receipt = GoodsReceipt::orderByDesc('goods_receipt_id')->firstOrFail();

        $this->actingAs($this->manajer())->post('/pembelian', [
            'supplier_id' => $this->pemasok()->supplier_id,
            'transaction_date' => now()->toDateString(),
            'receipt_ids' => [$receipt->goods_receipt_id],
            'lines' => [[
                'receipt_item_id' => $receipt->items->firstOrFail()->receipt_item_id,
                'qty' => $qty,
                'unit_cost' => $unitCost,
            ]],
        ]);

        return Payable::orderByDesc('payable_id')->firstOrFail();
    }

    // -------------------------------------------------------------- posting

    public function test_pembayaran_tunai_menulis_cash_transaction_dan_melunasi(): void
    {
        $payable = $this->fakturkan(10, 2000); // Rp20.000

        $this->actingAs($this->manajer())
            ->post("/utang/{$payable->payable_id}/bayar", [
                'payment_date' => now()->toDateString(),
                'amount' => 20000,
                'payment_type' => 'CASH',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payable->refresh();
        $this->assertSame('PAID', $payable->status);
        $this->assertSame(20000.0, (float) $payable->paid_amount);

        $payment = Payment::firstOrFail();
        $this->assertSame('POSTED', $payment->status);

        $this->assertDatabaseHas('cash_transactions', [
            'txn_type' => 'CASH_OUT',
            'amount' => -20000,
            'reference_type' => 'PAYMENT',
            'reference_id' => $payment->payment_id,
        ]);

        $this->assertDatabaseHas('purchase_payments', [
            'purchase_id' => $payable->purchase_id,
            'payment_id' => $payment->payment_id,
            'amount' => 20000,
        ]);
    }

    public function test_pembayaran_sebagian_membuat_status_partial(): void
    {
        $payable = $this->fakturkan(10, 2000); // Rp20.000

        $this->actingAs($this->manajer())->post("/utang/{$payable->payable_id}/bayar", [
            'payment_date' => now()->toDateString(),
            'amount' => 12000,
            'payment_type' => 'TRANSFER',
        ])->assertRedirect();

        $payable->refresh();
        $this->assertSame('PARTIAL', $payable->status);
        $this->assertSame(12000.0, (float) $payable->paid_amount);
        $this->assertSame(8000.0, $payable->outstanding());

        // Metode non-tunai tidak menyentuh buku kas.
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_pembayaran_berkali_kali_sampai_lunas(): void
    {
        $payable = $this->fakturkan(10, 2000); // Rp20.000

        $this->actingAs($this->manajer())->post("/utang/{$payable->payable_id}/bayar", [
            'payment_date' => now()->toDateString(),
            'amount' => 12000,
            'payment_type' => 'TRANSFER',
        ]);

        $this->actingAs($this->manajer())
            ->post("/utang/{$payable->payable_id}/bayar", [
                'payment_date' => now()->toDateString(),
                'amount' => 8000,
                'payment_type' => 'CASH',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payable->refresh();
        $this->assertSame('PAID', $payable->status);
        $this->assertSame(20000.0, (float) $payable->paid_amount);
        $this->assertSame(2, Payment::count());
    }

    public function test_pembayaran_tidak_boleh_melebihi_sisa(): void
    {
        $payable = $this->fakturkan(10, 2000); // Rp20.000

        $this->actingAs($this->manajer())
            ->post("/utang/{$payable->payable_id}/bayar", [
                'payment_date' => now()->toDateString(),
                'amount' => 25000,
                'payment_type' => 'CASH',
            ])
            ->assertSessionHasErrors('amount');

        $payable->refresh();
        $this->assertSame('OPEN', $payable->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_utang_yang_sudah_lunas_tidak_dapat_dibayar_lagi(): void
    {
        $payable = $this->fakturkan(10, 2000);

        $this->actingAs($this->manajer())->post("/utang/{$payable->payable_id}/bayar", [
            'payment_date' => now()->toDateString(),
            'amount' => 20000,
            'payment_type' => 'CASH',
        ]);

        $this->actingAs($this->manajer())
            ->post("/utang/{$payable->payable_id}/bayar", [
                'payment_date' => now()->toDateString(),
                'amount' => 1000,
                'payment_type' => 'CASH',
            ])
            ->assertSessionHasErrors('payable');
    }

    public function test_kasir_tidak_boleh_membayar_utang(): void
    {
        $payable = $this->fakturkan(10, 2000);

        $this->actingAs($this->kasir())
            ->post("/utang/{$payable->payable_id}/bayar", [
                'payment_date' => now()->toDateString(),
                'amount' => 20000,
                'payment_type' => 'CASH',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    // -------------------------------------------------------------------- void

    public function test_void_pembayaran_tunai_membalik_kas_dan_membuka_kembali_utang(): void
    {
        $payable = $this->fakturkan(10, 2000); // Rp20.000

        $this->actingAs($this->manajer())->post("/utang/{$payable->payable_id}/bayar", [
            'payment_date' => now()->toDateString(),
            'amount' => 20000,
            'payment_type' => 'CASH',
        ]);
        $payment = Payment::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/utang/pembayaran/{$payment->payment_id}/batal")
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame('VOID', $payment->status);

        $payable->refresh();
        $this->assertSame('OPEN', $payable->status);
        $this->assertSame(0.0, (float) $payable->paid_amount);

        $this->assertDatabaseHas('cash_transactions', [
            'txn_type' => 'CASH_IN',
            'amount' => 20000,
            'reference_type' => 'PAYMENT',
            'reference_id' => $payment->payment_id,
        ]);

        $totalKas = (float) CashTransaction::where('reference_id', $payment->payment_id)->sum('amount');
        $this->assertSame(0.0, $totalKas);
    }

    public function test_void_pembayaran_sebagian_mengembalikan_ke_partial(): void
    {
        $payable = $this->fakturkan(10, 2000); // Rp20.000

        $this->actingAs($this->manajer())->post("/utang/{$payable->payable_id}/bayar", [
            'payment_date' => now()->toDateString(),
            'amount' => 12000,
            'payment_type' => 'TRANSFER',
        ]);
        $pertama = Payment::firstOrFail();

        $this->actingAs($this->manajer())->post("/utang/{$payable->payable_id}/bayar", [
            'payment_date' => now()->toDateString(),
            'amount' => 8000,
            'payment_type' => 'CASH',
        ]);

        $payable->refresh();
        $this->assertSame('PAID', $payable->status);

        // Batalkan pembayaran pertama (Rp12.000) — sisa Rp8.000 sudah dibayar, jadi kembali PARTIAL.
        $this->actingAs($this->manajer())->post("/utang/pembayaran/{$pertama->payment_id}/batal")->assertRedirect();

        $payable->refresh();
        $this->assertSame('PARTIAL', $payable->status);
        $this->assertSame(8000.0, (float) $payable->paid_amount);
    }

    public function test_kasir_tidak_boleh_membatalkan_pembayaran(): void
    {
        $payable = $this->fakturkan(10, 2000);

        $this->actingAs($this->manajer())->post("/utang/{$payable->payable_id}/bayar", [
            'payment_date' => now()->toDateString(),
            'amount' => 20000,
            'payment_type' => 'CASH',
        ]);
        $payment = Payment::firstOrFail();

        $this->actingAs($this->kasir())
            ->post("/utang/pembayaran/{$payment->payment_id}/batal")
            ->assertForbidden();
    }
}

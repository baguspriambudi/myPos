<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\FiscalPeriod;
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
 * Penjualan kredit & piutang usaha (Addendum 41.3).
 *
 * Yang dijaga: kredit wajib pelanggan terdaftar (bukan tunai bawaan) dengan
 * credit_limit > 0, tidak boleh melebihi sisa limit, paid_total nota jadi 0
 * (bukan nilai nota), tidak ada Payment/CASH_SALE yang tercipta, dan void
 * hanya boleh selama piutangnya belum ada pembayaran sama sekali.
 */
class ReceivableTest extends TestCase
{
    use RefreshDatabase;

    private Branch $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);
        $this->pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
    }

    private function kasir(): User
    {
        return User::where('username', 'kasir')->firstOrFail();
    }

    private function manajer(): User
    {
        return User::where('username', 'manajer')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function pelanggan(string $name): Customer
    {
        return Customer::where('customer_name', $name)->firstOrFail();
    }

    private function isiStok(string $sku, float $qty, float $cost): void
    {
        $product = $this->produk($sku);
        $periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $periode,
            $this->pusat->branch_id,
            now()->toDateString(),
            $product,
            $product->uoms->firstWhere('uom_name', 'PCS'),
            $qty,
            $cost,
        ));
    }

    /** @return array<string, mixed> */
    private function isianKredit(Customer $customer, string $sku, float $qty): array
    {
        $product = $this->produk($sku);

        return [
            'payment_type' => 'CREDIT',
            'customer_id' => $customer->customer_id,
            'lines' => [[
                'product_id' => $product->product_id,
                'uom_id' => $product->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => $qty,
            ]],
        ];
    }

    // -------------------------------------------------------------- posting

    public function test_nota_kredit_membuat_piutang_dan_paid_total_nol(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $pelanggan = $this->pelanggan('Warung Bu Aminah');

        $this->actingAs($this->kasir())
            ->post('/kasir/nota', $this->isianKredit($pelanggan, 'RNS-045', 4))
            ->assertRedirect()
            ->assertSessionHas('nota');

        $sale = Sale::firstOrFail();
        $this->assertSame(0.0, (float) $sale->paid_total);
        $this->assertSame($pelanggan->customer_id, $sale->customer_id);

        $receivable = Receivable::firstOrFail();
        $this->assertSame('OPEN', $receivable->status);
        $this->assertSame($sale->sale_id, $receivable->sale_id);
        $this->assertSame((float) $sale->net_total, (float) $receivable->amount);

        // Kredit tidak pernah membuat baris payments maupun CASH_SALE.
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);

        // Stok tetap keluar seperti penjualan biasa.
        $this->assertSame(6.0, app(StockLedger::class)->onHand($this->pusat->branch_id, $this->produk('RNS-045')->product_id));
    }

    public function test_kredit_wajib_pelanggan_terdaftar_bukan_tunai_bawaan(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $product = $this->produk('RNS-045');

        $this->actingAs($this->kasir())
            ->post('/kasir/nota', [
                'payment_type' => 'CREDIT',
                // customer_id tidak diisi -> jatuh ke pelanggan tunai bawaan.
                'lines' => [[
                    'product_id' => $product->product_id,
                    'uom_id' => $product->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                    'qty' => 1,
                ]],
            ])
            ->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_pelanggan_dengan_credit_limit_nol_tidak_boleh_kredit(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        // Pelanggan tunai bawaan sendiri punya credit_limit 0.
        $bawaan = Customer::where('owner_id', $this->manajer()->owner_id)->where('is_default', true)->firstOrFail();

        $this->actingAs($this->kasir())
            ->post('/kasir/nota', $this->isianKredit($bawaan, 'RNS-045', 1))
            ->assertSessionHasErrors('customer_id');
    }

    public function test_transaksi_melebihi_sisa_limit_ditolak(): void
    {
        $this->isiStok('RNS-045', 300, 2000);
        // Kantin SD Melati: limit Rp500.000. RNS-045 Rp2.500/pcs — 250 pcs
        // sudah Rp625.000, melampaui batas dalam satu nota.
        $pelanggan = $this->pelanggan('Kantin SD Melati');

        $this->actingAs($this->kasir())
            ->post('/kasir/nota', $this->isianKredit($pelanggan, 'RNS-045', 250))
            ->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('receivables', 0);
    }

    public function test_akumulasi_piutang_terbuka_ikut_diperhitungkan(): void
    {
        $this->isiStok('RNS-045', 300, 2000);
        $pelanggan = $this->pelanggan('Kantin SD Melati'); // limit Rp500.000, RNS-045 Rp2.500/pcs

        // Nota pertama 100 pcs = Rp250.000 -> masih di bawah limit.
        $this->actingAs($this->kasir())
            ->post('/kasir/nota', $this->isianKredit($pelanggan, 'RNS-045', 100))
            ->assertSessionHas('nota');

        // Nota kedua 100 pcs lagi -> akumulasi tepat Rp500.000, masih boleh (tidak "melebihi").
        $this->actingAs($this->kasir())
            ->post('/kasir/nota', $this->isianKredit($pelanggan, 'RNS-045', 100))
            ->assertSessionHas('nota');

        // Nota ketiga akan mendorong akumulasi ke Rp750.000 -> melebihi Rp500.000.
        $this->actingAs($this->kasir())
            ->post('/kasir/nota', $this->isianKredit($pelanggan, 'RNS-045', 100))
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(2, Sale::count());
    }

    public function test_petugas_gudang_tidak_boleh_menjual_kredit(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $pelanggan = $this->pelanggan('Warung Bu Aminah');

        $this->actingAs(User::where('username', 'gudang')->firstOrFail())
            ->post('/kasir/nota', $this->isianKredit($pelanggan, 'RNS-045', 1))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------- void

    public function test_void_nota_kredit_belum_dibayar_membatalkan_piutang(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $pelanggan = $this->pelanggan('Warung Bu Aminah');

        $this->actingAs($this->kasir())->post('/kasir/nota', $this->isianKredit($pelanggan, 'RNS-045', 4));
        $sale = Sale::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Pelanggan batal ambil barang.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame('VOID', $sale->status);

        $receivable = Receivable::firstOrFail();
        $this->assertSame('VOID', $receivable->status);

        // Stok kembali seperti nota biasa; tidak ada kas yang bergerak sama sekali.
        $this->assertSame(10.0, app(StockLedger::class)->onHand($this->pusat->branch_id, $this->produk('RNS-045')->product_id));
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_void_nota_kredit_yang_sudah_dicicil_ditolak(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $pelanggan = $this->pelanggan('Warung Bu Aminah');

        $this->actingAs($this->kasir())->post('/kasir/nota', $this->isianKredit($pelanggan, 'RNS-045', 4));
        $sale = Sale::firstOrFail();
        $receivable = Receivable::firstOrFail();

        $this->actingAs($this->manajer())->post("/piutang/{$receivable->receivable_id}/terima", [
            'payment_date' => now()->toDateString(),
            'amount' => 1000,
            'payment_type' => 'CASH',
        ])->assertRedirect();

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Ingin membatalkan walau sudah dicicil.'])
            ->assertSessionHasErrors('sale');

        $this->assertSame(Sale::STATUS_POSTED, $sale->fresh()->status);
    }
}

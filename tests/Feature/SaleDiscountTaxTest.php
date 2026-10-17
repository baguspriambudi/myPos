<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Discount;
use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\User;
use App\Services\FiscalPeriodFigures;
use App\Services\SalePoster;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Diskon (Addendum 61, acceptance 56.14) dan pajak per produk (PRD 19,
 * Addendum 51.2) pada nota penjualan.
 */
class SaleDiscountTaxTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    private Branch $pusat;

    private FiscalPeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);

        $this->owner = Owner::firstOrFail();
        $this->pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
        $this->periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();

        // Diskon contoh dari seeder dimatikan supaya tiap tes mengatur
        // diskonnya sendiri dan angkanya bisa dihitung dengan tangan.
        Discount::query()->update(['active' => false]);
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

    private function diskon(array $atribut): Discount
    {
        return Discount::create(array_merge([
            'owner_id' => $this->owner->owner_id,
            'discount_name' => 'Diskon uji',
            'discount_scope' => 'ITEM',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'min_qty' => 0,
            'min_amount' => 0,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'active' => true,
        ], $atribut));
    }

    private function isiStok(string $sku, string $uom, float $qty, float $cost): void
    {
        $product = $this->produk($sku);

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->periode,
            $this->pusat->branch_id,
            now()->toDateString(),
            $product,
            $product->uoms->firstWhere('uom_name', $uom),
            $qty,
            $cost,
        ));
    }

    /** Jasa antar Rp15.000/KALI — tanpa stok, jadi angka nota mudah dihitung. */
    private function jual(float $qty, array $extra = [], ?User $user = null, string $sku = 'JSA-ANTAR', string $uom = 'KALI'): Sale
    {
        $product = $this->produk($sku);

        return app(SalePoster::class)->post($user ?? $this->kasir(), $this->pusat, array_merge([
            'payment_type' => 'QRIS',
            'lines' => [[
                'product_id' => $product->product_id,
                'uom_id' => $product->uoms->firstWhere('uom_name', $uom)->uom_id,
                'qty' => $qty,
            ]],
        ], $extra));
    }

    // ------------------------------------------------------------ diskon baris

    public function test_diskon_persen_dibatasi_max_discount_amount(): void
    {
        $antar = $this->produk('JSA-ANTAR');
        // 10% dari Rp150.000 = Rp15.000, tapi batasnya Rp5.000 (acceptance 56.14).
        $this->diskon(['product_id' => $antar->product_id, 'max_discount_amount' => 5000]);

        $sale = $this->jual(10);

        $this->assertSame(150000.0, (float) $sale->gross_total);
        $this->assertSame(5000.0, (float) $sale->discount_total);
        $this->assertSame(145000.0, (float) $sale->net_total);
        $this->assertSame(5000.0, (float) $sale->items->first()->discount_amount);
        $this->assertNotNull($sale->items->first()->discount_id);
    }

    public function test_diskon_baris_hanya_berlaku_bila_syarat_qty_terpenuhi(): void
    {
        $this->diskon(['product_id' => $this->produk('JSA-ANTAR')->product_id, 'min_qty' => 3]);

        $this->assertSame(0.0, (float) $this->jual(2)->discount_total);
        $this->assertSame(4500.0, (float) $this->jual(3)->discount_total);
    }

    public function test_diskon_kedaluwarsa_atau_nonaktif_tidak_dipakai(): void
    {
        $this->diskon(['end_date' => now()->subDays(40)->toDateString(), 'start_date' => now()->subDays(60)->toDateString()]);
        $this->diskon(['active' => false]);

        $this->assertSame(0.0, (float) $this->jual(1)->discount_total);
    }

    public function test_diskon_terbesar_yang_dipakai_bila_beberapa_berlaku(): void
    {
        $this->diskon(['discount_value' => 5]);
        $this->diskon(['discount_type' => 'AMOUNT', 'discount_value' => 2000]);

        // 5% dari 15.000 = 750; potongan Rp2.000 lebih besar.
        $this->assertSame(2000.0, (float) $this->jual(1)->discount_total);
    }

    public function test_diskon_tidak_mengubah_hpp(): void
    {
        $this->isiStok('RNS-045', 'PCS', 10, 2000);
        $this->diskon(['discount_value' => 50]);

        $sale = $this->jual(2, [], null, 'RNS-045', 'PCS');
        $item = $sale->items->first();

        $this->assertSame(2500.0, (float) $item->discount_amount);
        $this->assertSame(4000.0, (float) $item->cogs_total);
    }

    public function test_menonaktifkan_master_tidak_mengubah_nota_lama(): void
    {
        $diskon = $this->diskon([]);
        $sale = $this->jual(1);

        $diskon->update(['active' => false, 'discount_value' => 90]);

        $this->assertSame(1500.0, (float) $sale->fresh()->discount_total);
        $this->assertSame(13500.0, (float) $sale->fresh()->net_total);
    }

    // ------------------------------------------------------------- diskon nota

    public function test_diskon_nota_dialokasikan_proporsional_ke_baris(): void
    {
        $this->isiStok('RNS-045', 'PCS', 100, 2000);
        $nota = $this->diskon(['discount_scope' => 'TRANSACTION', 'discount_type' => 'AMOUNT', 'discount_value' => 10000]);
        $antar = $this->produk('JSA-ANTAR');
        $rinso = $this->produk('RNS-045');

        // Rp45.000 (3 × antar) dan Rp27.600 (12 pcs rinso × harga bertingkat 2.300).
        $sale = app(SalePoster::class)->post($this->kasir(), $this->pusat, [
            'payment_type' => 'QRIS',
            'discount_id' => $nota->discount_id,
            'lines' => [
                ['product_id' => $antar->product_id, 'uom_id' => $antar->uoms->first()->uom_id, 'qty' => 3],
                ['product_id' => $rinso->product_id, 'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id, 'qty' => 12],
            ],
        ]);

        $baris = $sale->items->sortBy('sale_item_id')->values();
        $this->assertSame(10000.0, (float) $sale->discount_total);
        $this->assertSame($nota->discount_id, $sale->discount_id);
        $this->assertEqualsWithDelta(10000.0, (float) $baris[0]->discount_amount + (float) $baris[1]->discount_amount, 0.001);
        $this->assertSame(62600.0, (float) $sale->net_total);
        // 10.000 × 45.000/72.600 = 6.198,35; sisanya jatuh ke baris terakhir.
        $this->assertSame(6198.35, (float) $baris[0]->discount_amount);
        $this->assertSame(3801.65, (float) $baris[1]->discount_amount);
    }

    public function test_diskon_nota_yang_syaratnya_belum_terpenuhi_ditolak(): void
    {
        $nota = $this->diskon(['discount_scope' => 'TRANSACTION', 'min_amount' => 100000]);

        $this->expectException(ValidationException::class);
        $this->jual(1, ['discount_id' => $nota->discount_id]);
    }

    // ----------------------------------------------------------- diskon manual

    public function test_kasir_tidak_boleh_memberi_diskon_manual(): void
    {
        try {
            $this->jual(1, ['manual_discount' => 1000, 'manual_discount_reason' => 'Langganan lama.']);
            $this->fail('Diskon manual oleh kasir seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('manual_discount', $e->errors());
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_manajer_boleh_memberi_diskon_manual_dan_tercatat_di_audit_log(): void
    {
        $sale = $this->jual(1, ['manual_discount' => 1000, 'manual_discount_reason' => 'Langganan lama.'], $this->manajer());

        $this->assertSame(1000.0, (float) $sale->discount_total);
        $this->assertSame(14000.0, (float) $sale->net_total);
        $this->assertNull($sale->discount_id);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'sales',
            'entity_id' => $sale->sale_id,
            'field_name' => 'discount_total',
        ]);
    }

    public function test_diskon_manual_melebihi_nilai_belanja_ditolak(): void
    {
        $this->expectException(ValidationException::class);
        $this->jual(1, ['manual_discount' => 20000, 'manual_discount_reason' => 'Terlalu besar.'], $this->manajer());
    }

    public function test_diskon_manual_wajib_beralasan(): void
    {
        $this->expectException(ValidationException::class);
        $this->jual(1, ['manual_discount' => 1000], $this->manajer());
    }

    // ------------------------------------------------------------------ pajak

    private function pasangPpn(string $sku = 'JSA-ANTAR'): Tax
    {
        $ppn = Tax::where('tax_name', 'PPN 11%')->firstOrFail();
        $this->produk($sku)->update(['tax_id' => $ppn->tax_id]);

        return $ppn;
    }

    public function test_pajak_dihitung_dari_dalam_harga_bila_harga_termasuk_pajak(): void
    {
        $ppn = $this->pasangPpn();

        $sale = $this->jual(1);

        // 15.000 × 11/111 = 1.486,49 — harga yang dibayar pembeli tidak berubah.
        $this->assertSame(15000.0, (float) $sale->net_total);
        $this->assertSame(1486.49, (float) $sale->tax_total);
        $this->assertSame(1486.49, (float) $sale->items->first()->tax_amount);
        $this->assertDatabaseHas('sale_taxes', [
            'sale_id' => $sale->sale_id,
            'tax_id' => $ppn->tax_id,
            'base_amount' => 13513.51,
            'tax_amount' => 1486.49,
        ]);
    }

    public function test_pajak_ditambahkan_di_atas_harga_bila_harga_belum_termasuk_pajak(): void
    {
        $this->owner->update(['prices_include_tax' => false]);
        $this->pasangPpn();

        $sale = $this->jual(1);

        $this->assertSame(1650.0, (float) $sale->tax_total);
        $this->assertSame(16650.0, (float) $sale->net_total);
        $this->assertSame(16650.0, (float) $sale->items->first()->line_total);
    }

    public function test_pajak_dihitung_setelah_diskon(): void
    {
        $this->owner->update(['prices_include_tax' => false]);
        $this->pasangPpn();
        $this->diskon(['discount_type' => 'AMOUNT', 'discount_value' => 5000]);

        $sale = $this->jual(1);

        // Dasar pengenaan 15.000 - 5.000 = 10.000; PPN 11% = 1.100.
        $this->assertSame(1100.0, (float) $sale->tax_total);
        $this->assertSame(11100.0, (float) $sale->net_total);
    }

    public function test_penjualan_bersih_di_laba_rugi_tidak_memuat_pajak(): void
    {
        $this->pasangPpn();
        $this->jual(1);

        $angka = app(FiscalPeriodFigures::class)->penjualan($this->periode);

        $this->assertSame(1486.49, $angka['tax_total']);
        $this->assertSame(13513.51, $angka['net_sales']);
    }

    public function test_produk_tanpa_pajak_tidak_berubah(): void
    {
        $sale = $this->jual(1);

        $this->assertSame(0.0, (float) $sale->tax_total);
        $this->assertSame(15000.0, (float) $sale->net_total);
        $this->assertDatabaseCount('sale_taxes', 0);
    }

    // -------------------------------------------------------------- pratinjau

    public function test_pratinjau_kasir_sama_dengan_nota_yang_diposting(): void
    {
        $this->pasangPpn();
        $this->diskon(['discount_value' => 10]);
        $antar = $this->produk('JSA-ANTAR');
        $baris = [['product_id' => $antar->product_id, 'uom_id' => $antar->uoms->first()->uom_id, 'qty' => 2]];

        $pratinjau = $this->actingAs($this->kasir())
            ->postJson('/kasir/hitung', ['lines' => $baris])
            ->assertOk()
            ->json();

        $sale = $this->jual(2);

        $this->assertEquals((float) $sale->net_total, $pratinjau['netTotal']);
        $this->assertEquals((float) $sale->discount_total, $pratinjau['discountTotal']);
        $this->assertEquals((float) $sale->tax_total, $pratinjau['taxTotal']);
    }

    public function test_pratinjau_menawarkan_diskon_nota_yang_memenuhi_syarat(): void
    {
        $nota = $this->diskon(['discount_scope' => 'TRANSACTION', 'discount_name' => 'Belanja 20rb', 'min_amount' => 20000]);
        $antar = $this->produk('JSA-ANTAR');
        $uom = $antar->uoms->first()->uom_id;

        $this->actingAs($this->kasir())
            ->postJson('/kasir/hitung', ['lines' => [['product_id' => $antar->product_id, 'uom_id' => $uom, 'qty' => 1]]])
            ->assertOk()
            ->assertJsonCount(0, 'transactionDiscounts');

        $this->actingAs($this->kasir())
            ->postJson('/kasir/hitung', ['lines' => [['product_id' => $antar->product_id, 'uom_id' => $uom, 'qty' => 2]]])
            ->assertOk()
            ->assertJsonPath('transactionDiscounts.0.id', $nota->discount_id)
            ->assertJsonPath('transactionDiscounts.0.amount', 3000);
    }

    // ------------------------------------------------------------------ retur

    public function test_refund_retur_mengikuti_harga_setelah_diskon(): void
    {
        $this->isiStok('RNS-045', 'PCS', 10, 2000);
        $this->diskon(['discount_value' => 20]);
        $sale = $this->jual(4, [], null, 'RNS-045', 'PCS'); // 10.000 - 20% = 8.000
        $item = $sale->items->first();

        $this->actingAs($this->manajer())->post('/retur/penjualan', [
            'sale_id' => $sale->sale_id,
            'transaction_date' => now()->toDateString(),
            'lines' => [['sale_item_id' => $item->sale_item_id, 'qty' => 1]],
        ])->assertSessionHasNoErrors();

        // Satu dari empat pcs: 8.000 / 4 = 2.000, bukan harga daftar 2.500.
        $this->assertDatabaseHas('sales_return_items', ['sale_item_id' => $item->sale_item_id, 'refund_amount' => 2000]);
    }
}

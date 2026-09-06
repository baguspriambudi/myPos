<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SalePoster;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Laporan per modul: penjualan, pembelian, persediaan, laba rugi, buku kas,
 * kartu stok, dan dashboard (PRD 17-19, Addendum 50, 59.4).
 *
 * Yang dijaga di sini bukan sekadar "halamannya terbuka", melainkan tiga hal
 * yang mudah rusak diam-diam: angkanya benar, periode yang sudah ditutup
 * membaca SNAPSHOT alih-alih menghitung ulang, dan kolom harga tidak bocor ke
 * peran yang tidak berhak melihatnya.
 */
class LaporanTest extends TestCase
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
    }

    // ------------------------------------------------------------- perkakas

    private function pemilik(): User
    {
        return User::where('username', 'bagus')->firstOrFail();
    }

    private function kasir(): User
    {
        return User::where('username', 'kasir')->firstOrFail();
    }

    private function gudang(): User
    {
        return User::where('username', 'gudang')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function satuan(Product $product, string $nama): ProductUom
    {
        return $product->uoms->firstWhere('uom_name', $nama);
    }

    private function isiStok(string $sku, string $uomName, float $qty, float $costBase): void
    {
        $product = $this->produk($sku);

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->periode,
            $this->pusat->branch_id,
            now()->toDateString(),
            $product,
            $this->satuan($product, $uomName),
            $qty,
            $costBase,
        ));
    }

    private function jual(string $sku, string $uom, float $qty): Sale
    {
        $product = $this->produk($sku);

        return app(SalePoster::class)->post($this->kasir(), $this->pusat, [
            'payment_type' => 'CASH',
            'lines' => [['product_id' => $product->product_id, 'uom_id' => $this->satuan($product, $uom)->uom_id, 'qty' => $qty]],
        ]);
    }

    /** Satu nota 2 pcs: omzet 5.000, HPP 4.000, laba kotor 1.000. */
    private function satuTransaksi(): void
    {
        $this->isiStok('RNS-045', 'PCS', 24, 2000);
        $this->jual('RNS-045', 'PCS', 2);
    }

    /** @return array<string, mixed> */
    private function props(string $url, ?User $user = null): array
    {
        $response = $this->actingAs($user ?? $this->pemilik())->get($url);
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    // --------------------------------------------------- laporan penjualan

    public function test_laporan_penjualan_merinci_omzet_hpp_dan_laba(): void
    {
        $this->satuTransaksi();

        $props = $this->props('/laporan/penjualan');

        $this->assertSame($this->periode->period_code, $props['period']['code']);
        $this->assertSame(1, $props['summary']['saleCount']);
        $this->assertSame(5000.0, $props['summary']['netSales']);
        $this->assertSame(4000.0, $props['summary']['cogs']);
        $this->assertSame(1000.0, $props['summary']['grossProfit']);
        $this->assertSame(5000.0, $props['summary']['payments']['CASH']);

        $produk = $props['products'][0];
        $this->assertSame('RNS-045', $produk['sku']);
        $this->assertSame(2.0, $produk['qty']);
        $this->assertSame(5000.0, $produk['netSales']);
        $this->assertSame(1000.0, $produk['grossProfit']);

        $this->assertSame(1, $props['daily'][0]['saleCount']);
        $this->assertSame(1, $props['cashiers'][0]['saleCount']);
    }

    public function test_nota_yang_dibatalkan_tidak_ikut_laporan_penjualan(): void
    {
        $this->satuTransaksi();
        $sale = Sale::firstOrFail();

        $this->actingAs($this->pemilik())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'salah input'])
            ->assertSessionHas('success');

        $props = $this->props('/laporan/penjualan');

        $this->assertSame(0, $props['summary']['saleCount']);
        $this->assertSame(0.0, $props['summary']['netSales']);
        $this->assertSame([], $props['products']);
    }

    public function test_kasir_dan_gudang_tidak_boleh_membuka_laporan_uang(): void
    {
        foreach (['/laporan/penjualan', '/laporan/pembelian', '/laporan/laba-rugi', '/laporan/kas'] as $url) {
            $this->actingAs($this->kasir())->get($url)->assertForbidden();
            $this->actingAs($this->gudang())->get($url)->assertForbidden();
        }
    }

    // ---------------------------------------------------------- laba rugi

    public function test_laba_rugi_periode_berjalan_dihitung_dari_buku_besar(): void
    {
        $this->satuTransaksi();

        $props = $this->props('/laporan/laba-rugi');

        $this->assertSame('berjalan', $props['figures']['source']);
        $this->assertSame(5000.0, $props['figures']['netSales']);
        $this->assertSame(4000.0, $props['figures']['cogs']);
        $this->assertSame(1000.0, $props['figures']['grossProfit']);
        $this->assertSame(1000.0, $props['figures']['netProfit']);
        $this->assertSame(44000.0, $props['figures']['inventory']['ending']);
        $this->assertSame(5000.0, $props['figures']['cash']['ending']);
    }

    /**
     * Inti gunanya menutup periode: laporan berhenti menghitung ulang.
     *
     * Setelah ditutup, movement yang disisipkan diam-diam tidak boleh lagi
     * menggeser angka periode itu.
     */
    public function test_laba_rugi_periode_tertutup_membaca_snapshot_bukan_menghitung_ulang(): void
    {
        $this->satuTransaksi();

        $this->actingAs($this->pemilik())
            ->post("/periode/{$this->periode->fiscal_period_id}/tutup")
            ->assertSessionHas('success');

        $props = $this->props('/laporan/laba-rugi');
        $this->assertSame('snapshot', $props['figures']['source']);
        $this->assertSame(1, $props['figures']['version']);
        $this->assertSame(1000.0, $props['figures']['netProfit']);

        // Sisipkan pergerakan langsung ke buku besar periode yang sudah beku.
        $movement = StockMovement::where('fiscal_period_id', $this->periode->fiscal_period_id)->firstOrFail();
        StockMovement::create([
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'branch_id' => $this->pusat->branch_id,
            'product_id' => $movement->product_id,
            'movement_date' => $this->periode->end_date->toDateString(),
            'movement_type' => StockMovement::TYPE_ADJUSTMENT_IN,
            'reference_type' => StockMovement::REF_ADJUSTMENT,
            'uom_id' => $movement->uom_id,
            'qty' => 5,
            'qty_base' => 5,
            'unit_cost' => 2000,
            'total_cost' => 10000,
            'sequence' => 999,
        ]);

        $sesudah = $this->props('/laporan/laba-rugi');
        $this->assertSame(1000.0, $sesudah['figures']['netProfit']);
        $this->assertSame(44000.0, $sesudah['figures']['inventory']['ending']);
    }

    // -------------------------------------------------------- buku kas

    public function test_buku_kas_menyusun_saldo_berjalan_tanpa_menghitung_kas_awal_shift(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 100000])->assertSessionHas('success');
        $this->satuTransaksi();

        $props = $this->props('/laporan/kas');

        $baris = collect($props['rows']);
        $awalShift = $baris->firstWhere('type', 'OPENING_CASH');
        $penjualan = $baris->firstWhere('type', 'CASH_SALE');

        // Kas awal shift tercatat di buku, tetapi tidak menggerakkan saldo.
        $this->assertFalse($awalShift['movesBalance']);
        $this->assertNull($awalShift['balance']);
        $this->assertSame(5000.0, $penjualan['balance']);
        $this->assertSame(5000.0, $props['summary']['ending']);
    }

    // ------------------------------------------------------ laporan pembelian

    public function test_laporan_pembelian_memisahkan_penerimaan_dan_faktur(): void
    {
        $pemasok = Supplier::firstOrFail();
        $rinso = $this->produk('RNS-045');

        // Penerimaan tidak menerima input harga: nilainya memakai harga
        // sementara dari saldo yang sudah ada (Addendum 58.2), jadi stoknya
        // perlu berharga lebih dulu supaya penerimaan ini bernilai.
        $this->isiStok('RNS-045', 'PCS', 5, 2000);

        $this->actingAs($this->pemilik())
            ->post('/penerimaan', [
                'supplier_id' => $pemasok->supplier_id,
                'receipt_date' => now()->toDateString(),
                'lines' => [[
                    'product_id' => $rinso->product_id,
                    'uom_id' => $this->satuan($rinso, 'PCS')->uom_id,
                    'qty' => 10,
                ]],
            ])
            ->assertSessionHas('success');

        $props = $this->props('/laporan/pembelian');

        $this->assertSame(1, $props['summary']['receiptCount']);
        $this->assertSame(20000.0, $props['summary']['receiptValue']);
        // Penerimaan belum menerbitkan utang; itu pekerjaan faktur.
        $this->assertSame(0, $props['summary']['invoiceCount']);
        $this->assertSame(1, $props['summary']['uninvoicedCount']);
        $this->assertSame(20000.0, $props['summary']['uninvoicedValue']);
        $this->assertCount(1, $props['uninvoiced']);
        $this->assertSame($pemasok->supplier_name, $props['suppliers'][0]['name']);
    }

    // ---------------------------------------------------- laporan persediaan

    public function test_laporan_persediaan_menampilkan_saldo_dan_nilai(): void
    {
        $this->satuTransaksi();

        $props = $this->props('/laporan/persediaan');

        $this->assertSame(1, $props['summary']['productCount']);
        $this->assertSame(44000.0, $props['summary']['totalValue']);
        $this->assertSame(0, $props['summary']['negativeCount']);

        $baris = $props['rows']['data'][0];
        $this->assertSame('RNS-045', $baris['sku']);
        $this->assertSame(22.0, $baris['qty']);
        $this->assertSame(2000.0, $baris['avgCost']);
        $this->assertSame(44000.0, $baris['value']);
    }

    public function test_kasir_melihat_saldo_persediaan_tanpa_harga(): void
    {
        $this->satuTransaksi();

        $props = $this->props('/laporan/persediaan', $this->kasir());

        $this->assertFalse($props['canSeeCost']);
        $this->assertNull($props['summary']['totalValue']);

        $baris = $props['rows']['data'][0];
        $this->assertSame(22.0, $baris['qty']);
        $this->assertNull($baris['avgCost']);
        $this->assertNull($baris['value']);
    }

    /**
     * Petugas gudang bertugas di gudang, bukan di toko.
     *
     * Laporan persediaannya tetap terbuka (Addendum 50) tetapi berisi saldo
     * LOKASINYA SENDIRI — bukan saldo toko. Ini yang membuat "boleh melihat
     * stok" tidak berarti "boleh melihat stok semua orang".
     */
    public function test_gudang_melihat_persediaan_lokasinya_sendiri(): void
    {
        $this->satuTransaksi();

        $props = $this->props('/laporan/persediaan', $this->gudang());

        $this->assertFalse($props['canSeeCost']);
        $this->assertNotSame($this->pusat->branch_id, $props['branch']['id']);
        $this->assertSame(0, $props['summary']['productCount']);
    }

    // -------------------------------------------------------- kartu stok

    public function test_kartu_stok_satu_produk_menyusun_saldo_berjalan(): void
    {
        $this->satuTransaksi();
        $rinso = $this->produk('RNS-045');

        $props = $this->props("/stok?produk={$rinso->product_id}");

        $this->assertSame($rinso->product_id, $props['productId']);
        $this->assertCount(2, $props['movements']);

        // Urut maju: masuk 24 lalu keluar 2, saldonya 24 kemudian 22.
        $this->assertSame(24.0, $props['movements'][0]['balance']);
        $this->assertSame(22.0, $props['movements'][1]['balance']);
        $this->assertSame(-2.0, $props['movements'][1]['qtyBase']);
    }

    public function test_kartu_stok_tanpa_produk_tidak_menampilkan_saldo_berjalan(): void
    {
        $this->satuTransaksi();

        $props = $this->props('/stok');

        $this->assertNull($props['productId']);
        $this->assertCount(2, $props['movements']);
        $this->assertNull($props['movements'][0]['balance']);
    }

    public function test_kasir_melihat_kartu_stok_tanpa_kolom_harga(): void
    {
        $this->satuTransaksi();

        $props = $this->props('/stok', $this->kasir());

        $this->assertFalse($props['canSeeCost']);
        $this->assertNull($props['movements'][0]['unitCost']);
        $this->assertNull($props['summary']['totalValue']);
    }

    // ---------------------------------------------------------- dashboard

    public function test_dashboard_menampilkan_angka_hari_ini(): void
    {
        $this->satuTransaksi();

        $props = $this->props('/dashboard');

        $this->assertTrue($props['canSeeMoney']);
        $this->assertSame($this->pusat->branch_name, $props['branch']['name']);
        $this->assertSame($this->periode->period_code, $props['period']['code']);
        $this->assertSame(1, $props['today']['saleCount']);
        $this->assertSame(5000.0, $props['today']['omzet']);
        $this->assertSame(4000.0, $props['today']['cogs']);
        $this->assertSame(1000.0, $props['today']['grossProfit']);
        $this->assertCount(14, $props['trend']);
        $this->assertSame('RNS-045', Product::where('product_name', $props['topProducts'][0]['name'])->value('sku'));
    }

    public function test_dashboard_kasir_tanpa_angka_uang(): void
    {
        $this->satuTransaksi();

        $props = $this->props('/dashboard', $this->kasir());

        $this->assertFalse($props['canSeeMoney']);
        $this->assertSame(1, $props['today']['saleCount']);
        $this->assertNull($props['today']['omzet']);
        $this->assertNull($props['today']['grossProfit']);
        $this->assertSame([], $props['trend']);
        $this->assertSame([], $props['topProducts']);
        $this->assertNull($props['recentSales'][0]['paidTotal']);
    }

    public function test_dashboard_menyorot_hal_yang_menggantung(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 50000]);

        $props = $this->props('/dashboard');

        $this->assertSame(1, $props['attention']['openShifts']);
        $this->assertSame(0, $props['attention']['periodMissing']);
    }

    public function test_laporan_lama_dialihkan_ke_laba_rugi(): void
    {
        $this->actingAs($this->pemilik())->get('/laporan')->assertRedirect('/laporan/laba-rugi');
    }
}

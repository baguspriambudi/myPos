<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Sale;
use App\Models\StockLot;
use App\Models\User;
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
 * Posting nota penjualan: nomor, harga, uang, stok, dan HPP (PRD 8, 14, 34).
 *
 * Yang dijaga di sini bukan hanya "notanya tersimpan", melainkan bahwa keempat
 * akibatnya bergerak bersama — nota tanpa stok berkurang berarti barang hilang
 * dari kartu stok, stok berkurang tanpa nota berarti uangnya tidak tercatat.
 */
class SalePostingTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    private Branch $pusat;

    private User $kasir;

    private FiscalPeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);

        $this->owner = Owner::firstOrFail();
        $this->pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
        $this->kasir = User::where('username', 'kasir')->firstOrFail();
        $this->periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();
    }

    // ------------------------------------------------------------- perkakas

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function satuan(Product $product, string $nama): ProductUom
    {
        return $product->uoms->firstWhere('uom_name', $nama);
    }

    /** Isi stok lewat jalur resmi buku stok, bukan insert langsung. */
    private function isiStok(string $sku, string $uomName, float $qty, float $costBase, ?string $tanggal = null): void
    {
        $product = $this->produk($sku);

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->periode,
            $this->pusat->branch_id,
            $tanggal ?? now()->toDateString(),
            $product,
            $this->satuan($product, $uomName),
            $qty,
            $costBase,
        ));
    }

    /** @param array<int, array{sku: string, uom: string, qty: float}> $baris */
    private function jual(array $baris, array $extra = []): Sale
    {
        $lines = [];

        foreach ($baris as $b) {
            $product = $this->produk($b['sku']);
            $lines[] = [
                'product_id' => $product->product_id,
                'uom_id' => $this->satuan($product, $b['uom'])->uom_id,
                'qty' => $b['qty'],
            ];
        }

        return app(SalePoster::class)->post(
            $this->kasir,
            $this->pusat,
            array_merge(['payment_type' => 'CASH', 'lines' => $lines], $extra),
        );
    }

    // ------------------------------------------------------------ nota utuh

    public function test_nota_tunai_membentuk_nomor_stok_keluar_dan_hpp(): void
    {
        $this->isiStok('RNS-045', 'PCS', 24, 2000);

        $sale = $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 2]]);

        $this->assertSame("INV/BR01/{$this->periode->period_code}/000001", $sale->sale_number);
        $this->assertSame(Sale::STATUS_POSTED, $sale->status);
        $this->assertSame(5000.0, (float) $sale->net_total);
        $this->assertSame(5000.0, (float) $sale->paid_total);

        $item = $sale->items->first();
        $this->assertSame(2500.0, (float) $item->unit_price);
        $this->assertSame(2.0, (float) $item->qty_base);
        // HPP memakai harga perolehan, bukan harga jual.
        $this->assertSame(2000.0, (float) $item->cost_at_sale);
        $this->assertSame(4000.0, (float) $item->cogs_total);

        $this->assertSame(22.0, app(StockLedger::class)->onHand($this->pusat->branch_id, $item->product_id));

        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'SALES',
            'reference_id' => $sale->sale_id,
            'movement_type' => 'SALES',
            'qty_base' => -2,
        ]);

        $this->assertDatabaseHas('payments', ['payment_type' => 'CASH', 'amount' => 5000, 'status' => 'POSTED']);
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->sale_id, 'amount' => 5000]);
    }

    public function test_pelanggan_member_mendapat_harga_daftar_member(): void
    {
        $this->isiStok('RNS-045', 'PCS', 24, 2000);
        $member = Customer::where('customer_name', 'Toko Berkah Jaya')->firstOrFail();

        $sale = $this->jual(
            [['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 1]],
            ['customer_id' => $member->customer_id],
        );

        $this->assertSame(2200.0, (float) $sale->items->first()->unit_price);
    }

    public function test_tanpa_memilih_pelanggan_nota_jatuh_ke_pelanggan_tunai_bawaan(): void
    {
        $this->isiStok('RNS-045', 'PCS', 24, 2000);

        $sale = $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 1]]);
        $bawaan = Customer::where('is_default', true)->firstOrFail();

        $this->assertSame($bawaan->customer_id, $sale->customer_id);
        $this->assertSame(2500.0, (float) $sale->items->first()->unit_price);
    }

    // ---------------------------------------------------------- pembulatan

    public function test_pembulatan_tunai_hanya_mengubah_uang_yang_diterima(): void
    {
        $this->owner->update(['payment_rounding_unit' => 1000, 'payment_rounding_mode' => 'DOWN']);
        $this->isiStok('RNS-045', 'PCS', 24, 2000);

        $sale = $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 1]]);

        // Nilai penjualan resmi tidak boleh ikut turun — kalau ikut, omzet dan
        // dasar pajak mengecil padahal barangnya terjual seharga penuh.
        $this->assertSame(2500.0, (float) $sale->net_total);
        $this->assertSame(-500.0, (float) $sale->rounding_amount);
        $this->assertSame(2000.0, (float) $sale->paid_total);
        $this->assertDatabaseHas('payments', ['amount' => 2000]);
    }

    public function test_pembayaran_non_tunai_dibayar_persis_tanpa_pembulatan(): void
    {
        $this->owner->update(['payment_rounding_unit' => 1000, 'payment_rounding_mode' => 'DOWN']);
        $this->isiStok('RNS-045', 'PCS', 24, 2000);

        $sale = $this->jual(
            [['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 1]],
            ['payment_type' => 'QRIS'],
        );

        $this->assertSame(0.0, (float) $sale->rounding_amount);
        $this->assertSame(2500.0, (float) $sale->paid_total);
    }

    public function test_uang_tunai_kurang_dari_tagihan_ditolak(): void
    {
        $this->isiStok('RNS-045', 'PCS', 24, 2000);

        $this->expectException(ValidationException::class);

        $this->jual(
            [['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 2]],
            ['cash_received' => 4000],
        );
    }

    // ---------------------------------------------------------------- stok

    public function test_stok_tidak_cukup_ditolak_dan_tidak_menyisakan_nota(): void
    {
        $this->isiStok('RNS-045', 'PCS', 1, 2000);

        try {
            $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 5]]);
            $this->fail('Penjualan melebihi stok seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }

        // Seluruh isi transaksi harus ikut batal, termasuk nomor yang sempat
        // diambil — nota separuh jadi jauh lebih berbahaya daripada gagal.
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(1.0, app(StockLedger::class)->onHand($this->pusat->branch_id, $this->produk('RNS-045')->product_id));
    }

    public function test_jasa_terjual_tanpa_menyentuh_stok(): void
    {
        $sale = $this->jual([['sku' => 'JSA-ANTAR', 'uom' => 'KALI', 'qty' => 1]]);

        $this->assertSame(15000.0, (float) $sale->net_total);
        $this->assertSame(0.0, (float) $sale->items->first()->cogs_total);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_fifo_mengambil_lot_terlama_lebih_dulu(): void
    {
        $this->owner->update(['costing_method' => 'FIFO']);

        $this->isiStok('RNS-045', 'PCS', 10, 2000, $this->periode->start_date->toDateString());
        $this->isiStok('RNS-045', 'PCS', 10, 2500);

        $sale = $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 12]]);

        // 10 pcs dari lot Rp2.000 + 2 pcs dari lot Rp2.500.
        $this->assertSame(25000.0, (float) $sale->items->first()->cogs_total);

        $lots = StockLot::orderBy('lot_id')->get();
        $this->assertSame(StockLot::STATUS_EXHAUSTED, $lots[0]->status);
        $this->assertSame(8.0, (float) $lots[1]->qty_remaining);
        $this->assertDatabaseCount('stock_lot_consumptions', 2);
    }

    public function test_hpp_rata_rata_memakai_saldo_rata_rata_lokasi(): void
    {
        $this->isiStok('RNS-045', 'PCS', 10, 2000);
        $this->isiStok('RNS-045', 'PCS', 10, 3000);

        // Rata-rata bergerak: (10×2000 + 10×3000) / 20 = 2500.
        $sale = $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 4]]);

        $this->assertSame(10000.0, (float) $sale->items->first()->cogs_total);
    }

    public function test_satuan_besar_mengurangi_stok_dalam_satuan_dasar(): void
    {
        $this->isiStok('RNS-045', 'DUS', 5, 2000); // 5 dus = 60 pcs

        $sale = $this->jual([['sku' => 'RNS-045', 'uom' => 'DUS', 'qty' => 1]]);
        $item = $sale->items->first();

        $this->assertSame(20000.0, (float) $item->unit_price);
        $this->assertSame(12.0, (float) $item->qty_base);
        $this->assertSame(48.0, app(StockLedger::class)->onHand($this->pusat->branch_id, $item->product_id));
    }

    // ------------------------------------------------------------- validasi

    public function test_produk_bukan_timbangan_menolak_qty_pecahan(): void
    {
        $this->isiStok('RNS-045', 'PCS', 24, 2000);

        $this->expectException(ValidationException::class);

        $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 1.5]]);
    }

    public function test_produk_timbangan_menerima_qty_pecahan(): void
    {
        $this->isiStok('GLA-CRH', 'KG', 10, 12000);

        $sale = $this->jual([['sku' => 'GLA-CRH', 'uom' => 'KG', 'qty' => 1.5]]);

        $this->assertSame(22500.0, (float) $sale->net_total);
        $this->assertSame(8.5, app(StockLedger::class)->onHand($this->pusat->branch_id, $this->produk('GLA-CRH')->product_id));
    }

    /**
     * Jebakan yang mudah menggigit di timbangan: tingkat harga terkecil gula
     * mulai dari 1 KG, sehingga 0,5 KG tidak tercakup tingkat mana pun dan
     * ditolak — bukan dihitung setengah harga.
     *
     * Ini memang perilaku yang dikehendaki (Addendum 39.12): sistem tidak
     * pernah mengarang harga. Yang harus dilakukan toko adalah menambah tingkat
     * dengan min_qty lebih kecil, misalnya 0,25 KG.
     */
    public function test_pecahan_di_bawah_tingkat_harga_terkecil_ditolak(): void
    {
        $this->isiStok('GLA-CRH', 'KG', 10, 12000);

        try {
            $this->jual([['sku' => 'GLA-CRH', 'uom' => 'KG', 'qty' => 0.5]]);
            $this->fail('Qty di bawah tingkat harga terkecil seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('belum punya harga', $e->getMessage());
        }
    }

    public function test_aset_tidak_dapat_dijual_dari_kasir(): void
    {
        $this->expectException(ValidationException::class);

        $this->jual([['sku' => 'AST-FRZ', 'uom' => 'UNIT', 'qty' => 1]]);
    }

    public function test_gudang_tidak_dapat_menerbitkan_nota(): void
    {
        $gudang = Branch::where('branch_code', 'WH01')->firstOrFail();
        $produk = $this->produk('JSA-ANTAR');

        $this->expectException(ValidationException::class);

        app(SalePoster::class)->post($this->kasir, $gudang, [
            'payment_type' => 'CASH',
            'lines' => [[
                'product_id' => $produk->product_id,
                'uom_id' => $this->satuan($produk, 'KALI')->uom_id,
                'qty' => 1,
            ]],
        ]);
    }

    public function test_periode_tertutup_menolak_nota_baru(): void
    {
        $this->periode->update(['status' => 'CLOSED']);

        try {
            $this->jual([['sku' => 'JSA-ANTAR', 'uom' => 'KALI', 'qty' => 1]]);
            $this->fail('Periode tertutup seharusnya menolak transaksi.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('CLOSED', $e->getMessage());
        }

        $this->assertDatabaseCount('sales', 0);
    }

    // ---------------------------------------------------------- idempotensi

    public function test_percobaan_ulang_dengan_uuid_sama_tidak_membuat_nota_kedua(): void
    {
        $uuid = '11111111-2222-3333-4444-555555555555';

        $pertama = $this->jual([['sku' => 'JSA-ANTAR', 'uom' => 'KALI', 'qty' => 1]], ['local_uuid' => $uuid]);
        $kedua = $this->jual([['sku' => 'JSA-ANTAR', 'uom' => 'KALI', 'qty' => 1]], ['local_uuid' => $uuid]);

        $this->assertSame($pertama->sale_id, $kedua->sale_id);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_nomor_nota_berurut_per_lokasi_dan_tidak_dipakai_ulang(): void
    {
        $satu = $this->jual([['sku' => 'JSA-ANTAR', 'uom' => 'KALI', 'qty' => 1]]);
        $dua = $this->jual([['sku' => 'JSA-ANTAR', 'uom' => 'KALI', 'qty' => 1]]);

        $this->assertSame("INV/BR01/{$this->periode->period_code}/000001", $satu->sale_number);
        $this->assertSame("INV/BR01/{$this->periode->period_code}/000002", $dua->sale_number);

        // Nomor pembayaran berjalan pada penghitung sendiri.
        $this->assertDatabaseHas('payments', ['payment_number' => "PAY/BR01/{$this->periode->period_code}/000001"]);
    }
}

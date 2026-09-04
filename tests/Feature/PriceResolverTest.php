<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Unit;
use App\Services\PriceResolver;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resolusi harga jual bertingkat (Addendum 39.11).
 *
 * Daftar harga adalah satu-satunya sumber harga; tidak ada lagi harga standar
 * di master produk.
 */
class PriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private PriceResolver $resolver;

    private Product $rinso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);

        $this->resolver = app(PriceResolver::class);
        $this->rinso = Product::where('sku', 'RNS-045')->with('uoms')->firstOrFail();
    }

    private function uom(string $name): ProductUom
    {
        return $this->rinso->uoms->firstWhere('uom_name', $name);
    }

    private function harga(string $uomName, float $qty, ?int $customerId = null): array
    {
        return $this->resolver->resolve(
            $this->rinso->owner_id,
            $this->rinso->product_id,
            $this->uom($uomName)->uom_id,
            $qty,
            null,
            $customerId,
        );
    }

    public function test_harga_ecer_dan_harga_dus_berdiri_sendiri(): void
    {
        // Inti permintaannya: satuan berbeda, harga berbeda, bukan hasil konversi.
        $this->assertSame(2500.0, $this->harga('PCS', 1)['unit_price']);
        $this->assertSame(20000.0, $this->harga('DUS', 1)['unit_price']);
    }

    public function test_tingkat_harga_baru_berlaku_setelah_ambang_terpenuhi(): void
    {
        $this->assertSame(2500.0, $this->harga('PCS', 5)['unit_price'], '5 pcs belum mencapai ambang 6');
        $this->assertSame(2300.0, $this->harga('PCS', 6)['unit_price'], '6 pcs tepat di ambang');
        $this->assertSame(2300.0, $this->harga('PCS', 20)['unit_price'], 'di atas ambang tetap memakai tingkat itu');
    }

    public function test_min_qty_dibandingkan_terhadap_satuan_baris_bukan_qty_base(): void
    {
        // 1 dus = 12 pcs. Kalau min_qty salah dibandingkan terhadap qty base,
        // baris DUS ini akan keliru memakai tingkat "PCS min 6".
        $hasil = $this->harga('DUS', 1);

        $this->assertSame(20000.0, $hasil['unit_price']);
        $this->assertSame(1.0, $hasil['tier_min_qty']);
    }

    public function test_daftar_harga_member_menang_atas_daftar_harga_umum(): void
    {
        $berkah = Customer::where('customer_name', 'Toko Berkah Jaya')->firstOrFail();

        $this->assertSame(2200.0, $this->harga('PCS', 1, $berkah->customer_id)['unit_price']);
        $this->assertSame(19000.0, $this->harga('DUS', 1, $berkah->customer_id)['unit_price']);
        $this->assertSame(18500.0, $this->harga('DUS', 5, $berkah->customer_id)['unit_price']);
    }

    public function test_pelanggan_bukan_member_memakai_harga_umum(): void
    {
        $umum = Customer::where('customer_name', 'Umum / Tunai')->firstOrFail();

        $this->assertSame(20000.0, $this->harga('DUS', 1, $umum->customer_id)['unit_price']);
    }

    /**
     * Daftar member boleh dibatasi satu lokasi. Yang khusus lokasi transaksi
     * harus menang, walau prioritasnya lebih rendah — kekhususan lokasi adalah
     * kandidat yang lebih sempit, dan prioritas hanya mengurutkan di dalam satu
     * tingkat kekhususan.
     */
    public function test_daftar_member_khusus_lokasi_menang_atas_member_seluruh_lokasi(): void
    {
        $pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
        $berkah = Customer::where('customer_name', 'Toko Berkah Jaya')->firstOrFail();

        $lokal = PriceList::create([
            'owner_id' => $this->rinso->owner_id,
            'branch_id' => $pusat->branch_id,
            'price_list_code' => 'PL-MBR-BR01',
            'price_list_name' => 'Harga Member Cabang Pusat',
            'member_only' => true,
            'priority' => 1, // di bawah PL-GROSIR yang priority 10
        ]);

        PriceListItem::create([
            'price_list_id' => $lokal->price_list_id,
            'product_id' => $this->rinso->product_id,
            'uom_id' => $this->uom('PCS')->uom_id,
            'min_qty' => 1,
            'unit_price' => 2100,
        ]);

        $diPusat = fn (?int $branchId) => $this->resolver->resolve(
            $this->rinso->owner_id,
            $this->rinso->product_id,
            $this->uom('PCS')->uom_id,
            1,
            $branchId,
            $berkah->customer_id,
        )['unit_price'];

        $this->assertSame(2100.0, $diPusat($pusat->branch_id), 'di cabang pusat memakai daftar member cabang itu');
        $this->assertSame(2200.0, $diPusat(null), 'di luar cabang itu kembali ke daftar member seluruh lokasi');
    }

    public function test_produk_di_luar_daftar_member_turun_ke_daftar_umum(): void
    {
        // Daftar grosir hanya memuat sebagian produk. Yang tidak ada di sana
        // harus tetap bisa dijual dengan harga umum, bukan kehilangan harga.
        $berkah = Customer::where('customer_name', 'Toko Berkah Jaya')->firstOrFail();
        $telur = Product::where('sku', 'TLR-AYM')->with('uoms')->firstOrFail();
        $kg = $telur->uoms->firstWhere('uom_name', 'KG');

        $hasil = $this->resolver->resolve(
            $telur->owner_id,
            $telur->product_id,
            $kg->uom_id,
            1,
            null,
            $berkah->customer_id,
        );

        $umum = PriceList::where('price_list_code', 'PL-UMUM')->firstOrFail();

        $this->assertSame(PriceResolver::SOURCE_PRICE_LIST, $hasil['source']);
        $this->assertSame($umum->price_list_id, $hasil['price_list_id']);
        $this->assertSame(28000.0, $hasil['unit_price']);
    }

    public function test_daftar_harga_lokasi_turun_ke_daftar_seluruh_lokasi(): void
    {
        // Daftar promo satu lokasi hanya memuat Rinso. Produk lain di lokasi itu
        // tetap memakai harga seluruh lokasi.
        $pusat = Branch::where('branch_code', 'BR01')->firstOrFail();

        $promo = PriceList::create([
            'owner_id' => $this->rinso->owner_id,
            'branch_id' => $pusat->branch_id,
            'price_list_code' => 'PL-PROMO',
            'price_list_name' => 'Promo Cabang Pusat',
            'priority' => 50,
        ]);

        PriceListItem::create([
            'price_list_id' => $promo->price_list_id,
            'product_id' => $this->rinso->product_id,
            'uom_id' => $this->uom('PCS')->uom_id,
            'min_qty' => 1,
            'unit_price' => 1999,
        ]);

        $hargaRinso = $this->resolver->resolve(
            $this->rinso->owner_id,
            $this->rinso->product_id,
            $this->uom('PCS')->uom_id,
            1,
            $pusat->branch_id,
        );

        $aqua = Product::where('sku', 'AQU-600')->with('uoms')->firstOrFail();

        $hargaAqua = $this->resolver->resolve(
            $aqua->owner_id,
            $aqua->product_id,
            $aqua->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            1,
            $pusat->branch_id,
        );

        $this->assertSame(1999.0, $hargaRinso['unit_price'], 'promo lokasi menang untuk produk yang dimuatnya');
        $this->assertSame(4000.0, $hargaAqua['unit_price'], 'produk di luar promo turun ke harga seluruh lokasi');
    }

    public function test_produk_tanpa_harga_tidak_boleh_dijual(): void
    {
        $produk = Product::create([
            'owner_id' => $this->rinso->owner_id,
            'product_name' => 'Produk Belum Berharga',
            'sku' => 'BLM-001',
        ]);

        $uom = ProductUom::create([
            'product_id' => $produk->product_id,
            'unit_id' => Unit::where('unit_name', 'PCS')->firstOrFail()->unit_id,
            'uom_name' => 'PCS',
            'uom_type' => ProductUom::TYPE_BASE,
            'conversion_factor' => 1,
            'is_default' => true,
        ]);

        $hasil = $this->resolver->resolve($produk->owner_id, $produk->product_id, $uom->uom_id, 1);

        $this->assertSame(PriceResolver::SOURCE_NONE, $hasil['source']);
        $this->assertNull($hasil['unit_price'], 'tidak boleh jatuh ke Rp0 — kasir harus menolak baris ini');
        $this->assertFalse($this->resolver->isSellable($produk->owner_id, $produk->product_id, $uom->uom_id));
    }

    public function test_tangga_harga_dikembalikan_terurut(): void
    {
        $tiers = $this->resolver->tiersFor(
            $this->rinso->owner_id,
            $this->rinso->product_id,
            $this->uom('PCS')->uom_id,
        );

        $this->assertSame([
            ['min_qty' => 1.0, 'unit_price' => 2500.0],
            ['min_qty' => 6.0, 'unit_price' => 2300.0],
        ], $tiers);
    }
}

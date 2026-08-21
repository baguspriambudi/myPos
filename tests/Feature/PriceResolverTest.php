<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUom;
use App\Services\PriceResolver;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resolusi harga jual bertingkat (Addendum 39.11).
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

    public function test_daftar_harga_pelanggan_menang_atas_daftar_harga_umum(): void
    {
        $berkah = Customer::where('customer_name', 'Toko Berkah Jaya')->firstOrFail();

        $this->assertSame(2200.0, $this->harga('PCS', 1, $berkah->customer_id)['unit_price']);
        $this->assertSame(19000.0, $this->harga('DUS', 1, $berkah->customer_id)['unit_price']);
        $this->assertSame(18500.0, $this->harga('DUS', 5, $berkah->customer_id)['unit_price']);
    }

    public function test_pelanggan_tanpa_daftar_harga_memakai_harga_umum(): void
    {
        $umum = Customer::where('customer_name', 'Umum / Tunai')->firstOrFail();

        $this->assertSame(20000.0, $this->harga('DUS', 1, $umum->customer_id)['unit_price']);
    }

    public function test_produk_di_luar_daftar_harga_turun_ke_harga_standar(): void
    {
        // Tidak boleh melompat ke daftar harga lain — kalau melompat, harga
        // bisa terambil dari daftar yang tidak diniatkan (Addendum 39.11).
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

        $this->assertSame('STANDARD', $hasil['source']);
        $this->assertNull($hasil['price_list_id']);
        $this->assertSame(28000.0, $hasil['unit_price']);
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

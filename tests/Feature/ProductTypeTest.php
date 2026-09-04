<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\PriceResolver;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jenis produk: barang dagang, jasa, aset, produksi (Addendum 39.14).
 *
 * Yang dijaga di sini dua hal, keduanya diturunkan dari jenisnya: siapa yang
 * boleh punya harga jual, dan siapa yang punya stok.
 */
class ProductTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);
    }

    private function pemilik(): User
    {
        return User::where('username', 'bagus')->firstOrFail();
    }

    private function unit(string $nama): Unit
    {
        return Unit::where('unit_name', $nama)->firstOrFail();
    }

    /** Payload produk yang sah, boleh ditimpa sebagian. */
    private function produk(array $ubah = []): array
    {
        return array_replace([
            'product_name' => 'Produk Baru',
            'sku' => 'BARU-001',
            'barcode' => null,
            'category_id' => null,
            'product_type' => Product::TYPE_TRADE,
            'is_weighted' => false,
            'active' => true,
            'uoms' => [
                ['id' => null, 'unit_id' => $this->unit('PCS')->unit_id, 'uom_type' => 'BASE', 'conversion_factor' => 1, 'active' => true],
            ],
        ], $ubah);
    }

    // ----------------------------------------------------------- dasar jenis

    public function test_produk_tanpa_jenis_dianggap_barang_dagang(): void
    {
        // Seluruh produk lama dibuat sebelum kolom ini ada; bawaannya harus
        // barang dagang, bukan nilai kosong yang membuat mereka hilang dari
        // daftar harga.
        $rinso = Product::where('sku', 'RNS-045')->firstOrFail();

        $this->assertSame(Product::TYPE_TRADE, $rinso->product_type);
        $this->assertTrue($rinso->canSell());
        $this->assertTrue($rinso->tracksStock());
    }

    public function test_jasa_boleh_dijual_tetapi_tidak_punya_stok(): void
    {
        $jasa = Product::where('sku', 'JSA-ANTAR')->firstOrFail();

        $this->assertSame(Product::TYPE_SERVICE, $jasa->product_type);
        $this->assertTrue($jasa->canSell(), 'jasa tetap dijual');
        $this->assertFalse($jasa->tracksStock(), 'jasa tidak berwujud, tidak ada yang berkurang');
    }

    public function test_aset_punya_stok_tetapi_tidak_dijual(): void
    {
        $aset = Product::where('sku', 'AST-FRZ')->firstOrFail();

        $this->assertSame(Product::TYPE_ASSET, $aset->product_type);
        $this->assertFalse($aset->canSell());
        $this->assertTrue($aset->tracksStock());
    }

    public function test_scope_memisahkan_yang_dijual_dari_yang_berstok(): void
    {
        $dijual = Product::sellable()->pluck('sku');
        $berstok = Product::stocked()->pluck('sku');

        $this->assertContains('JSA-ANTAR', $dijual);
        $this->assertNotContains('AST-FRZ', $dijual);

        $this->assertContains('AST-FRZ', $berstok);
        $this->assertNotContains('JSA-ANTAR', $berstok);
    }

    // ------------------------------------------------------- aturan di layar

    public function test_jasa_tidak_boleh_punya_konversi_satuan(): void
    {
        $this->actingAs($this->pemilik())
            ->post('/master/produk', $this->produk([
                'product_type' => Product::TYPE_SERVICE,
                'uoms' => [
                    ['id' => null, 'unit_id' => $this->unit('KALI')->unit_id, 'uom_type' => 'BASE', 'conversion_factor' => 1, 'active' => true],
                    ['id' => null, 'unit_id' => $this->unit('PAK')->unit_id, 'uom_type' => 'PACK', 'conversion_factor' => 10, 'active' => true],
                ],
            ]))
            ->assertSessionHasErrors('uoms');

        $this->assertDatabaseMissing('products', ['sku' => 'BARU-001']);
    }

    public function test_jasa_bersatuan_tunggal_diterima(): void
    {
        $this->actingAs($this->pemilik())
            ->post('/master/produk', $this->produk([
                'product_name' => 'Servis Kompor',
                'sku' => 'JSA-KMP',
                'product_type' => Product::TYPE_SERVICE,
                'uoms' => [
                    ['id' => null, 'unit_id' => $this->unit('KALI')->unit_id, 'uom_type' => 'BASE', 'conversion_factor' => 1, 'active' => true],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Product::where('sku', 'JSA-KMP')->firstOrFail()->tracksStock());
    }

    public function test_aset_tidak_dapat_diberi_harga_jual(): void
    {
        $umum = PriceList::where('price_list_code', 'PL-UMUM')->firstOrFail();
        $aset = Product::where('sku', 'AST-FRZ')->with('uoms')->firstOrFail();

        $this->actingAs($this->pemilik())
            ->post("/master/harga/{$umum->price_list_id}/tingkat", [
                'product_id' => $aset->product_id,
                'uom_id' => $aset->uoms->first()->uom_id,
                'min_qty' => 1,
                'unit_price' => 2500000,
                'active' => true,
            ])
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseMissing('price_list_items', ['product_id' => $aset->product_id]);
    }

    public function test_jasa_dapat_diberi_harga_jual_seperti_barang(): void
    {
        // Daftar harga tetap satu-satunya sumber harga, apa pun jenis produknya.
        $jasa = Product::where('sku', 'JSA-ANTAR')->with('uoms')->firstOrFail();

        $hasil = app(PriceResolver::class)->resolve(
            $jasa->owner_id,
            $jasa->product_id,
            $jasa->uoms->first()->uom_id,
            1,
        );

        $this->assertSame(15000.0, $hasil['unit_price']);
    }

    public function test_produk_yang_masih_berharga_tidak_dapat_diubah_menjadi_aset(): void
    {
        // Kalau boleh, baris harganya menggantung dan kasir tetap menemukan
        // harga untuk barang yang seharusnya sudah tidak dijual.
        $rinso = Product::where('sku', 'RNS-045')->with('uoms')->firstOrFail();

        $this->actingAs($this->pemilik())
            ->put("/master/produk/{$rinso->product_id}", $this->produk([
                'product_name' => $rinso->product_name,
                'sku' => $rinso->sku,
                'barcode' => $rinso->barcode,
                'product_type' => Product::TYPE_ASSET,
                'uoms' => $rinso->uoms->map(fn ($u) => [
                    'id' => $u->uom_id,
                    'unit_id' => $u->unit_id,
                    'uom_type' => $u->uom_type,
                    'conversion_factor' => (float) $u->conversion_factor,
                    'active' => true,
                ])->all(),
            ]))
            ->assertSessionHasErrors('product_type');

        $this->assertSame(Product::TYPE_TRADE, $rinso->fresh()->product_type);
    }

    public function test_produk_tanpa_harga_bebas_berpindah_jenis(): void
    {
        $aset = Product::where('sku', 'AST-FRZ')->with('uoms')->firstOrFail();

        $this->actingAs($this->pemilik())
            ->put("/master/produk/{$aset->product_id}", $this->produk([
                'product_name' => $aset->product_name,
                'sku' => $aset->sku,
                'barcode' => null,
                'product_type' => Product::TYPE_PRODUCTION,
                'uoms' => [
                    ['id' => $aset->uoms->first()->uom_id, 'unit_id' => $aset->uoms->first()->unit_id, 'uom_type' => 'BASE', 'conversion_factor' => 1, 'active' => true],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(Product::TYPE_PRODUCTION, $aset->fresh()->product_type);
    }
}

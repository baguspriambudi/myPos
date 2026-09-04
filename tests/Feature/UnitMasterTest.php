<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Master satuan: nama satuan hidup di satu tempat, dikunci begitu dipakai,
 * dan layar produk hanya boleh memilih dari daftar itu.
 */
class UnitMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([OwnerSeeder::class, CatalogSeeder::class]);
    }

    private function pemilik(): User
    {
        return User::where('username', 'bagus')->firstOrFail();
    }

    private function satuan(string $nama): Unit
    {
        return Unit::where('unit_name', $nama)->firstOrFail();
    }

    /** Payload produk yang sah, boleh ditimpa sebagian. */
    private function produkBaru(array $ubah = []): array
    {
        return array_replace([
            'product_name' => 'Produk Uji',
            'sku' => 'UJI-001',
            'barcode' => null,
            'category_id' => null,
            'product_type' => Product::TYPE_TRADE,
            'is_weighted' => false,
            'active' => true,
            'uoms' => [
                ['id' => null, 'unit_id' => $this->satuan('PCS')->unit_id, 'uom_type' => 'BASE', 'conversion_factor' => 1],
                ['id' => null, 'unit_id' => $this->satuan('DUS')->unit_id, 'uom_type' => 'PACK', 'conversion_factor' => 12],
            ],
        ], $ubah);
    }

    // ------------------------------------------------------------ master satuan

    public function test_nama_satuan_yang_sudah_dipakai_produk_tidak_dapat_diubah(): void
    {
        $dus = $this->satuan('DUS'); // dipakai seeder pada beberapa produk

        $this->actingAs($this->pemilik())
            ->put("/master/satuan/{$dus->unit_id}", [
                'unit_name' => 'KARTON',
                'description' => $dus->description,
                'active' => true,
            ])
            ->assertSessionHasErrors('unit_name');

        $this->assertSame('DUS', $dus->fresh()->unit_name);
    }

    public function test_satuan_terpakai_tetap_boleh_dinonaktifkan(): void
    {
        $dus = $this->satuan('DUS');

        $this->actingAs($this->pemilik())
            ->put("/master/satuan/{$dus->unit_id}", [
                'unit_name' => 'DUS',
                'description' => 'Kardus besar',
                'active' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($dus->fresh()->active);
    }

    public function test_satuan_yang_belum_dipakai_masih_bebas_diubah_dan_dihapus(): void
    {
        $botol = $this->satuan('BOTOL'); // ada di master, belum dipakai produk

        $this->actingAs($this->pemilik())
            ->put("/master/satuan/{$botol->unit_id}", [
                'unit_name' => 'botol besar',
                'description' => null,
                'active' => true,
            ])
            ->assertSessionHasNoErrors();

        // Nama satuan selalu disimpan huruf besar.
        $this->assertSame('BOTOL BESAR', $botol->fresh()->unit_name);

        $this->actingAs($this->pemilik())->delete("/master/satuan/{$botol->unit_id}");

        $this->assertDatabaseMissing('units', ['unit_id' => $botol->unit_id]);
    }

    public function test_satuan_terpakai_tidak_dapat_dihapus(): void
    {
        $pcs = $this->satuan('PCS');

        $this->actingAs($this->pemilik())
            ->delete("/master/satuan/{$pcs->unit_id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('units', ['unit_id' => $pcs->unit_id]);
    }

    public function test_nama_satuan_tidak_boleh_kembar_dalam_satu_usaha(): void
    {
        $this->actingAs($this->pemilik())
            ->post('/master/satuan', ['unit_name' => 'pcs', 'description' => null, 'active' => true])
            ->assertSessionHasErrors('unit_name');
    }

    // -------------------------------------------------------------- dipakai produk

    public function test_nama_satuan_produk_disalin_dari_master(): void
    {
        $this->actingAs($this->pemilik())
            ->post('/master/produk', $this->produkBaru())
            ->assertSessionHasNoErrors();

        $produk = Product::where('sku', 'UJI-001')->with('uoms')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['PCS', 'DUS'],
            $produk->uoms->pluck('uom_name')->all(),
        );
        $this->assertSame($this->satuan('PCS')->unit_id, $produk->baseUom->unit_id);
    }

    public function test_produk_menolak_satuan_di_luar_master_usaha_ini(): void
    {
        $usahaLain = Owner::create(['owner_code' => 'TB', 'owner_name' => 'Toko Bahagia']);
        $lain = Unit::create(['owner_id' => $usahaLain->owner_id, 'unit_name' => 'SELUNDUPAN']);

        $this->actingAs($this->pemilik())
            ->post('/master/produk', $this->produkBaru([
                'uoms' => [
                    ['id' => null, 'unit_id' => $lain->unit_id, 'uom_type' => 'BASE', 'conversion_factor' => 1],
                ],
            ]))
            ->assertSessionHasErrors('uoms.0.unit_id');

        $this->assertDatabaseMissing('products', ['sku' => 'UJI-001']);
    }

    public function test_satuan_yang_sama_tidak_boleh_dua_kali_pada_satu_produk(): void
    {
        $pcs = $this->satuan('PCS')->unit_id;

        $this->actingAs($this->pemilik())
            ->post('/master/produk', $this->produkBaru([
                'uoms' => [
                    ['id' => null, 'unit_id' => $pcs, 'uom_type' => 'BASE', 'conversion_factor' => 1],
                    ['id' => null, 'unit_id' => $pcs, 'uom_type' => 'PACK', 'conversion_factor' => 6],
                ],
            ]))
            ->assertSessionHasErrors('uoms');

        $this->assertDatabaseMissing('products', ['sku' => 'UJI-001']);
    }

    public function test_mengganti_satuan_pada_baris_lama_ikut_mengganti_namanya(): void
    {
        $rinso = Product::where('sku', 'RNS-045')->with('uoms')->firstOrFail();
        $barisDus = $rinso->uoms->firstWhere('uom_name', 'DUS');
        $lusin = $this->satuan('LUSIN');

        $this->actingAs($this->pemilik())
            ->put("/master/produk/{$rinso->product_id}", [
                'product_name' => $rinso->product_name,
                'sku' => $rinso->sku,
                'barcode' => $rinso->barcode,
                'category_id' => $rinso->category_id,
                'product_type' => $rinso->product_type,
                'is_weighted' => false,
                'active' => true,
                'uoms' => [
                    [
                        'id' => $rinso->baseUom->uom_id,
                        'unit_id' => $rinso->baseUom->unit_id,
                        'uom_type' => 'BASE',
                        'conversion_factor' => 1,
                    ],
                    [
                        'id' => $barisDus->uom_id,
                        'unit_id' => $lusin->unit_id,
                        'uom_type' => 'PACK',
                        'conversion_factor' => 12,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $baris = ProductUom::findOrFail($barisDus->uom_id);

        $this->assertSame($lusin->unit_id, $baris->unit_id);
        $this->assertSame('LUSIN', $baris->uom_name);
    }
}

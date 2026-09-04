<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceResolver;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pelanggan tunai bawaan dan hak istimewa member.
 *
 * Kasir tidak memilih pelanggan untuk penjualan eceran: semuanya masuk ke
 * pelanggan bawaan. Harga khusus hanya milik member.
 */
class CustomerMemberTest extends TestCase
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

    private function bawaan(): Customer
    {
        return Customer::where('is_default', true)->firstOrFail();
    }

    /** Harga 1 PCS Rinso bagi pelanggan tersebut: 2.500 eceran, 2.200 member. */
    private function hargaRinso(Customer $pelanggan): ?float
    {
        $rinso = Product::where('sku', 'RNS-045')->with('uoms')->firstOrFail();
        $pcs = $rinso->uoms->firstWhere('uom_name', 'PCS');

        return app(PriceResolver::class)->resolve(
            $rinso->owner_id,
            $rinso->product_id,
            $pcs->uom_id,
            1,
            null,
            $pelanggan->customer_id,
        )['unit_price'];
    }

    /** Payload pelanggan yang sah, boleh ditimpa sebagian. */
    private function pelanggan(array $ubah = []): array
    {
        return array_replace([
            'customer_name' => 'Warung Baru',
            'phone' => null,
            'is_member' => true,
            'is_default' => false,
            'credit_limit' => 0,
            'branch_id' => null,
            'active' => true,
        ], $ubah);
    }

    // ------------------------------------------------------- pelanggan bawaan

    public function test_setiap_usaha_punya_tepat_satu_pelanggan_tunai_bawaan(): void
    {
        $this->assertCount(1, Customer::where('is_default', true)->get());
        $this->assertSame('Umum / Tunai', $this->bawaan()->customer_name);
        $this->assertFalse($this->bawaan()->is_member);
        $this->assertNotNull(Customer::defaultFor($this->pemilik()->owner_id));
    }

    public function test_pelanggan_bawaan_tidak_dapat_dihapus(): void
    {
        $bawaan = $this->bawaan();

        $this->actingAs($this->pemilik())
            ->delete("/master/pelanggan/{$bawaan->customer_id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('customers', ['customer_id' => $bawaan->customer_id]);
    }

    public function test_tanda_bawaan_tidak_boleh_sekadar_dilepas(): void
    {
        $bawaan = $this->bawaan();

        $this->actingAs($this->pemilik())
            ->put("/master/pelanggan/{$bawaan->customer_id}", $this->pelanggan([
                'customer_name' => $bawaan->customer_name,
                'is_member' => false,
                'is_default' => false,
            ]))
            ->assertSessionHasErrors('is_default');

        $this->assertTrue($bawaan->fresh()->is_default);
    }

    public function test_menandai_pelanggan_lain_memindahkan_tanda_bawaan(): void
    {
        $lama = $this->bawaan();
        $kantin = Customer::where('customer_name', 'Kantin SD Melati')->firstOrFail();

        $this->actingAs($this->pemilik())
            ->put("/master/pelanggan/{$kantin->customer_id}", $this->pelanggan([
                'customer_name' => $kantin->customer_name,
                'is_member' => true,
                'is_default' => true,
                'credit_limit' => 500000,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($lama->fresh()->is_default);
        $this->assertTrue($kantin->fresh()->is_default);

        // Pelanggan bawaan mewakili pembeli tanpa nama: tanpa piutang, tanpa
        // lokasi, tanpa keanggotaan, dan tidak pernah nonaktif.
        $baru = $kantin->fresh();
        $this->assertFalse($baru->is_member);
        $this->assertSame('0.00', $baru->credit_limit);
        $this->assertNull($baru->branch_id);
        $this->assertTrue($baru->active);
    }

    // --------------------------------------------------------------- member

    /**
     * Inti perubahan ini: cukup ditandai member, tanpa memilih daftar harga
     * apa pun, pelanggan baru langsung membayar harga member. Dulu ia harus
     * ditugaskan ke daftar harga — dan yang lupa ditugaskan diam-diam membayar
     * harga eceran.
     */
    public function test_member_baru_langsung_mendapat_harga_member_tanpa_penugasan(): void
    {
        $this->actingAs($this->pemilik())
            ->post('/master/pelanggan', $this->pelanggan(['is_member' => true]))
            ->assertSessionHasNoErrors();

        $baru = Customer::where('customer_name', 'Warung Baru')->firstOrFail();

        $this->assertTrue($baru->is_member);
        $this->assertSame(2200.0, $this->hargaRinso($baru), 'member memakai harga grosir');
    }

    public function test_pelanggan_bukan_member_memakai_harga_normal(): void
    {
        $this->actingAs($this->pemilik())
            ->post('/master/pelanggan', $this->pelanggan(['is_member' => false]))
            ->assertSessionHasNoErrors();

        $baru = Customer::where('customer_name', 'Warung Baru')->firstOrFail();

        $this->assertFalse($baru->is_member);
        $this->assertSame(2500.0, $this->hargaRinso($baru), 'bukan member, harga eceran');
    }

    /**
     * Harga normal adalah jaring terakhir untuk semua pembeli, sedangkan daftar
     * khusus member sengaja dilewati pembeli tunai. Satu daftar yang memikul
     * kedua tanda akan membuat pembeli tunai kehilangan harga sama sekali.
     */
    public function test_daftar_harga_member_tidak_boleh_sekaligus_harga_normal(): void
    {
        $this->actingAs($this->pemilik())
            ->post('/master/harga', [
                'price_list_code' => 'PL-CAMPUR',
                'price_list_name' => 'Harga Campur Aduk',
                'branch_id' => null,
                'is_default' => true,
                'member_only' => true,
                'priority' => 5,
                'start_date' => null,
                'end_date' => null,
                'active' => true,
            ])
            ->assertSessionHasErrors('member_only');

        $this->assertDatabaseMissing('price_lists', ['price_list_code' => 'PL-CAMPUR']);

        // Tanda bawaan lama tidak boleh ikut terlepas oleh percobaan yang gagal.
        $this->assertSame('PL-UMUM', PriceList::where('is_default', true)->firstOrFail()->price_list_code);
    }

    public function test_harga_member_gugur_bila_keanggotaan_dicabut(): void
    {
        $berkah = Customer::where('customer_name', 'Toko Berkah Jaya')->firstOrFail();

        $this->assertSame(2200.0, $this->hargaRinso($berkah), 'member memakai harga grosir');

        $berkah->update(['is_member' => false]);

        $this->assertSame(2500.0, $this->hargaRinso($berkah->fresh()), 'bukan member lagi, kembali ke harga normal');
    }

    public function test_penjualan_tanpa_pelanggan_memakai_harga_normal(): void
    {
        $this->assertSame(2500.0, $this->hargaRinso($this->bawaan()));
    }
}

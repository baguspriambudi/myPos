<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Autentikasi, hak akses master, dan isolasi antar-owner.
 */
class MasterAccessTest extends TestCase
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

    private function kasir(): User
    {
        return User::where('username', 'kasir')->firstOrFail();
    }

    // ------------------------------------------------------------------ auth

    public function test_login_memakai_username_bukan_email(): void
    {
        $response = $this->post('/login', [
            'username' => 'bagus',
            'password' => OwnerSeeder::PASSWORD,
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->pemilik());
    }

    public function test_kata_sandi_salah_ditolak(): void
    {
        $this->post('/login', ['username' => 'bagus', 'password' => 'salah'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_pengguna_nonaktif_tidak_dapat_masuk(): void
    {
        $this->kasir()->update(['active' => false]);

        $this->post('/login', ['username' => 'kasir', 'password' => OwnerSeeder::PASSWORD])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_username_kembar_antar_usaha_meminta_kode_usaha(): void
    {
        // Usaha kedua dengan username yang sama persis.
        $lain = Owner::create(['owner_code' => 'TB', 'owner_name' => 'Toko Bahagia']);
        User::create([
            'owner_id' => $lain->owner_id,
            'username' => 'bagus',
            'password_hash' => OwnerSeeder::PASSWORD,
            'full_name' => 'Bagus Lain',
            'role' => User::ROLE_OWNER,
        ]);

        $this->post('/login', ['username' => 'bagus', 'password' => OwnerSeeder::PASSWORD])
            ->assertSessionHasErrors('owner_code');
        $this->assertGuest();

        // Dengan kode usaha, login berhasil dan masuk ke owner yang benar.
        $this->post('/login', [
            'username' => 'bagus',
            'password' => OwnerSeeder::PASSWORD,
            'owner_code' => 'SM',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($this->pemilik());
    }

    public function test_halaman_master_menolak_tamu(): void
    {
        $this->get('/master/lokasi')->assertRedirect('/login');
        $this->get('/master/harga')->assertRedirect('/login');
    }

    // --------------------------------------------------------------- akses

    public function test_kasir_boleh_membaca_master_tetapi_tidak_menulis(): void
    {
        $this->actingAs($this->kasir())->get('/master/lokasi')->assertOk();

        $this->actingAs($this->kasir())->post('/master/lokasi', [
            'branch_code' => 'BR99',
            'branch_name' => 'Cabang Selundupan',
            'branch_type' => Branch::TYPE_STORE,
            'can_sell' => true,
            'active' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('branches', ['branch_code' => 'BR99']);
    }

    public function test_pemilik_dapat_menambah_gudang(): void
    {
        $this->actingAs($this->pemilik())->post('/master/lokasi', [
            'branch_code' => 'wh02',
            'branch_name' => 'Gudang Cadangan',
            'branch_type' => Branch::TYPE_WAREHOUSE,
            'can_sell' => false,
            'active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('branches', [
            'branch_code' => 'WH02',
            'branch_type' => 'WAREHOUSE',
            'can_sell' => 0,
        ]);
    }

    public function test_izin_menjual_tidak_dapat_dimatikan_saat_shift_terbuka(): void
    {
        $pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
        $periode = FiscalPeriod::where('branch_id', $pusat->branch_id)->firstOrFail();

        DB::table('cashier_shifts')->insert([
            'shift_number' => 'SH-001',
            'fiscal_period_id' => $periode->fiscal_period_id,
            'branch_id' => $pusat->branch_id,
            'cashier_id' => $this->kasir()->user_id,
            'opened_at' => now(),
            'status' => 'OPEN',
        ]);

        $this->actingAs($this->pemilik())->put("/master/lokasi/{$pusat->branch_id}", [
            'branch_code' => $pusat->branch_code,
            'branch_name' => $pusat->branch_name,
            'branch_type' => $pusat->branch_type,
            'can_sell' => false,
            'active' => true,
        ])->assertSessionHasErrors('can_sell');

        $this->assertTrue($pusat->fresh()->can_sell);
    }

    // ------------------------------------------------------------- isolasi

    public function test_master_usaha_lain_tidak_dapat_disentuh(): void
    {
        $lain = Owner::create(['owner_code' => 'TB', 'owner_name' => 'Toko Bahagia']);
        $cabangLain = Branch::create([
            'owner_id' => $lain->owner_id,
            'branch_code' => 'XX01',
            'branch_name' => 'Cabang Usaha Lain',
            'branch_type' => Branch::TYPE_STORE,
        ]);

        $this->actingAs($this->pemilik())->put("/master/lokasi/{$cabangLain->branch_id}", [
            'branch_code' => 'XX01',
            'branch_name' => 'Dibajak',
            'branch_type' => Branch::TYPE_STORE,
            'can_sell' => true,
            'active' => true,
        ])->assertNotFound();

        $this->assertSame('Cabang Usaha Lain', $cabangLain->fresh()->branch_name);
    }

    // --------------------------------------------------------- daftar harga

    public function test_hanya_satu_daftar_harga_bawaan_per_usaha(): void
    {
        $this->actingAs($this->pemilik())->post('/master/harga', [
            'price_list_code' => 'PL-BARU',
            'price_list_name' => 'Daftar Bawaan Baru',
            'branch_id' => null,
            'is_default' => true,
            'member_only' => false,
            'priority' => 0,
            'active' => true,
        ])->assertRedirect();

        $bawaan = PriceList::where('is_default', true)->get();

        $this->assertCount(1, $bawaan);
        $this->assertSame('PL-BARU', $bawaan->first()->price_list_code);
    }

    public function test_tingkat_harga_menolak_satuan_milik_produk_lain(): void
    {
        $umum = PriceList::where('price_list_code', 'PL-UMUM')->firstOrFail();
        $rinso = Product::where('sku', 'RNS-045')->firstOrFail();
        $satuanAqua = Product::where('sku', 'AQU-600')->firstOrFail()->uoms()->first();

        $this->actingAs($this->pemilik())
            ->post("/master/harga/{$umum->price_list_id}/tingkat", [
                'product_id' => $rinso->product_id,
                'uom_id' => $satuanAqua->uom_id,
                'min_qty' => 1,
                'unit_price' => 1000,
                'start_date' => now()->toDateString(),
                'end_date' => PriceListItem::TANPA_AKHIR,
            ])
            ->assertSessionHasErrors('uom_id');
    }

    /**
     * Duplikat sekarang berarti tanggal mulai yang sama.
     *
     * Dua versi harga untuk tingkat yang sama memang boleh — itulah riwayatnya
     * — tetapi dua versi yang mulai pada hari yang sama tidak dapat dijelaskan
     * mana yang dimaksud.
     */
    public function test_tingkat_harga_menolak_dua_versi_yang_mulai_pada_hari_sama(): void
    {
        $umum = PriceList::where('price_list_code', 'PL-UMUM')->firstOrFail();
        $rinso = Product::where('sku', 'RNS-045')->with('uoms')->firstOrFail();
        $pcs = $rinso->uoms->firstWhere('uom_name', 'PCS');

        $baris = fn (float $harga) => [
            'product_id' => $rinso->product_id,
            'uom_id' => $pcs->uom_id,
            'min_qty' => 1,
            'unit_price' => $harga,
            'start_date' => now()->toDateString(),
            'end_date' => PriceListItem::TANPA_AKHIR,
        ];

        // Versi baru di atas harga seeder (yang mulai 1900) tetap diterima.
        $this->actingAs($this->pemilik())
            ->post("/master/harga/{$umum->price_list_id}/tingkat", $baris(2400))
            ->assertSessionHasNoErrors();

        // Versi kedua pada hari yang sama ditolak.
        $this->actingAs($this->pemilik())
            ->post("/master/harga/{$umum->price_list_id}/tingkat", $baris(2350))
            ->assertSessionHasErrors('min_qty');
    }
}

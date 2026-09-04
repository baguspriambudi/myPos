<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shift kasir (Addendum 50, PRD §18).
 *
 * Yang dijaga: shift BUKAN prasyarat berjualan (nota tetap terbit tanpa shift
 * terbuka), sekali dibuka menaut nota & pembayaran tunai lewat cash_transactions
 * CASH_SALE, satu kasir hanya boleh satu shift terbuka per lokasi, rollover
 * opening_cash dari shift terakhir yang CLOSED, dan expected_cash saat tutup
 * dihitung dari cash_transactions yang benar-benar tertulis.
 */
class CashierShiftTest extends TestCase
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

    private function jual(User $user, string $sku, string $uomName, float $qty, string $metode = 'CASH'): void
    {
        $product = $this->produk($sku);

        $this->actingAs($user)->post('/kasir/nota', [
            'payment_type' => $metode,
            'lines' => [[
                'product_id' => $product->product_id,
                'uom_id' => $product->uoms->firstWhere('uom_name', $uomName)->uom_id,
                'qty' => $qty,
            ]],
        ])->assertSessionHas('nota');
    }

    // -------------------------------------------------------------- membuka

    public function test_buka_shift_menulis_opening_cash(): void
    {
        $this->actingAs($this->kasir())
            ->post('/kasir/shift/buka', ['opening_cash' => 100000])
            ->assertRedirect()
            ->assertSessionHas('success');

        $shift = CashierShift::firstOrFail();
        $this->assertSame('OPEN', $shift->status);
        $this->assertSame(100000.0, (float) $shift->opening_cash);
        $this->assertSame('MANUAL', $shift->opening_source);

        $this->assertDatabaseHas('cash_transactions', [
            'shift_id' => $shift->shift_id,
            'txn_type' => 'OPENING_CASH',
            'amount' => 100000,
        ]);
    }

    public function test_kasir_tidak_boleh_membuka_dua_shift_sekaligus(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 50000]);

        $this->actingAs($this->kasir())
            ->post('/kasir/shift/buka', ['opening_cash' => 50000])
            ->assertSessionHasErrors('shift');

        $this->assertSame(1, CashierShift::count());
    }

    public function test_opening_cash_kosong_rollover_dari_shift_sebelumnya(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 100000]);
        $pertama = CashierShift::firstOrFail();

        $this->actingAs($this->kasir())->post("/kasir/shift/{$pertama->shift_id}/tutup", ['actual_cash' => 150000]);

        $this->actingAs($this->kasir())->post('/kasir/shift/buka', [])->assertRedirect();

        $kedua = CashierShift::orderByDesc('shift_id')->first();
        $this->assertSame(150000.0, (float) $kedua->opening_cash);
        $this->assertSame('ROLLOVER', $kedua->opening_source);
    }

    public function test_petugas_gudang_tidak_boleh_membuka_shift(): void
    {
        $this->actingAs(User::where('username', 'gudang')->firstOrFail())
            ->post('/kasir/shift/buka', ['opening_cash' => 50000])
            ->assertForbidden();

        $this->assertDatabaseCount('cashier_shifts', 0);
    }

    // ------------------------------------------------------- nota selama shift

    public function test_shift_bukan_prasyarat_berjualan(): void
    {
        // Tanpa membuka shift sama sekali.
        $this->jual($this->kasir(), 'JSA-ANTAR', 'KALI', 1);

        $this->assertDatabaseHas('sales', ['status' => 'POSTED', 'shift_id' => null]);
        $this->assertDatabaseHas('cash_transactions', ['txn_type' => 'CASH_SALE', 'shift_id' => null]);
    }

    public function test_nota_selama_shift_terbuka_tertaut_dan_tercatat_di_buku_kas(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 100000]);
        $shift = CashierShift::firstOrFail();

        $this->jual($this->kasir(), 'JSA-ANTAR', 'KALI', 1);

        $this->assertDatabaseHas('sales', ['shift_id' => $shift->shift_id]);
        $this->assertDatabaseHas('cash_transactions', [
            'shift_id' => $shift->shift_id,
            'txn_type' => 'CASH_SALE',
            'amount' => 15000,
            'reference_type' => 'SALES',
        ]);
    }

    public function test_nota_non_tunai_tidak_menulis_cash_transaction(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 100000]);

        $this->jual($this->kasir(), 'JSA-ANTAR', 'KALI', 1, 'QRIS');

        $this->assertDatabaseMissing('cash_transactions', ['txn_type' => 'CASH_SALE']);
    }

    // -------------------------------------------------------------- menutup

    public function test_tutup_shift_menghitung_expected_dan_selisih_dari_buku_kas(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 100000]);
        $shift = CashierShift::firstOrFail();

        $this->jual($this->kasir(), 'JSA-ANTAR', 'KALI', 1); // Rp15.000 tunai

        $this->actingAs($this->kasir())
            ->post("/kasir/shift/{$shift->shift_id}/tutup", ['actual_cash' => 114000, 'close_note' => 'Kurang 1.000, kembalian salah hitung.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $shift->refresh();
        $this->assertSame('CLOSED', $shift->status);
        $this->assertSame(115000.0, (float) $shift->expected_cash); // 100.000 + 15.000
        $this->assertSame(114000.0, (float) $shift->actual_cash);
        $this->assertSame(-1000.0, (float) $shift->difference);
    }

    public function test_shift_yang_sudah_ditutup_tidak_dapat_ditutup_lagi(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 50000]);
        $shift = CashierShift::firstOrFail();

        $this->actingAs($this->kasir())->post("/kasir/shift/{$shift->shift_id}/tutup", ['actual_cash' => 50000]);

        $this->actingAs($this->kasir())
            ->post("/kasir/shift/{$shift->shift_id}/tutup", ['actual_cash' => 50000])
            ->assertSessionHasErrors('shift');
    }

    public function test_manajer_boleh_menutup_shift_kasir_lain(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 50000]);
        $shift = CashierShift::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/kasir/shift/{$shift->shift_id}/tutup", ['actual_cash' => 50000])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ---------------------------------------------------------- layar kasir

    public function test_layar_kasir_menampilkan_shift_terbuka(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 75000]);

        $response = $this->actingAs($this->kasir())->get('/kasir');
        $shift = $response->viewData('page')['props']['currentShift'];

        $this->assertNotNull($shift);
        $this->assertSame(75000.0, $shift['openingCash']);
    }

    public function test_layar_kasir_tanpa_shift_terbuka_tetap_null(): void
    {
        $response = $this->actingAs($this->kasir())->get('/kasir');
        $this->assertNull($response->viewData('page')['props']['currentShift']);
    }
}

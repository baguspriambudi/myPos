<?php

namespace Tests\Feature;

use App\Models\CashDifference;
use App\Models\CashierShift;
use App\Models\FiscalPeriod;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selisih kas shift dan penyelesaiannya (Addendum 46).
 *
 * Yang dijaga: selisih lahir sendiri saat shift ditutup, tidak bisa
 * diselesaikan oleh kasir yang bersangkutan, penyelesaiannya menyamakan buku
 * kas dengan uang fisik, dan selama masih menggantung ia muncul sebagai
 * peringatan pra-tutup — bukan penghalang.
 */
class CashDifferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);
    }

    private function kasir(): User
    {
        return User::where('username', 'kasir')->firstOrFail();
    }

    private function manajer(): User
    {
        return User::where('username', 'manajer')->firstOrFail();
    }

    private function pemilik(): User
    {
        return User::where('username', 'bagus')->firstOrFail();
    }

    /** Buka lalu tutup satu shift dengan uang fisik yang ditentukan. */
    private function shiftSelesai(float $openingCash, float $actualCash): CashierShift
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => $openingCash])->assertSessionHas('success');

        $shift = CashierShift::where('cashier_id', $this->kasir()->user_id)->firstOrFail();

        $this->actingAs($this->kasir())
            ->post("/kasir/shift/{$shift->shift_id}/tutup", ['actual_cash' => $actualCash])
            ->assertSessionHas('success');

        return $shift->fresh();
    }

    public function test_tutup_shift_dengan_uang_kurang_membuat_selisih_terbuka(): void
    {
        $shift = $this->shiftSelesai(100000, 90000);

        $selisih = CashDifference::firstOrFail();

        $this->assertSame($shift->shift_id, $selisih->shift_id);
        $this->assertSame(100000.0, (float) $selisih->expected_cash);
        $this->assertSame(90000.0, (float) $selisih->actual_cash);
        $this->assertSame(-10000.0, (float) $selisih->difference);
        $this->assertSame(CashDifference::STATUS_OPEN, $selisih->status);
    }

    public function test_shift_yang_pas_tidak_meninggalkan_selisih(): void
    {
        $this->shiftSelesai(100000, 100000);

        $this->assertSame(0, CashDifference::count());
    }

    public function test_kasir_tidak_berwenang_menyentuh_penyelesaian_selisih(): void
    {
        $this->shiftSelesai(100000, 90000);
        $selisih = CashDifference::firstOrFail();

        $this->actingAs($this->kasir())
            ->post("/periode/selisih-kas/{$selisih->difference_id}/selesaikan", [
                'resolution_type' => CashDifference::RESOLUTION_ADJUSTMENT,
                'note' => 'Sudah saya ganti',
            ])
            ->assertForbidden();

        $this->assertSame(CashDifference::STATUS_OPEN, $selisih->fresh()->status);
    }

    public function test_pemegang_shift_tidak_boleh_menyelesaikan_selisihnya_sendiri(): void
    {
        // Manajer boleh membuka shift, dan wewenang menyelesaikan selisih pun
        // ada padanya — tetapi tidak untuk selisih shift-nya sendiri
        // (Addendum 46.2). Larangan ini di service, bukan di peran.
        $manajer = $this->manajer();

        $this->actingAs($manajer)->post('/kasir/shift/buka', ['opening_cash' => 50000])->assertSessionHas('success');
        $shift = CashierShift::where('cashier_id', $manajer->user_id)->firstOrFail();
        $this->actingAs($manajer)->post("/kasir/shift/{$shift->shift_id}/tutup", ['actual_cash' => 40000]);

        $selisih = CashDifference::firstOrFail();

        $this->actingAs($manajer)
            ->post("/periode/selisih-kas/{$selisih->difference_id}/selesaikan", [
                'resolution_type' => CashDifference::RESOLUTION_ADJUSTMENT,
                'note' => 'Biar cepat beres',
            ])
            ->assertSessionHasErrors('difference');

        $this->assertSame(CashDifference::STATUS_OPEN, $selisih->fresh()->status);
    }

    public function test_penyelesaian_menyamakan_buku_kas_dengan_uang_fisik(): void
    {
        $this->shiftSelesai(100000, 90000);
        $selisih = CashDifference::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/periode/selisih-kas/{$selisih->difference_id}/selesaikan", [
                'resolution_type' => CashDifference::RESOLUTION_CASHIER,
                'note' => 'Kekurangan ditanggung kasir, dipotong bulan ini',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $selisih->refresh();
        $this->assertSame(CashDifference::STATUS_RESOLVED, $selisih->status);
        $this->assertSame(CashDifference::RESOLUTION_CASHIER, $selisih->resolution_type);
        $this->assertSame($this->manajer()->user_id, $selisih->resolved_by);

        // Uang fisik lebih sedikit daripada buku, jadi buku ikut dikeluarkan.
        $this->assertDatabaseHas('cash_transactions', [
            'txn_type' => 'CASH_OUT',
            'amount' => -10000,
            'reference_type' => 'CASH_DIFFERENCE',
            'reference_id' => $selisih->difference_id,
        ]);
    }

    public function test_kas_kurang_tidak_dapat_diakui_sebagai_pendapatan(): void
    {
        $this->shiftSelesai(100000, 90000);
        $selisih = CashDifference::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/periode/selisih-kas/{$selisih->difference_id}/selesaikan", [
                'resolution_type' => CashDifference::RESOLUTION_INCOME,
                'note' => 'Anggap saja pendapatan',
            ])
            ->assertSessionHasErrors('resolution_type');

        $this->assertSame(CashDifference::STATUS_OPEN, $selisih->fresh()->status);
    }

    public function test_kas_lebih_masuk_sebagai_pendapatan_lain(): void
    {
        $this->shiftSelesai(100000, 115000);
        $selisih = CashDifference::firstOrFail();

        $this->assertSame(15000.0, (float) $selisih->difference);

        $this->actingAs($this->manajer())
            ->post("/periode/selisih-kas/{$selisih->difference_id}/selesaikan", [
                'resolution_type' => CashDifference::RESOLUTION_INCOME,
                'note' => 'Kelebihan uang laci, tidak ada pemiliknya',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('cash_transactions', [
            'txn_type' => 'CASH_IN',
            'amount' => 15000,
            'reference_type' => 'CASH_DIFFERENCE',
        ]);
    }

    public function test_selisih_menggantung_hanya_jadi_peringatan_bukan_penghalang(): void
    {
        $this->shiftSelesai(100000, 90000);

        $periode = FiscalPeriod::findOrFail(CashDifference::firstOrFail()->fiscal_period_id);

        $this->actingAs($this->pemilik())
            ->post("/periode/{$periode->fiscal_period_id}/tutup")
            ->assertSessionHas('success');

        $this->assertSame(FiscalPeriod::STATUS_CLOSED, $periode->fresh()->status);
        // Selisih tetap terbuka setelah periode ditutup; ia dicatat di snapshot
        // agar terlihat, bukan dihapus.
        $this->assertSame(CashDifference::STATUS_OPEN, CashDifference::firstOrFail()->status);
    }
}

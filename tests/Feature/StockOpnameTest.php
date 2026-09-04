<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stock opname (Addendum 39.8, 50, 59.3).
 *
 * Yang dijaga: mencatat hitungan sebagai DRAFT tidak menyentuh stok sama
 * sekali, menerapkan baru mengubah saldo lewat StockAdjustment, saldo dihitung
 * ULANG saat diterapkan (bukan snapshot basi), dan hanya MANAGER ke atas yang
 * boleh menerapkan walau semua peran boleh mencatat hitungan.
 */
class StockOpnameTest extends TestCase
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

    private function gudang(): User
    {
        return User::where('username', 'gudang')->firstOrFail();
    }

    private function manajer(): User
    {
        return User::where('username', 'manajer')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function stok(string $sku): float
    {
        return app(StockLedger::class)->onHand($this->pusat->branch_id, $this->produk($sku)->product_id);
    }

    private function isiStok(string $sku, float $qty, float $unitCost, string $uomName = 'PCS'): void
    {
        $product = $this->produk($sku);
        $periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $periode,
            $this->pusat->branch_id,
            now()->toDateString(),
            $product,
            $product->uoms->firstWhere('uom_name', $uomName),
            $qty,
            $unitCost,
        ));
    }

    /** @return array<string, mixed> */
    private function isian(array $lines, array $extra = []): array
    {
        return array_merge([
            'opname_date' => now()->toDateString(),
            'lines' => $lines,
        ], $extra);
    }

    // -------------------------------------------------------------- mencatat

    public function test_mencatat_draf_tidak_mengubah_stok(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())
            ->post('/stok/opname', $this->isian([[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'actual_qty' => 7,
            ]]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(10.0, $this->stok('RNS-045'));

        $opname = StockOpname::firstOrFail();
        $this->assertSame('DRAFT', $opname->status);

        $item = $opname->items->firstOrFail();
        $this->assertSame(10.0, (float) $item->system_qty_base);
        $this->assertSame(7.0, (float) $item->actual_qty_base);
        $this->assertSame(-3.0, (float) $item->difference_qty_base);

        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_semua_peran_boleh_mencatat_hitungan(): void
    {
        foreach (['kasir', 'gudang', 'manajer'] as $username) {
            $this->actingAs(User::where('username', $username)->firstOrFail())
                ->post('/stok/opname', $this->isian([[
                    'product_id' => $this->produk('AQU-600')->product_id,
                    'uom_id' => $this->produk('AQU-600')->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                    'actual_qty' => 1,
                ]]))
                ->assertRedirect()
                ->assertSessionHas('success');
        }

        $this->assertSame(3, StockOpname::count());
    }

    public function test_hasil_hitungan_nol_diperbolehkan(): void
    {
        $this->isiStok('RNS-045', 5, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())
            ->post('/stok/opname', $this->isian([[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'actual_qty' => 0,
            ]]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(-5.0, (float) StockOpname::firstOrFail()->items->firstOrFail()->difference_qty_base);
    }

    // ---------------------------------------------------------------- terapkan

    public function test_terapkan_membuat_penyesuaian_dan_mengubah_saldo(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())->post('/stok/opname', $this->isian([[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'actual_qty' => 7,
        ]]));
        $opname = StockOpname::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/stok/opname/{$opname->opname_id}/terapkan")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(7.0, $this->stok('RNS-045'));

        $this->assertDatabaseHas('stock_adjustments', [
            'adjustment_type' => 'OUT',
            'qty_base' => 3,
            'reason' => "Selisih stock opname {$opname->opname_number}.",
        ]);

        $opname->refresh();
        $this->assertSame('APPLIED', $opname->status);
        $this->assertNotNull($opname->applied_at);
    }

    public function test_selisih_nol_tidak_membuat_penyesuaian(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())->post('/stok/opname', $this->isian([[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'actual_qty' => 10,
        ]]));
        $opname = StockOpname::firstOrFail();

        $this->actingAs($this->manajer())->post("/stok/opname/{$opname->opname_id}/terapkan")->assertRedirect();

        $this->assertDatabaseCount('stock_adjustments', 0);
        $this->assertSame(10.0, $this->stok('RNS-045'));
    }

    public function test_saldo_dihitung_ulang_saat_diterapkan_bukan_snapshot_basi(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        // Dicatat saat saldo masih 10, hasil hitungan fisik 7 (selisih -3).
        $this->actingAs($this->manajer())->post('/stok/opname', $this->isian([[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'actual_qty' => 7,
        ]]));
        $opname = StockOpname::firstOrFail();

        // Sebelum diterapkan, ada penerimaan susulan +5 — saldo sistem sekarang 15.
        $this->actingAs($this->manajer())->post('/penerimaan', [
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 5,
            ]],
        ])->assertRedirect();

        $this->assertSame(15.0, $this->stok('RNS-045'));

        $this->actingAs($this->manajer())->post("/stok/opname/{$opname->opname_id}/terapkan")->assertRedirect();

        // Selisih yang disahkan harus 7 - 15 = -8, bukan -3 dari snapshot lama.
        $this->assertSame(7.0, $this->stok('RNS-045'));
        $this->assertDatabaseHas('stock_adjustments', ['adjustment_type' => 'OUT', 'qty_base' => 8]);

        $opname->refresh();
        $item = $opname->items->firstOrFail();
        $this->assertSame(-8.0, (float) $item->difference_qty_base);
    }

    public function test_hanya_manajer_ke_atas_boleh_menerapkan(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())->post('/stok/opname', $this->isian([[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'actual_qty' => 7,
        ]]));
        $opname = StockOpname::firstOrFail();

        foreach (['kasir', 'gudang'] as $username) {
            $this->actingAs(User::where('username', $username)->firstOrFail())
                ->post("/stok/opname/{$opname->opname_id}/terapkan")
                ->assertForbidden();
        }

        $this->assertSame(10.0, $this->stok('RNS-045'));
    }

    public function test_kasir_dan_gudang_tidak_melihat_nilai_selisih(): void
    {
        // Dicatat & dilihat gudang sendiri di lokasinya sendiri (WH01) — nilai
        // stok pusat tidak relevan untuk tes ini, yang diuji hanya visibilitas.
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->gudang())->post('/stok/opname', $this->isian([[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'actual_qty' => 7,
        ]]));

        $response = $this->actingAs($this->gudang())->get('/stok/opname');
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['canSeeCost']);
        $this->assertFalse($props['canTerapkan']);
        $this->assertNull($props['opnames']['data'][0]['items'][0]['differenceValue']);
    }

    // ----------------------------------------------------------------- batal

    public function test_draf_dapat_dibatalkan_tanpa_menyentuh_stok(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())->post('/stok/opname', $this->isian([[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'actual_qty' => 7,
        ]]));
        $opname = StockOpname::firstOrFail();

        $this->actingAs($this->gudang())
            ->post("/stok/opname/{$opname->opname_id}/batal")
            ->assertRedirect()
            ->assertSessionHas('success');

        $opname->refresh();
        $this->assertSame('CANCELLED', $opname->status);
        $this->assertSame(10.0, $this->stok('RNS-045'));
    }

    public function test_opname_yang_sudah_diterapkan_tidak_dapat_diterapkan_ulang(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->gudang())->post('/stok/opname', $this->isian([[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'actual_qty' => 7,
        ]]));
        $opname = StockOpname::firstOrFail();

        $this->actingAs($this->manajer())->post("/stok/opname/{$opname->opname_id}/terapkan");

        $this->actingAs($this->manajer())
            ->post("/stok/opname/{$opname->opname_id}/terapkan")
            ->assertSessionHasErrors('opname');

        $this->assertSame(1, StockAdjustment::count());
    }
}

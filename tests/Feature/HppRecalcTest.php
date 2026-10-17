<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\HppRecalculator;
use App\Services\SalePoster;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hitung ulang HPP rata-rata bergerak (Addendum 41.2, 42.2, 45.3).
 */
class HppRecalcTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    private Branch $pusat;

    private FiscalPeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);

        $this->owner = Owner::firstOrFail();
        $this->owner->update(['costing_method' => 'AVERAGE']);
        $this->pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
        $this->periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();
        $this->periode->update(['costing_method' => 'AVERAGE']);
    }

    private function pemilik(): User
    {
        return User::where('username', 'bagus')->firstOrFail();
    }

    private function rinso(): Product
    {
        return Product::where('sku', 'RNS-045')->with('uoms')->firstOrFail();
    }

    private function terima(float $qty, float $cost): void
    {
        $p = $this->rinso();

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->periode, $this->pusat->branch_id, now()->toDateString(), $p, $p->uoms->firstWhere('uom_name', 'PCS'), $qty, $cost,
        ));
    }

    private function jual(float $qty): Sale
    {
        $p = $this->rinso();

        return app(SalePoster::class)->post(User::where('username', 'kasir')->firstOrFail(), $this->pusat, [
            'payment_type' => 'QRIS',
            'lines' => [['product_id' => $p->product_id, 'uom_id' => $p->uoms->firstWhere('uom_name', 'PCS')->uom_id, 'qty' => $qty]],
        ]);
    }

    private function gerakJual(Sale $sale): StockMovement
    {
        return StockMovement::where('reference_type', 'SALES')->where('reference_id', $sale->sale_id)->firstOrFail();
    }

    /** Penerimaan yang HPP-nya keliru, seperti yang terjadi bila rantai rata-ratanya terputus. */
    private function rusakkanHpp(Sale $sale, float $biayaSalah): void
    {
        $m = $this->gerakJual($sale);
        $m->update(['unit_cost' => $biayaSalah, 'total_cost' => -abs((float) $m->qty_base) * $biayaSalah]);
        $sale->items()->update(['cost_at_sale' => $biayaSalah, 'cogs_total' => abs((float) $m->qty_base) * $biayaSalah]);
    }

    public function test_hpp_nota_yang_keliru_diperbaiki_dari_riwayat_pergerakan(): void
    {
        $this->terima(10, 1000);
        $this->terima(10, 3000); // rata-rata 2.000
        $sale = $this->jual(4);
        $this->assertSame(8000.0, (float) $sale->items->first()->cogs_total);

        $this->rusakkanHpp($sale, 1000);

        $this->actingAs($this->pemilik())
            ->post("/periode/{$this->periode->fiscal_period_id}/hitung-ulang-hpp")
            ->assertRedirect()
            ->assertSessionHas('success');

        $item = $sale->items()->first();
        $this->assertSame(2000.0, (float) $item->cost_at_sale);
        $this->assertSame(8000.0, (float) $item->cogs_total);
        $this->assertSame(-8000.0, (float) $this->gerakJual($sale)->total_cost);

        $this->assertDatabaseHas('hpp_recalc_logs', [
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'branch_id' => $this->pusat->branch_id,
            'trigger_type' => HppRecalculator::TRIGGER_MANUAL,
            'products_affected' => 1,
            'old_cogs_value' => 4000,
            'new_cogs_value' => 8000,
            'status' => 'SUCCESS',
        ]);
    }

    public function test_riwayat_yang_sudah_benar_tidak_diubah_sama_sekali(): void
    {
        $this->terima(10, 1000);
        $this->jual(3);
        $this->terima(5, 4000);
        $this->jual(6);
        $avgSebelum = (float) StockBalance::where('product_id', $this->rinso()->product_id)->value('avg_cost');

        $hasil = DB::transaction(fn () => app(HppRecalculator::class)->recalculate(
            $this->periode, $this->pusat->branch_id, null, HppRecalculator::TRIGGER_MANUAL, $this->pemilik(),
        ));

        $this->assertSame(0, $hasil['movements_changed']);
        $this->assertSame($avgSebelum, (float) StockBalance::where('product_id', $this->rinso()->product_id)->value('avg_cost'));
    }

    public function test_periode_tertutup_tidak_pernah_ditulis_ulang(): void
    {
        $this->terima(10, 1000);
        $sale = $this->jual(2);
        $this->rusakkanHpp($sale, 500);
        $this->periode->update(['status' => FiscalPeriod::STATUS_CLOSED]);

        DB::transaction(fn () => app(HppRecalculator::class)->recalculate(
            $this->periode, $this->pusat->branch_id, null, HppRecalculator::TRIGGER_MANUAL, $this->pemilik(),
        ));

        // Angka periode tertutup sudah dilaporkan; hanya buka ulang yang boleh mengubahnya.
        $this->assertSame(500.0, (float) $sale->items()->first()->cost_at_sale);
    }

    public function test_fifo_tidak_dihitung_ulang(): void
    {
        $this->periode->update(['costing_method' => 'FIFO']);

        $this->actingAs($this->pemilik())
            ->post("/periode/{$this->periode->fiscal_period_id}/hitung-ulang-hpp")
            ->assertSessionHasErrors('period');

        $this->assertDatabaseCount('hpp_recalc_logs', 0);
    }

    public function test_void_nota_mencatat_hitung_ulang(): void
    {
        $this->terima(10, 1000);
        $sale = $this->jual(2);

        $this->actingAs(User::where('username', 'manajer')->firstOrFail())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Salah input.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('hpp_recalc_logs', [
            'trigger_type' => HppRecalculator::TRIGGER_VOID,
            'reference_type' => 'SALES',
            'reference_id' => $sale->sale_id,
        ]);
        $this->assertSame(10.0, app(StockLedger::class)->onHand($this->pusat->branch_id, $this->rinso()->product_id));
    }

    public function test_kasir_tidak_boleh_menghitung_ulang_hpp(): void
    {
        $this->actingAs(User::where('username', 'kasir')->firstOrFail())
            ->post("/periode/{$this->periode->fiscal_period_id}/hitung-ulang-hpp")
            ->assertForbidden();
    }
}

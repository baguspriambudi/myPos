<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StockLot;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retur penjualan (Addendum 48).
 *
 * Yang dijaga: stok kembali dengan HPP transaksi asal (bukan HPP hari ini),
 * qty retur tidak pernah melebihi qty nota dikurangi retur sebelumnya, kasir
 * boleh memproses tapi tidak melihat HPP, dan void mengeluarkan stok lagi.
 */
class SalesReturnTest extends TestCase
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

    /** Mengisi stok langsung lewat ledger supaya cost-nya bersih & dapat diprediksi. */
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

    /** Menjual lewat kasir supaya sale_items.cost_at_sale & unit_price_base nyata. */
    private function jual(string $sku, float $qty, string $uomName = 'PCS'): Sale
    {
        $product = $this->produk($sku);

        $this->actingAs($this->kasir())->post('/kasir/nota', [
            'payment_type' => 'CASH',
            'lines' => [[
                'product_id' => $product->product_id,
                'uom_id' => $product->uoms->firstWhere('uom_name', $uomName)->uom_id,
                'qty' => $qty,
            ]],
        ])->assertSessionHas('nota');

        return Sale::orderByDesc('sale_id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function isian(Sale $sale, array $lines, array $extra = []): array
    {
        return array_merge([
            'sale_id' => $sale->sale_id,
            'transaction_date' => now()->toDateString(),
            'lines' => $lines,
        ], $extra);
    }

    // -------------------------------------------------------------- posting

    public function test_retur_mengembalikan_stok_dengan_hpp_asal_bukan_hpp_hari_ini(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $sale = $this->jual('RNS-045', 4);
        $saleItem = $sale->items->firstOrFail();

        // HPP hari ini berubah sesudah nota terbit — retur tetap harus
        // memakai HPP transaksi asal (Rp2.000), bukan yang baru ini.
        $this->isiStok('RNS-045', 5, 5000);

        $sebelum = $this->stok('RNS-045');

        $this->actingAs($this->manajer())
            ->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 2]]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($sebelum + 2, $this->stok('RNS-045'));

        $this->assertDatabaseHas('sales_return_items', [
            'sale_item_id' => $saleItem->sale_item_id,
            'qty_base' => 2,
            'cost_at_return' => 2000,
        ]);

        // Lot baru bertanggal hari ini, dengan cost transaksi asal.
        $lot = StockLot::where('unit_cost', 2000)->where('lot_number', 'like', '%'.now()->format('Ymd').'%')->latest('lot_id')->first();
        $this->assertNotNull($lot);
    }

    public function test_qty_retur_tidak_boleh_melebihi_sisa(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $sale = $this->jual('RNS-045', 5);
        $saleItem = $sale->items->firstOrFail();

        $this->actingAs($this->manajer())
            ->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 3]]))
            ->assertRedirect();

        // Sisa tinggal 2, mencoba retur 3 lagi harus ditolak.
        $this->actingAs($this->manajer())
            ->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 3]]))
            ->assertSessionHasErrors('lines.0.qty');

        $this->assertSame(1, SalesReturn::count());
    }

    public function test_retur_boleh_sebagian_berkali_kali_sampai_habis(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $sale = $this->jual('RNS-045', 5);
        $saleItem = $sale->items->firstOrFail();

        $this->actingAs($this->manajer())->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 2]]));
        $this->actingAs($this->manajer())
            ->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 3]]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, SalesReturn::count());
    }

    public function test_refund_dihitung_dari_harga_jual_bukan_hpp(): void
    {
        $this->isiStok('GLA-CRH', 10, 5000, 'KG');
        $sale = $this->jual('GLA-CRH', 4, 'KG');
        $saleItem = $sale->items->firstOrFail();

        $this->actingAs($this->manajer())
            ->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 2]]))
            ->assertRedirect();

        $return = SalesReturn::firstOrFail();
        $expectedRefund = round(2 * (float) $saleItem->unit_price_base, 2);

        $this->assertSame($expectedRefund, (float) $return->refund_total);
        $this->assertNotEquals(4000.0, (float) $return->refund_total); // bukan HPP (2 × 2000)
    }

    // -------------------------------------------------------------- visibilitas

    public function test_kasir_boleh_membuat_retur_tapi_tidak_melihat_hpp(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $sale = $this->jual('RNS-045', 4);
        $saleItem = $sale->items->firstOrFail();

        $this->actingAs($this->kasir())
            ->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 1]]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $response = $this->actingAs($this->kasir())->get('/retur/penjualan');
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['canInput']);
        $this->assertFalse($props['canSeeCost']);
        $this->assertFalse($props['canVoid']);
        $this->assertNull($props['returns']['data'][0]['items'][0]['costAtReturn']);
    }

    public function test_petugas_gudang_tidak_boleh_membuat_retur_penjualan(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $sale = $this->jual('RNS-045', 4);
        $saleItem = $sale->items->firstOrFail();

        $this->actingAs($this->gudang())
            ->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 1]]))
            ->assertForbidden();

        $this->assertDatabaseCount('sales_returns', 0);
    }

    // -------------------------------------------------------------------- void

    public function test_void_retur_mengeluarkan_stok_lagi(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $sale = $this->jual('RNS-045', 4);
        $saleItem = $sale->items->firstOrFail();

        $this->actingAs($this->manajer())->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 2]]));
        $return = SalesReturn::firstOrFail();

        $sebelum = $this->stok('RNS-045');

        $this->actingAs($this->manajer())
            ->post("/retur/penjualan/{$return->return_id}/batal")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($sebelum - 2, $this->stok('RNS-045'));
        $this->assertDatabaseHas('sales_returns', ['return_id' => $return->return_id, 'status' => 'VOID']);
    }

    public function test_kasir_tidak_boleh_membatalkan_retur(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $sale = $this->jual('RNS-045', 4);
        $saleItem = $sale->items->firstOrFail();

        $this->actingAs($this->manajer())->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 2]]));
        $return = SalesReturn::firstOrFail();

        $this->actingAs($this->kasir())
            ->post("/retur/penjualan/{$return->return_id}/batal")
            ->assertForbidden();
    }

    public function test_void_retur_ditolak_bila_barangnya_sudah_terjual_lagi(): void
    {
        $this->isiStok('RNS-045', 10, 2000);
        $sale = $this->jual('RNS-045', 4);
        $saleItem = $sale->items->firstOrFail();

        $this->actingAs($this->manajer())->post('/retur/penjualan', $this->isian($sale, [['sale_item_id' => $saleItem->sale_item_id, 'qty' => 2]]));
        $return = SalesReturn::firstOrFail();

        // Barang yang baru kembali langsung terjual lagi ke pelanggan lain.
        $this->jual('RNS-045', 8);

        $this->actingAs($this->manajer())
            ->post("/retur/penjualan/{$return->return_id}/batal")
            ->assertSessionHasErrors('receipt');
    }
}

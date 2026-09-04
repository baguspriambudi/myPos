<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retur pembelian (Addendum 48.2, 58.6).
 *
 * Yang dijaga: retur mengurangi LOT PENERIMAAN ASAL (bukan FIFO umum), ditolak
 * bila lot sudah terjual sebagian, qty retur tidak melebihi qty diterima, akses
 * dibatasi MANAGER ke atas seperti faktur pemasok, dan void mengembalikan lot.
 */
class PurchaseReturnTest extends TestCase
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

    private function pemasok(): Supplier
    {
        return Supplier::firstOrFail();
    }

    private function stok(string $sku): float
    {
        return app(StockLedger::class)->onHand($this->pusat->branch_id, $this->produk($sku)->product_id);
    }

    private function terima(string $sku, float $qty): GoodsReceipt
    {
        $product = $this->produk($sku);

        $this->actingAs($this->manajer())->post('/penerimaan', [
            'supplier_id' => $this->pemasok()->supplier_id,
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->product_id,
                'uom_id' => $product->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => $qty,
            ]],
        ])->assertRedirect();

        return GoodsReceipt::orderByDesc('goods_receipt_id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function isian(GoodsReceipt $receipt, array $lines, array $extra = []): array
    {
        return array_merge([
            'goods_receipt_id' => $receipt->goods_receipt_id,
            'transaction_date' => now()->toDateString(),
            'lines' => $lines,
        ], $extra);
    }

    // -------------------------------------------------------------- posting

    public function test_retur_mengurangi_lot_penerimaan_asal_dan_stok(): void
    {
        $receipt = $this->terima('RNS-045', 10);
        $item = $receipt->items->firstOrFail();
        $lot = StockLot::where('receipt_item_id', $item->receipt_item_id)->firstOrFail();

        $this->actingAs($this->manajer())
            ->post('/retur/pembelian', $this->isian($receipt, [['receipt_item_id' => $item->receipt_item_id, 'qty' => 3]]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(7.0, $this->stok('RNS-045'));

        $lot->refresh();
        $this->assertSame(7.0, (float) $lot->qty_remaining);

        $this->assertDatabaseHas('purchase_return_items', [
            'receipt_item_id' => $item->receipt_item_id,
            'qty_base' => 3,
            'cost_at_return' => (float) $lot->unit_cost,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'RETURN_OUT',
            'reference_type' => 'PURCHASE_RETURN',
            'qty_base' => -3,
        ]);
    }

    public function test_qty_retur_tidak_boleh_melebihi_qty_diterima(): void
    {
        $receipt = $this->terima('RNS-045', 5);
        $item = $receipt->items->firstOrFail();

        $this->actingAs($this->manajer())
            ->post('/retur/pembelian', $this->isian($receipt, [['receipt_item_id' => $item->receipt_item_id, 'qty' => 8]]))
            ->assertSessionHasErrors('lines.0.qty');

        $this->assertDatabaseCount('purchase_returns', 0);
    }

    public function test_retur_ditolak_bila_lot_sudah_terjual_sebagian(): void
    {
        $receipt = $this->terima('RNS-045', 10);
        $item = $receipt->items->firstOrFail();

        $rinso = $this->produk('RNS-045');
        $this->actingAs($this->kasir())->post('/kasir/nota', [
            'payment_type' => 'CASH',
            'lines' => [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 6,
            ]],
        ])->assertSessionHas('nota');

        // Tersisa 4 di lot; mencoba meretur 5 harus ditolak.
        $this->actingAs($this->manajer())
            ->post('/retur/pembelian', $this->isian($receipt, [['receipt_item_id' => $item->receipt_item_id, 'qty' => 5]]))
            ->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('purchase_returns', 0);
        $this->assertSame(4.0, $this->stok('RNS-045'));
    }

    public function test_retur_boleh_sebagian_berkali_kali(): void
    {
        $receipt = $this->terima('RNS-045', 10);
        $item = $receipt->items->firstOrFail();

        $this->actingAs($this->manajer())->post('/retur/pembelian', $this->isian($receipt, [['receipt_item_id' => $item->receipt_item_id, 'qty' => 3]]));
        $this->actingAs($this->manajer())
            ->post('/retur/pembelian', $this->isian($receipt, [['receipt_item_id' => $item->receipt_item_id, 'qty' => 4]]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, PurchaseReturn::count());
        $this->assertSame(3.0, $this->stok('RNS-045'));
    }

    // ----------------------------------------------------------- hak akses

    public function test_hanya_manajer_ke_atas_boleh_menangani_retur_pembelian(): void
    {
        $receipt = $this->terima('RNS-045', 10);
        $item = $receipt->items->firstOrFail();

        foreach (['kasir', 'gudang'] as $username) {
            $user = User::where('username', $username)->firstOrFail();

            $this->actingAs($user)->get('/retur/pembelian')->assertForbidden();

            $this->actingAs($user)
                ->post('/retur/pembelian', $this->isian($receipt, [['receipt_item_id' => $item->receipt_item_id, 'qty' => 1]]))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('purchase_returns', 0);
    }

    // -------------------------------------------------------------------- void

    public function test_void_retur_mengembalikan_lot(): void
    {
        $receipt = $this->terima('RNS-045', 10);
        $item = $receipt->items->firstOrFail();
        $lot = StockLot::where('receipt_item_id', $item->receipt_item_id)->firstOrFail();

        $this->actingAs($this->manajer())->post('/retur/pembelian', $this->isian($receipt, [['receipt_item_id' => $item->receipt_item_id, 'qty' => 3]]));
        $return = PurchaseReturn::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/retur/pembelian/{$return->return_id}/batal")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(10.0, $this->stok('RNS-045'));

        $lot->refresh();
        $this->assertSame(10.0, (float) $lot->qty_remaining);
        $this->assertDatabaseHas('purchase_returns', ['return_id' => $return->return_id, 'status' => 'VOID']);
    }
}

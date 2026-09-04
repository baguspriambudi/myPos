<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faktur pemasok (Addendum 58.3, 58.4, 58.6, 58.7).
 *
 * Faktur dibuat DARI penerimaan yang belum ditagih, bukan dari daftar produk
 * kosong — jadi yang dijaga di sini adalah bahwa mustahil menagih barang yang
 * tak pernah datang, mustahil menagih dua kali, dan pembatalannya melepas
 * penerimaan kembali ke status menggantung, bukan menyentuh stok.
 */
class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);
    }

    private function manajer(): User
    {
        return User::where('username', 'manajer')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function pemasok(string $name): Supplier
    {
        return Supplier::where('supplier_name', $name)->firstOrFail();
    }

    /** Membuat penerimaan lewat HTTP supaya lot dan cost sementaranya nyata. */
    private function terima(Supplier $supplier, string $sku, string $uomName, float $qty): GoodsReceipt
    {
        $product = $this->produk($sku);

        $this->actingAs($this->manajer())->post('/penerimaan', [
            'supplier_id' => $supplier->supplier_id,
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->product_id,
                'uom_id' => $product->uoms->firstWhere('uom_name', $uomName)->uom_id,
                'qty' => $qty,
            ]],
        ])->assertRedirect();

        return GoodsReceipt::orderByDesc('goods_receipt_id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function isian(Supplier $supplier, array $receiptIds, array $extra = []): array
    {
        return array_merge([
            'supplier_id' => $supplier->supplier_id,
            'transaction_date' => now()->toDateString(),
            'receipt_ids' => $receiptIds,
        ], $extra);
    }

    // -------------------------------------------------------------- posting

    public function test_faktur_mengakui_utang_dan_menandai_penerimaan(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $receipt = $this->terima($unilever, 'RNS-045', 'PCS', 10);
        $item = $receipt->items->firstOrFail();

        $this->actingAs($this->manajer())
            ->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
                'due_date' => now()->addDays(30)->toDateString(),
                'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2200]],
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $purchase = Purchase::firstOrFail();
        $this->assertSame(22000.0, (float) $purchase->subtotal);
        $this->assertSame(22000.0, (float) $purchase->total);
        $this->assertSame('POSTED', $purchase->status);

        $this->assertDatabaseHas('payables', [
            'purchase_id' => $purchase->purchase_id,
            'amount' => 22000,
            'status' => 'OPEN',
        ]);

        $receipt->refresh();
        $this->assertSame($purchase->purchase_id, $receipt->purchase_id);
        $this->assertNotNull($receipt->invoiced_at);
    }

    public function test_pajak_dihitung_dari_subtotal(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $receipt = $this->terima($unilever, 'RNS-045', 'PCS', 10);
        $item = $receipt->items->firstOrFail();
        $ppn = Tax::where('tax_name', 'PPN 11%')->firstOrFail();

        $this->actingAs($this->manajer())->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
            'tax_id' => $ppn->tax_id,
            'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2000]],
        ]))->assertRedirect();

        $purchase = Purchase::firstOrFail();
        $this->assertSame(20000.0, (float) $purchase->subtotal);
        $this->assertSame(2200.0, (float) $purchase->tax_total);
        $this->assertSame(22200.0, (float) $purchase->total);
    }

    public function test_faktur_mencakup_beberapa_penerimaan_sekaligus(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $r1 = $this->terima($unilever, 'RNS-045', 'PCS', 5);
        $r2 = $this->terima($unilever, 'AQU-600', 'PCS', 3);

        $this->actingAs($this->manajer())->post('/pembelian', $this->isian($unilever, [$r1->goods_receipt_id, $r2->goods_receipt_id], [
            'lines' => [
                ['receipt_item_id' => $r1->items->firstOrFail()->receipt_item_id, 'qty' => 5, 'unit_cost' => 2000],
                ['receipt_item_id' => $r2->items->firstOrFail()->receipt_item_id, 'qty' => 3, 'unit_cost' => 3000],
            ],
        ]))->assertRedirect();

        $purchase = Purchase::firstOrFail();
        $this->assertSame(19000.0, (float) $purchase->subtotal); // 5*2000 + 3*3000
        $this->assertSame(2, $purchase->items()->count());
        $this->assertSame(2, $purchase->receipts()->count());
    }

    // ------------------------------------------------------------- validasi

    public function test_penerimaan_tidak_dapat_difakturkan_dua_kali(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $receipt = $this->terima($unilever, 'RNS-045', 'PCS', 10);
        $item = $receipt->items->firstOrFail();

        $this->actingAs($this->manajer())->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
            'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2000]],
        ]))->assertRedirect();

        $this->actingAs($this->manajer())
            ->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
                'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2000]],
            ]))
            ->assertSessionHasErrors('receipt_ids');

        $this->assertSame(1, Purchase::count());
    }

    public function test_qty_faktur_melebihi_penerimaan_ditolak(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $receipt = $this->terima($unilever, 'RNS-045', 'PCS', 5);
        $item = $receipt->items->firstOrFail();

        $this->actingAs($this->manajer())
            ->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
                'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2000]],
            ]))
            ->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_qty_faktur_lebih_kecil_dari_penerimaan_diperbolehkan(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $receipt = $this->terima($unilever, 'RNS-045', 'PCS', 10);
        $item = $receipt->items->firstOrFail();

        $this->actingAs($this->manajer())
            ->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
                'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 8, 'unit_cost' => 2000]],
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(16000.0, (float) Purchase::firstOrFail()->subtotal);
    }

    public function test_penerimaan_dari_pemasok_lain_ditolak(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $indofood = $this->pemasok('PT Indofood Sukses Makmur');
        $receipt = $this->terima($unilever, 'RNS-045', 'PCS', 10);
        $item = $receipt->items->firstOrFail();

        $this->actingAs($this->manajer())
            ->post('/pembelian', $this->isian($indofood, [$receipt->goods_receipt_id], [
                'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2000]],
            ]))
            ->assertSessionHasErrors('receipt_ids');
    }

    // ----------------------------------------------------------- hak akses

    public function test_hanya_manajer_ke_atas_boleh_menangani_faktur(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $receipt = $this->terima($unilever, 'RNS-045', 'PCS', 10);
        $item = $receipt->items->firstOrFail();

        foreach (['kasir', 'gudang'] as $username) {
            $user = User::where('username', $username)->firstOrFail();

            $this->actingAs($user)->get('/pembelian')->assertForbidden();

            $this->actingAs($user)
                ->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
                    'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2000]],
                ]))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('purchases', 0);
    }

    // -------------------------------------------------------------------- void

    public function test_void_melepas_penerimaan_kembali_menggantung(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $receipt = $this->terima($unilever, 'RNS-045', 'PCS', 10);
        $item = $receipt->items->firstOrFail();

        $this->actingAs($this->manajer())->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
            'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2000]],
        ]));

        $purchase = Purchase::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/pembelian/{$purchase->purchase_id}/batal")
            ->assertRedirect()
            ->assertSessionHas('success');

        $purchase->refresh();
        $this->assertSame('VOID', $purchase->status);

        $receipt->refresh();
        $this->assertNull($receipt->purchase_id);
        $this->assertNull($receipt->invoiced_at);

        $this->assertDatabaseHas('payables', ['purchase_id' => $purchase->purchase_id, 'status' => 'VOID']);

        // Stok tidak tersentuh — faktur memang tidak pernah menambahnya.
        $rinso = $this->produk('RNS-045');
        $this->assertSame(10.0, app(StockLedger::class)->onHand($receipt->branch_id, $rinso->product_id));
    }

    public function test_penerimaan_dapat_difakturkan_ulang_setelah_faktur_dibatalkan(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $receipt = $this->terima($unilever, 'RNS-045', 'PCS', 10);
        $item = $receipt->items->firstOrFail();

        $this->actingAs($this->manajer())->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
            'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2000]],
        ]));

        $purchase = Purchase::firstOrFail();
        $this->actingAs($this->manajer())->post("/pembelian/{$purchase->purchase_id}/batal");

        $this->actingAs($this->manajer())
            ->post('/pembelian', $this->isian($unilever, [$receipt->goods_receipt_id], [
                'lines' => [['receipt_item_id' => $item->receipt_item_id, 'qty' => 10, 'unit_cost' => 2100]],
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, Purchase::count());
    }
}

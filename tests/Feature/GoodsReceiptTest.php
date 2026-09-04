<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penerimaan barang (Addendum 58.2, 58.6, 58.7).
 *
 * Yang dijaga: penerimaan menambah stok dan membentuk lot, cost sementaranya
 * berurutan (terakhir dibeli → rata-rata berjalan → nol), kasir/gudang tidak
 * pernah melihat harga, dan pembatalannya ditolak begitu barangnya sudah
 * dipakai — baik lewat penjualan maupun faktur.
 */
class GoodsReceiptTest extends TestCase
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

    private function baris(string $sku, string $uomName, float $qty): array
    {
        $product = $this->produk($sku);

        return [
            'product_id' => $product->product_id,
            'uom_id' => $product->uoms->firstWhere('uom_name', $uomName)->uom_id,
            'qty' => $qty,
        ];
    }

    /** @return array<string, mixed> */
    private function isian(array $lines, array $extra = []): array
    {
        return array_merge([
            'receipt_date' => now()->toDateString(),
            'lines' => $lines,
        ], $extra);
    }

    // -------------------------------------------------------------- posting

    public function test_penerimaan_menambah_saldo_dan_membentuk_lot(): void
    {
        $this->actingAs($this->manajer())
            ->post('/penerimaan', $this->isian([$this->baris('RNS-045', 'PCS', 10)]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(10.0, $this->stok('RNS-045'));

        $receipt = GoodsReceipt::firstOrFail();
        $this->assertSame('POSTED', $receipt->status);

        $item = $receipt->items->firstOrFail();
        $lot = StockLot::where('receipt_item_id', $item->receipt_item_id)->firstOrFail();
        $this->assertSame(10.0, (float) $lot->qty_remaining);

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'PURCHASE',
            'reference_type' => 'RECEIPT',
            'reference_id' => $receipt->goods_receipt_id,
            'qty_base' => 10,
        ]);
    }

    public function test_satuan_besar_dikonversi_ke_satuan_dasar(): void
    {
        $this->actingAs($this->manajer())
            ->post('/penerimaan', $this->isian([$this->baris('RNS-045', 'DUS', 2)]))
            ->assertRedirect();

        // 2 dus × 12 = 24 pcs.
        $this->assertSame(24.0, $this->stok('RNS-045'));
    }

    public function test_semua_peran_boleh_mencatat_penerimaan(): void
    {
        foreach (['kasir', 'gudang', 'manajer'] as $username) {
            $this->actingAs(User::where('username', $username)->firstOrFail())
                ->post('/penerimaan', $this->isian([$this->baris('AQU-600', 'PCS', 1)]))
                ->assertRedirect()
                ->assertSessionHas('success');
        }

        $this->assertSame(3, GoodsReceipt::count());
    }

    // ---------------------------------------------------------- cost sementara

    public function test_cost_sementara_tanpa_riwayat_bernilai_nol_dan_ditandai_none(): void
    {
        $this->actingAs($this->manajer())->post('/penerimaan', $this->isian([$this->baris('AQU-600', 'PCS', 5)]));

        $this->assertDatabaseHas('goods_receipt_items', [
            'unit_cost' => 0,
            'cost_source' => 'NONE',
        ]);
    }

    public function test_cost_sementara_memakai_rata_rata_berjalan_bila_ada(): void
    {
        $rinso = $this->produk('RNS-045');
        $periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();

        // Saldo awal dibentuk langsung lewat ledger, bukan lewat penerimaan,
        // supaya avg_cost bersih 2.000 tanpa campuran cost lain.
        DB::transaction(fn () => app(StockLedger::class)->receive(
            $periode,
            $this->pusat->branch_id,
            now()->toDateString(),
            $rinso,
            $rinso->uoms->firstWhere('uom_name', 'PCS'),
            10,
            2000,
        ));

        $this->actingAs($this->manajer())->post('/penerimaan', $this->isian([$this->baris('RNS-045', 'PCS', 5)]));

        $this->assertDatabaseHas('goods_receipt_items', [
            'unit_cost' => 2000,
            'cost_source' => 'AVG_COST',
        ]);
    }

    public function test_cost_sementara_memakai_harga_beli_terakhir_bila_ada(): void
    {
        $supplier = Supplier::firstOrFail();

        $this->actingAs($this->manajer())->post('/penerimaan', $this->isian(
            [$this->baris('GLA-CRH', 'KG', 5)],
            ['supplier_id' => $supplier->supplier_id],
        ));

        $receipt = GoodsReceipt::firstOrFail();

        $this->actingAs($this->manajer())->post('/pembelian', [
            'supplier_id' => $supplier->supplier_id,
            'transaction_date' => now()->toDateString(),
            'receipt_ids' => [$receipt->goods_receipt_id],
            'lines' => [[
                'receipt_item_id' => $receipt->items->firstOrFail()->receipt_item_id,
                'qty' => 5,
                'unit_cost' => 12500,
            ]],
        ])->assertRedirect();

        // Penerimaan berikutnya untuk produk yang sama harus memakai harga
        // faktur yang baru saja diposting, bukan lagi rata-rata berjalan.
        $this->actingAs($this->manajer())->post('/penerimaan', $this->isian([$this->baris('GLA-CRH', 'KG', 3)]));

        $terbaru = GoodsReceiptItem::where('cost_source', 'LAST_PURCHASE')->firstOrFail();
        $this->assertSame(12500.0, (float) $terbaru->unit_cost);
    }

    // -------------------------------------------------------------- visibilitas

    public function test_kasir_dan_gudang_tidak_melihat_harga_penerimaan(): void
    {
        foreach ([$this->kasir(), $this->gudang()] as $user) {
            // Masing-masing mencatat penerimaannya sendiri di lokasinya sendiri
            // supaya layarnya punya baris untuk diperiksa.
            $this->actingAs($user)->post('/penerimaan', $this->isian([$this->baris('RNS-045', 'PCS', 10)]));

            $response = $this->actingAs($user)->get('/penerimaan');
            $response->assertOk();

            $props = $response->viewData('page')['props'];
            $this->assertFalse($props['canSeeCost']);
            $this->assertFalse($props['canVoid']);

            $baris = $props['receipts']['data'][0];
            $this->assertNull($baris['totalCost']);
            $this->assertNull($baris['items'][0]['unitCost']);
        }
    }

    public function test_manajer_ke_atas_melihat_harga_penerimaan(): void
    {
        $this->actingAs($this->manajer())->post('/penerimaan', $this->isian([$this->baris('RNS-045', 'PCS', 10)]));

        $response = $this->actingAs($this->manajer())->get('/penerimaan');
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['canSeeCost']);
        $this->assertTrue($props['canVoid']);
        $this->assertNotNull($props['receipts']['data'][0]['totalCost']);
    }

    // -------------------------------------------------------------------- void

    public function test_void_menarik_kembali_stok_yang_masuk(): void
    {
        $this->actingAs($this->manajer())->post('/penerimaan', $this->isian([$this->baris('RNS-045', 'PCS', 10)]));
        $receipt = GoodsReceipt::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/penerimaan/{$receipt->goods_receipt_id}/batal")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0.0, $this->stok('RNS-045'));
        $this->assertDatabaseHas('goods_receipts', ['goods_receipt_id' => $receipt->goods_receipt_id, 'status' => 'VOID']);
    }

    public function test_void_ditolak_bila_sudah_difakturkan(): void
    {
        $supplier = Supplier::firstOrFail();

        $this->actingAs($this->manajer())->post('/penerimaan', $this->isian(
            [$this->baris('RNS-045', 'PCS', 10)],
            ['supplier_id' => $supplier->supplier_id],
        ));
        $receipt = GoodsReceipt::firstOrFail();

        $this->actingAs($this->manajer())->post('/pembelian', [
            'supplier_id' => $supplier->supplier_id,
            'transaction_date' => now()->toDateString(),
            'receipt_ids' => [$receipt->goods_receipt_id],
            'lines' => [[
                'receipt_item_id' => $receipt->items->firstOrFail()->receipt_item_id,
                'qty' => 10,
                'unit_cost' => 2000,
            ]],
        ]);

        $this->actingAs($this->manajer())
            ->post("/penerimaan/{$receipt->goods_receipt_id}/batal")
            ->assertSessionHasErrors('receipt');

        $this->assertDatabaseHas('goods_receipts', ['goods_receipt_id' => $receipt->goods_receipt_id, 'status' => 'POSTED']);
        $this->assertSame(10.0, $this->stok('RNS-045'));
    }

    public function test_void_ditolak_bila_sebagian_sudah_terjual(): void
    {
        $this->actingAs($this->manajer())->post('/penerimaan', $this->isian([$this->baris('RNS-045', 'PCS', 10)]));
        $receipt = GoodsReceipt::firstOrFail();

        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->kasir())->post('/kasir/nota', [
            'payment_type' => 'CASH',
            'lines' => [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 4,
            ]],
        ])->assertSessionHas('nota');

        $this->actingAs($this->manajer())
            ->post("/penerimaan/{$receipt->goods_receipt_id}/batal")
            ->assertSessionHasErrors('receipt');

        $this->assertSame(6.0, $this->stok('RNS-045'));
    }

    public function test_void_hanya_boleh_manajer_ke_atas(): void
    {
        $this->actingAs($this->manajer())->post('/penerimaan', $this->isian([$this->baris('RNS-045', 'PCS', 10)]));
        $receipt = GoodsReceipt::firstOrFail();

        foreach (['kasir', 'gudang'] as $username) {
            $this->actingAs(User::where('username', $username)->firstOrFail())
                ->post("/penerimaan/{$receipt->goods_receipt_id}/batal")
                ->assertForbidden();
        }

        $this->assertSame(10.0, $this->stok('RNS-045'));
    }
}

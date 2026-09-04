<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penyesuaian stok (Addendum 40.4, 50, 55.3).
 *
 * Penyesuaian adalah satu-satunya jalan mengubah saldo tanpa dokumen jual-beli,
 * jadi yang dijaga di sini bukan cuma angkanya berubah, melainkan bahwa
 * perubahannya bernomor, beralasan, dan hanya boleh dilakukan yang berwenang.
 */
class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);
        $this->pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
    }

    private function manajer(): User
    {
        return User::where('username', 'manajer')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function isian(string $sku, string $uomName, array $extra = []): array
    {
        $product = $this->produk($sku);

        return array_merge([
            'product_id' => $product->product_id,
            'uom_id' => $product->uoms->firstWhere('uom_name', $uomName)->uom_id,
            'adjustment_type' => 'IN',
            'qty' => 10,
            'unit_cost' => 2000,
            'reason' => 'Stok awal saat sistem mulai dipakai.',
            'adjustment_date' => now()->toDateString(),
        ], $extra);
    }

    private function stok(string $sku): float
    {
        return app(StockLedger::class)->onHand($this->pusat->branch_id, $this->produk($sku)->product_id);
    }

    // ---------------------------------------------------------------- masuk

    public function test_penyesuaian_masuk_menambah_saldo_dan_membentuk_lot(): void
    {
        $this->actingAs($this->manajer())
            ->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(10.0, $this->stok('RNS-045'));

        $this->assertDatabaseHas('stock_adjustments', [
            'adjustment_type' => 'IN',
            'qty_base' => 10,
            'unit_cost' => 2000,
            'status' => 'POSTED',
        ]);

        // Lot terbentuk supaya FIFO dan retur punya asal barang yang jelas.
        $lot = StockLot::firstOrFail();
        $this->assertSame(10.0, (float) $lot->qty_remaining);
        $this->assertSame(2000.0, (float) $lot->unit_cost);

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => 'ADJUSTMENT_IN',
            'reference_type' => 'ADJUSTMENT',
            'qty_base' => 10,
        ]);
    }

    public function test_satuan_besar_dikonversi_ke_satuan_dasar(): void
    {
        $this->actingAs($this->manajer())
            ->post('/stok/penyesuaian', $this->isian('RNS-045', 'DUS', ['qty' => 2]))
            ->assertRedirect();

        // 2 dus × 12 = 24 pcs.
        $this->assertSame(24.0, $this->stok('RNS-045'));
    }

    public function test_harga_perolehan_kosong_memakai_rata_rata_berjalan(): void
    {
        $this->actingAs($this->manajer())->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS', ['unit_cost' => 3000]));

        $this->actingAs($this->manajer())->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS', [
            'unit_cost' => null,
            'reason' => 'Temuan tambahan saat penataan gudang.',
        ]));

        // Rata-rata berjalan masih 3.000, jadi baris kedua ikut bernilai itu.
        $this->assertDatabaseHas('stock_adjustments', ['unit_cost' => 3000, 'reason' => 'Temuan tambahan saat penataan gudang.']);
    }

    // --------------------------------------------------------------- keluar

    public function test_penyesuaian_keluar_memakai_harga_lot_dan_mengurangi_saldo(): void
    {
        $this->actingAs($this->manajer())->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS', ['qty' => 10, 'unit_cost' => 2000]));

        $this->actingAs($this->manajer())
            ->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS', [
                'adjustment_type' => 'OUT',
                'qty' => 3,
                'unit_cost' => null,
                'reason' => 'Tiga sachet rusak terkena air.',
            ]))
            ->assertRedirect();

        $this->assertSame(7.0, $this->stok('RNS-045'));

        // Harga keluar tidak diisi pengguna; diambil dari lot yang terpakai.
        $this->assertDatabaseHas('stock_adjustments', ['adjustment_type' => 'OUT', 'unit_cost' => 2000]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'ADJUSTMENT_OUT', 'qty_base' => -3]);
    }

    public function test_penyesuaian_keluar_melebihi_stok_ditolak(): void
    {
        $this->actingAs($this->manajer())->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS', ['qty' => 2]));

        $this->actingAs($this->manajer())
            ->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS', [
                'adjustment_type' => 'OUT',
                'qty' => 5,
                'reason' => 'Percobaan mengeluarkan lebih dari yang ada.',
            ]))
            ->assertSessionHasErrors('qty');

        $this->assertSame(2.0, $this->stok('RNS-045'));
    }

    // ------------------------------------------------------------- validasi

    public function test_jasa_tidak_dapat_disesuaikan(): void
    {
        $this->actingAs($this->manajer())
            ->post('/stok/penyesuaian', $this->isian('JSA-ANTAR', 'KALI'))
            ->assertSessionHasErrors('product_id');
    }

    public function test_alasan_wajib_diisi(): void
    {
        $this->actingAs($this->manajer())
            ->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS', ['reason' => 'x']))
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_produk_bukan_timbangan_menolak_qty_pecahan(): void
    {
        $this->actingAs($this->manajer())
            ->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS', ['qty' => 1.5]))
            ->assertSessionHasErrors('qty');
    }

    public function test_produk_timbangan_menerima_qty_pecahan(): void
    {
        $this->actingAs($this->manajer())
            ->post('/stok/penyesuaian', $this->isian('GLA-CRH', 'KG', ['qty' => 2.5, 'unit_cost' => 12000]))
            ->assertRedirect();

        $this->assertSame(2.5, $this->stok('GLA-CRH'));
    }

    // ----------------------------------------------------------- hak akses

    public function test_kasir_tidak_boleh_memposting_penyesuaian(): void
    {
        $this->actingAs(User::where('username', 'kasir')->firstOrFail())
            ->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS'))
            ->assertForbidden();

        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_petugas_gudang_tidak_boleh_memposting_penyesuaian(): void
    {
        $this->actingAs(User::where('username', 'gudang')->firstOrFail())
            ->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------- penomoran

    public function test_nomor_penyesuaian_berurut_per_lokasi(): void
    {
        $periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();

        $this->actingAs($this->manajer())->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS'));
        $this->actingAs($this->manajer())->post('/stok/penyesuaian', $this->isian('AQU-600', 'PCS', ['unit_cost' => 3000]));

        $this->assertDatabaseHas('stock_adjustments', ['adjustment_number' => "ADJ/BR01/{$periode->period_code}/000001"]);
        $this->assertDatabaseHas('stock_adjustments', ['adjustment_number' => "ADJ/BR01/{$periode->period_code}/000002"]);
    }

    // ------------------------------------------------------------- integrasi

    public function test_barang_dari_penyesuaian_dapat_dijual_dengan_hpp_yang_benar(): void
    {
        $this->actingAs($this->manajer())->post('/stok/penyesuaian', $this->isian('RNS-045', 'PCS', ['qty' => 20, 'unit_cost' => 1800]));

        $rinso = $this->produk('RNS-045');

        $this->actingAs(User::where('username', 'kasir')->firstOrFail())
            ->post('/kasir/nota', [
                'payment_type' => 'CASH',
                'lines' => [[
                    'product_id' => $rinso->product_id,
                    'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                    'qty' => 4,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHas('nota');

        $this->assertSame(16.0, $this->stok('RNS-045'));
        // HPP mengikuti harga perolehan yang diisikan pada penyesuaian.
        $this->assertDatabaseHas('sale_items', ['qty_base' => 4, 'cost_at_sale' => 1800, 'cogs_total' => 7200]);
    }
}

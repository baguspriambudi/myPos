<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Transfer antar-lokasi (Addendum 49).
 *
 * Yang dijaga: kedua sisi bergerak bersamaan dalam satu transaksi, cost yang
 * dibawa adalah cost pengirim (tanpa margin), kedua lokasi wajib punya
 * periode terbuka, GUDANG boleh memposting tapi tidak melihat harga, dan void
 * hanya boleh dari sisi pengirim serta ditolak bila barangnya sudah terpakai
 * di lokasi tujuan.
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private Branch $pusat;

    private Branch $timur;

    private Branch $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);
        $this->pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
        $this->timur = Branch::where('branch_code', 'BR02')->firstOrFail();
        $this->gudang = Branch::where('branch_code', 'WH01')->firstOrFail();
    }

    private function manajer(): User
    {
        return User::where('username', 'manajer')->firstOrFail();
    }

    private function kasir(): User
    {
        return User::where('username', 'kasir')->firstOrFail();
    }

    private function petugasGudang(): User
    {
        return User::where('username', 'gudang')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function stok(Branch $branch, string $sku): float
    {
        return app(StockLedger::class)->onHand($branch->branch_id, $this->produk($sku)->product_id);
    }

    private function isiStok(Branch $branch, string $sku, float $qty, float $unitCost, string $uomName = 'PCS'): void
    {
        $product = $this->produk($sku);
        $periode = FiscalPeriod::where('branch_id', $branch->branch_id)->firstOrFail();

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $periode,
            $branch->branch_id,
            now()->toDateString(),
            $product,
            $product->uoms->firstWhere('uom_name', $uomName),
            $qty,
            $unitCost,
        ));
    }

    private function pindahAktif(User $user, Branch $branch): void
    {
        $this->actingAs($user)->post('/lokasi-aktif', ['branch_id' => $branch->branch_id])->assertRedirect();
    }

    /** @return array<string, mixed> */
    private function isian(Branch $to, array $lines, array $extra = []): array
    {
        return array_merge([
            'to_branch_id' => $to->branch_id,
            'transaction_date' => now()->toDateString(),
            'lines' => $lines,
        ], $extra);
    }

    // -------------------------------------------------------------- posting

    public function test_transfer_memindahkan_stok_dengan_cost_pengirim(): void
    {
        $this->isiStok($this->pusat, 'RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())
            ->post('/stok/transfer', $this->isian($this->timur, [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 4,
            ]]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(6.0, $this->stok($this->pusat, 'RNS-045'));
        $this->assertSame(4.0, $this->stok($this->timur, 'RNS-045'));

        $this->assertDatabaseHas('stock_transfer_items', ['qty_base' => 4, 'unit_cost' => 2000]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'TRANSFER_OUT', 'branch_id' => $this->pusat->branch_id, 'qty_base' => -4]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'TRANSFER_IN', 'branch_id' => $this->timur->branch_id, 'qty_base' => 4]);
    }

    public function test_transfer_ditolak_bila_stok_tidak_cukup(): void
    {
        $this->isiStok($this->pusat, 'RNS-045', 3, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())
            ->post('/stok/transfer', $this->isian($this->timur, [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 5,
            ]]))
            ->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_transfer_ditolak_bila_tujuan_sama_dengan_asal(): void
    {
        $this->isiStok($this->pusat, 'RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())
            ->post('/stok/transfer', $this->isian($this->pusat, [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 2,
            ]]))
            ->assertSessionHasErrors('to_branch_id');
    }

    public function test_transfer_ditolak_bila_lokasi_tujuan_tidak_punya_periode_terbuka(): void
    {
        $this->isiStok($this->pusat, 'RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        FiscalPeriod::where('branch_id', $this->timur->branch_id)->update(['status' => 'CLOSED']);

        $this->actingAs($this->manajer())
            ->post('/stok/transfer', $this->isian($this->timur, [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 2,
            ]]))
            ->assertSessionHasErrors('to_branch_id');

        // Sisi pengirim tidak boleh ikut bergerak walau tujuannya yang gagal.
        $this->assertSame(10.0, $this->stok($this->pusat, 'RNS-045'));
        $this->assertDatabaseCount('stock_transfers', 0);
    }

    // -------------------------------------------------------------- akses

    public function test_kasir_tidak_boleh_mengakses_transfer(): void
    {
        $this->actingAs($this->kasir())->get('/stok/transfer')->assertForbidden();

        $rinso = $this->produk('RNS-045');
        $this->actingAs($this->kasir())
            ->post('/stok/transfer', $this->isian($this->timur, [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 1,
            ]]))
            ->assertForbidden();
    }

    public function test_petugas_gudang_boleh_memposting_tapi_tidak_melihat_harga(): void
    {
        $this->isiStok($this->gudang, 'RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->petugasGudang())
            ->post('/stok/transfer', $this->isian($this->pusat, [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 3,
            ]]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $response = $this->actingAs($this->petugasGudang())->get('/stok/transfer');
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['canSeeCost']);
        $this->assertFalse($props['canVoid']);
        $this->assertNull($props['transfers']['data'][0]['items'][0]['unitCost']);
    }

    // -------------------------------------------------------------------- void

    public function test_void_transfer_mengembalikan_stok_kedua_sisi(): void
    {
        $this->isiStok($this->pusat, 'RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())->post('/stok/transfer', $this->isian($this->timur, [[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'qty' => 4,
        ]]));

        $transfer = StockTransfer::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/stok/transfer/{$transfer->transfer_id}/batal")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(10.0, $this->stok($this->pusat, 'RNS-045'));
        $this->assertSame(0.0, $this->stok($this->timur, 'RNS-045'));
        $this->assertDatabaseHas('stock_transfers', ['transfer_id' => $transfer->transfer_id, 'status' => 'VOID']);
    }

    public function test_void_hanya_boleh_dari_sisi_pengirim(): void
    {
        $this->isiStok($this->pusat, 'RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())->post('/stok/transfer', $this->isian($this->timur, [[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'qty' => 4,
        ]]));

        $transfer = StockTransfer::firstOrFail();

        // Manajer pindah aktif ke lokasi tujuan (timur) — void harus ditolak.
        $this->pindahAktif($this->manajer(), $this->timur);

        $this->actingAs($this->manajer())
            ->post("/stok/transfer/{$transfer->transfer_id}/batal")
            ->assertNotFound();

        $this->assertSame(4.0, $this->stok($this->timur, 'RNS-045'));
    }

    public function test_void_ditolak_bila_barang_sudah_terpakai_di_tujuan(): void
    {
        $this->isiStok($this->pusat, 'RNS-045', 10, 2000);
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->manajer())->post('/stok/transfer', $this->isian($this->timur, [[
            'product_id' => $rinso->product_id,
            'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
            'qty' => 4,
        ]]));

        $transfer = StockTransfer::firstOrFail();

        // Sebagian barang yang baru tiba di timur langsung terjual di sana.
        // Kasir tidak berhak di timur, jadi manajer sendiri yang menjualnya.
        $this->pindahAktif($this->manajer(), $this->timur);
        $this->actingAs($this->manajer())->post('/kasir/nota', [
            'payment_type' => 'CASH',
            'lines' => [[
                'product_id' => $rinso->product_id,
                'uom_id' => $rinso->uoms->firstWhere('uom_name', 'PCS')->uom_id,
                'qty' => 2,
            ]],
        ])->assertSessionHas('nota');

        // Kembali ke lokasi pengirim untuk mencoba pembatalan.
        $this->pindahAktif($this->manajer(), $this->pusat);

        $this->actingAs($this->manajer())
            ->post("/stok/transfer/{$transfer->transfer_id}/batal")
            ->assertSessionHasErrors('receipt');

        $this->assertSame(2.0, $this->stok($this->timur, 'RNS-045'));
    }
}

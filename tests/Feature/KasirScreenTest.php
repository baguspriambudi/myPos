<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\User;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Layar kasir dari sisi HTTP: apa yang boleh dilihat, dan siapa yang boleh
 * menerbitkan nota.
 */
class KasirScreenTest extends TestCase
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

    private function baris(string $sku, string $uomName, float $qty): array
    {
        $product = Product::where('sku', $sku)->with('uoms')->firstOrFail();

        return [
            'product_id' => $product->product_id,
            'uom_id' => $product->uoms->firstWhere('uom_name', $uomName)->uom_id,
            'qty' => $qty,
        ];
    }

    public function test_layar_kasir_hanya_memuat_produk_yang_sudah_berharga(): void
    {
        $response = $this->actingAs($this->kasir())->get('/kasir');

        $response->assertOk();

        $katalog = collect($response->viewData('page')['props']['products']);
        $sku = $katalog->pluck('sku');

        $this->assertTrue($sku->contains('RNS-045'));
        $this->assertTrue($sku->contains('JSA-ANTAR'));
        // Aset memang tidak dijual dan tidak diberi harga — ia tidak boleh
        // muncul sebagai kartu produk yang bisa diklik.
        $this->assertFalse($sku->contains('AST-FRZ'));

        // Jasa ditandai tanpa stok supaya layar tidak menghalanginya saat
        // saldo stoknya nol.
        $this->assertFalse($katalog->firstWhere('sku', 'JSA-ANTAR')['tracksStock']);
    }

    public function test_kasir_menerbitkan_nota_dari_layar(): void
    {
        $response = $this->actingAs($this->kasir())->post('/kasir/nota', [
            'payment_type' => 'CASH',
            'cash_received' => 20000,
            'lines' => [$this->baris('JSA-ANTAR', 'KALI', 1)],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('nota');

        $nota = session('nota');
        $this->assertSame(15000.0, $nota['netTotal']);
        $this->assertSame(5000.0, $nota['change']);

        $this->assertDatabaseHas('sales', ['sale_number' => $nota['number'], 'status' => 'POSTED']);
    }

    public function test_stok_berkurang_setelah_nota_terbit(): void
    {
        $rinso = Product::where('sku', 'RNS-045')->with('uoms')->firstOrFail();
        $pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
        $periode = FiscalPeriod::where('branch_id', $pusat->branch_id)->firstOrFail();

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $periode,
            $pusat->branch_id,
            now()->toDateString(),
            $rinso,
            $rinso->uoms->firstWhere('uom_name', 'PCS'),
            10,
            2000,
        ));

        $this->actingAs($this->kasir())
            ->post('/kasir/nota', [
                'payment_type' => 'QRIS',
                'lines' => [$this->baris('RNS-045', 'PCS', 3)],
            ])
            ->assertRedirect();

        $this->assertSame(7.0, app(StockLedger::class)->onHand($pusat->branch_id, $rinso->product_id));
    }

    public function test_petugas_gudang_tidak_boleh_menerbitkan_nota(): void
    {
        $this->actingAs(User::where('username', 'gudang')->firstOrFail())
            ->post('/kasir/nota', [
                'payment_type' => 'CASH',
                'lines' => [$this->baris('JSA-ANTAR', 'KALI', 1)],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_keranjang_kosong_ditolak(): void
    {
        $this->actingAs($this->kasir())
            ->post('/kasir/nota', ['payment_type' => 'CASH', 'lines' => []])
            ->assertSessionHasErrors('lines');
    }

    public function test_stok_kurang_menghasilkan_pesan_bukan_nota(): void
    {
        $this->actingAs($this->kasir())
            ->post('/kasir/nota', [
                'payment_type' => 'CASH',
                'lines' => [$this->baris('RNS-045', 'PCS', 1)],
            ])
            ->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('sales', 0);
    }
}

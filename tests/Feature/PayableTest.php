<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Payable;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Utang usaha (Addendum 58.3) — daftar saja untuk saat ini, lahir otomatis
 * dari faktur pemasok yang diposting. Pembayarannya menyusul di gelombang
 * berikutnya, jadi yang dijaga di sini baru soal isi dan hak akses layarnya.
 */
class PayableTest extends TestCase
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

    private function pemasok(string $name): Supplier
    {
        return Supplier::where('supplier_name', $name)->firstOrFail();
    }

    /** Membuat faktur nyata lewat HTTP supaya payable-nya lahir dari alur asli. */
    private function fakturkan(Supplier $supplier, string $sku, float $qty, float $unitCost, array $extra = []): Payable
    {
        $product = $this->produk($sku);

        $this->actingAs($this->manajer())->post('/penerimaan', [
            'supplier_id' => $supplier->supplier_id,
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->product_id,
                'uom_id' => $product->uoms->first()->uom_id,
                'qty' => $qty,
            ]],
        ]);
        $receipt = GoodsReceipt::orderByDesc('goods_receipt_id')->firstOrFail();

        $this->actingAs($this->manajer())->post('/pembelian', array_merge([
            'supplier_id' => $supplier->supplier_id,
            'transaction_date' => now()->toDateString(),
            'receipt_ids' => [$receipt->goods_receipt_id],
            'lines' => [[
                'receipt_item_id' => $receipt->items->firstOrFail()->receipt_item_id,
                'qty' => $qty,
                'unit_cost' => $unitCost,
            ]],
        ], $extra));

        return Payable::orderByDesc('payable_id')->firstOrFail();
    }

    public function test_faktur_diposting_membuat_utang_terbuka(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $this->fakturkan($unilever, 'RNS-045', 10, 2000, ['due_date' => now()->addDays(14)->toDateString()]);

        $response = $this->actingAs($this->manajer())->get('/utang');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $baris = $props['payables']['data'][0];

        $this->assertSame('OPEN', $baris['status']);
        $this->assertSame(20000.0, $baris['amount']);
        $this->assertSame(0.0, $baris['paidAmount']);
        $this->assertSame(20000.0, $baris['outstanding']);
        $this->assertFalse($baris['overdue']);
    }

    public function test_hanya_manajer_ke_atas_boleh_melihat_utang(): void
    {
        foreach (['kasir', 'gudang'] as $username) {
            $this->actingAs(User::where('username', $username)->firstOrFail())
                ->get('/utang')
                ->assertForbidden();
        }
    }

    public function test_saringan_default_menyembunyikan_yang_dibatalkan(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $indofood = $this->pemasok('PT Indofood Sukses Makmur');

        $this->fakturkan($unilever, 'RNS-045', 10, 2000);
        $dibatalkan = $this->fakturkan($indofood, 'AQU-600', 5, 3000);

        $this->actingAs($this->manajer())->post("/pembelian/{$dibatalkan->purchase_id}/batal");

        $response = $this->actingAs($this->manajer())->get('/utang');
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['payables']['data']);
        $this->assertSame('OPEN', $props['payables']['data'][0]['status']);
    }

    public function test_saringan_semua_menampilkan_yang_dibatalkan_juga(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $dibatalkan = $this->fakturkan($unilever, 'RNS-045', 10, 2000);
        $this->actingAs($this->manajer())->post("/pembelian/{$dibatalkan->purchase_id}/batal");

        $response = $this->actingAs($this->manajer())->get('/utang?status=ALL');
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['payables']['data']);
        $this->assertSame('VOID', $props['payables']['data'][0]['status']);
    }

    public function test_saringan_dibatalkan_hanya_menampilkan_status_void(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $this->fakturkan($unilever, 'RNS-045', 10, 2000);
        $dibatalkan = $this->fakturkan($unilever, 'AQU-600', 5, 3000);
        $this->actingAs($this->manajer())->post("/pembelian/{$dibatalkan->purchase_id}/batal");

        $response = $this->actingAs($this->manajer())->get('/utang?status=VOID');
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['payables']['data']);
        $this->assertSame('VOID', $props['payables']['data'][0]['status']);
    }

    public function test_ringkasan_menghitung_total_terutang_dan_jatuh_tempo(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $indofood = $this->pemasok('PT Indofood Sukses Makmur');

        // Diposting wajar dulu (validasi menolak jatuh tempo di masa lalu saat
        // dibuat), lalu didorong ke masa lalu langsung lewat model — jatuh
        // tempo dua hari lalu, sudah terlambat.
        $telat = $this->fakturkan($unilever, 'RNS-045', 10, 2000);
        $telat->update(['due_date' => now()->subDays(2)->toDateString()]);

        // Jatuh tempo dua minggu lagi — belum terlambat.
        $this->fakturkan($indofood, 'AQU-600', 5, 3000, [
            'due_date' => now()->addDays(14)->toDateString(),
        ]);

        $response = $this->actingAs($this->manajer())->get('/utang');
        $summary = $response->viewData('page')['props']['summary'];

        $this->assertSame(2, $summary['count']);
        $this->assertSame(35000.0, $summary['outstanding']); // 20.000 + 15.000
        $this->assertSame(20000.0, $summary['overdue']);

        $baris = collect($response->viewData('page')['props']['payables']['data']);
        $this->assertTrue($baris->firstWhere('supplier', 'PT Unilever Indonesia')['overdue']);
        $this->assertFalse($baris->firstWhere('supplier', 'PT Indofood Sukses Makmur')['overdue']);
    }

    public function test_utang_hanya_menampilkan_milik_cabang_aktif(): void
    {
        $unilever = $this->pemasok('PT Unilever Indonesia');
        $this->fakturkan($unilever, 'RNS-045', 10, 2000);

        $timur = Branch::where('branch_code', 'BR02')->first();
        $this->assertNotNull($timur, 'Fixture BR02 diharapkan ada dari OwnerSeeder.');

        $this->actingAs($this->manajer())->post('/lokasi-aktif', ['branch_id' => $timur->branch_id])->assertRedirect();

        $response = $this->actingAs($this->manajer())->get('/utang');
        $this->assertCount(0, $response->viewData('page')['props']['payables']['data']);
    }
}

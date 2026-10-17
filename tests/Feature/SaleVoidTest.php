<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\ExpenseCategory;
use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockLot;
use App\Models\StockLotConsumption;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SalePoster;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pembatalan nota penjualan (Addendum 41).
 *
 * Void bukan hapus: notanya tetap ada, movement aslinya tetap tercatat, dan
 * nomornya tidak pernah dipakai ulang. Yang ditambahkan adalah pembalikannya.
 */
class SaleVoidTest extends TestCase
{
    use RefreshDatabase;

    private Branch $pusat;

    private FiscalPeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);
        $this->pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
        $this->periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();
    }

    private function manajer(): User
    {
        return User::where('username', 'manajer')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function isiStok(string $sku, string $uomName, float $qty, float $cost, ?string $tanggal = null): void
    {
        $product = $this->produk($sku);

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->periode,
            $this->pusat->branch_id,
            $tanggal ?? now()->toDateString(),
            $product,
            $product->uoms->firstWhere('uom_name', $uomName),
            $qty,
            $cost,
        ));
    }

    private function jual(string $sku, string $uomName, float $qty, string $metode = 'CASH'): Sale
    {
        $product = $this->produk($sku);

        return app(SalePoster::class)->post(
            User::where('username', 'kasir')->firstOrFail(),
            $this->pusat,
            [
                'payment_type' => $metode,
                'lines' => [[
                    'product_id' => $product->product_id,
                    'uom_id' => $product->uoms->firstWhere('uom_name', $uomName)->uom_id,
                    'qty' => $qty,
                ]],
            ],
        );
    }

    private function stok(string $sku): float
    {
        return app(StockLedger::class)->onHand($this->pusat->branch_id, $this->produk($sku)->product_id);
    }

    // ------------------------------------------------------------ pembalikan

    public function test_void_mengembalikan_stok_dan_menyisakan_jejak_lengkap(): void
    {
        $this->isiStok('RNS-045', 'PCS', 20, 2000);
        $sale = $this->jual('RNS-045', 'PCS', 5);

        $this->assertSame(15.0, $this->stok('RNS-045'));

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Pelanggan membatalkan sebelum barang dibawa.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(20.0, $this->stok('RNS-045'));

        $sale->refresh();
        $this->assertSame(Sale::STATUS_VOID, $sale->status);
        $this->assertNotNull($sale->voided_at);
        $this->assertSame($this->manajer()->user_id, $sale->voided_by);

        // Movement asli TIDAK dihapus; pembalikannya ditambahkan.
        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $sale->sale_id,
            'movement_type' => 'SALES',
            'qty_base' => -5,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $sale->sale_id,
            'movement_type' => 'VOID_REVERSAL',
            'qty_base' => 5,
        ]);
    }

    public function test_void_mengembalikan_qty_ke_lot_asal(): void
    {
        Owner::firstOrFail()->update(['costing_method' => 'FIFO']);

        $this->isiStok('RNS-045', 'PCS', 5, 2000, $this->periode->start_date->toDateString());
        $this->isiStok('RNS-045', 'PCS', 5, 2500);

        // Menghabiskan lot pertama dan menggigit lot kedua.
        $sale = $this->jual('RNS-045', 'PCS', 7);

        $lots = StockLot::orderBy('lot_id')->get();
        $this->assertSame(StockLot::STATUS_EXHAUSTED, $lots[0]->status);

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Salah input satuan oleh kasir.'])
            ->assertRedirect();

        // Qty kembali ke lot asalnya, bukan menjadi lot baru hari ini yang akan
        // mengantre paling belakang pada FIFO.
        $lots = StockLot::orderBy('lot_id')->get();
        $this->assertSame(5.0, (float) $lots[0]->qty_remaining);
        $this->assertSame(StockLot::STATUS_ACTIVE, $lots[0]->status);
        $this->assertSame(5.0, (float) $lots[1]->qty_remaining);
        $this->assertCount(2, StockLot::all());

        // Jejak konsumsinya ditandai sudah dibalik.
        $this->assertSame(0, StockLotConsumption::whereNull('reversed_by_movement_id')->count());
    }

    public function test_void_membatalkan_pembayarannya(): void
    {
        $sale = $this->jual('JSA-ANTAR', 'KALI', 1);

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Layanan tidak jadi dilaksanakan.'])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', ['status' => 'VOID']);
        $this->assertDatabaseMissing('payments', ['status' => 'POSTED']);
    }

    // ------------------------------------------------------------ pembukuan

    public function test_nota_void_tidak_ikut_ringkasan_tetapi_tetap_terdaftar(): void
    {
        $sale = $this->jual('JSA-ANTAR', 'KALI', 1);
        $this->jual('JSA-ANTAR', 'KALI', 1);

        $this->actingAs($this->manajer())->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Dobel input.']);

        $response = $this->actingAs($this->manajer())->get('/penjualan');
        $props = $response->viewData('page')['props'];

        $this->assertSame(1, $props['summary']['count']);
        $this->assertSame(15000.0, $props['summary']['net']);
        // Notanya sendiri tetap muncul di daftar — void bukan hapus.
        $this->assertCount(2, $props['sales']['data']);
    }

    public function test_nomor_nota_tidak_dipakai_ulang_setelah_void(): void
    {
        $sale = $this->jual('JSA-ANTAR', 'KALI', 1);

        $this->actingAs($this->manajer())->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Salah pelanggan.']);

        $berikutnya = $this->jual('JSA-ANTAR', 'KALI', 1);

        $this->assertSame("INV/BR01/{$this->periode->period_code}/000001", $sale->sale_number);
        $this->assertSame("INV/BR01/{$this->periode->period_code}/000002", $berikutnya->sale_number);
    }

    // ------------------------------------------------------------- penolakan

    public function test_kasir_tidak_boleh_membatalkan_nota(): void
    {
        $sale = $this->jual('JSA-ANTAR', 'KALI', 1);

        $this->actingAs(User::where('username', 'kasir')->firstOrFail())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Ingin membatalkan sendiri.'])
            ->assertForbidden();

        $this->assertSame(Sale::STATUS_POSTED, $sale->fresh()->status);
    }

    public function test_alasan_pembatalan_wajib_diisi(): void
    {
        $sale = $this->jual('JSA-ANTAR', 'KALI', 1);

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => ''])
            ->assertSessionHasErrors('void_reason');

        $this->assertSame(Sale::STATUS_POSTED, $sale->fresh()->status);
    }

    public function test_nota_yang_sudah_dibatalkan_tidak_dapat_dibatalkan_lagi(): void
    {
        $this->isiStok('RNS-045', 'PCS', 10, 2000);
        $sale = $this->jual('RNS-045', 'PCS', 4);

        $this->actingAs($this->manajer())->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Pembatalan pertama.']);

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Pembatalan kedua.'])
            ->assertSessionHasErrors('sale');

        // Inilah yang sebenarnya dijaga: stok tidak boleh naik dua kali hanya
        // karena tombol batal ditekan dua kali.
        $this->assertSame(10.0, $this->stok('RNS-045'));
        $this->assertSame(1, StockMovement::where('movement_type', 'VOID_REVERSAL')->count());
        $this->assertSame('Pembatalan pertama.', $sale->fresh()->void_reason);
    }

    public function test_periode_tertutup_menolak_pembatalan(): void
    {
        $sale = $this->jual('JSA-ANTAR', 'KALI', 1);
        $this->periode->update(['status' => 'CLOSED']);

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Ingin membatalkan setelah tutup buku.'])
            ->assertSessionHasErrors('sale');

        $this->assertSame(Sale::STATUS_POSTED, $sale->fresh()->status);
    }

    // ------------------------------------------------------- buku kas (41.3, 41.4)

    public function test_void_tunai_menulis_refund_negatif_di_buku_kas(): void
    {
        $sale = $this->jual('JSA-ANTAR', 'KALI', 1); // Rp15.000 tunai

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Pelanggan batal.'])
            ->assertRedirect();

        $this->assertDatabaseHas('cash_transactions', [
            'txn_type' => 'REFUND',
            'amount' => -15000,
            'reference_type' => 'SALES',
            'reference_id' => $sale->sale_id,
        ]);

        // Saldo kas bersih dari nota ini kembali nol: +15.000 (CASH_SALE) - 15.000 (REFUND).
        $totalKas = (float) CashTransaction::where('reference_id', $sale->sale_id)->sum('amount');
        $this->assertSame(0.0, $totalKas);
    }

    public function test_void_non_tunai_tidak_menulis_refund(): void
    {
        $sale = $this->jual('JSA-ANTAR', 'KALI', 1, 'QRIS');

        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Pelanggan batal.'])
            ->assertRedirect();

        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_void_ditolak_bila_saldo_kas_tidak_cukup(): void
    {
        $sale = $this->jual('JSA-ANTAR', 'KALI', 1); // Rp15.000 tunai masuk

        // Sebagian besar kas sudah keluar lagi untuk biaya sebelum sempat divoid.
        $kategori = ExpenseCategory::where('owner_id', Owner::firstOrFail()->owner_id)->firstOrFail();
        $this->actingAs($this->manajer())->post('/biaya', [
            'expense_category_id' => $kategori->expense_category_id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Kas ditarik untuk keperluan lain.',
            'amount' => 14000,
            'payment_type' => 'CASH',
        ])->assertRedirect();

        // Sisa kas cuma Rp1.000, padahal refund butuh Rp15.000 — harus ditolak.
        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Pelanggan batal setelah kas menipis.'])
            ->assertSessionHasErrors('sale');

        $this->assertSame(Sale::STATUS_POSTED, $sale->fresh()->status);
        $this->assertDatabaseMissing('cash_transactions', ['txn_type' => 'REFUND']);
    }

    public function test_uang_laci_yang_digulirkan_antar_shift_tidak_dihitung_dua_kali(): void
    {
        $kasir = User::where('username', 'kasir')->firstOrFail();

        // Shift pertama membawa Rp100.000 ke laci, ditutup utuh, lalu shift kedua
        // menggulirkan uang yang SAMA. Ada dua baris OPENING_CASH, tapi uangnya satu.
        $this->actingAs($kasir)->post('/kasir/shift/buka', ['opening_cash' => 100000]);
        $pertama = CashierShift::firstOrFail();
        $this->actingAs($kasir)->post("/kasir/shift/{$pertama->shift_id}/tutup", ['actual_cash' => 100000]);
        $this->actingAs($kasir)->post('/kasir/shift/buka', [])->assertRedirect();

        $sale = $this->jual('JSA-ANTAR', 'KALI', 1); // +Rp15.000

        $kategori = ExpenseCategory::where('owner_id', Owner::firstOrFail()->owner_id)->firstOrFail();
        $this->actingAs($this->manajer())->post('/biaya', [
            'expense_category_id' => $kategori->expense_category_id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Setor ke bank.',
            'amount' => 101000,
            'payment_type' => 'CASH',
        ])->assertRedirect();

        // Kas sebenarnya 100.000 + 15.000 - 101.000 = 14.000, kurang dari refund
        // 15.000. Rumus lama menjumlah kedua OPENING_CASH (114.000) dan meloloskannya.
        $this->actingAs($this->manajer())
            ->post("/penjualan/{$sale->sale_id}/batal", ['void_reason' => 'Pelanggan batal.'])
            ->assertSessionHasErrors('sale');

        $this->assertSame(Sale::STATUS_POSTED, $sale->fresh()->status);
    }
}

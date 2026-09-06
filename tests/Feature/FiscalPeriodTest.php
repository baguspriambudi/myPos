<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClosingAttemptLog;
use App\Models\FiscalClosing;
use App\Models\FiscalClosingItem;
use App\Models\FiscalPeriod;
use App\Models\FiscalPeriodLog;
use App\Models\FiscalPeriodReopenRequest;
use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SalePoster;
use App\Services\StockLedger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Periode pembukuan: membuat, opening awal, menutup, dan membuka ulang
 * (PRD 20-25, Addendum 40, 43-45, 51.3, 56.2, 56.4, 56.10, 56.11).
 *
 * Yang dijaga bukan sekadar "statusnya berubah", melainkan bahwa snapshot yang
 * terbentuk benar-benar menjelaskan periodenya — persediaan awal + mutasi =
 * persediaan akhir — dan bahwa periode tidak pernah bisa tertinggal terkunci.
 */
class FiscalPeriodTest extends TestCase
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
        $this->pusat = Branch::where('branch_code', 'BR01')->firstOrFail();
        $this->periode = FiscalPeriod::where('branch_id', $this->pusat->branch_id)->firstOrFail();
    }

    // ------------------------------------------------------------- perkakas

    private function pemilik(): User
    {
        return User::where('username', 'bagus')->firstOrFail();
    }

    private function superAdmin(): User
    {
        return User::where('username', 'admin')->firstOrFail();
    }

    private function kasir(): User
    {
        return User::where('username', 'kasir')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function satuan(Product $product, string $nama): ProductUom
    {
        return $product->uoms->firstWhere('uom_name', $nama);
    }

    private function isiStok(string $sku, string $uomName, float $qty, float $costBase): void
    {
        $product = $this->produk($sku);

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->periode,
            $this->pusat->branch_id,
            now()->toDateString(),
            $product,
            $this->satuan($product, $uomName),
            $qty,
            $costBase,
        ));
    }

    /** @param  array<int, array{sku: string, uom: string, qty: float}>  $baris */
    private function jual(array $baris): Sale
    {
        $lines = [];

        foreach ($baris as $b) {
            $product = $this->produk($b['sku']);
            $lines[] = [
                'product_id' => $product->product_id,
                'uom_id' => $this->satuan($product, $b['uom'])->uom_id,
                'qty' => $b['qty'],
            ];
        }

        return app(SalePoster::class)->post($this->kasir(), $this->pusat, ['payment_type' => 'CASH', 'lines' => $lines]);
    }

    /**
     * Formulir periode baru hanya berisi lokasi dan rentang tanggal — kode dan
     * nama dibuat sistem.
     *
     * @return array<string, mixed>
     */
    private function isianPeriode(array $extra = []): array
    {
        $mulai = $this->bulanDepan();

        return array_merge([
            'branch_id' => $this->pusat->branch_id,
            'start_date' => $mulai->toDateString(),
            'end_date' => $mulai->copy()->endOfMonth()->toDateString(),
        ], $extra);
    }

    private function bulanDepan(): Carbon
    {
        return $this->periode->end_date->copy()->addDay()->startOfMonth();
    }

    private function kodeBulanDepan(): string
    {
        return 'FP-'.$this->bulanDepan()->format('Y-m');
    }

    /** Nama bulan Indonesia, dihitung terpisah dari kode agar tesnya berarti. */
    private function namaBulanDepan(): string
    {
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return $bulan[(int) $this->bulanDepan()->month].' '.$this->bulanDepan()->year;
    }

    // ------------------------------------------------------- membuat periode

    public function test_periode_baru_tersimpan_sebagai_draf(): void
    {
        $this->actingAs($this->pemilik())
            ->post('/periode', $this->isianPeriode())
            ->assertRedirect()
            ->assertSessionHas('success');

        $baru = FiscalPeriod::where('period_code', $this->kodeBulanDepan())->firstOrFail();

        $this->assertSame(FiscalPeriod::STATUS_DRAFT, $baru->status);
        // Nama disusun sistem dalam bahasa Indonesia dari bulan tanggal mulai.
        $this->assertSame($this->namaBulanDepan(), $baru->period_name);
        // Metode costing dibekukan dari setting owner saat periode dibuat.
        $this->assertSame($this->owner->costing_method, $baru->costing_method);
        $this->assertDatabaseHas('fiscal_period_logs', [
            'fiscal_period_id' => $baru->fiscal_period_id,
            'action' => FiscalPeriodLog::ACTION_CREATE,
        ]);
    }

    public function test_periode_yang_bertindih_ditolak(): void
    {
        $sebelum = FiscalPeriod::count();

        $this->actingAs($this->pemilik())
            ->post('/periode', $this->isianPeriode([
                'start_date' => $this->periode->start_date->toDateString(),
                'end_date' => $this->periode->end_date->toDateString(),
            ]))
            ->assertSessionHasErrors('start_date');

        $this->assertSame($sebelum, FiscalPeriod::count());
    }

    /**
     * Kode dibuat sistem, jadi dua periode yang mulai pada bulan sama tidak
     * boleh saling menggagalkan — cukup diberi akhiran urut.
     */
    public function test_kode_periode_diberi_akhiran_bila_bulannya_sudah_terpakai(): void
    {
        $mulai = $this->bulanDepan();

        $this->actingAs($this->pemilik())
            ->post('/periode', $this->isianPeriode([
                'start_date' => $mulai->toDateString(),
                'end_date' => $mulai->copy()->addDays(9)->toDateString(),
            ]))
            ->assertSessionHas('success');

        $this->actingAs($this->pemilik())
            ->post('/periode', $this->isianPeriode([
                'start_date' => $mulai->copy()->addDays(10)->toDateString(),
                'end_date' => $mulai->copy()->endOfMonth()->toDateString(),
            ]))
            ->assertSessionHas('success');

        $kode = $this->kodeBulanDepan();
        $this->assertDatabaseHas('fiscal_periods', ['period_code' => $kode]);
        $this->assertDatabaseHas('fiscal_periods', ['period_code' => $kode.'-2']);
    }

    /**
     * Saran tanggal menyambung periode terakhir, dan mempertahankan
     * kebiasaannya: yang berakhir tanggal 5 terus disarankan tanggal 5.
     */
    public function test_saran_tanggal_mengikuti_kebiasaan_periode_sebelumnya(): void
    {
        $props = $this->actingAs($this->pemilik())->get('/periode')->viewData('page')['props'];
        $saran = $props['periodDefaults'][(string) $this->pusat->branch_id];

        // Periode seeder berakhir di ujung bulan, jadi sarannya pun ujung bulan.
        $this->assertSame($this->periode->end_date->copy()->addDay()->toDateString(), $saran['startDate']);
        $this->assertSame($this->bulanDepan()->copy()->endOfMonth()->toDateString(), $saran['endDate']);

        // Ubah kebiasaannya: periode terakhir kini berakhir tanggal 5.
        $akhirGanjil = $this->bulanDepan()->copy()->day(5);
        FiscalPeriod::create([
            'owner_id' => $this->owner->owner_id,
            'branch_id' => $this->pusat->branch_id,
            'period_code' => 'FP-GANJIL',
            'period_name' => 'Ganjil',
            'start_date' => $this->periode->end_date->copy()->addDay()->toDateString(),
            'end_date' => $akhirGanjil->toDateString(),
            'status' => FiscalPeriod::STATUS_CLOSED,
            'costing_method' => $this->owner->costing_method,
            'currency_code' => $this->owner->default_currency,
        ]);

        $props = $this->actingAs($this->pemilik())->get('/periode')->viewData('page')['props'];
        $saran = $props['periodDefaults'][(string) $this->pusat->branch_id];

        $this->assertSame($akhirGanjil->copy()->addDay()->toDateString(), $saran['startDate']);
        $this->assertSame($akhirGanjil->copy()->addMonthNoOverflow()->toDateString(), $saran['endDate']);
    }

    public function test_layar_periode_menampilkan_periode_berjalan_dan_pemeriksaannya(): void
    {
        $this->isiStok('RNS-045', 'PCS', 10, 2000);

        $response = $this->actingAs($this->pemilik())->get('/periode');
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertSame($this->periode->period_code, $props['active']['code']);
        $this->assertSame(FiscalPeriod::STATUS_OPEN, $props['active']['status']);
        // Tujuh pemeriksaan pra-tutup, semuanya beres pada data sebersih ini.
        $this->assertCount(7, $props['active']['checks']);
        $this->assertSame(
            [],
            array_values(array_filter($props['active']['checks'], fn ($c) => $c['status'] !== 'ok')),
        );
    }

    public function test_kasir_tidak_boleh_mengelola_periode(): void
    {
        $this->actingAs($this->kasir())->get('/periode')->assertForbidden();
        $this->actingAs($this->kasir())->post('/periode', $this->isianPeriode())->assertForbidden();
        $this->actingAs($this->kasir())->post("/periode/{$this->periode->fiscal_period_id}/tutup")->assertForbidden();
    }

    // -------------------------------------------------------- opening awal

    /** Periode tingkat usaha (branch_id NULL) belum punya pendahulu, jadi ia yang pertama. */
    private function periodeDraf(): FiscalPeriod
    {
        $this->actingAs($this->pemilik())->post('/periode', $this->isianPeriode(['branch_id' => null]));

        return FiscalPeriod::where('period_code', $this->kodeBulanDepan())->whereNull('branch_id')->firstOrFail();
    }

    public function test_opening_membuat_satu_movement_per_produk(): void
    {
        $draf = $this->periodeDraf();
        $rinso = $this->produk('RNS-045');
        $lain = Product::where('owner_id', $this->owner->owner_id)
            ->where('product_id', '!=', $rinso->product_id)
            ->active()
            ->stocked()
            ->whereHas('uoms', fn ($q) => $q->where('uom_type', ProductUom::TYPE_BASE)->where('active', true))
            ->with(['uoms' => fn ($q) => $q->where('uom_type', ProductUom::TYPE_BASE)->where('active', true)])
            ->firstOrFail();

        $this->actingAs($this->pemilik())
            ->post("/periode/{$draf->fiscal_period_id}/opening", [
                'lines' => [
                    ['product_id' => $rinso->product_id, 'uom_id' => $this->satuan($rinso, 'PCS')->uom_id, 'qty' => 24, 'unit_cost' => 2000],
                    ['product_id' => $lain->product_id, 'uom_id' => $lain->uoms->first()->uom_id, 'qty' => 5, 'unit_cost' => 10000],
                ],
                'opening_cash' => 1000000,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $movements = StockMovement::where('fiscal_period_id', $draf->fiscal_period_id)
            ->where('movement_type', StockMovement::TYPE_OPENING)
            ->get();

        // Dua produk = dua movement terpisah, bukan satu movement gabungan
        // (Addendum 40.2 & 56.4).
        $this->assertCount(2, $movements);
        $this->assertSame(2, $movements->pluck('product_id')->unique()->count());

        $this->assertDatabaseHas('opening_balances', [
            'fiscal_period_id' => $draf->fiscal_period_id,
            'balance_type' => 'CASH',
            'amount' => 1000000,
        ]);
        $this->assertDatabaseHas('opening_balances', [
            'fiscal_period_id' => $draf->fiscal_period_id,
            'balance_type' => 'INVENTORY',
            'amount' => 98000,
        ]);
    }

    public function test_opening_menolak_produk_kembar_dalam_satu_pengisian(): void
    {
        $draf = $this->periodeDraf();
        $rinso = $this->produk('RNS-045');
        $pcs = $this->satuan($rinso, 'PCS')->uom_id;

        $this->actingAs($this->pemilik())
            ->post("/periode/{$draf->fiscal_period_id}/opening", [
                'lines' => [
                    ['product_id' => $rinso->product_id, 'uom_id' => $pcs, 'qty' => 10, 'unit_cost' => 2000],
                    ['product_id' => $rinso->product_id, 'uom_id' => $pcs, 'qty' => 5, 'unit_cost' => 2000],
                ],
            ])
            ->assertSessionHasErrors('lines.1.product_id');

        $this->assertSame(0, StockMovement::where('fiscal_period_id', $draf->fiscal_period_id)->count());
    }

    public function test_opening_ditolak_setelah_periode_dibuka(): void
    {
        $draf = $this->periodeDraf();
        $rinso = $this->produk('RNS-045');

        $this->actingAs($this->pemilik())->post("/periode/{$draf->fiscal_period_id}/buka")->assertSessionHas('success');
        $this->assertSame(FiscalPeriod::STATUS_OPEN, $draf->fresh()->status);

        $this->actingAs($this->pemilik())
            ->post("/periode/{$draf->fiscal_period_id}/opening", [
                'lines' => [['product_id' => $rinso->product_id, 'uom_id' => $this->satuan($rinso, 'PCS')->uom_id, 'qty' => 1, 'unit_cost' => 2000]],
            ])
            ->assertSessionHasErrors('period');
    }

    // ------------------------------------------------------------- penutupan

    public function test_penutupan_membentuk_snapshot_yang_menjelaskan_periodenya(): void
    {
        $this->isiStok('RNS-045', 'PCS', 24, 2000);
        $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 2]]);

        $this->actingAs($this->pemilik())
            ->post("/periode/{$this->periode->fiscal_period_id}/tutup")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(FiscalPeriod::STATUS_CLOSED, $this->periode->fresh()->status);

        $snapshot = FiscalClosing::where('fiscal_period_id', $this->periode->fiscal_period_id)->firstOrFail();

        $this->assertSame(FiscalClosing::STATUS_VALID, $snapshot->status);
        $this->assertSame(1, $snapshot->version);

        // Persediaan: 24 pcs @2000 masuk, 2 pcs keluar dengan HPP 4.000.
        $this->assertSame(48000.0, (float) $snapshot->opening_inventory_value);
        $this->assertSame(4000.0, (float) $snapshot->sales_cost_value);
        $this->assertSame(44000.0, (float) $snapshot->ending_inventory_value);

        // Laba: penjualan 5.000 dikurangi HPP 4.000, tanpa biaya operasional.
        $this->assertSame(5000.0, (float) $snapshot->net_sales);
        $this->assertSame(4000.0, (float) $snapshot->cogs_value);
        $this->assertSame(1000.0, (float) $snapshot->gross_profit);
        $this->assertSame(1000.0, (float) $snapshot->net_profit);

        // Kas: nota tunai 5.000 masuk buku kas.
        $this->assertSame(5000.0, (float) $snapshot->cash_sales);
        $this->assertSame(5000.0, (float) $snapshot->ending_cash);
        $this->assertSame(5000.0, (float) $snapshot->payment_cash);

        $item = FiscalClosingItem::where('fiscal_closing_id', $snapshot->fiscal_closing_id)->firstOrFail();
        $this->assertSame(22.0, (float) $item->ending_qty);
        $this->assertSame(44000.0, (float) $item->ending_value);
        $this->assertSame(2000.0, (float) $item->average_cost);
    }

    public function test_periode_tertutup_menolak_transaksi_baru(): void
    {
        $this->isiStok('RNS-045', 'PCS', 24, 2000);

        $this->actingAs($this->pemilik())->post("/periode/{$this->periode->fiscal_period_id}/tutup")->assertSessionHas('success');

        $this->expectException(ValidationException::class);
        $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 1]]);
    }

    public function test_shift_kasir_yang_masih_terbuka_memblokir_penutupan(): void
    {
        $this->actingAs($this->kasir())->post('/kasir/shift/buka', ['opening_cash' => 100000])->assertSessionHas('success');

        $this->actingAs($this->pemilik())
            ->post("/periode/{$this->periode->fiscal_period_id}/tutup")
            ->assertSessionHasErrors('period');

        $this->assertSame(FiscalPeriod::STATUS_OPEN, $this->periode->fresh()->status);
        $this->assertSame(0, FiscalClosing::count());
    }

    public function test_selisih_pembulatan_hpp_diakui_saat_penutupan(): void
    {
        // Rata-rata Rp3.333,3333 per pcs: HPP tiap nota dibulatkan, sedangkan
        // nilai persediaan yang keluar tidak (Addendum 51.3).
        $this->isiStok('RNS-045', 'PCS', 3, 3333.3333);

        foreach (range(1, 3) as $ignored) {
            $this->jual([['sku' => 'RNS-045', 'uom' => 'PCS', 'qty' => 1]]);
        }

        $this->actingAs($this->pemilik())->post("/periode/{$this->periode->fiscal_period_id}/tutup")->assertSessionHas('success');

        $snapshot = FiscalClosing::firstOrFail();
        $item = FiscalClosingItem::where('fiscal_closing_id', $snapshot->fiscal_closing_id)->firstOrFail();

        $this->assertSame(0.01, (float) $item->rounding_adjustment);

        // Movement ROUNDING berkebalikan tanda: nilai persediaan yang tercatat
        // kelebihan sebanyak itu dan harus dikeluarkan.
        $this->assertDatabaseHas('stock_movements', [
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'movement_type' => 'ROUNDING',
            'reference_type' => StockMovement::REF_CLOSING,
            'total_cost' => -0.01,
            'qty_base' => 0,
        ]);

        // Stok habis dan nilainya nol — persis guna koreksi itu.
        $this->assertSame(0.0, (float) $item->ending_qty);
        $this->assertSame(0.0, (float) $item->ending_value);
    }

    public function test_penutupan_yang_gagal_di_tengah_mengembalikan_status(): void
    {
        $this->isiStok('RNS-045', 'PCS', 10, 2000);

        FiscalClosingItem::creating(function () {
            throw new RuntimeException('gagal di tengah jalan');
        });

        try {
            $this->actingAs($this->pemilik())->post("/periode/{$this->periode->fiscal_period_id}/tutup");
        } catch (RuntimeException) {
            // Kegagalannya memang yang sedang diuji.
        }

        $this->assertSame(FiscalPeriod::STATUS_OPEN, $this->periode->fresh()->status);
        $this->assertSame(0, FiscalClosing::count());

        $this->assertDatabaseHas('closing_attempt_logs', [
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'status' => ClosingAttemptLog::STATUS_FAILED,
        ]);
        $this->assertDatabaseHas('fiscal_period_logs', [
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'action' => FiscalPeriodLog::ACTION_FAILED,
            'new_status' => FiscalPeriod::STATUS_OPEN,
        ]);
    }

    public function test_periode_yang_tertinggal_di_closing_dipulihkan_perintah_penjadwal(): void
    {
        FiscalPeriodLog::create([
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'action' => FiscalPeriodLog::ACTION_CLOSING,
            'old_status' => FiscalPeriod::STATUS_OPEN,
            'new_status' => FiscalPeriod::STATUS_CLOSING,
            'action_at' => now()->subHour(),
        ]);
        ClosingAttemptLog::create([
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'step' => ClosingAttemptLog::STEP_VALIDATE,
            'status' => ClosingAttemptLog::STATUS_STARTED,
            'started_at' => now()->subHour(),
        ]);
        $this->periode->update(['status' => FiscalPeriod::STATUS_CLOSING]);

        $this->artisan('periode:pulihkan-closing')->assertSuccessful();

        $this->assertSame(FiscalPeriod::STATUS_OPEN, $this->periode->fresh()->status);
        $this->assertDatabaseHas('fiscal_period_logs', [
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'action' => FiscalPeriodLog::ACTION_RECOVERED,
        ]);
    }

    public function test_closing_yang_masih_berjalan_tidak_dipulihkan(): void
    {
        ClosingAttemptLog::create([
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'step' => ClosingAttemptLog::STEP_INVENTORY,
            'status' => ClosingAttemptLog::STATUS_STARTED,
            'started_at' => now(),
        ]);
        $this->periode->update(['status' => FiscalPeriod::STATUS_CLOSING]);

        $this->artisan('periode:pulihkan-closing')->assertSuccessful();

        $this->assertSame(FiscalPeriod::STATUS_CLOSING, $this->periode->fresh()->status);
    }

    // ------------------------------------------------------------ buka ulang

    private function tutupPeriode(): void
    {
        $this->actingAs($this->pemilik())
            ->post("/periode/{$this->periode->fiscal_period_id}/tutup")
            ->assertSessionHas('success');
    }

    public function test_kasir_tidak_boleh_mengajukan_buka_ulang(): void
    {
        $this->tutupPeriode();

        $this->actingAs($this->kasir())
            ->post("/periode/{$this->periode->fiscal_period_id}/buka-ulang", ['reason' => 'iseng'])
            ->assertForbidden();

        $this->assertSame(0, FiscalPeriodReopenRequest::count());
    }

    public function test_pemilik_tunggal_boleh_menyetujui_permintaannya_sendiri(): void
    {
        $this->isiStok('RNS-045', 'PCS', 5, 2000);
        $this->tutupPeriode();

        $snapshotLama = FiscalClosing::firstOrFail();

        $this->actingAs($this->pemilik())
            ->post("/periode/{$this->periode->fiscal_period_id}/buka-ulang", ['reason' => 'Ada nota yang belum sempat divoid'])
            ->assertSessionHas('success');

        $permintaan = FiscalPeriodReopenRequest::firstOrFail();
        $this->assertSame(FiscalPeriodReopenRequest::STATUS_PENDING, $permintaan->status);

        $this->actingAs($this->pemilik())
            ->post("/periode/buka-ulang/{$permintaan->reopen_request_id}/setujui")
            ->assertSessionHas('success');

        $permintaan->refresh();
        $this->assertSame(FiscalPeriodReopenRequest::STATUS_APPROVED, $permintaan->status);
        // Ditandai berbeda supaya jelas terlihat saat diaudit (Addendum 45.2).
        $this->assertSame(FiscalPeriodReopenRequest::APPROVAL_SELF, $permintaan->approval_type);

        $this->assertSame(FiscalPeriod::STATUS_REOPENED, $this->periode->fresh()->status);
        $this->assertSame(FiscalClosing::STATUS_REPLACED, $snapshotLama->fresh()->status);
    }

    public function test_super_admin_tidak_boleh_menyetujui_buka_ulang(): void
    {
        $this->tutupPeriode();

        $this->actingAs($this->superAdmin())
            ->post("/periode/{$this->periode->fiscal_period_id}/buka-ulang", ['reason' => 'Koreksi stok'])
            ->assertSessionHas('success');

        $permintaan = FiscalPeriodReopenRequest::firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post("/periode/buka-ulang/{$permintaan->reopen_request_id}/setujui")
            ->assertSessionHasErrors('request');

        $this->assertSame(FiscalPeriodReopenRequest::STATUS_PENDING, $permintaan->fresh()->status);
        $this->assertSame(FiscalPeriod::STATUS_CLOSED, $this->periode->fresh()->status);
    }

    public function test_penutupan_ulang_membuat_snapshot_versi_baru(): void
    {
        $this->isiStok('RNS-045', 'PCS', 5, 2000);
        $this->tutupPeriode();

        $this->actingAs($this->pemilik())->post("/periode/{$this->periode->fiscal_period_id}/buka-ulang", ['reason' => 'Koreksi']);
        $permintaan = FiscalPeriodReopenRequest::firstOrFail();
        $this->actingAs($this->pemilik())->post("/periode/buka-ulang/{$permintaan->reopen_request_id}/setujui");

        $this->tutupPeriode();

        $this->assertSame(2, FiscalClosing::count());
        $this->assertSame(1, FiscalClosing::where('status', FiscalClosing::STATUS_VALID)->count());
        $this->assertSame(2, (int) FiscalClosing::where('status', FiscalClosing::STATUS_VALID)->value('version'));
    }

    // ------------------------------------------------- warisan antar-periode

    public function test_periode_berikutnya_mewarisi_saldo_akhir_periode_sebelumnya(): void
    {
        $this->isiStok('RNS-045', 'PCS', 10, 2000);
        $this->tutupPeriode();

        $berikut = FiscalPeriod::create([
            'owner_id' => $this->owner->owner_id,
            'branch_id' => $this->pusat->branch_id,
            'period_code' => 'FP-BERIKUT',
            'period_name' => 'Periode Berikutnya',
            'start_date' => $this->periode->end_date->copy()->addDay()->toDateString(),
            'end_date' => $this->periode->end_date->copy()->addMonthNoOverflow()->toDateString(),
            'status' => FiscalPeriod::STATUS_OPEN,
            'costing_method' => $this->owner->costing_method,
            'currency_code' => $this->owner->default_currency,
            'opened_at' => now(),
        ]);

        $this->actingAs($this->pemilik())
            ->post("/periode/{$berikut->fiscal_period_id}/tutup")
            ->assertSessionHas('success');

        $snapshot = FiscalClosing::where('fiscal_period_id', $berikut->fiscal_period_id)->firstOrFail();

        // Tanpa transaksi apa pun, saldo akhir periode lalu menjadi saldo awal
        // periode ini dan bertahan sampai akhir (Addendum 40.4).
        $this->assertSame(20000.0, (float) $snapshot->opening_inventory_value);
        $this->assertSame(20000.0, (float) $snapshot->ending_inventory_value);

        $item = FiscalClosingItem::where('fiscal_closing_id', $snapshot->fiscal_closing_id)->firstOrFail();
        $this->assertSame(10.0, (float) $item->opening_qty);
        $this->assertSame(10.0, (float) $item->ending_qty);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\FiscalClosing;
use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Tax;
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
 * Jurnal dasar saat penutupan periode (Addendum 53).
 */
class JournalTest extends TestCase
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
        DB::table('discounts')->update(['active' => false]);
    }

    private function pemilik(): User
    {
        return User::where('username', 'bagus')->firstOrFail();
    }

    private function produk(string $sku): Product
    {
        return Product::where('sku', $sku)->with('uoms')->firstOrFail();
    }

    private function isiStok(float $qty, float $cost): void
    {
        $p = $this->produk('RNS-045');

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->periode, $this->pusat->branch_id, now()->toDateString(), $p, $p->uoms->firstWhere('uom_name', 'PCS'), $qty, $cost,
        ));
    }

    private function jual(string $sku, string $uom, float $qty, string $metode, ?int $customerId = null): void
    {
        $p = $this->produk($sku);

        app(SalePoster::class)->post(User::where('username', 'kasir')->firstOrFail(), $this->pusat, [
            'payment_type' => $metode,
            'customer_id' => $customerId,
            'lines' => [['product_id' => $p->product_id, 'uom_id' => $p->uoms->firstWhere('uom_name', $uom)->uom_id, 'qty' => $qty]],
        ]);
    }

    private function tutup(): FiscalClosing
    {
        $this->actingAs($this->pemilik())
            ->post("/periode/{$this->periode->fiscal_period_id}/tutup")
            ->assertSessionHas('success');

        return FiscalClosing::where('fiscal_period_id', $this->periode->fiscal_period_id)->where('status', 'VALID')->firstOrFail();
    }

    /** @return array<string, float> kode akun => debit - kredit */
    private function saldoAkun(string $entryType = 'CLOSING'): array
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.journal_entry_id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.account_id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entries.fiscal_period_id', $this->periode->fiscal_period_id)
            ->where('journal_entries.entry_type', $entryType)
            ->groupBy('chart_of_accounts.account_code')
            ->selectRaw('chart_of_accounts.account_code, SUM(debit - credit) AS saldo')
            ->pluck('saldo', 'account_code')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    public function test_penutupan_membentuk_jurnal_yang_seimbang_dan_menjelaskan_periodenya(): void
    {
        $this->isiStok(24, 2000);                       // persediaan awal 48.000 (movement OPENING)
        $this->jual('RNS-045', 'PCS', 2, 'CASH');       // tunai 5.000, HPP 4.000
        $this->jual('JSA-ANTAR', 'KALI', 1, 'QRIS');    // non-tunai 15.000

        $kategori = ExpenseCategory::where('owner_id', $this->owner->owner_id)->whereNotNull('account_id')->firstOrFail();
        $this->actingAs($this->pemilik())->post('/biaya', [
            'expense_category_id' => $kategori->expense_category_id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Listrik.',
            'amount' => 3000,
            'payment_type' => 'CASH',
        ])->assertSessionHasNoErrors();

        $closing = $this->tutup();

        $entry = DB::table('journal_entries')->where('fiscal_period_id', $this->periode->fiscal_period_id)->where('entry_type', 'CLOSING')->first();
        $this->assertNotNull($entry);
        $this->assertSame('FISCAL_CLOSING', $entry->reference_type);
        $this->assertSame($closing->fiscal_closing_id, (int) $entry->reference_id);

        $baris = DB::table('journal_entry_lines')->where('journal_entry_id', $entry->journal_entry_id);
        $this->assertEqualsWithDelta((float) (clone $baris)->sum('debit'), (float) (clone $baris)->sum('credit'), 0.001);

        $saldo = $this->saldoAkun();
        $this->assertSame(2000.0, $saldo['1-1100']);    // kas: +5.000 jual -3.000 biaya
        $this->assertSame(15000.0, $saldo['1-1200']);   // bank: QRIS
        $this->assertSame(-20000.0, $saldo['4-1100']);  // penjualan (kredit)
        $this->assertSame(4000.0, $saldo['5-1100']);    // HPP
        $this->assertSame(-4000.0, $saldo['1-1400']);   // keluar sebesar HPP

        // Persediaan awal masuk jurnal OPENING, berpasangan dengan modal.
        $this->assertSame(48000.0, $this->saldoAkun('OPENING')['1-1400']);
        $this->assertSame(-48000.0, $this->saldoAkun('OPENING')['3-1100']);
        $this->assertSame(3000.0, $saldo[DB::table('chart_of_accounts')->where('account_id', $kategori->account_id)->value('account_code')]);
    }

    public function test_penjualan_kredit_dan_pelunasannya_lewat_piutang(): void
    {
        $pelanggan = Customer::where('owner_id', $this->owner->owner_id)->where('is_default', false)->firstOrFail();
        $pelanggan->update(['credit_limit' => 1000000]);
        $this->jual('JSA-ANTAR', 'KALI', 2, 'CREDIT', $pelanggan->customer_id); // piutang 30.000

        $piutang = Receivable::firstOrFail();
        $this->actingAs($this->pemilik())->post("/piutang/{$piutang->receivable_id}/terima", [
            'amount' => 10000,
            'payment_type' => 'CASH',
            'payment_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->tutup();
        $saldo = $this->saldoAkun();

        $this->assertSame(20000.0, $saldo['1-1300']);   // 30.000 - 10.000
        $this->assertSame(10000.0, $saldo['1-1100']);
        $this->assertSame(-30000.0, $saldo['4-1100']);
    }

    public function test_pajak_keluaran_dipisah_dari_penjualan(): void
    {
        $this->produk('JSA-ANTAR')->update(['tax_id' => Tax::where('tax_name', 'PPN 11%')->value('tax_id')]);
        $this->jual('JSA-ANTAR', 'KALI', 1, 'CASH');

        $this->tutup();
        $saldo = $this->saldoAkun();

        $this->assertSame(15000.0, $saldo['1-1100']);
        $this->assertSame(-13513.51, $saldo['4-1100']);
        $this->assertSame(-1486.49, $saldo['2-1300']);
    }

    public function test_usaha_tanpa_bagan_akun_tetap_dapat_menutup_periode(): void
    {
        DB::table('expense_categories')->update(['account_id' => null]);
        DB::table('chart_of_accounts')->delete();
        $this->jual('JSA-ANTAR', 'KALI', 1, 'CASH');

        $this->tutup();

        // Akun standar dibuat sendiri saat jurnal pertama dibentuk.
        $this->assertDatabaseHas('chart_of_accounts', ['owner_id' => $this->owner->owner_id, 'account_code' => '1-1100']);
        $this->assertSame(15000.0, $this->saldoAkun()['1-1100']);
    }

    public function test_saldo_awal_dibukukan_sebagai_jurnal_opening(): void
    {
        DB::table('opening_balances')->insert([
            'fiscal_period_id' => $this->periode->fiscal_period_id,
            'branch_id' => $this->pusat->branch_id,
            'balance_type' => 'CASH',
            'amount' => 500000,
        ]);

        $this->tutup();
        $saldo = $this->saldoAkun('OPENING');

        $this->assertSame(500000.0, $saldo['1-1100']);
        $this->assertSame(-500000.0, $saldo['3-1100']);
    }

    public function test_jurnal_dapat_diunduh_sebagai_csv(): void
    {
        $this->jual('JSA-ANTAR', 'KALI', 1, 'CASH');
        $this->tutup();

        $res = $this->actingAs($this->pemilik())->get("/periode/{$this->periode->fiscal_period_id}/jurnal");

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $isi = $res->streamedContent();
        $this->assertStringContainsString('1-1100', $isi);
        $this->assertStringContainsString('15000', $isi);
    }
}

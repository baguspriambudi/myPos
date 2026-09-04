<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Biaya operasional (Addendum 60).
 *
 * Yang dijaga: biaya tunai menulis cash_transactions CASH_OUT, metode lain
 * tidak menyentuh buku kas sama sekali, void tunai membalik kas pada tanggal
 * void, dan hanya MANAGER ke atas yang boleh mencatat maupun membatalkan.
 */
class ExpenseTest extends TestCase
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

    private function kategori(string $name): ExpenseCategory
    {
        return ExpenseCategory::where('category_name', $name)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function isian(array $extra = []): array
    {
        return array_merge([
            'expense_category_id' => $this->kategori('Listrik & Air')->expense_category_id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Token listrik toko bulan ini',
            'amount' => 250000,
            'payment_type' => 'CASH',
        ], $extra);
    }

    // -------------------------------------------------------------- posting

    public function test_biaya_tunai_membuat_cash_transaction_keluar(): void
    {
        $this->actingAs($this->manajer())
            ->post('/biaya', $this->isian())
            ->assertRedirect()
            ->assertSessionHas('success');

        $expense = Expense::firstOrFail();
        $this->assertSame('POSTED', $expense->status);

        $this->assertDatabaseHas('cash_transactions', [
            'txn_type' => 'CASH_OUT',
            'amount' => -250000,
            'reference_type' => 'EXPENSE',
            'reference_id' => $expense->expense_id,
        ]);
    }

    public function test_biaya_non_tunai_tidak_menyentuh_buku_kas(): void
    {
        $this->actingAs($this->manajer())
            ->post('/biaya', $this->isian(['payment_type' => 'TRANSFER']))
            ->assertRedirect();

        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_kategori_tidak_aktif_ditolak(): void
    {
        $kategori = $this->kategori('Sewa Tempat');
        $kategori->update(['active' => false]);

        $this->actingAs($this->manajer())
            ->post('/biaya', $this->isian(['expense_category_id' => $kategori->expense_category_id]))
            ->assertSessionHasErrors('expense_category_id');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_hanya_manajer_ke_atas_boleh_mencatat_biaya(): void
    {
        foreach (['kasir', 'gudang'] as $username) {
            $this->actingAs(User::where('username', $username)->firstOrFail())
                ->post('/biaya', $this->isian())
                ->assertForbidden();

            $this->actingAs(User::where('username', $username)->firstOrFail())
                ->get('/biaya')
                ->assertForbidden();
        }

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_ringkasan_menghitung_total_dan_total_tunai_bulan_ini(): void
    {
        $this->actingAs($this->manajer())->post('/biaya', $this->isian(['amount' => 250000, 'payment_type' => 'CASH']));
        $this->actingAs($this->manajer())->post('/biaya', $this->isian(['amount' => 100000, 'payment_type' => 'TRANSFER']));

        $response = $this->actingAs($this->manajer())->get('/biaya');
        $summary = $response->viewData('page')['props']['summary'];

        $this->assertSame(2, $summary['countBulanIni']);
        $this->assertSame(350000.0, $summary['totalBulanIni']);
        $this->assertSame(250000.0, $summary['totalTunaiBulanIni']);
    }

    // -------------------------------------------------------------------- void

    public function test_void_biaya_tunai_membalik_kas_pada_tanggal_void(): void
    {
        $this->actingAs($this->manajer())->post('/biaya', $this->isian());
        $expense = Expense::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/biaya/{$expense->expense_id}/batal", ['void_reason' => 'Salah kategori, dicatat ulang.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $expense->refresh();
        $this->assertSame('VOID', $expense->status);
        $this->assertSame('Salah kategori, dicatat ulang.', $expense->void_reason);

        $this->assertDatabaseHas('cash_transactions', [
            'txn_type' => 'CASH_IN',
            'amount' => 250000,
            'reference_type' => 'EXPENSE',
            'reference_id' => $expense->expense_id,
            'transaction_date' => now()->toDateString(),
        ]);

        // Saldo kas bersih dari biaya ini kembali nol.
        $totalKas = (float) CashTransaction::where('reference_id', $expense->expense_id)->sum('amount');
        $this->assertSame(0.0, $totalKas);
    }

    public function test_void_biaya_non_tunai_tidak_membuat_pembalikan_kas(): void
    {
        $this->actingAs($this->manajer())->post('/biaya', $this->isian(['payment_type' => 'QRIS']));
        $expense = Expense::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/biaya/{$expense->expense_id}/batal", ['void_reason' => 'Dibatalkan pelanggan.'])
            ->assertRedirect();

        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_alasan_pembatalan_wajib_diisi(): void
    {
        $this->actingAs($this->manajer())->post('/biaya', $this->isian());
        $expense = Expense::firstOrFail();

        $this->actingAs($this->manajer())
            ->post("/biaya/{$expense->expense_id}/batal", ['void_reason' => 'x'])
            ->assertSessionHasErrors('void_reason');

        $expense->refresh();
        $this->assertSame('POSTED', $expense->status);
    }

    public function test_kasir_tidak_boleh_membatalkan_biaya(): void
    {
        $this->actingAs($this->manajer())->post('/biaya', $this->isian());
        $expense = Expense::firstOrFail();

        $this->actingAs(User::where('username', 'kasir')->firstOrFail())
            ->post("/biaya/{$expense->expense_id}/batal", ['void_reason' => 'Mencoba membatalkan.'])
            ->assertForbidden();
    }

    public function test_biaya_yang_sudah_dibatalkan_tidak_dapat_dibatalkan_lagi(): void
    {
        $this->actingAs($this->manajer())->post('/biaya', $this->isian());
        $expense = Expense::firstOrFail();

        $this->actingAs($this->manajer())->post("/biaya/{$expense->expense_id}/batal", ['void_reason' => 'Alasan pertama.']);

        $this->actingAs($this->manajer())
            ->post("/biaya/{$expense->expense_id}/batal", ['void_reason' => 'Alasan kedua.'])
            ->assertSessionHasErrors('expense');
    }
}

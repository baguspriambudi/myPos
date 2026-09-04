<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\ExpenseCategory;
use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tax;
use Illuminate\Database\Seeder;

/**
 * Pelanggan, pemasok, pajak, diskon, kategori biaya, dan periode fiskal.
 */
class PartnerSeeder extends Seeder
{
    public function run(): void
    {
        $owner = Owner::firstOrFail();
        $ownerId = $owner->owner_id;

        $pusat = Branch::where('owner_id', $ownerId)->where('branch_code', 'BR01')->firstOrFail();

        // "Umum / Tunai" adalah pelanggan tunai bawaan: seluruh penjualan eceran
        // yang tidak menyebut pelanggan tercatat ke sana, jadi kasir tidak perlu
        // memilih apa pun. Sisanya member, dan member otomatis memakai daftar
        // harga bertanda member — tanpa penugasan satu per satu (Addendum 39.13).
        Customer::insert([
            ['owner_id' => $ownerId, 'branch_id' => null, 'customer_name' => 'Umum / Tunai', 'phone' => null, 'is_member' => false, 'is_default' => true, 'credit_limit' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['owner_id' => $ownerId, 'branch_id' => null, 'customer_name' => 'Toko Berkah Jaya', 'phone' => '081234567890', 'is_member' => true, 'is_default' => false, 'credit_limit' => 5000000, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['owner_id' => $ownerId, 'branch_id' => null, 'customer_name' => 'Warung Bu Aminah', 'phone' => '082345678901', 'is_member' => true, 'is_default' => false, 'credit_limit' => 1500000, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['owner_id' => $ownerId, 'branch_id' => $pusat->branch_id, 'customer_name' => 'Kantin SD Melati', 'phone' => '083456789012', 'is_member' => true, 'is_default' => false, 'credit_limit' => 500000, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Supplier::insert([
            ['owner_id' => $ownerId, 'branch_id' => null, 'supplier_name' => 'PT Unilever Indonesia', 'phone' => '02150000001', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['owner_id' => $ownerId, 'branch_id' => null, 'supplier_name' => 'PT Indofood Sukses Makmur', 'phone' => '02150000002', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['owner_id' => $ownerId, 'branch_id' => null, 'supplier_name' => 'CV Sumber Rejeki Sembako', 'phone' => '0318000123', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['owner_id' => $ownerId, 'branch_id' => null, 'supplier_name' => 'UD Tani Makmur', 'phone' => '0318000456', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Tax::create(['owner_id' => $ownerId, 'tax_name' => 'PPN 11%', 'tax_rate' => 11.00]);
        Tax::create(['owner_id' => $ownerId, 'tax_name' => 'Non PPN', 'tax_rate' => 0.00]);

        $accounts = ChartOfAccount::where('owner_id', $ownerId)->pluck('account_id', 'account_code');

        foreach ([
            ['Gaji Karyawan', '6-1100'],
            ['Listrik & Air', '6-1200'],
            ['Sewa Tempat', '6-1300'],
            ['Transport & Bensin', '6-1400'],
            ['Perlengkapan Toko', '6-1500'],
        ] as [$name, $code]) {
            ExpenseCategory::create([
                'owner_id' => $ownerId,
                'category_name' => $name,
                'account_id' => $accounts[$code] ?? null,
            ]);
        }

        $indomie = Product::where('owner_id', $ownerId)->where('sku', 'IDM-GRG')->first();

        Discount::create([
            'owner_id' => $ownerId,
            'discount_name' => 'Promo Indomie Beli 10',
            'discount_scope' => 'ITEM',
            'discount_type' => 'AMOUNT',
            'discount_value' => 200,
            'product_id' => $indomie?->product_id,
            'min_qty' => 10,
            'min_amount' => 0,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);

        Discount::create([
            'owner_id' => $ownerId,
            'discount_name' => 'Diskon Belanja di atas 500rb',
            'discount_scope' => 'TRANSACTION',
            'discount_type' => 'PERCENT',
            'discount_value' => 2,
            'max_discount_amount' => 25000,
            'min_qty' => 0,
            'min_amount' => 500000,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);

        $this->seedPeriods($owner);
    }

    /**
     * Periode fiskal dibuat untuk SETIAP lokasi, gudang termasuk.
     *
     * Gudang tanpa periode terbuka akan menolak seluruh transfer masuk maupun
     * keluar (Addendum 49.1) — jebakan yang paling mudah terlewat saat setup.
     */
    private function seedPeriods(Owner $owner): void
    {
        $start = now()->startOfMonth();

        foreach (Branch::where('owner_id', $owner->owner_id)->get() as $branch) {
            FiscalPeriod::create([
                'owner_id' => $owner->owner_id,
                'branch_id' => $branch->branch_id,
                'period_code' => 'FP-'.$start->format('Y-m'),
                'period_name' => $start->translatedFormat('F Y'),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->endOfMonth()->toDateString(),
                'status' => 'OPEN',
                'costing_method' => $owner->costing_method,
                'currency_code' => $owner->default_currency,
                'opened_at' => now(),
            ]);
        }
    }
}

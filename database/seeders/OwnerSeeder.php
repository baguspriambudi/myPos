<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Usaha, lokasi, akun, dan pengguna.
 *
 * Lokasi sengaja mencakup ketiga tipe supaya perbedaan gudang (tidak boleh
 * menjual) langsung terlihat begitu aplikasi dibuka.
 */
class OwnerSeeder extends Seeder
{
    public const PASSWORD = 'password123';

    public function run(): void
    {
        DB::table('currencies')->insertOrIgnore([
            ['currency_code' => 'IDR', 'currency_name' => 'Rupiah', 'is_base' => true],
            ['currency_code' => 'USD', 'currency_name' => 'Dolar Amerika', 'is_base' => false],
        ]);

        $owner = Owner::create([
            'owner_code' => 'SM',
            'owner_name' => 'Sumber Makmur',
            'default_currency' => 'IDR',
            'costing_method' => 'AVERAGE',
            'allow_negative_stock' => false,
            'payment_rounding_unit' => 100,
            'payment_rounding_mode' => 'DOWN',
        ]);

        $branches = collect([
            ['branch_code' => 'BR01', 'branch_name' => 'Sumber Makmur — Pusat', 'branch_type' => Branch::TYPE_STORE, 'address' => 'Jl. Raya Utama No. 12, Sidoarjo'],
            ['branch_code' => 'BR02', 'branch_name' => 'Sumber Makmur — Timur', 'branch_type' => Branch::TYPE_DEPO, 'address' => 'Jl. Melati No. 45, Sidoarjo'],
            ['branch_code' => 'WH01', 'branch_name' => 'Gudang Utama', 'branch_type' => Branch::TYPE_WAREHOUSE, 'address' => 'Kawasan Pergudangan Blok C-7, Sidoarjo'],
        ])->map(fn (array $row) => Branch::create([
            ...$row,
            'owner_id' => $owner->owner_id,
            // Gudang tidak melayani penjualan (Addendum 59.2).
            'can_sell' => Branch::defaultCanSell($row['branch_type']),
        ]));

        $this->seedAccounts($owner->owner_id);
        $this->seedUsers($owner, $branches);
    }

    private function seedAccounts(int $ownerId): void
    {
        $accounts = [
            ['1-1100', 'Kas', 'ASSET'],
            ['1-1200', 'Bank', 'ASSET'],
            ['1-1300', 'Piutang Usaha', 'ASSET'],
            ['1-1400', 'Persediaan Barang Dagang', 'ASSET'],
            ['1-1500', 'PPN Masukan', 'ASSET'],
            ['2-1100', 'Utang Usaha', 'LIABILITY'],
            // Penampung barang yang sudah diterima tetapi fakturnya belum masuk.
            // Wajib ada sebelum modul penerimaan barang dipakai (Addendum 58.5).
            [ChartOfAccount::CODE_GRNI, 'Utang Belum Ditagih (GRNI)', 'LIABILITY'],
            ['2-1300', 'PPN Keluaran', 'LIABILITY'],
            ['3-1100', 'Modal Pemilik', 'EQUITY'],
            ['3-2100', 'Laba Ditahan', 'EQUITY'],
            ['4-1100', 'Penjualan', 'REVENUE'],
            ['4-1200', 'Diskon Penjualan', 'REVENUE'],
            ['4-9100', 'Selisih Pembulatan', 'REVENUE'],
            ['5-1100', 'Harga Pokok Penjualan', 'EXPENSE'],
            ['6-1100', 'Beban Gaji', 'EXPENSE'],
            ['6-1200', 'Beban Listrik & Air', 'EXPENSE'],
            ['6-1300', 'Beban Sewa', 'EXPENSE'],
            ['6-1400', 'Beban Transport', 'EXPENSE'],
            ['6-1500', 'Beban Perlengkapan', 'EXPENSE'],
            ['6-9100', 'Beban Selisih Kas', 'EXPENSE'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            ChartOfAccount::create([
                'owner_id' => $ownerId,
                'account_code' => $code,
                'account_name' => $name,
                'account_type' => $type,
            ]);
        }
    }

    private function seedUsers(Owner $owner, $branches): void
    {
        $pusat = $branches->firstWhere('branch_code', 'BR01');
        $timur = $branches->firstWhere('branch_code', 'BR02');
        $gudang = $branches->firstWhere('branch_code', 'WH01');

        // OWNER dan SUPER ADMIN: branch_id NULL = seluruh lokasi.
        User::create([
            'owner_id' => $owner->owner_id,
            'branch_id' => null,
            'username' => 'bagus',
            'password_hash' => self::PASSWORD,
            'full_name' => 'Bagus Prasetyo',
            'role' => User::ROLE_OWNER,
        ]);

        User::create([
            'owner_id' => $owner->owner_id,
            'branch_id' => null,
            'username' => 'admin',
            'password_hash' => self::PASSWORD,
            'full_name' => 'Admin Sistem',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $manager = User::create([
            'owner_id' => $owner->owner_id,
            'branch_id' => $pusat->branch_id,
            'username' => 'manajer',
            'password_hash' => self::PASSWORD,
            'full_name' => 'Siti Rahmawati',
            'role' => User::ROLE_MANAGER,
        ]);
        $manager->branchAccess()->sync([$pusat->branch_id, $timur->branch_id]);

        $kasir = User::create([
            'owner_id' => $owner->owner_id,
            'branch_id' => $pusat->branch_id,
            'username' => 'kasir',
            'password_hash' => self::PASSWORD,
            'full_name' => 'Dwi Lestari',
            'role' => User::ROLE_KASIR,
        ]);
        $kasir->branchAccess()->sync([$pusat->branch_id]);

        // Petugas gudang: tidak melihat harga, tidak punya akses kasir.
        $petugasGudang = User::create([
            'owner_id' => $owner->owner_id,
            'branch_id' => $gudang->branch_id,
            'username' => 'gudang',
            'password_hash' => self::PASSWORD,
            'full_name' => 'Anwar Hidayat',
            'role' => User::ROLE_GUDANG,
        ]);
        $petugasGudang->branchAccess()->sync([$gudang->branch_id]);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Urutannya penting: pengguna butuh lokasi, daftar harga butuh produk,
     * dan pelanggan grosir butuh daftar harga.
     */
    public function run(): void
    {
        $this->call([
            OwnerSeeder::class,
            CatalogSeeder::class,
            PartnerSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->info('Akun contoh — kata sandi semuanya: '.OwnerSeeder::PASSWORD);
        $this->command->table(
            ['Username', 'Nama', 'Peran', 'Lokasi'],
            [
                ['bagus', 'Bagus Prasetyo', 'Pemilik', 'semua lokasi'],
                ['admin', 'Admin Sistem', 'Super Admin', 'semua lokasi'],
                ['manajer', 'Siti Rahmawati', 'Manajer', 'BR01, BR02'],
                ['kasir', 'Dwi Lestari', 'Kasir', 'BR01'],
                ['gudang', 'Anwar Hidayat', 'Gudang', 'WH01'],
            ]
        );
    }
}

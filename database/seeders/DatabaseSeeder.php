<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Isi awal database: data nyata Toko Pandu.
 *
 * Sejak 6 September 2026, `migrate:fresh --seed` mengisi Toko Pandu, bukan lagi
 * usaha contoh "Sumber Makmur". Seeder contoh itu TIDAK dihapus — seluruh
 * berkas pengujian memanggilnya langsung (OwnerSeeder, CatalogSeeder,
 * PartnerSeeder) karena tes membutuhkan katalog beragam: barang timbang, jasa,
 * aset, produk bersatuan bertingkat, dan daftar harga member. Data Toko Pandu
 * seluruhnya bersatuan tunggal, jadi tidak dapat menggantikan peran itu.
 *
 * Jangan menambahkan seeder contoh ke sini: apa pun yang dipanggil di kelas ini
 * akan ikut masuk ke database yang dipakai sungguhan.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TokoPanduSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->info('Toko Pandu siap. Kata sandi kedua akun: '.TokoPanduSeeder::PASSWORD);
        $this->command->table(
            ['Username', 'Nama', 'Peran'],
            [
                ['pandu', 'Pandu', 'Pemilik'],
                ['admin', 'Admin Sistem', 'Super Admin'],
            ]
        );
    }
}

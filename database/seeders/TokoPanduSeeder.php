<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUom;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data awal Toko Pandu, dipindahkan dari "toko pandu.xlsx".
 *
 * Sumber datanya `database/data/toko-pandu-produk.csv`, hasil ekstraksi berkas
 * Excel tersebut. Sengaja dibaca dari CSV, bukan dari .xlsx: isinya jadi bisa
 * dibaca dan ditelusuri lewat git, dan seeder tidak menggantungkan diri pada
 * pustaka pembaca Excel maupun pada berkas yang bisa saja berpindah.
 *
 * Yang DIISI: usaha, satu lokasi toko, dua pengguna, satu kategori, satuan
 * yang benar-benar dipakai, 137 produk, dan harga jualnya.
 *
 * Yang SENGAJA KOSONG: stok (belum ada), pemasok, pajak, diskon, kategori
 * biaya, dan daftar akun. Semuanya diisi sendiri lewat layar master saat
 * dibutuhkan — mengarang isinya di sini hanya akan jadi data yang harus
 * dihapus lagi.
 */
class TokoPanduSeeder extends Seeder
{
    public const PASSWORD = 'password123';

    private const BERKAS = 'database/data/toko-pandu-produk.csv';

    public function run(): void
    {
        DB::table('currencies')->insertOrIgnore([
            ['currency_code' => 'IDR', 'currency_name' => 'Rupiah', 'is_base' => true],
        ]);

        $owner = Owner::create([
            'owner_code' => 'PANDU',
            'owner_name' => 'Toko Pandu',
            'default_currency' => 'IDR',
            'costing_method' => 'AVERAGE',
            'allow_negative_stock' => false,
            // Pembulatan pembayaran dimatikan sampai pemiliknya menentukan
            // sendiri: memaksa pembulatan pada harga yang belum tentu bulat
            // hanya akan membuat kembalian tidak cocok sejak hari pertama.
            'payment_rounding_unit' => 0,
            'payment_rounding_mode' => 'NEAREST',
        ]);

        $branch = Branch::create([
            'owner_id' => $owner->owner_id,
            'branch_code' => 'TP01',
            'branch_name' => 'Toko Pandu',
            'branch_type' => Branch::TYPE_STORE,
            'can_sell' => true,
        ]);

        $this->seedUsers($owner, $branch);
        $this->seedPelangganTunai($owner);
        $this->seedPeriode($owner, $branch);
        $this->seedProduk($owner);
    }

    private function seedUsers(Owner $owner, Branch $branch): void
    {
        // branch_id NULL: keduanya berwenang atas seluruh lokasi, jadi tidak
        // diikat ke satu toko. ActiveBranch akan memilih lokasi pertama.
        foreach ([
            ['pandu', 'Pandu', User::ROLE_OWNER],
            ['admin', 'Admin Sistem', User::ROLE_SUPER_ADMIN],
        ] as [$username, $nama, $role]) {
            $user = User::create([
                'owner_id' => $owner->owner_id,
                'branch_id' => null,
                'username' => $username,
                'password_hash' => self::PASSWORD,
                'full_name' => $nama,
                'role' => $role,
            ]);

            $user->branchAccess()->sync([$branch->branch_id]);
        }
    }

    /**
     * Pelanggan tunai bawaan (Addendum 39.13).
     *
     * Tanpa baris ini kasir dipaksa memilih pelanggan untuk setiap penjualan
     * eceran. `credit_limit` 0 berarti tidak boleh berutang sama sekali.
     */
    private function seedPelangganTunai(Owner $owner): void
    {
        Customer::create([
            'owner_id' => $owner->owner_id,
            'branch_id' => null,
            'customer_name' => 'Umum / Tunai',
            'is_member' => false,
            'is_default' => true,
            'credit_limit' => 0,
            'active' => true,
        ]);
    }

    /**
     * Periode berjalan, langsung OPEN.
     *
     * Bukan "data contoh": tanpa periode, seluruh transaksi ditolak karena
     * tidak punya tempat bernaung, dan aplikasi tampak rusak sejak login
     * pertama. Kodenya mengikuti format yang sama dengan FiscalPeriodManager.
     */
    private function seedPeriode(Owner $owner, Branch $branch): void
    {
        $mulai = now()->startOfMonth();

        FiscalPeriod::create([
            'owner_id' => $owner->owner_id,
            'branch_id' => $branch->branch_id,
            'period_code' => 'FP-'.$mulai->format('Y-m'),
            'period_name' => $this->namaBulan((int) $mulai->month).' '.$mulai->year,
            'start_date' => $mulai->toDateString(),
            'end_date' => $mulai->copy()->endOfMonth()->toDateString(),
            'status' => FiscalPeriod::STATUS_OPEN,
            'costing_method' => $owner->costing_method,
            'currency_code' => $owner->default_currency,
            'opened_at' => now(),
        ]);
    }

    private function namaBulan(int $bulan): string
    {
        return [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ][$bulan];
    }

    private function seedProduk(Owner $owner): void
    {
        $baris = $this->bacaCsv();

        // Excel-nya tidak punya kolom kategori. Satu kategori penampung dibuat
        // lebih dulu supaya produknya tidak menggantung tanpa induk; pemiliknya
        // bisa memecahnya kapan saja lewat master Kategori Produk.
        $kategori = ProductCategory::create([
            'owner_id' => $owner->owner_id,
            'category_name' => 'Barang Dagang',
        ]);

        // Hanya satuan yang benar-benar dipakai yang dibuat — master satuan
        // yang penuh nama tak terpakai justru menyulitkan saat memilih.
        $satuan = [];

        foreach (array_unique(array_column($baris, 'satuan')) as $nama) {
            $satuan[$nama] = Unit::create([
                'owner_id' => $owner->owner_id,
                'unit_name' => $nama,
                'description' => 'Dipindahkan dari data Toko Pandu',
            ]);
        }

        // Harga jual TIDAK disimpan di produk: daftar harga adalah satu-satunya
        // sumber harga jual (Addendum 39.10), dan produk tanpa baris harga
        // tidak dapat dijual sama sekali.
        $daftarHarga = PriceList::create([
            'owner_id' => $owner->owner_id,
            'branch_id' => null,
            'price_list_code' => 'PL-UMUM',
            'price_list_name' => 'Harga Jual',
            'is_default' => true,
            'priority' => 0,
        ]);

        foreach ($baris as $row) {
            $product = Product::create([
                'owner_id' => $owner->owner_id,
                'category_id' => $kategori->category_id,
                'product_name' => $row['nama'],
                'sku' => $row['sku'],
                // Hanya kode berupa angka yang dianggap barcode; kode racikan
                // seperti "BC KUNING" tidak akan pernah terbaca pemindai.
                'barcode' => $row['barcode'] !== '' ? $row['barcode'] : null,
                'is_weighted' => false,
            ]);

            $uom = ProductUom::create([
                'product_id' => $product->product_id,
                'unit_id' => $satuan[$row['satuan']]->unit_id,
                'uom_name' => $row['satuan'],
                'uom_type' => ProductUom::TYPE_BASE,
                'conversion_factor' => 1,
                'is_default' => true,
            ]);

            $product->update(['base_uom_id' => $uom->uom_id]);

            PriceListItem::create([
                'price_list_id' => $daftarHarga->price_list_id,
                'product_id' => $product->product_id,
                'uom_id' => $uom->uom_id,
                'min_qty' => 1,
                'unit_price' => $row['harga_jual'],
            ]);
        }
    }

    /**
     * @return array<int, array{sku: string, barcode: string, nama: string, satuan: string, harga_jual: string}>
     */
    private function bacaCsv(): array
    {
        $path = base_path(self::BERKAS);

        if (! is_readable($path)) {
            throw new RuntimeException(self::BERKAS.' tidak ditemukan; seeder Toko Pandu membutuhkannya.');
        }

        $handle = fopen($path, 'r');
        $judul = fgetcsv($handle);
        $baris = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $baris[] = array_combine($judul, $row);
        }

        fclose($handle);

        return $baris;
    }
}

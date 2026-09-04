<?php

namespace Database\Seeders;

use App\Models\Owner;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUom;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Master satuan, kategori, produk beserta satuannya, dan daftar harga bertingkat.
 *
 * Baris Rinso sengaja dibuat persis seperti contoh yang diminta: satu dus
 * 20.000, satu pcs 2.500, dan tingkat menengah 2.300 untuk pembelian >= 6 pcs.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $owner = Owner::firstOrFail();
        $ownerId = $owner->owner_id;

        $categories = collect([
            'Sembako', 'Minuman', 'Makanan Ringan', 'Kebersihan', 'Perawatan Diri', 'Jasa', 'Inventaris',
        ])->mapWithKeys(fn (string $name) => [
            $name => ProductCategory::create(['owner_id' => $ownerId, 'category_name' => $name]),
        ]);

        // Daftar satuan awal. Nama satuan hanya boleh berasal dari sini.
        $units = collect([
            'PCS' => 'Satuan terkecil, dijual eceran',
            'DUS' => 'Kemasan kardus dari pemasok',
            'RENTENG' => 'Sachet berderet',
            'LUSIN' => '12 buah',
            'PAK' => 'Bungkus isi beberapa buah',
            'TRAY' => 'Rak telur',
            'SAK' => 'Karung',
            'KG' => 'Kilogram, untuk barang timbang',
            'GRAM' => 'Gram',
            'LITER' => 'Liter, untuk barang curah cair',
            'BOTOL' => 'Kemasan botol',
            'KALI' => 'Sekali layanan, untuk jasa',
            'UNIT' => 'Satu buah utuh, untuk aset',
        ])->map(fn (string $keterangan, string $nama) => Unit::create([
            'owner_id' => $ownerId,
            'unit_name' => $nama,
            'description' => $keterangan,
        ]));

        /**
         * Format: [nama, sku, barcode, kategori, ditimbang, [satuan...]]
         * Satuan: [nama, tipe, faktor konversi, harga eceran]
         *
         * Harga di sini tidak disimpan di produk — ia menjadi baris min_qty 1
         * pada daftar harga umum, karena daftar harga adalah satu-satunya sumber
         * harga jual (Addendum 39.10).
         */
        $catalog = [
            ['Rinso Cair Sachet 45g', 'RNS-045', '8999999521004', 'Kebersihan', false, [
                ['PCS', 'BASE', 1, 2500],
                ['DUS', 'PACK', 12, 20000],
            ]],
            ['Indomie Goreng 85g', 'IDM-GRG', '8998866200028', 'Makanan Ringan', false, [
                ['PCS', 'BASE', 1, 3500],
                ['DUS', 'PACK', 40, 128000],
            ]],
            ['Aqua Botol 600ml', 'AQU-600', '8886008101053', 'Minuman', false, [
                ['PCS', 'BASE', 1, 4000],
                ['DUS', 'PACK', 24, 88000],
            ]],
            ['Teh Pucuk Harum 350ml', 'TPH-350', '8998009010101', 'Minuman', false, [
                ['PCS', 'BASE', 1, 3500],
                ['DUS', 'PACK', 24, 78000],
            ]],
            ['Kopi Kapal Api Special 65g', 'KKA-065', '8991002100015', 'Minuman', false, [
                ['PCS', 'BASE', 1, 2000],
                ['RENTENG', 'PACK', 10, 18500],
                ['DUS', 'PACK', 120, 216000],
            ]],
            ['Minyak Goreng Bimoli 1L', 'BML-1L', '8992222100019', 'Sembako', false, [
                ['PCS', 'BASE', 1, 18000],
                ['DUS', 'PACK', 12, 205000],
            ]],
            ['Gula Pasir Curah', 'GLA-CRH', null, 'Sembako', true, [
                ['KG', 'BASE', 1, 15000],
                ['SAK', 'PACK', 25, 360000],
            ]],
            ['Beras Premium Pandan Wangi', 'BRS-PDW', null, 'Sembako', true, [
                ['KG', 'BASE', 1, 14500],
                ['SAK', 'PACK', 5, 70000],
            ]],
            ['Sabun Lifebuoy Batang 85g', 'LFB-085', '8999999040017', 'Perawatan Diri', false, [
                ['PCS', 'BASE', 1, 4500],
                ['LUSIN', 'PACK', 12, 50000],
            ]],
            ['Telur Ayam Negeri', 'TLR-AYM', null, 'Sembako', true, [
                ['KG', 'BASE', 1, 28000],
                ['TRAY', 'PACK', 1.7, 46000],
            ]],
        ];

        $products = [];

        foreach ($catalog as [$name, $sku, $barcode, $categoryName, $weighted, $uoms]) {
            $product = Product::create([
                'owner_id' => $ownerId,
                'category_id' => $categories[$categoryName]->category_id,
                'product_name' => $name,
                'sku' => $sku,
                'barcode' => $barcode,
                'is_weighted' => $weighted,
            ]);

            $baseUomId = null;

            foreach ($uoms as [$uomName, $uomType, $factor]) {
                $uom = ProductUom::create([
                    'product_id' => $product->product_id,
                    'unit_id' => $units[$uomName]->unit_id,
                    'uom_name' => $uomName,
                    'uom_type' => $uomType,
                    'conversion_factor' => $factor,
                    'is_default' => $uomType === 'BASE',
                ]);

                if ($uomType === 'BASE') {
                    $baseUomId = $uom->uom_id;
                }
            }

            $product->update(['base_uom_id' => $baseUomId]);
            $products[$sku] = $product->fresh('uoms');
        }

        // Contoh jenis produk selain barang dagang (Addendum 39.14). Keduanya
        // sengaja ada sejak seeder supaya bedanya kelihatan tanpa harus diisi
        // manual dulu: jasa boleh dijual tapi tanpa stok, aset punya stok tapi
        // tidak pernah muncul di daftar harga.
        $products['JSA-ANTAR'] = $this->seedSatuSatuan(
            $ownerId,
            $categories['Jasa']->category_id,
            Product::TYPE_SERVICE,
            'Antar Barang Dalam Kota',
            'JSA-ANTAR',
            $units['KALI']->unit_id,
            'KALI',
        );

        $products['AST-FRZ'] = $this->seedSatuSatuan(
            $ownerId,
            $categories['Inventaris']->category_id,
            Product::TYPE_ASSET,
            'Freezer Box 200L',
            'AST-FRZ',
            $units['UNIT']->unit_id,
            'UNIT',
        );

        $this->seedPriceLists($ownerId, $products, $catalog);
    }

    /** Produk bersatuan tunggal: jasa dan aset tidak punya kemasan. */
    private function seedSatuSatuan(
        int $ownerId,
        int $categoryId,
        string $type,
        string $name,
        string $sku,
        int $unitId,
        string $uomName,
    ): Product {
        $product = Product::create([
            'owner_id' => $ownerId,
            'category_id' => $categoryId,
            'product_type' => $type,
            'product_name' => $name,
            'sku' => $sku,
        ]);

        $uom = ProductUom::create([
            'product_id' => $product->product_id,
            'unit_id' => $unitId,
            'uom_name' => $uomName,
            'uom_type' => 'BASE',
            'conversion_factor' => 1,
            'is_default' => true,
        ]);

        $product->update(['base_uom_id' => $uom->uom_id]);

        return $product->fresh('uoms');
    }

    private function seedPriceLists(int $ownerId, array $products, array $catalog): void
    {
        // Daftar harga umum: dipakai bila tidak ada yang lebih spesifik.
        $umum = PriceList::create([
            'owner_id' => $ownerId,
            'branch_id' => null,
            'price_list_code' => 'PL-UMUM',
            'price_list_name' => 'Harga Normal (Eceran)',
            'is_default' => true,
            'priority' => 0,
        ]);

        // Daftar harga grosir: HANYA untuk pelanggan bertanda member. Tanda
        // member_only itulah yang menjaganya agar tidak ikut menjadi harga umum
        // walaupun prioritasnya lebih tinggi.
        $grosir = PriceList::create([
            'owner_id' => $ownerId,
            'branch_id' => null,
            'price_list_code' => 'PL-GROSIR',
            'price_list_name' => 'Harga Grosir Member',
            'is_default' => false,
            'member_only' => true,
            'priority' => 10,
        ]);

        $tier = function (PriceList $list, Product $product, string $uomName, float $minQty, float $price): void {
            $uom = $product->uoms->firstWhere('uom_name', $uomName);

            if ($uom === null) {
                return;
            }

            PriceListItem::create([
                'price_list_id' => $list->price_list_id,
                'product_id' => $product->product_id,
                'uom_id' => $uom->uom_id,
                'min_qty' => $minQty,
                'unit_price' => $price,
            ]);
        };

        // Harga eceran setiap satuan masuk daftar harga umum. Wajib lengkap:
        // produk yang tidak punya baris harga tidak dapat dijual sama sekali.
        foreach ($catalog as [$name, $sku, $barcode, $categoryName, $weighted, $uoms]) {
            foreach ($uoms as [$uomName, $uomType, $factor, $price]) {
                $tier($umum, $products[$sku], $uomName, 1, $price);
            }
        }

        // ------------------------------------------------------ contoh Rinso
        $rinso = $products['RNS-045'];
        $tier($umum, $rinso, 'PCS', 6, 2300);   // ecer banyak, di bawah satu dus
        $tier($grosir, $rinso, 'PCS', 1, 2200);
        $tier($grosir, $rinso, 'DUS', 1, 19000);
        $tier($grosir, $rinso, 'DUS', 5, 18500); // grosir, mulai 5 dus

        // ------------------------------------------------------------- jasa
        // Jasa tetap butuh baris harga seperti barang: daftar harga adalah
        // satu-satunya sumber harga jual, apa pun jenis produknya. Yang tidak
        // dimiliki jasa hanyalah stok.
        $tier($umum, $products['JSA-ANTAR'], 'KALI', 1, 15000);

        // Aset (AST-FRZ) sengaja TIDAK diberi harga — ia memang tidak dijual.

        // ---------------------------------------------------- produk lainnya
        $indomie = $products['IDM-GRG'];
        $tier($umum, $indomie, 'PCS', 10, 3300);
        $tier($grosir, $indomie, 'DUS', 1, 124000);

        $tier($grosir, $products['AQU-600'], 'DUS', 1, 84000);
        $tier($grosir, $products['KKA-065'], 'DUS', 1, 208000);
        $tier($grosir, $products['BML-1L'], 'DUS', 1, 198000);

        $gula = $products['GLA-CRH'];
        $tier($umum, $gula, 'KG', 10, 14500);
        $tier($grosir, $gula, 'SAK', 1, 348000);
    }
}

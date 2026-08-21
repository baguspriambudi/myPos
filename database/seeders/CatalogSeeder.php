<?php

namespace Database\Seeders;

use App\Models\Owner;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUom;
use Illuminate\Database\Seeder;

/**
 * Kategori, produk beserta satuannya, dan daftar harga bertingkat.
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
            'Sembako', 'Minuman', 'Makanan Ringan', 'Kebersihan', 'Perawatan Diri',
        ])->mapWithKeys(fn (string $name) => [
            $name => ProductCategory::create(['owner_id' => $ownerId, 'category_name' => $name]),
        ]);

        /**
         * Format: [nama, sku, barcode, kategori, ditimbang, [satuan...]]
         * Satuan: [nama, tipe, faktor konversi, harga standar]
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

            foreach ($uoms as [$uomName, $uomType, $factor, $price]) {
                $uom = ProductUom::create([
                    'product_id' => $product->product_id,
                    'uom_name' => $uomName,
                    'uom_type' => $uomType,
                    'conversion_factor' => $factor,
                    'is_default' => $uomType === 'BASE',
                    'selling_price' => $price,
                ]);

                if ($uomType === 'BASE') {
                    $baseUomId = $uom->uom_id;
                }
            }

            $product->update(['base_uom_id' => $baseUomId]);
            $products[$sku] = $product->fresh('uoms');
        }

        $this->seedPriceLists($ownerId, $products);
    }

    private function seedPriceLists(int $ownerId, array $products): void
    {
        // Daftar harga umum: dipakai bila tidak ada yang lebih spesifik.
        $umum = PriceList::create([
            'owner_id' => $ownerId,
            'branch_id' => null,
            'price_list_code' => 'PL-UMUM',
            'price_list_name' => 'Harga Umum (Eceran)',
            'is_default' => true,
            'priority' => 0,
        ]);

        // Daftar harga grosir: HANYA untuk pelanggan yang ditugaskan lewat
        // customers.price_list_id. customer_only menjaganya agar tidak ikut
        // menjadi harga umum walaupun prioritasnya lebih tinggi.
        $grosir = PriceList::create([
            'owner_id' => $ownerId,
            'branch_id' => null,
            'price_list_code' => 'PL-GROSIR',
            'price_list_name' => 'Harga Grosir',
            'is_default' => false,
            'customer_only' => true,
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

        // ------------------------------------------------------ contoh Rinso
        $rinso = $products['RNS-045'];
        $tier($umum, $rinso, 'PCS', 1, 2500);   // ecer
        $tier($umum, $rinso, 'PCS', 6, 2300);   // ecer banyak, di bawah satu dus
        $tier($umum, $rinso, 'DUS', 1, 20000);  // beli per dus
        $tier($grosir, $rinso, 'PCS', 1, 2200);
        $tier($grosir, $rinso, 'DUS', 1, 19000);
        $tier($grosir, $rinso, 'DUS', 5, 18500); // grosir, mulai 5 dus

        // ---------------------------------------------------- produk lainnya
        $indomie = $products['IDM-GRG'];
        $tier($umum, $indomie, 'PCS', 1, 3500);
        $tier($umum, $indomie, 'PCS', 10, 3300);
        $tier($umum, $indomie, 'DUS', 1, 128000);
        $tier($grosir, $indomie, 'DUS', 1, 124000);

        $aqua = $products['AQU-600'];
        $tier($umum, $aqua, 'PCS', 1, 4000);
        $tier($umum, $aqua, 'DUS', 1, 88000);
        $tier($grosir, $aqua, 'DUS', 1, 84000);

        $kopi = $products['KKA-065'];
        $tier($umum, $kopi, 'PCS', 1, 2000);
        $tier($umum, $kopi, 'RENTENG', 1, 18500);
        $tier($umum, $kopi, 'DUS', 1, 216000);
        $tier($grosir, $kopi, 'DUS', 1, 208000);

        $minyak = $products['BML-1L'];
        $tier($umum, $minyak, 'PCS', 1, 18000);
        $tier($umum, $minyak, 'DUS', 1, 205000);
        $tier($grosir, $minyak, 'DUS', 1, 198000);

        $gula = $products['GLA-CRH'];
        $tier($umum, $gula, 'KG', 1, 15000);
        $tier($umum, $gula, 'KG', 10, 14500);
        $tier($umum, $gula, 'SAK', 1, 360000);
        $tier($grosir, $gula, 'SAK', 1, 348000);
    }
}

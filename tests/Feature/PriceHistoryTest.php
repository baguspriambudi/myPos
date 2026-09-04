<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\User;
use App\Services\PriceResolver;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\PartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Riwayat harga berbasis tanggal (Addendum 39.15).
 *
 * Harga tidak pernah ditimpa. Menggantinya berarti menambah baris baru dengan
 * start_date lebih baru, dan yang paling baru mulai itulah yang menang pada
 * tanggal transaksi. Inilah yang menggantikan priority.
 */
class PriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private PriceResolver $resolver;

    private Product $rinso;

    private ProductUom $pcs;

    private PriceList $umum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OwnerSeeder::class, CatalogSeeder::class, PartnerSeeder::class]);

        $this->resolver = app(PriceResolver::class);
        $this->rinso = Product::where('sku', 'RNS-045')->with('uoms')->firstOrFail();
        $this->pcs = $this->rinso->uoms->firstWhere('uom_name', 'PCS');
        $this->umum = PriceList::where('price_list_code', 'PL-UMUM')->firstOrFail();
    }

    private function pemilik(): User
    {
        return User::where('username', 'bagus')->firstOrFail();
    }

    /** Harga 1 PCS Rinso pada tanggal tersebut. */
    private function hargaPada(string $tanggal, float $qty = 1): ?float
    {
        return $this->resolver->resolve(
            $this->rinso->owner_id,
            $this->rinso->product_id,
            $this->pcs->uom_id,
            $qty,
            null,
            null,
            $tanggal,
        )['unit_price'];
    }

    private function tambahHarga(float $harga, string $mulai, ?string $sampai = null, ?float $minQty = null): PriceListItem
    {
        return PriceListItem::create([
            'price_list_id' => $this->umum->price_list_id,
            'product_id' => $this->rinso->product_id,
            'uom_id' => $this->pcs->uom_id,
            'min_qty' => $minQty ?? 1,
            'unit_price' => $harga,
            'start_date' => $mulai,
            'end_date' => $sampai ?? PriceListItem::TANPA_AKHIR,
        ]);
    }

    // ------------------------------------------------------------- inti

    public function test_yang_paling_baru_mulai_yang_menang(): void
    {
        // Harga seeder mulai 1900-01-01 sebesar 2.500.
        $this->tambahHarga(2300, '2026-08-21');

        $this->assertSame(2500.0, $this->hargaPada('2026-01-05'), 'sebelum harga baru mulai');
        $this->assertSame(2300.0, $this->hargaPada('2026-08-21'), 'sejak harga baru mulai');
        $this->assertSame(2300.0, $this->hargaPada('2026-12-31'), 'seterusnya');
    }

    public function test_baris_lama_tidak_perlu_ditutup_untuk_diganti(): void
    {
        // Ini yang membuat aturan "tutup baris lama H-1" tidak diperlukan:
        // baris lama dibiarkan utuh dan tetap kalah sejak harga baru mulai.
        $this->tambahHarga(2300, '2026-08-21');

        $lama = PriceListItem::where('price_list_id', $this->umum->price_list_id)
            ->where('uom_id', $this->pcs->uom_id)
            ->where('min_qty', 1)
            ->orderBy('start_date')
            ->first();

        $this->assertSame(PriceListItem::TANPA_AKHIR, $lama->end_date->toDateString());
        $this->assertSame(2300.0, $this->hargaPada('2026-08-21'));
    }

    /**
     * Mengganti harga dua kali dalam sehari tidak menghasilkan dua baris, tetapi
     * juga tidak buntu.
     *
     * Aturan "tutup baris lama H-1" akan memberi baris pagi tadi end_date
     * KEMARIN padahal ia mulai hari ini — rentang yang tidak sah. Di sini baris
     * pagi tadi belum terkunci (start_date-nya belum lewat), jadi jalan
     * keluarnya sederhana: ubah baris itu, bukan menumpuk versi baru.
     */
    public function test_mengganti_harga_dua_kali_sehari_cukup_mengubah_baris_hari_ini(): void
    {
        $hariIni = now()->toDateString();
        $pagi = $this->tambahHarga(2300, $hariIni, null, 3);

        $this->assertFalse($pagi->terkunci(), 'baris hari ini belum jadi riwayat');
        $this->assertTrue($pagi->start_date <= $pagi->end_date, 'rentangnya tetap sah');

        $baris = [
            'product_id' => $this->rinso->product_id,
            'uom_id' => $this->pcs->uom_id,
            'min_qty' => 3,
            'unit_price' => 2400,
            'start_date' => $hariIni,
            'end_date' => PriceListItem::TANPA_AKHIR,
        ];

        // Versi kedua pada hari yang sama ditolak — tidak ada yang bisa
        // menjelaskan mana yang dimaksud.
        $this->actingAs($this->pemilik())
            ->post("/master/harga/{$this->umum->price_list_id}/tingkat", $baris)
            ->assertSessionHasErrors('min_qty');

        // Mengubah baris hari ini diterima.
        $this->actingAs($this->pemilik())
            ->put("/master/harga/{$this->umum->price_list_id}/tingkat/{$pagi->price_list_item_id}", $baris)
            ->assertSessionHasNoErrors();

        $this->assertSame('2400.00', $pagi->fresh()->unit_price);
        $this->assertSame(1, PriceListItem::where('min_qty', 3)->count(), 'tetap satu baris');
    }

    public function test_promo_berjangka_mengembalikan_harga_normal_sendiri(): void
    {
        // Justru inilah yang mustahil bila baris lama ditutup permanen.
        $this->tambahHarga(1999, '2026-09-01', '2026-09-10');

        $this->assertSame(2500.0, $this->hargaPada('2026-08-31'), 'sebelum promo');
        $this->assertSame(1999.0, $this->hargaPada('2026-09-05'), 'selama promo');
        $this->assertSame(2500.0, $this->hargaPada('2026-09-11'), 'setelah promo, harga normal hidup lagi');
    }

    public function test_tingkat_kuantitas_lain_tidak_ikut_terganti(): void
    {
        // Rinso PCS punya dua tingkat: min 1 (2.500) dan min 6 (2.300).
        // Mengganti harga tingkat min 1 tidak boleh menyentuh tingkat min 6.
        $this->tambahHarga(2400, '2026-08-21');

        $this->assertSame(2400.0, $this->hargaPada('2026-08-21', 1));
        $this->assertSame(2300.0, $this->hargaPada('2026-08-21', 6), 'tingkat min 6 utuh');
    }

    public function test_harga_yang_sudah_berakhir_tidak_dipakai(): void
    {
        $telur = Product::where('sku', 'TLR-AYM')->with('uoms')->firstOrFail();
        $kg = $telur->uoms->firstWhere('uom_name', 'KG');

        PriceListItem::where('product_id', $telur->product_id)
            ->where('uom_id', $kg->uom_id)
            ->update(['end_date' => '2026-08-20']);

        $hasil = $this->resolver->resolve(
            $telur->owner_id,
            $telur->product_id,
            $kg->uom_id,
            1,
            null,
            null,
            '2026-08-21',
        );

        // Termasuk lewat jaring terakhir is_default: masa berlaku BARIS tetap
        // dihormati, kalau tidak harga yang sengaja dihentikan hidup lagi.
        $this->assertSame(PriceResolver::SOURCE_NONE, $hasil['source']);
    }

    // ------------------------------------------------------- aturan di layar

    public function test_layar_atur_harga_menyembunyikan_riwayat_secara_bawaan(): void
    {
        // Baris yang sudah berakhir hanya muncul kalau riwayat diminta.
        $this->tambahHarga(2450, now()->subMonths(2)->toDateString(), now()->subMonth()->toDateString(), 3);

        $hitung = function (array $query): int {
            $response = $this->actingAs($this->pemilik())->get("/master/harga/{$this->umum->price_list_id}?".http_build_query($query));
            $response->assertOk();

            return count($response->viewData('page')['props']['items']);
        };

        $this->assertGreaterThan($hitung([]), $hitung(['riwayat' => 1]), 'riwayat memuat lebih banyak baris');
    }

    public function test_harga_tidak_dapat_dibuat_berlaku_mundur(): void
    {
        $this->actingAs($this->pemilik())
            ->post("/master/harga/{$this->umum->price_list_id}/tingkat", [
                'product_id' => $this->rinso->product_id,
                'uom_id' => $this->pcs->uom_id,
                'min_qty' => 3,
                'unit_price' => 2450,
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => PriceListItem::TANPA_AKHIR,
            ])
            ->assertSessionHasErrors('start_date');
    }

    public function test_harga_yang_sudah_mulai_berlaku_terkunci(): void
    {
        $lama = $this->tambahHarga(2300, now()->subMonth()->toDateString(), null, 3);

        $this->assertTrue($lama->terkunci());

        $this->actingAs($this->pemilik())
            ->put("/master/harga/{$this->umum->price_list_id}/tingkat/{$lama->price_list_item_id}", [
                'product_id' => $this->rinso->product_id,
                'uom_id' => $this->pcs->uom_id,
                'min_qty' => 3,
                'unit_price' => 1,
                'start_date' => now()->toDateString(),
                'end_date' => PriceListItem::TANPA_AKHIR,
            ])
            ->assertSessionHas('error');

        $this->assertSame('2300.00', $lama->fresh()->unit_price);

        $this->actingAs($this->pemilik())
            ->delete("/master/harga/{$this->umum->price_list_id}/tingkat/{$lama->price_list_item_id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('price_list_items', ['price_list_item_id' => $lama->price_list_item_id]);
    }

    public function test_harga_yang_belum_mulai_berlaku_masih_bebas_diubah(): void
    {
        // Salah ketik hari ini harus bisa langsung diperbaiki; ia belum jadi
        // riwayat karena belum pernah dipakai transaksi mana pun.
        $baru = $this->tambahHarga(9999, now()->toDateString(), null, 3);

        $this->assertFalse($baru->terkunci());

        $this->actingAs($this->pemilik())
            ->put("/master/harga/{$this->umum->price_list_id}/tingkat/{$baru->price_list_item_id}", [
                'product_id' => $this->rinso->product_id,
                'uom_id' => $this->pcs->uom_id,
                'min_qty' => 3,
                'unit_price' => 2450,
                'start_date' => now()->toDateString(),
                'end_date' => PriceListItem::TANPA_AKHIR,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2450.00', $baru->fresh()->unit_price);
    }
}

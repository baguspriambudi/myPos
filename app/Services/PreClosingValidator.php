<?php

namespace App\Services;

use App\Models\CashDifference;
use App\Models\CashierShift;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\Payable;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\StockBalance;
use App\Models\StockOpname;

/**
 * Pemeriksaan sebelum period boleh ditutup (Addendum 46.1, 55.3, 56.10, 56.18).
 *
 * Hasilnya tiga tingkat, dan bedanya penting:
 *
 *   error  — menghalangi penutupan.
 *   warn   — wajib ditampilkan, tetapi tidak menghalangi.
 *   ok     — sudah beres.
 *
 * Sebagian error dapat ditembus persetujuan eksplisit OWNER (`overridable`).
 * Itu bukan tombol "abaikan saja": persetujuannya dicatat di
 * `fiscal_period_logs` beserta alasannya, sehingga angka period yang ditutup
 * dalam keadaan tidak ideal tetap dapat dipertanggungjawabkan.
 */
class PreClosingValidator
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARN = 'warn';

    public const STATUS_ERROR = 'error';

    /** Kunci pemeriksaan yang boleh ditembus persetujuan OWNER. */
    public const OVERRIDE_STOK_NEGATIF = 'stok_negatif';

    public const OVERRIDE_BELUM_FAKTUR = 'penerimaan_belum_faktur';

    public const OVERRIDES = [self::OVERRIDE_STOK_NEGATIF, self::OVERRIDE_BELUM_FAKTUR];

    /**
     * @param  array<int, string>  $disetujui  kunci pemeriksaan yang ditembus OWNER
     * @return array<int, array{key: string, label: string, detail: string, status: string, overridable: bool, approved: bool}>
     */
    public function check(FiscalPeriod $period, array $disetujui = []): array
    {
        return [
            $this->periodeSebelumnya($period),
            $this->shiftTerbuka($period),
            $this->stokNegatif($period, $disetujui),
            $this->penerimaanBelumFaktur($period, $disetujui),
            $this->opnameDraf($period),
            $this->selisihKas($period),
            $this->utangPiutang($period),
        ];
    }

    /**
     * Hal-hal yang benar-benar masih menghalangi penutupan.
     *
     * @param  array<int, string>  $disetujui
     * @return array<int, array<string, mixed>>
     */
    public function blockers(FiscalPeriod $period, array $disetujui = []): array
    {
        return array_values(array_filter(
            $this->check($period, $disetujui),
            fn (array $c) => $c['status'] === self::STATUS_ERROR && ! $c['approved'],
        ));
    }

    /**
     * Nama pemeriksaan yang ditembus persetujuan pada percobaan penutupan ini.
     *
     * @param  array<int, string>  $disetujui
     * @return array<int, string>
     */
    public function overridesTerpakai(FiscalPeriod $period, array $disetujui = []): array
    {
        return array_values(array_map(
            fn (array $c) => $c['label'],
            array_filter(
                $this->check($period, $disetujui),
                fn (array $c) => $c['status'] === self::STATUS_ERROR && $c['approved'],
            ),
        ));
    }

    /** @return array<string, mixed> */
    private function periodeSebelumnya(FiscalPeriod $period): array
    {
        $sebelumnya = $period->sebelumnya();

        if ($sebelumnya === null) {
            return $this->hasil('periode_sebelumnya', 'Periode sebelumnya sudah ditutup', 'Ini periode pertama pada lokasi ini.', self::STATUS_OK);
        }

        if ($sebelumnya->status === FiscalPeriod::STATUS_CLOSED) {
            return $this->hasil('periode_sebelumnya', 'Periode sebelumnya sudah ditutup', "{$sebelumnya->period_code} berstatus CLOSED.", self::STATUS_OK);
        }

        // Tidak dapat ditembus persetujuan: saldo awal period ini diambil dari
        // snapshot period sebelumnya. Tanpa snapshot itu, angka pembukanya
        // hanya tebakan.
        return $this->hasil(
            'periode_sebelumnya',
            'Periode sebelumnya belum ditutup',
            "{$sebelumnya->period_code} masih berstatus {$sebelumnya->status}; saldo awal periode ini bergantung padanya.",
            self::STATUS_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function shiftTerbuka(FiscalPeriod $period): array
    {
        $terbuka = CashierShift::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', CashierShift::STATUS_OPEN)
            ->with('cashier')
            ->get();

        if ($terbuka->isEmpty()) {
            return $this->hasil('shift_terbuka', 'Seluruh shift kasir sudah ditutup', 'Tidak ada laci yang masih terbuka.', self::STATUS_OK);
        }

        $nama = $terbuka->map(fn (CashierShift $s) => $s->cashier?->full_name ?? $s->shift_number)->unique()->implode(', ');

        return $this->hasil(
            'shift_terbuka',
            "{$terbuka->count()} shift kasir masih terbuka",
            "Tutup dulu shift milik {$nama}.",
            self::STATUS_ERROR,
        );
    }

    /**
     * @param  array<int, string>  $disetujui
     * @return array<string, mixed>
     */
    private function stokNegatif(FiscalPeriod $period, array $disetujui): array
    {
        $negatif = StockBalance::whereIn('branch_id', $period->branchIds())
            ->where('qty_on_hand', '<', 0)
            ->get();

        if ($negatif->isEmpty()) {
            return $this->hasil('stok_negatif', 'Tidak ada stok bersaldo negatif', 'Seluruh saldo produk nol atau lebih.', self::STATUS_OK);
        }

        $nama = Product::whereIn('product_id', $negatif->pluck('product_id'))->limit(5)->pluck('product_name')->implode(', ');
        $sisa = max($negatif->count() - 5, 0);

        return $this->hasil(
            self::OVERRIDE_STOK_NEGATIF,
            "{$negatif->count()} produk bersaldo negatif",
            $nama.($sisa > 0 ? ", dan {$sisa} lainnya" : '').'. Selesaikan lewat penyesuaian stok atau opname.',
            self::STATUS_ERROR,
            overridable: true,
            approved: in_array(self::OVERRIDE_STOK_NEGATIF, $disetujui, true),
        );
    }

    /**
     * @param  array<int, string>  $disetujui
     * @return array<string, mixed>
     */
    private function penerimaanBelumFaktur(FiscalPeriod $period, array $disetujui): array
    {
        $belum = GoodsReceipt::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', GoodsReceipt::STATUS_POSTED)
            ->uninvoiced()
            ->get();

        if ($belum->isEmpty()) {
            return $this->hasil('penerimaan_belum_faktur', 'Seluruh penerimaan sudah difakturkan', 'Tidak ada barang yang nilainya masih sementara.', self::STATUS_OK);
        }

        return $this->hasil(
            self::OVERRIDE_BELUM_FAKTUR,
            "{$belum->count()} penerimaan belum difakturkan",
            'Nilainya masih memakai harga sementara: '.$belum->take(3)->pluck('receipt_number')->implode(', ').'.',
            self::STATUS_ERROR,
            overridable: true,
            approved: in_array(self::OVERRIDE_BELUM_FAKTUR, $disetujui, true),
        );
    }

    /** @return array<string, mixed> */
    private function opnameDraf(FiscalPeriod $period): array
    {
        $draf = StockOpname::where('fiscal_period_id', $period->fiscal_period_id)
            ->where('status', StockOpname::STATUS_DRAFT)
            ->count();

        if ($draf === 0) {
            return $this->hasil('opname_draf', 'Tidak ada hitungan opname menggantung', 'Semua hitungan sudah diterapkan atau dibatalkan.', self::STATUS_OK);
        }

        return $this->hasil(
            'opname_draf',
            "{$draf} hitungan opname masih draf",
            'Hitungan yang tidak diterapkan tidak memengaruhi saldo periode ini.',
            self::STATUS_WARN,
        );
    }

    /** @return array<string, mixed> */
    private function selisihKas(FiscalPeriod $period): array
    {
        $terbuka = CashDifference::where('fiscal_period_id', $period->fiscal_period_id)->open()->get();

        if ($terbuka->isEmpty()) {
            return $this->hasil('selisih_kas', 'Selisih kas sudah diselesaikan', 'Tidak ada selisih shift yang menggantung.', self::STATUS_OK);
        }

        $total = round((float) $terbuka->sum('difference'), 2);

        // Peringatan, bukan penghalang (Addendum 46.1): selisih kas boleh
        // diselesaikan setelah period ditutup, asal terlihat di laporan closing.
        return $this->hasil(
            'selisih_kas',
            "{$terbuka->count()} selisih kas belum diselesaikan",
            'Total selisih Rp'.number_format(abs($total), 0, ',', '.').($total < 0 ? ' (kurang)' : ' (lebih)').'.',
            self::STATUS_WARN,
        );
    }

    /** @return array<string, mixed> */
    private function utangPiutang(FiscalPeriod $period): array
    {
        $branchIds = $period->branchIds();
        $terbuka = [Receivable::STATUS_OPEN, Receivable::STATUS_PARTIAL];

        $piutang = Receivable::whereIn('branch_id', $branchIds)->whereIn('status', $terbuka)->count();
        $utang = Payable::whereIn('branch_id', $branchIds)->whereIn('status', $terbuka)->count();

        if ($piutang === 0 && $utang === 0) {
            return $this->hasil('utang_piutang', 'Tidak ada utang/piutang terbuka', 'Semua tagihan sudah lunas.', self::STATUS_OK);
        }

        // Sengaja bukan peringatan: utang dan piutang memang dirancang melintas
        // period (Addendum 47). Ditampilkan agar terlihat, bukan agar dikejar.
        return $this->hasil(
            'utang_piutang',
            "{$piutang} piutang dan {$utang} utang masih terbuka",
            'Wajar terbawa ke periode berikutnya; tidak menghalangi penutupan.',
            self::STATUS_OK,
        );
    }

    /** @return array<string, mixed> */
    private function hasil(string $key, string $label, string $detail, string $status, bool $overridable = false, bool $approved = false): array
    {
        return compact('key', 'label', 'detail', 'status', 'overridable', 'approved');
    }
}

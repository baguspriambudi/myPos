<?php

namespace App\Services;

use App\Models\FiscalPeriod;
use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockLotConsumption;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buku besar stok: satu-satunya pintu keluar-masuk persediaan.
 *
 * Seluruh perubahan stok melewati kelas ini supaya empat hal selalu bergerak
 * bersama — movement, lot, jejak konsumsi lot, dan saldo. Bila salah satunya
 * boleh diubah dari tempat lain, saldo dan riwayat akan berselisih dan HPP
 * tidak lagi dapat dihitung ulang (PRD 15).
 *
 * Semua qty di sini dalam SATUAN BASE. Konversi dari satuan transaksi terjadi
 * di pintu masuk kelas ini, bukan di pemanggilnya.
 *
 * WAJIB dipanggil di dalam transaksi database.
 */
class StockLedger
{
    public const METHOD_FIFO = 'FIFO';

    /**
     * Keluarkan stok untuk satu baris penjualan, dan tentukan HPP-nya.
     *
     * @return array{cost_at_sale: float, cogs_total: float, movement: ?StockMovement}
     *
     * @throws ValidationException bila stok tidak cukup dan owner melarang stok negatif.
     */
    public function issueForSale(
        Owner $owner,
        FiscalPeriod $period,
        int $branchId,
        string $date,
        Product $product,
        ProductUom $uom,
        float $qty,
        int $saleId,
        ?int $userId,
    ): array {
        return $this->issue(
            $owner,
            $period,
            $branchId,
            $date,
            $product,
            $uom,
            $qty,
            StockMovement::TYPE_SALES,
            StockMovement::REF_SALES,
            $saleId,
            $userId,
        );
    }

    /**
     * Keluarkan stok apa pun sebabnya: penjualan, penyesuaian, transfer keluar.
     *
     * Harga keluarnya SELALU ditentukan persediaan yang benar-benar terpakai,
     * tidak pernah diisi pemanggil — itulah sebabnya semua jalur keluar melewati
     * satu pintu ini.
     *
     * @return array{cost_at_sale: float, cogs_total: float, movement: ?StockMovement}
     *
     * @throws ValidationException bila stok tidak cukup dan owner melarang stok negatif.
     */
    public function issue(
        Owner $owner,
        FiscalPeriod $period,
        int $branchId,
        string $date,
        Product $product,
        ProductUom $uom,
        float $qty,
        string $movementType,
        ?string $referenceType,
        ?int $referenceId,
        ?int $userId,
        string $errorKey = 'lines',
    ): array {
        // Jasa tidak punya persediaan: tidak ada movement, tidak ada HPP.
        // Menyimpan movement bernilai nol pun hanya mengotori kartu stok dengan
        // baris yang tidak pernah berarti apa-apa.
        if (! $product->tracksStock()) {
            return ['cost_at_sale' => 0.0, 'cogs_total' => 0.0, 'movement' => null];
        }

        $qtyBase = round($qty * (float) $uom->conversion_factor, 4);

        // Kunci saldo lebih dulu. Kunci baris inilah yang membuat dua kasir yang
        // menjual produk sama pada saat bersamaan tidak saling menimpa —
        // sekaligus menjadikan penomoran sequence movement aman, karena scope
        // kunci dan scope sequence sama-sama (lokasi + produk).
        $balance = $this->lockedBalance($branchId, $product->product_id);
        $onHand = (float) $balance->qty_on_hand;

        if ($qtyBase > $onHand && ! $owner->allow_negative_stock) {
            throw ValidationException::withMessages([
                $errorKey => sprintf(
                    'Stok %s tidak mencukupi: tersedia %s, diminta %s (satuan dasar).',
                    $product->product_name,
                    $this->angka($onHand),
                    $this->angka($qtyBase),
                ),
            ]);
        }

        $consumed = $this->consumeLots($branchId, $product->product_id, $qtyBase);
        $shortage = round($qtyBase - $consumed['qty'], 4);

        if ($owner->costing_method === self::METHOD_FIFO) {
            // Kekurangan hanya mungkin terjadi saat stok negatif diizinkan;
            // harganya memakai lot terakhir yang sempat terpakai, lalu saldo
            // rata-rata sebagai cadangan (Addendum 55.2).
            $fallback = $consumed['last_cost'] ?? (float) $balance->avg_cost;
            $cogs = $consumed['cost'] + $shortage * $fallback;
        } else {
            // AVERAGE — dan sementara SPECIFIC juga, sampai modul yang
            // memerlukannya ada. avg_cost 0 berarti produk belum pernah masuk
            // lewat pembelian; HPP-nya 0 dan perlu dikoreksi (Addendum 55.2).
            $cogs = $qtyBase * (float) $balance->avg_cost;
        }

        $cogsTotal = round($cogs, 2);
        $costAtSale = $qtyBase > 0 ? round($cogs / $qtyBase, 4) : 0.0;

        $movement = StockMovement::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'branch_id' => $branchId,
            'product_id' => $product->product_id,
            'movement_date' => $date,
            'movement_type' => $movementType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'uom_id' => $uom->uom_id,
            // Bertanda negatif: barang keluar. Menjumlahkan seluruh qty_base
            // sebuah produk harus menghasilkan saldonya.
            'qty' => -$qty,
            'qty_base' => -$qtyBase,
            'unit_cost' => $costAtSale,
            'total_cost' => -$cogsTotal,
            'sequence' => $this->nextSequence($period->fiscal_period_id, $branchId, $product->product_id),
            'created_by' => $userId,
        ]);

        foreach ($consumed['rows'] as $row) {
            StockLotConsumption::create([
                'movement_id' => $movement->movement_id,
                'lot_id' => $row['lot_id'],
                'qty_base' => $row['qty'],
                'unit_cost' => $row['unit_cost'],
                'total_cost' => round($row['qty'] * $row['unit_cost'], 2),
            ]);
        }

        $balance->qty_on_hand = round($onHand - $qtyBase, 4);
        $balance->save();

        return ['cost_at_sale' => $costAtSale, 'cogs_total' => $cogsTotal, 'movement' => $movement];
    }

    /**
     * Masukkan stok: opening awal maupun penyesuaian.
     *
     * Dipakai penuh oleh modul persediaan kelak; untuk sekarang jalur inilah
     * yang menyiapkan stok pada pengujian penjualan barang dagang.
     */
    public function receive(
        FiscalPeriod $period,
        int $branchId,
        string $date,
        Product $product,
        ProductUom $uom,
        float $qty,
        float $unitCostBase,
        string $movementType = StockMovement::TYPE_OPENING,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $userId = null,
        ?int $receiptItemId = null,
        ?int $returnItemId = null,
        ?int $transferItemId = null,
    ): StockMovement {
        if (! $product->tracksStock()) {
            throw ValidationException::withMessages([
                'product_id' => "{$product->product_name} adalah jasa dan tidak memiliki persediaan.",
            ]);
        }

        $qtyBase = round($qty * (float) $uom->conversion_factor, 4);
        $totalCost = round($qtyBase * $unitCostBase, 2);

        $balance = $this->lockedBalance($branchId, $product->product_id);
        $onHand = (float) $balance->qty_on_hand;

        StockLot::create([
            'branch_id' => $branchId,
            'product_id' => $product->product_id,
            'fiscal_period_id' => $period->fiscal_period_id,
            // Lot menunjuk baris penerimaan asalnya supaya retur pembelian tahu
            // partai mana yang harus dikurangi (Addendum 48.2, 58.6). Lot dari
            // retur penjualan menunjuk baris retur itu sendiri, dengan alasan
            // yang sama: supaya void-nya tahu lot persis mana yang dikosongkan.
            'receipt_item_id' => $receiptItemId,
            'return_item_id' => $returnItemId,
            'transfer_item_id' => $transferItemId,
            'lot_number' => $this->lotNumber($product, $branchId, $date),
            'received_date' => $date,
            'qty_remaining' => $qtyBase,
            'unit_cost' => $unitCostBase,
            'status' => StockLot::STATUS_ACTIVE,
        ]);

        $movement = StockMovement::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'branch_id' => $branchId,
            'product_id' => $product->product_id,
            'movement_date' => $date,
            'movement_type' => $movementType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'uom_id' => $uom->uom_id,
            'qty' => $qty,
            'qty_base' => $qtyBase,
            'unit_cost' => $unitCostBase,
            'total_cost' => $totalCost,
            'sequence' => $this->nextSequence($period->fiscal_period_id, $branchId, $product->product_id),
            'created_by' => $userId,
        ]);

        // Rata-rata bergerak dihitung ulang tiap barang masuk (Addendum 38.2).
        // Saldo negatif tidak ikut menimbang rata-rata lama: barang yang belum
        // pernah ada tidak punya harga perolehan untuk dirata-ratakan.
        $qtyBaru = round($onHand + $qtyBase, 4);

        if ($qtyBaru > 0) {
            $nilaiLama = max($onHand, 0) * (float) $balance->avg_cost;
            $balance->avg_cost = round(($nilaiLama + $qtyBase * $unitCostBase) / $qtyBaru, 4);
        }

        $balance->qty_on_hand = $qtyBaru;
        $balance->save();

        return $movement;
    }

    /**
     * Balikkan satu pergerakan MASUK — dipakai saat penerimaan barang divoid
     * maupun saat retur penjualan divoid (keduanya sama-sama membentuk lot
     * baru yang harus dikosongkan kembali).
     *
     * Lot yang dibentuk pergerakan itu dikosongkan, bukan dihapus. Syaratnya
     * lot tersebut belum tersentuh sama sekali: barang yang sudah terjual tidak
     * bisa dianggap tidak pernah masuk (Addendum 58.6).
     */
    public function reverseReceipt(Owner $owner, StockMovement $original, ?StockLot $lot, string $date, ?int $userId): StockMovement
    {
        $qtyBase = abs((float) $original->qty_base);

        if ($lot !== null && round((float) $lot->qty_remaining, 4) < round($qtyBase, 4)) {
            throw ValidationException::withMessages([
                'receipt' => "Sebagian {$lot->product->product_name} sudah terpakai sejak itu, sehingga pembatalan ini tidak dapat dilakukan. Selesaikan lewat penyesuaian stok.",
            ]);
        }

        $balance = $this->lockedBalance($original->branch_id, $original->product_id);
        $onHand = (float) $balance->qty_on_hand;

        if ($lot !== null) {
            $lot->qty_remaining = round((float) $lot->qty_remaining - $qtyBase, 4);
            $lot->status = $lot->qty_remaining > 0 ? StockLot::STATUS_ACTIVE : StockLot::STATUS_EXHAUSTED;
            $lot->save();
        }

        $reversal = StockMovement::create([
            'fiscal_period_id' => $original->fiscal_period_id,
            'branch_id' => $original->branch_id,
            'product_id' => $original->product_id,
            'movement_date' => $date,
            'movement_type' => StockMovement::TYPE_VOID_REVERSAL,
            'reference_type' => $original->reference_type,
            'reference_id' => $original->reference_id,
            'uom_id' => $original->uom_id,
            'qty' => -abs((float) $original->qty),
            'qty_base' => -$qtyBase,
            'unit_cost' => (float) $original->unit_cost,
            'total_cost' => -abs((float) $original->total_cost),
            'sequence' => $this->nextSequence($original->fiscal_period_id, $original->branch_id, $original->product_id),
            'created_by' => $userId,
        ]);

        // Nilai yang ikut ditarik keluar adalah nilai yang dulu ia bawa masuk,
        // sehingga rata-rata kembali ke keadaan sebelum penerimaan itu.
        $qtyBaru = round($onHand - $qtyBase, 4);

        if ($owner->costing_method !== self::METHOD_FIFO) {
            $nilaiBaru = max($onHand, 0) * (float) $balance->avg_cost - $qtyBase * (float) $original->unit_cost;
            $balance->avg_cost = $qtyBaru > 0 ? round(max($nilaiBaru, 0) / $qtyBaru, 4) : 0;
        }

        $balance->qty_on_hand = $qtyBaru;
        $balance->save();

        return $reversal;
    }

    /**
     * Balikkan satu pergerakan keluar — dipakai saat transaksi divoid.
     *
     * Movement aslinya TIDAK dihapus; dibuat movement baru bertipe
     * VOID_REVERSAL (Addendum 41.2). Riwayat yang dihapus akan membuat kartu
     * stok berbohong tentang apa yang pernah terjadi, dan HPP tidak lagi bisa
     * dihitung ulang.
     *
     * Qty dikembalikan ke LOT ASALNYA sesuai jejak konsumsi, sehingga FIFO
     * kembali persis ke keadaan sebelum transaksi — bukan menjadi lot baru
     * bertanggal hari ini yang akan mengantre di urutan paling belakang.
     */
    public function reverse(Owner $owner, StockMovement $original, string $date, ?int $userId): StockMovement
    {
        $qtyBase = abs((float) $original->qty_base);
        $balance = $this->lockedBalance($original->branch_id, $original->product_id);
        $onHand = (float) $balance->qty_on_hand;

        $reversal = StockMovement::create([
            'fiscal_period_id' => $original->fiscal_period_id,
            'branch_id' => $original->branch_id,
            'product_id' => $original->product_id,
            'movement_date' => $date,
            'movement_type' => StockMovement::TYPE_VOID_REVERSAL,
            'reference_type' => $original->reference_type,
            'reference_id' => $original->reference_id,
            'uom_id' => $original->uom_id,
            'qty' => abs((float) $original->qty),
            'qty_base' => $qtyBase,
            // Harga baliknya adalah harga saat barang keluar, bukan harga hari
            // ini — yang kembali ke rak persis barang yang dulu keluar.
            'unit_cost' => (float) $original->unit_cost,
            'total_cost' => abs((float) $original->total_cost),
            'sequence' => $this->nextSequence($original->fiscal_period_id, $original->branch_id, $original->product_id),
            'created_by' => $userId,
        ]);

        foreach ($original->consumptions()->whereNull('reversed_by_movement_id')->get() as $consumption) {
            $lot = StockLot::whereKey($consumption->lot_id)->lockForUpdate()->first();

            if ($lot !== null) {
                $lot->qty_remaining = round((float) $lot->qty_remaining + (float) $consumption->qty_base, 4);
                $lot->status = StockLot::STATUS_ACTIVE;
                $lot->save();
            }

            $consumption->update(['reversed_by_movement_id' => $reversal->movement_id]);
        }

        $qtyBaru = round($onHand + $qtyBase, 4);

        // AVERAGE menghitung ulang rata-ratanya dengan harga saat transaksi asli
        // (Addendum 41.2). Transaksi SESUDAHNYA yang sudah terlanjur memakai
        // rata-rata lama tidak ikut dihitung ulang di sini — itu pekerjaan
        // recalculate HPP yang punya jejaknya sendiri di hpp_recalc_logs.
        if ($owner->costing_method !== self::METHOD_FIFO && $qtyBaru > 0) {
            $nilaiLama = max($onHand, 0) * (float) $balance->avg_cost;
            $balance->avg_cost = round(($nilaiLama + $qtyBase * (float) $original->unit_cost) / $qtyBaru, 4);
        }

        $balance->qty_on_hand = $qtyBaru;
        $balance->save();

        return $reversal;
    }

    /**
     * Keluarkan stok dari SATU lot tertentu — dipakai retur pembelian, yang
     * harus mengurangi partai yang benar-benar berasal dari penerimaan yang
     * diretur, bukan FIFO umum atas seluruh lot produk (Addendum 48.2, 58.6).
     *
     * Konsumsinya dicatat lewat `stock_lot_consumptions` seperti jalur keluar
     * lain, sehingga bila retur ini kelak divoid, `reverse()` generik sudah
     * tahu cara mengembalikannya ke lot yang sama.
     *
     * @throws ValidationException bila lot sudah habis terjual/terpakai.
     */
    public function issueFromLot(
        Owner $owner,
        FiscalPeriod $period,
        string $date,
        StockLot $lot,
        ProductUom $uom,
        float $qty,
        string $movementType,
        string $referenceType,
        int $referenceId,
        ?int $userId,
        string $errorKey = 'lines',
    ): StockMovement {
        $qtyBase = round($qty * (float) $uom->conversion_factor, 4);

        if (round((float) $lot->qty_remaining, 4) < $qtyBase) {
            throw ValidationException::withMessages([
                $errorKey => "Sebagian {$lot->product->product_name} dari penerimaan asal sudah terjual atau terpakai; sisa lot hanya {$this->angka((float) $lot->qty_remaining)}. Selesaikan lewat penyesuaian stok dengan persetujuan Pemilik/Super Admin.",
            ]);
        }

        $balance = $this->lockedBalance($lot->branch_id, $lot->product_id);
        $onHand = (float) $balance->qty_on_hand;
        $unitCost = (float) $lot->unit_cost;

        $lot->qty_remaining = round((float) $lot->qty_remaining - $qtyBase, 4);
        $lot->status = $lot->qty_remaining > 0 ? StockLot::STATUS_ACTIVE : StockLot::STATUS_EXHAUSTED;
        $lot->save();

        $totalCost = round($qtyBase * $unitCost, 2);

        $movement = StockMovement::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'branch_id' => $lot->branch_id,
            'product_id' => $lot->product_id,
            'movement_date' => $date,
            'movement_type' => $movementType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'uom_id' => $uom->uom_id,
            'qty' => -$qty,
            'qty_base' => -$qtyBase,
            'unit_cost' => $unitCost,
            'total_cost' => -$totalCost,
            'sequence' => $this->nextSequence($period->fiscal_period_id, $lot->branch_id, $lot->product_id),
            'created_by' => $userId,
        ]);

        StockLotConsumption::create([
            'movement_id' => $movement->movement_id,
            'lot_id' => $lot->lot_id,
            'qty_base' => $qtyBase,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
        ]);

        $qtyBaru = round($onHand - $qtyBase, 4);

        // Sama seperti reverseReceipt(): nilai yang ditarik keluar adalah nilai
        // lot itu sendiri, bukan rata-rata berjalan saat ini — konsisten dengan
        // bagaimana penarikan sebagian penerimaan dihitung.
        if ($owner->costing_method !== self::METHOD_FIFO) {
            $nilaiBaru = max($onHand, 0) * (float) $balance->avg_cost - $qtyBase * $unitCost;
            $balance->avg_cost = $qtyBaru > 0 ? round(max($nilaiBaru, 0) / $qtyBaru, 4) : 0;
        }

        $balance->qty_on_hand = $qtyBaru;
        $balance->save();

        return $movement;
    }

    /** Saldo produk di satu lokasi, dalam satuan base. */
    public function onHand(int $branchId, int $productId): float
    {
        return (float) (StockBalance::where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->value('qty_on_hand') ?? 0);
    }

    /**
     * Ambil qty dari lot terlama lebih dulu (FIFO).
     *
     * Lot ikut dikurangi walau metodenya AVERAGE: lot adalah catatan fisik
     * partai barang, dan bila dibiarkan utuh maka sisa lot akan berselisih
     * dengan saldo begitu metode HPP diganti.
     *
     * @return array{rows: array<int, array{lot_id: int, qty: float, unit_cost: float}>, qty: float, cost: float, last_cost: float|null}
     */
    private function consumeLots(int $branchId, int $productId, float $qtyBase): array
    {
        $sisa = $qtyBase;
        $rows = [];
        $cost = 0.0;
        $lastCost = null;

        $lots = StockLot::where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->available()
            ->fifo()
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($sisa <= 0) {
                break;
            }

            $ambil = min($sisa, (float) $lot->qty_remaining);
            $unitCost = (float) $lot->unit_cost;

            $rows[] = ['lot_id' => $lot->lot_id, 'qty' => $ambil, 'unit_cost' => $unitCost];
            $cost += $ambil * $unitCost;
            $lastCost = $unitCost;
            $sisa = round($sisa - $ambil, 4);

            $lot->qty_remaining = round((float) $lot->qty_remaining - $ambil, 4);
            $lot->status = $lot->qty_remaining > 0 ? StockLot::STATUS_ACTIVE : StockLot::STATUS_EXHAUSTED;
            $lot->save();
        }

        return [
            'rows' => $rows,
            'qty' => round($qtyBase - $sisa, 4),
            'cost' => $cost,
            'last_cost' => $lastCost,
        ];
    }

    private function lockedBalance(int $branchId, int $productId): StockBalance
    {
        StockBalance::firstOrCreate(
            ['branch_id' => $branchId, 'product_id' => $productId],
            ['qty_on_hand' => 0, 'avg_cost' => 0],
        );

        return StockBalance::where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Sequence berjalan per (period, lokasi, produk) — bukan global.
     *
     * Aman dari nomor kembar karena pemanggilnya selalu memegang kunci baris
     * saldo produk tersebut lebih dulu.
     */
    private function nextSequence(int $periodId, int $branchId, int $productId): int
    {
        $max = DB::table('stock_movements')
            ->where('fiscal_period_id', $periodId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->max('sequence');

        return (int) $max + 1;
    }

    private function lotNumber(Product $product, int $branchId, string $date): string
    {
        $urutan = StockLot::where('branch_id', $branchId)
            ->where('product_id', $product->product_id)
            ->whereDate('received_date', $date)
            ->count() + 1;

        return sprintf('LOT/%s/%s/%02d', $product->sku, str_replace('-', '', $date), $urutan);
    }

    /** Angka qty tanpa nol ekor, untuk pesan kesalahan yang enak dibaca. */
    private function angka(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ',');
    }
}

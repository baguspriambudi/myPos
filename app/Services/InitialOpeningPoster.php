<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Models\OpeningBalance;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening awal period pertama (Addendum 40).
 *
 * PRD utama hanya mengenal opening yang diwarisi dari closing period
 * sebelumnya. Period pertama tidak punya pendahulu, jadi saldo awalnya
 * diketik sekali di sini: stok fisik per produk, uang laci, serta piutang dan
 * utang yang sudah berjalan sebelum sistem dipakai.
 *
 * Dua batasan yang membuat jalur ini tidak bisa disalahgunakan:
 *
 *   1. Hanya untuk period berstatus DRAFT, dan hanya period PERTAMA pada
 *      scope tersebut (Addendum 40.4).
 *   2. Hanya boleh disimpan sekali. Sesudahnya, koreksi lewat penyesuaian
 *      stok — sebab begitu period dibuka, angka opening sudah menjadi dasar
 *      HPP seluruh transaksi di dalamnya.
 */
class InitialOpeningPoster
{
    public function __construct(private StockLedger $stock) {}

    /**
     * @param  array{
     *     lines?: array<int, array{product_id: int, uom_id: int, qty: float, unit_cost: float}>,
     *     opening_cash?: float|null,
     *     opening_receivable?: float|null,
     *     opening_payable?: float|null,
     *     note?: string|null
     * }  $payload
     */
    public function simpan(User $user, FiscalPeriod $period, Branch $branch, array $payload): FiscalPeriod
    {
        $this->pastikanBoleh($period, $branch);

        $lines = $this->lines($period->owner_id, $payload['lines'] ?? []);
        $date = $period->start_date->toDateString();

        return DB::transaction(function () use ($user, $period, $branch, $lines, $payload, $date) {
            $nilaiPersediaan = 0.0;

            // Satu movement OPENING per PRODUK, bukan satu movement gabungan
            // (Addendum 40.2 & 56.4): qty produk yang berbeda tidak pernah
            // boleh dijumlahkan dalam satu baris pergerakan.
            foreach ($lines as $line) {
                $movement = $this->stock->receive(
                    $period,
                    $branch->branch_id,
                    $date,
                    $line['product'],
                    $line['uom'],
                    $line['qty'],
                    $line['unit_cost_base'],
                    StockMovement::TYPE_OPENING,
                    StockMovement::REF_OPENING,
                    $period->fiscal_period_id,
                    $user->user_id,
                );

                $nilaiPersediaan += (float) $movement->total_cost;
            }

            $this->simpanSaldo($period, $branch, OpeningBalance::TYPE_INVENTORY, $nilaiPersediaan, $payload['note'] ?? null);
            $this->simpanSaldo($period, $branch, OpeningBalance::TYPE_CASH, (float) ($payload['opening_cash'] ?? 0));
            $this->simpanSaldo($period, $branch, OpeningBalance::TYPE_RECEIVABLE, (float) ($payload['opening_receivable'] ?? 0));
            $this->simpanSaldo($period, $branch, OpeningBalance::TYPE_PAYABLE, (float) ($payload['opening_payable'] ?? 0));

            return $period->fresh();
        });
    }

    /** Sudah pernah diisi? Dipakai layar untuk menyembunyikan formulirnya. */
    public function sudahTersimpan(FiscalPeriod $period): bool
    {
        return $period->openingBalances()->exists()
            || StockMovement::where('fiscal_period_id', $period->fiscal_period_id)
                ->where('movement_type', StockMovement::TYPE_OPENING)
                ->exists();
    }

    private function pastikanBoleh(FiscalPeriod $period, Branch $branch): void
    {
        if ($period->status !== FiscalPeriod::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'period' => "Opening awal hanya dapat diisi selama periode berstatus DRAFT; periode ini {$period->status}. Koreksi saldo lewat penyesuaian stok.",
            ]);
        }

        if ($period->sebelumnya() !== null) {
            throw ValidationException::withMessages([
                'period' => 'Opening awal hanya untuk periode pertama. Periode ini mewarisi saldo awal dari snapshot periode sebelumnya.',
            ]);
        }

        if ($period->branch_id !== null && $period->branch_id !== $branch->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => "Periode ini khusus lokasi lain, bukan {$branch->branch_name}.",
            ]);
        }

        if ($branch->owner_id !== $period->owner_id) {
            throw ValidationException::withMessages(['branch_id' => 'Lokasi tersebut bukan milik usaha ini.']);
        }

        if ($this->sudahTersimpan($period)) {
            throw ValidationException::withMessages([
                'period' => 'Opening awal periode ini sudah tersimpan. Perbaikan berikutnya lewat penyesuaian stok setelah periode dibuka.',
            ]);
        }
    }

    /**
     * @param  array<int, array{product_id: int, uom_id: int, qty: float, unit_cost: float}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function lines(int $ownerId, array $rows): array
    {
        $lines = [];
        $terpakai = [];

        foreach ($rows as $i => $row) {
            $product = Product::where('owner_id', $ownerId)->active()->find($row['product_id'] ?? 0);

            if ($product === null) {
                throw ValidationException::withMessages(["lines.{$i}.product_id" => 'Produk tidak dikenal atau sudah tidak aktif.']);
            }

            if (! $product->tracksStock()) {
                throw ValidationException::withMessages([
                    "lines.{$i}.product_id" => "{$product->product_name} adalah jasa; tidak ada stok awal untuk dicatat.",
                ]);
            }

            // Satu produk satu baris: dua baris produk sama akan melanggar
            // aturan "satu movement OPENING per produk" (Addendum 40.2).
            if (isset($terpakai[$product->product_id])) {
                throw ValidationException::withMessages([
                    "lines.{$i}.product_id" => "{$product->product_name} sudah ada di baris lain. Gabungkan menjadi satu baris.",
                ]);
            }

            $terpakai[$product->product_id] = true;

            $uom = ProductUom::where('product_id', $product->product_id)->where('active', true)->find($row['uom_id'] ?? 0);

            if ($uom === null) {
                throw ValidationException::withMessages([
                    "lines.{$i}.uom_id" => "Satuan tersebut bukan satuan aktif milik {$product->product_name}.",
                ]);
            }

            $qty = (float) ($row['qty'] ?? 0);

            if ($qty <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => 'Stok awal harus lebih dari nol; hapus barisnya bila memang belum ada stok.']);
            }

            if (! $product->is_weighted && fmod($qty, 1.0) !== 0.0) {
                throw ValidationException::withMessages([
                    "lines.{$i}.qty" => "{$product->product_name} tidak dihitung dalam pecahan.",
                ]);
            }

            $unitCost = (float) ($row['unit_cost'] ?? 0);

            if ($unitCost < 0) {
                throw ValidationException::withMessages(["lines.{$i}.unit_cost" => 'Harga perolehan tidak boleh negatif.']);
            }

            $factor = (float) $uom->conversion_factor;

            $lines[] = [
                'product' => $product,
                'uom' => $uom,
                'qty' => $qty,
                // Harga diketik per satuan yang dipilih ("satu dus Rp60.000")
                // karena begitulah orang mengingat harga belinya; buku besar
                // stok bekerja per satuan dasar, jadi dibagi di sini.
                'unit_cost_base' => $factor > 0 ? round($unitCost / $factor, 4) : $unitCost,
            ];
        }

        return $lines;
    }

    private function simpanSaldo(FiscalPeriod $period, Branch $branch, string $type, float $amount, ?string $note = null): void
    {
        if (round($amount, 2) === 0.0 && $type !== OpeningBalance::TYPE_INVENTORY) {
            return;
        }

        OpeningBalance::create([
            'fiscal_period_id' => $period->fiscal_period_id,
            'branch_id' => $branch->branch_id,
            'balance_type' => $type,
            'amount' => round($amount, 2),
            'note' => $note,
        ]);
    }
}

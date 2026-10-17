<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Owner;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\User;
use App\Support\QtyRule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Menghitung isi nota: harga, diskon, dan pajak (Addendum 51.2, 61, PRD 19).
 *
 * Satu-satunya tempat angka nota dihitung. SalePoster memakainya saat
 * memposting, dan layar kasir memakainya lewat pratinjau — supaya angka yang
 * dilihat kasir sebelum menekan Bayar persis sama dengan yang tersimpan.
 *
 * Urutan hitungnya wajib (Addendum 51.2):
 *
 *   1. Bruto baris = round(qty × harga satuan).
 *   2. Diskon baris (ITEM) dari master, otomatis: yang paling besar menang.
 *   3. Diskon nota (TRANSACTION) — dari master yang dipilih kasir, atau
 *      manual oleh MANAJER ke atas — dialokasikan PROPORSIONAL ke baris yang
 *      berhak, sisa pembulatannya jatuh ke baris terakhir.
 *   4. Pajak per baris atas dasar setelah seluruh diskon, dibulatkan per baris.
 *   5. Total nota = jumlah baris yang sudah dibulatkan.
 *
 * `line_total` adalah yang DIBAYAR pembeli untuk baris itu, termasuk pajak.
 * Saat harga sudah termasuk pajak, pajaknya dihitung dari dalam; saat belum,
 * pajak ditambahkan di atasnya. Dengan begitu `net_total` = jumlah
 * `line_total` di kedua mode, dan pendapatan bersih = `line_total - tax_amount`.
 *
 * Diskon tidak pernah menyentuh HPP — HPP tetap dari persediaan (Addendum 61.2).
 */
class SaleCalculator
{
    /** Peran yang boleh memberi diskon manual tanpa master (Addendum 61.2). */
    public const MANUAL_DISCOUNT_ROLES = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    public function __construct(private PriceResolver $prices) {}

    /**
     * @param  array<int, array{product_id: int, uom_id: int, qty: float}>  $rows
     * @param  array{discount_id?: int|null, manual_discount?: float|null, manual_discount_reason?: string|null}  $diskon
     * @return array{
     *     lines: array<int, array<string, mixed>>,
     *     gross_total: float,
     *     discount_total: float,
     *     tax_total: float,
     *     net_total: float,
     *     discount_id: int|null,
     *     manual_discount: float,
     *     taxes: array<int, array{tax_id: int, name: string, rate: float, base_amount: float, tax_amount: float}>,
     *     transaction_discounts: array<int, array<string, mixed>>
     * }
     */
    public function calculate(Owner $owner, Branch $branch, ?Customer $customer, array $rows, string $date, User $user, array $diskon = []): array
    {
        $lines = $this->priceLines($owner, $branch, $customer, $rows, $date);
        $masters = $this->activeDiscounts($owner->owner_id, $date);

        foreach ($lines as $i => $line) {
            [$lines[$i]['item_discount'], $lines[$i]['discount_id']] = $this->itemDiscount($line, $masters);
        }

        $tersedia = $this->eligibleTransactionDiscounts($lines, $masters);
        [$diskonNota, $discountId, $manual] = $this->transactionDiscount($user, $lines, $tersedia, $diskon);

        $lines = $this->allocate($lines, $diskonNota, $discountId === null ? null : $tersedia->firstWhere('discount_id', $discountId));
        $lines = $this->applyTax($lines, (bool) $owner->prices_include_tax);

        return [
            'lines' => $lines,
            'gross_total' => round(array_sum(array_column($lines, 'gross')), 2),
            'discount_total' => round(array_sum(array_column($lines, 'discount_amount')), 2),
            'tax_total' => round(array_sum(array_column($lines, 'tax_amount')), 2),
            'net_total' => round(array_sum(array_column($lines, 'line_total')), 2),
            'discount_id' => $discountId,
            'manual_discount' => $manual,
            'taxes' => $this->taxSummary($lines),
            'transaction_discounts' => $tersedia->map(fn (Discount $d) => [
                'id' => $d->discount_id,
                'name' => $d->discount_name,
                'type' => $d->discount_type,
                'value' => (float) $d->discount_value,
                'amount' => $this->amountFor($d, $this->eligibleBase($lines, $d)),
            ])->values()->all(),
        ];
    }

    /**
     * Ubah baris keranjang menjadi baris berharga.
     *
     * @param  array<int, array{product_id: int, uom_id: int, qty: float}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function priceLines(Owner $owner, Branch $branch, ?Customer $customer, array $rows, string $date): array
    {
        $lines = [];

        foreach ($rows as $i => $row) {
            $qty = (float) ($row['qty'] ?? 0);

            $product = Product::where('owner_id', $owner->owner_id)
                ->active()
                ->sellable()
                ->with('tax')
                ->find($row['product_id'] ?? 0);

            if ($product === null) {
                throw ValidationException::withMessages([
                    "lines.{$i}.product_id" => 'Produk tidak dikenal, tidak aktif, atau memang tidak dijual.',
                ]);
            }

            $uom = ProductUom::where('product_id', $product->product_id)
                ->where('active', true)
                ->find($row['uom_id'] ?? 0);

            if ($uom === null) {
                throw ValidationException::withMessages([
                    "lines.{$i}.uom_id" => "Satuan tersebut bukan satuan aktif milik {$product->product_name}.",
                ]);
            }

            QtyRule::assert($product, $qty, "lines.{$i}.qty");

            $harga = $this->prices->resolve(
                $owner->owner_id,
                $product->product_id,
                $uom->uom_id,
                $qty,
                $branch->branch_id,
                $customer?->customer_id,
                $date,
            );

            // Produk tanpa harga di daftar mana pun tidak boleh dijual — dulu
            // hal ini jatuh ke harga cadangan dan barang terjual seharga Rp0
            // tanpa ada yang menyadarinya (Addendum 39.10).
            if ($harga['source'] === PriceResolver::SOURCE_NONE) {
                throw ValidationException::withMessages([
                    "lines.{$i}.product_id" => "{$product->product_name} ({$uom->uom_name}) belum punya harga di daftar harga mana pun sehingga belum boleh dijual.",
                ]);
            }

            $unitPrice = (float) $harga['unit_price'];
            $factor = (float) $uom->conversion_factor;

            $lines[] = [
                'product' => $product,
                'uom' => $uom,
                'qty' => $qty,
                'qty_base' => round($qty * $factor, 4),
                'unit_price' => $unitPrice,
                'unit_price_base' => $factor > 0 ? round($unitPrice / $factor, 2) : $unitPrice,
                'price_list_id' => $harga['price_list_id'],
                'gross' => round($qty * $unitPrice, 2),
            ];
        }

        return $lines;
    }

    /** @return Collection<int, Discount> */
    private function activeDiscounts(int $ownerId, string $date): Collection
    {
        return Discount::where('owner_id', $ownerId)
            ->where('active', true)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->orderBy('discount_id')
            ->get();
    }

    /** Sasaran diskon: NULL = berlaku untuk semua produk/kategori. */
    private function targets(Discount $d, Product $product): bool
    {
        return ($d->product_id === null || $d->product_id === $product->product_id)
            && ($d->category_id === null || $d->category_id === $product->category_id);
    }

    /**
     * Diskon baris terbesar yang memenuhi syarat. `min_qty` dibandingkan
     * terhadap qty dalam satuan dasar, supaya "minimal 12 pcs" juga terpenuhi
     * oleh pembelian 1 dus isi 12.
     *
     * Hasilnya dibatasi bruto baris: diskon master yang lebih besar dari
     * barisnya membuat baris gratis, bukan negatif.
     *
     * @param  array<string, mixed>  $line
     * @param  Collection<int, Discount>  $masters
     * @return array{0: float, 1: int|null}
     */
    private function itemDiscount(array $line, Collection $masters): array
    {
        $terbaik = [0.0, null];

        foreach ($masters->where('discount_scope', 'ITEM') as $d) {
            if (! $this->targets($d, $line['product'])) {
                continue;
            }

            if ($line['qty_base'] < (float) $d->min_qty || $line['gross'] < (float) $d->min_amount) {
                continue;
            }

            $nilai = min($this->amountFor($d, $line['gross']), $line['gross']);

            if ($nilai > $terbaik[0]) {
                $terbaik = [$nilai, $d->discount_id];
            }
        }

        return $terbaik;
    }

    /** Dasar diskon nota: baris yang menjadi sasarannya, setelah diskon baris. */
    private function eligibleBase(array $lines, Discount $d): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            if ($this->targets($d, $line['product'])) {
                $total += $line['gross'] - $line['item_discount'];
            }
        }

        return round($total, 2);
    }

    /**
     * Diskon nota dari master yang syaratnya terpenuhi keranjang ini.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  Collection<int, Discount>  $masters
     * @return Collection<int, Discount>
     */
    private function eligibleTransactionDiscounts(array $lines, Collection $masters): Collection
    {
        return $masters->where('discount_scope', 'TRANSACTION')
            ->filter(function (Discount $d) use ($lines) {
                $base = $this->eligibleBase($lines, $d);
                $qty = 0.0;

                foreach ($lines as $line) {
                    if ($this->targets($d, $line['product'])) {
                        $qty += $line['qty_base'];
                    }
                }

                return $base > 0 && $base >= (float) $d->min_amount && $qty >= (float) $d->min_qty;
            })
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  Collection<int, Discount>  $tersedia
     * @param  array{discount_id?: int|null, manual_discount?: float|null, manual_discount_reason?: string|null}  $diskon
     * @return array{0: float, 1: int|null, 2: float}
     */
    private function transactionDiscount(User $user, array $lines, Collection $tersedia, array $diskon): array
    {
        $discountId = $diskon['discount_id'] ?? null;
        $manual = round((float) ($diskon['manual_discount'] ?? 0), 2);

        if ($discountId !== null && $manual > 0) {
            throw ValidationException::withMessages([
                'manual_discount' => 'Pilih salah satu: diskon dari master atau diskon manual, tidak keduanya.',
            ]);
        }

        if ($discountId !== null) {
            $master = $tersedia->firstWhere('discount_id', (int) $discountId);

            if ($master === null) {
                throw ValidationException::withMessages([
                    'discount_id' => 'Diskon nota tersebut tidak aktif, sudah lewat masa berlakunya, atau syaratnya belum terpenuhi keranjang ini.',
                ]);
            }

            return [$this->amountFor($master, $this->eligibleBase($lines, $master)), $master->discount_id, 0.0];
        }

        if ($manual <= 0) {
            return [0.0, null, 0.0];
        }

        // KASIR hanya boleh memakai diskon master (Addendum 61.2, 66.1).
        // Keterbatasan uang pecahan ditangani pembulatan pembayaran, bukan diskon.
        if (! in_array($user->role, self::MANUAL_DISCOUNT_ROLES, true)) {
            throw ValidationException::withMessages([
                'manual_discount' => 'Diskon manual hanya boleh diberikan Manajer ke atas. Kasir hanya dapat memakai diskon dari master.',
            ]);
        }

        if (mb_strlen(trim((string) ($diskon['manual_discount_reason'] ?? ''))) < 5) {
            throw ValidationException::withMessages([
                'manual_discount_reason' => 'Alasan diskon manual wajib diisi; alasan ini tercatat di audit log.',
            ]);
        }

        $subtotal = round(array_sum(array_map(fn ($l) => $l['gross'] - $l['item_discount'], $lines)), 2);

        if ($manual > $subtotal) {
            throw ValidationException::withMessages([
                'manual_discount' => 'Diskon manual melebihi nilai belanja — total nota tidak boleh negatif.',
            ]);
        }

        return [$manual, null, $manual];
    }

    /** Nilai rupiah satu master diskon atas dasar tertentu. */
    private function amountFor(Discount $d, float $base): float
    {
        $nilai = $d->discount_type === 'PERCENT'
            ? round($base * (float) $d->discount_value / 100, 2)
            : (float) $d->discount_value;

        if ($d->discount_type === 'PERCENT' && $d->max_discount_amount !== null && (float) $d->max_discount_amount > 0) {
            $nilai = min($nilai, (float) $d->max_discount_amount);
        }

        return round(min($nilai, $base), 2);
    }

    /**
     * Alokasi proporsional diskon nota ke baris (Addendum 61.2). Baris terakhir
     * yang berhak menampung sisa pembulatan, supaya jumlah alokasinya tepat
     * sama dengan diskon notanya.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function allocate(array $lines, float $diskonNota, ?Discount $master): array
    {
        $berhak = [];

        foreach ($lines as $i => $line) {
            $lines[$i]['allocated_discount'] = 0.0;

            if ($master === null || $this->targets($master, $line['product'])) {
                $berhak[] = $i;
            }
        }

        $dasar = array_sum(array_map(fn ($i) => $lines[$i]['gross'] - $lines[$i]['item_discount'], $berhak));

        if ($diskonNota > 0 && $dasar > 0) {
            $sisa = $diskonNota;
            $terakhir = end($berhak);

            foreach ($berhak as $i) {
                $setelahBaris = $lines[$i]['gross'] - $lines[$i]['item_discount'];
                $porsi = $i === $terakhir ? round($sisa, 2) : round($diskonNota * $setelahBaris / $dasar, 2);
                $porsi = min($porsi, round($setelahBaris, 2));

                $lines[$i]['allocated_discount'] = $porsi;
                $sisa -= $porsi;
            }
        }

        foreach ($lines as $i => $line) {
            $lines[$i]['discount_amount'] = round($line['item_discount'] + $line['allocated_discount'], 2);
            $lines[$i]['net_before_tax'] = round($line['gross'] - $lines[$i]['discount_amount'], 2);
        }

        return $lines;
    }

    /**
     * Pajak per baris, dibulatkan per baris (Addendum 51.2 langkah 3).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function applyTax(array $lines, bool $inclusive): array
    {
        foreach ($lines as $i => $line) {
            $tax = $line['product']->tax;
            $rate = $tax !== null && $tax->active ? (float) $tax->tax_rate : 0.0;
            $dasar = $line['net_before_tax'];

            if ($rate <= 0) {
                $pajak = 0.0;
            } elseif ($inclusive) {
                $pajak = round($dasar * $rate / (100 + $rate), 2);
            } else {
                $pajak = round($dasar * $rate / 100, 2);
            }

            $lines[$i]['tax_id'] = $rate > 0 ? $tax->tax_id : null;
            $lines[$i]['tax_amount'] = $pajak;
            $lines[$i]['line_total'] = $inclusive ? $dasar : round($dasar + $pajak, 2);
        }

        return $lines;
    }

    /**
     * Rekap per jenis pajak untuk `sale_taxes`. `base_amount` adalah DPP —
     * nilai baris tanpa pajaknya.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{tax_id: int, name: string, rate: float, base_amount: float, tax_amount: float}>
     */
    private function taxSummary(array $lines): array
    {
        $rekap = [];

        foreach ($lines as $line) {
            if ($line['tax_id'] === null) {
                continue;
            }

            $id = $line['tax_id'];
            $rekap[$id] ??= [
                'tax_id' => $id,
                'name' => $line['product']->tax->tax_name,
                'rate' => (float) $line['product']->tax->tax_rate,
                'base_amount' => 0.0,
                'tax_amount' => 0.0,
            ];
            $rekap[$id]['base_amount'] = round($rekap[$id]['base_amount'] + $line['line_total'] - $line['tax_amount'], 2);
            $rekap[$id]['tax_amount'] = round($rekap[$id]['tax_amount'] + $line['tax_amount'], 2);
        }

        return array_values($rekap);
    }
}

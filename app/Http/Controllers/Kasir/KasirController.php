<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\FiscalPeriodResolver;
use App\Services\PriceResolver;
use App\Services\SaleCalculator;
use App\Services\SalePoster;
use App\Support\ActiveBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Layar kasir: keranjang menjadi nota penjualan.
 *
 * Harga yang tampil di layar dihitung ulang di server saat nota diposting.
 * Angka yang dikirim perangkat kasir tidak pernah dipercaya — hanya produk,
 * satuan, dan kuantitasnya yang dipakai (lihat SalePoster).
 */
class KasirController extends Controller
{
    /** Gudang tidak melayani penjualan (Addendum 59.2). */
    private const PERAN_DILARANG = [User::ROLE_GUDANG];

    private const ATURAN_DISKON = [
        'discount_id' => ['nullable', 'integer'],
        'manual_discount' => ['nullable', 'numeric', 'min:0'],
        'manual_discount_reason' => ['nullable', 'string', 'max:255'],
    ];

    public function __construct(
        private PriceResolver $prices,
        private SalePoster $poster,
        private FiscalPeriodResolver $periods,
        private SaleCalculator $calculator,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);
        $today = now()->toDateString();

        // Pelanggan menentukan harga: member memakai daftar bertanda member
        // (Addendum 39.11). Pilihannya ikut di URL supaya harga katalog dapat
        // dimuat ulang tanpa kehilangan isi keranjang.
        $customer = $this->selectedCustomer($request, $user->owner_id);

        return Inertia::render('kasir/index', [
            'products' => $branch ? $this->catalog($user->owner_id, $branch->branch_id, $customer?->customer_id, $today) : [],
            'categories' => $this->categories($user->owner_id),
            'customers' => $this->customers($user->owner_id),
            'selectedCustomerId' => $customer?->customer_id,
            'canSell' => (bool) $branch?->can_sell,
            'periodProblem' => $this->periodProblem($user->owner_id, $branch?->branch_id, $today),
            'today' => $today,
            'methods' => Payment::TYPES,
            'creditMethod' => Sale::PAYMENT_TYPE_CREDIT,
            'currentShift' => $branch ? $this->currentShift($user->user_id, $branch->branch_id) : null,
            'canManualDiscount' => in_array($user->role, SaleCalculator::MANUAL_DISCOUNT_ROLES, true),
            'pricesIncludeTax' => (bool) Owner::find($user->owner_id)?->prices_include_tax,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (in_array($user->role, self::PERAN_DILARANG, true)) {
            throw new HttpException(403, 'Peran Anda tidak berwenang menerbitkan nota penjualan.');
        }

        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'payment_type' => ['required', 'string'],
            'customer_id' => ['nullable', 'integer'],
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'local_uuid' => ['nullable', 'string', 'size:36'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.uom_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            ...self::ATURAN_DISKON,
        ], [], [
            'payment_type' => 'metode pembayaran',
            'lines' => 'isi keranjang',
        ]);

        $sale = $this->poster->post($user, $branch, $data);

        return back()->with('nota', $this->receipt($sale, $data['payment_type'], $data['cash_received'] ?? null));
    }

    /**
     * Pratinjau angka nota untuk isi keranjang saat ini — dihitung dengan
     * SaleCalculator yang sama dengan posting, jadi diskon, pajak, dan harga
     * bertingkat yang tampil di layar persis yang nanti tersimpan.
     */
    public function hitung(Request $request): JsonResponse
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'lines' => ['present', 'array'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.uom_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            ...self::ATURAN_DISKON,
        ]);

        $owner = Owner::findOrFail($user->owner_id);
        $customer = $this->selectedCustomer(new Request(['pelanggan' => $data['customer_id'] ?? 0]), $owner->owner_id);

        if ($data['lines'] === []) {
            return response()->json(['lines' => [], 'grossTotal' => 0, 'discountTotal' => 0, 'taxTotal' => 0, 'netTotal' => 0, 'taxes' => [], 'transactionDiscounts' => []]);
        }

        $hasil = $this->calculator->calculate($owner, $branch, $customer, $data['lines'], now()->toDateString(), $user, $data);

        return response()->json([
            'lines' => array_map(fn ($l) => [
                'productId' => $l['product']->product_id,
                'uomId' => $l['uom']->uom_id,
                'unitPrice' => $l['unit_price'],
                'gross' => $l['gross'],
                'discount' => $l['discount_amount'],
                'tax' => $l['tax_amount'],
                'lineTotal' => $l['line_total'],
            ], $hasil['lines']),
            'grossTotal' => $hasil['gross_total'],
            'discountTotal' => $hasil['discount_total'],
            'taxTotal' => $hasil['tax_total'],
            'netTotal' => $hasil['net_total'],
            'taxes' => $hasil['taxes'],
            'transactionDiscounts' => $hasil['transaction_discounts'],
        ]);
    }

    /**
     * Katalog beserta harga yang berlaku hari ini dan sisa stoknya.
     *
     * Harga diselesaikan per satuan lewat PriceResolver — satu panggilan per
     * satuan. Selama katalog masih ratusan baris ini tidak terasa; begitu
     * ribuan, resolusi massal-lah yang perlu dibuat, bukan cache di layar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalog(int $ownerId, int $branchId, ?int $customerId, string $date): array
    {
        $stock = StockBalance::where('branch_id', $branchId)
            ->pluck('qty_on_hand', 'product_id');

        return Product::where('owner_id', $ownerId)
            ->active()
            ->sellable()
            ->with(['uoms' => fn ($q) => $q->where('active', true), 'category'])
            ->orderBy('product_name')
            ->get()
            ->map(function (Product $p) use ($ownerId, $branchId, $customerId, $date, $stock) {
                $uoms = $p->uoms
                    ->map(function ($u) use ($ownerId, $p, $branchId, $customerId, $date) {
                        $harga = $this->prices->resolve(
                            $ownerId, $p->product_id, $u->uom_id, 1, $branchId, $customerId, $date,
                        );

                        return [
                            'id' => $u->uom_id,
                            'name' => $u->uom_name,
                            'factor' => (float) $u->conversion_factor,
                            // null = satuan ini belum berharga; layar mematikannya
                            // supaya tidak ada barang yang terjual seharga Rp0.
                            'price' => $harga['unit_price'],
                        ];
                    })
                    ->values();

                return [
                    'id' => $p->product_id,
                    'sku' => $p->sku,
                    'barcode' => $p->barcode,
                    'name' => $p->product_name,
                    'category' => $p->category?->category_name,
                    'isWeighted' => (bool) $p->is_weighted,
                    'tracksStock' => $p->tracksStock(),
                    'baseUom' => $p->uoms->firstWhere('uom_type', 'BASE')?->uom_name ?? $p->uoms->first()?->uom_name,
                    'stock' => (float) ($stock[$p->product_id] ?? 0),
                    'uoms' => $uoms,
                ];
            })
            ->filter(fn (array $p) => collect($p['uoms'])->contains(fn ($u) => $u['price'] !== null))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function categories(int $ownerId): array
    {
        return Product::where('owner_id', $ownerId)
            ->active()
            ->sellable()
            ->with('category')
            ->get()
            ->map(fn (Product $p) => $p->category?->category_name)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function customers(int $ownerId): array
    {
        // Batas piutang berlaku per pelanggan di seluruh usaha, bukan per
        // lokasi — pelanggan yang sama boleh berbelanja kredit di cabang mana
        // pun selama akumulasinya belum melebihi credit_limit-nya.
        $terpakai = Receivable::query()
            ->outstanding()
            ->selectRaw('customer_id, COALESCE(SUM(amount - paid_amount), 0) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        return Customer::where('owner_id', $ownerId)
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('customer_name')
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->customer_id,
                'name' => $c->customer_name,
                'isMember' => (bool) $c->is_member,
                'isDefault' => (bool) $c->is_default,
                'creditLimit' => (float) $c->credit_limit,
                'receivableOutstanding' => (float) ($terpakai[$c->customer_id] ?? 0),
            ])
            ->all();
    }

    private function selectedCustomer(Request $request, int $ownerId): ?Customer
    {
        $id = $request->integer('pelanggan');

        if ($id > 0) {
            return Customer::where('owner_id', $ownerId)->where('active', true)->find($id);
        }

        return Customer::where('owner_id', $ownerId)->where('active', true)->where('is_default', true)->first();
    }

    /**
     * Shift terbuka milik kasir ini di lokasi ini, kalau ada — bukan prasyarat
     * berjualan (lihat SalePoster).
     *
     * @return array<string, mixed>|null
     */
    private function currentShift(int $cashierId, int $branchId): ?array
    {
        $shift = CashierShift::where('branch_id', $branchId)
            ->where('cashier_id', $cashierId)
            ->where('status', CashierShift::STATUS_OPEN)
            ->first();

        if ($shift === null) {
            return null;
        }

        return [
            'id' => $shift->shift_id,
            'number' => $shift->shift_number,
            'openedAt' => $shift->opened_at->toIso8601String(),
            'openingCash' => (float) $shift->opening_cash,
            'openingSource' => $shift->opening_source,
        ];
    }

    /**
     * Alasan kasir belum boleh berjualan hari ini, bila ada.
     *
     * Lebih baik diberitahu di awal daripada baru ketahuan setelah keranjang
     * penuh dan tombol Bayar ditekan.
     */
    private function periodProblem(int $ownerId, ?int $branchId, string $date): ?string
    {
        if ($branchId === null) {
            return 'Anda belum terhubung ke lokasi mana pun.';
        }

        try {
            $this->periods->forDate($ownerId, $branchId, $date);

            return null;
        } catch (ValidationException $e) {
            return $e->validator->errors()->first();
        }
    }

    /**
     * Ringkasan untuk struk. Diambil dari nota yang sudah tersimpan, bukan dari
     * keranjang di layar — yang dicetak harus persis yang tercatat.
     *
     * @return array<string, mixed>
     */
    private function receipt(Sale $sale, string $paymentType, ?float $cashReceived): array
    {
        $sale->loadMissing(['items.product', 'items.uom', 'customer', 'payments']);
        $isCredit = $paymentType === Sale::PAYMENT_TYPE_CREDIT;

        return [
            'number' => $sale->sale_number,
            'date' => $sale->transaction_date->toDateString(),
            'customer' => $sale->customer?->customer_name,
            'paymentType' => $paymentType,
            'grossTotal' => (float) $sale->gross_total,
            'discountTotal' => (float) $sale->discount_total,
            'taxTotal' => (float) $sale->tax_total,
            'netTotal' => (float) $sale->net_total,
            'rounding' => (float) $sale->rounding_amount,
            'paidTotal' => (float) $sale->paid_total,
            // Piutang yang baru lahir dari nota ini — hanya terisi untuk kredit.
            'receivableAmount' => $isCredit ? (float) $sale->net_total : null,
            'cashReceived' => $cashReceived,
            'change' => $cashReceived === null ? null : round($cashReceived - (float) $sale->paid_total, 2),
            'lines' => $sale->items->map(fn ($i) => [
                'name' => $i->product->product_name,
                'qty' => (float) $i->qty,
                'uom' => $i->uom->uom_name,
                'unitPrice' => (float) $i->unit_price,
                'discount' => (float) $i->discount_amount,
                'lineTotal' => (float) $i->line_total,
            ])->all(),
        ];
    }
}

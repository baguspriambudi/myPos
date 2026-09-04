<?php

namespace App\Http\Controllers\Pembelian;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use App\Services\PurchaseInvoicePoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Faktur pemasok (Addendum 58.3, 58.7).
 *
 * Fakturnya dibuat DARI penerimaan yang belum ditagih — bukan dari daftar
 * produk kosong. Dengan begitu tidak mungkin ada faktur untuk barang yang
 * tidak pernah datang, dan tidak mungkin satu penerimaan ditagih dua kali.
 */
class PurchaseInvoiceController extends Controller
{
    private const PERAN_BERHAK = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    public function __construct(private PurchaseInvoicePoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);

        $this->authorizeAccess($user);

        $purchases = Purchase::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id))
            ->with(['supplier', 'items.product', 'items.uom', 'receipts', 'author'])
            ->orderByDesc('purchase_id')
            ->paginate(self::PER_PAGE)
            ->through(fn (Purchase $p) => [
                'id' => $p->purchase_id,
                'number' => $p->purchase_number,
                'date' => $p->transaction_date->toDateString(),
                'supplier' => $p->supplier?->supplier_name,
                'status' => $p->status,
                'subtotal' => (float) $p->subtotal,
                'taxTotal' => (float) $p->tax_total,
                'total' => (float) $p->total,
                'author' => $p->author?->full_name,
                'receipts' => $p->receipts->pluck('receipt_number')->all(),
                'items' => $p->items->map(fn ($i) => [
                    'name' => $i->product->product_name,
                    'qty' => (float) $i->qty,
                    'uom' => $i->uom->uom_name,
                    'unitCost' => (float) $i->unit_cost,
                    'lineTotal' => (float) $i->line_total,
                ])->all(),
            ]);

        return Inertia::render('pembelian/faktur/index', [
            'purchases' => $purchases,
            'openReceipts' => $branch ? $this->uninvoicedReceipts($branch->branch_id) : [],
            'suppliers' => $this->suppliers($user->owner_id),
            'taxes' => $this->taxes($user->owner_id),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeAccess($user);

        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'transaction_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:transaction_date'],
            'tax_id' => ['nullable', 'integer'],
            'receipt_ids' => ['required', 'array', 'min:1'],
            'receipt_ids.*' => ['integer'],
            'lines' => ['array'],
            'lines.*.receipt_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ], [], [
            'supplier_id' => 'pemasok',
            'transaction_date' => 'tanggal faktur',
            'due_date' => 'jatuh tempo',
            'receipt_ids' => 'penerimaan barang',
        ]);

        $purchase = $this->poster->post($user, $branch, $data);

        return back()->with('success', "Faktur {$purchase->purchase_number} tersimpan dan utangnya sudah diakui.");
    }

    public function void(Request $request, Purchase $purchase): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeAccess($user);

        abort_unless($purchase->branch_id === ActiveBranch::for($user)?->branch_id, 404);

        $this->poster->void($user, $purchase);

        return back()->with('success', "Faktur {$purchase->purchase_number} dibatalkan; penerimaannya kembali menggantung.");
    }

    private function authorizeAccess(User $user): void
    {
        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat menangani faktur pemasok.');
        }
    }

    /**
     * Penerimaan yang sudah masuk tetapi belum ditagih — inilah isi akun Utang
     * Belum Ditagih, dan inilah satu-satunya bahan pembuatan faktur.
     *
     * @return array<int, array<string, mixed>>
     */
    private function uninvoicedReceipts(int $branchId): array
    {
        return GoodsReceipt::where('branch_id', $branchId)
            ->uninvoiced()
            ->with(['supplier', 'items.product', 'items.uom'])
            ->orderBy('receipt_date')
            ->get()
            ->map(fn (GoodsReceipt $r) => [
                'id' => $r->goods_receipt_id,
                'number' => $r->receipt_number,
                'date' => $r->receipt_date->toDateString(),
                'supplierId' => $r->supplier_id,
                'supplier' => $r->supplier?->supplier_name,
                'totalCost' => (float) $r->total_cost,
                'items' => $r->items->map(fn ($i) => [
                    'id' => $i->receipt_item_id,
                    'name' => $i->product->product_name,
                    'sku' => $i->product->sku,
                    'qty' => (float) $i->qty,
                    'uom' => $i->uom->uom_name,
                    // Harga usulan: cost sementara saat barang diterima. Admin
                    // menimpanya dengan angka yang tertera di faktur.
                    'unitCost' => (float) $i->unit_cost,
                    'costSource' => $i->cost_source,
                ])->all(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function suppliers(int $ownerId): array
    {
        return Supplier::where('owner_id', $ownerId)
            ->where('active', true)
            ->orderBy('supplier_name')
            ->get()
            ->map(fn (Supplier $s) => ['id' => $s->supplier_id, 'name' => $s->supplier_name])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function taxes(int $ownerId): array
    {
        return Tax::where('owner_id', $ownerId)
            ->where('active', true)
            ->orderBy('tax_name')
            ->get()
            ->map(fn (Tax $t) => ['id' => $t->tax_id, 'name' => $t->tax_name, 'rate' => (float) $t->tax_rate])
            ->all();
    }
}

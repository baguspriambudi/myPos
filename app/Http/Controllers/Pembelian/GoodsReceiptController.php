<?php

namespace App\Http\Controllers\Pembelian;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\GoodsReceiptPoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Penerimaan barang (Addendum 58.2, 58.7).
 *
 * Semua peran boleh mencatat kedatangan barang — termasuk kasir dan petugas
 * gudang — tetapi keduanya tidak boleh melihat maupun mengisi harga. Karena
 * itu kolom nilainya tidak sekadar disembunyikan di layar: datanya memang
 * tidak dikirim ke sana.
 */
class GoodsReceiptController extends Controller
{
    /** Yang boleh melihat harga pada penerimaan (Addendum 58.7). */
    private const PERAN_LIHAT_HARGA = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    public function __construct(private GoodsReceiptPoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);
        $bolehLihatHarga = in_array($user->role, self::PERAN_LIHAT_HARGA, true);

        $receipts = GoodsReceipt::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id))
            ->with(['supplier', 'items.product', 'items.uom', 'author', 'purchase'])
            ->orderByDesc('goods_receipt_id')
            ->paginate(self::PER_PAGE)
            ->through(fn (GoodsReceipt $r) => [
                'id' => $r->goods_receipt_id,
                'number' => $r->receipt_number,
                'date' => $r->receipt_date->toDateString(),
                'supplier' => $r->supplier?->supplier_name,
                'doNumber' => $r->supplier_do_number,
                'status' => $r->status,
                'invoiceNumber' => $r->purchase?->purchase_number,
                'note' => $r->note,
                'author' => $r->author?->full_name,
                'totalCost' => $bolehLihatHarga ? (float) $r->total_cost : null,
                'items' => $r->items->map(fn ($i) => [
                    'name' => $i->product->product_name,
                    'sku' => $i->product->sku,
                    'qty' => (float) $i->qty,
                    'uom' => $i->uom->uom_name,
                    'unitCost' => $bolehLihatHarga ? (float) $i->unit_cost : null,
                    'costSource' => $bolehLihatHarga ? $i->cost_source : null,
                ])->all(),
            ]);

        return Inertia::render('pembelian/penerimaan/index', [
            'receipts' => $receipts,
            'products' => $branch ? $this->products($user->owner_id) : [],
            'suppliers' => $this->suppliers($user->owner_id),
            'canSeeCost' => $bolehLihatHarga,
            'canVoid' => $bolehLihatHarga,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'receipt_date' => ['required', 'date'],
            'supplier_do_number' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.uom_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ], [], [
            'receipt_date' => 'tanggal terima',
            'lines' => 'barang yang diterima',
        ]);

        $receipt = $this->poster->post($user, $branch, $data);

        return back()->with('success', "Penerimaan {$receipt->receipt_number} tersimpan dan stoknya sudah bertambah.");
    }

    public function void(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_LIHAT_HARGA, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat membatalkan penerimaan.');
        }

        abort_unless($goodsReceipt->branch->owner_id === $user->owner_id, 404);

        $this->poster->void($user, $goodsReceipt);

        return back()->with('success', "Penerimaan {$goodsReceipt->receipt_number} dibatalkan dan stoknya ditarik kembali.");
    }

    /** @return array<int, array<string, mixed>> */
    private function products(int $ownerId): array
    {
        return Product::where('owner_id', $ownerId)
            ->active()
            ->stocked()
            ->with(['uoms' => fn ($q) => $q->where('active', true)])
            ->orderBy('product_name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->product_id,
                'name' => $p->product_name,
                'sku' => $p->sku,
                'isWeighted' => (bool) $p->is_weighted,
                'uoms' => $p->uoms->map(fn ($u) => ['id' => $u->uom_id, 'name' => $u->uom_name, 'factor' => (float) $u->conversion_factor])->values(),
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
}

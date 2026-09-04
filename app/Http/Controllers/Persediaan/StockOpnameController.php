<?php

namespace App\Http\Controllers\Persediaan;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\StockOpnamePoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Stock opname (Addendum 39.8, 50, 59.3).
 *
 * Mencatat hitungan (DRAFT) terbuka untuk semua peran termasuk KASIR/GUDANG;
 * menerapkannya jadi penyesuaian sungguhan dibatasi MANAGER ke atas — sama
 * seperti "GUDANG menghitung fisik, atasannya yang mengesahkan selisih".
 */
class StockOpnameController extends Controller
{
    private const PERAN_TERAPKAN = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    public function __construct(private StockOpnamePoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);
        $bolehLihatHarga = in_array($user->role, self::PERAN_TERAPKAN, true);

        $opnames = StockOpname::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id))
            ->with(['items.product', 'items.uom', 'author', 'appliedByUser'])
            ->orderByDesc('opname_id')
            ->paginate(self::PER_PAGE)
            ->through(fn (StockOpname $o) => [
                'id' => $o->opname_id,
                'number' => $o->opname_number,
                'date' => $o->opname_date->toDateString(),
                'status' => $o->status,
                'author' => $o->author?->full_name,
                'appliedBy' => $o->appliedByUser?->full_name,
                'items' => $o->items->map(fn ($i) => [
                    'name' => $i->product->product_name,
                    'sku' => $i->product->sku,
                    'systemQty' => (float) $i->system_qty,
                    'actualQty' => (float) $i->actual_qty,
                    'uom' => $i->uom->uom_name,
                    'differenceQtyBase' => (float) $i->difference_qty_base,
                    'differenceValue' => $bolehLihatHarga ? (float) $i->difference_value : null,
                ])->all(),
            ]);

        return Inertia::render('stok/opname/index', [
            'opnames' => $opnames,
            'products' => $branch ? $this->products($user->owner_id, $branch->branch_id) : [],
            'canTerapkan' => $bolehLihatHarga,
            'canSeeCost' => $bolehLihatHarga,
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
            'opname_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.uom_id' => ['required', 'integer'],
            'lines.*.actual_qty' => ['required', 'numeric', 'min:0'],
        ], [], [
            'opname_date' => 'tanggal hitung',
            'lines' => 'barang yang dihitung',
        ]);

        $opname = $this->poster->create($user, $branch, $data);

        return back()->with('success', "Hitungan {$opname->opname_number} tersimpan sebagai draf. Terapkan untuk mengubah saldo.");
    }

    public function terapkan(Request $request, StockOpname $stockOpname): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_TERAPKAN, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat menerapkan stock opname.');
        }

        abort_unless($stockOpname->branch->owner_id === $user->owner_id, 404);

        $this->poster->apply($user, $stockOpname);

        return back()->with('success', "Opname {$stockOpname->opname_number} diterapkan; selisihnya sudah menjadi penyesuaian stok.");
    }

    public function batal(Request $request, StockOpname $stockOpname): RedirectResponse
    {
        $user = $request->user();

        abort_unless($stockOpname->branch->owner_id === $user->owner_id, 404);

        $this->poster->cancel($user, $stockOpname);

        return back()->with('success', "Draf {$stockOpname->opname_number} dibatalkan.");
    }

    /** @return array<int, array<string, mixed>> */
    private function products(int $ownerId, int $branchId): array
    {
        $balances = StockBalance::where('branch_id', $branchId)->get()->keyBy('product_id');

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
                'baseUom' => $p->uoms->firstWhere('uom_type', 'BASE')?->uom_name,
                'stock' => (float) ($balances->get($p->product_id)?->qty_on_hand ?? 0),
                'uoms' => $p->uoms->map(fn ($u) => ['id' => $u->uom_id, 'name' => $u->uom_name, 'factor' => (float) $u->conversion_factor])->values(),
            ])
            ->all();
    }
}

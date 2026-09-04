<?php

namespace App\Http\Controllers\Persediaan;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\StockAdjustmentPoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Penyesuaian stok (Addendum 40.4, 55.3).
 *
 * Hanya OWNER, SUPER ADMIN, dan MANAGER yang boleh memposting (Addendum 50).
 * Kasir dan petugas gudang sengaja tidak: penyesuaian mengubah nilai
 * persediaan tanpa ada dokumen jual-beli yang menopangnya, dan itu jalur yang
 * paling mudah dipakai menutupi selisih.
 */
class StockAdjustmentController extends Controller
{
    private const PERAN_BERHAK = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    public function __construct(private StockAdjustmentPoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);

        $adjustments = StockAdjustment::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id))
            ->with(['product', 'uom', 'author'])
            ->orderByDesc('adjustment_id')
            ->paginate(self::PER_PAGE)
            ->through(fn (StockAdjustment $a) => [
                'id' => $a->adjustment_id,
                'number' => $a->adjustment_number,
                'date' => $a->adjustment_date->toDateString(),
                'productName' => $a->product->product_name,
                'sku' => $a->product->sku,
                'uomName' => $a->uom->uom_name,
                'type' => $a->adjustment_type,
                'qty' => (float) $a->qty,
                'qtyBase' => (float) $a->qty_base,
                'unitCost' => (float) $a->unit_cost,
                'value' => round((float) $a->qty_base * (float) $a->unit_cost, 2),
                'reason' => $a->reason,
                'author' => $a->author?->full_name ?? $a->author?->username,
            ]);

        return Inertia::render('stok/penyesuaian/index', [
            'adjustments' => $adjustments,
            'products' => $branch ? $this->products($user->owner_id, $branch->branch_id) : [],
            'canPost' => in_array($user->role, self::PERAN_BERHAK, true),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat memposting penyesuaian stok.');
        }

        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'uom_id' => ['required', 'integer'],
            'adjustment_type' => ['required', 'string'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
            'adjustment_date' => ['required', 'date'],
        ], [], [
            'product_id' => 'produk',
            'uom_id' => 'satuan',
            'adjustment_type' => 'jenis penyesuaian',
            'qty' => 'kuantitas',
            'unit_cost' => 'harga perolehan',
            'reason' => 'alasan',
            'adjustment_date' => 'tanggal',
        ]);

        $adjustment = $this->poster->post($user, $branch, $data);

        return back()->with('success', "Penyesuaian {$adjustment->adjustment_number} tersimpan.");
    }

    /**
     * Produk yang punya kartu stok beserta saldo dan rata-ratanya.
     *
     * Jasa tidak ikut — tidak ada yang bisa disesuaikan. Aset ikut, karena aset
     * memang punya stok walau tidak dijual (Addendum 39.14).
     *
     * @return array<int, array<string, mixed>>
     */
    private function products(int $ownerId, int $branchId): array
    {
        $balances = StockBalance::where('branch_id', $branchId)->get()->keyBy('product_id');

        return Product::where('owner_id', $ownerId)
            ->active()
            ->stocked()
            ->with(['uoms' => fn ($q) => $q->where('active', true)])
            ->orderBy('product_name')
            ->get()
            ->map(function (Product $p) use ($balances) {
                $balance = $balances->get($p->product_id);

                return [
                    'id' => $p->product_id,
                    'name' => $p->product_name,
                    'sku' => $p->sku,
                    'isWeighted' => (bool) $p->is_weighted,
                    'baseUom' => $p->uoms->firstWhere('uom_type', 'BASE')?->uom_name,
                    'stock' => (float) ($balance->qty_on_hand ?? 0),
                    'avgCost' => (float) ($balance->avg_cost ?? 0),
                    'uoms' => $p->uoms->map(fn ($u) => [
                        'id' => $u->uom_id,
                        'name' => $u->uom_name,
                        'factor' => (float) $u->conversion_factor,
                    ])->values(),
                ];
            })
            ->all();
    }
}

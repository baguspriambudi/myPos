<?php

namespace App\Http\Controllers\Persediaan;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockTransferPoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Transfer stok antar-lokasi (Addendum 49).
 *
 * GUDANG boleh memposting transfer (bukan sekadar input) — beda dengan
 * faktur/penyesuaian yang mengharuskan MANAGER ke atas — tapi tetap tidak
 * melihat harga (Addendum 50, 59.3).
 */
class StockTransferController extends Controller
{
    private const PERAN_INPUT = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER, User::ROLE_GUDANG];

    private const PERAN_LIHAT_HARGA = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    public function __construct(private StockTransferPoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_INPUT, true)) {
            throw new HttpException(403, 'Kasir tidak dapat mengakses transfer antar-lokasi.');
        }

        $branch = ActiveBranch::for($user);
        $bolehLihatHarga = in_array($user->role, self::PERAN_LIHAT_HARGA, true);

        $transfers = StockTransfer::query()
            ->when($branch, fn ($q) => $q->where(fn ($qq) => $qq->where('from_branch_id', $branch->branch_id)->orWhere('to_branch_id', $branch->branch_id)))
            ->with(['fromBranch', 'toBranch', 'items.product', 'items.uom', 'author'])
            ->orderByDesc('transfer_id')
            ->paginate(self::PER_PAGE)
            ->through(fn (StockTransfer $t) => [
                'id' => $t->transfer_id,
                'number' => $t->transfer_number,
                'date' => $t->transaction_date->toDateString(),
                'fromBranchId' => $t->from_branch_id,
                'fromBranch' => $t->fromBranch->branch_name,
                'toBranch' => $t->toBranch->branch_name,
                'status' => $t->status,
                'author' => $t->author?->full_name,
                'items' => $t->items->map(fn ($i) => [
                    'name' => $i->product->product_name,
                    'sku' => $i->product->sku,
                    'qty' => (float) $i->qty,
                    'uom' => $i->uom->uom_name,
                    'unitCost' => $bolehLihatHarga ? (float) $i->unit_cost : null,
                ])->all(),
            ]);

        return Inertia::render('stok/transfer/index', [
            'transfers' => $transfers,
            'products' => $branch ? $this->products($user->owner_id, $branch->branch_id) : [],
            'destinations' => $branch ? $this->destinations($user->owner_id, $branch->branch_id) : [],
            'activeBranchId' => $branch?->branch_id,
            'canSeeCost' => $bolehLihatHarga,
            'canVoid' => $bolehLihatHarga,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_INPUT, true)) {
            throw new HttpException(403, 'Kasir tidak dapat memposting transfer antar-lokasi.');
        }

        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['from_branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'to_branch_id' => ['required', 'integer'],
            'transaction_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.uom_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ], [], [
            'to_branch_id' => 'lokasi tujuan',
            'transaction_date' => 'tanggal transfer',
            'lines' => 'barang yang ditransfer',
        ]);

        $data['from_branch_id'] = $branch->branch_id;

        $transfer = $this->poster->post($user, $data);

        return back()->with('success', "Transfer {$transfer->transfer_number} tersimpan.");
    }

    public function void(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_LIHAT_HARGA, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat membatalkan transfer.');
        }

        // Hanya boleh dari sisi pengirim (Addendum 49.4).
        abort_unless(ActiveBranch::for($user)?->branch_id === $stockTransfer->from_branch_id, 404);

        $this->poster->void($user, $stockTransfer);

        return back()->with('success', "Transfer {$stockTransfer->transfer_number} dibatalkan.");
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
                'stock' => (float) ($balances->get($p->product_id)?->qty_on_hand ?? 0),
                'uoms' => $p->uoms->map(fn ($u) => ['id' => $u->uom_id, 'name' => $u->uom_name, 'factor' => (float) $u->conversion_factor])->values(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function destinations(int $ownerId, int $excludeBranchId): array
    {
        return Branch::where('owner_id', $ownerId)
            ->active()
            ->where('branch_id', '!=', $excludeBranchId)
            ->orderBy('branch_code')
            ->get()
            ->map(fn (Branch $b) => ['id' => $b->branch_id, 'name' => "{$b->branch_name} ({$b->branch_code})"])
            ->all();
    }
}

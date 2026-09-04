<?php

namespace App\Http\Controllers\Pembelian;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\User;
use App\Services\PurchaseReturnPoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Retur pembelian (Addendum 48.2, 58.6).
 *
 * Mengacu pada PENERIMAAN, bukan faktur — sama seperti faktur pemasok,
 * seluruhnya menyangkut harga sehingga dibatasi MANAGER ke atas (Addendum 50).
 */
class PurchaseReturnController extends Controller
{
    private const PERAN_BERHAK = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    public function __construct(private PurchaseReturnPoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->authorizeAccess($user);

        $branch = ActiveBranch::for($user);

        $returns = PurchaseReturn::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id))
            ->with(['goodsReceipt.supplier', 'items.product', 'items.uom', 'author'])
            ->orderByDesc('return_id')
            ->paginate(self::PER_PAGE)
            ->through(fn (PurchaseReturn $r) => [
                'id' => $r->return_id,
                'number' => $r->return_number,
                'date' => $r->transaction_date->toDateString(),
                'receiptNumber' => $r->goodsReceipt?->receipt_number,
                'supplier' => $r->goodsReceipt?->supplier?->supplier_name,
                'status' => $r->status,
                'refundTotal' => (float) $r->refund_total,
                'author' => $r->author?->full_name,
                'items' => $r->items->map(fn ($i) => [
                    'name' => $i->product->product_name,
                    'sku' => $i->product->sku,
                    'qty' => (float) $i->qty,
                    'uom' => $i->uom->uom_name,
                    'costAtReturn' => (float) $i->cost_at_return,
                    'refundAmount' => (float) $i->refund_amount,
                ])->all(),
            ]);

        $cari = trim((string) $request->string('cari'));

        return Inertia::render('pembelian/retur/index', [
            'returns' => $returns,
            'receiptLookup' => $branch && $cari !== '' ? $this->cariPenerimaan($branch->branch_id, $cari) : null,
            'query' => $cari,
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
            'goods_receipt_id' => ['required', 'integer'],
            'transaction_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.receipt_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ], [], [
            'transaction_date' => 'tanggal retur',
            'lines' => 'barang yang diretur',
        ]);

        $return = $this->poster->post($user, $branch, $data);

        return back()->with('success', "Retur {$return->return_number} tersimpan dan stok sudah dikeluarkan.");
    }

    public function void(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeAccess($user);

        abort_unless($purchaseReturn->branch_id === ActiveBranch::for($user)?->branch_id, 404);

        $this->poster->void($user, $purchaseReturn);

        return back()->with('success', "Retur {$purchaseReturn->return_number} dibatalkan; stoknya masuk kembali.");
    }

    private function authorizeAccess(User $user): void
    {
        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat menangani retur pembelian.');
        }
    }

    /** @return array<string, mixed> */
    private function cariPenerimaan(int $branchId, string $nomor): array
    {
        $receipt = GoodsReceipt::where('branch_id', $branchId)
            ->where('status', GoodsReceipt::STATUS_POSTED)
            ->where('receipt_number', 'like', "%{$nomor}%")
            ->with(['items.product', 'items.uom', 'supplier'])
            ->orderByDesc('goods_receipt_id')
            ->first();

        if ($receipt === null) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'id' => $receipt->goods_receipt_id,
            'number' => $receipt->receipt_number,
            'date' => $receipt->receipt_date->toDateString(),
            'supplier' => $receipt->supplier?->supplier_name,
            'items' => $receipt->items->map(function ($i) {
                $factor = (float) $i->uom->conversion_factor;
                $sisaBase = round((float) $i->qty_base - PurchaseReturnItem::sudahDiretur($i->receipt_item_id), 4);

                return [
                    'receiptItemId' => $i->receipt_item_id,
                    'name' => $i->product->product_name,
                    'sku' => $i->product->sku,
                    'uom' => $i->uom->uom_name,
                    'qty' => (float) $i->qty,
                    'sisa' => $factor > 0 ? round($sisaBase / $factor, 4) : 0,
                ];
            })->values()->all(),
        ];
    }
}

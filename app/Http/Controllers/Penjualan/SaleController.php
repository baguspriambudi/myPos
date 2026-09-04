<?php

namespace App\Http\Controllers\Penjualan;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\SaleVoider;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Daftar nota penjualan berikut pembatalannya.
 *
 * Ini bukan laporan: di sinilah nota dibatalkan, dan pembatalan mengembalikan
 * stok serta membatalkan pembayarannya. Laporan penjualan yang benar-benar
 * hanya membaca akan tinggal di modul Laporan.
 */
class SaleController extends Controller
{
    /** Void hanya untuk MANAJER ke atas (Addendum 50). */
    private const PERAN_BOLEH_VOID = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    public function __construct(private SaleVoider $voider) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status', 'ALL');

        $query = Sale::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id))
            ->when($status !== 'ALL', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('sale_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$search}%"))));

        $sales = (clone $query)
            ->with(['customer', 'items.product', 'items.uom', 'payments', 'cashier'])
            ->orderByDesc('sale_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Sale $s) => $this->row($s));

        return Inertia::render('penjualan/index', [
            'sales' => $sales,
            'filters' => ['q' => $search, 'status' => $status],
            'summary' => $this->summary($branch?->branch_id),
            'canVoid' => in_array($user->role, self::PERAN_BOLEH_VOID, true),
        ]);
    }

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BOLEH_VOID, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat membatalkan nota.');
        }

        abort_unless($sale->branch->owner_id === $user->owner_id, 404);

        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ], [], ['void_reason' => 'alasan pembatalan']);

        $this->voider->void($user, $sale, $data['void_reason']);

        return back()->with('success', "Nota {$sale->sale_number} dibatalkan. Stoknya sudah dikembalikan.");
    }

    /** @return array<string, mixed> */
    private function row(Sale $sale): array
    {
        return [
            'id' => $sale->sale_id,
            'number' => $sale->sale_number,
            'date' => $sale->transaction_date->toDateString(),
            'customer' => $sale->customer?->customer_name,
            'cashier' => $sale->cashier?->full_name,
            'status' => $sale->status,
            'paymentType' => $sale->payments->first()?->payment_type,
            'netTotal' => (float) $sale->net_total,
            'rounding' => (float) $sale->rounding_amount,
            'paidTotal' => (float) $sale->paid_total,
            'cogs' => $sale->cogsTotal(),
            'voidReason' => $sale->void_reason,
            'items' => $sale->items->map(fn ($i) => [
                'name' => $i->product->product_name,
                'sku' => $i->product->sku,
                'qty' => (float) $i->qty,
                'uom' => $i->uom->uom_name,
                'unitPrice' => (float) $i->unit_price,
                'lineTotal' => (float) $i->line_total,
                'cogs' => (float) $i->cogs_total,
            ])->all(),
        ];
    }

    /**
     * Ringkasan hanya menghitung nota POSTED. Nota yang dibatalkan tetap
     * tampil di daftar tetapi tidak boleh ikut menaikkan omzet — itulah beda
     * void dengan hapus.
     *
     * @return array<string, float|int>
     */
    private function summary(?int $branchId): array
    {
        if ($branchId === null) {
            return ['count' => 0, 'net' => 0, 'cogs' => 0, 'rounding' => 0];
        }

        $posted = Sale::where('branch_id', $branchId)->posted();

        return [
            'count' => (clone $posted)->count(),
            'net' => (float) (clone $posted)->sum('net_total'),
            'rounding' => (float) (clone $posted)->sum('rounding_amount'),
            'cogs' => (float) SaleItem::whereIn('sale_id', (clone $posted)->select('sale_id'))->sum('cogs_total'),
        ];
    }
}

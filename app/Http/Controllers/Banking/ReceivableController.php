<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use App\Services\ReceivablePaymentPoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Piutang usaha kepada pelanggan (Addendum 41.3) beserta pelunasannya.
 *
 * Peran yang boleh mengakses sama dengan Utang Usaha: uang bukan urusan
 * kasir/gudang, walau merekalah yang menerbitkan nota kreditnya di kasir.
 */
class ReceivableController extends Controller
{
    private const PERAN_BERHAK = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    private const SARINGAN = ['OUTSTANDING', 'PAID', 'VOID', 'ALL'];

    public function __construct(private ReceivablePaymentPoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat melihat piutang usaha.');
        }

        $branch = ActiveBranch::for($user);
        $status = $request->string('status')->toString();
        $status = in_array($status, self::SARINGAN, true) ? $status : 'OUTSTANDING';

        $dasar = Receivable::query()->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id));

        $receivables = (clone $dasar)
            ->when($status === 'OUTSTANDING', fn ($q) => $q->outstanding())
            ->when($status !== 'OUTSTANDING' && $status !== 'ALL', fn ($q) => $q->where('status', $status))
            ->with(['customer', 'sale.items.product', 'sale.items.uom', 'sale.payments'])
            ->orderByRaw('due_date IS NULL, due_date')
            ->orderByDesc('receivable_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Receivable $r) => [
                'id' => $r->receivable_id,
                'saleNumber' => $r->sale?->sale_number,
                'date' => $r->sale?->transaction_date?->toDateString(),
                'customer' => $r->customer?->customer_name,
                'dueDate' => $r->due_date?->toDateString(),
                'overdue' => $r->due_date !== null
                    && $r->due_date->isPast()
                    && in_array($r->status, [Receivable::STATUS_OPEN, Receivable::STATUS_PARTIAL], true),
                'amount' => (float) $r->amount,
                'paidAmount' => (float) $r->paid_amount,
                'outstanding' => $r->outstanding(),
                'status' => $r->status,
                'items' => $r->sale?->items->map(fn ($i) => [
                    'name' => $i->product->product_name,
                    'qty' => (float) $i->qty,
                    'uom' => $i->uom->uom_name,
                    'unitPrice' => (float) $i->unit_price,
                    'lineTotal' => (float) $i->line_total,
                ])->all() ?? [],
                'payments' => $r->sale?->payments
                    ->sortByDesc('payment_id')
                    ->map(fn (Payment $pay) => [
                        'id' => $pay->payment_id,
                        'number' => $pay->payment_number,
                        'date' => $pay->transaction_date->toDateString(),
                        'amount' => (float) $pay->pivot->amount,
                        'paymentType' => $pay->payment_type,
                        'status' => $pay->status,
                    ])->values()->all() ?? [],
            ]);

        $terbuka = (clone $dasar)->outstanding();

        return Inertia::render('banking/piutang/index', [
            'receivables' => $receivables,
            'filters' => ['status' => $status],
            'summary' => [
                'count' => (clone $terbuka)->count(),
                'outstanding' => (float) (clone $terbuka)->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
                'overdue' => (float) (clone $terbuka)
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', now()->toDateString())
                    ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')
                    ->value('total'),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    public function terima(Request $request, Receivable $receivable): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat menerima pelunasan piutang.');
        }

        abort_unless($receivable->branch->owner_id === $user->owner_id, 404);

        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_type' => ['required', 'string'],
        ], [], [
            'payment_date' => 'tanggal bayar',
            'amount' => 'nilai',
            'payment_type' => 'metode bayar',
        ]);

        $payment = $this->poster->post($user, $branch, $receivable, $data);

        return back()->with('success', "Pembayaran {$payment->payment_number} tersimpan.");
    }

    public function batalTerima(Request $request, Payment $payment): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat membatalkan pembayaran.');
        }

        abort_unless($payment->branch->owner_id === $user->owner_id, 404);

        $this->poster->void($user, $payment);

        return back()->with('success', "Pembayaran {$payment->payment_number} dibatalkan.");
    }
}

<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\User;
use App\Services\PayablePaymentPoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Utang usaha kepada pemasok (Addendum 58.3) beserta pembayarannya.
 *
 * Peran yang boleh mengakses sama dengan yang boleh menangani faktur
 * pemasok: uang bukan urusan kasir/gudang.
 */
class PayableController extends Controller
{
    private const PERAN_BERHAK = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    private const SARINGAN = ['OUTSTANDING', 'PAID', 'VOID', 'ALL'];

    public function __construct(private PayablePaymentPoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat melihat utang usaha.');
        }

        $branch = ActiveBranch::for($user);
        $status = $request->string('status')->toString();
        $status = in_array($status, self::SARINGAN, true) ? $status : 'OUTSTANDING';

        $dasar = Payable::query()->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id));

        $payables = (clone $dasar)
            ->when($status === 'OUTSTANDING', fn ($q) => $q->outstanding())
            ->when($status !== 'OUTSTANDING' && $status !== 'ALL', fn ($q) => $q->where('status', $status))
            ->with(['supplier', 'purchase.items.product', 'purchase.items.uom', 'purchase.payments'])
            ->orderByRaw('due_date IS NULL, due_date')
            ->orderByDesc('payable_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Payable $p) => [
                'id' => $p->payable_id,
                'purchaseNumber' => $p->purchase?->purchase_number,
                'date' => $p->purchase?->transaction_date?->toDateString(),
                'supplier' => $p->supplier?->supplier_name,
                'dueDate' => $p->due_date?->toDateString(),
                'overdue' => $p->due_date !== null
                    && $p->due_date->isPast()
                    && in_array($p->status, [Payable::STATUS_OPEN, Payable::STATUS_PARTIAL], true),
                'amount' => (float) $p->amount,
                'paidAmount' => (float) $p->paid_amount,
                'outstanding' => $p->outstanding(),
                'status' => $p->status,
                'items' => $p->purchase?->items->map(fn ($i) => [
                    'name' => $i->product->product_name,
                    'qty' => (float) $i->qty,
                    'uom' => $i->uom->uom_name,
                    'unitCost' => (float) $i->unit_cost,
                    'lineTotal' => (float) $i->line_total,
                ])->all() ?? [],
                'payments' => $p->purchase?->payments
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

        return Inertia::render('banking/utang/index', [
            'payables' => $payables,
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

    public function bayar(Request $request, Payable $payable): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat membayar utang usaha.');
        }

        abort_unless($payable->branch->owner_id === $user->owner_id, 404);

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

        $payment = $this->poster->post($user, $branch, $payable, $data);

        return back()->with('success', "Pembayaran {$payment->payment_number} tersimpan.");
    }

    public function batalBayar(Request $request, Payment $payment): RedirectResponse
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

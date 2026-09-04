<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\ExpensePoster;
use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Biaya operasional (Addendum 60).
 *
 * Dibatasi OWNER/SUPER ADMIN/MANAGER sepenuhnya untuk saat ini — PRD
 * sebenarnya mengizinkan KASIR ikut mencatat draf ("Input biaya operasional"),
 * tapi alur draf→posting terpisah itu sengaja ditunda; siapa pun yang boleh
 * mencatat di sini langsung memposting.
 */
class ExpenseController extends Controller
{
    private const PERAN_BERHAK = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const PER_PAGE = 15;

    public function __construct(private ExpensePoster $poster) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat mengakses Kas & Biaya.');
        }

        $branch = ActiveBranch::for($user);

        $expenses = Expense::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id))
            ->with(['category', 'author'])
            ->orderByDesc('expense_id')
            ->paginate(self::PER_PAGE)
            ->through(fn (Expense $e) => [
                'id' => $e->expense_id,
                'number' => $e->expense_number,
                'date' => $e->transaction_date->toDateString(),
                'category' => $e->category->category_name,
                'description' => $e->description,
                'amount' => (float) $e->amount,
                'paymentType' => $e->payment_type,
                'status' => $e->status,
                'voidReason' => $e->void_reason,
                'author' => $e->author?->full_name,
            ]);

        $bulanIni = Expense::query()
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->branch_id))
            ->where('status', Expense::STATUS_POSTED)
            ->whereMonth('transaction_date', now()->month)
            ->whereYear('transaction_date', now()->year);

        return Inertia::render('biaya/index', [
            'expenses' => $expenses,
            'categories' => $this->categories($user->owner_id),
            'summary' => [
                'countBulanIni' => (clone $bulanIni)->count(),
                'totalBulanIni' => (float) (clone $bulanIni)->sum('amount'),
                'totalTunaiBulanIni' => (float) (clone $bulanIni)->where('payment_type', 'CASH')->sum('amount'),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat mencatat biaya operasional.');
        }

        $branch = ActiveBranch::for($user);

        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Anda belum terhubung ke lokasi mana pun.']);
        }

        $data = $request->validate([
            'expense_category_id' => ['required', 'integer'],
            'transaction_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_type' => ['required', 'string'],
        ], [], [
            'expense_category_id' => 'kategori biaya',
            'transaction_date' => 'tanggal',
            'description' => 'keterangan',
            'amount' => 'nilai',
            'payment_type' => 'metode bayar',
        ]);

        $expense = $this->poster->post($user, $branch, $data);

        return back()->with('success', "Biaya {$expense->expense_number} tersimpan.");
    }

    public function void(Request $request, Expense $expense): RedirectResponse
    {
        $user = $request->user();

        if (! in_array($user->role, self::PERAN_BERHAK, true)) {
            throw new HttpException(403, 'Hanya Pemilik, Super Admin, dan Manajer yang dapat membatalkan biaya.');
        }

        abort_unless($expense->branch->owner_id === $user->owner_id, 404);

        $data = $request->validate(['void_reason' => ['required', 'string']], [], ['void_reason' => 'alasan pembatalan']);

        $this->poster->void($user, $expense, $data['void_reason']);

        return back()->with('success', "Biaya {$expense->expense_number} dibatalkan.");
    }

    /** @return array<int, array<string, mixed>> */
    private function categories(int $ownerId): array
    {
        return ExpenseCategory::where('owner_id', $ownerId)
            ->where('active', true)
            ->orderBy('category_name')
            ->get()
            ->map(fn (ExpenseCategory $c) => ['id' => $c->expense_category_id, 'name' => $c->category_name])
            ->all();
    }
}

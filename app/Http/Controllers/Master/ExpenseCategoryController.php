<?php

namespace App\Http\Controllers\Master;

use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseCategoryController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $categories = $this->scopeOwner(ExpenseCategory::query(), $request)
            ->when($search !== '', fn ($q) => $q->where('category_name', 'like', "%{$search}%"))
            ->with('account')
            ->orderBy('category_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ExpenseCategory $c) => [
                'id' => $c->expense_category_id,
                'name' => $c->category_name,
                'accountId' => $c->account_id,
                'accountLabel' => $c->account ? $c->account->account_code.' — '.$c->account->account_name : null,
                'active' => $c->active,
            ]);

        return Inertia::render('master/kategori-biaya/index', [
            'categories' => $categories,
            'filters' => ['q' => $search],
            'accounts' => $this->scopeOwner(ChartOfAccount::query(), $request)
                ->where('account_type', 'EXPENSE')
                ->orderBy('account_code')
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->account_id,
                    'label' => $a->account_code.' — '.$a->account_name,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        ExpenseCategory::create([...$this->validated($request), 'owner_id' => $this->ownerId($request)]);

        return back()->with('success', 'Kategori biaya ditambahkan.');
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($expenseCategory->owner_id === $this->ownerId($request), 404);

        $expenseCategory->update($this->validated($request, $expenseCategory));

        return back()->with('success', 'Kategori biaya diperbarui.');
    }

    public function destroy(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($expenseCategory->owner_id === $this->ownerId($request), 404);

        try {
            $expenseCategory->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Kategori biaya'));
        }

        return back()->with('success', 'Kategori biaya dihapus.');
    }

    private function validated(Request $request, ?ExpenseCategory $category = null): array
    {
        $ownerId = $this->ownerId($request);

        return $request->validate([
            'category_name' => [
                'required', 'string', 'max:100',
                Rule::unique('expense_categories', 'category_name')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($category?->expense_category_id, 'expense_category_id'),
            ],
            'account_id' => [
                'nullable',
                Rule::exists('chart_of_accounts', 'account_id')->where(fn ($q) => $q->where('owner_id', $ownerId)),
            ],
            'active' => ['required', 'boolean'],
        ], [], ['category_name' => 'nama kategori', 'account_id' => 'akun']);
    }
}

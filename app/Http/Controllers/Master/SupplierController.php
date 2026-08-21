<?php

namespace App\Http\Controllers\Master;

use App\Models\Branch;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $suppliers = $this->scopeOwner(Supplier::query(), $request)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('supplier_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->with('branch')
            ->orderBy('supplier_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Supplier $s) => [
                'id' => $s->supplier_id,
                'name' => $s->supplier_name,
                'phone' => $s->phone,
                'branchId' => $s->branch_id,
                'branchName' => $s->branch?->branch_name,
                'active' => $s->active,
            ]);

        return Inertia::render('master/pemasok/index', [
            'suppliers' => $suppliers,
            'filters' => ['q' => $search],
            'branches' => $this->scopeOwner(Branch::query(), $request)->active()->orderBy('branch_code')->get()
                ->map(fn ($b) => ['id' => $b->branch_id, 'name' => $b->branch_name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        Supplier::create([...$this->validated($request), 'owner_id' => $this->ownerId($request)]);

        return back()->with('success', 'Pemasok ditambahkan.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($supplier->owner_id === $this->ownerId($request), 404);

        $supplier->update($this->validated($request));

        return back()->with('success', 'Pemasok diperbarui.');
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($supplier->owner_id === $this->ownerId($request), 404);

        try {
            $supplier->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Pemasok'));
        }

        return back()->with('success', 'Pemasok dihapus.');
    }

    private function validated(Request $request): array
    {
        $ownerId = $this->ownerId($request);

        return $request->validate([
            'supplier_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', Rule::exists('branches', 'branch_id')->where(fn ($q) => $q->where('owner_id', $ownerId))],
            'active' => ['required', 'boolean'],
        ], [], ['supplier_name' => 'nama pemasok']);
    }
}

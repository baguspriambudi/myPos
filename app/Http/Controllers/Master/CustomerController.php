<?php

namespace App\Http\Controllers\Master;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PriceList;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $customers = $this->scopeOwner(Customer::query(), $request)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->with(['branch', 'priceList'])
            ->orderBy('customer_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Customer $c) => [
                'id' => $c->customer_id,
                'name' => $c->customer_name,
                'phone' => $c->phone,
                'creditLimit' => (float) $c->credit_limit,
                'branchId' => $c->branch_id,
                'branchName' => $c->branch?->branch_name,
                'priceListId' => $c->price_list_id,
                'priceListName' => $c->priceList?->price_list_name,
                'active' => $c->active,
            ]);

        return Inertia::render('master/pelanggan/index', [
            'customers' => $customers,
            'filters' => ['q' => $search],
            'branches' => $this->scopeOwner(Branch::query(), $request)->active()->orderBy('branch_code')->get()
                ->map(fn ($b) => ['id' => $b->branch_id, 'name' => $b->branch_name]),
            'priceLists' => $this->scopeOwner(PriceList::query(), $request)->active()->orderBy('price_list_name')->get()
                ->map(fn ($p) => ['id' => $p->price_list_id, 'name' => $p->price_list_name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        Customer::create([...$this->validated($request), 'owner_id' => $this->ownerId($request)]);

        return back()->with('success', 'Pelanggan ditambahkan.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($customer->owner_id === $this->ownerId($request), 404);

        $customer->update($this->validated($request));

        return back()->with('success', 'Pelanggan diperbarui.');
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($customer->owner_id === $this->ownerId($request), 404);

        try {
            $customer->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Pelanggan'));
        }

        return back()->with('success', 'Pelanggan dihapus.');
    }

    private function validated(Request $request): array
    {
        $ownerId = $this->ownerId($request);

        return $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'branch_id' => ['nullable', Rule::exists('branches', 'branch_id')->where(fn ($q) => $q->where('owner_id', $ownerId))],
            'price_list_id' => ['nullable', Rule::exists('price_lists', 'price_list_id')->where(fn ($q) => $q->where('owner_id', $ownerId))],
            'active' => ['required', 'boolean'],
        ], [], [
            'customer_name' => 'nama pelanggan',
            'credit_limit' => 'batas piutang',
            'price_list_id' => 'daftar harga',
        ]);
    }
}

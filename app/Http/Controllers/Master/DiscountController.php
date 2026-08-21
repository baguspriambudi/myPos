<?php

namespace App\Http\Controllers\Master;

use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master diskon (Addendum 61).
 *
 * Diskon berbeda dari daftar harga: daftar harga menentukan harga dasar per
 * satuan, diskon memotongnya setelah itu. Keduanya sengaja tidak dicampur.
 */
class DiscountController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $discounts = $this->scopeOwner(Discount::query(), $request)
            ->when($search !== '', fn ($q) => $q->where('discount_name', 'like', "%{$search}%"))
            ->with(['product', 'category'])
            ->orderByDesc('active')
            ->orderBy('discount_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Discount $d) => [
                'id' => $d->discount_id,
                'name' => $d->discount_name,
                'scope' => $d->discount_scope,
                'type' => $d->discount_type,
                'value' => (float) $d->discount_value,
                'maxAmount' => $d->max_discount_amount !== null ? (float) $d->max_discount_amount : null,
                'productId' => $d->product_id,
                'productName' => $d->product?->product_name,
                'categoryId' => $d->category_id,
                'categoryName' => $d->category?->category_name,
                'minQty' => (float) $d->min_qty,
                'minAmount' => (float) $d->min_amount,
                'startDate' => $d->start_date?->toDateString(),
                'endDate' => $d->end_date?->toDateString(),
                'active' => $d->active,
            ]);

        return Inertia::render('master/diskon/index', [
            'discounts' => $discounts,
            'filters' => ['q' => $search],
            'products' => $this->scopeOwner(Product::query(), $request)->active()->orderBy('product_name')->get()
                ->map(fn ($p) => ['id' => $p->product_id, 'name' => $p->product_name]),
            'categories' => $this->scopeOwner(ProductCategory::query(), $request)->where('active', true)->orderBy('category_name')->get()
                ->map(fn ($c) => ['id' => $c->category_id, 'name' => $c->category_name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        Discount::create([...$this->validated($request), 'owner_id' => $this->ownerId($request)]);

        return back()->with('success', 'Diskon ditambahkan.');
    }

    public function update(Request $request, Discount $discount): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($discount->owner_id === $this->ownerId($request), 404);

        $discount->update($this->validated($request));

        return back()->with('success', 'Diskon diperbarui.');
    }

    public function destroy(Request $request, Discount $discount): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($discount->owner_id === $this->ownerId($request), 404);

        try {
            $discount->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Diskon'));
        }

        return back()->with('success', 'Diskon dihapus.');
    }

    private function validated(Request $request): array
    {
        $ownerId = $this->ownerId($request);

        return $request->validate([
            'discount_name' => ['required', 'string', 'max:150'],
            'discount_scope' => ['required', Rule::in(['ITEM', 'TRANSACTION'])],
            'discount_type' => ['required', Rule::in(['PERCENT', 'AMOUNT'])],
            'discount_value' => ['required', 'numeric', 'gt:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'product_id' => ['nullable', Rule::exists('products', 'product_id')->where(fn ($q) => $q->where('owner_id', $ownerId))],
            'category_id' => ['nullable', Rule::exists('product_categories', 'category_id')->where(fn ($q) => $q->where('owner_id', $ownerId))],
            'min_qty' => ['required', 'numeric', 'min:0'],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'active' => ['required', 'boolean'],
        ], [], [
            'discount_name' => 'nama diskon',
            'discount_scope' => 'cakupan',
            'discount_type' => 'tipe',
            'discount_value' => 'nilai',
            'start_date' => 'mulai berlaku',
            'end_date' => 'berlaku sampai',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Master;

use App\Models\ProductCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $categories = $this->scopeOwner(ProductCategory::query(), $request)
            ->when($search !== '', fn ($q) => $q->where('category_name', 'like', "%{$search}%"))
            ->withCount('products')
            ->orderBy('category_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ProductCategory $c) => [
                'id' => $c->category_id,
                'name' => $c->category_name,
                'active' => $c->active,
                'productsCount' => $c->products_count,
            ]);

        return Inertia::render('master/kategori/index', [
            'categories' => $categories,
            'filters' => ['q' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);

        ProductCategory::create([
            ...$this->validated($request),
            'owner_id' => $this->ownerId($request),
        ]);

        return back()->with('success', 'Kategori ditambahkan.');
    }

    public function update(Request $request, ProductCategory $category): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($category->owner_id === $this->ownerId($request), 404);

        $category->update($this->validated($request, $category));

        return back()->with('success', 'Kategori diperbarui.');
    }

    public function destroy(Request $request, ProductCategory $category): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($category->owner_id === $this->ownerId($request), 404);

        try {
            $category->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Kategori'));
        }

        return back()->with('success', 'Kategori dihapus.');
    }

    private function validated(Request $request, ?ProductCategory $category = null): array
    {
        $ownerId = $this->ownerId($request);

        return $request->validate([
            'category_name' => [
                'required', 'string', 'max:100',
                Rule::unique('product_categories', 'category_name')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($category?->category_id, 'category_id'),
            ],
            'active' => ['required', 'boolean'],
        ], [], ['category_name' => 'nama kategori']);
    }
}

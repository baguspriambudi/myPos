<?php

namespace App\Http\Controllers\Master;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUom;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master produk beserta satuannya.
 *
 * Satuan tidak dipisah ke layar sendiri karena sebuah produk tanpa satuan base
 * tidak dapat dipakai sama sekali — keduanya dibuat sekaligus.
 */
class ProductController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $categoryId = $request->query('category');

        $products = $this->scopeOwner(Product::query(), $request)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('product_name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->with(['category', 'uoms'])
            ->orderBy('product_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->product_id,
                'name' => $p->product_name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'categoryId' => $p->category_id,
                'categoryName' => $p->category?->category_name,
                'isWeighted' => $p->is_weighted,
                'active' => $p->active,
                'baseUomId' => $p->base_uom_id,
                'uoms' => $p->uoms->map(fn (ProductUom $u) => [
                    'id' => $u->uom_id,
                    'name' => $u->uom_name,
                    'type' => $u->uom_type,
                    'factor' => (float) $u->conversion_factor,
                    'price' => (float) $u->selling_price,
                    'active' => $u->active,
                ])->values(),
            ]);

        return Inertia::render('master/produk/index', [
            'products' => $products,
            'filters' => ['q' => $search, 'category' => $categoryId ? (int) $categoryId : null],
            'categories' => $this->scopeOwner(ProductCategory::query(), $request)
                ->where('active', true)
                ->orderBy('category_name')
                ->get()
                ->map(fn ($c) => ['id' => $c->category_id, 'name' => $c->category_name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request) {
            $product = Product::create([
                'owner_id' => $this->ownerId($request),
                'category_id' => $data['category_id'],
                'product_name' => $data['product_name'],
                'sku' => $data['sku'],
                'barcode' => $data['barcode'],
                'is_weighted' => $data['is_weighted'],
                'active' => $data['active'],
            ]);

            $this->syncUoms($product, $data['uoms']);
        });

        return back()->with('success', 'Produk ditambahkan.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($product->owner_id === $this->ownerId($request), 404);

        $data = $this->validated($request, $product);

        DB::transaction(function () use ($product, $data) {
            $product->update([
                'category_id' => $data['category_id'],
                'product_name' => $data['product_name'],
                'sku' => $data['sku'],
                'barcode' => $data['barcode'],
                'is_weighted' => $data['is_weighted'],
                'active' => $data['active'],
            ]);

            $this->syncUoms($product, $data['uoms']);
        });

        return back()->with('success', 'Produk diperbarui.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($product->owner_id === $this->ownerId($request), 404);

        try {
            DB::transaction(function () use ($product) {
                $product->update(['base_uom_id' => null]);
                $product->uoms()->delete();
                $product->delete();
            });
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Produk'));
        }

        return back()->with('success', 'Produk dihapus.');
    }

    /**
     * Menulis ulang daftar satuan.
     *
     * conversion_factor satuan yang sudah dipakai transaksi tidak boleh berubah
     * (Addendum 39.9) — karena itu satuan lama di-update, bukan dihapus lalu
     * dibuat ulang, dan yang hilang dari form hanya dinonaktifkan.
     */
    private function syncUoms(Product $product, array $uoms): void
    {
        $keptIds = [];
        $baseUomId = null;

        foreach ($uoms as $row) {
            $attributes = [
                'uom_name' => strtoupper($row['uom_name']),
                'uom_type' => $row['uom_type'],
                'conversion_factor' => $row['uom_type'] === ProductUom::TYPE_BASE ? 1 : $row['conversion_factor'],
                'selling_price' => $row['selling_price'],
                'active' => $row['active'] ?? true,
                'is_default' => $row['uom_type'] === ProductUom::TYPE_BASE,
            ];

            $uom = isset($row['id'])
                ? $product->uoms()->whereKey($row['id'])->first()
                : null;

            if ($uom !== null) {
                $uom->update($attributes);
            } else {
                $uom = $product->uoms()->create($attributes);
            }

            $keptIds[] = $uom->uom_id;

            if ($attributes['uom_type'] === ProductUom::TYPE_BASE) {
                $baseUomId = $uom->uom_id;
            }
        }

        // Satuan yang dibuang dari form hanya dinonaktifkan, tidak dihapus,
        // agar transaksi lama yang menunjuknya tetap utuh.
        $product->uoms()->whereNotIn('uom_id', $keptIds)->update(['active' => false]);

        $product->update(['base_uom_id' => $baseUomId]);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $ownerId = $this->ownerId($request);

        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:200'],
            'sku' => [
                'required', 'string', 'max:50',
                Rule::unique('products', 'sku')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($product?->product_id, 'product_id'),
            ],
            'barcode' => [
                'nullable', 'string', 'max:100',
                Rule::unique('products', 'barcode')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($product?->product_id, 'product_id'),
            ],
            'category_id' => [
                'nullable',
                Rule::exists('product_categories', 'category_id')->where(fn ($q) => $q->where('owner_id', $ownerId)),
            ],
            'is_weighted' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'uoms' => ['required', 'array', 'min:1'],
            'uoms.*.id' => ['nullable', 'integer'],
            'uoms.*.uom_name' => ['required', 'string', 'max:50'],
            'uoms.*.uom_type' => ['required', Rule::in([ProductUom::TYPE_BASE, ProductUom::TYPE_PACK])],
            'uoms.*.conversion_factor' => ['required', 'numeric', 'gt:0'],
            'uoms.*.selling_price' => ['required', 'numeric', 'min:0'],
            'uoms.*.active' => ['nullable', 'boolean'],
        ], [], [
            'product_name' => 'nama produk',
            'sku' => 'SKU',
            'category_id' => 'kategori',
        ]);

        $baseCount = collect($data['uoms'])->where('uom_type', ProductUom::TYPE_BASE)->count();

        if ($baseCount !== 1) {
            throw ValidationException::withMessages([
                'uoms' => 'Produk wajib punya tepat satu satuan dasar (BASE). Satuan dasar itulah yang dipakai menyimpan stok.',
            ]);
        }

        $names = collect($data['uoms'])->map(fn ($u) => strtoupper($u['uom_name']));

        if ($names->unique()->count() !== $names->count()) {
            throw ValidationException::withMessages([
                'uoms' => 'Nama satuan tidak boleh sama.',
            ]);
        }

        return $data;
    }
}

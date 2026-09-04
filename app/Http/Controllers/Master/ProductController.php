<?php

namespace App\Http\Controllers\Master;

use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUom;
use App\Models\Unit;
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
 * Konversi satuan tetap dibuat di sini karena produk tanpa satuan BASE tidak
 * dapat dipakai sama sekali. Yang pindah ke layar sendiri: NAMA satuan (master
 * units, tinggal dipilih) dan HARGA JUAL (master daftar harga).
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
                'productType' => $p->product_type,
                'canSell' => $p->canSell(),
                'tracksStock' => $p->tracksStock(),
                'isWeighted' => $p->is_weighted,
                'active' => $p->active,
                'baseUomId' => $p->base_uom_id,
                'uoms' => $p->uoms->map(fn (ProductUom $u) => [
                    'id' => $u->uom_id,
                    'unitId' => $u->unit_id,
                    'name' => $u->uom_name,
                    'type' => $u->uom_type,
                    'factor' => (float) $u->conversion_factor,
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
            // Satuan nonaktif ikut dikirim agar produk lama yang memakainya
            // tetap terbaca di form; dropdown hanya menawarkan yang aktif.
            'units' => $this->scopeOwner(Unit::query(), $request)
                ->orderBy('unit_name')
                ->get()
                ->map(fn (Unit $u) => ['id' => $u->unit_id, 'name' => $u->unit_name, 'active' => $u->active]),
            'productTypes' => collect(Product::typeLabels())
                ->map(fn (string $label, string $value) => [
                    'value' => $value,
                    'label' => $label,
                    'canSell' => in_array($value, Product::SELLABLE_TYPES, true),
                    'tracksStock' => $value !== Product::TYPE_SERVICE,
                ])
                ->values(),
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
                'product_type' => $data['product_type'],
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
                'product_type' => $data['product_type'],
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

        // Nama satuan tidak pernah datang dari form — selalu disalin dari master
        // agar tidak ada "DUS" versi ketikan sendiri.
        $units = Unit::whereIn('unit_id', collect($uoms)->pluck('unit_id'))->get()->keyBy('unit_id');

        foreach ($uoms as $row) {
            $attributes = [
                'unit_id' => $row['unit_id'],
                'uom_name' => $units[$row['unit_id']]->unit_name,
                'uom_type' => $row['uom_type'],
                'conversion_factor' => $row['uom_type'] === ProductUom::TYPE_BASE ? 1 : $row['conversion_factor'],
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

    /**
     * Jasa dijual dalam satu satuan saja.
     *
     * Konversi satuan menerjemahkan kemasan menjadi stok — 1 DUS menambah 12
     * PCS. Jasa tidak punya stok, jadi angka itu tidak menerjemahkan apa pun
     * dan hanya menjadi setelan yang diam-diam tidak berefek.
     */
    private function assertTypeAllowsUoms(array $data): void
    {
        if ($data['product_type'] !== Product::TYPE_SERVICE) {
            return;
        }

        $adaKemasan = collect($data['uoms'])->contains(fn ($row) => $row['uom_type'] === ProductUom::TYPE_PACK);

        if ($adaKemasan) {
            throw ValidationException::withMessages([
                'uoms' => 'Jasa tidak punya stok, jadi tidak punya kemasan — cukup satu satuan saja.',
            ]);
        }
    }

    /**
     * Produk yang sudah punya harga jual tidak boleh berpindah ke jenis yang
     * tidak dapat dijual: baris harganya akan menggantung, dan kasir tetap
     * menemukan harga untuk barang yang seharusnya sudah tidak dijual.
     */
    private function assertTypeChangeAllowed(string $newType, ?Product $product): void
    {
        if ($product === null || in_array($newType, Product::SELLABLE_TYPES, true)) {
            return;
        }

        $punyaHarga = PriceListItem::where('product_id', $product->product_id)->exists();

        if ($punyaHarga) {
            $label = Product::typeLabels()[$newType];

            throw ValidationException::withMessages([
                'product_type' => "Produk ini masih punya harga jual, jadi tidak dapat diubah menjadi {$label}. Hapus dulu barisnya di Master → Daftar Harga.",
            ]);
        }
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
            'product_type' => ['required', Rule::in(Product::TYPES)],
            'is_weighted' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'uoms' => ['required', 'array', 'min:1'],
            'uoms.*.id' => ['nullable', 'integer'],
            'uoms.*.unit_id' => [
                'required', 'integer',
                Rule::exists('units', 'unit_id')->where(fn ($q) => $q->where('owner_id', $ownerId)),
            ],
            'uoms.*.uom_type' => ['required', Rule::in([ProductUom::TYPE_BASE, ProductUom::TYPE_PACK])],
            'uoms.*.conversion_factor' => ['required', 'numeric', 'gt:0'],
            'uoms.*.active' => ['nullable', 'boolean'],
        ], [
            'uoms.*.unit_id.required' => 'Satuan wajib dipilih dari master satuan.',
            'uoms.*.unit_id.exists' => 'Satuan tidak dikenal pada usaha ini.',
        ], [
            'product_name' => 'nama produk',
            'product_type' => 'jenis produk',
            'sku' => 'SKU',
            'category_id' => 'kategori',
        ]);

        $this->assertTypeAllowsUoms($data);
        $this->assertTypeChangeAllowed($data['product_type'], $product);

        $baseCount = collect($data['uoms'])->where('uom_type', ProductUom::TYPE_BASE)->count();

        if ($baseCount !== 1) {
            throw ValidationException::withMessages([
                'uoms' => 'Produk wajib punya tepat satu satuan dasar — itulah satuan terkecil tempat stok disimpan.',
            ]);
        }

        $unitIds = collect($data['uoms'])->pluck('unit_id');

        if ($unitIds->unique()->count() !== $unitIds->count()) {
            throw ValidationException::withMessages([
                'uoms' => 'Satuan yang sama tidak boleh dipakai dua kali pada satu produk.',
            ]);
        }

        return $data;
    }
}

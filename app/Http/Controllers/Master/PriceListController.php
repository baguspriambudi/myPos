<?php

namespace App\Http\Controllers\Master;

use App\Models\Branch;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master daftar harga jual (Addendum 39.10).
 *
 * Satu daftar harga berisi banyak baris "produk + satuan + kuantitas minimum →
 * harga". Itulah yang membuat "1 dus 20.000, 1 pcs 2.500, ≥6 pcs 2.300" bisa
 * hidup berdampingan pada produk yang sama.
 *
 * Ini satu-satunya tempat harga jual diatur; master produk tidak lagi menyimpan
 * harga. Produk yang belum punya baris di sini tidak dapat dijual.
 */
class PriceListController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $priceLists = $this->scopeOwner(PriceList::query(), $request)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('price_list_name', 'like', "%{$search}%")
                ->orWhere('price_list_code', 'like', "%{$search}%")))
            ->with('branch')
            ->withCount('items')
            ->orderBy('price_list_code')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (PriceList $p) => [
                'id' => $p->price_list_id,
                'code' => $p->price_list_code,
                'name' => $p->price_list_name,
                'branchId' => $p->branch_id,
                'branchName' => $p->branch?->branch_name,
                'isDefault' => $p->is_default,
                'memberOnly' => $p->member_only,
                'startDate' => $p->start_date?->toDateString(),
                'endDate' => $p->end_date?->toDateString(),
                'active' => $p->active,
                'itemsCount' => $p->items_count,
            ]);

        return Inertia::render('master/harga/index', [
            'priceLists' => $priceLists,
            'filters' => ['q' => $search],
            'branches' => $this->branchOptions($request),
        ]);
    }

    /** Layar tingkat harga untuk satu daftar harga. */
    public function show(Request $request, PriceList $priceList): Response
    {
        abort_unless($priceList->owner_id === $this->ownerId($request), 404);

        $search = trim((string) $request->query('q', ''));
        $today = now()->toDateString();

        // Bawaannya hanya harga yang berlaku hari ini. Baris lama tidak pernah
        // dihapus — tanpa saringan ini layar akan penuh riwayat dalam setahun.
        $riwayat = $request->boolean('riwayat');

        $items = PriceListItem::query()
            ->where('price_list_id', $priceList->price_list_id)
            ->with(['product', 'uom'])
            ->unless($riwayat, fn ($q) => $q->effectiveOn($today))
            ->when($search !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p
                ->where('product_name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->get()
            ->sortBy([
                fn ($a, $b) => strcmp($a->product->product_name, $b->product->product_name),
                fn ($a, $b) => strcmp($a->uom->uom_name, $b->uom->uom_name),
                fn ($a, $b) => $a->min_qty <=> $b->min_qty,
                // Versi terbaru di atas versi lamanya.
                fn ($a, $b) => $b->start_date <=> $a->start_date,
            ])
            ->values()
            ->map(fn (PriceListItem $i) => [
                'id' => $i->price_list_item_id,
                'productId' => $i->product_id,
                'productName' => $i->product->product_name,
                'sku' => $i->product->sku,
                'uomId' => $i->uom_id,
                'uomName' => $i->uom->uom_name,
                'uomFactor' => (float) $i->uom->conversion_factor,
                'minQty' => (float) $i->min_qty,
                'unitPrice' => (float) $i->unit_price,
                'startDate' => $i->start_date->toDateString(),
                'endDate' => $i->end_date->toDateString(),
                'berlakuHariIni' => $i->start_date->toDateString() <= $today && $i->end_date->toDateString() >= $today,
                // Sudah mulai berlaku = sudah jadi riwayat. Mengubah nilainya
                // akan membuat penjualan ber-backdate memakai angka yang tidak
                // pernah tercetak di struk.
                'terkunci' => $i->terkunci($today),
            ]);

        // Produk beserta satuannya, untuk memilih baris baru. Aset dan bahan
        // produksi tidak ditawarkan sama sekali — keduanya memang tidak dijual
        // (Addendum 39.14).
        $products = $this->scopeOwner(Product::query(), $request)
            ->active()
            ->sellable()
            ->with('uoms')
            ->orderBy('product_name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->product_id,
                'name' => $p->product_name,
                'sku' => $p->sku,
                'uoms' => $p->uoms->where('active', true)->map(fn ($u) => [
                    'id' => $u->uom_id,
                    'name' => $u->uom_name,
                    'factor' => (float) $u->conversion_factor,
                ])->values(),
            ]);

        return Inertia::render('master/harga/show', [
            'priceList' => [
                'id' => $priceList->price_list_id,
                'code' => $priceList->price_list_code,
                'name' => $priceList->price_list_name,
                'branchName' => $priceList->branch?->branch_name,
                'isDefault' => $priceList->is_default,
                'memberOnly' => $priceList->member_only,
                'active' => $priceList->active,
                'startDate' => $priceList->start_date?->toDateString(),
                'endDate' => $priceList->end_date?->toDateString(),
            ],
            'items' => $items,
            'products' => $products,
            'filters' => ['q' => $search, 'riwayat' => $riwayat],
            'today' => $today,
            'tanpaAkhir' => PriceListItem::TANPA_AKHIR,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request) {
            $priceList = PriceList::create([...$data, 'owner_id' => $this->ownerId($request)]);
            $this->enforceSingleDefault($priceList);
        });

        return back()->with('success', 'Daftar harga ditambahkan.');
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($priceList->owner_id === $this->ownerId($request), 404);

        $data = $this->validated($request, $priceList);

        DB::transaction(function () use ($priceList, $data) {
            $priceList->update($data);
            $this->enforceSingleDefault($priceList);
        });

        return back()->with('success', 'Daftar harga diperbarui.');
    }

    public function destroy(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($priceList->owner_id === $this->ownerId($request), 404);

        try {
            $priceList->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Daftar harga'));
        }

        return back()->with('success', 'Daftar harga dihapus.');
    }

    // ------------------------------------------------------------- tingkatan

    public function storeItem(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($priceList->owner_id === $this->ownerId($request), 404);

        $data = $this->validatedItem($request, $priceList);

        PriceListItem::create([...$data, 'price_list_id' => $priceList->price_list_id]);

        return back()->with('success', 'Tingkat harga ditambahkan.');
    }

    public function updateItem(Request $request, PriceList $priceList, PriceListItem $item): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($priceList->owner_id === $this->ownerId($request), 404);
        abort_unless($item->price_list_id === $priceList->price_list_id, 404);

        if ($item->terkunci()) {
            return back()->with('error', $this->pesanTerkunci());
        }

        $item->update($this->validatedItem($request, $priceList, $item));

        return back()->with('success', 'Tingkat harga diperbarui.');
    }

    public function destroyItem(Request $request, PriceList $priceList, PriceListItem $item): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($priceList->owner_id === $this->ownerId($request), 404);
        abort_unless($item->price_list_id === $priceList->price_list_id, 404);

        if ($item->terkunci()) {
            return back()->with('error', $this->pesanTerkunci());
        }

        $item->delete();

        return back()->with('success', 'Tingkat harga dihapus.');
    }

    /**
     * Baris yang sudah mulai berlaku adalah riwayat, bukan setelan.
     *
     * Kalau nilainya boleh ditimpa, penjualan yang di-backdate akan memakai
     * angka yang tidak pernah tercetak di struk mana pun — dan justru itu yang
     * hendak dicegah oleh masa berlaku (Addendum 39.15).
     */
    private function pesanTerkunci(): string
    {
        return 'Harga yang sudah mulai berlaku tidak dapat diubah atau dihapus — itu riwayat. Pakai Ganti harga untuk membuat harga baru mulai hari ini.';
    }

    // ---------------------------------------------------------------- privat

    private function validated(Request $request, ?PriceList $priceList = null): array
    {
        $ownerId = $this->ownerId($request);

        $data = $request->validate([
            'price_list_code' => [
                'required', 'string', 'max:20',
                Rule::unique('price_lists', 'price_list_code')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($priceList?->price_list_id, 'price_list_id'),
            ],
            'price_list_name' => ['required', 'string', 'max:150'],
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'branch_id')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId)->where('can_sell', true)),
            ],
            'is_default' => ['required', 'boolean'],
            'member_only' => ['required', 'boolean'],
            // Prioritas sudah tidak ada di layar: yang memutus pemenang antar
            // daftar sederajat kini start_date baris harganya (Addendum 39.15).
            // Kolomnya belum di-drop supaya data lama tetap terbaca.
            'priority' => ['nullable', 'integer', 'min:0', 'max:999'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'active' => ['required', 'boolean'],
        ], [], [
            'price_list_code' => 'kode daftar harga',
            'price_list_name' => 'nama daftar harga',
            'branch_id' => 'lokasi penjualan',
            'priority' => 'prioritas',
            'start_date' => 'mulai berlaku',
            'end_date' => 'berlaku sampai',
        ]);

        // Harga normal adalah jaring terakhir untuk SEMUA pembeli, sedangkan
        // daftar khusus member sengaja dilewati pembeli tunai. Satu daftar yang
        // memikul kedua tanda membuat pembeli tunai kehilangan harga sama
        // sekali — produknya jadi tidak bisa dijual tanpa ada yang salah tampak.
        if ($data['is_default'] && $data['member_only']) {
            throw ValidationException::withMessages([
                'member_only' => 'Daftar harga khusus member tidak boleh sekaligus menjadi harga normal — harga normal dipakai semua pembeli, termasuk yang bukan member.',
            ]);
        }

        $data['price_list_code'] = strtoupper($data['price_list_code']);
        $data['priority'] = $data['priority'] ?? $priceList?->priority ?? 0;

        return $data;
    }

    private function validatedItem(Request $request, PriceList $priceList, ?PriceListItem $item = null): array
    {
        $ownerId = $this->ownerId($request);

        $data = $request->validate([
            'product_id' => [
                'required',
                // Jenis produk ikut disaring di sini, bukan hanya di dropdown:
                // aset dan bahan produksi tidak boleh punya harga jual walau
                // permintaannya dikirim langsung.
                Rule::exists('products', 'product_id')->where(fn ($q) => $q
                    ->where('owner_id', $ownerId)
                    ->whereIn('product_type', Product::SELLABLE_TYPES)),
            ],
            'uom_id' => ['required', 'integer'],
            'min_qty' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            // Harga tidak boleh dibuat berlaku mundur: kalau boleh, transaksi
            // yang sudah tercetak mendadak punya harga lain saat ditelusuri.
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'product_id.exists' => 'Produk tidak dikenal atau memang tidak dijual — aset dan bahan produksi tidak dapat diberi harga jual.',
            'start_date.after_or_equal' => 'Harga tidak dapat dibuat berlaku mundur. Tanggal mulai paling awal adalah hari ini.',
        ], [
            'product_id' => 'produk',
            'uom_id' => 'satuan',
            'min_qty' => 'kuantitas minimum',
            'unit_price' => 'harga',
            'start_date' => 'mulai berlaku',
            'end_date' => 'berlaku sampai',
        ]);

        // Satuan wajib milik produk yang dipilih, kalau tidak harga akan
        // menempel pada satuan produk lain tanpa ketahuan.
        $uomBelongs = DB::table('product_uoms')
            ->where('uom_id', $data['uom_id'])
            ->where('product_id', $data['product_id'])
            ->exists();

        if (! $uomBelongs) {
            throw ValidationException::withMessages([
                'uom_id' => 'Satuan tersebut bukan milik produk yang dipilih.',
            ]);
        }

        // Duplikat sekarang ikut memandang tanggal mulai: dua versi harga untuk
        // tingkat yang sama memang boleh — itulah riwayatnya. Yang tidak boleh
        // adalah dua versi yang mulai pada hari yang sama, karena tidak ada
        // yang bisa menjelaskan mana yang dimaksud.
        $duplicate = PriceListItem::where('price_list_id', $priceList->price_list_id)
            ->where('product_id', $data['product_id'])
            ->where('uom_id', $data['uom_id'])
            ->where('min_qty', $data['min_qty'])
            ->whereDate('start_date', $data['start_date'])
            ->when($item, fn ($q) => $q->whereKeyNot($item->price_list_item_id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'min_qty' => 'Sudah ada harga untuk produk, satuan, dan kuantitas minimum itu yang mulai berlaku pada tanggal yang sama. Ubah harga tersebut, atau pilih tanggal mulai lain.',
            ]);
        }

        return $data;
    }

    /** Maksimal satu daftar harga bawaan per owner (Addendum 39.12). */
    private function enforceSingleDefault(PriceList $priceList): void
    {
        if (! $priceList->is_default) {
            return;
        }

        PriceList::where('owner_id', $priceList->owner_id)
            ->whereKeyNot($priceList->price_list_id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Hanya lokasi yang boleh menjual. Gudang tidak melayani penjualan, jadi
     * daftar harga khusus gudang tidak akan pernah terpakai — lebih baik tidak
     * ditawarkan sekalian daripada jadi setelan yang diam-diam tidak berefek.
     */
    private function branchOptions(Request $request)
    {
        return $this->scopeOwner(Branch::query(), $request)
            ->active()
            ->selling()
            ->orderBy('branch_code')
            ->get()
            ->map(fn (Branch $b) => [
                'id' => $b->branch_id,
                'name' => $b->branch_name,
                'code' => $b->branch_code,
            ]);
    }
}

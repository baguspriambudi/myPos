<?php

namespace App\Http\Controllers\Persediaan;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kartu stok: riwayat pergerakan satu produk di satu lokasi (PRD 15).
 *
 * Dua tampilan dalam satu layar, dan bedanya penting:
 *
 *   - Tanpa produk terpilih: seluruh pergerakan lokasi ini, terbaru dulu.
 *     Berguna untuk "apa saja yang terjadi hari ini", tetapi TIDAK punya kolom
 *     saldo — saldo berjalan lintas produk tidak berarti apa-apa.
 *   - Dengan produk terpilih: kartu stok yang sebenarnya, urut maju dengan
 *     saldo berjalan, persis urutan yang dipakai perhitungan ulang HPP.
 *
 * Terbuka untuk semua peran di lokasinya sendiri (Addendum 50); kolom harga
 * disembunyikan dari KASIR dan GUDANG.
 */
class StockCardController extends Controller
{
    private const PERAN_LIHAT_HARGA = [User::ROLE_OWNER, User::ROLE_SUPER_ADMIN, User::ROLE_MANAGER];

    private const BATAS = 300;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branch = ActiveBranch::for($user);
        $bolehLihatHarga = in_array($user->role, self::PERAN_LIHAT_HARGA, true);

        $product = $this->produkTerpilih($request, $user);

        return Inertia::render('stok/index', [
            'products' => $this->daftarProduk($user->owner_id),
            'productId' => $product?->product_id,
            'movements' => $branch === null ? [] : $this->pergerakan($branch, $product, $bolehLihatHarga),
            'summary' => $branch === null ? null : $this->ringkasan($branch, $product, $bolehLihatHarga),
            'canSeeCost' => $bolehLihatHarga,
            'branch' => $branch === null ? null : ['id' => $branch->branch_id, 'name' => $branch->branch_name],
        ]);
    }

    private function produkTerpilih(Request $request, User $user): ?Product
    {
        $id = $request->integer('produk');

        if ($id === 0) {
            return null;
        }

        return Product::where('owner_id', $user->owner_id)->with('uoms')->find($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pergerakan(Branch $branch, ?Product $product, bool $bolehLihatHarga): array
    {
        $query = StockMovement::where('branch_id', $branch->branch_id)
            ->when($product, fn ($q) => $q->where('product_id', $product->product_id))
            ->with(['product'])
            ->limit(self::BATAS);

        // Kartu stok satu produk urut MAJU supaya saldo berjalannya masuk akal;
        // daftar campuran urut mundur supaya yang terbaru terlihat lebih dulu.
        $rows = $product !== null
            ? $query->orderBy('movement_date')->orderBy('sequence')->get()
            : $query->orderByDesc('movement_date')->orderByDesc('movement_id')->get();

        $saldo = 0.0;

        return $rows->map(function (StockMovement $m) use ($product, $bolehLihatHarga, &$saldo) {
            if ($product !== null) {
                $saldo = round($saldo + (float) $m->qty_base, 4);
            }

            return [
                'id' => $m->movement_id,
                'date' => $m->movement_date->toDateString(),
                'type' => $m->movement_type,
                'reference' => $m->reference_type,
                'referenceId' => $m->reference_id,
                'product' => $m->product->product_name,
                'sku' => $m->product->sku,
                'qtyBase' => round((float) $m->qty_base, 4),
                'unitCost' => $bolehLihatHarga ? round((float) $m->unit_cost, 4) : null,
                'totalCost' => $bolehLihatHarga ? round((float) $m->total_cost, 2) : null,
                'balance' => $product === null ? null : $saldo,
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function ringkasan(Branch $branch, ?Product $product, bool $bolehLihatHarga): array
    {
        $row = DB::table('stock_balances')
            ->where('branch_id', $branch->branch_id)
            ->when($product, fn ($q) => $q->where('product_id', $product->product_id))
            ->selectRaw('COUNT(*) jumlah, COALESCE(SUM(qty_on_hand),0) qty, COALESCE(SUM(qty_on_hand * avg_cost),0) nilai, SUM(qty_on_hand <= 0) habis')
            ->first();

        return [
            'productCount' => (int) $row->jumlah,
            'totalQty' => round((float) $row->qty, 4),
            'emptyCount' => (int) $row->habis,
            'totalValue' => $bolehLihatHarga ? round((float) $row->nilai, 2) : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function daftarProduk(int $ownerId): array
    {
        return Product::where('owner_id', $ownerId)
            ->stocked()
            ->orderBy('product_name')
            ->get(['product_id', 'product_name', 'sku'])
            ->map(fn (Product $p) => ['id' => $p->product_id, 'name' => $p->product_name, 'sku' => $p->sku])
            ->all();
    }
}

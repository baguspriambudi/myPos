<?php

namespace App\Http\Controllers\Master;

use App\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master lokasi: toko, depo, dan gudang (Addendum 59).
 */
class BranchController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $branches = $this->scopeOwner(Branch::query(), $request)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('branch_name', 'like', "%{$search}%")
                ->orWhere('branch_code', 'like', "%{$search}%")))
            ->withCount('users')
            ->orderBy('branch_code')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Branch $b) => [
                'id' => $b->branch_id,
                'code' => $b->branch_code,
                'name' => $b->branch_name,
                'type' => $b->branch_type,
                'typeLabel' => $b->typeLabel(),
                'canSell' => $b->can_sell,
                'address' => $b->address,
                'active' => $b->active,
                'usersCount' => $b->users_count,
            ]);

        return Inertia::render('master/lokasi/index', [
            'branches' => $branches,
            'filters' => ['q' => $search],
            'types' => [
                ['value' => Branch::TYPE_STORE, 'label' => 'Toko', 'hint' => 'Melayani pembeli dan menyimpan stok'],
                ['value' => Branch::TYPE_DEPO, 'label' => 'Depo', 'hint' => 'Toko yang sekaligus titik distribusi'],
                ['value' => Branch::TYPE_WAREHOUSE, 'label' => 'Gudang', 'hint' => 'Hanya menyimpan dan memindahkan barang'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        $data = $this->validated($request);

        Branch::create([...$data, 'owner_id' => $this->ownerId($request)]);

        return back()->with('success', 'Lokasi ditambahkan.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeWrite($request);
        $this->assertSameOwner($request, $branch);

        $data = $this->validated($request, $branch);

        // Mematikan penjualan saat masih ada shift terbuka akan meninggalkan
        // laci kasir yang tidak bisa ditutup (Addendum 59.2).
        if ($branch->can_sell && ! $data['can_sell'] && $this->hasOpenShift($branch)) {
            throw ValidationException::withMessages([
                'can_sell' => 'Masih ada shift kasir yang terbuka di lokasi ini. Tutup shift tersebut lebih dulu.',
            ]);
        }

        $branch->update($data);

        return back()->with('success', 'Lokasi diperbarui.');
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeWrite($request);
        $this->assertSameOwner($request, $branch);

        try {
            $branch->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Lokasi'));
        }

        return back()->with('success', 'Lokasi dihapus.');
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        $ownerId = $this->ownerId($request);

        $data = $request->validate([
            'branch_code' => [
                'required', 'string', 'max:10',
                Rule::unique('branches', 'branch_code')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($branch?->branch_id, 'branch_id'),
            ],
            'branch_name' => ['required', 'string', 'max:150'],
            'branch_type' => ['required', Rule::in(Branch::TYPES)],
            'can_sell' => ['required', 'boolean'],
            'address' => ['nullable', 'string'],
            'active' => ['required', 'boolean'],
        ], [], [
            'branch_code' => 'kode lokasi',
            'branch_name' => 'nama lokasi',
            'branch_type' => 'tipe lokasi',
            'can_sell' => 'izin menjual',
        ]);

        $data['branch_code'] = strtoupper($data['branch_code']);

        return $data;
    }

    private function assertSameOwner(Request $request, Branch $branch): void
    {
        abort_unless($branch->owner_id === $this->ownerId($request), 404);
    }

    private function hasOpenShift(Branch $branch): bool
    {
        return DB::table('cashier_shifts')
            ->where('branch_id', $branch->branch_id)
            ->where('status', 'OPEN')
            ->exists();
    }
}

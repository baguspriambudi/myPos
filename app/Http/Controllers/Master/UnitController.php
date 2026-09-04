<?php

namespace App\Http\Controllers\Master;

use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master satuan.
 *
 * Nama satuan yang sudah dipakai produk tidak dapat diubah lagi: transaksi lama
 * menyebut satuan itu dengan namanya, dan mengganti "DUS" menjadi "KARTON" akan
 * mengubah arti struk yang sudah dicetak. Untuk berhenti memakainya, nonaktifkan
 * — satuan nonaktif tidak muncul lagi saat membuat produk baru.
 */
class UnitController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $units = $this->scopeOwner(Unit::query(), $request)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('unit_name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->withCount('productUoms')
            ->orderBy('unit_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Unit $u) => [
                'id' => $u->unit_id,
                'name' => $u->unit_name,
                'description' => $u->description,
                'active' => $u->active,
                'assignedCount' => $u->product_uoms_count,
            ]);

        return Inertia::render('master/satuan/index', [
            'units' => $units,
            'filters' => ['q' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);

        Unit::create([
            ...$this->validated($request),
            'owner_id' => $this->ownerId($request),
        ]);

        return back()->with('success', 'Satuan ditambahkan.');
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($unit->owner_id === $this->ownerId($request), 404);

        $data = $this->validated($request, $unit);

        if ($data['unit_name'] !== $unit->unit_name && $unit->isAssigned()) {
            throw ValidationException::withMessages([
                'unit_name' => 'Satuan ini sudah dipakai produk sehingga namanya tidak dapat diubah. Buat satuan baru bila memang berbeda.',
            ]);
        }

        $unit->update($data);

        return back()->with('success', 'Satuan diperbarui.');
    }

    public function destroy(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($unit->owner_id === $this->ownerId($request), 404);

        if ($unit->isAssigned()) {
            return back()->with('error', $this->inUseMessage('Satuan'));
        }

        try {
            $unit->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Satuan'));
        }

        return back()->with('success', 'Satuan dihapus.');
    }

    private function validated(Request $request, ?Unit $unit = null): array
    {
        $ownerId = $this->ownerId($request);

        $request->merge(['unit_name' => strtoupper(trim((string) $request->input('unit_name')))]);

        $data = $request->validate([
            'unit_name' => [
                'required', 'string', 'max:50',
                Rule::unique('units', 'unit_name')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($unit?->unit_id, 'unit_id'),
            ],
            'description' => ['nullable', 'string', 'max:150'],
            'active' => ['required', 'boolean'],
        ], [], [
            'unit_name' => 'nama satuan',
            'description' => 'keterangan',
        ]);

        $data['description'] = $data['description'] ?: null;

        return $data;
    }
}

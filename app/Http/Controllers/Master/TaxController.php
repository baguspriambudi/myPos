<?php

namespace App\Http\Controllers\Master;

use App\Models\Tax;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $taxes = $this->scopeOwner(Tax::query(), $request)
            ->when($search !== '', fn ($q) => $q->where('tax_name', 'like', "%{$search}%"))
            ->orderBy('tax_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Tax $t) => [
                'id' => $t->tax_id,
                'name' => $t->tax_name,
                'rate' => (float) $t->tax_rate,
                'active' => $t->active,
            ]);

        return Inertia::render('master/pajak/index', [
            'taxes' => $taxes,
            'filters' => ['q' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        Tax::create([...$this->validated($request), 'owner_id' => $this->ownerId($request)]);

        return back()->with('success', 'Pajak ditambahkan.');
    }

    public function update(Request $request, Tax $tax): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($tax->owner_id === $this->ownerId($request), 404);

        $tax->update($this->validated($request, $tax));

        return back()->with('success', 'Pajak diperbarui.');
    }

    public function destroy(Request $request, Tax $tax): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($tax->owner_id === $this->ownerId($request), 404);

        try {
            $tax->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Pajak'));
        }

        return back()->with('success', 'Pajak dihapus.');
    }

    private function validated(Request $request, ?Tax $tax = null): array
    {
        $ownerId = $this->ownerId($request);

        return $request->validate([
            'tax_name' => [
                'required', 'string', 'max:100',
                Rule::unique('taxes', 'tax_name')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($tax?->tax_id, 'tax_id'),
            ],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'active' => ['required', 'boolean'],
        ], [], ['tax_name' => 'nama pajak', 'tax_rate' => 'tarif']);
    }
}

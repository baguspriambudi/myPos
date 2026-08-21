<?php

namespace App\Http\Controllers\Master;

use App\Models\ChartOfAccount;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChartOfAccountController extends MasterController
{
    private const TYPES = ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $type = $request->query('type');

        $accounts = $this->scopeOwner(ChartOfAccount::query(), $request)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('account_name', 'like', "%{$search}%")
                ->orWhere('account_code', 'like', "%{$search}%")))
            ->when($type, fn ($q) => $q->where('account_type', $type))
            ->orderBy('account_code')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (ChartOfAccount $a) => [
                'id' => $a->account_id,
                'code' => $a->account_code,
                'name' => $a->account_name,
                'type' => $a->account_type,
                'typeLabel' => $a->typeLabel(),
                'isGrni' => $a->account_code === ChartOfAccount::CODE_GRNI,
            ]);

        return Inertia::render('master/akun/index', [
            'accounts' => $accounts,
            'filters' => ['q' => $search, 'type' => $type],
            'types' => [
                ['value' => 'ASSET', 'label' => 'Aset'],
                ['value' => 'LIABILITY', 'label' => 'Kewajiban'],
                ['value' => 'EQUITY', 'label' => 'Ekuitas'],
                ['value' => 'REVENUE', 'label' => 'Pendapatan'],
                ['value' => 'EXPENSE', 'label' => 'Beban'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        ChartOfAccount::create([...$this->validated($request), 'owner_id' => $this->ownerId($request)]);

        return back()->with('success', 'Akun ditambahkan.');
    }

    public function update(Request $request, ChartOfAccount $account): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($account->owner_id === $this->ownerId($request), 404);

        $account->update($this->validated($request, $account));

        return back()->with('success', 'Akun diperbarui.');
    }

    public function destroy(Request $request, ChartOfAccount $account): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($account->owner_id === $this->ownerId($request), 404);

        // Akun GRNI dipakai mekanisme penerimaan barang (Addendum 58.5).
        if ($account->account_code === ChartOfAccount::CODE_GRNI) {
            return back()->with('error', 'Akun Utang Belum Ditagih dipakai mekanisme penerimaan barang dan tidak dapat dihapus.');
        }

        try {
            $account->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Akun'));
        }

        return back()->with('success', 'Akun dihapus.');
    }

    private function validated(Request $request, ?ChartOfAccount $account = null): array
    {
        $ownerId = $this->ownerId($request);

        return $request->validate([
            'account_code' => [
                'required', 'string', 'max:20',
                Rule::unique('chart_of_accounts', 'account_code')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($account?->account_id, 'account_id'),
            ],
            'account_name' => ['required', 'string', 'max:150'],
            'account_type' => ['required', Rule::in(self::TYPES)],
        ], [], [
            'account_code' => 'kode akun',
            'account_name' => 'nama akun',
            'account_type' => 'tipe akun',
        ]);
    }
}

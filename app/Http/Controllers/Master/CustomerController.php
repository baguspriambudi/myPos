<?php

namespace App\Http\Controllers\Master;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PriceList;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master pelanggan.
 *
 * Tiap usaha punya satu pelanggan tunai bawaan. Kasir tidak perlu memilih
 * pelanggan untuk penjualan eceran — semuanya tercatat ke sana. Yang benar-benar
 * didaftarkan hanyalah member.
 *
 * Tidak ada pilihan daftar harga di layar ini: member otomatis memakai daftar
 * harga bertanda member, pelanggan tunai memakai harga normal (Addendum 39.13).
 */
class CustomerController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $customers = $this->scopeOwner(Customer::query(), $request)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->with('branch')
            // Pelanggan tunai bawaan selalu di baris pertama: itu yang paling
            // sering dilihat dan yang menjelaskan isi layar ini.
            ->orderByDesc('is_default')
            ->orderBy('customer_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Customer $c) => [
                'id' => $c->customer_id,
                'name' => $c->customer_name,
                'phone' => $c->phone,
                'isMember' => $c->is_member,
                'isDefault' => $c->is_default,
                'creditLimit' => (float) $c->credit_limit,
                'branchId' => $c->branch_id,
                'branchName' => $c->branch?->branch_name,
                'active' => $c->active,
            ]);

        return Inertia::render('master/pelanggan/index', [
            'customers' => $customers,
            'filters' => ['q' => $search],
            'branches' => $this->scopeOwner(Branch::query(), $request)->active()->orderBy('branch_code')->get()
                ->map(fn ($b) => ['id' => $b->branch_id, 'name' => $b->branch_name]),
            // Nama daftar harga member, sekadar untuk memberi tahu member akan
            // memakai harga yang mana. Tidak ada yang perlu dipilih di sini.
            'daftarHargaMember' => $this->scopeOwner(PriceList::query(), $request)
                ->active()
                ->where('member_only', true)
                ->orderByDesc('priority')
                ->pluck('price_list_name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request) {
            $customer = Customer::create([...$data, 'owner_id' => $this->ownerId($request)]);
            $this->enforceSingleDefault($customer);
        });

        return back()->with('success', 'Pelanggan ditambahkan.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($customer->owner_id === $this->ownerId($request), 404);

        $data = $this->validated($request, $customer);

        DB::transaction(function () use ($customer, $data) {
            $customer->update($data);
            $this->enforceSingleDefault($customer);
        });

        return back()->with('success', 'Pelanggan diperbarui.');
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($customer->owner_id === $this->ownerId($request), 404);

        if ($customer->is_default) {
            return back()->with('error', 'Pelanggan tunai bawaan tidak dapat dihapus — seluruh penjualan eceran tercatat ke sana.');
        }

        try {
            $customer->delete();
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Pelanggan'));
        }

        return back()->with('success', 'Pelanggan dihapus.');
    }

    /**
     * Bawaan hanya boleh satu per usaha. Menandai pelanggan lain sebagai bawaan
     * otomatis melepas tanda dari yang lama — itu cara memindahkannya.
     */
    private function enforceSingleDefault(Customer $customer): void
    {
        if (! $customer->is_default) {
            return;
        }

        Customer::where('owner_id', $customer->owner_id)
            ->whereKeyNot($customer->customer_id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        $ownerId = $this->ownerId($request);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_member' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'branch_id' => ['nullable', Rule::exists('branches', 'branch_id')->where(fn ($q) => $q->where('owner_id', $ownerId))],
            'active' => ['required', 'boolean'],
        ], [], [
            'customer_name' => 'nama pelanggan',
            'credit_limit' => 'batas piutang',
        ]);

        // Tanda bawaan dipindahkan dengan menandai pelanggan LAIN, bukan dengan
        // melepasnya di sini — kalau boleh dilepas, usaha ini bisa kehilangan
        // tujuan pencatatan seluruh penjualan eceran.
        if ($customer?->is_default && ! $data['is_default']) {
            throw ValidationException::withMessages([
                'is_default' => 'Setiap usaha wajib punya satu pelanggan tunai bawaan. Tandai pelanggan lain sebagai bawaan untuk memindahkannya.',
            ]);
        }

        if ($data['is_default']) {
            // Ia mewakili pembeli yang tidak disebut namanya: tidak punya
            // piutang, tidak terikat satu lokasi, tidak pernah nonaktif.
            $data['is_member'] = false;
            $data['credit_limit'] = 0;
            $data['branch_id'] = null;
            $data['active'] = true;
        }

        return $data;
    }
}

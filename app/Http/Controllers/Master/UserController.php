<?php

namespace App\Http\Controllers\Master;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master pengguna dan hak akses lokasinya (Addendum 50).
 *
 * Kata sandi tidak pernah dikirim ke frontend dan hanya bisa direset dari sini
 * — pengguna POS tidak punya email sehingga tidak ada alur lupa kata sandi.
 */
class UserController extends MasterController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $users = $this->scopeOwner(User::query(), $request)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")))
            ->with(['branch', 'branchAccess'])
            ->orderBy('full_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->user_id,
                'username' => $u->username,
                'fullName' => $u->full_name,
                'role' => $u->role,
                'roleLabel' => $u->roleLabel(),
                'branchId' => $u->branch_id,
                'branchName' => $u->branch?->branch_name,
                'branchAccessIds' => $u->branchAccess->pluck('branch_id')->values(),
                'active' => $u->active,
                'isSelf' => $u->user_id === $request->user()->user_id,
            ]);

        return Inertia::render('master/pengguna/index', [
            'users' => $users,
            'filters' => ['q' => $search],
            'branches' => $this->scopeOwner(Branch::query(), $request)
                ->active()
                ->orderBy('branch_code')
                ->get()
                ->map(fn (Branch $b) => [
                    'id' => $b->branch_id,
                    'name' => $b->branch_name,
                    'code' => $b->branch_code,
                    'typeLabel' => $b->typeLabel(),
                    'canSell' => $b->can_sell,
                ]),
            'roles' => [
                ['value' => User::ROLE_OWNER, 'label' => 'Pemilik', 'hint' => 'Akses penuh seluruh lokasi'],
                ['value' => User::ROLE_SUPER_ADMIN, 'label' => 'Super Admin', 'hint' => 'Sama seperti pemilik, kecuali menyetujui reopen miliknya sendiri'],
                ['value' => User::ROLE_MANAGER, 'label' => 'Manajer', 'hint' => 'Menutup periode dan void, terbatas pada lokasinya'],
                ['value' => User::ROLE_KASIR, 'label' => 'Kasir', 'hint' => 'Transaksi penjualan dan shift; tidak melihat harga beli'],
                ['value' => User::ROLE_GUDANG, 'label' => 'Gudang', 'hint' => 'Penerimaan, transfer, dan opname; tidak menyentuh uang maupun harga'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'owner_id' => $this->ownerId($request),
                'branch_id' => $data['branch_id'],
                'username' => $data['username'],
                'password_hash' => $data['password'],
                'full_name' => $data['full_name'],
                'role' => $data['role'],
                'active' => $data['active'],
            ]);

            $user->branchAccess()->sync($data['branch_access'] ?? []);
        });

        return back()->with('success', 'Pengguna ditambahkan.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($user->owner_id === $this->ownerId($request), 404);

        $data = $this->validated($request, $user);

        // Mengunci diri sendiri akan mengeluarkan admin terakhir dari sistem.
        if ($user->user_id === $request->user()->user_id && ! $data['active']) {
            throw ValidationException::withMessages([
                'active' => 'Tidak dapat menonaktifkan akun yang sedang Anda pakai.',
            ]);
        }

        DB::transaction(function () use ($user, $data) {
            $attributes = [
                'branch_id' => $data['branch_id'],
                'username' => $data['username'],
                'full_name' => $data['full_name'],
                'role' => $data['role'],
                'active' => $data['active'],
            ];

            // Kata sandi hanya diganti bila diisi.
            if (! empty($data['password'])) {
                $attributes['password_hash'] = $data['password'];
            }

            $user->update($attributes);
            $user->branchAccess()->sync($data['branch_access'] ?? []);
        });

        return back()->with('success', 'Pengguna diperbarui.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorizeWrite($request);
        abort_unless($user->owner_id === $this->ownerId($request), 404);

        if ($user->user_id === $request->user()->user_id) {
            return back()->with('error', 'Tidak dapat menghapus akun yang sedang Anda pakai.');
        }

        try {
            DB::transaction(function () use ($user) {
                $user->branchAccess()->detach();
                $user->delete();
            });
        } catch (QueryException) {
            return back()->with('error', $this->inUseMessage('Pengguna'));
        }

        return back()->with('success', 'Pengguna dihapus.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $ownerId = $this->ownerId($request);

        $data = $request->validate([
            'username' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('users', 'username')
                    ->where(fn ($q) => $q->where('owner_id', $ownerId))
                    ->ignore($user?->user_id, 'user_id'),
            ],
            'full_name' => ['required', 'string', 'max:150'],
            'role' => ['required', Rule::in(User::ROLES)],
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'branch_id')->where(fn ($q) => $q->where('owner_id', $ownerId)),
            ],
            'branch_access' => ['array'],
            'branch_access.*' => [
                Rule::exists('branches', 'branch_id')->where(fn ($q) => $q->where('owner_id', $ownerId)),
            ],
            // Wajib saat membuat, opsional saat mengubah.
            'password' => [$user === null ? 'required' : 'nullable', 'string', 'min:6', 'max:255'],
            'active' => ['required', 'boolean'],
        ], [], [
            'username' => 'nama pengguna',
            'full_name' => 'nama lengkap',
            'role' => 'peran',
            'branch_id' => 'lokasi utama',
            'password' => 'kata sandi',
        ]);

        // OWNER dan SUPER ADMIN melihat seluruh lokasi, jadi daftar akses
        // per-lokasi tidak berlaku untuk mereka (Addendum 50).
        if (in_array($data['role'], User::ROLES_MASTER_ADMIN, true)) {
            $data['branch_access'] = [];
        } elseif (empty($data['branch_access']) && $data['branch_id'] === null) {
            throw ValidationException::withMessages([
                'branch_access' => 'Peran ini harus diberi setidaknya satu lokasi.',
            ]);
        }

        return $data;
    }
}

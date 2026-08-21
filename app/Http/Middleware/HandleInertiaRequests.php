<?php

namespace App\Http\Middleware;

use App\Support\ActiveBranch;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props yang dibagikan ke seluruh halaman.
     *
     * Fallback "pengguna contoh" yang dipakai selama tahap tampilan sudah
     * dihapus — sejak login aktif, seluruh halaman berada di balik middleware
     * auth sehingga user selalu ada.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? $this->userPayload($user) : null,
                'owner' => $user?->owner ? [
                    'id' => $user->owner->owner_id,
                    'code' => $user->owner->owner_code,
                    'name' => $user->owner->owner_name,
                    'costingMethod' => $user->owner->costing_method,
                    'roundingUnit' => $user->owner->payment_rounding_unit,
                    'roundingMode' => $user->owner->payment_rounding_mode,
                ] : null,
                'branch' => $user ? $this->branchPayload(ActiveBranch::for($user)) : null,
                'branches' => $user
                    ? $user->accessibleBranches()->map(fn ($b) => $this->branchPayload($b))->values()
                    : [],
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Kunci "name" dan "email" dipertahankan supaya komponen profil bawaan
     * starter kit tetap bekerja tanpa diubah. "email" diisi peran + lokasi,
     * karena pengguna POS memang tidak punya alamat surel.
     */
    private function userPayload($user): array
    {
        $branch = ActiveBranch::for($user);

        return [
            'id' => $user->user_id,
            'name' => $user->full_name,
            'email' => $user->roleLabel().($branch ? ' · '.$branch->branch_name : ''),
            'username' => $user->username,
            'role' => $user->role,
            'roleLabel' => $user->roleLabel(),
            'isMasterAdmin' => $user->isMasterAdmin(),
            'avatar' => null,
        ];
    }

    private function branchPayload($branch): ?array
    {
        if ($branch === null) {
            return null;
        }

        return [
            'id' => $branch->branch_id,
            'code' => $branch->branch_code,
            'name' => $branch->branch_name,
            'type' => $branch->branch_type,
            'typeLabel' => $branch->typeLabel(),
            'canSell' => (bool) $branch->can_sell,
        ];
    }
}

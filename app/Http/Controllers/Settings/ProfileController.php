<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profil pengguna sendiri: nama lengkap saja.
 *
 * Username tidak dapat diubah sendiri karena dipakai untuk login dan unik per
 * owner — penggantiannya lewat master Pengguna oleh OWNER/SUPER ADMIN. Menghapus
 * akun sendiri juga ditiadakan: akun POS milik usaha, bukan milik pribadi.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/profile', [
            'status' => $request->session()->get('status'),
            'profile' => [
                'fullName' => $user->full_name,
                'username' => $user->username,
                'roleLabel' => $user->roleLabel(),
                'ownerName' => $user->owner?->owner_name,
            ],
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return to_route('profile.edit')->with('success', 'Profil disimpan.');
    }
}

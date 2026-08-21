<?php

namespace App\Http\Controllers;

use App\Support\ActiveBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Berpindah lokasi aktif tanpa logout.
 *
 * Pilihan divalidasi ulang terhadap hak akses user — session tidak dipercaya.
 */
class BranchSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
        ]);

        if (! ActiveBranch::set($request->user(), $data['branch_id'])) {
            return back()->with('error', 'Anda tidak memiliki akses ke lokasi tersebut.');
        }

        return back()->with('success', 'Lokasi aktif diganti.');
    }
}

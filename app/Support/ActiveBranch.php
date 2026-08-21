<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;

/**
 * Lokasi yang sedang dilihat pengguna.
 *
 * Disimpan di session supaya OWNER/MANAGER yang memegang beberapa lokasi dapat
 * berpindah tanpa logout. Nilainya selalu divalidasi ulang terhadap daftar
 * lokasi yang benar-benar boleh diakses — session tidak dipercaya begitu saja.
 */
class ActiveBranch
{
    public const SESSION_KEY = 'active_branch_id';

    public static function for(User $user): ?Branch
    {
        $allowed = $user->accessibleBranches();

        if ($allowed->isEmpty()) {
            return null;
        }

        $sessionId = session(self::SESSION_KEY);

        if ($sessionId !== null) {
            $match = $allowed->firstWhere('branch_id', (int) $sessionId);

            if ($match !== null) {
                return $match;
            }
        }

        // Lokasi utama user bila ada, kalau tidak ambil yang pertama.
        return $allowed->firstWhere('branch_id', $user->branch_id) ?? $allowed->first();
    }

    /** @return bool true bila lokasi tersebut boleh dipakai user ini. */
    public static function set(User $user, int $branchId): bool
    {
        $allowed = $user->accessibleBranches();

        if ($allowed->firstWhere('branch_id', $branchId) === null) {
            return false;
        }

        session([self::SESSION_KEY => $branchId]);

        return true;
    }
}

<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Dasar seluruh controller master.
 *
 * Isolasi antar-owner wajib dijamin di seluruh query (Addendum 57.6). Daripada
 * mengandalkan ingatan di tiap controller, semua query master lewat scopeOwner()
 * dan semua tulis lewat ownerId().
 */
abstract class MasterController extends Controller
{
    /** Jumlah baris per halaman untuk seluruh layar master. */
    protected const PER_PAGE = 15;

    protected function user(Request $request): User
    {
        return $request->user();
    }

    protected function ownerId(Request $request): int
    {
        return $request->user()->owner_id;
    }

    /** Batasi query ke owner yang sedang login. */
    protected function scopeOwner(Builder $query, Request $request): Builder
    {
        return $query->where('owner_id', $this->ownerId($request));
    }

    /**
     * Master hanya boleh diubah OWNER dan SUPER ADMIN (Addendum 50).
     * Membaca tetap diizinkan role lain supaya layar transaksi bisa memilih data.
     */
    protected function authorizeWrite(Request $request): void
    {
        if (! $request->user()->isMasterAdmin()) {
            throw new HttpException(403, 'Hanya Pemilik dan Super Admin yang dapat mengubah data master.');
        }
    }

    /**
     * Terjemahan pesan constraint database ke bahasa yang dimengerti pengguna.
     * Dipakai saat menghapus master yang sudah dipakai transaksi.
     */
    protected function inUseMessage(string $entity): string
    {
        return "{$entity} ini sudah dipakai pada transaksi sehingga tidak dapat dihapus. Nonaktifkan saja agar tidak muncul pada transaksi baru.";
    }
}

<?php

namespace App\Http\Controllers\Master;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan tingkat usaha (tabel owners).
 *
 * Metode costing dan izin stok negatif ada di sini karena keduanya per owner,
 * bukan per lokasi — seluruh cabang satu owner wajib memakai metode yang sama
 * (Addendum 38.1 dan catatan keputusan 66.1).
 */
class OwnerSettingController extends MasterController
{
    public function edit(Request $request): Response
    {
        $owner = $request->user()->owner;

        return Inertia::render('master/usaha/index', [
            'owner' => [
                'code' => $owner->owner_code,
                'name' => $owner->owner_name,
                'defaultCurrency' => $owner->default_currency,
                'costingMethod' => $owner->costing_method,
                'allowNegativeStock' => $owner->allow_negative_stock,
                'paymentRoundingUnit' => $owner->payment_rounding_unit,
                'paymentRoundingMode' => $owner->payment_rounding_mode,
            ],
            'hasOpenPeriod' => $owner->branches()
                ->join('fiscal_periods', 'fiscal_periods.branch_id', '=', 'branches.branch_id')
                ->whereIn('fiscal_periods.status', ['OPEN', 'REOPENED'])
                ->exists(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeWrite($request);
        $owner = $request->user()->owner;

        $data = $request->validate([
            'owner_name' => ['required', 'string', 'max:150'],
            'costing_method' => ['required', Rule::in(['AVERAGE', 'FIFO', 'SPECIFIC'])],
            'allow_negative_stock' => ['required', 'boolean'],
            'payment_rounding_unit' => ['required', 'integer', Rule::in([0, 100, 500, 1000])],
            'payment_rounding_mode' => ['required', Rule::in(['NEAREST', 'DOWN', 'UP'])],
        ], [], [
            'owner_name' => 'nama usaha',
            'costing_method' => 'metode HPP',
            'payment_rounding_unit' => 'pembulatan pembayaran',
        ]);

        // Metode costing di-snapshot ke fiscal period saat dibuka (Addendum 38.1).
        // Mengubahnya di tengah period yang berjalan membuat angka HPP period itu
        // tidak lagi cocok dengan metodenya sendiri.
        if ($data['costing_method'] !== $owner->costing_method) {
            $hasOpen = $owner->branches()
                ->join('fiscal_periods', 'fiscal_periods.branch_id', '=', 'branches.branch_id')
                ->whereIn('fiscal_periods.status', ['OPEN', 'REOPENED'])
                ->exists();

            if ($hasOpen) {
                throw ValidationException::withMessages([
                    'costing_method' => 'Metode HPP tidak dapat diubah selama masih ada periode yang terbuka. Tutup periode berjalan lebih dulu.',
                ]);
            }
        }

        $owner->update($data);

        return back()->with('success', 'Pengaturan usaha disimpan.');
    }
}

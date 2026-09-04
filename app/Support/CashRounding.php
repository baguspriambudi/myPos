<?php

namespace App\Support;

/**
 * Pembulatan pembayaran tunai (Addendum 51.4).
 *
 * Ini BUKAN diskon: nilai penjualan (net_total) dan dasar pajak tidak berubah,
 * yang berubah hanya jumlah uang yang benar-benar diterima. Mencatatnya sebagai
 * diskon akan mengecilkan omzet dan pajak padahal barangnya terjual penuh.
 *
 * Kembarannya di layar kasir ada di resources/js/components/pos-ui.tsx
 * (hitungPembulatan) — keduanya harus memberi hasil sama, karena kasir melihat
 * angkanya sebelum server menghitungnya ulang.
 */
class CashRounding
{
    /** Selisih pembulatan: bernilai + atau −, dan 0 bila pembulatan dimatikan. */
    public static function amount(float $netTotal, int $unit, string $mode): float
    {
        if ($unit <= 0) {
            return 0.0;
        }

        $dibayar = match ($mode) {
            'DOWN' => floor($netTotal / $unit) * $unit,
            'UP' => ceil($netTotal / $unit) * $unit,
            default => round($netTotal / $unit) * $unit,
        };

        return round($dibayar - $netTotal, 2);
    }
}

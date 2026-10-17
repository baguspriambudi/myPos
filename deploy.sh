#!/usr/bin/env bash
#
# deploy.sh — Deploy myPOS ke VPS
#
# Cara pakai di VPS:
#   cd /var/www/mypos
#   ./deploy.sh
#
# Ini untuk deploy BERULANG. Penyiapan pertama kali (clone, .env, database,
# vhost, cron) ada di DEPLOY.md dan hanya dikerjakan sekali.
#
# Catatan penting:
#   - Pakai `composer install`, BUKAN `composer update`
#   - APP_KEY di VPS jangan sampai berubah — sesi dan data terenkripsi ikut mati
#   - Script ini TIDAK menjalankan seeder. Seeder Toko Pandu hanya untuk
#     pengisian pertama; menjalankannya lagi akan menggandakan usaha & produk.
#
set -euo pipefail

# Seluruh langkah dibungkus di fungsi main() supaya bash membaca & mem-parse
# semua isi script LEBIH DULU sebelum eksekusi. Ini penting karena `git pull`
# di dalam bisa menimpa file deploy.sh ini sendiri saat sedang berjalan.
main() {
    cd "$(dirname "$0")"

    echo "==> [1/7] Aktifkan maintenance mode"
    php artisan down || true

    # Jaminan: apa pun yang terjadi (sukses/gagal), situs dikembalikan online
    trap 'php artisan up || true' EXIT

    echo "==> [2/7] Tarik kode terbaru dari GitHub (branch main)"
    git pull origin main

    echo "==> [3/7] Composer install (produksi, tanpa dev)"
    composer install --no-dev --optimize-autoloader --no-interaction

    echo "==> [4/7] Install dependency & build asset frontend (Vite)"
    npm ci
    npm run build

    echo "==> [5/7] Backup database, lalu jalankan migrasi"
    # Migrasi yang salah tidak bisa di-undo dengan aman di produksi; salinan
    # tepat sebelum migrasi adalah jalan pulangnya (lihat ops/backup-databases.sh).
    if [[ -x /usr/local/bin/backup-databases.sh ]]; then
        sudo /usr/local/bin/backup-databases.sh
    else
        echo "    !! backup-databases.sh belum terpasang — migrasi berjalan TANPA backup."
    fi
    php artisan migrate --force

    echo "==> [6/7] Bersihkan & bangun ulang cache Laravel"
    php artisan optimize:clear
    php artisan optimize

    echo "==> [7/7] Periksa penjadwal"
    # myPOS menggantungkan satu hal penting pada scheduler: periode yang
    # tertinggal di status CLOSING dikembalikan otomatis tiap 5 menit
    # (periode:pulihkan-closing, Addendum 44). Tanpa cron, periode yang
    # penutupannya mati di tengah akan terkunci SELAMANYA — transaksi baru
    # ditolak dan tidak ada yang bisa membukanya lewat layar mana pun.
    if crontab -l 2>/dev/null | grep -q "schedule:run"; then
        echo "    cron schedule:run terpasang."
    else
        echo "    !! PERINGATAN: cron schedule:run TIDAK ditemukan."
        echo "    !! Pasang dengan: crontab -e"
        echo "    !! * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
    fi

    echo ""
    echo "======================================================"
    echo " SELESAI ✅"
    echo "======================================================"
}

main "$@"

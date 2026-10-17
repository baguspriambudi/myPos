#!/usr/bin/env bash
#
# backup-databases.sh — mysqldump harian untuk seluruh aplikasi Laravel di VPS
#
# Dipasang di server sebagai /usr/local/bin/backup-databases.sh dan dijalankan
# cron root setiap hari:
#
#   15 2 * * * /usr/local/bin/backup-databases.sh >> /var/log/backup-databases.log 2>&1
#
# Kredensial database dibaca dari .env tiap aplikasi saat skrip berjalan,
# jadi tidak ada kata sandi yang disalin ke skrip ini atau ke crontab.
# Hasilnya: /var/backups/mysql/<app>/<app>_YYYYMMDD_HHMM.sql.gz, disimpan
# KEEP_DAYS hari.
#
# Backup yang hanya ada di server yang sama ikut hilang bila servernya hilang.
# Unduh salinannya ke tempat lain secara berkala.
#
set -euo pipefail

APPS=(/var/www/mypos /var/www/homeplan)
DEST=/var/backups/mysql
KEEP_DAYS=14

env_value() {
    # Ambil satu nilai dari .env, buang tanda kutip pembungkusnya.
    grep -E "^$2=" "$1/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

status=0

for app in "${APPS[@]}"; do
    name=$(basename "$app")

    if [[ ! -f "$app/.env" ]]; then
        echo "$(date '+%F %T') [$name] dilewati: .env tidak ditemukan"
        continue
    fi

    db=$(env_value "$app" DB_DATABASE)
    user=$(env_value "$app" DB_USERNAME)
    host=$(env_value "$app" DB_HOST)
    port=$(env_value "$app" DB_PORT)
    pass=$(env_value "$app" DB_PASSWORD)

    mkdir -p "$DEST/$name"
    chmod 700 "$DEST" "$DEST/$name"
    out="$DEST/$name/${name}_$(date +%Y%m%d_%H%M).sql.gz"

    if MYSQL_PWD="$pass" mysqldump -h "${host:-127.0.0.1}" -P "${port:-3306}" -u "$user" \
        --single-transaction --routines --triggers --no-tablespaces \
        --default-character-set=utf8mb4 "$db" | gzip > "$out.tmp"; then
        mv "$out.tmp" "$out"
        chmod 600 "$out"
        echo "$(date '+%F %T') [$name] OK $(du -h "$out" | cut -f1) -> $out"
    else
        rm -f "$out.tmp"
        echo "$(date '+%F %T') [$name] GAGAL"
        status=1
    fi

    find "$DEST/$name" -name "${name}_*.sql.gz" -mtime +"$KEEP_DAYS" -delete
done

exit $status

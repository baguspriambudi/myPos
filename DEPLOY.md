# Deploy myPOS

Polanya sama dengan myExpansesV2/homeplan: kode ditarik dari GitHub ke VPS, lalu
`./deploy.sh` dijalankan di sana. Berkas ini hanya untuk **penyiapan pertama
kali**; deploy berikutnya cukup `./deploy.sh`.

---

## 0. Yang harus sudah ada di VPS

| | Keterangan |
|---|---|
| PHP | **8.4** — sama seperti lokal. Ekstensi: `pdo_mysql`, `mbstring`, `bcmath`, `intl`, `zip`, `xml`, `curl`, `fileinfo` |
| Composer | 2.x |
| Node | 20+ (untuk `npm ci && npm run build`) |
| MySQL | **5.7 ke atas.** Catatan: MySQL 5.7 mengabaikan `CHECK`, dan migrasi myPOS memang memakai `ENUM`, bukan `CHECK` — jadi aman |
| Web server | Nginx/Apache dengan document root `public/` |

---

## 1. Clone

```bash
sudo mkdir -p /var/www/mypos
sudo chown "$USER":"$USER" /var/www/mypos
git clone https://github.com/baguspriambudi/myPos.git /var/www/mypos
cd /var/www/mypos
```

## 2. Database

```sql
CREATE DATABASE mypos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mypos'@'localhost' IDENTIFIED BY '<kata-sandi-kuat>';
GRANT ALL PRIVILEGES ON mypos.* TO 'mypos'@'localhost';
FLUSH PRIVILEGES;
```

## 3. `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Lalu sesuaikan — yang **wajib** berbeda dari lokal:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domain-anda>

DB_DATABASE=mypos
DB_USERNAME=mypos
DB_PASSWORD=<kata-sandi-kuat>

# Keduanya memakai tabel database; tabelnya sudah ikut migrasi, tidak perlu
# perintah tambahan.
SESSION_DRIVER=database
CACHE_STORE=database

# Belum ada pekerjaan latar apa pun, jadi tidak perlu queue worker.
QUEUE_CONNECTION=sync
```

> **APP_KEY jangan pernah diganti setelah dipakai.** Mengubahnya membuat seluruh
> sesi dan nilai terenkripsi tidak terbaca lagi.

> `APP_LOCALE` dibiarkan `en`. Teks aplikasi seluruhnya Bahasa Indonesia dan
> ditulis langsung; nama bulan yang dipakai penomoran periode punya daftarnya
> sendiri di `FiscalPeriodManager`, tidak bergantung locale.

## 4. Dependency & build

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## 5. Migrasi + isi data awal

**Hanya di deploy pertama:**

```bash
php artisan migrate --force --seed
```

Seeder mengisi data nyata Toko Pandu: 1 usaha, 1 lokasi (`TP01`), 2 pengguna,
137 produk beserta harga jualnya, dan periode pembukuan bulan berjalan.

> **Jangan pernah menjalankan `--seed` lagi setelah ini**, dan jangan sekali-kali
> `migrate:fresh` di server — usaha dan produknya akan terduplikasi atau hilang.
> `deploy.sh` sengaja hanya menjalankan `migrate --force`.

**Segera setelah deploy pertama, ganti kata sandi kedua akun** (`pandu` dan
`admin` masih memakai `password123` bawaan seeder) lewat Pengaturan › Pengguna.

## 6. Izin folder

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## 7. Cron — **jangan dilewat**

```bash
crontab -e
```

```cron
* * * * * cd /var/www/mypos && php artisan schedule:run >> /dev/null 2>&1
```

Satu baris ini menjalankan `periode:pulihkan-closing` tiap 5 menit. Fungsinya
mengembalikan periode yang tertinggal di status `CLOSING` — keadaan yang terjadi
bila proses penutupan mati mendadak (server restart, PHP timeout, koneksi
database putus). **Tanpa cron ini, periode seperti itu terkunci selamanya**:
seluruh transaksi pada periode tersebut ditolak dan tidak ada layar yang bisa
membukanya kembali.

## 8. Nginx

```nginx
server {
    listen 80;
    server_name <domain-anda>;
    root /var/www/mypos/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 20M;
}
```

Lalu pasang HTTPS:

```bash
sudo certbot --nginx -d <domain-anda>
```

## 9. Uji

```bash
php artisan about
```

Buka `https://<domain-anda>/login`, masuk sebagai `pandu`, lalu periksa:
Dashboard, Kasir, Master › Produk (harus 137), dan Banking › Periode Pembukuan.

---

## Deploy berikutnya

```bash
cd /var/www/mypos && ./deploy.sh
```

---

## Backup & log (sekali pasang)

```bash
sudo install -m 750 ops/backup-databases.sh /usr/local/bin/backup-databases.sh
sudo install -m 644 ops/logrotate-laravel /etc/logrotate.d/laravel-apps
sudo crontab -l 2>/dev/null | { cat; echo '15 2 * * * /usr/local/bin/backup-databases.sh >> /var/log/backup-databases.log 2>&1'; } | sudo crontab -
```

- Backup harian jam 02:15 untuk `mypos` **dan** `homeplan`, ke
  `/var/backups/mysql/<app>/`, disimpan 14 hari. Kredensial dibaca dari `.env`
  masing-masing saat berjalan.
- `deploy.sh` ikut menjalankan backup yang sama tepat sebelum `migrate`.
- `storage/logs/*.log` dirotasi harian, 14 salinan, dikompres.
- **Backup di server yang sama ikut hilang bila servernya hilang** — unduh
  salinannya ke tempat lain secara berkala.

## CI

`.github/workflows/tests.yml` menjalankan seluruh tes pada MySQL 8 setiap push
ke `main`/`develop`. `lint.yml` menjaga `tsc` dan Pint untuk kode aplikasi;
ESLint dilaporkan tanpa menggagalkan build karena enam layar master lama masih
melanggar `no-unused-expressions`.

---

## Yang belum ada, supaya tidak dicari

- **Tidak ada queue worker maupun Horizon** — belum ada pekerjaan latar.
- **Domain + HTTPS** belum dipasang; aplikasi masih di `http://<ip>:8080`.

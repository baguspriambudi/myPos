# PRD Addendum — Fiscal Period, Closing & Periodic HPP

Aplikasi POS — Dokumen Versi 1.3
Status: Draft (Revisi 6 — 20 Agustus 2026)
Modul: Fiscal Period & Closing Management

Dokumen ini merupakan addendum dari PRD utama (`prd.md`, Section 1–37). Seluruh aturan pada PRD utama tetap berlaku. Penomoran section dilanjutkan dari Section 38.

Acuan struktur data: `database-schema.sql`. Bila ada perbedaan antara dokumen ini dan schema, yang berlaku adalah dokumen ini dan schema wajib disesuaikan (daftar penyesuaian ada di Section 66).

---

## 38. Metode HPP / Costing Method

PRD utama (Section 14–16) belum mendefinisikan metode costing. Sistem harus mendukung metode berikut:

| Metode | Kode | Status |
|---|---|---|
| Average Cost | `AVERAGE` | Default |
| FIFO (First In First Out) | `FIFO` | Didukung tahap 1 |
| Specific Identification | `SPECIFIC` | Opsional, tahap lanjut |

### 38.1 Konfigurasi

- Metode costing dikonfigurasi **per owner** (`owners.costing_method`). Tidak ada konfigurasi per branch — seluruh branch milik satu owner memakai metode yang sama.
- Saat fiscal period dibuat, metode costing owner **disalin (snapshot)** ke `fiscal_periods.costing_method` dan menjadi metode yang berlaku untuk seluruh transaksi period tersebut.
- Mengubah metode di owner **tidak** memengaruhi period yang sudah berjalan atau sudah CLOSED. Metode baru berlaku untuk period berikutnya.
- Mengubah metode pada period yang sedang OPEN hanya boleh melalui reopen + recalculate HPP (Section 45).
- Jika tidak dikonfigurasi, gunakan Average Cost.

### 38.2 Contoh Perhitungan

Data pembelian yang dipakai pada kedua contoh:

| Urutan | Transaksi | Qty | Harga/unit |
|---|---|---|---|
| 1 | Purchase | 10 pcs | Rp10.000 |
| 2 | Purchase | 10 pcs | Rp12.000 |

**Average Cost**
Setelah pembelian 1: average cost = Rp10.000.
Setelah pembelian 2: average cost = (10×10.000 + 10×12.000) / 20 = Rp11.000.
Sales 5 pcs → COGS = 5 × 11.000 = **Rp55.000**.

**FIFO**
Sales 5 pcs diambil dari lot tertua (lot @Rp10.000, sisa 10 pcs) → COGS = 5 × 10.000 = **Rp50.000**.
Sisa lot 1 menjadi 5 pcs, lot 2 tetap 10 pcs @Rp12.000.

### 38.3 Penerapan pada Data

- Metode AVERAGE memakai `stock_balances.avg_cost` yang dihitung ulang setiap movement masuk.
- Metode FIFO memakai `stock_lots` (per branch + product) yang dikonsumsi berdasarkan `received_date`, lalu `lot_id`.
- Berapa pun metodenya, HPP yang dipakai sebuah transaksi **disimpan** di `sale_items.cost_at_sale` saat POSTED dan menjadi acuan untuk void (Section 41) dan retur (Section 48).

---

## 39. Konversi Satuan (UOM) — Retail / Ecer

Toko retail/ecer umumnya membeli dalam satuan besar tetapi menjual dalam satuan kecil. Sistem WAJIB mendukung konversi satuan pada pembelian dan penjualan.

### 39.1 Konsep Dasar

- Setiap product memiliki satu **Base UOM** (satuan dasar).
- Seluruh stok, HPP, dan closing disimpan dalam Base UOM.
- Satuan lain (**Pack UOM**) memiliki `conversion_factor` terhadap Base UOM.
- Harga jual/beli dapat ditetapkan per UOM.

Contoh — Indomie:

| UOM | Tipe | conversion_factor |
|---|---|---|
| pcs | BASE | 1 |
| dus | PACK | 24 |
| karton | PACK | 120 |

### 39.2 Struktur Data

`product_uoms`:

| Field | Tipe | Keterangan |
|---|---|---|
| `uom_id` | BIGINT | PK |
| `product_id` | BIGINT | FK ke products |
| `uom_name` | VARCHAR(50) | pcs / dus / karton / kg |
| `uom_type` | VARCHAR(10) | `BASE` atau `PACK` |
| `conversion_factor` | DECIMAL(18,6) | jumlah base unit dalam satuan ini |
| `is_default` | BOOLEAN | UOM yang muncul lebih dulu di layar kasir |
| `selling_price` | DECIMAL(18,2) | harga jual per UOM |
| `active` | BOOLEAN | |

Field pendukung di `products`:

| Field | Tipe | Keterangan |
|---|---|---|
| `base_uom_id` | BIGINT | menunjuk ke UOM bertipe BASE milik product ini |
| `is_weighted` | BOOLEAN | `TRUE` = item berat/volume (qty boleh desimal), `FALSE` = item satuan (qty wajib bulat) |

### 39.3 Aturan Konversi

- Setiap product wajib memiliki tepat 1 Base UOM (tidak boleh 0, tidak boleh lebih dari 1).
- `conversion_factor` Base UOM = 1.
- `conversion_factor` Pack UOM = jumlah base unit dalam satuan tersebut, wajib > 0, presisi maksimal 6 desimal.
- Saat transaksi dibuat, qty disimpan dalam base unit: `qty_base = qty × conversion_factor`.

### 39.4 Pembelian dengan Konversi

Toko membeli dalam satuan besar, sistem otomatis mengonversi ke base unit.

Contoh — beli 5 dus @ Rp60.000 = Rp300.000:
- `qty_base` = 5 × 24 = 120 pcs
- `unit_cost_base` = Rp300.000 / 120 = Rp2.500 per pcs
- HPP dihitung per base unit.

### 39.5 Penjualan dengan Konversi

Contoh ecer — jual 10 pcs @ Rp3.500 = Rp35.000:
- Stok berkurang 10 pcs (base unit)
- HPP = 10 × cost per pcs

Contoh satuan besar — jual 1 dus @ Rp84.000:
- `qty_base` = 1 × 24 = 24 pcs
- HPP = 24 × cost per pcs

### 39.6 UOM Berat / Volume

Penentu boleh-tidaknya qty desimal adalah `products.is_weighted`, bukan nama satuan:

| `is_weighted` | Validasi input qty | Contoh |
|---|---|---|
| `TRUE` | desimal, maksimal 2 digit | 0,25 kg; 1,5 liter |
| `FALSE` | wajib bilangan bulat | 3 pcs; 2 dus |

Penyimpanan `qty` dan `qty_base` tetap DECIMAL(18,4) agar hasil konversi pecahan tidak kehilangan presisi; batas 2 desimal adalah aturan validasi input, bukan batas penyimpanan.

Contoh konversi berat: base UOM = gram, maka 1 kg = 1.000 gram (`conversion_factor` = 1000), dan 1 ikat = 0,5 kg = 500 gram (`conversion_factor` = 500).

### 39.7 UOM pada Transaksi

Setiap baris transaksi menyimpan:

| Field | Keterangan |
|---|---|
| `uom_id` | UOM yang dipilih saat transaksi |
| `qty` | qty dalam UOM tersebut |
| `qty_base` | `qty × conversion_factor` |
| `unit_price` / `unit_cost` | harga per UOM |
| `unit_price_base` / `unit_cost_base` | harga per base unit |

`fiscal_closing_items` menyimpan qty dan value dalam base unit. Laporan closing menampilkan qty dalam base unit.

### 39.8 UOM pada Stock Opname

- Stock opname dapat dilakukan dalam UOM mana pun.
- Hasil opname dikonversi ke base unit sebelum dibandingkan dengan stok sistem.
- Selisih opname dicatat dalam base unit.

### 39.9 Validasi Konversi

- UOM yang dipilih wajib milik product tersebut dan berstatus `active`.
- `conversion_factor` sebuah UOM **tidak boleh diubah** dan UOM **tidak boleh dihapus** apabila sudah dipakai transaksi mana pun. Yang diizinkan hanya menonaktifkan (`active = FALSE`) agar tidak dapat dipilih pada transaksi baru.
- Untuk mengoreksi konversi yang terlanjur salah dan sudah dipakai transaksi: pada period OPEN lakukan koreksi transaksi (Section 42); pada period CLOSED lakukan reopen + recalculate (Section 45).
- Menambah UOM baru pada product yang sudah bertransaksi diizinkan dan tidak memengaruhi histori.
- Perubahan konversi tidak boleh mengubah histori period sebelumnya (snapshot closing bersifat final).

### 39.10 Daftar Harga Jual (Price List)

Harga jual melekat pada **satuan**, bukan pada produk. Rinso satu dus dan Rinso satu pcs adalah dua harga yang berdiri sendiri, dan harga per dus hampir selalu lebih murah dibanding mengalikan harga ecer.

`product_uoms.selling_price` tetap menjadi **harga standar** setiap satuan. Daftar harga adalah lapisan override di atasnya: bernama, berjangka waktu, boleh khusus satu lokasi atau satu pelanggan, dan **bertingkat menurut kuantitas**.

**Struktur.**

| Tabel | Isi |
|---|---|
| `price_lists` | Kepala daftar: kode, nama, lokasi (`branch_id` NULL = semua), masa berlaku, `priority`, `is_default`, `customer_only` |
| `price_list_items` | Baris harga: `product_id` + `uom_id` + `min_qty` → `unit_price` |

Contoh Rinso, base `PCS`, pack `DUS` = 12 pcs:

| UOM | `min_qty` | Harga | Berlaku saat |
|---|---|---|---|
| `DUS` | 1 | 20.000 | beli per dus |
| `PCS` | 1 | 2.500 | ecer |
| `PCS` | 6 | 2.300 | ecer ≥ 6 pcs, masih di bawah satu dus |

### 39.11 Urutan Pemilihan Harga

Diperiksa berurutan dan **berhenti pada yang pertama cocok**:

1. Daftar harga milik pelanggan (`customers.price_list_id`), bila transaksi memakai pelanggan terdaftar.
2. Daftar harga khusus lokasi (`branch_id` = lokasi transaksi) yang aktif dan mencakup `transaction_date`, `priority` tertinggi lebih dulu.
3. Daftar harga owner (`branch_id` NULL) dengan syarat yang sama.
4. Daftar harga bertanda `is_default`.
5. `product_uoms.selling_price` untuk satuan tersebut.

**Langkah 2–4 hanya memandang daftar harga dengan `customer_only` = FALSE.** Daftar bertanda `customer_only` hanya dapat dijangkau lewat langkah 1. Tanpa penyaringan ini, sebuah daftar "Harga Grosir" berprioritas tinggi akan menang atas "Harga Umum" pada langkah 3, sehingga pembeli eceran ikut mendapat harga grosir — persis kesalahan yang tertangkap saat pengujian resolusi harga pertama kali dijalankan.

Di dalam daftar harga terpilih, baris diambil dengan `product_id` + `uom_id` yang sama persis dengan yang dipilih kasir, lalu **`min_qty` terbesar yang masih ≤ qty transaksi**. Bila produk tersebut tidak ada di daftar harga terpilih, sistem **tidak** melompat ke daftar harga berikutnya — langsung turun ke `product_uoms.selling_price`, supaya harga tidak diam-diam diambil dari daftar yang tidak diniatkan.

### 39.12 Aturan

- Harga yang benar-benar dipakai **disimpan sebagai angka** di `sale_items.unit_price`. `price_list_id` hanya referensi telusur — mengubah atau menonaktifkan daftar harga tidak boleh mengubah histori transaksi. Prinsipnya sama dengan diskon (Section 61.2).
- Tidak ada konversi harga otomatis antar satuan. Bila `DUS` tidak punya baris harga, sistem **tidak** menghitungnya dari harga pcs × 12 — satuan yang belum diberi harga tidak dapat dijual, dan kasir diberi pesan yang jelas.
- Urutan hitung tidak berubah: harga dari daftar harga adalah `unit_price` pada Section 51.2 langkah 1, baru kemudian diskon, pajak, dan pembulatan.
- `min_qty` dibandingkan terhadap qty **dalam satuan baris itu**, bukan qty base. Beli 6 pcs memenuhi `PCS min_qty 6`; beli 1 dus tidak.
- Maksimal satu daftar harga `is_default` yang aktif per owner. Dijaga di service — MySQL tidak memiliki partial unique index.
- Daftar harga yang sudah dipakai transaksi tidak boleh dihapus, hanya dinonaktifkan. Barisnya boleh diubah dan berlaku untuk transaksi berikutnya saja.
- Perubahan harga masuk audit log (Section 62), karena memengaruhi laba meskipun tidak memengaruhi HPP.
- Daftar harga **tidak memengaruhi HPP**. COGS tetap dihitung dari cost persediaan.

---

## 40. Opening Awal (Initial Opening)

PRD utama (Section 11) hanya membahas opening dari closing period sebelumnya. Sistem juga harus mendukung initial opening untuk period pertama.

### 40.1 Flow

```
CREATE PERIOD
   ↓
INITIAL STOCK TAKE
   ↓
SET OPENING CASH
   ↓
SET OPENING RECEIVABLE / PAYABLE (opsional)
   ↓
OPEN
```

### 40.2 Initial Stock Take

- Stok awal dicatat **per product dan per UOM**, lalu dikonversi ke base unit.
- Nilai awal = `qty_base` × cost awal per base unit (harga beli rata-rata saat setup) atau nilai yang diinput manual.
- Setelah disimpan, sistem membuat **satu stock movement bertipe `OPENING` per product** (bukan satu movement gabungan), berisi `qty_base`, `unit_cost`, dan `total_cost` product tersebut, dengan `fiscal_period_id` period berjalan.
- Untuk metode FIFO, setiap baris opening juga membuat satu `stock_lots` dengan `received_date` = tanggal mulai period.
- Hak akses: initial opening hanya boleh diinput dan diposting oleh OWNER / SUPER ADMIN (Section 50). Kasir tidak berwenang karena nilai ini menjadi dasar HPP seluruh period.

### 40.3 Contoh

Toko "Sumber Makmur" pertama kali memakai sistem, membuka Fiscal Period FP-2026-01 (01-01-2026 s/d 31-01-2026).

| Product | Input | qty_base | Cost/base | Nilai |
|---|---|---|---|---|
| Indomie | 50 dus | 1.200 pcs | Rp2.500 | Rp3.000.000 |
| Aqua | 100 pcs | 100 pcs | Rp4.000 | Rp400.000 |
| **Total opening inventory** | | | | **Rp3.400.000** |

Opening cash: Rp1.000.000.

Movement yang terbentuk:

| Movement | Product | Tipe | qty_base | total_cost |
|---|---|---|---|---|
| 1 | Indomie | `OPENING` | 1.200 | Rp3.000.000 |
| 2 | Aqua | `OPENING` | 100 | Rp400.000 |

Nilai Rp3.400.000 dan qty 1.300 pcs adalah **agregat laporan**, bukan satu record movement — qty produk berbeda tidak boleh dijumlahkan dalam satu movement.

Stok tersebut menjadi opening qty/value pada closing snapshot period pertama.

### 40.4 Aturan

- Initial opening hanya boleh dilakukan saat period berstatus DRAFT, dan hanya untuk period pertama pada scope (owner + branch) tersebut.
- Setelah period OPEN dan sudah ada transaksi lain, koreksi opening hanya melalui stock adjustment (period OPEN) atau reopen (period CLOSED).
- Period kedua dan seterusnya mengambil opening otomatis dari closing snapshot period sebelumnya, tidak melalui initial opening.

---

## 41. Void & Cancel Transaksi

Sistem wajib mendukung pembatalan transaksi yang sudah POSTED.

### 41.1 Ruang Lingkup

- Void hanya untuk transaksi berstatus POSTED.
- Void hanya diizinkan pada period yang **sama dengan transaksi asli** dan berstatus OPEN.
- Void pada period CLOSING atau CLOSED ditolak; harus melalui reopen (Section 45).
- Transaksi VOID tidak lagi dihitung dalam closing, tetapi nomornya tetap terpakai (Section 52).
- Void wajib menyertakan alasan (`void_reason`) dan tercatat siapa/kapan.

**Void tidak sama dengan Delete:** delete hanya untuk data yang belum POSTED (DRAFT); void untuk transaksi yang sudah POSTED.

### 41.2 Pembalikan Stok & HPP

- Sistem **tidak menghapus** movement asli, melainkan membuat movement baru bertipe `VOID_REVERSAL`.
- Metode AVERAGE: qty dikembalikan dengan `unit_cost` = `cost_at_sale` yang tercatat pada transaksi asli, lalu average cost dihitung ulang untuk movement sesudahnya.
- Metode FIFO: qty dikembalikan ke **lot asal** sesuai konsumsi yang tercatat pada transaksi tersebut; `stock_lots.qty_remaining` dipulihkan dan status `EXHAUSTED` dikembalikan menjadi `ACTIVE` bila perlu.
- Seluruh movement dengan `sequence` lebih besar dari transaksi yang divoid **wajib di-recalculate**, dan prosesnya dicatat di `hpp_recalc_logs`.

Contoh: sales 5 pcs @ COGS Rp11.000 → void → stok kembali +5 pcs senilai Rp55.000, COGS transaksi tersebut tidak lagi dihitung di closing.

### 41.3 Pengembalian Pembayaran (Refund)

Refund dicatat pada **tanggal void**, bukan tanggal transaksi asli.

> Alasan: mencatat refund mundur ke tanggal transaksi asli akan mengubah saldo kas historis seluruh tanggal sesudahnya dan berpotensi membuat saldo tanggal yang sudah direkonsiliasi menjadi negatif. Aturan ini juga selaras dengan Section 47 (pencatatan mengikuti tanggal kejadian pembayaran).

Karena void hanya boleh pada period yang sama, tanggal void selalu berada di period yang sama dengan transaksi asli.

Nilai yang direfund adalah `paid_total` — uang yang benar-benar diterima — bukan `net_total`. Bila transaksi asli terkena pembulatan pembayaran (Section 51.4), selisih pembulatannya ikut dibalik.

Perlakuan per metode pembayaran:

| Metode bayar transaksi asli | Perlakuan saat void |
|---|---|
| Tunai (CASH) | Membuat `cash_transactions` bertipe `REFUND` (amount negatif) pada tanggal void. **Wajib cek saldo kas** (41.4). |
| Non-tunai (QRIS / DEBIT / KARTU / TRANSFER) | Membuat reversal pada ledger metode bayar terkait. Tidak menyentuh saldo kas laci, sehingga tidak dikenai cek saldo kas. |
| Kredit / piutang | Piutang (`receivables`) dibatalkan sebesar sisa yang belum dibayar. Porsi yang sudah dibayar direfund mengikuti metode bayar pembayaran tersebut (baris di atas). |
| Campuran (split payment) | Diperlakukan per porsi sesuai metodenya masing-masing. |

### 41.4 Cek Saldo Kas (khusus porsi tunai)

Sebelum void diproses, sistem memeriksa saldo kas branch pada tanggal void:

- Jika saldo kas < nilai refund tunai → **VOID DITOLAK**, dengan pesan berisi saldo tersedia dan kekurangannya.
- Cek ini hanya berlaku untuk porsi tunai. Void transaksi non-tunai atau kredit tidak boleh ditolak karena laci kosong.

Contoh:

| Kejadian | Tanggal | Nilai |
|---|---|---|
| Sales tunai | 20-01-2026 | Rp100.000 |
| Saldo kas branch | 22-01-2026 | Rp50.000 |
| Permintaan void sales di atas | 22-01-2026 | refund Rp100.000 |

Hasil: **VOID DITOLAK** — saldo kas 22-01-2026 hanya Rp50.000. Setelah ada cash in minimal Rp50.000 pada 22-01-2026, void dapat diproses dan saldo kas tanggal tersebut berkurang Rp100.000.

---

## 42. Koreksi Transaksi pada Period OPEN

Koreksi = perbaikan data transaksi yang sudah tercatat karena kesalahan input. Berbeda dengan void: void membatalkan seluruh transaksi, koreksi hanya memperbaiki sebagian data.

### 42.1 Jenis Koreksi

| Kasus | Contoh | Dampak |
|---|---|---|
| Salah harga | jual 10 pcs @ Rp3.500, seharusnya Rp3.600 | nilai penjualan & pajak |
| Salah qty | jual 5 pcs, seharusnya 3 pcs | stok, HPP, nilai penjualan |
| Salah metode bayar | cash, seharusnya QRIS | saldo kas & ledger metode bayar (tidak menyentuh qty/harga/stok) |
| Salah UOM | jual dalam pcs, seharusnya dus | `qty_base`, stok, HPP |

### 42.2 Aturan

- Koreksi pada transaksi **belum POSTED** (DRAFT): langsung diizinkan.
- Koreksi pada transaksi **sudah POSTED**: hanya melalui void + create ulang (Section 41), **kecuali** koreksi metode pembayaran yang diizinkan sebagai koreksi terbatas.
- Setiap koreksi yang mengubah qty/harga/UOM memicu recalculate HPP untuk seluruh movement dengan `sequence` sesudahnya, dicatat di `hpp_recalc_logs`.
- Koreksi backdated (transaksi disisipkan pada tanggal yang lebih awal) mengambil `sequence` sesuai urutan tanggal, bukan urutan input, lalu memicu recalculate movement sesudahnya.

### 42.3 Koreksi Metode Pembayaran

Koreksi metode bayar wajib membuat dua pencatatan bertanggal **tanggal koreksi**:
1. Pembalik pada ledger metode bayar lama.
2. Pencatatan baru pada metode bayar baru.

Jika koreksi menyebabkan uang tunai keluar dari laci (contoh: cash → QRIS, uang tunai dikembalikan ke pelanggan), berlaku cek saldo kas yang sama dengan Section 41.4.

---

## 43. Status CLOSING & Concurrency

### 43.1 Definisi Status Period

| Status | Arti |
|---|---|
| `DRAFT` | Period dibuat, belum menerima transaksi. |
| `OPEN` | Period aktif menerima transaksi. |
| `CLOSING` | Proses penutupan sedang berjalan. Semua transaksi baru ditolak otomatis. |
| `CLOSED` | Period terkunci (sampai reopen). |
| `REOPENED` | Period dibuka kembali setelah CLOSED, wajib ditutup ulang. |

### 43.2 Aturan Saat Period Berstatus CLOSING

Saat **period** masuk status `CLOSING`, sistem menolak:
- Create / Edit / Delete / Void semua transaksi pada period tersebut.
- Perubahan master yang memengaruhi HPP (harga beli, konversi UOM, metode costing).

### 43.3 Concurrency

- Seluruh proses closing berjalan dalam satu database transaction.
- Sebelum menghitung, sistem mengunci transaksi period tersebut (`SELECT ... FOR UPDATE`).
- Jika ada transaksi in-flight saat closing dimulai, closing menunggu maksimal **30 detik** (lock wait timeout). Jika melewati batas, closing dibatalkan, status kembali `OPEN`, dan kegagalan dicatat di `closing_attempt_logs`.
- Hanya boleh ada satu proses closing berjalan per fiscal period.

---

## 44. Recovery Closing Gagal

Jika proses closing gagal di tengah jalan:
- Semua perubahan di-rollback.
- Status period kembali ke `OPEN` (atau `REOPENED` bila closing tersebut adalah closing ulang).
- Sistem mencatat log kegagalan di `closing_attempt_logs`: step terakhir, error message, timestamp.

Retry:
- Closing dapat dijalankan ulang setelah error diperbaiki.
- Snapshot dari proses yang gagal tidak boleh dianggap valid dan tidak boleh terbaca oleh laporan.

Stale CLOSING:
- Period berstatus `CLOSING` lebih dari **15 menit** tanpa progres (tidak ada `closing_attempt_logs` baru) dianggap stale.
- Job recovery berjalan setiap 5 menit untuk mengembalikan period stale ke status sebelumnya dan mencatat kejadiannya di `fiscal_period_logs`.
- Period tidak boleh tertinggal di status `CLOSING` selamanya (Section 57).

---

## 45. Reopen Approval

### 45.1 Flow (perluasan Section 23)

```
CLOSED
   ↓
REOPEN REQUEST (OWNER / SUPER ADMIN) + alasan wajib
   ↓
VALIDASI
   ↓
APPROVAL
   ↓
REOPENED
```

### 45.2 Aturan Approval

- Reopen request wajib menyertakan alasan (free text).
- Request oleh **SUPER ADMIN** → disetujui oleh **OWNER**.
- Request oleh **OWNER** → disetujui oleh **OWNER lain** pada akun owner yang sama, bila ada.
- Bila owner hanya satu orang (tidak ada owner kedua), OWNER boleh menyetujui request-nya sendiri dengan syarat: alasan wajib diisi, approval ditandai `SELF_APPROVED` pada audit trail, dan notifikasi dikirim ke seluruh SUPER ADMIN.
- SUPER ADMIN tidak dapat menyetujui request milik OWNER.
- MANAGER dan KASIR tidak dapat mengajukan maupun menyetujui reopen.
- Tidak ada auto-approve oleh sistem.
- Setiap request dan approval dicatat dengan timestamp dan user.

> Catatan: aturan self-approve untuk owner tunggal ditambahkan agar alur tidak buntu. Pada versi sebelumnya, request milik OWNER tidak dapat disetujui oleh siapa pun.

### 45.3 Setelah REOPENED

- Recalculate stock dan HPP untuk seluruh period tersebut.
- Period wajib ditutup ulang (closing ulang).
- Snapshot lama ditandai `REPLACED` (Section 25) dan tidak lagi dipakai laporan.
- Bila period berikutnya sudah CLOSED, period tersebut juga harus di-reopen dan ditutup ulang karena opening-nya berubah. Sistem wajib menampilkan daftar period yang terdampak sebelum reopen dijalankan.

---

## 46. Penyelesaian Selisih Kas

Section 18 mencatat selisih kas, namun belum ada penyelesaiannya.

### 46.1 Aturan

- Selisih kas dicatat sebagai `cash_differences` dengan status `OPEN`.
- Selisih diselesaikan dengan salah satu cara:

| Resolusi | Kode | Keterangan |
|---|---|---|
| Adjustment kas | `CASH_ADJUSTMENT` | Menyesuaikan saldo kas sistem dengan fisik, wajib alasan |
| Dibebankan ke kasir | `CASHIER_CHARGE` | Kekurangan ditanggung kasir shift terkait (piutang karyawan) |
| Biaya lain | `OTHER_EXPENSE` | Kekurangan ditutup sebagai biaya |
| Pendapatan lain | `OTHER_INCOME` | Kelebihan diakui sebagai pendapatan lain |

- Selisih **tidak boleh dihapus**, hanya dapat ditandai `RESOLVED` beserta siapa/kapan/alasan.
- Selisih yang belum diselesaikan wajib ditampilkan pada report closing.
- Selisih yang masih `OPEN` tidak memblokir closing, tetapi wajib muncul sebagai peringatan pada pre-closing validation.

### 46.2 Hak Akses

Penyelesaian selisih kas dilakukan oleh MANAGER, SUPER ADMIN, atau OWNER (Section 50). Kasir yang bersangkutan tidak boleh menyelesaikan selisihnya sendiri.

---

## 47. Pembayaran Lintas Period

Piutang/utang dapat dibayar di period yang berbeda dari transaksi asal.

Aturan:
- Pembayaran selalu dicatat pada fiscal period berdasarkan **tanggal pembayaran** (`transaction_date`).
- Closing period pembayaran menghitung kas/pembayaran yang masuk pada period tersebut; nilai piutang/utang terbuka ditampilkan sebagai informasi (opening & ending), bukan sebagai komponen HPP.
- Tidak ada penyesuaian HPP karena pembayaran lintas period — HPP sudah diakui pada period penjualan.
- Pembayaran ditolak bila period pada tanggal pembayaran tidak ada atau tidak berstatus OPEN.

---

## 48. Return Lintas Period

Retur penjualan/pembelian dari transaksi period sebelumnya.

### 48.1 Aturan Umum

- Retur menggunakan fiscal period **tanggal retur**, bukan period transaksi asal.
- HPP retur dihitung dari cost yang tercatat pada transaksi asal (`sale_items.cost_at_sale`), bukan cost pada period retur.
- Qty retur tidak boleh melebihi qty transaksi asal dikurangi retur sebelumnya.

Contoh:

| Kejadian | Period | Qty | Cost/unit | Nilai |
|---|---|---|---|---|
| Sales | Januari | 10 pcs | Rp11.000 | Rp110.000 |
| Retur | Februari | 2 pcs | Rp11.000 (cost asal) | Rp22.000 |

### 48.2 Dampak ke Costing Berjalan

- **Retur penjualan (`RETURN_IN`)**: barang masuk kembali dinilai dengan cost asal. Pada metode AVERAGE, nilai tersebut ikut dalam perhitungan ulang average cost berjalan. Pada metode FIFO, dibuat lot baru dengan `unit_cost` = cost asal dan `received_date` = tanggal retur.
- **Retur pembelian (`RETURN_OUT`)**: mengurangi lot yang berasal dari **penerimaan** asal, bukan dari faktur (Section 58.6). Bila lot tersebut sudah habis terjual, retur ditolak dan harus diselesaikan melalui stock adjustment dengan persetujuan OWNER/SUPER ADMIN.

### 48.3 Bila Period Asal Sudah CLOSED

- Retur tetap diperbolehkan di period baru.
- Closing period baru mencatat retur beserta nilai HPP-nya.
- Period lama tidak berubah — snapshot tetap final.

---

## 49. Transfer Antar-Branch

### 49.1 Aturan

- Transfer dicatat di dua sisi: branch pengirim `TRANSFER_OUT`, branch penerima `TRANSFER_IN`.
- Setiap sisi menggunakan fiscal period masing-masing branch berdasarkan `transaction_date`.
- Transfer hanya boleh POSTED bila **kedua** branch memiliki period berstatus OPEN yang mencakup `transaction_date`. Bila salah satu sisi tidak punya period open, transfer ditolak.

Contoh: Branch A (FP Januari OPEN) → Branch B (FP Februari belum dibuka) → transfer ditolak. Setelah FP Februari dibuka, transfer dapat dibuat.

### 49.2 Tanpa State In-Transit (Tahap 1)

- Pada tahap 1 transfer bersifat langsung: saat POSTED, movement `TRANSFER_OUT` dan `TRANSFER_IN` dibuat bersamaan dalam satu database transaction. Status transfer hanya `DRAFT`, `POSTED`, `VOID`.
- Bila barang secara fisik baru tiba beberapa hari kemudian, gunakan tanggal terima sebagai `transaction_date` transfer.
- Status `IN_TRANSIT` dan akun "persediaan dalam perjalanan" masuk tahap lanjut (Section 66).

### 49.3 HPP Transfer

- Barang keluar dinilai sesuai metode costing branch pengirim.
- Barang masuk dicatat dengan nilai yang sama (cost transfer). Pada FIFO, branch penerima membuat lot baru dengan `unit_cost` = cost keluar branch pengirim.
- Tidak ada margin pada transfer antar-branch.

### 49.4 Void Transfer

- Void transfer hanya dapat dilakukan dari branch pengirim.
- Ditolak bila period salah satu sisi sudah CLOSING/CLOSED, atau bila stok yang diterima branch penerima sudah terjual/terpakai sehingga tidak dapat dibalik.

---

## 50. Role & Permission Matrix

Role: OWNER, SUPER ADMIN, MANAGER, KASIR, GUDANG.

| Aksi | OWNER | SUPER ADMIN | MANAGER | KASIR | GUDANG |
|---|---|---|---|---|---|
| Create / edit fiscal period | ✔ | ✔ | ✘ | ✘ | ✘ |
| Open fiscal period | ✔ | ✔ | ✘ | ✘ | ✘ |
| Initial opening (stok & kas awal) | ✔ | ✔ | input draft | ✘ | ✘ |
| Close fiscal period | ✔ | ✔ | ✔ | ✘ | ✘ |
| Reopen request | ✔ | ✔ | ✘ | ✘ | ✘ |
| Approve reopen | ✔ (lihat 45.2) | ✔ kecuali request OWNER | ✘ | ✘ | ✘ |
| Buat / ubah master lokasi (branch) | ✔ | ✔ | ✘ | ✘ | ✘ |
| Ubah `branch_type` / `can_sell` (Section 59) | ✔ | ✔ | ✘ | ✘ | ✘ |
| Transaksi penjualan & buka shift kasir | ✔ | ✔ | ✔ | ✔ | ✘ |
| Void transaksi | ✔ | ✔ | ✔ | ✘ | ✘ |
| Koreksi transaksi DRAFT | ✔ | ✔ | ✔ | ✔ (miliknya sendiri) | ✔ (miliknya sendiri) |
| Koreksi metode bayar (POSTED) | ✔ | ✔ | ✔ | ✘ | ✘ |
| Recalculate HPP | ✔ | ✔ | ✘ | ✘ | ✘ |
| Input stock opname | ✔ | ✔ | ✔ | ✔ | ✔ |
| Posting stock opname & adjustment | ✔ | ✔ | ✔ | ✘ | ✘ |
| Transfer antar-branch | ✔ | ✔ | ✔ | ✘ | ✔ |
| Resolve selisih kas | ✔ | ✔ | ✔ | ✘ | ✘ |
| Input & posting penerimaan barang | ✔ | ✔ | ✔ | ✔ (qty saja) | ✔ (qty saja) |
| Isi harga pada penerimaan barang | ✔ | ✔ | ✔ | ✘ | ✘ |
| Input & posting faktur pemasok | ✔ | ✔ | ✔ | ✘ | ✘ |
| Input biaya operasional (DRAFT) | ✔ | ✔ | ✔ | ✔ | ✘ |
| Posting & void biaya operasional | ✔ | ✔ | ✔ | ✘ | ✘ |
| Buat / ubah master diskon | ✔ | ✔ | ✘ | ✘ | ✘ |
| Beri diskon manual di luar master | ✔ | ✔ | ✔ | ✘ | ✘ |
| Atur pembulatan pembayaran (setting owner) | ✔ | ✔ | ✘ | ✘ | ✘ |
| Tinjau exception sinkronisasi offline | ✔ | ✔ | ✘ | ✘ | ✘ |
| Lihat audit log | ✔ | ✔ | ✘ | ✘ | ✘ |
| Ubah master yang memengaruhi HPP (UOM, costing, harga beli) | ✔ | ✔ | ✘ | ✘ | ✘ |
| Lihat stok & kartu stok | ✔ semua branch | ✔ semua branch | ✔ branch-nya | ✔ branch-nya | ✔ branch-nya |
| Lihat report closing | ✔ semua branch | ✔ semua branch | ✔ branch-nya | ✔ shift-nya sendiri | ✘ |

KASIR tidak dapat: membuat/mengubah/menutup/membuka fiscal period, melakukan initial opening, void transaksi, menyelesaikan selisih kas, atau melihat data owner/branch lain.

GUDANG tidak dapat menyentuh uang, harga, faktur pemasok, maupun fiscal period. Aksesnya terbatas pada pergerakan barang di lokasi yang ditugaskan: penerimaan, transfer, dan penghitungan opname — pengesahannya tetap di MANAGER ke atas.

Akses branch untuk MANAGER, KASIR, dan GUDANG dibatasi melalui `user_branch_access`.

Matrix di atas adalah batas atas. Lokasi dengan `can_sell` = FALSE (Section 59) menutup seluruh aksi penjualan dan kas di lokasi tersebut untuk **semua** role, termasuk OWNER.

---

## 51. Aturan Rounding & Presisi

### 51.1 Presisi Penyimpanan

| Jenis nilai | Presisi | Catatan |
|---|---|---|
| Nilai uang (total, harga, pajak) | DECIMAL(18,2) | Untuk IDR dibulatkan ke rupiah penuh (0 desimal) saat posting; 2 desimal disediakan untuk currency non-IDR (Section 54) |
| Cost per base unit | DECIMAL(18,4) | `cost_at_sale`, `unit_cost_base`, `stock_lots.unit_cost`, `avg_cost` |
| qty & qty_base | DECIMAL(18,4) | Validasi input mengikuti Section 39.6 |
| `conversion_factor` | DECIMAL(18,6) | |
| `exchange_rate` | DECIMAL(18,6) | |

Metode pembulatan: **round half up** untuk seluruh nilai.

### 51.2 Urutan Perhitungan (wajib konsisten)

1. `line_total` = round(qty × unit_price) − diskon baris (Section 61).
2. Diskon level transaksi dialokasikan proporsional ke setiap baris sebelum pajak dihitung.
3. Pajak dihitung **per baris** atas dasar pengenaan setelah seluruh diskon, dibulatkan per baris.
4. Total nota = penjumlahan baris yang sudah dibulatkan, bukan pembulatan ulang atas total mentah.

> Alasan: agar total pada struk selalu sama persis dengan penjumlahan baris yang tercetak.

### 51.3 Rounding pada HPP

- COGS per baris = round(`qty_base` × `cost_at_sale`) ke presisi nilai uang.
- Selisih antara total COGS yang dibulatkan dan nilai persediaan yang benar-benar keluar (presisi 4 desimal) diakumulasi **per produk per period** dan disimpan di `fiscal_closing_items.rounding_adjustment`.
- Bila selisih ≠ 0, proses closing membuat stock movement bertipe `ROUNDING` sebesar nilai selisih dengan qty = 0.
- COGS transaksi yang sudah POSTED **tidak boleh diubah** oleh proses closing — nilai pada struk dan laporan transaksi harus tetap sama setelah closing.

Contoh:

| Langkah | Nilai |
|---|---|
| Average cost per pcs | Rp3.333,3333 |
| 3 transaksi, masing-masing 1 pcs | COGS tercatat = round(3.333,3333) = Rp3.333 per transaksi |
| Total COGS tercatat | 3 × 3.333 = Rp9.999 |
| Nilai persediaan keluar (presisi 4 desimal) | 3 × 3.333,3333 = Rp9.999,9999 ≈ Rp10.000 |
| `rounding_adjustment` produk tersebut | Rp1 (movement `ROUNDING`) |

### 51.4 Pembulatan Pembayaran (Cash Rounding)

Toko sering tidak memegang pecahan kecil: nota Rp7.560 diterima Rp7.500. Ini **bukan diskon** dan tidak boleh dicatat sebagai diskon.

| | Diskon (Section 61) | Pembulatan pembayaran |
|---|---|---|
| Mengubah nilai penjualan (`net_total`) | Ya | **Tidak** |
| Mengubah dasar pengenaan pajak | Ya | **Tidak** |
| Ditentukan oleh | master promo atau MANAGER | otomatis oleh sistem |
| Akun | Potongan Penjualan | Selisih Pembulatan (pendapatan/beban lain-lain) |

Konfigurasi per owner:

| Setting | Isi |
|---|---|
| `payment_rounding_unit` | 0 = nonaktif, atau 100 / 500 / 1000 |
| `payment_rounding_mode` | `NEAREST` (default), `DOWN` (selalu ke bawah), `UP` (selalu ke atas) |

Aturan:
- Hanya berlaku untuk pembayaran **tunai**. Pembayaran non-tunai (QRIS, debit, transfer, e-wallet) dibayar persis sebesar `net_total`.
- `net_total`, `tax_total`, dan `discount_total` **tidak berubah**. Yang dicatat adalah:
  - `paid_total` = uang yang benar-benar diterima
  - `rounding_amount` = `paid_total` − `net_total` (bernilai + atau −)
- Kas yang masuk ke `cash_transactions` sebesar `paid_total`, bukan `net_total`.
- `|rounding_amount|` tidak boleh melebihi `payment_rounding_unit`. Pengaman ini mencegah pembulatan dipakai sebagai diskon terselubung.
- Kasir **tidak dapat** mengubah nilai pembulatan secara manual; nilainya dihitung sistem dari setting owner.
- Struk menampilkan baris "Pembulatan" terpisah dari baris "Diskon".
- Akumulasi per period disimpan di `fiscal_closings.payment_rounding_total`.
- Saat void, yang direfund adalah `paid_total` (Section 41.3).

Contoh (`payment_rounding_unit` = 100, mode `DOWN`):

| Item | Nilai |
|---|---|
| `net_total` (nilai penjualan resmi) | Rp7.560 |
| `paid_total` (uang diterima) | Rp7.500 |
| `rounding_amount` | −Rp60 |
| Kas masuk | Rp7.500 |
| Jurnal selisih | Beban Lain-lain — Selisih Pembulatan Rp60 |

Bila mode `UP`: `paid_total` Rp7.600, `rounding_amount` +Rp40, selisihnya masuk Pendapatan Lain-lain.

> Jangan tertukar dengan **rounding adjustment HPP** (Section 51.3): yang itu menyangkut nilai persediaan dan tidak pernah menyentuh kas.

---

## 52. Penomoran Transaksi

### 52.1 Format

```
[PREFIX]/[BRANCH_CODE]/[PERIOD_CODE]/[SEQUENCE]
```

Contoh: `INV/BR01/2026-01/000001`

| Bagian | Sumber | Keterangan |
|---|---|---|
| `PREFIX` | `document_numberings.doc_prefix` | INV, PUR, PAY, RTN, TRF, dst. |
| `BRANCH_CODE` | `branches.branch_code` | **Wajib** ada di format — sequence di-reset per (owner, branch, period), sehingga tanpa kode branch dua cabang akan mencetak nomor yang sama persis |
| `PERIOD_CODE` | `fiscal_periods.period_code` | Diambil apa adanya, bukan diasumsikan bulan — agar period non-bulanan (mingguan/custom) tetap valid |
| `SEQUENCE` | `document_numberings.current_sequence` | 6 digit dengan leading zero |

### 52.2 Aturan

- Nomor unik per scope: `owner_id` + `branch_id` + `fiscal_period_id` + `doc_prefix`.
- Sequence di-reset setiap fiscal period baru.
- Pengambilan nomor wajib memakai `SELECT ... FOR UPDATE` pada `document_numberings` agar tidak ganda saat kasir bertransaksi bersamaan.
- Nomor yang sudah terbit tidak boleh dipakai ulang, termasuk oleh transaksi yang divoid — nomor transaksi VOID tetap terpakai dan tidak diberikan ke transaksi baru.
- Nomor transaksi tidak berubah setelah POSTED.

---

## 53. Integrasi Akuntansi (GL/Journal)

Modul ini menghasilkan nilai akuntansi; sistem wajib menyiapkan jurnal dasar.

### 53.1 Jurnal Minimal

| Kejadian | Debit | Kredit |
|---|---|---|
| Penjualan tunai | Kas | Penjualan, PPN Keluaran |
| Penjualan kredit | Piutang Usaha | Penjualan, PPN Keluaran |
| Penjualan tunai, pembulatan ke bawah | Kas (`paid_total`), Selisih Pembulatan | Penjualan, PPN Keluaran |
| Penjualan tunai, pembulatan ke atas | Kas (`paid_total`) | Penjualan, PPN Keluaran, Selisih Pembulatan |
| Pengakuan HPP | HPP | Persediaan |
| Penerimaan barang (belum difakturkan) | Persediaan | Utang Belum Ditagih |
| Faktur pemasok | Utang Belum Ditagih, PPN Masukan | Utang Usaha |
| Pembelian tunai di tempat | Persediaan, PPN Masukan | Kas |
| Retur penjualan | Retur Penjualan, PPN Keluaran | Kas / Piutang Usaha |
| Retur penjualan (sisi stok) | Persediaan | HPP |
| Retur pembelian | Kas / Utang Usaha / Utang Belum Ditagih | Persediaan, PPN Masukan |
| Biaya operasional tunai | Biaya (sesuai kategori) | Kas |
| Biaya operasional non-tunai | Biaya (sesuai kategori) | Bank / Utang Usaha |
| Selisih kas kurang | Biaya Selisih Kas / Piutang Karyawan | Kas |
| Selisih kas lebih | Kas | Pendapatan Lain |
| Selisih pembulatan HPP (Section 51.3) | Selisih Pembulatan HPP | Persediaan (atau sebaliknya) |

### 53.2 Aturan

- Setiap fiscal period dapat di-export sebagai journal entry per closing.
- Map akun (chart of account) dikonfigurasi per owner (`chart_of_accounts`).
- Total debit wajib sama dengan total kredit per journal entry; divalidasi di service sebelum posting.
- Jika integrasi akuntansi di luar scope tahap 1, struktur data closing (Section 20–21) tetap wajib dapat di-export ke format jurnal di atas.

---

## 54. Multi-Currency

Opsional pada tahap 1, tetapi wajib diakomodasi di desain data.

Aturan:
- Setiap transaksi menyimpan `currency_code` dan `exchange_rate`.
- `exchange_rate` diambil dari `exchange_rates` pada `transaction_date`, disimpan di transaksi, dan **tidak berubah** setelah POSTED.
- Fiscal period memiliki satu currency dasar (`fiscal_periods.currency_code`, default IDR). Seluruh nilai closing disimpan dalam currency dasar tersebut; transaksi non-base dikonversi memakai kurs transaksi.
- **Tahap 1 tidak melakukan revaluasi** piutang/utang valas pada akhir period (tidak ada unrealized gain/loss).
- Selisih kurs yang terealisasi saat pembayaran diakui sebagai pendapatan/biaya selisih kurs pada period pembayaran.
- Bila owner hanya memakai satu mata uang: `currency_code` = IDR dan `exchange_rate` = 1 untuk seluruh transaksi.

---

## 55. Kebijakan Stok Negatif

### 55.1 Setting

- Diatur per owner: `owners.allow_negative_stock`, default `FALSE` (stok negatif ditolak saat transaksi).

### 55.2 Bila Stok Negatif Diizinkan

Transaksi tetap berjalan meski stok tidak mencukupi. Costing untuk qty yang melebihi stok tersedia:

| Metode | Perlakuan |
|---|---|
| AVERAGE | COGS memakai `stock_balances.avg_cost` terakhir. Bila `avg_cost` = 0 (belum pernah ada pembelian), memakai harga beli terakhir product tersebut; bila tetap tidak ada, COGS = 0 dan transaksi ditandai perlu koreksi. |
| FIFO | Qty yang melebihi lot tersedia dicatat sebagai lot negatif sementara dengan `unit_cost` = cost lot terakhir. Saat pembelian berikutnya masuk, sistem otomatis menutup kekurangan tersebut dan menghitung ulang COGS transaksi terkait (dicatat di `hpp_recalc_logs`). |

### 55.3 Saat Closing

- Stok negatif dilarang pada saat closing (pre-closing validation).
- Sistem menampilkan daftar produk bersaldo negatif beserta nilainya.
- Closing hanya dapat dilanjutkan setelah dilakukan adjustment/opname, atau setelah OWNER menyetujui secara eksplisit — persetujuan tersebut dicatat di `fiscal_period_logs`.

---

## 56. Acceptance Criteria

### 56.1 Validasi Transaksi
- Given transaksi bertanggal di luar range period → When submit → Then ditolak dengan pesan error.
- Given period berstatus CLOSED atau CLOSING → When submit transaksi → Then ditolak.
- Given period berstatus OPEN → When submit → Then diterima dan tersimpan dengan `fiscal_period_id` yang benar.

### 56.2 Overlap & Gap
- Given period baru overlap dengan period existing pada scope sama → When create → Then ditolak.
- Given tanggal transaksi jatuh pada gap antar period → When submit → Then ditolak.

### 56.3 UOM
- Given beli 5 dus (1 dus = 24 pcs) → Then stok bertambah 120 pcs dan `unit_cost_base` = total / 120.
- Given jual 10 pcs → Then stok berkurang 10 pcs, HPP = 10 × cost per pcs.
- Given jual 1 dus → Then stok berkurang 24 pcs, HPP = 24 × cost per pcs.
- Given product `is_weighted = FALSE` → When input qty 1,5 → Then ditolak.
- Given UOM sudah dipakai transaksi → When ubah `conversion_factor` → Then ditolak.

### 56.4 Initial Opening
- Given opening 2 produk → When simpan → Then terbentuk 2 movement `OPENING` terpisah, bukan 1 movement gabungan.
- Given period sudah OPEN dan sudah ada transaksi → When input initial opening → Then ditolak.

### 56.5 Void
- Given sales tunai Rp100.000 dan saldo kas pada tanggal void Rp50.000 → When void → Then ditolak dengan pesan kekurangan Rp50.000.
- Given sales QRIS Rp100.000 dan saldo kas Rp0 → When void → Then void berhasil (tidak dikenai cek saldo kas).
- Given sales kredit belum dibayar → When void → Then piutang dibatalkan dan tidak ada kas keluar.
- Given metode FIFO dan sales mengonsumsi lot A → When void → Then `qty_remaining` lot A kembali seperti semula.
- Given transaksi divoid → Then nomor transaksinya tidak dipakai ulang oleh transaksi baru.

### 56.6 Rounding
- Given 3 transaksi @1 pcs dengan average cost Rp3.333,3333 → Then COGS tiap transaksi Rp3.333 dan `rounding_adjustment` produk = Rp1.
- Given closing dijalankan → Then COGS transaksi yang sudah POSTED tidak berubah nilainya.
- Given `payment_rounding_unit` = 100 mode `DOWN` dan `net_total` Rp7.560 → When bayar tunai → Then `paid_total` Rp7.500, `rounding_amount` −Rp60, kas masuk Rp7.500, dan `net_total` tetap Rp7.560.
- Given transaksi di atas divoid → Then refund tunai Rp7.500, bukan Rp7.560.
- Given pembayaran QRIS → Then tidak ada pembulatan dan `paid_total` = `net_total`.
- Given nilai pembulatan melebihi `payment_rounding_unit` → When submit → Then ditolak.

### 56.7 Penomoran
- Given dua branch pada owner yang sama bertransaksi di period sama → Then nomor yang tercetak berbeda (mengandung kode branch).
- Given dua kasir menyimpan transaksi bersamaan → Then tidak ada nomor ganda.

### 56.8 Transfer Antar-Branch
- Given branch penerima tidak punya period open → When posting transfer → Then ditolak.
- Given transfer POSTED → Then terbentuk `TRANSFER_OUT` dan `TRANSFER_IN` dengan nilai cost yang sama.

### 56.9 Return Lintas Period
- Given sales Januari COGS Rp11.000/pcs dan retur 2 pcs di Februari → Then HPP retur = Rp22.000 dan snapshot Januari tidak berubah.
- Given retur melebihi qty transaksi asal → When submit → Then ditolak.

### 56.10 Closing & Reopen
- Given period dengan transaksi lengkap → When close → Then snapshot terbentuk, status CLOSED, transaksi baru ditolak.
- Given closing gagal di tengah → Then seluruh perubahan rollback dan status kembali OPEN.
- Given period berstatus CLOSING > 15 menit tanpa progres → Then job recovery mengembalikannya ke OPEN.
- Given OWNER request reopen + alasan → Then butuh approval sesuai Section 45.2, tercatat di audit, snapshot lama `REPLACED`.
- Given KASIR request reopen → Then ditolak.

### 56.11 Permission
- Given user role KASIR → When mengakses menu close period / void / initial opening → Then ditolak.
- Given kasir dengan `user_branch_access` branch A → When membuka report branch B → Then ditolak.

### 56.12 Stok Negatif
- Given `allow_negative_stock = FALSE` dan stok 3 pcs → When jual 5 pcs → Then ditolak.
- Given `allow_negative_stock = TRUE` dan stok 3 pcs → When jual 5 pcs → Then transaksi tersimpan, stok −2, COGS memakai cost sesuai Section 55.2.
- Given ada produk bersaldo negatif → When closing → Then muncul peringatan berisi daftar produk tersebut.

### 56.13 Biaya Operasional
- Given biaya tunai Rp200.000 berstatus POSTED → Then terbentuk `cash_transactions` `CASH_OUT` dan saldo kas tanggal tersebut berkurang Rp200.000.
- Given period sudah CLOSED → When input biaya bertanggal period tersebut → Then ditolak.
- Given closing dijalankan → Then `net_profit` = `gross_profit` − `expense_total`.
- Given biaya tunai dibayar dari laci kasir dengan `shift_id` terisi → Then expected cash shift tersebut ikut berkurang.

### 56.14 Diskon & Promo
- Given diskon `PERCENT` 10% dengan `max_discount_amount` Rp5.000 atas baris Rp100.000 → Then diskon yang dipakai Rp5.000, bukan Rp10.000.
- Given diskon level transaksi Rp10.000 atas dua baris Rp60.000 dan Rp40.000 → Then alokasi Rp6.000 dan Rp4.000 sebelum pajak dihitung.
- Given master diskon dinonaktifkan setelah transaksi POSTED → Then nilai diskon pada transaksi lama tidak berubah.
- Given KASIR memberi diskon manual di luar master → Then ditolak.
- Given diskon melebihi nilai baris → When submit → Then ditolak karena `line_total` tidak boleh negatif.

### 56.15 Audit Log
- Given OWNER mengubah `conversion_factor` sebuah UOM → Then terbentuk baris `audit_logs` berisi `old_value` dan `new_value`.
- Given satu record diubah pada tiga field sekaligus → Then terbentuk tiga baris audit, satu per field.
- Given proses aplikasi mencoba mengubah atau menghapus baris `audit_logs` → Then ditolak.

### 56.16 Ketahanan Koneksi (Tahap 1)
- Given server sudah menyimpan transaksi tetapi koneksi putus sebelum respons diterima → When perangkat mengirim ulang dengan `local_uuid` yang sama → Then tidak terbentuk transaksi kedua dan perangkat menerima data transaksi yang sudah tersimpan.
- Given kasir menyimpan transaksi tanpa koneksi → Then perangkat menampilkan kegagalan secara jelas, tidak boleh tampak seolah tersimpan.

### 56.17 Transaksi Offline (Tahap 2)
- Given kasir offline membuat 3 transaksi → When koneksi pulih → Then ketiganya tersinkron dan memperoleh nomor final dari `document_numberings`.
- Given paket sync yang sama dikirim dua kali → Then hanya terbentuk satu transaksi (idempotensi `device_id` + `local_uuid`).
- Given transaksi offline jatuh pada period yang sudah CLOSED → When sync → Then `REJECTED` dengan `error_code` `PERIOD_CLOSED` dan masuk daftar exception.
- Given transaksi offline bertanggal lebih awal dari movement yang sudah ada → When sync → Then `sequence` disisipkan sesuai tanggal dan HPP sesudahnya di-recalculate.
- Given perangkat tidak berhasil sinkron lebih dari 72 jam → Then aplikasi kasir menolak transaksi baru sampai koneksi pulih.

### 56.18 Penerimaan Barang & Faktur
- Given penerimaan 5 dus (1 dus = 24 pcs) diposting → Then stok bertambah 120 pcs dan terbentuk lot baru, sementara utang usaha belum berubah.
- Given faktur belum dibuat → Then nilai barang tersebut duduk di akun Utang Belum Ditagih.
- Given faktur diposting dengan harga berbeda dari cost sementara → Then HPP movement sesudah penerimaan dihitung ulang dan tercatat di `hpp_recalc_logs`.
- Given qty faktur melebihi qty diterima → When posting faktur → Then ditolak.
- Given KASIR membuka form penerimaan → Then kolom harga tidak ditampilkan.
- Given masih ada penerimaan tanpa faktur → When tutup periode → Then diblokir sampai difakturkan atau disetujui OWNER.
- Given penerimaan sudah difakturkan → When void penerimaan → Then ditolak sampai fakturnya dibatalkan lebih dulu.

### 56.19 Tipe Lokasi
- Given lokasi bertipe `WAREHOUSE` dengan `can_sell` = FALSE → When membuat transaksi penjualan atau membuka shift kasir → Then ditolak, apa pun role penggunanya.
- Given lokasi bertipe `WAREHOUSE` → When mencatat penerimaan barang, transfer, atau stock opname → Then diizinkan seperti lokasi lain.
- Given gudang belum memiliki fiscal period OPEN → When posting transfer dari toko ke gudang tersebut → Then ditolak (Section 49.1).
- Given nomor dokumen dicetak di gudang berkode `WH01` → Then nomornya mengandung `WH01` dan berurutan terpisah dari toko.
- Given user ber-role GUDANG → When membuka menu penjualan, kas, atau faktur pemasok → Then ditolak.
- Given user ber-role GUDANG → When posting stock opname → Then ditolak; input hitungan fisik tetap diizinkan.
- Given masih ada shift kasir terbuka di sebuah toko → When `can_sell` diubah menjadi FALSE → Then ditolak.
- Given laporan persediaan konsolidasi per owner → Then nilai stok gudang ikut terhitung.

---

## 57. Non-Functional Requirements

### 57.1 Performa
- Closing period dengan ≤ 100.000 transaksi selesai ≤ 5 menit.
- Simpan transaksi kasir ≤ 2 detik (p95) pada kondisi 20 kasir aktif bersamaan per branch.
- Pengambilan nomor transaksi tidak boleh menjadi bottleneck (lock per prefix, bukan per tabel).

### 57.2 Integritas
- Seluruh proses closing dan posting berjalan dalam database transaction.
- Snapshot closing tidak dapat diubah oleh proses normal (hanya melalui reopen yang menandai snapshot lama `REPLACED`).

### 57.3 Audit
- Semua perubahan status period tercatat (who, when, why) di `fiscal_period_logs`.
- Semua void, koreksi, recalculate HPP, dan penyelesaian selisih kas tercatat beserta alasannya.

### 57.4 Ketersediaan & Ketahanan Koneksi
- **Tahap 1 mewajibkan kasir online.** Mode offline penuh digeser ke tahap 2 (Section 63).
- Meski begitu, koneksi yang putus di tengah proses simpan tidak boleh membuat transaksi hilang maupun ganda:
  - Setiap permintaan simpan membawa `local_uuid` (UUID v4) yang dibuat perangkat kasir.
  - Server menjamin idempotensi: pengiriman ulang `local_uuid` yang sama mengembalikan transaksi yang sudah terbentuk, bukan membuat transaksi kedua.
  - Perangkat wajib menampilkan status yang jelas (tersimpan / gagal / perlu diulang); kegagalan tidak boleh tampak seperti keberhasilan.
- Target ketersediaan server pada jam operasional toko: 99,5% per bulan.

### 57.5 Recovery
- Kegagalan sistem di tengah closing tidak boleh meninggalkan period pada status `CLOSING` selamanya (auto-recovery sesuai Section 44).

### 57.6 Keamanan & Retensi
- Isolasi data antar-owner wajib dijamin di seluruh query (multi-tenant).
- Password disimpan sebagai hash, sesi memiliki masa berlaku.
- Data transaksi dan snapshot closing disimpan minimal 10 tahun mengikuti ketentuan perpajakan.
- Backup harian dengan uji restore berkala.

---

## 58. Penerimaan Barang & Faktur Pemasok

Sebelumnya satu dokumen pembelian mengurus kedatangan barang, pengakuan utang, dan penambahan stok sekaligus. Di toko, barang hampir selalu datang lebih dulu dan fakturnya menyusul — sering dengan jumlah yang tidak sama persis.

### 58.1 Alur

```
BARANG DATANG
   ↓
PENERIMAAN BARANG        ← dicatat kasir/counter, qty fisik saja
   ↓  stok bertambah, lot terbentuk
   ↓  jurnal: Persediaan (D) / Utang Belum Ditagih (K)
FAKTUR PEMASOK           ← dicatat admin, harga & pajak
   ↓  jurnal: Utang Belum Ditagih (D) / Utang Usaha (K)
PEMBAYARAN
```

Tahap 1 **tidak memakai dokumen pesanan (PO)** — pemesanan ke pemasok dianggap terjadi di luar sistem. Bila kelak dibutuhkan, PO disisipkan sebelum penerimaan tanpa mengubah dua dokumen di bawah.

### 58.2 Penerimaan Barang

- Dokumen `goods_receipts`, prefix penomoran `GRN` (Section 52).
- **Penerimaan inilah yang menambah stok**, bukan faktur. Saat POSTED, tiap baris membentuk stock movement `PURCHASE` dan satu lot baru.
- Fiscal period ditentukan dari **tanggal terima**, dan penerimaan ditolak bila period tersebut tidak OPEN.
- Kasir hanya mengisi **qty fisik yang diterima** beserta satuannya. Kolom harga tidak ditampilkan untuk KASIR dan tidak dapat diisi olehnya — konsisten dengan prinsip Section 51 bahwa kasir tidak menyentuh angka yang memengaruhi HPP.
- `unit_cost` diisi sistem sebagai **cost sementara**, berurutan: harga beli terakhir produk tersebut → `stock_balances.avg_cost` → 0 dengan baris ditandai perlu harga.

### 58.3 Faktur Pemasok

- Dokumen `purchases` (tetap), berisi harga, diskon, dan pajak. **Faktur tidak menambah stok.**
- Satu faktur dapat mencakup beberapa penerimaan. Penerimaan yang sudah difakturkan tidak dapat difakturkan ulang.
- Saat faktur POSTED: utang usaha diakui, PPN Masukan dicatat, dan Utang Belum Ditagih dilepas sebesar nilai penerimaan yang tercakup.
- Pembelian tunai di tempat (barang dan faktur datang bersamaan) tetap memakai dua dokumen, dibuat berurutan dalam satu proses.

### 58.4 Selisih Harga dan Qty

**Selisih harga.** Bila harga pada faktur berbeda dari cost sementara, sistem menghitung ulang HPP untuk seluruh movement sesudah penerimaan tersebut dan mencatatnya di `hpp_recalc_logs` — memakai mekanisme yang sama dengan koreksi backdated (Section 42.2).

**Selisih qty.**

| Kondisi | Perlakuan |
|---|---|
| Qty faktur < qty diterima | Selisih ditagihkan ke pemasok, atau diselesaikan lewat retur pembelian / stock adjustment |
| Qty faktur > qty diterima | Faktur ditolak sampai penerimaan susulan dicatat atau qty faktur dikoreksi |

Selisih wajib ditampilkan saat faktur diposting dan tidak boleh dilewati diam-diam.

### 58.5 Penerimaan yang Belum Difakturkan

- Boleh menggantung selama periode berjalan. Nilainya duduk di akun **Utang Belum Ditagih** (*Goods Received Not Invoiced*).
- Masuk **pemeriksaan pra-penutupan**: periode tidak dapat ditutup selama masih ada penerimaan tanpa faktur, kecuali OWNER menyetujui secara eksplisit dengan alasan yang tercatat di `fiscal_period_logs`.
- Daftarnya wajib tampil pada laporan closing beserta nilainya.

### 58.6 Retur dan Pembatalan

- Retur pembelian mengacu pada **penerimaan**, bukan faktur, karena lot persediaan lahir dari penerimaan (Section 48.2).
- Void penerimaan ditolak bila barangnya sudah terjual atau terpakai, atau bila penerimaan sudah difakturkan — fakturnya harus dibatalkan lebih dulu.
- Void faktur mengembalikan penerimaan terkait ke status belum difakturkan; stok tidak tersentuh.

### 58.7 Hak Akses

| Aksi | OWNER | SUPER ADMIN | MANAGER | KASIR | GUDANG |
|---|---|---|---|---|---|
| Input & posting penerimaan barang | ✔ | ✔ | ✔ | ✔ (qty saja) | ✔ (qty saja) |
| Melihat & mengisi harga pada penerimaan | ✔ | ✔ | ✔ | ✘ | ✘ |
| Input & posting faktur pemasok | ✔ | ✔ | ✔ | ✘ | ✘ |
| Void penerimaan atau faktur | ✔ | ✔ | ✔ | ✘ | ✘ |

Penerimaan barang dapat dibuat di lokasi bertipe apa pun, termasuk gudang (Section 59.2). Tidak ada kewajiban barang masuk gudang lebih dulu sebelum sampai ke toko.

---

## 59. Tipe Lokasi: Toko, Depo & Gudang

Sebelumnya seluruh lokasi diperlakukan sama: setiap baris `branches` dianggap toko yang menjual sekaligus menyimpan stok. Kenyataannya sebagian lokasi hanya menyimpan dan mendistribusikan barang tanpa pernah melayani pembeli.

Lokasi tetap disimpan pada **satu tabel** (`branches`). Gudang sengaja tidak dipisah ke tabel sendiri karena `branch_id` sudah menjadi kunci stok di seluruh tabel persediaan — memisahkannya akan memaksa dua FK nullable pada setiap tabel stok dan memecah Section 49 menjadi empat kombinasi arah transfer.

### 59.1 Tipe

| Tipe | Arti | Jual (POS) | Simpan stok | Terima dari pemasok | Transfer |
|---|---|---|---|---|---|
| `STORE` | Toko / outlet yang melayani pembeli | ✔ | ✔ | ✔ | ✔ |
| `DEPO` | Toko yang sekaligus menjadi titik distribusi ke lokasi lain | ✔ | ✔ | ✔ | ✔ |
| `WAREHOUSE` | Gudang murni, tidak melayani pembeli | ✘ | ✔ | ✔ | ✔ |

`DEPO` memiliki kemampuan yang **sama persis** dengan `STORE`. Pembedaannya untuk pengelompokan laporan dan penamaan, bukan pembatasan hak.

Kemampuan menjual disimpan pada kolom terpisah `branches.can_sell`, bukan diturunkan langsung dari tipe. Dengan begitu gudang yang sesekali melayani penjualan grosir dapat diizinkan tanpa mengubah tipenya, dan sebaliknya sebuah toko dapat dihentikan penjualannya sementara tanpa kehilangan riwayat.

### 59.2 Aturan

- `can_sell` bawaan: TRUE untuk `STORE` dan `DEPO`, FALSE untuk `WAREHOUSE`. Nilainya dapat diubah OWNER/SUPER ADMIN kapan saja dan perubahannya masuk audit log (Section 62).
- Bila `can_sell` = FALSE, lokasi tersebut **menolak**: pembuatan `sales` dan `sale_payments`, pembukaan `cashier_shifts`, pencatatan `cash_balances` dan `cash_differences`, serta biaya operasional yang dibayar dari laci kasir (Section 60). Penolakan dilakukan di service, bukan sekadar disembunyikan di UI.
- Mengubah `can_sell` dari TRUE menjadi FALSE ditolak selama masih ada shift kasir terbuka di lokasi tersebut.
- Seluruh lokasi — **termasuk gudang** — wajib memiliki fiscal period. Nilai persediaan gudang masuk laporan posisi keuangan, dan Section 49 mensyaratkan kedua sisi transfer memiliki period OPEN. Gudang yang periodenya lupa dibuka akan menolak seluruh kiriman masuk maupun keluar.
- Penutupan period gudang mengikuti aturan yang sama dengan toko, termasuk pemeriksaan penerimaan tanpa faktur (Section 58.5). Yang tidak berlaku bagi gudang: rekonsiliasi kas dan penyelesaian selisih kas (Section 46), karena tidak ada laci kasir.
- Penomoran dokumen tidak berubah. Gudang memakai `branch_code` sendiri, sehingga `GRN/WH01/2026-08/0001` dan `TRF/WH01/2026-08/0001` tetap mengikuti format Section 52.
- Pembelian dari pemasok **boleh masuk langsung ke lokasi mana pun**, termasuk toko. Tidak ada kewajiban barang singgah di gudang lebih dulu.
- Transfer antar-lokasi (Section 49) berlaku untuk semua kombinasi tipe tanpa aturan tambahan: gudang → toko, toko → gudang, maupun antar-gudang.

### 59.3 Role GUDANG

Role `GUDANG` untuk petugas yang mengurus barang tanpa menyentuh uang maupun harga.

| Aksi | GUDANG |
|---|---|
| Input & posting penerimaan barang (qty saja) | ✔ |
| Melihat atau mengisi harga | ✘ |
| Input & posting transfer antar-lokasi | ✔ |
| Input stock opname | ✔ |
| Posting stock opname & adjustment | ✘ |
| Melihat stok & kartu stok lokasinya | ✔ |
| Penjualan, kas, faktur pemasok, biaya operasional | ✘ |
| Fiscal period, void, recalculate HPP | ✘ |

- Akses GUDANG dibatasi ke lokasi tertentu lewat `user_branch_access`, sama seperti MANAGER dan KASIR.
- GUDANG dapat ditempatkan di lokasi bertipe apa pun. Toko besar yang punya petugas gudang sendiri memakai role ini tanpa perlu memberi akses kasir.
- Posting stock opname tetap di MANAGER ke atas: GUDANG menghitung fisik, atasannya yang mengesahkan selisih.

### 59.4 Laporan

- Laporan penjualan, shift, dan kas **tidak menampilkan** lokasi dengan `can_sell` = FALSE.
- Laporan persediaan, kartu stok, mutasi, dan opname **wajib menyertakan** gudang.
- Laporan konsolidasi per owner (posisi keuangan, laba rugi) wajib mencakup seluruh lokasi termasuk gudang. Mengecualikan gudang akan menghilangkan nilai persediaan dari pembukuan.

---

## 60. Biaya Operasional (Expense)

Tanpa pencatatan biaya, closing hanya menghasilkan laba kotor (penjualan − HPP). Laba bersih per period tidak dapat dihitung, padahal itulah angka yang dicari pemilik toko.

### 60.1 Struktur Data

`expense_categories`:

| Field | Keterangan |
|---|---|
| `expense_category_id` | PK |
| `owner_id` | pemilik kategori |
| `category_name` | Listrik / Gaji / Sewa / Transport / Perlengkapan |
| `account_id` | map ke `chart_of_accounts` (opsional, untuk export jurnal) |
| `active` | |

`expenses`:

| Field | Keterangan |
|---|---|
| `expense_number` | mengikuti format penomoran Section 52 (prefix `EXP`) |
| `fiscal_period_id` | ditentukan dari `transaction_date` |
| `branch_id`, `shift_id` | shift diisi bila biaya dibayar dari laci kasir |
| `expense_category_id` | wajib |
| `amount`, `payment_type` | nilai dan cara bayar |
| `status` | `DRAFT` / `POSTED` / `VOID` |

### 60.2 Aturan

- Fiscal period biaya ditentukan dari `transaction_date`; ditolak bila period tidak berstatus OPEN.
- Biaya berstatus POSTED dengan `payment_type = CASH` **wajib** membuat `cash_transactions` bertipe `CASH_OUT` dengan `reference_type = 'EXPENSE'`. Konsekuensinya biaya tunai ikut mengurangi saldo kas, sehingga memengaruhi cek saldo pada Section 41.4.
- Bila `shift_id` diisi, biaya tunai ikut mengurangi expected cash shift tersebut.
- Void biaya mengikuti aturan Section 41: hanya pada period yang sama, dan pembalikan kas dicatat pada tanggal void.
- Biaya operasional **tidak memengaruhi HPP** dan tidak masuk nilai persediaan.

### 60.3 Dampak ke Closing

`fiscal_closings` menyimpan:

| Kolom | Isi |
|---|---|
| `gross_profit` | `net_sales − cogs_value` |
| `expense_total` | total biaya POSTED pada period tersebut |
| `net_profit` | `gross_profit − expense_total` |

Rincian biaya per kategori tidak di-snapshot terpisah — diambil langsung dari tabel `expenses`, yang sudah tidak dapat berubah begitu period CLOSED.

---

## 61. Diskon & Promo

### 61.1 Struktur Data

`discounts` menyimpan master diskon:

| Field | Keterangan |
|---|---|
| `discount_scope` | `ITEM` (per baris) atau `TRANSACTION` (per nota) |
| `discount_type` | `PERCENT` atau `AMOUNT` |
| `discount_value` | nilai persen atau rupiah |
| `max_discount_amount` | batas atas untuk tipe `PERCENT` |
| `product_id` / `category_id` | pembatas sasaran; NULL = berlaku semua |
| `min_qty` / `min_amount` | syarat minimum |
| `start_date` / `end_date` / `active` | masa berlaku |

### 61.2 Aturan

- Nilai diskon yang benar-benar dipakai **disimpan sebagai angka** di `sale_items.discount_amount` dan `sales.discount_total`. `discount_id` hanya referensi asal — mengubah atau menonaktifkan master diskon tidak boleh mengubah histori transaksi.
- Diskon level transaksi dialokasikan **proporsional** ke setiap baris sebelum pajak dihitung (Section 51.2), agar dasar pengenaan pajak per baris tetap benar.
- Diskon **tidak memengaruhi HPP**. COGS tetap dihitung dari cost persediaan, bukan dari harga jual setelah diskon.
- Diskon tidak boleh membuat `line_total` atau `net_total` menjadi negatif.
- Validasi saat dipakai: masa berlaku mencakup `transaction_date`, `active = TRUE`, syarat `min_qty`/`min_amount` terpenuhi, dan hasil tipe `PERCENT` dibatasi `max_discount_amount`.
- Diskon manual (tanpa master, `discount_id` NULL) hanya boleh diberikan MANAGER ke atas dan wajib tercatat di audit log (Section 62). KASIR hanya dapat memakai diskon dari master yang aktif.
- KASIR **tidak boleh** memakai diskon untuk menutupi keterbatasan uang pecahan. Kasus "Rp7.560 diterima Rp7.500" ditangani sebagai pembulatan pembayaran (Section 51.4) yang tidak mengurangi nilai penjualan maupun pajak — bukan sebagai diskon.

---

## 62. Audit Log & Jejak Perubahan

### 62.1 Pembagian Log

| Tabel | Mencatat |
|---|---|
| `fiscal_period_logs` | perubahan status fiscal period (open, closing, closed, reopen) |
| `hpp_recalc_logs` | proses recalculate HPP beserta pemicunya |
| `closing_attempt_logs` | jalannya proses closing per step, untuk recovery |
| `audit_logs` | seluruh perubahan lain, terutama pada master |

### 62.2 Yang Wajib Masuk `audit_logs`

- Perubahan master yang memengaruhi HPP: harga beli/jual, `conversion_factor` UOM, `costing_method`, `allow_negative_stock`.
- Void dan koreksi transaksi, beserta alasannya.
- Request, approval, dan penolakan reopen (termasuk `SELF_APPROVED`).
- Penyelesaian selisih kas.
- Pemberian diskon manual di luar master.
- Perubahan role dan hak akses user, termasuk `user_branch_access`.

### 62.3 Aturan

- `audit_logs` bersifat **append-only**: tidak boleh diubah maupun dihapus oleh proses aplikasi.
- Setiap baris menyimpan `user_id`, `entity_type`, `entity_id`, `field_name`, `old_value`, `new_value`, `reason`, `ip_address`, dan `created_at`.
- Perubahan satu record pada banyak field dicatat sebagai beberapa baris (satu baris per field), agar dapat ditelusuri per kolom.
- Hanya OWNER dan SUPER ADMIN yang dapat melihat audit log.
- Retensi mengikuti Section 57.6.

---

## 63. Transaksi Offline: Antrian & Sinkronisasi (Tahap 2)

> **Status: tahap 2.** Tahap 1 mewajibkan kasir online (Section 57.4). Rancangannya ditulis lebih dulu — dan tabel `offline_sync_queue` beserta kolom `sales.device_id` / `sales.local_uuid` sudah disiapkan di schema — supaya penambahannya nanti tidak membongkar struktur data yang sudah berjalan.

Toko retail sering kehilangan koneksi. Pada tahap 2, kasir tidak perlu berhenti melayani hanya karena server tidak terjangkau.

### 63.1 Prinsip

- Hanya **penjualan dan pembayarannya** yang boleh dibuat offline. Pembelian, retur, transfer, stock opname, dan seluruh operasi fiscal period **wajib online**.
- Perhitungan HPP tidak dilakukan di perangkat kasir, melainkan saat sinkronisasi di server.

### 63.2 Alur

```
KASIR OFFLINE
   ↓  simpan lokal: local_uuid (UUID v4) + nomor sementara OFF/[BRANCH]/[DEVICE]/[SEQ]
KONEKSI PULIH
   ↓  kirim ke offline_sync_queue (status PENDING)
VALIDASI SERVER
   ↓  period OPEN? stok cukup? data valid?
SYNCED  →  transaksi final + nomor dari document_numberings
   atau
REJECTED  →  masuk daftar exception, ditinjau OWNER / SUPER ADMIN
```

### 63.3 Idempotensi

- `offline_sync_queue` memakai UNIQUE (`device_id`, `local_uuid`), dan `sales` memakai UNIQUE (`branch_id`, `local_uuid`).
- Pengiriman ulang paket yang sama **tidak boleh** menghasilkan transaksi kedua; server mengembalikan hasil sync sebelumnya.

### 63.4 Penolakan saat Sync

| Kondisi | `error_code` | Perlakuan |
|---|---|---|
| Period tujuan CLOSING/CLOSED | `PERIOD_CLOSED` | Ditolak. Tidak boleh dipaksa masuk ke period yang sudah ditutup; wajib ditinjau OWNER/SUPER ADMIN |
| Stok tidak cukup dan `allow_negative_stock = FALSE` | `STOCK_NEGATIVE` | Ditolak, diselesaikan lewat adjustment lalu input ulang |
| Data tidak valid (produk/UOM nonaktif, dsb.) | `VALIDATION` | Ditolak dengan pesan spesifik |

Harga jual dan diskon memakai nilai yang tersimpan di payload (kondisi saat transaksi terjadi), bukan harga terbaru saat sync.

### 63.5 Urutan & HPP

- Transaksi hasil sync diperlakukan seperti transaksi backdated (Section 42.2): `sequence` diambil sesuai `local_created_at`, bukan urutan sync.
- Bila ada movement lain yang lebih baru, sistem menjalankan recalculate HPP dan mencatatnya di `hpp_recalc_logs`.

### 63.6 Batas Waktu Offline

- Perangkat wajib berhasil sinkron minimal setiap **72 jam**. Melewati batas itu, aplikasi kasir berhenti menerima transaksi baru sampai koneksi pulih.
- Tujuannya mencegah transaksi tertahan sampai period-nya keburu ditutup, yang berakhir menjadi exception.

---

## 64. Glossary

| Istilah | Arti |
|---|---|
| Base UOM | Satuan dasar penyimpanan stok |
| Pack UOM | Satuan turunan dengan konversi ke base |
| HPP / COGS | Harga Pokok Penjualan / Cost of Goods Sold |
| Fiscal Period | Periode akuntansi/operasional pengelompokan transaksi |
| Closing Snapshot | Hasil akhir perhitungan period yang tidak dapat berubah |
| Lot | Batch persediaan dengan cost tersendiri, dasar FIFO |
| Reopen | Proses membuka kembali period yang sudah CLOSED |
| Void | Pembatalan transaksi yang sudah POSTED |
| Koreksi | Perbaikan sebagian data transaksi tanpa membatalkan seluruhnya |
| Round Half Up | Pembulatan 0,5 ke atas |
| Rounding Adjustment | Selisih akumulasi akibat pembulatan HPP, dicatat di closing |
| Expense | Biaya operasional yang tidak menambah nilai persediaan |
| Laba Bersih | `gross_profit` − `expense_total` pada satu fiscal period |
| Audit Log | Catatan append-only perubahan data di luar log period dan log HPP |
| Idempotensi | Jaminan bahwa pengiriman ulang data yang sama tidak menghasilkan transaksi ganda |
| `local_uuid` | Id transaksi yang dibuat perangkat kasir, kunci idempotensi retry dan sync |
| Pembulatan Pembayaran | Penyesuaian jumlah uang tunai yang diterima ke pecahan terdekat; tidak mengurangi nilai penjualan |
| Selisih Pembulatan | Akun penampung selisih pembulatan pembayaran (pendapatan atau beban lain-lain) |
| Lokasi | Satu baris `branches`; dapat berupa toko, depo, atau gudang |
| Depo | Toko yang sekaligus menjadi titik distribusi ke lokasi lain; kemampuannya sama dengan toko |
| Gudang | Lokasi yang hanya menyimpan dan memindahkan barang, tidak melayani penjualan (`can_sell` = FALSE) |
| Utang Belum Ditagih | Akun penampung nilai barang yang sudah diterima tetapi fakturnya belum masuk (*Goods Received Not Invoiced*) |
| Harga Standar | `product_uoms.selling_price` — harga jual bawaan tiap satuan, dipakai bila tidak ada daftar harga yang cocok |
| Daftar Harga | Kumpulan harga jual bernama dan berjangka waktu yang menimpa harga standar (Section 39.10) |
| Tingkat Harga | Baris daftar harga dengan `min_qty`; harga berubah begitu kuantitas mencapai ambang tersebut |

---

## 65. Changelog

| Versi | Status | Isi |
|---|---|---|
| 1.2 | Draft | PRD utama (`prd.md`, Section 1–37) |
| 1.3 | Draft | Addendum (file ini) — Section 38–60: metode costing, konversi satuan (UOM), initial opening, void, koreksi, concurrency closing, recovery, reopen approval, selisih kas, transaksi lintas period, transfer antar-branch, permission matrix, rounding, penomoran, GL, multi-currency, stok negatif, acceptance criteria, NFR, glossary |
| 1.3 Rev.6 | Draft — 20 Agu 2026 | Menambah **Section 39.10–39.12 Daftar Harga Jual**: tabel `price_lists` dan `price_list_items` sebagai lapisan override di atas `product_uoms.selling_price`, dengan harga melekat pada satuan dan bertingkat lewat `min_qty` (Rinso 1 dus 20.000 / 1 pcs 2.500 / ≥6 pcs 2.300). Ditetapkan urutan pemilihan harga pelanggan → lokasi → owner → default → harga standar, tanpa konversi harga otomatis antar satuan. Sengaja **tidak** dibuat section baru agar penomoran tidak bergeser untuk ketiga kalinya; harga per satuan adalah kelanjutan langsung Section 39. Schema: `price_lists`, `price_list_items`, `sale_items.price_list_id`, `customers.price_list_id`. **Schema Section 58 akhirnya disinkronkan** (utang sejak Rev.4): tabel `goods_receipts` dan `goods_receipt_items`, `stock_lots.purchase_item_id` menjadi `receipt_item_id`, `reference_type` `RECEIPT` pada `stock_movements`, akun GRNI masuk seeder `chart_of_accounts` |
| 1.3 Rev.5 | Draft — 20 Agu 2026 | Menambah **Section 59 Tipe Lokasi: Toko, Depo & Gudang**. `branches` mendapat `branch_type` (`STORE` / `DEPO` / `WAREHOUSE`) dan `can_sell`, sehingga sebuah lokasi dapat menyimpan dan memindahkan barang tanpa melayani penjualan. Gudang tetap wajib memiliki fiscal period karena nilai persediaannya masuk pembukuan. Depo diperlakukan sama dengan toko. Pembelian boleh masuk langsung ke lokasi mana pun. Ditambah role **GUDANG** untuk petugas barang yang tidak menyentuh uang maupun harga. Section 59–65 lama bergeser menjadi 60–66 beserta rujukan silangnya. Ikut menyesuaikan: matrix hak akses (50), hak akses penerimaan (58.7), acceptance criteria (56.19), glossary (64). Schema: kolom `branches.branch_type` dan `branches.can_sell`, nilai `GUDANG` pada `users.role` |
| 1.3 Rev.4 | Draft — 19 Agu 2026 | Menambah **Section 58 Penerimaan Barang & Faktur Pemasok**: stok kini bertambah saat penerimaan, bukan saat faktur; penerimaan dapat dicatat kasir dengan qty saja; selisih harga memicu recalculate HPP; penerimaan tanpa faktur duduk di akun Utang Belum Ditagih dan memblokir penutupan periode. Section 58–64 lama bergeser menjadi 59–65 beserta seluruh rujukan silangnya. Ikut menyesuaikan: jurnal (54.1), retur pembelian (48.2), matrix hak akses (50), acceptance criteria (56.18). Schema: tabel `goods_receipts` dan `goods_receipt_items`, `stock_lots.purchase_item_id` menjadi `receipt_item_id` |
| 1.3 Rev.3 | Draft — 19 Agu 2026 | Penutupan Section 65: mode offline digeser ke tahap 2 (57.4 diganti jadi ketahanan koneksi + idempotensi `local_uuid`; 61 ditandai tahap 2), kasir dipastikan tidak boleh memberi diskon, dan kekurangan uang pecahan ditangani lewat mekanisme baru **pembulatan pembayaran** (51.4) yang tidak mengurangi nilai penjualan maupun pajak. Schema: `owners.payment_rounding_unit/mode`, `sales.rounding_amount/paid_total`, `fiscal_closings.payment_rounding_total` |
| 1.3 Rev.2 | Draft — 19 Agu 2026 | Penambahan cakupan: biaya operasional (58), diskon & promo (59), audit log (60), transaksi offline (61); Glossary/Changelog/Open Items bergeser ke 62/63/64. Schema disesuaikan: tabel baru `stock_lot_consumptions`, `expense_categories`, `expenses`, `discounts`, `audit_logs`, `offline_sync_queue`; kolom baru `branches.branch_code`, `stock_lots.fiscal_period_id`, `fiscal_period_reopen_requests.approval_type`, `sales.discount_id/device_id/local_uuid`, `sale_items.discount_id`, `fiscal_closings.expense_total/net_profit`; `payment_type` CREDIT → CREDIT_CARD |
| 1.3 Rev.1 | Draft — 19 Agu 2026 | Perbaikan konsistensi: deadlock approval reopen (45.2), tanggal & cakupan refund void (41.3–41.4), reversal lot FIFO (41.2), movement `OPENING` per produk (40.2), rounding adjustment tidak lagi dibebankan ke item terakhir (51.3), presisi disamakan dengan schema (51.1), kode branch pada nomor transaksi (52.1), HPP untuk stok negatif (55.2), aturan in-transit & void transfer (49.2, 49.4), dampak retur ke costing berjalan (48.2), kurs & revaluasi multi-currency (54), jurnal pajak (53.1), timeout closing & stale recovery (43.3, 44), NFR mode offline (57.4), matrix permission dilengkapi (50), acceptance criteria diperluas (56), perbaikan format tabel/flow, penyeragaman versi dokumen |

---

## 66. Keputusan & Batasan Tahap

Seluruh butir yang sebelumnya terbuka sudah diputuskan. Bagian ini menjadi catatan keputusan, bukan daftar pertanyaan.

### 66.1 Keputusan Tahap 1

| Butir | Keputusan |
|---|---|
| Costing method | Per owner, di-snapshot ke fiscal period. Kolom `branches.costing_method` sengaja tidak dibuat |
| Approval reopen owner tunggal | Self-approve diizinkan, ditandai `SELF_APPROVED` + notifikasi ke seluruh SUPER ADMIN |
| Diskon manual oleh KASIR | Tidak diizinkan. Keterbatasan uang pecahan ditangani lewat pembulatan pembayaran (Section 51.4), bukan diskon |
| Rincian biaya per kategori di closing | Tidak di-snapshot; diambil dari tabel `expenses` yang sudah tidak dapat berubah setelah period CLOSED |
| Revaluasi valas akhir period | Tidak dilakukan. Hanya selisih kurs terealisasi saat pembayaran yang diakui |
| Stok negatif pada FIFO | Diizinkan bila `owners.allow_negative_stock` = TRUE, memakai lot negatif sementara (Section 55.2) |
| Gudang sebagai tabel terpisah | Tidak. Gudang adalah `branches` bertipe `WAREHOUSE`. Memisahkannya akan memaksa dua FK nullable di seluruh tabel stok dan memecah Section 49 menjadi empat kombinasi arah |
| Depo | Diperlakukan sama dengan toko dan tetap boleh menjual. Tipe hanya untuk pengelompokan laporan dan penamaan |
| Kemampuan menjual | Disimpan di kolom `can_sell` yang terpisah dari `branch_type`, agar gudang yang sesekali melayani grosir tidak perlu berganti tipe |
| Penerimaan wajib lewat gudang | Tidak. Pembelian dari pemasok boleh masuk langsung ke lokasi mana pun, termasuk toko |
| Petugas gudang | Role tersendiri `GUDANG`, bukan KASIR yang dibatasi — agar larangan melihat harga melekat pada role, bukan pada tipe lokasi |
| Fiscal period gudang | Wajib, sama seperti toko. Yang tidak berlaku hanya rekonsiliasi dan selisih kas |

### 66.2 Digeser ke Tahap 2

| Butir | Alasan | Yang sudah disiapkan di schema |
|---|---|---|
| Mode offline kasir (Section 63) | Beban kerja aplikasi kasir paling besar di seluruh dokumen; tahap 1 wajib online (Section 57.4) | `offline_sync_queue`, `sales.device_id`, `sales.local_uuid` |
| Batas maksimum durasi offline | Mengikuti butir di atas | — |
| Transfer `IN_TRANSIT` (Section 49.2) | Tahap 1 memakai transfer langsung dua sisi | — |
| Costing `SPECIFIC` (Section 38) | Kebutuhan lanjutan, jarang dipakai toko retail | `costing_method` sudah menerima nilai `SPECIFIC` |

### 66.3 Angka yang Ditetapkan saat Setup

| Butir | Nilai bawaan | Catatan |
|---|---|---|
| `payment_rounding_unit` | 0 (nonaktif) | Diisi saat setup toko: 100 / 500 / 1000 |
| `payment_rounding_mode` | `NEAREST` | Pakai `DOWN` bila toko selalu membulatkan ke bawah |
| Lock wait closing (Section 43.3) | 30 detik | Tinjau ulang setelah uji beban |
| Ambang stale CLOSING (Section 44) | 15 menit | Tinjau ulang setelah uji beban |
| Retensi data (Section 57.6) | 10 tahun | Mengikuti ketentuan perpajakan |

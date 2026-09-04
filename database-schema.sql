-- ============================================================================
-- Database Schema — Aplikasi POS Versi 1.3
-- Berdasarkan: prd-addendum.md (Section 38-66)
-- Database: MySQL 8.0+
-- Konvensi: snake_case, InnoDB, utf8mb4
-- Catatan:
--   - Lokasi dengan branches.can_sell = FALSE (gudang) menolak sales,
--     sale_payments, cashier_shifts, cash_balances, dan cash_differences.
--     Aturan ini ditegakkan di service, bukan constraint — MySQL tidak dapat
--     memeriksa kolom tabel lain di dalam CHECK (Addendum 59.2).
--   - Validasi overlap fiscal period dilakukan di service (FiscalPeriodService,
--     PRD Section 33). Database tidak men-cover overlap karena butuh rule scope
--     (owner vs branch) yang kompleks.
--   - Pengambilan nomor transaksi (document_numberings) WAJIB pakai row lock
--     SELECT ... FOR UPDATE agar tidak ada nomor ganda (Addendum 52).
--   - Keseimbangan jurnal (total debit = total credit) dijamin di service
--     atau trigger; tabel hanya menyimpan baris (Addendum 53).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS mypos
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE mypos;

-- ============================================================================
-- 1. MASTER
-- ============================================================================

CREATE TABLE owners (
    owner_id            BIGINT NOT NULL AUTO_INCREMENT,
    owner_name          VARCHAR(150) NOT NULL,
    default_currency    CHAR(3) NOT NULL DEFAULT 'IDR',
    costing_method      VARCHAR(20) NOT NULL DEFAULT 'AVERAGE',
    allow_negative_stock BOOLEAN NOT NULL DEFAULT FALSE,
    -- Pembulatan pembayaran tunai (Addendum 51.4). 0 = nonaktif.
    payment_rounding_unit INT NOT NULL DEFAULT 0, -- 100 / 500 / 1000
    payment_rounding_mode VARCHAR(10) NOT NULL DEFAULT 'NEAREST', -- NEAREST | DOWN | UP
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (owner_id),
    CONSTRAINT chk_owners_costing CHECK (costing_method IN ('AVERAGE', 'FIFO', 'SPECIFIC')),
    CONSTRAINT chk_owners_round_unit CHECK (payment_rounding_unit >= 0),
    CONSTRAINT chk_owners_round_mode CHECK (payment_rounding_mode IN ('NEAREST', 'DOWN', 'UP'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Satu tabel untuk seluruh lokasi: toko, depo, dan gudang (Addendum 59).
-- Gudang sengaja tidak dipisah ke tabel sendiri karena branch_id sudah menjadi
-- kunci stok di seluruh tabel persediaan.
CREATE TABLE branches (
    branch_id           BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    branch_code         VARCHAR(10) NOT NULL, -- dipakai pada nomor transaksi (Addendum 52)
    branch_name         VARCHAR(150) NOT NULL,
    branch_type         VARCHAR(20) NOT NULL DEFAULT 'STORE', -- STORE | DEPO | WAREHOUSE
    -- Kemampuan menjual sengaja dipisah dari branch_type: gudang yang sesekali
    -- melayani grosir cukup diubah flag-nya tanpa berganti tipe (Addendum 59.1).
    -- Bawaan: TRUE untuk STORE/DEPO, FALSE untuk WAREHOUSE — diisi aplikasi saat
    -- lokasi dibuat, tidak dikunci CHECK agar pengecualian tetap mungkin.
    can_sell            BOOLEAN NOT NULL DEFAULT TRUE,
    address             TEXT,
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (branch_id),
    UNIQUE KEY uk_branches_code (owner_id, branch_code),
    CONSTRAINT fk_branches_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT chk_branches_type CHECK (branch_type IN ('STORE', 'DEPO', 'WAREHOUSE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Username unik per owner (multi-tenant), bukan global
CREATE TABLE users (
    user_id             BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    branch_id           BIGINT NULL, -- NULL = akses semua branch
    username            VARCHAR(100) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    full_name           VARCHAR(150) NOT NULL,
    role                VARCHAR(20) NOT NULL,
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uk_users_username (owner_id, username),
    CONSTRAINT fk_users_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    -- GUDANG: petugas barang, tidak menyentuh uang maupun harga (Addendum 59.3)
    CONSTRAINT chk_users_role CHECK (role IN ('OWNER', 'SUPER_ADMIN', 'MANAGER', 'KASIR', 'GUDANG'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Scope akses kasir ke branch tertentu (Addendum 50)
CREATE TABLE user_branch_access (
    access_id           BIGINT NOT NULL AUTO_INCREMENT,
    user_id             BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    PRIMARY KEY (access_id),
    UNIQUE KEY uk_user_branch (user_id, branch_id),
    CONSTRAINT fk_uba_user FOREIGN KEY (user_id) REFERENCES users (user_id),
    CONSTRAINT fk_uba_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_categories (
    category_id         BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    category_name       VARCHAR(100) NOT NULL,
    PRIMARY KEY (category_id),
    UNIQUE KEY uk_categories (owner_id, category_name),
    CONSTRAINT fk_categories_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Master satuan (Addendum 39.2). Hanya NAMA satuan; tipe BASE/PACK dan
-- conversion_factor melekat pada produk karena isi satu DUS berbeda tiap produk.
-- Nama yang sudah dipakai product_uoms tidak boleh diubah — nonaktifkan saja.
CREATE TABLE units (
    unit_id             BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    unit_name           VARCHAR(50) NOT NULL, -- PCS / DUS / KG, huruf besar
    description         VARCHAR(150) NULL,
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (unit_id),
    UNIQUE KEY uk_units_name (owner_id, unit_name),
    CONSTRAINT fk_units_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product: stok & HPP selalu dalam base unit (Addendum 39)
CREATE TABLE products (
    product_id          BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    category_id         BIGINT NULL,
    -- Jenis produk (Addendum 39.14). Dua akibat, keduanya DITURUNKAN dari sini
    -- dan sengaja tidak disimpan sebagai kolom boolean tersendiri agar tidak
    -- pernah berselisih dengan jenisnya:
    --   boleh dijual  = TRADE, SERVICE          (aset & bahan produksi tidak)
    --   punya stok    = semua KECUALI SERVICE   (jasa tidak berwujud)
    -- PRODUCTION disediakan sebelum modul produksi ada, supaya barang yang
    -- dipakai produksi sudah bisa dibedakan sejak awal.
    product_type        ENUM('TRADE','SERVICE','ASSET','PRODUCTION') NOT NULL DEFAULT 'TRADE',
    product_name        VARCHAR(200) NOT NULL,
    sku                 VARCHAR(50) NOT NULL,
    barcode             VARCHAR(100) NULL, -- untuk scan POS retail
    base_uom_id         BIGINT NULL, -- FK ke product_uoms (set setelah UOM dibuat)
    is_weighted         BOOLEAN NOT NULL DEFAULT FALSE, -- TRUE = qty desimal (kg/liter)
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id),
    UNIQUE KEY uk_products_sku (owner_id, sku),
    UNIQUE KEY uk_products_barcode (owner_id, barcode),
    CONSTRAINT fk_products_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Konversi satuan (Addendum 39.2)
CREATE TABLE product_uoms (
    uom_id              BIGINT NOT NULL AUTO_INCREMENT,
    product_id          BIGINT NOT NULL,
    unit_id             BIGINT NOT NULL, -- FK ke master units; nama satuan berasal dari sana
    uom_name            VARCHAR(50) NOT NULL, -- salinan units.unit_name (nama master dikunci saat dipakai)
    uom_type            VARCHAR(10) NOT NULL,
    conversion_factor   DECIMAL(18,6) NOT NULL DEFAULT 1,
    is_default          BOOLEAN NOT NULL DEFAULT FALSE,
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (uom_id),
    UNIQUE KEY uk_uoms_name (product_id, uom_name),
    CONSTRAINT fk_uoms_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_uoms_unit FOREIGN KEY (unit_id) REFERENCES units (unit_id),
    -- Base UOM harus conversion_factor = 1
    CONSTRAINT chk_uoms_type CHECK (uom_type IN ('BASE', 'PACK')),
    CONSTRAINT chk_uoms_factor CHECK (conversion_factor > 0),
    CONSTRAINT chk_uoms_base CHECK ((uom_type = 'BASE' AND conversion_factor = 1) OR uom_type = 'PACK')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE products
    ADD CONSTRAINT fk_products_base_uom FOREIGN KEY (base_uom_id) REFERENCES product_uoms (uom_id);

-- ----------------------------------------------------------------------------
-- DAFTAR HARGA JUAL (Addendum 39.10)
-- SATU-SATUNYA sumber harga jual: master produk tidak menyimpan harga.
-- Daftar harga bernama, berjangka waktu, boleh khusus satu lokasi penjualan,
-- dan BERTINGKAT lewat min_qty. Produk yang tidak punya baris di sini tidak
-- dapat dijual.
--
-- Contoh Rinso (base = pcs, pack DUS = 12 pcs):
--   (DUS, min_qty 1)  -> 20.000   beli per dus
--   (PCS, min_qty 1)  ->  2.500   ecer
--   (PCS, min_qty 6)  ->  2.300   ecer banyak, masih di bawah 1 dus
-- ----------------------------------------------------------------------------
CREATE TABLE price_lists (
    price_list_id       BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    branch_id           BIGINT NULL, -- NULL = berlaku seluruh lokasi milik owner
    price_list_code     VARCHAR(20) NOT NULL,
    price_list_name     VARCHAR(150) NOT NULL,
    -- HARGA NORMAL: dipakai bila tidak ada daftar harga lain yang cocok.
    -- Maksimal satu per owner yang aktif; dijaga di service karena MySQL tidak
    -- punya partial unique.
    is_default          BOOLEAN NOT NULL DEFAULT FALSE,
    -- TRUE = hanya berlaku bagi pelanggan bertanda customers.is_member;
    -- dilewati resolusi umum. Tanpa penanda ini, daftar "Harga Grosir" akan
    -- menang atas harga normal hanya karena prioritasnya lebih tinggi,
    -- sehingga pembeli eceran ikut dapat harga grosir (Addendum 39.11).
    -- Tidak boleh TRUE bersamaan dengan is_default: harga normal dipakai semua
    -- pembeli, jadi daftar yang memikul kedua tanda membuat pembeli tunai
    -- kehilangan harga sama sekali. Dijaga di service, bukan CHECK.
    member_only         BOOLEAN NOT NULL DEFAULT FALSE,
    -- TIDAK DIPAKAI LAGI sejak Addendum 39.15: pemenang antar daftar sederajat
    -- ditentukan price_list_items.start_date, bukan angka ini. Kolomnya
    -- dipertahankan agar data lama tetap terbaca, dan sudah tidak ada di layar.
    priority            INT NOT NULL DEFAULT 0,
    start_date          DATE NULL, -- NULL = tanpa batas awal
    end_date            DATE NULL, -- NULL = tanpa batas akhir
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (price_list_id),
    UNIQUE KEY uk_price_lists_code (owner_id, price_list_code),
    KEY idx_price_lists_lookup (owner_id, branch_id, active, priority),
    CONSTRAINT fk_price_lists_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT fk_price_lists_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT chk_price_lists_dates CHECK (start_date IS NULL OR end_date IS NULL OR start_date <= end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE price_list_items (
    price_list_item_id  BIGINT NOT NULL AUTO_INCREMENT,
    price_list_id       BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    -- Harga melekat pada satuan, bukan pada produk. Inilah yang membuat
    -- "1 dus 20.000, 1 pcs 2.500" bisa hidup berdampingan.
    uom_id              BIGINT NOT NULL,
    -- Tingkat kuantitas: harga berlaku bila qty >= min_qty. Baris dengan
    -- min_qty terbesar yang masih terpenuhi yang dipakai.
    min_qty             DECIMAL(18,4) NOT NULL DEFAULT 1,
    -- Masa berlaku harga (Addendum 39.15). Harga TIDAK PERNAH ditimpa:
    -- menggantinya berarti menambah baris baru dengan start_date lebih baru,
    -- dan di antara baris yang mencakup tanggal transaksi, yang paling baru
    -- mulai yang menang. start_date inilah pengganti price_lists.priority.
    --
    -- Baris lama sengaja dibiarkan utuh — dialah yang membuat penjualan
    -- ber-backdate menemukan harga yang benar-benar berlaku saat itu, dan yang
    -- mengembalikan harga normal begitu promo berjangka habis masanya.
    --
    -- 9999-12-31 dipakai sebagai ganti NULL supaya perbandingan tanggal cukup
    -- satu BETWEEN dan tetap dapat memakai index.
    start_date          DATE NOT NULL DEFAULT '1900-01-01',
    end_date            DATE NOT NULL DEFAULT '9999-12-31',
    unit_price          DECIMAL(18,2) NOT NULL,
    -- Tidak ada kolom active: dengan rentang tanggal, "nonaktif" dan "sudah
    -- lewat" adalah dua cara mengatakan hal yang sama dan pasti berselisih.
    PRIMARY KEY (price_list_item_id),
    -- Dua VERSI harga untuk tingkat yang sama boleh — itulah riwayatnya. Yang
    -- dilarang adalah dua versi yang mulai pada tanggal yang sama, karena tidak
    -- ada yang bisa menjelaskan mana yang dimaksud.
    UNIQUE KEY uk_pli_versi (price_list_id, product_id, uom_id, min_qty, start_date),
    KEY idx_price_list_items_lookup (price_list_id, product_id, uom_id, min_qty),
    KEY idx_pli_riwayat (product_id, uom_id, start_date),
    CONSTRAINT fk_pli_price_list FOREIGN KEY (price_list_id) REFERENCES price_lists (price_list_id) ON DELETE CASCADE,
    CONSTRAINT fk_pli_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_pli_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id),
    CONSTRAINT chk_pli_min_qty CHECK (min_qty > 0),
    CONSTRAINT chk_pli_price CHECK (unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
    customer_id         BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    branch_id           BIGINT NULL,
    customer_name       VARCHAR(150) NOT NULL,
    phone               VARCHAR(30),
    -- TRUE = member terdaftar. Inilah SATU-SATUNYA yang menentukan harga:
    -- member memakai daftar harga bertanda member_only, pelanggan tunai
    -- memakai harga normal. Tidak ada penugasan daftar harga per pelanggan —
    -- dua tempat yang harus cocok membuat member yang lupa ditugaskan diam-diam
    -- membayar harga eceran (Addendum 39.11).
    is_member           BOOLEAN NOT NULL DEFAULT FALSE,
    -- Pelanggan tunai bawaan, tepat satu per owner: seluruh penjualan yang
    -- tidak menyebut pelanggan tercatat ke sini sehingga kasir tidak perlu
    -- memilih apa pun dan laporan per pelanggan tetap rapi (Addendum 39.13).
    is_default          BOOLEAN NOT NULL DEFAULT FALSE,
    credit_limit        DECIMAL(18,2) NOT NULL DEFAULT 0, -- 0 = tunai saja / tanpa limit piutang
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id),
    CONSTRAINT fk_customers_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT fk_customers_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE suppliers (
    supplier_id         BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    branch_id           BIGINT NULL,
    supplier_name       VARCHAR(150) NOT NULL,
    phone               VARCHAR(30),
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id),
    CONSTRAINT fk_suppliers_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT fk_suppliers_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Master pajak (contoh: PPN 11%). PRD 19 menampilkan tax di report.
CREATE TABLE taxes (
    tax_id              BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    tax_name            VARCHAR(100) NOT NULL,
    tax_rate            DECIMAL(5,2) NOT NULL, -- persen, contoh 11.00
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tax_id),
    UNIQUE KEY uk_taxes (owner_id, tax_name),
    CONSTRAINT fk_taxes_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT chk_taxes_rate CHECK (tax_rate >= 0 AND tax_rate <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Master diskon & promo (Addendum 61). Nilai diskon yang benar-benar dipakai
-- tetap disimpan sebagai angka di sales/sale_items (snapshot), agar perubahan
-- master tidak mengubah histori transaksi.
CREATE TABLE discounts (
    discount_id         BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    discount_name       VARCHAR(150) NOT NULL,
    discount_scope      VARCHAR(15) NOT NULL, -- ITEM | TRANSACTION
    discount_type       VARCHAR(10) NOT NULL, -- PERCENT | AMOUNT
    discount_value      DECIMAL(18,2) NOT NULL,
    max_discount_amount DECIMAL(18,2) NULL, -- batas atas untuk tipe PERCENT
    product_id          BIGINT NULL, -- NULL = berlaku semua product
    category_id         BIGINT NULL, -- NULL = berlaku semua kategori
    min_qty             DECIMAL(18,4) NOT NULL DEFAULT 0,
    min_amount          DECIMAL(18,2) NOT NULL DEFAULT 0,
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (discount_id),
    KEY idx_discounts_active (owner_id, active, start_date, end_date),
    CONSTRAINT fk_discounts_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT fk_discounts_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_discounts_category FOREIGN KEY (category_id) REFERENCES product_categories (category_id),
    CONSTRAINT chk_discounts_scope CHECK (discount_scope IN ('ITEM', 'TRANSACTION')),
    CONSTRAINT chk_discounts_type CHECK (discount_type IN ('PERCENT', 'AMOUNT')),
    CONSTRAINT chk_discounts_value CHECK (discount_value > 0),
    CONSTRAINT chk_discounts_dates CHECK (start_date <= end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE currencies (
    currency_id         BIGINT NOT NULL AUTO_INCREMENT,
    currency_code       CHAR(3) NOT NULL,
    currency_name       VARCHAR(50) NOT NULL,
    is_base             BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (currency_id),
    UNIQUE KEY uk_currencies_code (currency_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exchange_rates (
    rate_id             BIGINT NOT NULL AUTO_INCREMENT,
    currency_code       CHAR(3) NOT NULL,
    rate_date           DATE NOT NULL,
    rate_to_base        DECIMAL(18,6) NOT NULL,
    PRIMARY KEY (rate_id),
    UNIQUE KEY uk_rates (currency_code, rate_date),
    CONSTRAINT fk_rates_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code),
    CONSTRAINT chk_rates_value CHECK (rate_to_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 2. FISCAL PERIOD (PRD Section 2, 4, 31 + Addendum 38)
-- ============================================================================

-- branch_id NULL = Owner Level (berlaku seluruh branch milik owner) (PRD 4)
CREATE TABLE fiscal_periods (
    fiscal_period_id    BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    branch_id           BIGINT NULL,
    period_code         VARCHAR(30) NOT NULL,
    period_name         VARCHAR(100) NOT NULL,
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    costing_method      VARCHAR(20) NOT NULL DEFAULT 'AVERAGE',
    currency_code       CHAR(3) NOT NULL DEFAULT 'IDR',
    opened_at           DATETIME NULL,
    opened_by           BIGINT NULL,
    closed_at           DATETIME NULL,
    closed_by           BIGINT NULL,
    reopened_at         DATETIME NULL,
    reopened_by         BIGINT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by          BIGINT NULL,
    PRIMARY KEY (fiscal_period_id),
    UNIQUE KEY uk_periods_code (owner_id, branch_id, period_code),
    KEY idx_periods_owner_dates (owner_id, branch_id, start_date, end_date),
    CONSTRAINT fk_periods_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT fk_periods_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_periods_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code),
    CONSTRAINT fk_periods_opened_by FOREIGN KEY (opened_by) REFERENCES users (user_id),
    CONSTRAINT fk_periods_closed_by FOREIGN KEY (closed_by) REFERENCES users (user_id),
    CONSTRAINT fk_periods_reopened_by FOREIGN KEY (reopened_by) REFERENCES users (user_id),
    CONSTRAINT fk_periods_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT fk_periods_updated_by FOREIGN KEY (updated_by) REFERENCES users (user_id),
    CONSTRAINT chk_periods_dates CHECK (start_date <= end_date),
    CONSTRAINT chk_periods_status CHECK (status IN ('DRAFT', 'OPEN', 'CLOSING', 'CLOSED', 'REOPENED')),
    CONSTRAINT chk_periods_costing CHECK (costing_method IN ('AVERAGE', 'FIFO', 'SPECIFIC'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit trail perubahan status period (PRD 24, Addendum 45)
CREATE TABLE fiscal_period_logs (
    log_id              BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    action              VARCHAR(30) NOT NULL, -- CREATE/OPEN/CLOSING/CLOSED/REOPENED/FAILED
    old_status          VARCHAR(10) NULL,
    new_status          VARCHAR(10) NULL,
    action_by           BIGINT NULL,
    action_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason              TEXT,
    closing_snapshot_id BIGINT NULL, -- fiscal_closing_id dari snapshot yang diganti (Addendum 25)
    PRIMARY KEY (log_id),
    KEY idx_period_logs (fiscal_period_id),
    CONSTRAINT fk_period_logs_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_period_logs_user FOREIGN KEY (action_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reopen request + approval (Addendum 45)
CREATE TABLE fiscal_period_reopen_requests (
    reopen_request_id   BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    requested_by        BIGINT NOT NULL,
    reason              TEXT NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'PENDING',
    approval_type       VARCHAR(15) NOT NULL DEFAULT 'NORMAL', -- NORMAL | SELF_APPROVED (Addendum 45.2)
    reviewed_by         BIGINT NULL,
    reviewed_at         DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reopen_request_id),
    CONSTRAINT fk_reopen_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_reopen_requested_by FOREIGN KEY (requested_by) REFERENCES users (user_id),
    CONSTRAINT fk_reopen_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (user_id),
    CONSTRAINT chk_reopen_status CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED')),
    CONSTRAINT chk_reopen_approval CHECK (approval_type IN ('NORMAL', 'SELF_APPROVED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Saldo awal period (PRD 10, Addendum 40)
CREATE TABLE opening_balances (
    opening_balance_id  BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    balance_type        VARCHAR(20) NOT NULL,
    amount              DECIMAL(18,2) NOT NULL DEFAULT 0,
    note                TEXT,
    PRIMARY KEY (opening_balance_id),
    CONSTRAINT fk_opening_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_opening_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT chk_opening_type CHECK (balance_type IN ('CASH', 'INVENTORY', 'RECEIVABLE', 'PAYABLE', 'OTHER'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 2.5 SHIFT & REGISTER (Open Register / Laci Kasir)
-- Konsep: saat buka shift, kasir menginput uang di laci (opening cash / float).
-- Saat end shift: sistem menghitung expected cash = opening + sales - refund
--   - cash out + cash in, kasir menghitung uang fisik (actual cash),
--   - selisih dicatat. Buka shift berikutnya default ROLLOVER = actual cash
--   shift sebelumnya (uang tetap di laci), boleh diubah manual (MANUAL).
-- ============================================================================

CREATE TABLE cashier_shifts (
    shift_id            BIGINT NOT NULL AUTO_INCREMENT,
    shift_number        VARCHAR(40) NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    -- Hanya lokasi dengan branches.can_sell = TRUE (Addendum 59.2)
    branch_id           BIGINT NOT NULL,
    cashier_id          BIGINT NOT NULL,
    opened_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at           DATETIME NULL,
    opening_cash        DECIMAL(18,2) NOT NULL DEFAULT 0, -- input saat buka shift (uang di laci)
    opening_source      VARCHAR(10) NOT NULL DEFAULT 'MANUAL', -- MANUAL | ROLLOVER
    expected_cash       DECIMAL(18,2) NOT NULL DEFAULT 0, -- dihitung saat end shift
    actual_cash         DECIMAL(18,2) NOT NULL DEFAULT 0, -- hasil hitung fisik saat end shift
    difference          DECIMAL(18,2) NOT NULL DEFAULT 0, -- actual - expected
    close_note          TEXT,
    status              VARCHAR(10) NOT NULL DEFAULT 'OPEN',
    closed_by           BIGINT NULL,
    PRIMARY KEY (shift_id),
    UNIQUE KEY uk_shifts_number (branch_id, shift_number),
    CONSTRAINT fk_shifts_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_shifts_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_shifts_cashier FOREIGN KEY (cashier_id) REFERENCES users (user_id),
    CONSTRAINT fk_shifts_closed_by FOREIGN KEY (closed_by) REFERENCES users (user_id),
    CONSTRAINT chk_shifts_source CHECK (opening_source IN ('MANUAL', 'ROLLOVER')),
    CONSTRAINT chk_shifts_status CHECK (status IN ('OPEN', 'CLOSED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aturan (di service, bukan DB):
--   - Satu kasir hanya boleh punya 1 shift OPEN dalam satu branch.
--   - Rollover: opening_cash shift baru = actual_cash shift terakhir yang CLOSED
--     pada register/branch yang sama.
--   - Expected cash (end shift) = opening_cash + CASH_SALE + CASH_IN
--     - CASH_OUT - REFUND yang tercatat pada shift tersebut (PRD 18).
--   - PRD 13: closing period hanya boleh jika seluruh shift berstatus CLOSED.

-- ============================================================================
-- 3. TRANSAKSI (PRD 8, 34) — semua wajib fiscal_period_id
-- ============================================================================

-- 3.1 SALES
CREATE TABLE sales (
    sale_id             BIGINT NOT NULL AUTO_INCREMENT,
    sale_number         VARCHAR(40) NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    shift_id            BIGINT NULL, -- shift kasir yang memproses (rekonsiliasi shift)
    customer_id         BIGINT NULL,
    discount_id         BIGINT NULL, -- diskon level transaksi (Addendum 61)
    device_id           VARCHAR(50) NULL, -- perangkat kasir asal (Addendum 57.4, 63)
    local_uuid          CHAR(36) NULL, -- id dari perangkat kasir: kunci idempotensi retry (tahap 1) dan sync offline (tahap 2)
    transaction_date    DATE NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    gross_total         DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_total      DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_total           DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_total           DECIMAL(18,2) NOT NULL DEFAULT 0, -- nilai penjualan resmi, TIDAK terpengaruh pembulatan
    rounding_amount     DECIMAL(18,2) NOT NULL DEFAULT 0, -- selisih pembulatan pembayaran tunai, +/- (Addendum 51.4)
    paid_total          DECIMAL(18,2) NOT NULL DEFAULT 0, -- net_total + rounding_amount = uang yang benar-benar diterima
    currency_code       CHAR(3) NOT NULL DEFAULT 'IDR',
    exchange_rate       DECIMAL(18,6) NOT NULL DEFAULT 1,
    voided_at           DATETIME NULL,
    voided_by           BIGINT NULL,
    void_reason         TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by          BIGINT NULL,
    PRIMARY KEY (sale_id),
    UNIQUE KEY uk_sales_number (branch_id, sale_number),
    UNIQUE KEY uk_sales_local_uuid (branch_id, local_uuid), -- cegah dobel saat sync offline
    KEY idx_sales_period_date (fiscal_period_id, transaction_date),
    CONSTRAINT fk_sales_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_sales_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id),
    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id),
    CONSTRAINT fk_sales_discount FOREIGN KEY (discount_id) REFERENCES discounts (discount_id),
    CONSTRAINT fk_sales_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code),
    CONSTRAINT fk_sales_voided_by FOREIGN KEY (voided_by) REFERENCES users (user_id),
    CONSTRAINT fk_sales_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT fk_sales_updated_by FOREIGN KEY (updated_by) REFERENCES users (user_id),
    CONSTRAINT chk_sales_status CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sale_items (
    sale_item_id        BIGINT NOT NULL AUTO_INCREMENT,
    sale_id             BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    uom_id              BIGINT NOT NULL,
    qty                 DECIMAL(18,4) NOT NULL,
    qty_base            DECIMAL(18,4) NOT NULL, -- qty x conversion_factor
    unit_price          DECIMAL(18,2) NOT NULL DEFAULT 0, -- per UOM
    unit_price_base     DECIMAL(18,2) NOT NULL DEFAULT 0, -- per base unit
    -- Asal harga, referensi telusur saja (Addendum 39.10). Harga yang berlaku
    -- tetap angka di unit_price — mengubah master daftar harga tidak boleh
    -- mengubah histori. NULL = harga diinput manual di layar transaksi.
    price_list_id       BIGINT NULL,
    discount_id         BIGINT NULL, -- diskon level item (Addendum 61)
    discount_amount     DECIMAL(18,2) NOT NULL DEFAULT 0,
    cost_at_sale        DECIMAL(18,4) NOT NULL DEFAULT 0, -- HPP unit (base), disimpan saat transaksi (PRD 14)
    cogs_total          DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_amount          DECIMAL(18,2) NOT NULL DEFAULT 0,
    line_total          DECIMAL(18,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (sale_item_id),
    KEY idx_sale_items_sale (sale_id),
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales (sale_id),
    CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_sale_items_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id),
    CONSTRAINT fk_sale_items_discount FOREIGN KEY (discount_id) REFERENCES discounts (discount_id),
    CONSTRAINT fk_sale_items_price_list FOREIGN KEY (price_list_id) REFERENCES price_lists (price_list_id),
    CONSTRAINT chk_sale_items_qty CHECK (qty > 0 AND qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Breakdown pajak per transaksi penjualan (PRD 19)
CREATE TABLE sale_taxes (
    sale_tax_id         BIGINT NOT NULL AUTO_INCREMENT,
    sale_id             BIGINT NOT NULL,
    tax_id              BIGINT NOT NULL,
    base_amount         DECIMAL(18,2) NOT NULL DEFAULT 0, -- dasar pengenaan pajak
    tax_amount          DECIMAL(18,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (sale_tax_id),
    KEY idx_sale_taxes_sale (sale_id),
    CONSTRAINT fk_sale_taxes_sale FOREIGN KEY (sale_id) REFERENCES sales (sale_id),
    CONSTRAINT fk_sale_taxes_tax FOREIGN KEY (tax_id) REFERENCES taxes (tax_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.2 PAYMENTS (pembayaran lintas period: period = tanggal pembayaran, Addendum 47)
CREATE TABLE payments (
    payment_id          BIGINT NOT NULL AUTO_INCREMENT,
    payment_number      VARCHAR(40) NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    shift_id            BIGINT NULL,
    transaction_date    DATE NOT NULL,
    payment_type        VARCHAR(15) NOT NULL,
    amount              DECIMAL(18,2) NOT NULL,
    currency_code       CHAR(3) NOT NULL DEFAULT 'IDR',
    exchange_rate       DECIMAL(18,6) NOT NULL DEFAULT 1,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    voided_at           DATETIME NULL,
    voided_by           BIGINT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uk_payments_number (branch_id, payment_number),
    KEY idx_payments_period_date (fiscal_period_id, transaction_date),
    CONSTRAINT fk_payments_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_payments_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_payments_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id),
    CONSTRAINT fk_payments_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code),
    CONSTRAINT fk_payments_voided_by FOREIGN KEY (voided_by) REFERENCES users (user_id),
    CONSTRAINT fk_payments_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    -- CREDIT_CARD = kartu kredit. Penjualan kredit (piutang) TIDAK membuat record
    -- payments; piutangnya dicatat di receivables (Addendum 41.3)
    CONSTRAINT chk_payments_type CHECK (payment_type IN ('CASH', 'QRIS', 'DEBIT', 'CREDIT_CARD', 'TRANSFER', 'E_WALLET')),
    CONSTRAINT chk_payments_amount CHECK (amount > 0),
    CONSTRAINT chk_payments_status CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sale_payments (
    sale_payment_id     BIGINT NOT NULL AUTO_INCREMENT,
    sale_id             BIGINT NOT NULL,
    payment_id          BIGINT NOT NULL,
    amount              DECIMAL(18,2) NOT NULL,
    PRIMARY KEY (sale_payment_id),
    UNIQUE KEY uk_sale_payments (sale_id, payment_id),
    CONSTRAINT fk_sp_sale FOREIGN KEY (sale_id) REFERENCES sales (sale_id),
    CONSTRAINT fk_sp_payment FOREIGN KEY (payment_id) REFERENCES payments (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.3 PENERIMAAN BARANG (Addendum 58)
-- Dokumen inilah yang MENAMBAH STOK dan membentuk lot — bukan faktur.
-- Boleh dicatat di lokasi mana pun termasuk gudang (Addendum 59.2), dan boleh
-- diisi kasir/petugas gudang dengan qty fisik saja tanpa melihat harga.
CREATE TABLE goods_receipts (
    goods_receipt_id    BIGINT NOT NULL AUTO_INCREMENT,
    receipt_number      VARCHAR(40) NOT NULL, -- prefix GRN (Addendum 52)
    fiscal_period_id    BIGINT NOT NULL, -- ditentukan dari receipt_date
    branch_id           BIGINT NOT NULL,
    supplier_id         BIGINT NULL,
    -- Faktur yang mencakup penerimaan ini. NULL = belum ditagih, nilainya duduk
    -- di akun Utang Belum Ditagih / GRNI dan memblokir closing (Addendum 58.5).
    -- Satu faktur dapat mencakup beberapa penerimaan, karena itu FK ada di sisi ini.
    purchase_id         BIGINT NULL,
    receipt_date        DATE NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    supplier_do_number  VARCHAR(50) NULL, -- nomor surat jalan pemasok
    note                TEXT,
    total_cost          DECIMAL(18,2) NOT NULL DEFAULT 0, -- nilai sementara, dasar nilai GRNI
    invoiced_at         DATETIME NULL,
    voided_at           DATETIME NULL,
    voided_by           BIGINT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (goods_receipt_id),
    UNIQUE KEY uk_receipts_number (branch_id, receipt_number),
    KEY idx_receipts_period_date (fiscal_period_id, receipt_date),
    -- Dipakai pemeriksaan pra-closing: cari penerimaan POSTED yang purchase_id NULL
    KEY idx_receipts_uninvoiced (branch_id, status, purchase_id),
    CONSTRAINT fk_receipts_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_receipts_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_receipts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id),
    CONSTRAINT fk_receipts_voided_by FOREIGN KEY (voided_by) REFERENCES users (user_id),
    CONSTRAINT fk_receipts_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_receipts_status CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))
    -- FK purchase_id ditambahkan lewat ALTER setelah tabel purchases dibuat
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE goods_receipt_items (
    receipt_item_id     BIGINT NOT NULL AUTO_INCREMENT,
    goods_receipt_id    BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    uom_id              BIGINT NOT NULL,
    qty                 DECIMAL(18,4) NOT NULL, -- qty fisik dalam uom yang dipilih
    qty_base            DECIMAL(18,4) NOT NULL,
    -- Cost sementara, diisi sistem bukan oleh kasir (Addendum 58.2).
    -- Berubah menjadi cost final saat faktur diposting; selisihnya memicu
    -- recalculate HPP lewat mekanisme Addendum 42.2.
    unit_cost           DECIMAL(18,2) NOT NULL DEFAULT 0, -- per uom
    unit_cost_base      DECIMAL(18,4) NOT NULL DEFAULT 0, -- per base unit
    cost_source         VARCHAR(15) NOT NULL DEFAULT 'NONE', -- LAST_PURCHASE | AVG_COST | NONE | INVOICE
    line_total          DECIMAL(18,2) NOT NULL DEFAULT 0,
    purchase_item_id    BIGINT NULL, -- baris faktur yang mencocokkan, diisi saat faktur POSTED
    PRIMARY KEY (receipt_item_id),
    KEY idx_receipt_items_receipt (goods_receipt_id),
    CONSTRAINT fk_receipt_items_receipt FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts (goods_receipt_id),
    CONSTRAINT fk_receipt_items_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_receipt_items_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id),
    CONSTRAINT chk_receipt_items_qty CHECK (qty > 0 AND qty_base > 0),
    CONSTRAINT chk_receipt_items_source CHECK (cost_source IN ('LAST_PURCHASE', 'AVG_COST', 'NONE', 'INVOICE'))
    -- FK purchase_item_id ditambahkan lewat ALTER setelah tabel purchase_items dibuat
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.4 FAKTUR PEMASOK (Addendum 58)
-- Mengakui utang dan PPN Masukan. TIDAK menambah stok.
CREATE TABLE purchases (
    purchase_id         BIGINT NOT NULL AUTO_INCREMENT,
    purchase_number     VARCHAR(40) NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    supplier_id         BIGINT NULL,
    transaction_date    DATE NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    subtotal            DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_total           DECIMAL(18,2) NOT NULL DEFAULT 0,
    total               DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency_code       CHAR(3) NOT NULL DEFAULT 'IDR',
    exchange_rate       DECIMAL(18,6) NOT NULL DEFAULT 1,
    voided_at           DATETIME NULL,
    voided_by           BIGINT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (purchase_id),
    UNIQUE KEY uk_purchases_number (branch_id, purchase_number),
    KEY idx_purchases_period_date (fiscal_period_id, transaction_date),
    CONSTRAINT fk_purchases_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_purchases_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id),
    CONSTRAINT fk_purchases_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code),
    CONSTRAINT fk_purchases_voided_by FOREIGN KEY (voided_by) REFERENCES users (user_id),
    CONSTRAINT fk_purchases_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_purchases_status CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_items (
    purchase_item_id    BIGINT NOT NULL AUTO_INCREMENT,
    purchase_id         BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    uom_id              BIGINT NOT NULL,
    qty                 DECIMAL(18,4) NOT NULL,
    qty_base            DECIMAL(18,4) NOT NULL,
    unit_cost           DECIMAL(18,2) NOT NULL DEFAULT 0, -- per UOM (contoh: harga per dus)
    unit_cost_base      DECIMAL(18,4) NOT NULL DEFAULT 0, -- cost per base unit (contoh: per pcs)
    line_total          DECIMAL(18,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (purchase_item_id),
    KEY idx_purchase_items_purchase (purchase_id),
    CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (purchase_id),
    CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_purchase_items_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id),
    CONSTRAINT chk_purchase_items_qty CHECK (qty > 0 AND qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pencocokan penerimaan ↔ faktur (Addendum 58.3). Ditaruh di sini karena
-- goods_receipts dibuat lebih dulu mengikuti urutan alur dokumen.
ALTER TABLE goods_receipts
    ADD CONSTRAINT fk_receipts_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (purchase_id);

ALTER TABLE goods_receipt_items
    ADD CONSTRAINT fk_receipt_items_purchase_item FOREIGN KEY (purchase_item_id) REFERENCES purchase_items (purchase_item_id);

-- Breakdown pajak per transaksi pembelian
CREATE TABLE purchase_taxes (
    purchase_tax_id     BIGINT NOT NULL AUTO_INCREMENT,
    purchase_id         BIGINT NOT NULL,
    tax_id              BIGINT NOT NULL,
    base_amount         DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_amount          DECIMAL(18,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (purchase_tax_id),
    KEY idx_purchase_taxes_purchase (purchase_id),
    CONSTRAINT fk_purchase_taxes_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (purchase_id),
    CONSTRAINT fk_purchase_taxes_tax FOREIGN KEY (tax_id) REFERENCES taxes (tax_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_payments (
    purchase_payment_id BIGINT NOT NULL AUTO_INCREMENT,
    purchase_id         BIGINT NOT NULL,
    payment_id          BIGINT NOT NULL,
    amount              DECIMAL(18,2) NOT NULL,
    PRIMARY KEY (purchase_payment_id),
    UNIQUE KEY uk_purchase_payments (purchase_id, payment_id),
    CONSTRAINT fk_pp_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (purchase_id),
    CONSTRAINT fk_pp_payment FOREIGN KEY (payment_id) REFERENCES payments (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.5 SALES RETURN (return lintas period: HPP = cost transaksi asal, Addendum 48)
CREATE TABLE sales_returns (
    return_id           BIGINT NOT NULL AUTO_INCREMENT,
    return_number       VARCHAR(40) NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    sale_id             BIGINT NULL,
    transaction_date    DATE NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    refund_total        DECIMAL(18,2) NOT NULL DEFAULT 0,
    voided_at           DATETIME NULL,
    voided_by           BIGINT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (return_id),
    UNIQUE KEY uk_sales_returns_number (branch_id, return_number),
    KEY idx_returns_period_date (fiscal_period_id, transaction_date),
    CONSTRAINT fk_sales_returns_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_sales_returns_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_sales_returns_sale FOREIGN KEY (sale_id) REFERENCES sales (sale_id),
    CONSTRAINT fk_sales_returns_voided_by FOREIGN KEY (voided_by) REFERENCES users (user_id),
    CONSTRAINT fk_sales_returns_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_sales_returns_status CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sales_return_items (
    return_item_id      BIGINT NOT NULL AUTO_INCREMENT,
    return_id           BIGINT NOT NULL,
    sale_item_id        BIGINT NULL,
    product_id          BIGINT NOT NULL,
    uom_id              BIGINT NOT NULL,
    qty                 DECIMAL(18,4) NOT NULL,
    qty_base            DECIMAL(18,4) NOT NULL,
    cost_at_return      DECIMAL(18,4) NOT NULL DEFAULT 0, -- cost dari transaksi asal (snapshot)
    refund_amount       DECIMAL(18,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (return_item_id),
    CONSTRAINT fk_sri_return FOREIGN KEY (return_id) REFERENCES sales_returns (return_id),
    CONSTRAINT fk_sri_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items (sale_item_id),
    CONSTRAINT fk_sri_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_sri_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id),
    CONSTRAINT chk_sri_qty CHECK (qty > 0 AND qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.6 PURCHASE RETURN
-- Mengacu pada PENERIMAAN, bukan faktur — lot persediaan lahir dari
-- penerimaan, bukan faktur (Addendum 58.6, 48.2). Diperbaiki 4 Sep 2026;
-- semula keliru menunjuk purchases/purchase_items, peninggalan sebelum
-- penerimaan dipisah dari faktur.
CREATE TABLE purchase_returns (
    return_id           BIGINT NOT NULL AUTO_INCREMENT,
    return_number       VARCHAR(40) NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    goods_receipt_id    BIGINT NULL,
    transaction_date    DATE NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    refund_total        DECIMAL(18,2) NOT NULL DEFAULT 0,
    voided_at           DATETIME NULL,
    voided_by           BIGINT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (return_id),
    UNIQUE KEY uk_purchase_returns_number (branch_id, return_number),
    KEY idx_preturns_period_date (fiscal_period_id, transaction_date),
    CONSTRAINT fk_purchase_returns_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_purchase_returns_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_purchase_returns_receipt FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts (goods_receipt_id),
    CONSTRAINT fk_purchase_returns_voided_by FOREIGN KEY (voided_by) REFERENCES users (user_id),
    CONSTRAINT fk_purchase_returns_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_purchase_returns_status CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_return_items (
    return_item_id      BIGINT NOT NULL AUTO_INCREMENT,
    return_id           BIGINT NOT NULL,
    receipt_item_id     BIGINT NULL,
    product_id          BIGINT NOT NULL,
    uom_id              BIGINT NOT NULL,
    qty                 DECIMAL(18,4) NOT NULL,
    qty_base            DECIMAL(18,4) NOT NULL,
    cost_at_return      DECIMAL(18,4) NOT NULL DEFAULT 0,
    refund_amount       DECIMAL(18,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (return_item_id),
    CONSTRAINT fk_pri_return FOREIGN KEY (return_id) REFERENCES purchase_returns (return_id),
    CONSTRAINT fk_pri_receipt_item FOREIGN KEY (receipt_item_id) REFERENCES goods_receipt_items (receipt_item_id),
    CONSTRAINT fk_pri_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_pri_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id),
    CONSTRAINT chk_pri_qty CHECK (qty > 0 AND qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.7 STOCK TRANSFER antar-branch (Addendum 49)
CREATE TABLE stock_transfers (
    transfer_id         BIGINT NOT NULL AUTO_INCREMENT,
    transfer_number     VARCHAR(40) NOT NULL,
    from_branch_id      BIGINT NOT NULL,
    to_branch_id        BIGINT NOT NULL,
    transaction_date    DATE NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    voided_at           DATETIME NULL,
    voided_by           BIGINT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (transfer_id),
    UNIQUE KEY uk_transfers_number (from_branch_id, transfer_number),
    CONSTRAINT fk_transfers_from FOREIGN KEY (from_branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_transfers_to FOREIGN KEY (to_branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_transfers_voided_by FOREIGN KEY (voided_by) REFERENCES users (user_id),
    CONSTRAINT fk_transfers_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_transfers_branch CHECK (from_branch_id <> to_branch_id),
    CONSTRAINT chk_transfers_status CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_transfer_items (
    transfer_item_id    BIGINT NOT NULL AUTO_INCREMENT,
    transfer_id         BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    uom_id              BIGINT NOT NULL,
    qty                 DECIMAL(18,4) NOT NULL,
    qty_base            DECIMAL(18,4) NOT NULL,
    unit_cost           DECIMAL(18,4) NOT NULL DEFAULT 0, -- cost dari branch pengirim
    PRIMARY KEY (transfer_item_id),
    CONSTRAINT fk_sti_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (transfer_id),
    CONSTRAINT fk_sti_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_sti_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id),
    CONSTRAINT chk_sti_qty CHECK (qty > 0 AND qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.8 PIUTANG & HUTANG TERBUKA (Addendum 47)
-- Ledger per customer/supplier. paid_amount diupdate saat ada payment.
CREATE TABLE receivables (
    receivable_id       BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    customer_id         BIGINT NOT NULL,
    sale_id             BIGINT NULL,
    due_date            DATE NULL,
    amount              DECIMAL(18,2) NOT NULL,
    paid_amount         DECIMAL(18,2) NOT NULL DEFAULT 0,
    status              VARCHAR(10) NOT NULL DEFAULT 'OPEN', -- OPEN | PARTIAL | PAID | VOID
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (receivable_id),
    KEY idx_receivables_customer (customer_id, status),
    CONSTRAINT fk_receivables_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_receivables_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_receivables_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id),
    CONSTRAINT fk_receivables_sale FOREIGN KEY (sale_id) REFERENCES sales (sale_id),
    CONSTRAINT fk_receivables_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_receivables_status CHECK (status IN ('OPEN', 'PARTIAL', 'PAID', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payables (
    payable_id          BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    supplier_id         BIGINT NOT NULL,
    purchase_id         BIGINT NULL,
    due_date            DATE NULL,
    amount              DECIMAL(18,2) NOT NULL,
    paid_amount         DECIMAL(18,2) NOT NULL DEFAULT 0,
    status              VARCHAR(10) NOT NULL DEFAULT 'OPEN', -- OPEN | PARTIAL | PAID | VOID
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (payable_id),
    KEY idx_payables_supplier (supplier_id, status),
    CONSTRAINT fk_payables_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_payables_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_payables_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id),
    CONSTRAINT fk_payables_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (purchase_id),
    CONSTRAINT fk_payables_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_payables_status CHECK (status IN ('OPEN', 'PARTIAL', 'PAID', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 4. STOCK (PRD 8, 15, 33)
-- ============================================================================

-- Lot/batch per branch + product. Dasar penerapan FIFO (Addendum 38).
-- Opening inventory (Addendum 40) membuat lot awal; PENERIMAAN BARANG membuat
-- lot baru (Addendum 58.2 — bukan faktur); sales FIFO mengambil dari lot terlama
-- (status jadi EXHAUSTED saat habis).
CREATE TABLE stock_lots (
    lot_id              BIGINT NOT NULL AUTO_INCREMENT,
    branch_id           BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    fiscal_period_id    BIGINT NULL, -- period saat lot terbentuk (telusur saat reopen)
    -- Lot lahir dari penerimaan, bukan faktur. Retur pembelian juga mengacu ke
    -- sini (Addendum 48.2 / 58.6). Menggantikan purchase_item_id sebelum Rev.4.
    receipt_item_id     BIGINT NULL,
    -- Lot lahir dari retur penjualan (barang kembali dari pelanggan) menunjuk
    -- baris retur itu sendiri, supaya void retur tahu lot persis mana yang
    -- harus dikosongkan kembali (Addendum 48.2). Ditambah 4 Sep 2026.
    return_item_id      BIGINT NULL,
    -- Lot lahir di branch PENERIMA transfer menunjuk baris transfer itu
    -- sendiri, pola sama seperti receipt_item_id/return_item_id (Addendum
    -- 49.3, 49.4). Ditambah 4 Sep 2026.
    transfer_item_id    BIGINT NULL,
    lot_number          VARCHAR(50) NOT NULL,
    received_date       DATE NOT NULL,
    qty_remaining       DECIMAL(18,4) NOT NULL DEFAULT 0, -- base unit, sisa belum terjual
    unit_cost           DECIMAL(18,4) NOT NULL DEFAULT 0, -- cost per base unit
    status              VARCHAR(10) NOT NULL DEFAULT 'ACTIVE', -- ACTIVE | EXHAUSTED
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lot_id),
    KEY idx_lots_fifo (branch_id, product_id, received_date, lot_id),
    CONSTRAINT fk_lots_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_lots_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_lots_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_lots_receipt_item FOREIGN KEY (receipt_item_id) REFERENCES goods_receipt_items (receipt_item_id),
    CONSTRAINT fk_lots_return_item FOREIGN KEY (return_item_id) REFERENCES sales_return_items (return_item_id),
    CONSTRAINT fk_lots_transfer_item FOREIGN KEY (transfer_item_id) REFERENCES stock_transfer_items (transfer_item_id),
    CONSTRAINT chk_lots_status CHECK (status IN ('ACTIVE', 'EXHAUSTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Riwayat pergerakan stok: dasar recalculate HPP (PRD 15).
-- UNIQUE (period, branch, product, sequence): urutan histori bebas duplikat,
-- mendukung penyisipan transaksi backdated (PRD 29, Addendum 42).
CREATE TABLE stock_movements (
    movement_id         BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    movement_date       DATE NOT NULL,
    movement_type       VARCHAR(20) NOT NULL,
    -- RECEIPT dipakai untuk penambahan stok dari penerimaan barang (Addendum 58.2);
    -- PURCHASE hanya tersisa untuk data lama sebelum penerimaan dipisah dari faktur.
    reference_type      VARCHAR(30) NULL, -- SALES/RECEIPT/PURCHASE/ADJUSTMENT/OPNAME/TRANSFER/RETURN/CLOSING
    reference_id        BIGINT NULL,
    uom_id              BIGINT NOT NULL,
    qty                 DECIMAL(18,4) NOT NULL, -- bernilai +/- (signed)
    qty_base            DECIMAL(18,4) NOT NULL,
    unit_cost           DECIMAL(18,4) NOT NULL DEFAULT 0,
    total_cost          DECIMAL(18,2) NOT NULL DEFAULT 0,
    sequence            BIGINT NOT NULL, -- urutan histori untuk recalculate (PRD 29)
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (movement_id),
    UNIQUE KEY uk_movements_sequence (fiscal_period_id, branch_id, product_id, sequence),
    CONSTRAINT fk_movements_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_movements_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_movements_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id),
    CONSTRAINT fk_movements_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_movements_type CHECK (movement_type IN (
        'OPENING',          -- initial opening / opening dari closing period (Addendum 40)
        'PURCHASE',
        'SALES',
        'ADJUSTMENT_IN',
        'ADJUSTMENT_OUT',
        'TRANSFER_IN',
        'TRANSFER_OUT',
        'RETURN_IN',        -- sales return masuk stok
        'RETURN_OUT',       -- purchase return keluar stok
        'VOID_REVERSAL',    -- pembalikan stok saat void (Addendum 41)
        'ROUNDING',         -- selisih pembulatan HPP (Addendum 51)
        'CORRECTION'        -- koreksi qty/harga (Addendum 42)
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Konsumsi lot per movement (Addendum 41.2, 48.2). Tanpa tabel ini, void dan
-- retur tidak dapat mengembalikan qty ke lot asal dan FIFO tidak dapat
-- di-recalculate karena tidak ada jejak lot mana yang terpakai.
CREATE TABLE stock_lot_consumptions (
    consumption_id      BIGINT NOT NULL AUTO_INCREMENT,
    movement_id         BIGINT NOT NULL, -- movement yang mengonsumsi lot (SALES/TRANSFER_OUT/RETURN_OUT/ADJUSTMENT_OUT)
    lot_id              BIGINT NOT NULL,
    qty_base            DECIMAL(18,4) NOT NULL,
    unit_cost           DECIMAL(18,4) NOT NULL DEFAULT 0, -- cost lot saat dikonsumsi
    total_cost          DECIMAL(18,2) NOT NULL DEFAULT 0,
    reversed_by_movement_id BIGINT NULL, -- diisi saat void (movement VOID_REVERSAL)
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (consumption_id),
    UNIQUE KEY uk_lot_consumption (movement_id, lot_id),
    KEY idx_lot_consumption_lot (lot_id),
    CONSTRAINT fk_slc_movement FOREIGN KEY (movement_id) REFERENCES stock_movements (movement_id),
    CONSTRAINT fk_slc_lot FOREIGN KEY (lot_id) REFERENCES stock_lots (lot_id),
    CONSTRAINT fk_slc_reversed FOREIGN KEY (reversed_by_movement_id) REFERENCES stock_movements (movement_id),
    CONSTRAINT chk_slc_qty CHECK (qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Saldo stok terkini per branch + product (base unit)
CREATE TABLE stock_balances (
    stock_balance_id    BIGINT NOT NULL AUTO_INCREMENT,
    branch_id           BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    qty_on_hand         DECIMAL(18,4) NOT NULL DEFAULT 0,
    avg_cost            DECIMAL(18,4) NOT NULL DEFAULT 0,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (stock_balance_id),
    UNIQUE KEY uk_stock_balances (branch_id, product_id),
    CONSTRAINT fk_balances_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_balances_product FOREIGN KEY (product_id) REFERENCES products (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit recalculate HPP (Addendum 42, 45): siapa, kapan, kenapa, dampaknya
CREATE TABLE hpp_recalc_logs (
    recalc_log_id       BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    triggered_by        BIGINT NOT NULL,
    trigger_type        VARCHAR(30) NOT NULL, -- CORRECTION | VOID | BACKDATED | REOPEN | CLOSING
    reference_type      VARCHAR(30) NULL,
    reference_id        BIGINT NULL,
    products_affected   INT NOT NULL DEFAULT 0,
    old_cogs_value      DECIMAL(18,2) NOT NULL DEFAULT 0,
    new_cogs_value      DECIMAL(18,2) NOT NULL DEFAULT 0,
    status              VARCHAR(10) NOT NULL DEFAULT 'SUCCESS', -- SUCCESS | FAILED
    error_message       TEXT,
    started_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at         DATETIME NULL,
    PRIMARY KEY (recalc_log_id),
    KEY idx_recalc_period (fiscal_period_id, branch_id),
    CONSTRAINT fk_recalc_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_recalc_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_recalc_user FOREIGN KEY (triggered_by) REFERENCES users (user_id),
    CONSTRAINT chk_recalc_status CHECK (status IN ('SUCCESS', 'FAILED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_adjustments (
    adjustment_id       BIGINT NOT NULL AUTO_INCREMENT,
    adjustment_number   VARCHAR(40) NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    -- Period ditentukan TANGGAL ini, bukan created_at: barang yang baru
    -- ketahuan hilang hari ini bisa saja hilangnya minggu lalu.
    adjustment_date     DATE NOT NULL,
    product_id          BIGINT NOT NULL,
    uom_id              BIGINT NOT NULL,
    adjustment_type     VARCHAR(10) NOT NULL,
    qty                 DECIMAL(18,4) NOT NULL,
    qty_base            DECIMAL(18,4) NOT NULL,
    -- Perolehan per satuan dasar; hanya dipakai penyesuaian MASUK. Yang keluar
    -- memakai harga lot yang benar-benar terpakai.
    unit_cost           DECIMAL(18,4) NOT NULL DEFAULT 0,
    reason              TEXT NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (adjustment_id),
    UNIQUE KEY uk_adjustments_number (branch_id, adjustment_number),
    CONSTRAINT fk_adjustments_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_adjustments_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_adjustments_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_adjustments_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id),
    CONSTRAINT fk_adjustments_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_adjustments_type CHECK (adjustment_type IN ('IN', 'OUT')),
    CONSTRAINT chk_adjustments_qty CHECK (qty > 0 AND qty_base > 0),
    CONSTRAINT chk_adjustments_status CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock Opname (PRD 13, Addendum 39.8)
CREATE TABLE stock_opnames (
    opname_id           BIGINT NOT NULL AUTO_INCREMENT,
    opname_number       VARCHAR(40) NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    opname_date         DATE NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    applied_at          DATETIME NULL,
    applied_by          BIGINT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (opname_id),
    UNIQUE KEY uk_opnames_number (branch_id, opname_number),
    CONSTRAINT fk_opnames_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_opnames_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_opnames_applied_by FOREIGN KEY (applied_by) REFERENCES users (user_id),
    CONSTRAINT fk_opnames_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_opnames_status CHECK (status IN ('DRAFT', 'APPLIED', 'CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_opname_items (
    opname_item_id      BIGINT NOT NULL AUTO_INCREMENT,
    opname_id           BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    uom_id              BIGINT NOT NULL,
    system_qty          DECIMAL(18,4) NOT NULL,
    system_qty_base     DECIMAL(18,4) NOT NULL,
    actual_qty          DECIMAL(18,4) NOT NULL,
    actual_qty_base     DECIMAL(18,4) NOT NULL,
    difference_qty_base DECIMAL(18,4) NOT NULL,
    unit_cost           DECIMAL(18,4) NOT NULL DEFAULT 0,
    difference_value    DECIMAL(18,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (opname_item_id),
    CONSTRAINT fk_opname_items_opname FOREIGN KEY (opname_id) REFERENCES stock_opnames (opname_id),
    CONSTRAINT fk_opname_items_product FOREIGN KEY (product_id) REFERENCES products (product_id),
    CONSTRAINT fk_opname_items_uom FOREIGN KEY (uom_id) REFERENCES product_uoms (uom_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 5. CASH (PRD 18, Addendum 41, 46)
-- ============================================================================

-- Pencatatan kas: dasar cek saldo kas per tanggal saat void (Addendum 41).
-- Per-shift reconciliation dihitung lewat sales/payments/refund yang punya
-- shift_id; cash_balances di bawah adalah agregat per branch per tanggal.
CREATE TABLE cash_transactions (
    cash_txn_id         BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    shift_id            BIGINT NULL,
    transaction_date    DATE NOT NULL,
    txn_type            VARCHAR(15) NOT NULL,
    amount              DECIMAL(18,2) NOT NULL, -- bernilai +/- (signed)
    reference_type      VARCHAR(30) NULL, -- SALES/PURCHASE/PAYMENT/VOID/RETURN/OPNAME/EXPENSE
    reference_id        BIGINT NULL,
    note                TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (cash_txn_id),
    KEY idx_cash_txn_period_date (fiscal_period_id, branch_id, transaction_date),
    CONSTRAINT fk_cash_txn_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_cash_txn_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_cash_txn_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id),
    CONSTRAINT fk_cash_txn_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_cash_txn_type CHECK (txn_type IN ('OPENING_CASH', 'CASH_SALE', 'CASH_IN', 'CASH_OUT', 'REFUND'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Saldo kas per tanggal (agregat branch). Void sales wajib cek saldo ini (Addendum 41)
CREATE TABLE cash_balances (
    cash_balance_id     BIGINT NOT NULL AUTO_INCREMENT,
    branch_id           BIGINT NOT NULL,
    balance_date        DATE NOT NULL,
    opening_balance     DECIMAL(18,2) NOT NULL DEFAULT 0,
    cash_in             DECIMAL(18,2) NOT NULL DEFAULT 0,
    cash_out            DECIMAL(18,2) NOT NULL DEFAULT 0,
    closing_balance     DECIMAL(18,2) NOT NULL DEFAULT 0,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cash_balance_id),
    UNIQUE KEY uk_cash_balances (branch_id, balance_date),
    CONSTRAINT fk_cash_balances_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Selisih kas & penyelesaiannya (Addendum 46)
CREATE TABLE cash_differences (
    difference_id       BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    shift_id            BIGINT NULL,
    expected_cash       DECIMAL(18,2) NOT NULL DEFAULT 0,
    actual_cash         DECIMAL(18,2) NOT NULL DEFAULT 0,
    difference          DECIMAL(18,2) NOT NULL DEFAULT 0,
    status              VARCHAR(10) NOT NULL DEFAULT 'OPEN',
    resolution_type     VARCHAR(30) NULL,
    resolved_at         DATETIME NULL,
    resolved_by         BIGINT NULL,
    note                TEXT,
    PRIMARY KEY (difference_id),
    CONSTRAINT fk_cash_diff_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_cash_diff_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_cash_diff_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id),
    CONSTRAINT fk_cash_diff_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (user_id),
    CONSTRAINT chk_cash_diff_status CHECK (status IN ('OPEN', 'RESOLVED')),
    CONSTRAINT chk_cash_diff_type CHECK (resolution_type IN ('CASH_ADJUSTMENT', 'CASHIER_CHARGE', 'OTHER_INCOME', 'OTHER_EXPENSE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Biaya operasional (Addendum 60). Tanpa tabel ini, closing hanya bisa
-- menghitung HPP & penjualan, tidak bisa menghasilkan laba bersih per period.
CREATE TABLE expense_categories (
    expense_category_id BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    category_name       VARCHAR(100) NOT NULL, -- Listrik / Gaji / Sewa / Transport
    account_id          BIGINT NULL, -- map ke chart_of_accounts (diisi belakangan)
    active              BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (expense_category_id),
    UNIQUE KEY uk_expense_category (owner_id, category_name),
    CONSTRAINT fk_expcat_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expenses (
    expense_id          BIGINT NOT NULL AUTO_INCREMENT,
    expense_number      VARCHAR(40) NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    shift_id            BIGINT NULL,
    expense_category_id BIGINT NOT NULL,
    transaction_date    DATE NOT NULL,
    description         VARCHAR(255) NOT NULL,
    amount              DECIMAL(18,2) NOT NULL,
    payment_type        VARCHAR(15) NOT NULL DEFAULT 'CASH',
    status              VARCHAR(10) NOT NULL DEFAULT 'DRAFT',
    voided_at           DATETIME NULL,
    voided_by           BIGINT NULL,
    void_reason         TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (expense_id),
    UNIQUE KEY uk_expenses_number (branch_id, expense_number),
    KEY idx_expenses_period_date (fiscal_period_id, transaction_date),
    CONSTRAINT fk_expenses_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_expenses_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_expenses_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id),
    CONSTRAINT fk_expenses_category FOREIGN KEY (expense_category_id) REFERENCES expense_categories (expense_category_id),
    CONSTRAINT fk_expenses_voided_by FOREIGN KEY (voided_by) REFERENCES users (user_id),
    CONSTRAINT fk_expenses_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_expenses_amount CHECK (amount > 0),
    CONSTRAINT chk_expenses_payment CHECK (payment_type IN ('CASH', 'QRIS', 'DEBIT', 'CREDIT_CARD', 'TRANSFER', 'E_WALLET')),
    CONSTRAINT chk_expenses_status CHECK (status IN ('DRAFT', 'POSTED', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Expense tunai yang POSTED wajib membuat cash_transactions bertipe CASH_OUT
-- dengan reference_type = 'EXPENSE' (Addendum 60).

-- ============================================================================
-- 6. CLOSING (PRD 20, 21, Addendum 43, 44, 51)
-- ============================================================================

-- Snapshot hasil closing (PRD 20). Snapshot REPLACED tidak dihitung (Addendum 25)
CREATE TABLE fiscal_closings (
    fiscal_closing_id   BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'VALID',
    version             INT NOT NULL DEFAULT 1, -- closing ulang setelah reopen menaikkan version

    -- Inventory (PRD 17)
    opening_inventory_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    purchase_value          DECIMAL(18,2) NOT NULL DEFAULT 0,
    purchase_return_value   DECIMAL(18,2) NOT NULL DEFAULT 0,
    sales_cost_value        DECIMAL(18,2) NOT NULL DEFAULT 0,
    sales_return_cost_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    adjustment_value        DECIMAL(18,2) NOT NULL DEFAULT 0,
    ending_inventory_value  DECIMAL(18,2) NOT NULL DEFAULT 0,

    -- HPP & Profit (PRD 14, 19, Addendum 60)
    cogs_value              DECIMAL(18,2) NOT NULL DEFAULT 0,
    gross_profit            DECIMAL(18,2) NOT NULL DEFAULT 0, -- net_sales - cogs_value
    expense_total           DECIMAL(18,2) NOT NULL DEFAULT 0, -- biaya operasional POSTED pada period ini
    net_profit              DECIMAL(18,2) NOT NULL DEFAULT 0, -- gross_profit - expense_total

    -- Cash (PRD 18) — agregat dari seluruh shift yang CLOSED
    opening_cash            DECIMAL(18,2) NOT NULL DEFAULT 0,
    cash_sales              DECIMAL(18,2) NOT NULL DEFAULT 0,
    cash_in                 DECIMAL(18,2) NOT NULL DEFAULT 0,
    cash_out                DECIMAL(18,2) NOT NULL DEFAULT 0,
    refund                  DECIMAL(18,2) NOT NULL DEFAULT 0,
    ending_cash             DECIMAL(18,2) NOT NULL DEFAULT 0,
    cash_difference         DECIMAL(18,2) NOT NULL DEFAULT 0,

    -- Report Sales & Payment (PRD 19)
    gross_sales             DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_total          DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_sales               DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_total               DECIMAL(18,2) NOT NULL DEFAULT 0,
    payment_rounding_total  DECIMAL(18,2) NOT NULL DEFAULT 0, -- akumulasi selisih pembulatan pembayaran (Addendum 51.4)
    payment_cash            DECIMAL(18,2) NOT NULL DEFAULT 0,
    payment_qris            DECIMAL(18,2) NOT NULL DEFAULT 0,
    payment_debit           DECIMAL(18,2) NOT NULL DEFAULT 0,
    payment_credit_card     DECIMAL(18,2) NOT NULL DEFAULT 0,
    payment_transfer        DECIMAL(18,2) NOT NULL DEFAULT 0,
    payment_ewallet         DECIMAL(18,2) NOT NULL DEFAULT 0,

    closed_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_by               BIGINT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fiscal_closing_id),
    UNIQUE KEY uk_closings_version (fiscal_period_id, version),
    KEY idx_closing_period (fiscal_period_id, status),
    CONSTRAINT fk_closings_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_closings_closed_by FOREIGN KEY (closed_by) REFERENCES users (user_id),
    CONSTRAINT chk_closings_status CHECK (status IN ('VALID', 'REPLACED', 'FAILED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Detail closing per produk (PRD 21, Addendum 51: rounding_adjustment)
CREATE TABLE fiscal_closing_items (
    closing_item_id     BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_closing_id   BIGINT NOT NULL,
    product_id          BIGINT NOT NULL,
    opening_qty         DECIMAL(18,4) NOT NULL DEFAULT 0,
    opening_value       DECIMAL(18,2) NOT NULL DEFAULT 0,
    purchase_qty        DECIMAL(18,4) NOT NULL DEFAULT 0,
    purchase_value      DECIMAL(18,2) NOT NULL DEFAULT 0,
    sales_qty           DECIMAL(18,4) NOT NULL DEFAULT 0,
    cogs                DECIMAL(18,2) NOT NULL DEFAULT 0,
    adjustment_qty      DECIMAL(18,4) NOT NULL DEFAULT 0,
    adjustment_value    DECIMAL(18,2) NOT NULL DEFAULT 0,
    return_qty          DECIMAL(18,4) NOT NULL DEFAULT 0,
    return_value        DECIMAL(18,2) NOT NULL DEFAULT 0,
    ending_qty          DECIMAL(18,4) NOT NULL DEFAULT 0,
    ending_value        DECIMAL(18,2) NOT NULL DEFAULT 0,
    average_cost        DECIMAL(18,4) NOT NULL DEFAULT 0,
    rounding_adjustment DECIMAL(18,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (closing_item_id),
    UNIQUE KEY uk_closing_items (fiscal_closing_id, product_id),
    CONSTRAINT fk_closing_items_closing FOREIGN KEY (fiscal_closing_id) REFERENCES fiscal_closings (fiscal_closing_id),
    CONSTRAINT fk_closing_items_product FOREIGN KEY (product_id) REFERENCES products (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log proses closing untuk recovery (Addendum 44)
CREATE TABLE closing_attempt_logs (
    attempt_log_id      BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    step                VARCHAR(50) NOT NULL, -- CHECK TRANSACTION / CALCULATE HPP / dst.
    status              VARCHAR(10) NOT NULL,
    error_message       TEXT,
    started_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at         DATETIME NULL,
    PRIMARY KEY (attempt_log_id),
    CONSTRAINT fk_attempts_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT chk_attempts_status CHECK (status IN ('STARTED', 'SUCCESS', 'FAILED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 7. PENOMORAN & AKUNTANSI (PRD 30, Addendum 52, 53)
-- ============================================================================

-- Sequence nomor transaksi: reset per fiscal period (Addendum 52).
-- WAJIB SELECT ... FOR UPDATE saat mengambil nomor agar tidak ganda.
CREATE TABLE document_numberings (
    numbering_id        BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    fiscal_period_id    BIGINT NOT NULL,
    doc_prefix          VARCHAR(30) NOT NULL, -- INV / PUR / PAY / dst.
    current_sequence    BIGINT NOT NULL DEFAULT 0,
    last_number         VARCHAR(40) NULL,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (numbering_id),
    UNIQUE KEY uk_numberings (owner_id, branch_id, fiscal_period_id, doc_prefix),
    CONSTRAINT fk_numberings_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT fk_numberings_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_numberings_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE chart_of_accounts (
    account_id          BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    account_code        VARCHAR(20) NOT NULL,
    account_name        VARCHAR(150) NOT NULL,
    account_type        VARCHAR(20) NOT NULL,
    PRIMARY KEY (account_id),
    UNIQUE KEY uk_accounts (owner_id, account_code),
    CONSTRAINT fk_accounts_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT chk_accounts_type CHECK (account_type IN ('ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE journal_entries (
    journal_entry_id    BIGINT NOT NULL AUTO_INCREMENT,
    fiscal_period_id    BIGINT NOT NULL,
    branch_id           BIGINT NOT NULL,
    entry_date          DATE NOT NULL,
    entry_type          VARCHAR(30) NOT NULL,
    reference_type      VARCHAR(30) NULL,
    reference_id        BIGINT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          BIGINT NULL,
    PRIMARY KEY (journal_entry_id),
    CONSTRAINT fk_journal_period FOREIGN KEY (fiscal_period_id) REFERENCES fiscal_periods (fiscal_period_id),
    CONSTRAINT fk_journal_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_journal_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT chk_journal_type CHECK (entry_type IN ('CLOSING', 'OPENING', 'REOPEN_CORRECTION'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE journal_entry_lines (
    line_id             BIGINT NOT NULL AUTO_INCREMENT,
    journal_entry_id    BIGINT NOT NULL,
    account_id          BIGINT NOT NULL,
    debit               DECIMAL(18,2) NOT NULL DEFAULT 0,
    credit              DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency_code       CHAR(3) NOT NULL DEFAULT 'IDR',
    exchange_rate       DECIMAL(18,6) NOT NULL DEFAULT 1,
    PRIMARY KEY (line_id),
    CONSTRAINT fk_jel_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (journal_entry_id),
    CONSTRAINT fk_jel_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts (account_id),
    CONSTRAINT fk_jel_currency FOREIGN KEY (currency_code) REFERENCES currencies (currency_code),
    CONSTRAINT chk_jel_values CHECK (NOT (debit = 0 AND credit = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catatan: keseimbangan debit = credit per journal_entry dijamin di service
-- atau trigger, bukan di tabel (tidak bisa dengan CHECK antar-baris).

-- ============================================================================
-- 8. AUDIT & SINKRONISASI OFFLINE (Addendum 62, 63)
-- ============================================================================

-- Jejak perubahan data di luar fiscal_period_logs & hpp_recalc_logs, terutama
-- master yang memengaruhi HPP: harga, konversi UOM, metode costing (Addendum 62).
CREATE TABLE audit_logs (
    audit_id            BIGINT NOT NULL AUTO_INCREMENT,
    owner_id            BIGINT NOT NULL,
    branch_id           BIGINT NULL,
    user_id             BIGINT NOT NULL,
    action              VARCHAR(15) NOT NULL, -- CREATE | UPDATE | DELETE | VOID | APPROVE | REJECT
    entity_type         VARCHAR(50) NOT NULL, -- nama tabel: products / product_uoms / owners / sales
    entity_id           BIGINT NOT NULL,
    field_name          VARCHAR(50) NULL, -- NULL = perubahan seluruh record
    old_value           TEXT,
    new_value           TEXT,
    reason              TEXT,
    ip_address          VARCHAR(45) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY idx_audit_entity (entity_type, entity_id, created_at),
    KEY idx_audit_user (owner_id, user_id, created_at),
    CONSTRAINT fk_audit_owner FOREIGN KEY (owner_id) REFERENCES owners (owner_id),
    CONSTRAINT fk_audit_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (user_id),
    CONSTRAINT chk_audit_action CHECK (action IN ('CREATE', 'UPDATE', 'DELETE', 'VOID', 'APPROVE', 'REJECT'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TAHAP 2 — belum dipakai pada tahap 1 (Addendum 63). Tabel disiapkan lebih awal
-- agar kolom sales.device_id / local_uuid punya pasangan saat mode offline
-- diaktifkan. Idempotensi dijaga UNIQUE (device_id, local_uuid): sync berulang
-- tidak boleh menghasilkan transaksi ganda.
CREATE TABLE offline_sync_queue (
    queue_id            BIGINT NOT NULL AUTO_INCREMENT,
    branch_id           BIGINT NOT NULL,
    device_id           VARCHAR(50) NOT NULL,
    local_uuid          CHAR(36) NOT NULL,
    entity_type         VARCHAR(30) NOT NULL, -- SALES / PAYMENT / EXPENSE
    payload             JSON NOT NULL, -- isi transaksi apa adanya dari perangkat
    local_created_at    DATETIME NOT NULL, -- waktu di perangkat kasir
    received_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status              VARCHAR(10) NOT NULL DEFAULT 'PENDING', -- PENDING | SYNCED | REJECTED
    synced_at           DATETIME NULL,
    reference_id        BIGINT NULL, -- id transaksi final setelah sync berhasil
    final_number        VARCHAR(40) NULL, -- nomor final hasil document_numberings
    error_code          VARCHAR(30) NULL, -- PERIOD_CLOSED / STOCK_NEGATIVE / VALIDATION
    error_message       TEXT,
    resolved_by         BIGINT NULL,
    resolved_at         DATETIME NULL,
    PRIMARY KEY (queue_id),
    UNIQUE KEY uk_offline_local (device_id, local_uuid),
    KEY idx_offline_status (branch_id, status, received_at),
    CONSTRAINT fk_offline_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_offline_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (user_id),
    CONSTRAINT chk_offline_status CHECK (status IN ('PENDING', 'SYNCED', 'REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Transaksi offline yang saat sync jatuh pada period CLOSING/CLOSED ditolak
-- (status REJECTED, error_code PERIOD_CLOSED) dan wajib ditinjau OWNER/SUPER
-- ADMIN — tidak boleh dipaksa masuk ke period yang sudah ditutup (Addendum 63).

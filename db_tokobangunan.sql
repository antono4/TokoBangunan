-- =====================================================================
-- BLUEPRINT DATABASE APLIKASI PENJUALAN "TOKO BANGUNAN"
-- Nama Database : db_tokobangunan
-- Engine        : InnoDB (mendukung Foreign Key & Transaksi)
-- Charset       : utf8mb4 (aman untuk karakter khusus)
-- Cara pakai    : import file ini via phpMyAdmin / MySQL Workbench
--                 atau: mysql -u root -p < db_tokobangunan.sql
-- =====================================================================

-- Bersihkan database lama bila ada (HATI-HATI: data akan hilang)
DROP DATABASE IF EXISTS db_tokobangunan;

CREATE DATABASE db_tokobangunan
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE db_tokobangunan;

-- =====================================================================
-- 1. TABEL : users  (akun login admin & kasir)
-- =====================================================================
CREATE TABLE users (
  id           INT(11)       NOT NULL AUTO_INCREMENT,
  username     VARCHAR(50)   NOT NULL,
  password     VARCHAR(255)  NOT NULL COMMENT 'Simpan HASH (bcrypt/password_hash), jangan plain text',
  nama_lengkap VARCHAR(100)  DEFAULT NULL,
  role         ENUM('admin','kasir') NOT NULL DEFAULT 'kasir',
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 2. TABEL : supplier  (pemasok material bangunan)
-- =====================================================================
CREATE TABLE supplier (
  id            INT(11)      NOT NULL AUTO_INCREMENT,
  nama_supplier VARCHAR(100) NOT NULL,
  telepon       VARCHAR(20)  DEFAULT NULL,
  alamat        TEXT         DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 3. TABEL : pelanggan  (pembeli: reguler / kontraktor)
-- =====================================================================
CREATE TABLE pelanggan (
  id             INT(11)      NOT NULL AUTO_INCREMENT,
  nama_pelanggan VARCHAR(100) NOT NULL,
  telepon        VARCHAR(20)  DEFAULT NULL,
  alamat         TEXT         DEFAULT NULL,
  tipe           ENUM('reguler','kontraktor') NOT NULL DEFAULT 'reguler',
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pelanggan_nama (nama_pelanggan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 4. TABEL : produk  (master stok bahan bangunan, satuan bervariasi)
-- =====================================================================
CREATE TABLE produk (
  id          INT(11)       NOT NULL AUTO_INCREMENT,
  kode_produk VARCHAR(20)   NOT NULL,
  nama_produk VARCHAR(150)  NOT NULL,
  kategori    VARCHAR(50)   DEFAULT NULL COMMENT 'semen, besi, cat, kabel, keramik, dll',
  satuan      VARCHAR(20)   NOT NULL DEFAULT 'pcs' COMMENT 'sak/pcs/batang/meter/dus/kg/roll/m2',
  harga_beli  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  harga_jual  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  stok        INT(11)       NOT NULL DEFAULT 0,
  stok_minimum INT(11)      NOT NULL DEFAULT 10 COMMENT 'batas alert stok menipis',
  supplier_id INT(11)       DEFAULT NULL,
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_produk_kode (kode_produk),
  KEY idx_produk_nama (nama_produk),
  KEY idx_produk_kategori (kategori),
  CONSTRAINT fk_produk_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 5. TABEL : penjualan  (header transaksi / faktur)
-- =====================================================================
CREATE TABLE penjualan (
  id            INT(11)       NOT NULL AUTO_INCREMENT,
  no_faktur     VARCHAR(30)   NOT NULL COMMENT 'contoh format: TRX-20261103-0001',
  tanggal       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total_bayar   DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'total setelah diskon',
  diskon        DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'nilai diskon dalam rupiah',
  user_id       INT(11)       NOT NULL COMMENT 'kasir yang melayani',
  pelanggan_id  INT(11)       DEFAULT NULL COMMENT 'NULL = pembeli umum',
  PRIMARY KEY (id),
  UNIQUE KEY uq_penjualan_faktur (no_faktur),
  KEY idx_penjualan_tanggal (tanggal),
  CONSTRAINT fk_penjualan_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_penjualan_pelanggan
    FOREIGN KEY (pelanggan_id) REFERENCES pelanggan (id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 6. TABEL : detail_penjualan  (item per faktur)
-- =====================================================================
CREATE TABLE detail_penjualan (
  id           INT(11)       NOT NULL AUTO_INCREMENT,
  penjualan_id INT(11)       NOT NULL,
  produk_id    INT(11)       NOT NULL,
  jumlah       INT(11)       NOT NULL COMMENT 'qty sesuai satuan produk',
  harga_satuan DECIMAL(12,2) NOT NULL COMMENT 'snapshot harga jual saat transaksi',
  subtotal     DECIMAL(14,2) NOT NULL COMMENT 'jumlah * harga_satuan',
  PRIMARY KEY (id),
  KEY idx_detail_penjualan (penjualan_id),
  KEY idx_detail_produk (produk_id),
  CONSTRAINT fk_detail_penjualan
    FOREIGN KEY (penjualan_id) REFERENCES penjualan (id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_detail_produk
    FOREIGN KEY (produk_id) REFERENCES produk (id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 6b. TABEL : pengaturan  (identitas toko, bisa diedit dari halaman
--     Pengaturan -> dipakai di sidebar & header struk)
-- =====================================================================
CREATE TABLE pengaturan (
  id    INT(11)     NOT NULL AUTO_INCREMENT,
  kunci VARCHAR(50) NOT NULL COMMENT 'nama_toko / alamat_toko / telepon_toko',
  nilai TEXT        DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pengaturan_kunci (kunci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 7. TRIGGER OTOMATIS: stok berkurang saat penjualan
-- =====================================================================
DELIMITER $$

-- a) Validasi & potong stok saat item penjualan dibuat
CREATE TRIGGER trg_kurangi_stok
AFTER INSERT ON detail_penjualan
FOR EACH ROW
BEGIN
  -- Tolak transaksi jika stok tidak cukup
  IF (SELECT stok FROM produk WHERE id = NEW.produk_id) < NEW.jumlah THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Transaksi ditolak: stok produk tidak mencukupi!';
  END IF;

  UPDATE produk
     SET stok = stok - NEW.jumlah
   WHERE id = NEW.produk_id;
END$$

-- b) Kembalikan stok bila item transaksi dibatalkan/dihapus
CREATE TRIGGER trg_kembalikan_stok
AFTER DELETE ON detail_penjualan
FOR EACH ROW
BEGIN
  UPDATE produk
     SET stok = stok + OLD.jumlah
   WHERE id = OLD.produk_id;
END$$

-- c) Sesuaikan stok bila item transaksi di-edit
CREATE TRIGGER trg_sesuaikan_stok
AFTER UPDATE ON detail_penjualan
FOR EACH ROW
BEGIN
  UPDATE produk
     SET stok = stok + OLD.jumlah - NEW.jumlah
   WHERE id = OLD.produk_id;
END$$

DELIMITER ;

-- =====================================================================
-- 8. VIEW SIAP PAKAI UNTUK LAPORAN
-- =====================================================================

-- Ringkasan omzet per hari
CREATE VIEW v_laporan_harian AS
SELECT
  DATE(p.tanggal)             AS tanggal,
  COUNT(p.id)                 AS jumlah_transaksi,
  SUM(d.jumlah)               AS total_item,
  SUM(p.total_bayar)          AS total_omzet
FROM penjualan p
JOIN detail_penjualan d ON d.penjualan_id = p.id
GROUP BY DATE(p.tanggal)
ORDER BY tanggal DESC;

-- Barang paling laris (top penjualan)
CREATE VIEW v_produk_terlaris AS
SELECT
  pr.nama_produk,
  pr.satuan,
  SUM(d.jumlah)          AS total_terjual,
  SUM(d.subtotal)        AS total_pendapatan
FROM detail_penjualan d
JOIN produk pr ON pr.id = d.produk_id
GROUP BY pr.id, pr.nama_produk, pr.satuan
ORDER BY total_terjual DESC;

-- Stok menipis (di bawah batas minimum)
CREATE VIEW v_stok_menipis AS
SELECT
  kode_produk,
  nama_produk,
  satuan,
  stok,
  stok_minimum
FROM produk
WHERE stok < stok_minimum
ORDER BY stok ASC;

-- =====================================================================
-- 9. DATA CONTOH (SEED DATA)
-- Password default "admin123" / "kasir123" (ganti dengan hash asli
-- dari PHP password_hash() saat implementasi backend)
-- =====================================================================
INSERT INTO users (username, password, nama_lengkap, role) VALUES
('admin',  '$2y$10$q4goGJO/3gj/gJVAwElHBOamDvbBmXSIX74eeidJgofQud1AyhuyK', 'Administrator', 'admin'),
('kasir1', '$2y$10$bA2gOjRcEOXcMyob9HwrJu7Sx2sJcHswryZH5DvNmDdHHNQ0m4y5G', 'Kasir Toko',    'kasir');
-- Password di atas sudah di-hash dengan password_hash() PHP:
--   admin  : admin123
--   kasir1 : kasir123

INSERT INTO supplier (nama_supplier, telepon, alamat) VALUES
('PT Semen Nusantara', '021-5550123', 'Jl. Industri Raya No. 10, Jakarta Utara'),
('CV Besi Baja Jaya', '081234567890', 'Jl. Pasar Baru No. 45, Bekasi'),
('Toko Cat & Kimia Abadi', '087654321098', 'Jl. Raya Bogor Km 25, Depok');

INSERT INTO pelanggan (nama_pelanggan, telepon, alamat, tipe) VALUES
('Budi Santoso', '081298765432', 'Jl. Melati No. 7, Depok', 'reguler'),
('CV Kontraktor Bangun Jaya', '0217788123', 'Jl. Proyek Raya No. 1, Jakarta', 'kontraktor'),
('Pembeli Umum', NULL, NULL, 'reguler');

INSERT INTO produk (kode_produk, nama_produk, kategori, satuan, harga_beli, harga_jual, stok, stok_minimum, supplier_id) VALUES
('PRD-001', 'Semen Gresik 50kg',        'Semen',   'sak',     55000.00, 62000.00, 120, 10, 1),
('PRD-002', 'Semen Holcim 50kg',         'Semen',   'sak',     58000.00, 65000.00,  80, 10, 1),
('PRD-003', 'Bata Merah Press',          'Bata',    'pcs',      1000.00,  1400.00, 500, 50, 2),
('PRD-004', 'Besi Beton 10mm x 12m',     'Besi',    'batang',  62000.00, 72000.00,  45, 10, 2),
('PRD-005', 'Besi Beton 12mm x 12m',     'Besi',    'batang',  88000.00,102000.00,  30, 10, 2),
('PRD-006', 'Cat Tembok Avitex 5kg',     'Cat',     'dus',     48000.00, 59000.00,  25,  5, 3),
('PRD-007', 'Kabel NYM 3x2.5mm (per meter)','Kabel','meter',   15000.00, 19000.00, 900, 20, 3),
('PRD-008', 'Keramik Lantai 60x60',      'Keramik', 'm2',      78000.00, 95000.00,  60,  8, 3),
('PRD-009', 'Paku 3 inci (per kg)',      'Aksesoris','kg',     12000.00, 16000.00,  15,  5, 2),
('PRD-010', 'Sika Beton 1Liter',         'Kimia',   'liter',   35000.00, 43000.00,   8,  5, 3);

-- Password di atas sudah di-hash dengan password_hash() PHP:
--   admin  : admin123
--   kasir1 : kasir123
-- User tambahan (beserta level/role) dapat dibuat dari halaman
-- "Pengaturan & User" di dalam aplikasi (menu admin).

INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES
('nama_toko',    'TOKO BANGUNAN MANDIRI'),
('alamat_toko',  'Jl. Raya Merdeka No. 123, Depok'),
('telepon_toko', '(021) 555-0123');

-- Contoh 1 transaksi penjualan (biar chart dashboard tidak kosong)
INSERT INTO penjualan (no_faktur, tanggal, total_bayar, diskon, user_id, pelanggan_id)
VALUES ('TRX-20261103-0001', NOW(), 124000.00, 0.00, 2, 1);

INSERT INTO detail_penjualan (penjualan_id, produk_id, jumlah, harga_satuan, subtotal) VALUES
(1, 1, 2, 62000.00, 124000.00);
-- (Trigger otomatis sudah memotong stok PRD-001 menjadi 118)

-- =====================================================================
-- 10. CONTOH QUERY LAPORAN (siap dipakai backend PHP)
-- =====================================================================
-- Laporan harian        : SELECT * FROM v_laporan_harian WHERE tanggal = CURDATE();
-- Laporan bulanan       : SELECT * FROM v_laporan_harian WHERE DATE_FORMAT(tanggal,'%Y-%m') = '2026-11';
-- Barang paling laris   : SELECT * FROM v_produk_terlaris LIMIT 10;
-- Alert stok menipis    : SELECT * FROM v_stok_menipis;
-- Omzet hari ini        : SELECT COALESCE(SUM(total_bayar),0) FROM penjualan WHERE DATE(tanggal) = CURDATE();
-- No. faktur otomatis   : SELECT CONCAT('TRX-', DATE_FORMAT(NOW(),'%Y%m%d'), '-', LPAD(IFNULL(
--                           MAX(SUBSTRING(no_faktur, -4)), 0) + 1, 4, '0'))
--                           FROM penjualan WHERE DATE(tanggal) = CURDATE();

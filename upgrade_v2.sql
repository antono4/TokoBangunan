-- =====================================================================
-- UPGRADE v2 : Pengaturan Toko (nama/alamat/telepon yang bisa diedit)
-- Jalankan file ini di phpMyAdmin bila database db_tokobangunan
-- sudah terpasang dari versi sebelumnya. (Untuk instalasi baru,
-- file db_tokobangunan.sql sudah memuat tabel ini.)
-- =====================================================================
USE db_tokobangunan;

CREATE TABLE IF NOT EXISTS pengaturan (
  id    INT(11)    NOT NULL AUTO_INCREMENT,
  kunci VARCHAR(50) NOT NULL COMMENT 'nama_toko / alamat_toko / telepon_toko',
  nilai TEXT        DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pengaturan_kunci (kunci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES
('nama_toko',   'TOKO BANGUNAN MANDIRI'),
('alamat_toko', 'Jl. Raya Merdeka No. 123, Depok'),
('telepon_toko', '(021) 555-0123');

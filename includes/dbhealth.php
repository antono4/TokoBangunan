<?php
/**
 * includes/dbhealth.php
 * -----------------------------------------------------------------
 * Helper kesehatan database: memeriksa status (koneksi, tabel, trigger,
 * akun) dan memperbaiki hal yang bisa diperbaiki otomatis
 * (buat ulang trigger stok, tabel pengaturan, seed akun bila kosong).
 * Dipakai api/cek_database.php dan api/perbaiki_database.php.
 */

/** Sambung ke MySQL TANPA memilih database (untuk deteksi MySQL mati/hidup). */
function dbhealth_connect_server(): ?PDO
{
    try {
        return new PDO(
            'mysql:host=' . DB_HOST . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Status lengkap kesehatan database.
 * @return array{ok: bool, status: array<string, bool>, kendala: array<int, array{kode:string, pesan:string, solusi:string}>}
 */
function dbhealth_status(): array
{
    $kendala = [];
    $st = [
        'mysql_aktif'      => false,
        'database_ada'     => false,
        'tabel_lengkap'    => false,
        'akun_terisi'      => false,
        'trigger_lengkap'  => false,
        'pengaturan_ok'    => false,
    ];

    // 0) MySQL hidup?
    $dbo = dbhealth_connect_server();
    if ($dbo === null) {
        $kendala[] = [
            'kode'   => 'MYSQL_MATI',
            'pesan'  => 'MySQL tidak dapat dihubungi (host ' . DB_HOST . ' dan user "' . DB_USER . '" salah, atau MySQL belum Start).',
            'solusi' => 'Buka daftar Kontrol XAMPP -> klik Start pada modul MySQL, lalu periksa DB_USER/DB_PASS di includes/config.php.',
        ];
        return ['ok' => false, 'status' => $st, 'kendala' => $kendala];
    }
    $st['mysql_aktif'] = true;

    // 1) Database ada?
    $q = $dbo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $q->execute([DB_NAME]);
    if (!$q->fetchColumn()) {
        $kendala[] = [
            'kode'   => 'DATABASE_BELUM_ADA',
            'pesan'  => 'Database "' . DB_NAME . '" belum ada di server MySQL.',
            'solusi' => 'Buka http://localhost/phpmyadmin -> tab Import -> pilih file db_tokobangunan.sql -> Import.',
        ];
        return ['ok' => true, 'status' => $st, 'kendala' => $kendala];
    }
    $st['database_ada'] = true;

    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        $kendala[] = ['kode' => 'AKSES_DB', 'pesan' => 'Tidak dapat membuka database: ' . $e->getMessage(),
            'solusi' => 'Cek DB_USER/DB_PASS di includes/config.php (privilegien akses).'];
        return ['ok' => true, 'status' => $st, 'kendala' => $kendala];
    }

    // 2) Tabel wajib ada?
    $q = $dbo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $q->execute([DB_NAME]);
    $ada  = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $ada[strtolower($t)] = true;
    }
    $wajib = ['users', 'supplier', 'pelanggan', 'produk', 'penjualan', 'detail_penjualan', 'pengaturan'];
    $kekuranganTabel = [];
    foreach ($wajib as $t) {
        if (!isset($ada[$t])) $kekuranganTabel[] = $t;
    }
    if ($kekuranganTabel) {
        $kendala[] = [
            'kode'   => 'TABEL_KURANG',
            'pesan'  => 'Tabel yang belum ada: ' . implode(', ', $kekuranganTabel) . '.',
            'solusi' => 'Import ulang db_tokobangunan.sql di phpMyAdmin (data transaksional lama kembali ke contoh).',
        ];
    } else {
        $st['tabel_lengkap'] = true;
    }

    // 3) Akun ada?
    if ($st['tabel_lengkap']) {
        $jumlahUser = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($jumlahUser === 0) {
            $kendala[] = [
                'kode'   => 'AKUN_KOSONG',
                'pesan'  => 'Tabel users kosong - tidak ada akun untuk login.',
                'solusi' => 'Gunakan tombol "Periksa & Perbaiki" di halaman Pengaturan, atau pengguna admin/kasir akan dibuat otomatis akun default: admin/admin123 & kasir1/kasir123.',
            ];
        } else {
            $st['akun_terisi'] = true;
        }

        // 4) Trigger stok lengkap?
        $q = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                           WHERE EVENT_OBJECT_TABLE = 'detail_penjualan'");
        $trig = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) $trig[$t] = true;
        $trigWajib = ['trg_kurangi_stok', 'trg_kembalikan_stok', 'trg_sesuaikan_stok'];
        $trigKurang = [];
        foreach ($trigWajib as $t) {
            if (!isset($trig[$t])) $trigKurang[] = $t;
        }
        if ($trigKurang) {
            $kendala[] = [
                'kode'   => 'TRIGGER_KURANG',
                'pesan'  => 'Trigger stok belum lengkap. Kurang: ' . implode(', ', $trigKurang) . '.',
                'solusi' => 'Gunakan tombol "Periksa & Perbaiki" di halaman Pengaturan (trigger dibuat ulang otomatis).',
            ];
        } else {
            $st['trigger_lengkap'] = true;
        }

        // 5) Pengaturan terisi?
        $jumlahPeng = (int)$pdo->query('SELECT COUNT(*) FROM pengaturan')->fetchColumn();
        if ($jumlahPeng === 0) {
            $kendala[] = [
                'kode'   => 'PENGATURAN_KOSONG',
                'pesan'  => 'Tabel pengaturan belum terisi (nama toko belum ada).',
                'solusi' => 'Tombol "Periksa & Perbaiki" juga mengosongkan bagian ini.',
            ];
        } else {
            $st['pengaturan_ok'] = true;
        }
    }

    return ['ok' => true, 'status' => $st, 'kendala' => $kendala];
}

/**
 * Perbaikan otomatis yang aman:
 *  - tabel pengaturan dibuat (bila belum ada) + data default;
 *  - 3 trigger stok dibuat ulang (drop + create);
 *  - akun default admin & kasir dibuat bila tabel users kosong.
 * @return array{kasus: array<int,string>, gagal: array<int,string>}
 */
function dbhealth_repair(): array
{
    $pdo   = db();
    $kasus = [];
    $gagal = [];

    // ---- 1. Tabel pengaturan + data default ----
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS pengaturan (
            id    INT(11)     NOT NULL AUTO_INCREMENT,
            kunci VARCHAR(50) NOT NULL,
            nilai TEXT        DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pengaturan_kunci (kunci)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec("INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES
            ('nama_toko',    'TOKO BANGUNAN MANDIRI'),
            ('alamat_toko',  'Jl. Raya Merdeka No. 123, Depok'),
            ('telepon_toko', '(021) 555-0123'),
            ('pajak',        '0.12')");
        $kasus[] = 'Tabel pengaturan dipastikan ada & terisi.';
    } catch (PDOException $e) {
        $gagal[] = 'Tabel pengaturan: ' . $e->getMessage();
    }

    // ---- 1b. Tabel retur & retur_detail (nota kredit) ----
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS retur (
            id            INT(11)      NOT NULL AUTO_INCREMENT,
            no_faktur    VARCHAR(30)  NOT NULL,
            tanggal       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id      INT(11)      NOT NULL,
            pelanggan_id INT(11)      NULL,
            total_retur  DECIMAL(14,2) NOT NULL DEFAULT 0,
            alasan       VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_retur_faktur (no_faktur),
            CONSTRAINT fk_retur_faktur FOREIGN KEY (no_faktur) REFERENCES penjualan (no_faktur) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_retur_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_retur_pelanggan FOREIGN KEY (pelanggan_id) REFERENCES pelanggan (id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE IF NOT EXISTS retur_detail (
            id          INT(11)      NOT NULL AUTO_INCREMENT,
            retur_id    INT(11)      NOT NULL,
            produk_id   INT(11)      NOT NULL,
            jumlah      INT(11)      NOT NULL,
            harga_satuan DECIMAL(12,2) NOT NULL,
            subtotal    DECIMAL(14,2) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_retur_detail (retur_id),
            KEY idx_retur_produk (produk_id),
            CONSTRAINT fk_retur_detail_retur FOREIGN KEY (retur_id) REFERENCES retur (id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_retur_detail_produk FOREIGN KEY (produk_id) REFERENCES produk (id) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $kasus[] = 'Tabel retur & retur_detail dipastikan ada.';
    } catch (PDOException $e) {
        $gagal[] = 'Tabel retur: ' . $e->getMessage();
    }

    // ---- 2. Trigger stok (drop lalu buat ulang agar pasti sesuai) ----
    try {
        foreach (['trg_kurangi_stok', 'trg_kembalikan_stok', 'trg_sesuaikan_stok'] as $t) {
            $pdo->exec("DROP TRIGGER IF EXISTS $t");
        }
        $pdo->exec("
        CREATE TRIGGER trg_kurangi_stok
        AFTER INSERT ON detail_penjualan
        FOR EACH ROW
        BEGIN
          IF (SELECT stok FROM produk WHERE id = NEW.produk_id) < NEW.jumlah THEN
            SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'Transaksi ditolak: stok produk tidak mencukupi!';
          END IF;
          UPDATE produk SET stok = stok - NEW.jumlah WHERE id = NEW.produk_id;
        END");
        $pdo->exec("
        CREATE TRIGGER trg_kembalikan_stok
        AFTER DELETE ON detail_penjualan
        FOR EACH ROW
        BEGIN
          UPDATE produk SET stok = stok + OLD.jumlah WHERE id = OLD.produk_id;
        END");
        $pdo->exec("
        CREATE TRIGGER trg_sesuaikan_stok
        AFTER UPDATE ON detail_penjualan
        FOR EACH ROW
        BEGIN
          UPDATE produk SET stok = stok + OLD.jumlah - NEW.jumlah WHERE id = OLD.produk_id;
        END");
        $kasus[] = '3 trigger stok dibuat ulang (kurangi/kembalikan/sesuaikan).';
    } catch (PDOException $e) {
        $gagal[] = 'Trigger: ' . $e->getMessage();
    }

    // ---- 3. Akun default bila tabel users kosong ----
    try {
        $jumlah = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($jumlah === 0) {
            $stmt = $pdo->prepare('INSERT INTO users (username, password, nama_lengkap, role) VALUES (?, ?, ?, ?)');
            $stmt->execute(['admin',  '$2y$10$q4goGJO/3gj/gJVAwElHBOamDvbBmXSIX74eeidJgofQud1AyhuyK', 'Administrator', 'admin']);
            $stmt->execute(['kasir1', '$2y$10$bA2gOjRcEOXcMyob9HwrJu7Sx2sJcHswryZH5DvNmDdHHNQ0m4y5G', 'Kasir Toko', 'kasir']);
            $kasus[] = 'Akun admin/kasir dibuat: admin/admin123 & kasir1/kasir123 (harap ganti password setelah login).';
        } else {
            $kasus[] = "Tabel users sudah berisi $jumlah akun (tidak diubah).";
        }
    } catch (PDOException $e) {
        $gagal[] = 'Akun: ' . $e->getMessage();
    }

    return ['kasus' => $kasus, 'gagal' => $gagal];
}

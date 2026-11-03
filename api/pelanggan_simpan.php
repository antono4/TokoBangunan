<?php
/**
 * api/pelanggan_simpan.php - Tambah / Update pelanggan (POST JSON)
 * -----------------------------------------------------------------
 * Tambah : { nama_pelanggan, telepon?, alamat?, tipe? }
 * Update : { id, nama_pelanggan?, telepon?, alamat?, tipe? }
 *
 * Hak akses:
 *  - INSERT  : semua user yang login (kasir butuh tambah cepat di POS)
 *  - UPDATE  : admin saja
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d    = json_input();
$id   = isset($d['id']) && $d['id'] !== '' ? (int)$d['id'] : null;
$nama = trim($d['nama_pelanggan'] ?? '');
$telepon = trim($d['telepon'] ?? '');
$alamat  = trim($d['alamat'] ?? '');
$tipe    = in_array($d['tipe'] ?? '', ['reguler', 'kontraktor'], true) ? $d['tipe'] : 'reguler';

if ($nama === '') json_out(['ok' => false, 'message' => 'Nama pelanggan wajib diisi.'], 422);
if (mb_strlen($nama) > 100) json_out(['ok' => false, 'message' => 'Nama maksimal 100 karakter.'], 422);

$pdo = db();

try {
    if ($id !== null) {
        // ---- UPDATE: admin saja ----
        if ($_SESSION['user']['role'] !== 'admin') {
            json_out(['ok' => false, 'message' => 'Mengubah pelanggan hanya untuk admin.'], 403);
        }
        $stmt = $pdo->prepare(
            'UPDATE pelanggan SET nama_pelanggan = ?, telepon = ?, alamat = ?, tipe = ? WHERE id = ?'
        );
        $stmt->execute([$nama, $telepon ?: null, $alamat ?: null, $tipe, $id]);
        if ($stmt->rowCount() === 0) {
            json_out(['ok' => false, 'message' => 'Pelanggan tidak ditemukan / tidak ada perubahan data.'], 404);
        }
        json_out(['ok' => true, 'message' => "Pelanggan \"$nama\" berhasil diperbarui."]);
    }

    // ---- INSERT: semua user yang login ----
    $stmt = $pdo->prepare(
        'INSERT INTO pelanggan (nama_pelanggan, telepon, alamat, tipe) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$nama, $telepon ?: null, $alamat ?: null, $tipe]);

    json_out(['ok' => true,
              'message' => "Pelanggan \"$nama\" berhasil ditambahkan.",
              'pelanggan' => ['id' => (int)$pdo->lastInsertId(), 'nama_pelanggan' => $nama, 'tipe' => $tipe]]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'message' => 'Gagal menyimpan pelanggan: ' . $e->getMessage()], 500);
}

<?php
/**
 * api/pelanggan_simpan.php - Tambah pelanggan baru (POST JSON)
 * Body: { nama_pelanggan, telepon?, alamat?, tipe? (reguler/kontraktor) }
 * Dipakai dengan select pelanggan di halaman kasir.
 * Akses: semua user yang login.
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d    = json_input();
$nama = trim($d['nama_pelanggan'] ?? '');
$telepon = trim($d['telepon'] ?? '');
$alamat  = trim($d['alamat'] ?? '');
$tipe    = in_array($d['tipe'] ?? '', ['reguler', 'kontraktor'], true) ? $d['tipe'] : 'reguler';

if ($nama === '') json_out(['ok' => false, 'message' => 'Nama pelanggan wajib diisi.'], 422);

try {
    $stmt = db()->prepare(
        'INSERT INTO pelanggan (nama_pelanggan, telepon, alamat, tipe) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$nama, $telepon ?: null, $alamat ?: null, $tipe]);

    json_out(['ok' => true,
              'message' => "Pelanggan \"$nama\" berhasil ditambahkan.",
              'pelanggan' => ['id' => (int)db()->lastInsertId(), 'nama_pelanggan' => $nama, 'tipe' => $tipe]]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'message' => 'Gagal menyimpan pelanggan: ' . $e->getMessage()], 500);
}

<?php
/**
 * api/supplier_simpan.php - Tambah / Update supplier (POST JSON)
 * Tambah : { nama_supplier, telepon?, alamat? }
 * Update : { id, nama_supplier?, telepon?, alamat? }
 * Akses: admin saja (master data).
 */
require_once __DIR__ . '/../includes/config.php';
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d    = json_input();
$id   = isset($d['id']) && $d['id'] !== '' ? (int)$d['id'] : null;
$nama = trim($d['nama_supplier'] ?? '');
$telepon = trim($d['telepon'] ?? '');
$alamat  = trim($d['alamat'] ?? '');

if ($nama === '') json_out(['ok' => false, 'message' => 'Nama supplier wajib diisi.'], 422);
if (mb_strlen($nama) > 100) json_out(['ok' => false, 'message' => 'Nama maksimal 100 karakter.'], 422);

try {
    if ($id !== null) {
        $stmt = db()->prepare(
            'UPDATE supplier SET nama_supplier = ?, telepon = ?, alamat = ? WHERE id = ?'
        );
        $stmt->execute([$nama, $telepon ?: null, $alamat ?: null, $id]);
        if ($stmt->rowCount() === 0) {
            json_out(['ok' => false, 'message' => 'Supplier tidak ditemukan / tidak ada perubahan.'], 404);
        }
        json_out(['ok' => true, 'message' => "Supplier \"$nama\" berhasil diperbarui."]);
    }

    $stmt = db()->prepare(
        'INSERT INTO supplier (nama_supplier, telepon, alamat) VALUES (?, ?, ?)'
    );
    $stmt->execute([$nama, $telepon ?: null, $alamat ?: null]);
    json_out(['ok' => true, 'message' => "Supplier \"$nama\" berhasil ditambahkan.",
              'id' => (int)db()->lastInsertId()]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'message' => 'Gagal menyimpan supplier: ' . $e->getMessage()], 500);
}

<?php
/**
 * api/produk_hapus.php - Hapus produk (POST JSON { id })
 * Akses: admin saja.
 * Bila produk sudah pernah masuk detail_penjualan, Foreign Key
 * (ON DELETE RESTRICT) menolak -> kirim pesan yang jelas ke user.
 */
require_once __DIR__ . '/../includes/config.php';
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d  = json_input();
$id = (int)($d['id'] ?? 0);
if ($id <= 0) json_out(['ok' => false, 'message' => 'ID produk tidak valid.'], 422);

try {
    $stmt = db()->prepare('DELETE FROM produk WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        json_out(['ok' => false, 'message' => 'Produk tidak ditemukan.'], 404);
    }
    json_out(['ok' => true, 'message' => 'Produk berhasil dihapus.']);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_out(['ok' => false,
            'message' => 'Produk ini sudah pernah ditransaksikan sehingga tidak bisa dihapus. Nonaktifkan dengan mengubah stok menjadi 0.'], 409);
    }
    json_out(['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()], 500);
}

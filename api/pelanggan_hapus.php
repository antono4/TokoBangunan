<?php
/**
 * api/pelanggan_hapus.php - Hapus pelanggan (POST JSON { id })
 * Akses: admin saja.
 * Pelanggan yang sudah pernah transaksi ditolak (FK RESTRICT) supaya
 * riwayat faktur tetap menunjuk orang yang benar.
 */
require_once __DIR__ . '/../includes/config.php';
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d  = json_input();
$id = (int)($d['id'] ?? 0);
if ($id <= 0) json_out(['ok' => false, 'message' => 'ID pelanggan tidak valid.'], 422);

try {
    $stmt = db()->prepare('DELETE FROM pelanggan WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_out(['ok' => false, 'message' => 'Pelanggan tidak ditemukan.'], 404);
    }
    json_out(['ok' => true, 'message' => 'Pelanggan berhasil dihapus.']);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_out(['ok' => false,
            'message' => 'Pelanggan ini sudah pernah bertransaksi sehingga tidak bisa dihapus.'], 409);
    }
    json_out(['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()], 500);
}

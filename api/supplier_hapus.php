<?php
/**
 * api/supplier_hapus.php - Hapus supplier (POST JSON { id })
 * Akses: admin saja.
 * Produk milik supplier ini tidak ikut terhapus: kolom produk.supplier_id
 * memakai ON DELETE SET NULL, lalu alert "supplier kosong" terlihat di app.
 */
require_once __DIR__ . '/../includes/config.php';
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d  = json_input();
$id = (int)($d['id'] ?? 0);
if ($id <= 0) json_out(['ok' => false, 'message' => 'ID supplier tidak valid.'], 422);

try {
    $stmt = db()->prepare('DELETE FROM supplier WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_out(['ok' => false, 'message' => 'Supplier tidak ditemukan.'], 404);
    }
    json_out(['ok' => true, 'message' => 'Supplier berhasil dihapus (produk miliknya tetap ada, supplier-nya dikosongkan).']);
} catch (PDOException $e) {
    json_out(['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()], 500);
}

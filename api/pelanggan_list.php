<?php
/**
 * api/pelanggan_list.php - Daftar pelanggan (GET, ?q= pencarian opsional)
 * Akses: semua user yang login.
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

$q = trim($_GET['q'] ?? '');

$sql = 'SELECT id, nama_pelanggan, telepon, alamat, tipe FROM pelanggan';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE nama_pelanggan LIKE ? OR telepon LIKE ?';
    $params = ["%$q%", "%$q%"];
}
$sql .= ' ORDER BY nama_pelanggan ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
json_out(['ok' => true, 'pelanggan' => $stmt->fetchAll()]);

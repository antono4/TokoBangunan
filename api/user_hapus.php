<?php
/**
 * api/user_hapus.php - Hapus user (POST JSON { id })
 * Akses: admin saja.
 *
 * Aturan keamanan:
 *  - tidak boleh menghapus akun sendiri
 *  - tidak boleh menghapus admin terakhir
 */
require_once __DIR__ . '/../includes/config.php';
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d  = json_input();
$id = (int)($d['id'] ?? 0);
if ($id <= 0) json_out(['ok' => false, 'message' => 'ID user tidak valid.'], 422);

if ($id === (int)$_SESSION['user']['id']) {
    json_out(['ok' => false, 'message' => 'Tidak bisa menghapus akun yang sedang login.'], 409);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT username, role FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) json_out(['ok' => false, 'message' => 'User tidak ditemukan.'], 404);

if ($user['role'] === 'admin') {
    $jumlahAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($jumlahAdmin <= 1) {
        json_out(['ok' => false,
            'message' => 'Tidak boleh menghapus satu-satunya admin.'], 409);
    }
}

// detail_penjualan -> users RESTRICT: kasir yang pernah melayani tidak boleh
// dihapus (riwayat transaksi harus tetap terhubung). Tangani dengan pesan jelas.
try {
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    json_out(['ok' => true, 'message' => "User \"{$user['username']}\" berhasil dihapus."]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_out(['ok' => false,
            'message' => "User ini sudah pernah melayani transaksi sehingga tidak bisa dihapus. Nonaktifkan dengan mengganti level atau password-nya."], 409);
    }
    json_out(['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()], 500);
}

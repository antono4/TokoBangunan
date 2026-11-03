<?php
/**
 * api/login.php - Autentikasi user (POST username & password)
 * Response sukses: { ok: true, user: {...} }
 */
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$input    = json_input();
$username = trim($input['username'] ?? '');
$password = (string)($input['password'] ?? '');

if ($username === '' || $password === '') {
    json_out(['ok' => false, 'message' => 'Username dan password wajib diisi.'], 422);
}

try {
    $stmt = db()->prepare('SELECT id, username, password, nama_lengkap, role FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    // Tabel users tidak ada / kosong -> beri petunjuk yang jelas (import SQL)
    if (in_array($e->getCode(), ['42S02', '1146'], true)) {
        json_out([
            'ok' => false,
            'message' => 'Tabel "users" belum ada. Database belum diimport: '
                . 'buka http://localhost/phpmyadmin -> tab Import -> pilih db_tokobangunan.sql -> Import.',
            'kode' => 'DATABASE_BELUM_ADA',
        ], 401);
    }
    json_out(['ok' => false, 'message' => 'Terjadi galat database: ' . $e->getMessage()], 500);
}

if (!$user) {
    usleep(300000);
    json_out(['ok' => false, 'message' => 'Username tidak terdaftar.'], 401);
}
if (!password_verify($password, $user['password'])) {
    // Sedikit delay untuk memperlambat brute force
    usleep(300000);
    json_out(['ok' => false, 'message' => 'Password salah untuk user "' . $username . '".'], 401);
}

// Regenerasi ID session untuk mencegah session fixation
session_regenerate_id(true);
$_SESSION['user'] = [
    'id'       => (int)$user['id'],
    'username' => $user['username'],
    'nama'     => $user['nama_lengkap'] ?: $user['username'],
    'role'     => $user['role'],
];

unset($user['password']); // jangan pernah kirim hash ke client
json_out(['ok' => true, 'user' => $user]);

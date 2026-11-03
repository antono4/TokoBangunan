<?php
/**
 * api/user_simpan.php - Tambah / Update user + level (POST JSON)
 * -----------------------------------------------------------------
 * Tambah : { username, nama_lengkap, password, role }
 * Update : { id, username?, nama_lengkap?, role?, password? }
 *          Password opsional saat update (isi saja bila ingin ganti).
 *
 * Aturan keamanan:
 *  - role hanya 'admin' atau 'kasir'
 *  - username unik
 *  - tidak boleh menurunkan level admin TERAKHIR menjadi kasir
 *
 * Akses: admin saja.
 */
require_once __DIR__ . '/../includes/config.php';
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d = json_input();

$id   = isset($d['id']) && $d['id'] !== '' ? (int)$d['id'] : null;
$user = trim($d['username'] ?? '');
$nama = trim($d['nama_lengkap'] ?? '');
$pass = (string)($d['password'] ?? '');
$role = $d['role'] ?? '';

// ---- Validasi umum ----
$errors = [];
if (strlen($user) < 3 || strlen($user) > 50)   $errors[] = 'Username 3-50 karakter.';
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $user)) $errors[] = 'Username hanya boleh huruf, angka, titik, garis bawah, dan minus.';
if (strlen($nama) > 100)                       $errors[] = 'Nama lengkap maksimal 100 karakter.'; 

if (!in_array($role, ['admin', 'kasir'], true)) $errors[] = 'Level user harus admin atau kasir.';
if ($id === null && strlen($pass) < 4)          $errors[] = 'Password minimal 4 karakter.';
if ($errors) json_out(['ok' => false, 'message' => implode(' ', $errors)], 422);

$pdo = db();

// ---- Cek username unik ----
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$user]);
$ada = $stmt->fetch();
if ($ada && (!$id || (int)$ada['id'] !== $id)) {
    json_out(['ok' => false, 'message' => "Username \"$user\" sudah digunakan."], 409);
}

// ---- Jangan izinkan menurunkan admin terakhir ----
if ($id !== null && $role === 'kasir') {
    $target = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $target->execute([$id]);
    $targetRole = $target->fetchColumn();
    if ($targetRole === 'admin') {
        $jumlahAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($jumlahAdmin <= 1) {
            json_out(['ok' => false,
                'message' => 'Tidak boleh menurunkan level satu-satunya admin. Buat admin baru terlebih dahulu.'], 409);
        }
    }
}

try {
    if ($id !== null) {
        // ---- UPDATE ----
        $fields = ['username = ?', 'nama_lengkap = ?', 'role = ?'];
        $params = [$user, $nama, $role];
        if ($pass !== '') {
            $fields[] = 'password = ?';
            $params[] = password_hash($pass, PASSWORD_DEFAULT);
        }
        $params[] = $id;

        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);
        json_out(['ok' => true, 'message' => "User \"$user\" berhasil diperbarui."]);
    } else {
        // ---- INSERT ----
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password, nama_lengkap, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$user, password_hash($pass, PASSWORD_DEFAULT), $nama, $role]);
        json_out(['ok' => true, 'message' => "User \"$user\" berhasil ditambahkan.",
                  'id' => (int)$pdo->lastInsertId()]);
    }
} catch (PDOException $e) {
    json_out(['ok' => false, 'message' => 'Gagal menyimpan user: ' . $e->getMessage()], 500);
}

<?php
/**
 * includes/config.php
 * -----------------------------------------------------------------
 * Konfigurasi inti aplikasi: koneksi database (PDO), session,
 * dan helper JSON + autentikasi. Di-include oleh semua file API.
 */

// ---- Session ----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Konfigurasi Database (sesuaikan dengan komputer Anda) ----
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'db_tokobangunan');
define('DB_USER', 'root');
define('DB_PASS', '');          // default XAMPP: kosong

/**
 * Koneksi PDO (dibuat satu kali per request, koneksi persisten kecil).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'ok'      => false,
                'message' => 'Koneksi database gagal: ' . $e->getMessage(),
            ]);
            exit;
        }
    }
    return $pdo;
}

/**
 * Keluarkan response JSON dan hentikan script.
 */
function json_out($data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ambil body request JSON (untuk method POST/PUT/PATCH).
 */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Pastikan user sudah login. Opsional batasi role.
 * Kembalikan data user session, atau kirim HTTP 401/403 + exit.
 */
function require_login(?array $roles = null): array
{
    if (empty($_SESSION['user'])) {
        json_out(['ok' => false, 'message' => 'Belum login.'], 401);
    }
    $user = $_SESSION['user'];
    if ($roles !== null && !in_array($user['role'], $roles, true)) {
        json_out(['ok' => false, 'message' => 'Akses ditolak: role tidak diizinkan.'], 403);
    }
    return $user;
}

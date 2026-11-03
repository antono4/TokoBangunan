<?php
/**
 * api/logout.php - Hancurkan session user (POST atau GET)
 */
require_once __DIR__ . '/../includes/config.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();
json_out(['ok' => true, 'message' => 'Logout berhasil.']);

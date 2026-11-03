<?php
/**
 * api/me.php - Cek apakah ada session aktif (GET)
 * Dipakai halaman HTML untuk guard: bila 401, redirect ke login.html
 */
require_once __DIR__ . '/../includes/config.php';

$user = require_login();
json_out(['ok' => true, 'user' => $user]);

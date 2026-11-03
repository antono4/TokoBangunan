<?php
/**
 * api/user_list.php - Daftar user beserta level/role (GET)
 * Akses: admin saja.
 */
require_once __DIR__ . '/../includes/config.php';
require_login(['admin']);

$stmt = db()->query(
    'SELECT id, username, nama_lengkap, role, created_at
       FROM users
      ORDER BY role ASC, username ASC'
);
json_out(['ok' => true, 'users' => $stmt->fetchAll()]);

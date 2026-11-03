<?php
/**
 * api/supplier_list.php - Daftar supplier (GET)
 * Termasuk jumlah produk yang ditangani tiap supplier.
 * Akses: semua user yang login.
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

$rows = db()->query(
    'SELECT s.id, s.nama_supplier, s.telepon, s.alamat,
            COUNT(p.id) AS jumlah_produk
       FROM supplier s
       LEFT JOIN produk p ON p.supplier_id = s.id
      GROUP BY s.id, s.nama_supplier, s.telepon, s.alamat
      ORDER BY s.nama_supplier ASC'
)->fetchAll();

json_out(['ok' => true, 'supplier' => $rows]);

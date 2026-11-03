<?php
/**
 * api/produk_list.php - Daftar produk (GET)
 * Query string opsional: q (cari), kategori
 * Juga mengembalikan daftar supplier & daftar kategori unik untuk dropdown form.
 * Akses: semua user yang login.
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

$q        = trim($_GET['q'] ?? '');
$kategori = trim($_GET['kategori'] ?? '');

$sql = 'SELECT p.id, p.kode_produk, p.nama_produk, p.kategori, p.satuan,
               p.harga_beli, p.harga_jual, p.stok, p.stok_minimum,
               p.supplier_id, s.nama_supplier
        FROM produk p
        LEFT JOIN supplier s ON s.id = p.supplier_id
        WHERE 1 = 1';
$params = [];

if ($q !== '') {
    $sql .= ' AND (p.kode_produk LIKE :q_kode OR p.nama_produk LIKE :q_nama)';
    $params[':q_kode'] = "%$q%";
    $params[':q_nama'] = "%$q%";
}
if ($kategori !== '') {
    $sql .= ' AND p.kategori = :kategori';
    $params[':kategori'] = $kategori;
}
$sql .= ' ORDER BY p.nama_produk ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$produk = $stmt->fetchAll();

$suppliers = db()->query('SELECT id, nama_supplier FROM supplier ORDER BY nama_supplier')->fetchAll();

$kategoriList = db()->query('SELECT DISTINCT kategori FROM produk WHERE kategori IS NOT NULL AND kategori <> "" ORDER BY kategori')
                    ->fetchAll(PDO::FETCH_COLUMN);

json_out(['ok' => true, 'produk' => $produk, 'supplier' => $suppliers, 'kategori' => $kategoriList]);

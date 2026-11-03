<?php
/**
 * api/produk_simpan.php - Tambah / Update produk (POST JSON)
 * Body : { id?, kode_produk, nama_produk, kategori?, satuan, supplier_id?,
 *          harga_beli, harga_jual, stok, stok_minimum }
 * id kosong/null = INSERT, ada id = UPDATE.
 * Akses: admin saja (kasir tidak boleh mengubah master).
 */
require_once __DIR__ . '/../includes/config.php';
require_login(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d = json_input();

$kode  = trim($d['kode_produk']  ?? '');
$nama  = trim($d['nama_produk']  ?? '');
$satuan = trim($d['satuan'] ?? 'pcs');
$kategori = trim($d['kategori'] ?? '');
$supplierId = $d['supplier_id'] ?? null;
$supplierId = ($supplierId !== null && $supplierId !== '') ? (int)$supplierId : null;
$hargaBeli  = (float)($d['harga_beli'] ?? 0);
$hargaJual  = (float)($d['harga_jual'] ?? 0);
$stok       = (int)($d['stok'] ?? 0);
$stokMin    = (int)($d['stok_minimum'] ?? 10);
$id         = isset($d['id']) && $d['id'] !== '' ? (int)$d['id'] : null;

// ---- Validasi ----
$errors = [];
if ($kode === '')                             $errors[] = 'Kode produk wajib diisi.';
if ($nama === '')                             $errors[] = 'Nama produk wajib diisi.';
if ($satuan === '')                           $errors[] = 'Satuan wajib dipilih.';
if ($hargaBeli < 0 || $hargaJual < 0)         $errors[] = 'Harga tidak boleh negatif.';
if ($stok < 0 || $stokMin < 0)                $errors[] = 'Stok tidak boleh negatif.';
if (strlen($kode) > 20)                       $errors[] = 'Kode produk maksimal 20 karakter.';
if ($errors) json_out(['ok' => false, 'message' => implode(' ', $errors)], 422);

$pdo = db();

// ---- Cek kode produk unik ----
$stmt = $pdo->prepare('SELECT id FROM produk WHERE kode_produk = ? LIMIT 1');
$stmt->execute([$kode]);
$existing = $stmt->fetch();
if ($existing && (!$id || (int)$existing['id'] !== $id)) {
    json_out(['ok' => false, 'message' => "Kode produk \"$kode\" sudah digunakan produk lain."], 409);
}

// ---- Cek supplier valid bila diisi ----
if ($supplierId !== null) {
    $check = $pdo->prepare('SELECT id FROM supplier WHERE id = ?');
    $check->execute([$supplierId]);
    if (!$check->fetch()) $supplierId = null;
}

try {
    if ($id) {
        $stmt = $pdo->prepare(
            'UPDATE produk SET
                kode_produk = ?, nama_produk = ?, kategori = ?, satuan = ?,
                harga_beli = ?, harga_jual = ?, stok = ?, stok_minimum = ?, supplier_id = ?
             WHERE id = ?'
        );
        $stmt->execute([$kode, $nama, $kategori, $satuan, $hargaBeli, $hargaJual, $stok, $stokMin, $supplierId, $id]);
        json_out(['ok' => true, 'message' => "Produk \"$nama\" berhasil diperbarui.", 'id' => $id]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO produk (kode_produk, nama_produk, kategori, satuan, harga_beli, harga_jual, stok, stok_minimum, supplier_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$kode, $nama, $kategori, $satuan, $hargaBeli, $hargaJual, $stok, $stokMin, $supplierId]);
        json_out(['ok' => true, 'message' => "Produk \"$nama\" berhasil ditambahkan.", 'id' => (int)$pdo->lastInsertId()]);
    }
} catch (PDOException $e) {
    json_out(['ok' => false, 'message' => 'Gagal menyimpan produk: ' . $e->getMessage()], 500);
}

<?php
/**
 * api/perbaiki_database.php - Perbaikan otomatis (POST)
 * Membuat ulang trigger stok, tabel pengaturan, akun default bila kosong,
 * lalu mengembalikan status sesudahnya.
 * Akses: admin saja (menulis skema database).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/dbhealth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

require_login(['admin']);

$hasil = dbhealth_repair();
$sesudah = dbhealth_status();

json_out([
    'ok'      => empty($hasil['gagal']),
    'message' => empty($hasil['gagal'])
        ? 'Perbaikan selesai tanpa kendala.'
        : 'Selesai dengan kendala: lihat "gagal".',
    'kasus'   => $hasil['kasus'],
    'gagal'   => $hasil['gagal'],
    'sesudah' => $sesudah,
]);

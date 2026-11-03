<?php
/**
 * api/pengaturan.php - Identitas toko (nama/alamat/telepon)
 * -----------------------------------------------------------------
 * GET  : ambil semua pengaturan (TANPA login -> dipakai halaman login
 *        & navbar; data ini tidak sensitif).
 * POST : update pengaturan (ADMIN SAJA), body JSON:
 *        { nama_toko?, alamat_toko?, telepon_toko? }
 */
require_once __DIR__ . '/../includes/config.php';

// Kunci yang boleh diubah (whitelist) + default bila belum ada di DB
const KUNCI_DEFAULT = [
    'nama_toko'    => 'TOKO BANGUNAN MANDIRI',
    'alamat_toko'  => 'Jl. Raya Merdeka No. 123, Depok',
    'telepon_toko' => '(021) 555-0123',
];

/** Ambil seluruh pengaturan sebagai assoc array. */
function ambil_pengaturan(): array
{
    $rows = db()->query('SELECT kunci, nilai FROM pengaturan')->fetchAll(PDO::FETCH_KEY_PAIR);
    $hasil = KUNCI_DEFAULT;
    foreach ($hasil as $kunci => $default) {
        if (isset($rows[$kunci]) && $rows[$kunci] !== null && $rows[$kunci] !== '') {
            $hasil[$kunci] = $rows[$kunci];
        }
    }
    return $hasil;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(['ok' => true, 'pengaturan' => ambil_pengaturan()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login(['admin']);

    $input = json_input();
    $batas = ['nama_toko' => 100, 'alamat_toko' => 200, 'telepon_toko' => 30];

    $stmt = db()->prepare(
        'INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)'
    );

    $diubah = 0;
    foreach ($batas as $kunci => $maxLen) {
        if (array_key_exists($kunci, $input)) {
            $nilai = trim((string)$input[$kunci]);
            if (mb_strlen($nilai) > $maxLen) {
                json_out(['ok' => false, "message" => "\"$kunci\" maksimal $maxLen karakter."], 422);
            }
            $stmt->execute([$kunci, $nilai]);
            $diubah++;
        }
    }

    json_out(['ok' => true, 'message' => 'Pengaturan toko berhasil disimpan.',
              'pengaturan' => ambil_pengaturan()]);
}

json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);

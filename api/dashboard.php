<?php
/**
 * api/dashboard.php - Semua data untuk halaman Dashboard (GET)
 * -----------------------------------------------------------------
 * Response berisi:
 *  - ringkasan      : total produk, pendapatan & transaksi hari ini
 *  - stok_menipis   : daftar produk yang stoknya < stok_minimum (fokus < 10)
 *  - grafik_harian  : omzet N hari terakhir (default 7, ?periode=7|14|30)
 *  - produk_terlaris: top 5 barang paling laris (view v_produk_terlaris)
 *
 * Akses: semua user yang login.
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

$periode = max(1, min(90, (int)($_GET['periode'] ?? 7)));
$pdo     = db();

// ---- Ringkasan hari ini ----
$produkTotal = (int)$pdo->query('SELECT COUNT(*) FROM produk')->fetchColumn();
$produkStok  = (int)$pdo->query('SELECT COALESCE(SUM(stok),0) FROM produk')->fetchColumn();

$ringkasanHariIni = $pdo->query(
    "SELECT COUNT(*)      AS jumlah_transaksi,
            COALESCE(SUM(total_bayar),0) AS total
       FROM penjualan
      WHERE DATE(tanggal) = CURDATE()"
)->fetch();

// ---- Stok menipis (fokus: di bawah minimum, khususnya < 10) ----
$stokMenipis = $pdo->query(
    'SELECT kode_produk, nama_produk, satuan, stok, stok_minimum
       FROM produk
      WHERE stok < stok_minimum OR stok < 10
      ORDER BY stok ASC
      LIMIT 20'
)->fetchAll();

// ---- Grafik omzet N hari terakhir ----
$grafik = [];
$dari   = date('Y-m-d', strtotime('-' . ($periode - 1) . ' days'));
$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(tanggal, '%Y-%m-%d') AS tanggal,
            COALESCE(SUM(total_bayar),0)    AS omzet
       FROM penjualan
      WHERE tanggal >= ?
      GROUP BY DATE(tanggal)
      ORDER BY tanggal ASC"
);
$stmt->execute([$dari . ' 00:00:00']);
$published = [];
foreach ($stmt->fetchAll() as $row) {
    $published[$row['tanggal']] = (float)$row['omzet'];
}
// isi hari tanpa transaksi dengan 0
$labels = [];
$nilai  = [];
for ($i = $periode - 1; $i >= 0; $i--) {
    $tgl      = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d M', strtotime($tgl));
    $nilai[]  = $published[$tgl] ?? 0;
}
$grafik = ['labels' => $labels, 'omzet' => $nilai];

// ---- Barang terlaris ----
$terlaris = $pdo->query(
    'SELECT nama_produk, satuan, total_terjual
       FROM v_produk_terlaris
      ORDER BY total_terjual DESC
      LIMIT 5'
)->fetchAll();

json_out([
    'ok' => true,
    'ringkasan' => [
        'total_produk'      => $produkTotal,
        'total_stok'        => $produkStok,
        'pendapatan_hari_ini' => (float)$ringkasanHariIni['total'],
        'transaksi_hari_ini'  => (int)$ringkasanHariIni['jumlah_transaksi'],
    ],
    'stok_menipis'    => $stokMenipis,
    'grafik_penjualan' => $grafik,
    'produk_terlaris'  => $terlaris,
]);

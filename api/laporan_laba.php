<?php
/**
 * api/laporan_laba.php - Laporan laba kotor (GET)
 * -----------------------------------------------------------------
 * Laba kotor = omzet (harga jual) - kos (harga beli) per periode.
 *
 * Query string:
 *   grup   : harian (default) | bulanan  -> cara pengelompokan baris
 *   dari   : YYYY-MM-DD (default:Awal bulan ini)
 *   sampai : YYYY-MM-DD (default: hari ini)
 *
 * Response:
 *   ringkasan : { omzet, laba_kotor, total_harga_beli, total_margin, transaksi }
 *   rincian   : [{ periode, transaksi, omzet, laba_kotor }]
 *   detail    : daftar faktur dalam rentang (max 300)
 *
 * Akses: semua user yang login.
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

$grup   = ($_GET['grup'] ?? 'harian') === 'bulanan' ? 'bulanan' : 'harian';
$dari   = $_GET['dari']   ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
    json_out(['ok' => false, 'message' => 'Format tanggal harus YYYY-MM-DD.'], 422);
}

$dariTs   = "$dari 00:00:00";
$sampaiTs = "$sampai 23:59:59";
$pdo      = db();

$expr = $grup === 'bulanan' ? "DATE_FORMAT(tanggal, '%Y-%m')" : 'DATE(tanggal)';

// ---- Rincian per periode: laba = Σ jumlah * (harga_jual - harga_beli) ----
$rincian = $pdo->prepare(
    "SELECT $expr AS periode,
            COUNT(DISTINCT p.id)                     AS transaksi,
            COALESCE(SUM(d.subtotal), 0)             AS omzet,
            COALESCE(SUM(d.jumlah * (d.harga_satuan - pr.harga_beli)), 0) AS laba_kotor
       FROM penjualan p
       JOIN detail_penjualan d ON d.penjualan_id = p.id
       JOIN produk pr          ON pr.id           = d.produk_id
      WHERE p.tanggal BETWEEN ? AND ?
      GROUP BY $expr
      ORDER BY periode ASC"
);
$rincian->execute([$dariTs, $sampaiTs]);
$rincianRows = $rincian->fetchAll();

// ---- Ringkasan rentang ----
$ringkasan = $pdo->prepare(
    "SELECT COUNT(DISTINCT p.id)                        AS transaksi,
            COALESCE(SUM(d.subtotal), 0)                 AS omzet,
            COALESCE(SUM(d.jumlah * (d.harga_satuan - pr.harga_beli)), 0) AS laba_kotor,
            COALESCE(SUM(d.jumlah * pr.harga_beli), 0)  AS total_harga_beli
       FROM penjualan p
       JOIN detail_penjualan d ON d.penjualan_id = p.id
       JOIN produk pr          ON pr.id           = d.produk_id
      WHERE p.tanggal BETWEEN ? AND ?"
);
$ringkasan->execute([$dariTs, $sampaiTs]);
$ring = $ringkasan->fetch();

// ---- Detail faktur dalam rentang ----
$detail = $pdo->prepare(
    "SELECT p.no_faktur, p.tanggal, p.total_bayar, p.diskon,
            pl.nama_pelanggan, u.nama_lengkap AS kasir,
            COALESCE(d.omzet, 0) AS omzet,
            COALESCE(d.laba, 0) AS laba_kotor
       FROM penjualan p
       LEFT JOIN (SELECT d.penjualan_id,
                        SUM(d.subtotal) AS omzet,
                        SUM(d.jumlah * (d.harga_satuan - pr.harga_beli)) AS laba
                   FROM detail_penjualan d
                   JOIN produk pr ON pr.id = d.produk_id
                  GROUP BY d.penjualan_id) d
              ON d.penjualan_id = p.id
       LEFT JOIN pelanggan pl ON pl.id = p.pelanggan_id
       LEFT JOIN users u      ON u.id  = p.user_id
      WHERE p.tanggal BETWEEN ? AND ?
      ORDER BY p.tanggal DESC
      LIMIT 300"
);
$detail->execute([$dariTs, $sampaiTs]);
$detailRows = $detail->fetchAll();

json_out([
    'ok' => true,
    'filter' => ['grup' => $grup, 'dari' => $dari, 'sampai' => $sampai],
    'ringkasan' => [
        'omzet'         => (float)$ring['omzet'],
        'laba_kotor'    => (float)$ring['laba_kotor'],
        'total_harga_beli' => (float)$ring['total_harga_beli'],
        'margin'        => (float)$ring['omzet'] - (float)$ring['total_harga_beli'],
        'transaksi'     => (int)$ring['transaksi'],
    ],
    'rincian' => array_map(function ($r) {
        return [
            'periode'    => $r['periode'],
            'transaksi'  => (int)$r['transaksi'],
            'omzet'      => (float)$r['omzet'],
            'laba_kotor' => (float)$r['laba_kotor'],
        ];
    }, $rincianRows),
    'detail' => array_map(function ($r) {
        return [
            'no_faktur'   => $r['no_faktur'],
            'tanggal'     => $r['tanggal'],
            'pelanggan'   => $r['nama_pelanggan'] ?: 'Pembeli Umum',
            'kasir'       => $r['kasir'] ?: '-',
            'omzet'       => (float)$r['omzet'],
            'laba_kotor'  => (float)$r['laba_kotor'],
        ];
    }, $detailRows),
]);

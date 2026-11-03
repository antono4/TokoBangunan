<?php
/**
 * api/laporan.php - Data laporan penjualan (GET)
 * -----------------------------------------------------------------
 * Query string:
 *   grup   : harian (default) | bulanan  -> cara pengelompokan baris
 *   dari   : YYYY-MM-DD (default:Awal bulan ini)
 *   sampai : YYYY-MM-DD (default: hari ini)
 *
 * Response:
 *   ringkasan : { omzet, transaksi, item_terjual, total_diskon }
 *   rincian   : [{ periode, transaksi, item, omzet, diskon }, ...]
 *   detail    : daftar faktur dalam rentang (max 300)
 *   terlaris  : top 5 produk dalam rentang
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

/* Pola pengelompokan baris laporan */
$expr = $grup === 'bulanan' ? "DATE_FORMAT(tanggal, '%Y-%m')" : 'DATE(tanggal)';

// ---- Baris rincian (per hari / per bulan) ----
$rincian = $pdo->prepare(
    "SELECT $expr AS periode,
            COUNT(*)                          AS transaksi,
            COALESCE(SUM(d.item), 0)          AS item,
            COALESCE(SUM(p.total_bayar), 0)   AS omzet,
            COALESCE(SUM(p.diskon), 0)        AS diskon
       FROM penjualan p
       LEFT JOIN (SELECT penjualan_id, SUM(jumlah) AS item
                    FROM detail_penjualan GROUP BY penjualan_id) d
              ON d.penjualan_id = p.id
      WHERE p.tanggal BETWEEN ? AND ?
      GROUP BY $expr
      ORDER BY periode ASC"
);
$rincian->execute([$dariTs, $sampaiTs]);
$rows = $rincian->fetchAll();

// ---- Ringkasan rentang ----
$tot = $pdo->prepare(
    "SELECT COUNT(*)                                   AS transaksi,
            COALESCE(SUM(p.total_bayar), 0)            AS omzet,
            COALESCE(SUM(p.diskon), 0)                 AS diskon,
            COALESCE(SUM(d.item), 0)                   AS item
       FROM penjualan p
       LEFT JOIN (SELECT penjualan_id, SUM(jumlah) AS item
                    FROM detail_penjualan GROUP BY penjualan_id) d
              ON d.penjualan_id = p.id
      WHERE p.tanggal BETWEEN ? AND ?"
);
$tot->execute([$dariTs, $sampaiTs]);
$ringkasan = $tot->fetch();

// ---- Detail faktur dalam rentang ----
$detail = $pdo->prepare(
    "SELECT p.no_faktur, p.tanggal, p.total_bayar, p.diskon,
            pl.nama_pelanggan, u.nama_lengkap AS kasir,
            COALESCE(d.item, 0) AS item
       FROM penjualan p
       LEFT JOIN pelanggan pl ON pl.id = p.pelanggan_id
       LEFT JOIN users u      ON u.id  = p.user_id
       LEFT JOIN (SELECT penjualan_id, SUM(jumlah) AS item
                    FROM detail_penjualan GROUP BY penjualan_id) d
              ON d.penjualan_id = p.id
      WHERE p.tanggal BETWEEN ? AND ?
      ORDER BY p.tanggal DESC
      LIMIT 300"
);
$detail->execute([$dariTs, $sampaiTs]);
$detail = $detail->fetchAll();

// ---- Top 5 produk dalam rentang ----
$terlaris = $pdo->prepare(
    "SELECT pr.nama_produk, pr.satuan,
            SUM(d.jumlah)   AS total_terjual,
            SUM(d.subtotal) AS pendapatan
       FROM detail_penjualan d
       JOIN penjualan p ON p.id = d.penjualan_id
       JOIN produk pr   ON pr.id = d.produk_id
      WHERE p.tanggal BETWEEN ? AND ?
      GROUP BY pr.id, pr.nama_produk, pr.satuan
      ORDER BY total_terjual DESC
      LIMIT 5"
);
$terlaris->execute([$dariTs, $sampaiTs]);
$terlaris = $terlaris->fetchAll();

json_out([
    'ok' => true,
    'filter' => ['grup' => $grup, 'dari' => $dari, 'sampai' => $sampai],
    'ringkasan' => [
        'omzet'        => (float)$ringkasan['omzet'],
        'transaksi'    => (int)$ringkasan['transaksi'],
        'item_terjual' => (int)$ringkasan['item'],
        'total_diskon' => (float)$ringkasan['diskon'],
    ],
    'rincian'  => array_map(function ($r) {
        return [
            'periode'   => $r['periode'],
            'transaksi' => (int)$r['transaksi'],
            'item'      => (int)$r['item'],
            'omzet'     => (float)$r['omzet'],
            'diskon'    => (float)$r['diskon'],
        ];
    }, $rows),
    'detail'   => array_map(function ($r) {
        return [
            'no_faktur'     => $r['no_faktur'],
            'tanggal'       => $r['tanggal'],
            'pelanggan'     => $r['nama_pelanggan'] ?: 'Pembeli Umum',
            'kasir'         => $r['kasir'] ?: '-',
            'item'          => (int)$r['item'],
            'total_bayar'   => (float)$r['total_bayar'],
            'diskon'        => (float)$r['diskon'],
        ];
    }, $detail),
    'terlaris' => array_map(function ($r) {
        return [
            'nama_produk'   => $r['nama_produk'],
            'satuan'        => $r['satuan'],
            'total_terjual' => (int)$r['total_terjual'],
            'pendapatan'    => (float)$r['pendapatan'],
        ];
    }, $terlaris),
]);

<?php
/**
 * api/laporan_export.php - Unduh laporan sebagai CSV (GET)
 * -----------------------------------------------------------------
 * Query string: type=harian|bulanan|detail  +  dari / sampai (YYYY-MM-DD)
 * CSV memakai BOM UTF-8 agar terbuka rapi di Microsoft Excel.
 * Akses: semua user yang login.
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

$type = in_array($_GET['type'] ?? '', ['harian', 'bulanan', 'detail'], true) ? $_GET['type'] : 'harian';
$dari   = $_GET['dari']   ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
    json_out(['ok' => false, 'message' => 'Format tanggal harus YYYY-MM-DD.'], 422);
}

$pdo = db();
$dariTs   = "$dari 00:00:00";
$sampaiTs = "$sampai 23:59:59";

switch ($type) {
    case 'bulanan':
        $judul = 'Laporan Bulanan';
        $head  = ['Bulan', 'Jumlah Transaksi', 'Item Terjual', 'Omzet (Rp)', 'Diskon (Rp)'];
        $stmt  = $pdo->prepare(
            "SELECT DATE_FORMAT(tanggal, '%Y-%m') AS periode,
                    COUNT(*) AS transaksi,
                    COALESCE(SUM(d.item), 0) AS item,
                    COALESCE(SUM(p.total_bayar), 0) AS omzet,
                    COALESCE(SUM(p.diskon), 0) AS diskon
               FROM penjualan p
               LEFT JOIN (SELECT penjualan_id, SUM(jumlah) AS item
                            FROM detail_penjualan GROUP BY penjualan_id) d
                      ON d.penjualan_id = p.id
              WHERE p.tanggal BETWEEN ? AND ?
              GROUP BY periode
              ORDER BY periode ASC"
        );
        break;

    case 'detail':
        $judul = 'Rincian Transaksi';
        $head  = ['No. Faktur', 'Tanggal', 'Pelanggan', 'Kasir', 'Item', 'Total Bayar (Rp)', 'Diskon (Rp)'];
        $stmt  = $pdo->prepare(
            "SELECT p.no_faktur, p.tanggal,
                    IFNULL(pl.nama_pelanggan, 'Pembeli Umum') AS pelanggan,
                    IFNULL(u.nama_lengkap, '-') AS kasir,
                    COALESCE(d.item, 0) AS item,
                    p.total_bayar, p.diskon
               FROM penjualan p
               LEFT JOIN pelanggan pl ON pl.id = p.pelanggan_id
               LEFT JOIN users u      ON u.id  = p.user_id
               LEFT JOIN (SELECT penjualan_id, SUM(jumlah) AS item
                            FROM detail_penjualan GROUP BY penjualan_id) d
                      ON d.penjualan_id = p.id
              WHERE p.tanggal BETWEEN ? AND ?
              ORDER BY p.tanggal DESC"
        );
        break;

    default: // harian
        $judul = 'Laporan Harian';
        $head  = ['Tanggal', 'Jumlah Transaksi', 'Item Terjual', 'Omzet (Rp)', 'Diskon (Rp)'];
        $stmt  = $pdo->prepare(
            "SELECT DATE(tanggal) AS periode,
                    COUNT(*) AS transaksi,
                    COALESCE(SUM(d.item), 0) AS item,
                    COALESCE(SUM(p.total_bayar), 0) AS omzet,
                    COALESCE(SUM(p.diskon), 0) AS diskon
               FROM penjualan p
               LEFT JOIN (SELECT penjualan_id, SUM(jumlah) AS item
                            FROM detail_penjualan GROUP BY penjualan_id) d
                      ON d.penjualan_id = p.id
              WHERE p.tanggal BETWEEN ? AND ?
              GROUP BY periode
              ORDER BY periode ASC"
        );
        break;
}

$stmt->execute([$dariTs, $sampaiTs]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Header & stream CSV ----
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '-', $judul))
       . '-' . $dari . '_sd_' . $sampai . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");                 // BOM UTF-8 for Excel
fputcsv($out, [$judul, "Periode: $dari s/d $sampai"]);
fputcsv($out, []);                            // baris kosong pemisah
fputcsv($out, $head);
foreach ($rows as $r) {
    fputcsv($out, array_values($r));
}
fclose($out);
exit;

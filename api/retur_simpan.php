<?php
/**
 * api/retur_simpan.php - Simpan retur / nota kredit (POST JSON)
 * -----------------------------------------------------------------
 * Body: {
 *   no_faktur: string,              // TRX-YYYYMMDD-0001 (wajib, harus ada di penjualan)
 *   alasan: string?,                 // alasan retur (opsional)
 *   items: [{ produk_id, jumlah }] // qty dikembalikan ke stok
 * }
 *
 * Alur:
 *   1. Validasi faktur ada & item valid (jumlah > 0, <= qty asli transaksi)
 *   2. INSERT ke tabel retur + retur_detail (nota kredit; faktur asal TIDAK dihapus)
 *   3. UPDATE produk: stok = stok + jumlah (stok masuk kembali)
 *   4. COMMIT
 *
 * Akses: semua user yang login (admin & kasir).
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d        = json_input();
$noFaktur = (string)($d['no_faktur'] ?? '');
$items    = $d['items'] ?? [];

if ($noFaktur === '' || !is_array($items) || empty($items)) {
    json_out(['ok' => false, 'message' => 'no_faktur dan items wajib diisi.'], 422);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    // ---- 1. Validasi faktur + ambil qty/harga asli transaksi ----
    $stmtTrx = $pdo->prepare('SELECT id, total_bayar, pelanggan_id, user_id FROM penjualan WHERE no_faktur = ?');
    $stmtTrx->execute([$noFaktur]);
    $trx = $stmtTrx->fetch();
    if (!$trx) {
        throw new RuntimeException('Faktur tidak ditemukan: ' . $noFaktur);
    }

    $stmtQty = $pdo->prepare(
        'SELECT d.produk_id, d.jumlah, d.harga_satuan
           FROM detail_penjualan d
           JOIN penjualan p ON p.id = d.penjualan_id
          WHERE p.no_faktur = ?
          ORDER BY d.id ASC'
    );
    $stmtQty->execute([$noFaktur]);
    $qtyAsli = [];
    foreach ($stmtQty->fetchAll() as $r) {
        $qtyAsli[(int)$r['produk_id']] = ['jumlah' => (int)$r['jumlah'], 'harga_satuan' => (float)$r['harga_satuan']];
    }

    // ---- 2. INSERT header nota kredit + detail + update stok ----
    $insRetur = $pdo->prepare(
        'INSERT INTO retur (no_faktur, tanggal, user_id, pelanggan_id, total_retur, alasan)
         VALUES (?, NOW(), ?, ?, ?, ?)'
    );
    $insRetur->execute([$noFaktur, (int)$trx['user_id'], $trx['pelanggan_id'], 0.0, $d['alasan'] ?? null]);
    $returId = (int)$pdo->lastInsertId();

    $totalRetur = 0.0;
    foreach ($items as $it) {
        $pid = (int)($it['produk_id'] ?? 0);
        $qty = (int)($it['jumlah'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            throw new RuntimeException('Item retur tidak valid (produk/jumlah).');
        }
        if (!isset($qtyAsli[$pid])) {
            throw new RuntimeException('Faktur tidak memiliki item produk ID ' . $pid . '.');
        }
        if ($qty > $qtyAsli[$pid]['jumlah']) {
            throw new RuntimeException('Jumlah retur melebihi qty transaksi untuk produk ID ' . $pid . '.');
        }

        $hargaSatuan = $qtyAsli[$pid]['harga_satuan'];
        $subtotal = $qty * $hargaSatuan;
        $totalRetur += $subtotal;

        $insDet = $pdo->prepare(
            'INSERT INTO retur_detail (retur_id, produk_id, jumlah, harga_satuan, subtotal)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insDet->execute([$returId, $pid, $qty, $hargaSatuan, $subtotal]);

        // Stok masuk kembali (nota kredit -> faktur asal tidak dihapus)
        $updStok = $pdo->prepare('UPDATE produk SET stok = stok + ? WHERE id = ?');
        $updStok->execute([$qty, $pid]);
    }

    $pdo->exec('UPDATE retur SET total_retur = ' . (float)$totalRetur . ' WHERE id = ' . (int)$returId);

    $pdo->commit();

    json_out([
        'ok'      => true,
        'message' => 'Retur & nota kredit berhasil disimpan (stok masuk kembali).',
        'retur'   => [
            'id'           => $returId,
            'no_faktur'   => $noFaktur,
            'total_retur' => (float)$totalRetur,
            'items'       => array_map(function ($it) use ($qtyAsli) {
                $pid = (int)$it['produk_id'];
                return ['produk_id' => $pid, 'jumlah' => (int)$it['jumlah'], 'harga_satuan' => $qtyAsli[$pid]['harga_satuan']];
            }, $items),
        ],
    ]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'message' => 'Gagal menyimpan retur: ' . $e->getMessage()], 500);
}

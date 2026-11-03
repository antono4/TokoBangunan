<?php
/**
 * api/penjualan_simpan.php - Simpan transaksi penjualan (POST JSON)
 * -----------------------------------------------------------------
 * Body: {
 *   pelanggan_id?: int|null,
 *   diskon: number,               // nilai diskon dalam rupiah
 *   bayar: number,                // uang yang dibayarkan (untuk validasi)
 *   items: [{ produk_id, jumlah }] // harga_satuan dihitung ulang di server!
 * }
 *
 * Alur (SATU DATABASE TRANSACTION):
 *   1. Ambil harga terkini dari tabel produk (jangan percaya harga dari client)
 *   2. Hitung subtotal, total & validasi stok + nominal bayar
 *   3. Generate no_faktur unik (TRX-YYYYMMDD-0001)
 *   4. INSERT penjualan + detail_penjualan
 *   5. COMMIT -> trigger SQL otomatis memotong stok produk
 *
 * Akses: semua user yang login (admin & kasir).
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$d    = json_input();
$user = $_SESSION['user'];

$pelangganId = isset($d['pelanggan_id']) && $d['pelanggan_id'] ? (int)$d['pelanggan_id'] : null;
$diskon      = (float)($d['diskon'] ?? 0);
$bayar       = (float)($d['bayar'] ?? 0);
$items       = $d['items'] ?? [];

if (empty($items) || !is_array($items)) {
    json_out(['ok' => false, 'message' => 'Daftar barang kosong, transaksi tidak dapat diproses.'], 422);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    // ---- 1. Ambil data produk terkini & LOCK agar tidak berubah saat proses ----
    // Trigger SQL juga memvalidasi stok; ini hanya untuk hitung ulang nominal.
    $placeholders = implode(',', array_fill(0, count($items), '?'));
    $stmt = $pdo->prepare("SELECT id, nama_produk, satuan, harga_jual, stok
                             FROM produk
                            WHERE id IN ($placeholders)
                            FOR UPDATE");
    $stmt->execute(array_column($items, 'produk_id'));
    $produkMap = [];
    foreach ($stmt->fetchAll() as $row) {
        $produkMap[(int)$row['id']] = $row;
    }

    // ---- 2. Hitung subtotal & validasi stok ----
    $detail   = [];   // baris yang akan di-INSERT ke detail_penjualan
    $subtotal = 0.0;
    $diskon   = max(0, $diskon);

    foreach ($items as $item) {
        $pid = (int)($item['produk_id'] ?? 0);
        $qty = (int)($item['jumlah'] ?? 0);

        if ($pid <= 0 || $qty <= 0) {
            throw new RuntimeException('Item transaksi tidak valid (produk/jumlah).');
        }
        if (!isset($produkMap[$pid])) {
            throw new RuntimeException("Produk ID $pid tidak ditemukan.");
        }
        $p = $produkMap[$pid];
        if ($qty > (int)$p['stok']) {
            throw new RuntimeException("Stok tidak cukup: \"{$p['nama_produk']}\" sisa {$p['stok']} {$p['satuan']}.");
        }

        $harga     = (float)$p['harga_jual'];           // harga dihitung ulang oleh server
        $subNilai  = $harga * $qty;
        $subtotal += $subNilai;

        $detail[] = ['produk_id' => $pid, 'jumlah' => $qty, 'harga_satuan' => $harga, 'subtotal' => $subNilai];
    }

    if ($diskon > $subtotal) $diskon = $subtotal;
    // Pajak (persentase, default 0.12 = 12%) diambil dari tabel pengaturan
    $pajakRate = (float)db()->query("SELECT nilai FROM pengaturan WHERE kunci = 'pajak'")->fetchColumn();
    if ($pajakRate <= 0) $pajakRate = 0.12;
    // Pajak atas jumlah bersih (subtotal - diskon)
    $pajak = round(($subtotal - $diskon) * $pajakRate, 2);
    $total = ($subtotal - $diskon) + $pajak;

    if ($bayar > 0 && $bayar < $total) {
        throw new RuntimeException('Nominal bayar (' . number_format($bayar) . ') kurang dari total (' . number_format($total) . ').');
    }

    // ---- 3. Validasi pelanggan bila diisi ----
    if ($pelangganId !== null) {
        $check = $pdo->prepare('SELECT id FROM pelanggan WHERE id = ?');
        $check->execute([$pelangganId]);
        if (!$check->fetch()) $pelangganId = null;
    }

    // ---- 4. Generate no_faktur unik, cegah duplikat dengan loop mak----
    $today = date('Y-m-d');
    $stmt  = $pdo->prepare(
        "SELECT no_faktur FROM penjualan
          WHERE DATE(tanggal) = ? AND no_faktur LIKE 'TRX-%'
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$today]);
    $last = $stmt->fetchColumn();

    $seq = ($last !== false) ? (int)substr($last, -4) + 1 : 1;
    do {
        $noFaktur = 'TRX-' . date('Ymd') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $seq++;
        $dupCheck  = $pdo->prepare('SELECT id FROM penjualan WHERE no_faktur = ?');
        $dupCheck->execute([$noFaktur]);
    } while ($dupCheck->fetch());  // loop bila masih ada yang sama (race condition)

    // ---- 5. INSERT header + baris detail ----
    $stmtH = $pdo->prepare(
        'INSERT INTO penjualan (no_faktur, tanggal, total_bayar, diskon, user_id, pelanggan_id)
         VALUES (?, NOW(), ?, ?, ?, ?)'
    );
    $stmtH->execute([$noFaktur, $total, $diskon, $user['id'], $pelangganId]);
    $penjualanId = (int)$pdo->lastInsertId();

    $stmtD = $pdo->prepare(
        'INSERT INTO detail_penjualan (penjualan_id, produk_id, jumlah, harga_satuan, subtotal)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($detail as $row) {
        $stmtD->execute([$penjualanId, $row['produk_id'], $row['jumlah'], $row['harga_satuan'], $row['subtotal']]);
    }

    $pdo->commit();
    // Trigger trig_kurangi_stok otomatis memotong stok setiap INSERT detail.

    // Ambil data untuk cetak struk
    $pelangganNama = null;
    if ($pelangganId !== null) {
        $stmtP = $pdo->prepare('SELECT nama_pelanggan FROM pelanggan WHERE id = ?');
        $stmtP->execute([$pelangganId]);
        $pelangganNama = $stmtP->fetchColumn();
    }

// ---- 6. Notifikasi: setelah transaksi sukses, cek produk yang stoknya menipis ----
try {
    require_once __DIR__ . '/../includes/notif.php';
    $notifIds = array_map(function ($r) { return (int)$r['produk_id']; }, $detail);
    if (!empty($notifIds)) {
        $marks = implode(',', array_fill(0, count($notifIds), '?'));
        $st = $pdo->prepare(
            "SELECT id, kode_produk, nama_produk, satuan, stok, stok_minimum
               FROM produk
              WHERE id IN ($marks) AND stok <= stok_minimum
              ORDER BY stok ASC"
        );
        $st->execute($notifIds);
        $stokMenipis = $st->fetchAll();
        notifKirimStokMenipis($stokMenipis);
    }
} catch (Throwable $e) {
    // Notifikasi tidak boleh menghentikan transaksi yang valid.
}

    json_out([
        'ok' => true,
        'message' => "Transaksi $noFaktur berhasil disimpan.",
        'faktur' => [
            'no_faktur'    => $noFaktur,
            'kasir'        => $user['nama'],
            'pelanggan'    => $pelangganNama ?: 'Pembeli Umum',
            'subtotal'     => $subtotal,
            'diskon'       => $diskon,
            'pajak'        => $pajak,
            'total'        => $total,
            'kembalian'    => max(0, $bayar - $total),
            'items'        => array_map(function ($r) use ($produkMap) {
                $p = $produkMap[$r['produk_id']];
                return ['nama' => $p['nama_produk'], 'satuan' => $p['satuan']] + $r;
            }, $detail),
        ],
    ]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // SQLSTATE 45000 = SIGNAL dari trigger stok tidak cukup
    if ($e->getCode() === '45000' || str_contains($e->getMessage(), 'stok produk tidak mencukupi')) {
        json_out(['ok' => false, 'message' => 'Stok produk tidak mencukupi untuk transaksi ini.'], 422);
    }
    json_out(['ok' => false, 'message' => 'Gagal menyimpan transaksi: ' . $e->getMessage()], 500);
}

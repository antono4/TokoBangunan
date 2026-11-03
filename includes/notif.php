<?php
/**
 * includes/notif.php - Notifikasi aplikasi (email + webhook)
 * -----------------------------------------------------------------
 * Mengirim notifikasi stok menipis setelah transaksi penjualan.
 *  - Email   : ke admin via PHP mail() (subjek + isi peringatan).
 *  - WhatsApp: POST JSON ke webhook opsional dari pengaturan
 *             (kunci: notif_webhook_url) -- cocok untuk layanan
 *             gateway WhatsApp / notifikasi serbaguna.
 *
 * Semua kirim bersifat non-blocking terhadap operasional:
 * kegagalan notifikasi tidak boleh menentukan hasil transaksi.
 */

function notifEmailAdmin(string $subjek, string $pesan): void
{
    $to = 'admin@tokobangunan.id';
    $headers = 'From: Toko Bangunan <admin@tokobangunan.id>' . "\r\n"
             . 'Content-Type: text/plain; charset=utf-8' . "\r\n";
    @mail($to, $subjek, $pesan, $headers);
}

function notifWebhookUrl(): string
{
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT nilai FROM pengaturan WHERE kunci = 'notif_webhook_url' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        return (string)($v ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

function notifWebhookKirim(string $url, array $payload): void
{
    if ($url === '') {
        return;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Kirim notifikasi stok menipis ke admin (email + WhatsApp webhook).
 * $items: baris produk [id, kode_produk, nama_produk, satuan, stok, stok_minimum]
 */
function notifKirimStokMenipis(array $items): void
{
    if (empty($items)) {
        return;
    }

    $baris = [];
    foreach ($items as $p) {
        $baris[] = sprintf(
            '- %s (stok %d %s, batas alert %d %s)',
            $p['nama_produk'],
            (int)$p['stok'],
            $p['satuan'],
            (int)$p['stok_minimum'],
            $p['satuan']
        );
    }

    $subjek = 'Peringatan Stok Menipis - Toko Bangunan';
    $pesan   = "Produk berikut stoknya telah menyentuh batas stok menipis:\n"
             . implode("\n", $baris)
             . "\n\nWaktu: " . date('Y-m-d H:i:s');
    notifEmailAdmin($subjek, $pesan);

    notifWebhookKirim(notifWebhookUrl(), [
        'event' => 'stok_menipis',
        'toko'  => 'Toko Bangunan',
        'waktu' => date('Y-m-d H:i:s'),
        'items' => array_map(function ($p) {
            return [
                'id'            => (int)$p['id'],
                'kode_produk'   => $p['kode_produk'],
                'nama_produk'   => $p['nama_produk'],
                'stok'          => (int)$p['stok'],
                'stok_minimum' => (int)$p['stok_minimum'],
            ];
        }, $items),
    ]);
}

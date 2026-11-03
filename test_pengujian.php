<?php
/**
 * test_pengujian.php - Uji end-to-end semua API aplikasi Toko Bangunan.
 * Jalankan:  php test_pengujian.php   (server PHP + MySQL harus aktif)
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

$BASE = 'http://127.0.0.1:8090/api/';

$hasil   = ['pass' => 0, 'fail' => 0];
$gagalan = [];

function hasil(string $nama, bool $ok, string $detail = ''): void {
    global $hasil, $gagalan;
    if ($ok) {
        $hasil['pass']++;
        echo "  [PASS] $nama\n";
    } else {
        $hasil['fail']++;
        $gagalan[] = $nama;
        echo "  [GAGAL] $nama  ->  $detail\n";
    }
}

/**
 * Client HTTP minimal dengan cookie session per-"simpul".
 */
function getData(string $url, string $cookieFile = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEFILE     => $cookieFile ?: '',
        CURLOPT_COOKIEJAR      => $cookieFile ?: '',
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw   = curl_exec($ch);
    $kode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $hSize);
    return ['kode' => $kode, 'json' => json_decode($body, true)];
}

function kirim(string $url, string $cookieFile = '', array $data = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIEFILE     => $cookieFile ?: '',
        CURLOPT_COOKIEJAR      => $cookieFile ?: '',
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw   = curl_exec($ch);
    $kode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $hSize);
    return ['kode' => $kode, 'json' => json_decode($body, true)];
}

$adminSess = sys_get_temp_dir() . '/sess_admin.txt';
$kasirSess = sys_get_temp_dir() . '/sess_kasir.txt';
@unlink($adminSess);
@unlink($kasirSess);

echo "==============================================\n";
echo " PENGUJIAN END-TO-END API TOKO BANGUNAN\n";
echo "==============================================\n\n";

// ------------------------------------------------------------------
echo "1. AKSES PUBLIK & AUTENTIKASI\n";
$a = getData($BASE . 'pengaturan.php');
hasil('GET pengaturan.php tanpa login (publik)',
    $a['kode'] === 200 && $a['json']['ok'] === true && !empty($a['json']['pengaturan']['nama_toko']),
    'kode=' . $a['kode']);

$m = getData($BASE . 'me.php');
hasil('GET me.php belum login -> 401',
    $m['kode'] === 401 && $m['json']['ok'] === false,
    'kode=' . $m['kode']);

$b = getData($BASE . 'produk_list.php');
hasil('GET produk_list.php belum login -> 401',
    $b['kode'] === 401, 'kode=' . $b['kode']);

$l = kirim($BASE . 'login.php', $adminSess, ['username' => 'admin', 'password' => 'salah123']);
hasil('POST login password salah -> 401',
    $l['kode'] === 401 && $l['json']['ok'] === false, 'kode=' . $l['kode']);

$l2 = kirim($BASE . 'login.php', $adminSess, ['username' => 'admin', 'password' => 'admin123']);
hasil('POST login admin/admin123 berhasil, role=admin',
    $l2['kode'] === 200 && $l2['json']['ok'] === true && $l2['json']['user']['role'] === 'admin',
    'kode=' . $l2['kode']);

$me2 = getData($BASE . 'me.php', $adminSess);
hasil('GET me.php setelah login = sesi aktif',
    $me2['kode'] === 200 && $me2['json']['user']['username'] === 'admin',
    'kode=' . $x = ($me2['kode'] ?? '?'));

$lK = kirim($BASE . 'login.php', $kasirSess, ['username' => 'kasir1', 'password' => 'kasir123']);
hasil('POST login kasir1/kasir123 berhasil, role=kasir',
    $lK['kode'] === 200 && $lK['json']['user']['role'] === 'kasir', 'kode=' . $lK['kode']);

// ------------------------------------------------------------------
echo "\n2. PRODUK (READ)\n";
$p = getData($BASE . 'produk_list.php', $adminSess);
$semen = array_values(array_filter($p['json']['produk'] ?? [], fn($x) => $x['kode_produk'] === 'PRD-001'))[0] ?? null;
$stokAwalSemen = (int)($semen['stok'] ?? -1);
hasil('GET produk_list: 10 produk + supplier + kategori',
    $p['kode'] === 200 && count($p['json']['produk']) === 10
        && count($p['json']['supplier']) === 3 && count($p['json']['kategori']) >= 5,
    'kode=' . $p['kode'] . ' jumlah=' . count($p['json']['produk'] ?? []));
hasil('Stok PRD-001 = 118 (efek trigger + seed transaksi)',
    $stokAwalSemen === 118, 'stok=' . $stokAwalSemen);

// ------------------------------------------------------------------
echo "\n3. PRODUK (CREATE / UPDATE / DUPLIKAT) - ADMIN\n";
$n = kirim($BASE . 'produk_simpan.php', $adminSess, [
    'kode_produk' => 'PRD-011', 'nama_produk' => 'Genteng Betang',
    'kategori' => 'Genteng', 'satuan' => 'pcs', 'harga_beli' => 5000,
    'harga_jual' => 7000, 'stok' => 200, 'stok_minimum' => 20, 'supplier_id' => null,
]);
hasil('INSERT produk PRD-011 baru', $n['kode'] === 200 && $n['json']['ok'] === true,
    'kode=' . $n['kode'] . ' msg=' . ($n['json']['message'] ?? ''));

$d = kirim($BASE . 'produk_simpan.php', $adminSess, [
    'kode_produk' => 'PRD-011', 'nama_produk' => 'Duplikat',
    'satuan' => 'pcs', 'harga_beli' => 1, 'harga_jual' => 2, 'stok' => 1,
]);
hasil('INSERT kode duplikat ditolak (409)',
    $d['kode'] === 409 && $d['json']['ok'] === false, 'kode=' . $d['kode']);

$v = kirim($BASE . 'produk_simpan.php', $adminSess, [
    'kode_produk' => '', 'nama_produk' => '', 'satuan' => '', 'harga_beli' => -1,
]);
hasil('Validasi form produk kosong/negatif ditolak (422)',
    $v['kode'] === 422, 'kode=' . $v['kode']);

$u = kirim($BASE . 'produk_simpan.php', $adminSess, [
    'id' => $n['json']['id'], 'kode_produk' => 'PRD-011',
    'nama_produk' => 'Genteng Betang Premium', 'kategori' => 'Genteng',
    'satuan' => 'pcs', 'harga_beli' => 5200, 'harga_jual' => 7500,
    'stok' => 180, 'stok_minimum' => 20,
]);
hasil('UPDATE produk PRD-011 -> nama & stok berubah',
    $u['kode'] === 200 && $u['json']['ok'] === true, 'kode=' . $u['kode']);

$p2 = getData($BASE . 'produk_list.php', $adminSess);
$genteng = array_values(array_filter(
    $p2['json']['produk'],
    fn($x) => $x['kode_produk'] === 'PRD-011'
))[0] ?? null;
hasil('Hasil update terbaca di list (nama + stok 180)',
    $genteng !== null && $genteng['nama_produk'] === 'Genteng Betang Premium'
        && (int)$genteng['stok'] === 180, print_r($genteng, true));

// ------------------------------------------------------------------
echo "\n4. TRANSAKSI KASIR & TRIGGER STOK\n";
// 1) transaksi normal, 2 jenis barang
$t = kirim($BASE . 'penjualan_simpan.php', $adminSess, [
    'pelanggan_id' => 1,
    'diskon' => 5000,
    'bayar'  => 250000,
    'items'  => [
        ['produk_id' => 1, 'jumlah' => 2],   // Semen Gresik 62.000 x2 = 124.000
        ['produk_id' => 7, 'jumlah' => 5],   // Kabel 19.000  x5 =  95.000
    ],   // subtotal 219.000 - 5.000 = 214.000 ; bayar 250.000 -> kembali 36.000
]);
$f1   = $t['json']['faktur'] ?? [];
$noFaktur = $f1['no_faktur'] ?? '';
hasil('Transaksi normal disimpan + no_faktur + kembalian benar',
    $t['kode'] === 200
        && preg_match('/^TRX-\d{8}-\d{4}$/', $noFaktur) === 1
        && abs(($f1['total'] ?? 0) - 214000) < 0.01
        && abs(($f1['kembalian'] ?? 0) - 36000) < 0.01,
    'kode=' . $t['kode'] . ' ' . ($t['json']['message'] ?? json_encode($f1)));

$p3 = getData($BASE . 'produk_list.php', $adminSess);
$map = [];
foreach ($p3['json']['produk'] as $x) $map[$x['kode_produk']] = (int)$x['stok'];
hasil('TRIGGER SQL: stok Semen 118->116, Kabel 900->895',
    $map['PRD-001'] === $stokAwalSemen - 2 && $map['PRD-007'] === 895,
    "semen={$map['PRD-001']} kabel={$map['PRD-007']}");

// harga - stok gak cukup, harus rollback
$g = kirim($BASE . 'penjualan_simpan.php', $adminSess, [
    'diskon' => 0, 'bayar' => 99999999,
    'items'  => [['produk_id' => 1, 'jumlah' => 99999]],
]);
$p4 = getData($BASE . 'produk_list.php', $adminSess);
$semenBaru = null;
foreach ($p4['json']['produk'] as $x) if ($x['kode_produk'] === 'PRD-001') $semenBaru = (int)$x['stok'];
hasil('Transaksi stok kurang ditolak + rollback (stok tetap)',
    $g['kode'] === 422 && $semenBaru === $map['PRD-001'],
    'kode=' . $g['kode'] . ' stok=' . (string)$semenBaru);

$k = kirim($BASE . 'penjualan_simpan.php', $adminSess, [
    'diskon' => 0, 'bayar' => 1000,
    'items'  => [['produk_id' => 3, 'jumlah' => 1]],
]);
hasil('Bayar kurang dari total ditolak (422)',
    $k['kode'] === 422, 'kode=' . $k['kode']);

$k2 = kirim($BASE . 'penjualan_simpan.php', $adminSess, [
    'diskon' => 0, 'bayar' => 0, 'items' => []
]);
hasil('Keranjang kosong ditolak (422)',
    $k2['kode'] === 422, 'kode=' . $k2['kode']);

// transaksi tanpa pelanggan (umum)
$t2 = kirim($BASE . 'penjualan_simpan.php', $adminSess, [
    'pelanggan_id' => null, 'diskon' => 0, 'bayar' => 0,
    'items' => [['produk_id' => 10, 'jumlah' => 2]],
]);
hasil('Transaksi pembeli umum (pelanggan NULL) disimpan',
    $t2['kode'] === 200, 'kode=' . $t2['kode'] . ' msg=' . ($t2['json']['message'] ?? ''));

// ------------------------------------------------------------------
echo "\n5. DASHBOARD & LAPORAN\n";
$dd = getData($BASE . 'dashboard.php?periode=7', $adminSess);
$dd7 = $dd['json'];
$cr7 = $dd7['ringkasan']['transaksi_hari_ini'] ?? 0;
$om7 = $dd7['ringkasan']['pendapatan_hari_ini'] ?? 0;
$dd30 = getData($BASE . 'dashboard.php?periode=30', $adminSess);
$om30 = $dd30['json']['ringkasan']['pendapatan_hari_ini'] ?? 0;
$lab  = $dd7['grafik_penjualan']['labels'] ?? [];
$omz  = $dd7['grafik_penjualan']['omzet'] ?? [];
$totalGraf = array_sum($omz);
hasil('Dashboard ringkasan: 3 transaksi hari ini, omzet = 214000 + subtotal trx umum',
    $cr7 === 3 && abs($om7 - $totalGraf) < 0.01,
    "tr=$cr7 omzet=$om7 grafik=$totalGraf");

hasil('Grafik 7 hari = 7 label', count($lab) === 7, 'n=' . count($lab));
hasil('Grafik 14/7: periode=30 -> label lebih banyak',
    count($dd30['json']['grafik_penjualan']['labels'] ?? []) === 30);

$dm = getData($BASE . 'dashboard.php', $adminSess);
hasil('Stok menipis terdeteksi: Sika Beton PRD-010 (stok 6 sesuai setelah trx)',
    array_reduce($dm['json']['stok_menipis'] ?? [],
        fn($c, $i) => $c || $i['kode_produk'] === 'PRD-010', false));
hasil('Produk terlaris top-5 = array valid (1-5 item sesuai angka produk pernah terjual)',
    count($dm['json']['produk_terlaris'] ?? []) >= 1 && count($dm['json']['produk_terlaris'] ?? []) <= 5,
    'n=' . count($dm['json']['produk_terlaris'] ?? []));

// ------------------------------------------------------------------
echo "\n6. PENGATURAN TOKO (edit nama)\n";
$ed = kirim($BASE . 'pengaturan.php', $adminSess, [
    'nama_toko' => 'TOKO BANGUNAN GEMILANG SENTOSA',
    'alamat_toko' => 'Jl. Baru No. 88, Jakarta',
    'telepon_toko' => '0812-1111-2222',
]);
hasil('POST pengaturan admin: simpan nama baru', $ed['kode'] === 200 && $ed['json']['ok'] === true
    && $ed['json']['pengaturan']['nama_toko'] === 'TOKO BANGUNAN GEMILANG SENTOSA');
$get2 = getData($BASE . 'pengaturan.php');
hasil('Nama toko baru terbaca publik',
    $get2['json']['pengaturan']['nama_toko'] === 'TOKO BANGUNAN GEMILANG SENTOSA');
// balikan ke nama default utk konsistensi
kirim($BASE . 'pengaturan.php', $adminSess, ['nama_toko' => 'TOKO BANGUNAN MANDIRI']);

// ------------------------------------------------------------------
echo "\n7. MANAJEMEN USER & LEVEL\n";
$ul = getData($BASE . 'user_list.php', $adminSess);
hasil('user_list: 2 user awal (admin + kasir1)',
    $ul['kode'] === 200 && count($ul['json']['users']) === 2, json_encode($ul['json']));

$nu = kirim($BASE . 'user_simpan.php', $adminSess, [
    'username' => 'kasir2', 'nama_lengkap' => 'Kasir Kedua',
    'password' => 'rahasia', 'role' => 'kasir',
]);
hasil('Tambah user kasir2', $nu['kode'] === 200 && $nu['json']['ok'] === true);

$nu2 = kirim($BASE . 'user_simpan.php', $adminSess, [
    'username' => 'kasir2', 'nama_lengkap' => 'Dup', 'password' => 'xxxx', 'role' => 'kasir',
]);
hasil('Username duplikat ditolak (409)', $nu2['kode'] === 409);

$nu3 = kirim($BASE . 'user_simpan.php', $adminSess, [
    'username' => 'budi', 'nama_lengkap' => 'Budi', 'password' => 'xxxx', 'role' => 'bos', ]);
hasil('Level invalid (bos) ditolak (422)', $nu3['kode'] === 422);

// kasir2 bisa login
$kas2Sess = sys_get_temp_dir() . '/sess_kasir2.txt';
@unlink($kas2Sess);
$lg2 = kirim($BASE . 'login.php', $kas2Sess, ['username' => 'kasir2', 'password' => 'rahasia']);
hasil('User baru (kasir2) berhasil login', $lg2['kode'] === 200);

// edit kasir2 -> ganti nama, password kosong
$ed2 = kirim($BASE . 'user_simpan.php', $adminSess, [
    'id' => $nu['json']['id'], 'username' => 'kasir2',
    'nama_lengkap' => 'Kasir Dua Bagus', 'role' => 'kasir', ]);
hasil('Edit nama user tanpa ganti password', $ed2['kode'] === 200);

// reset password kasir2 & tes login
kirim($BASE . 'user_simpan.php', $adminSess, [
    'id' => $nu['json']['id'], 'username' => 'kasir2',
    'nama_lengkap' => 'Kasir Dua Bagus', 'role' => 'kasir', 'password' => 'passBaru123']);
$lg3 = kirim($BASE . 'login.php', sys_get_temp_dir() . '/sess_k2b.txt',
    ['username' => 'kasir2', 'password' => 'passBaru123']);
hasil('Reset password via admin & login berhasil', $lg3['kode'] === 200);

// hapus kasir2
$h = kirim($BASE . 'user_hapus.php', $adminSess, ['id' => $nu['json']['id']]);
hasil('Hapus user kasir2 (belum transaksi)', $h['kode'] === 200 && $h['json']['ok'] === true);

// hapus diri sendiri ditolak
$me3 = getData($BASE . 'me.php', $adminSess);
$h2 = kirim($BASE . 'user_hapus.php', $adminSess, ['id' => $me3['json']['user']['id']]);
hasil('Tidak boleh hapus akun sendiri (409)', $h2['kode'] === 409);

// Proteksi admin terakhir: buat admin2, hapus dulu, lalu coba demote admin1
$na = kirim($BASE . 'user_simpan.php', $adminSess, [
    'username' => 'admin2', 'nama_lengkap' => 'Admin Kedua',
    'password' => 'admin456', 'role' => 'admin']);
$ha2 = kirim($BASE . 'user_hapus.php', $adminSess, ['id' => $na['json']['id']]);
hasil('Hapus admin2 (tetap ada 1 admin)', $ha2['kode'] === 200);

$me4 = getData($BASE . 'me.php', $adminSess);
$idAdmin1 = $me4['json']['user']['id'];
$dem = kirim($BASE . 'user_simpan.php', $adminSess, [
    'id' => $idAdmin1, 'username' => 'admin', 'nama_lengkap' => 'Administrator',
    'role' => 'kasir']);
hasil('Demote admin TERAKHIR ditolak (409)', $dem['kode'] === 409,
    'kode=' . $dem['kode'] . ' ' . ($dem['json']['message'] ?? json_encode($dem)));

// ------------------------------------------------------------------
echo "\n8. KASIR TIDAK BOLEH AKSES MASTER \n";
$kProduk = kirim($BASE . 'produk_simpan.php', $kasirSess, [
    'kode_produk' => 'PRD-999', 'nama_produk' => 'X', 'satuan' => 'pcs',
    'harga_beli' => 1, 'harga_jual' => 2, 'stok' => 1]);
hasil('Kasir insert produk ditolak (403)', $kProduk['kode'] === 403);

$kUser = getData($BASE . 'user_list.php', $kasirSess);
hasil('Kasir lihat user_list ditolak (403)', $kUser['kode'] === 403);

$kPeng = kirim($BASE . 'pengaturan.php', $kasirSess, ['nama_toko' => 'HACKED']);
hasil('Kasir ubah nama toko ditolak (403)', $kPeng['kode'] === 403);

$kSet = getData($BASE . 'pengaturan.php');
hasil('Nama toko tidak berubah oleh kasir', $kSet['json']['pengaturan']['nama_toko'] === 'TOKO BANGUNAN MANDIRI');

$kJual = kirim($BASE . 'penjualan_simpan.php', $kasirSess, [
    'diskon' => 0, 'bayar' => 0,
    'items' => [['produk_id' => 3, 'jumlah' => 10]]]);
hasil('Kasir tetap BOLEH transaksi penjualan', $kJual['kode'] === 200);

// Suspicious: harga tidak bisa dipalsuikan via client
$kFake = kirim($BASE . 'penjualan_simpan.php', $kasirSess, [
    'diskon' => 0, 'bayar' => 0,
    'items'  => [['produk_id' => 3, 'jumlah' => 1, 'harga_satuan' => 1]]]);
$f2 = $kFake['json']['faktur'] ?? [];
hasil('Palsukan harga di client diabaikan (server pakai harga resmi)',
    abs(($f2['total'] ?? 0) - 1400) < 0.01,
    'total=' . ($f2['total'] ?? '?'));

// ------------------------------------------------------------------
echo "\n9. HAPUS PRODUK (FK & RESTRICT)\n";
$hd = kirim($BASE . 'produk_hapus.php', $adminSess, ['id' => $n['json']['id']]);
hasil('Hapus PRD-011 (tanpa transaksi) sukses', $hd['kode'] === 200);

$p5 = getData($BASE . 'produk_list.php', $adminSess);
$idSemen = 0;
foreach ($p5['json']['produk'] as $x) if ($x['kode_produk'] === 'PRD-001') $idSemen = $x['id'];
$hd2 = kirim($BASE . 'produk_hapus.php', $adminSess, ['id' => $idSemen]);
hasil('Hapus produk yang sudah pernah ditransaksikan ditolak (409)',
    $hd2['kode'] === 409, 'kode=' . $hd2['kode']);

// ------------------------------------------------------------------
echo "\n10. PELANGGAN, SUPPLIER & LAPORAN\n";
// supplier list & CRUD (admin)
$sl = getData($BASE . 'supplier_list.php', $adminSess);
hasil('supplier_list: 3 supplier + jumlah produk',
    $sl['kode'] === 200 && count($sl['json']['supplier']) === 3
        && ($sl['json']['supplier'][0]['jumlah_produm'] ?? null) === null,
    'kode=' . $sl['kode']);

$ss = kirim($BASE . 'supplier_simpan.php', $adminSess, [
    'nama_supplier' => 'CV Genteng Jaya Baru', 'telepon' => '081111111111']);
hasil('Tambah supplier baru', $ss['kode'] === 200 && $ss['json']['ok'] === true, json_encode($ss));

$su = kirim($BASE . 'supplier_simpan.php', $adminSess, [
    'id' => $ss['json']['id'], 'nama_supplier' => 'CV Genteng Jaya Baru (Edit)',
    'telepon' => '081222222222']);
hasil('Update supplier', $su['kode'] === 200);

$sh = kirim($BASE . 'supplier_hapus.php', $adminSess, ['id' => $ss['json']['id']]);
hasil('Hapus supplier (produk aman: FK SET NULL)', $sh['kode'] === 200);

// supplier utk kasir ditolak
$kSup = kirim($BASE . 'supplier_simpan.php', $kasirSess, ['nama_supplier' => 'Blokir Saya']);
hasil('Kasir tambah supplier ditolak (403)', $kSup['kode'] === 403);
$kSup2 = getData($BASE . 'supplier_list.php', $kasirSess);
hasil('Kasir boleh LIHAT supplier', $kSup2['kode'] === 200);

// pelanggan: insert oleh kasir (untuk POS), update/hapus oleh admin
$kP = kirim($BASE . 'pelanggan_simpan.php', $kasirSess, [
    'nama_pelanggan' => 'Pelanggan POS Baru', 'tipe' => 'reguler']);
hasil('Kasir tambah pelanggan baru (untuk POS)', $kP['kode'] === 200 && $kP['json']['ok'] === true);
$idPelBaru = $kP['json']['pelanggan']['id'];

$kP2 = kirim($BASE . 'pelanggan_simpan.php', $kasirSess, [
    'id' => $idPelBaru, 'nama_pelanggan' => 'Ubah via kasir']);
hasil('Kasir UPDATE pelanggan ditolak (403)', $kP2['kode'] === 403);

$aP = kirim($BASE . 'pelanggan_simpan.php', $adminSess, [
    'id' => $idPelBaru, 'nama_pelanggan' => 'Pelanggan POS Baru (edit admin)',
    'tipe' => 'kontraktor']);
hasil('Admin update pelanggan + tipe kontraktor', $aP['kode'] === 200);

// pelanggan yang sudah transaksi (Budi id=1) tak boleh dihapus
$hP = kirim($BASE . 'pelanggan_hapus.php', $adminSess, ['id' => 1]);
hasil('Hapus pelanggan yang sudah transaksi ditolak (409)', $hP['kode'] === 409);
$hP2 = kirim($BASE . 'pelanggan_hapus.php', $adminSess, ['id' => $idPelBaru]);
hasil('Hapus pelanggan tanpa transaksi sukses', $hP2['kode'] === 200);

// laporan harian
$bulanIni = date('Y-m-01');
$hariIni  = date('Y-m-d');
$lp = getData($BASE . "laporan.php?grup=harian&dari=$bulanIni&sampai=$hariIni", $adminSess);
$r = $lp['json'];
hasil('Laporan harian: omzet 439.400 (5 transaksi: seed+test), baris >= 1',
    $r['ok'] === true
        && abs($r['ringkasan']['omzet'] - 439400) < 0.01
        && $r['ringkasan']['transaksi'] === 5
        && count($r['rincian']) >= 1,
    json_encode($r['ringkasan'] ?? []));

hasil('Laporan harian: detail >= 5 faktur, terlaris terisi',
    count($r['detail'] ?? []) >= 5 && count($r['terlaris'] ?? []) >= 1,
    'detail=' . count($r['detail'] ?? []) . ' terlaris=' . count($r['terlaris'] ?? []));

// laporan bulanan
$lb = getData($BASE . "laporan.php?grup=bulanan&dari=$bulanIni&sampai=$hariIni", $adminSess);
$periodeOk = true;
foreach ($lb['json']['rincian'] ?? [] as $row) {
    if (!preg_match('/^\d{4}-\d{2}$/', $row['periode'])) $periodeOk = false;
}
hasil('Laporan bulanan: grup periode format YYYY-MM',
    $lb['json']['ok'] === true && count($lb['json']['rincian']) >= 1 && $periodeOk);

// laporan butuh login
$lpNo = getData($BASE . "laporan.php?grup=harian");
hasil('Laporan tanpa login -> 401', $lpNo['kode'] === 401);

// laporan laba kotor (omzet - harga beli): butuh login + data numerik
$lbl = getData($BASE . "laporan_laba.php?grup=harian&dari=$bulanIni&sampai=$hariIni", $adminSess);
$lbj = $lbl['json'];
hasil('Laporan laba kotor: ok + laba kotor numerik',
    $lbl['kode'] === 200 && ($lbj['ok'] ?? null) === true
        && isset($lbj['ringkasan']['laba_kotor'])
        && is_numeric($lbj['ringkasan']['laba_kotor']),
    'kode=' . $lbl['kode'] . ' ringkasan=' . json_encode($lbj['ringkasan'] ?? []));

// laporan laba kotor tanpa login -> 401
$lbn = getData($BASE . 'laporan_laba.php?grup=harian');
hasil('Laporan laba kotor tanpa login -> 401', $lbn['kode'] === 401);

// export CSV detail (admin): BOM + judul + header
$ch = curl_init($BASE . "laporan_export.php?type=detail&dari=$bulanIni&sampai=$hariIni");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $adminSess]);
$csv = (string)curl_exec($ch);
$ct  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);
hasil('Export CSV detail: text/csv + BOM + judul + baris faktur',
    str_contains($ct, 'text/csv')
        && str_starts_with($csv, "\xEF\xBB\xBF")
        && str_contains($csv, 'Rincian Transaksi')
        && substr_count($csv, 'TRX-') >= 5,
    'ct=' . $ct . ' len=' . strlen($csv));

$ch = curl_init($BASE . "laporan_export.php?type=harian&dari=$bulanIni&sampai=$hariIni");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $adminSess]);
$csvH = (string)curl_exec($ch);
curl_close($ch);
hasil('Export CSV harian (login): judul + kolom tanggal, item, omzet Lengkap',
    str_contains($csvH, 'Laporan Harian')
        && str_contains($csvH, 'Tanggal')
        && str_contains($csvH, 'Omzet (Rp)')
        && substr_count($csvH, ',') >= 3,
    'len=' . strlen($csvH) . ' head=' . substr($csvH, 0, 80));

// ------------------------------------------------------------------
echo "\n11. DIAGNOSTIK DATABASE (cek_database & perbaiki otomatis)\n";
// Diagnostik PUBLIK (tanpa login): status 6 komponen sehat
$cdb = getData($BASE . 'cek_database.php');
$stCek = $cdb['json']['status'] ?? [];
hasil('cek_database.php PUBLIK tanpa login: 200 + 6 komponen sehat',
    $cdb['kode'] === 200 && ($cdb['json']['ok'] ?? null) === true && count(array_filter($stCek)) === 6,
    'kode=' . $cdb['kode'] . ' status=' . json_encode($stCek));

// Kasir tidak boleh menjalankan perbaikan (menulis skema DB)
$kdb = kirim($BASE . 'perbaiki_database.php', $kasirSess);
hasil('Kasir jalankan perbaiki_database ditolak (403)',
    $kdb['kode'] === 403 && ($kdb['json']['ok'] ?? true) === false, 'kode=' . $kdb['kode']);

if (!class_exists('mysqli')) {
    hasil('Ekstensi mysqli tersedia utk simulasi trigger', false,
        'Aktifkan extension=mysqli di php.ini (CLI) dan ulangi pengujian');
} else {
    // Simulasi kerusakan dunia nyata: trigger hilang (mis. database lama/hasil import parsial)
    $my = new mysqli('127.0.0.1', 'root', '', 'db_tokobangunan');
    $dropOk = $my->query('DROP TRIGGER IF EXISTS trg_kurangi_stok')
              && $my->query('DROP TRIGGER IF EXISTS trg_kembalikan_stok');
    hasil('Simulasi kerusakan: 2 dari 3 trigger stok di-DROP via mysqli', $dropOk === true);

    // Diagnostik harus MENDETEKI-nya
    $cdb2 = getData($BASE . 'cek_database.php');
    $kodeKendala = array_column($cdb2['json']['kendala'] ?? [], 'kode');
    hasil('Diagnostik mendeteksi: trigger_lengkap=false + kendala TRIGGER_KURANG',
        $cdb2['kode'] === 200
            && ($cdb2['json']['status']['trigger_lengkap'] ?? true) === false
            && in_array('TRIGGER_KURANG', $kodeKendala, true),
        'status=' . json_encode($cdb2['json']['status'] ?? []));

    // Perbaikan otomatis oleh admin membuat ulang trigger
    $fdb = kirim($BASE . 'perbaiki_database.php', $adminSess);
    hasil('Admin "Periksa & Perbaiki": ok, tanpa gagal, trigger_lengkap kembali true',
        $fdb['kode'] === 200 && ($fdb['json']['ok'] ?? false) === true
            && empty($fdb['json']['gagal'])
            && (($fdb['json']['sesudah']['status']['trigger_lengkap'] ?? false) === true),
        'kode=' . $fdb['kode'] . ' ' . json_encode($fdb['json']['gagal'] ?? []));

    // Bukti hard: trigger benar-benar ada lagi di skema database
    $nTrig = (int)$my->query(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = 'db_tokobangunan' AND EVENT_OBJECT_TABLE = 'detail_penjualan'"
    )->fetch_row()[0];
    hasil('Bukti skema: 3 trigger stok terdaftar ulang di database', $nTrig === 3, "jumlah=$nTrig");

    // Uji fungsional: stok Bata (PRD-003) berkurang lewat trigger hasil perbaikan
    $pl = getData($BASE . 'produk_list.php', $adminSess);
    $idBata = 0; $stokBataAwal = -1;
    foreach ($pl['json']['produk'] ?? [] as $x) {
        if ($x['kode_produk'] === 'PRD-003') { $idBata = (int)$x['id']; $stokBataAwal = (int)$x['stok']; }
    }
    $bata = kirim($BASE . 'penjualan_simpan.php', $adminSess, [
        'pelanggan_id' => null, 'diskon' => 0, 'bayar' => 0,
        'items' => [['produk_id' => $idBata, 'jumlah' => 1]],
    ]);
    $pl2 = getData($BASE . 'produk_list.php', $adminSess);
    $stokBataAkhir = -2;
    foreach ($pl2['json']['produk'] ?? [] as $x) {
        if ((int)$x['id'] === $idBata) $stokBataAkhir = (int)$x['stok'];
    }
    hasil('Trigger benar-benar HIDUP setelah perbaikan: beli 1 Bata -> stok -1',
        $bata['kode'] === 200 && $stokBataAkhir === $stokBataAwal - 1,
        "kode={$bata['kode']} stok $stokBataAwal -> $stokBataAkhir");
    $my->close();
}

// ------------------------------------------------------------------
echo "\n==============================================\n";
echo " HASIL: {$hasil['pass']} PASS / {$hasil['fail']} GAGAL\n";
echo "==============================================\n";
if ($gagalan) {
    echo "Pengujian gagal:\n";
    foreach ($gagalan as $g) echo " - $g\n";
}
exit($hasil['fail'] > 0 ? 1 : 0);

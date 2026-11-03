# Aplikasi Penjualan — Toko Bangunan

Aplikasi penjualan/kasir (POS) berbasis web: **HTML + Bootstrap 5 (frontend)**,
**PHP (backend REST API)**, dan **MySQL** (database) dengan trigger otomatis
potong stok.

---

## 📁 Struktur Proyek

```
TOKO/
├── db_tokobangunan.sql        # Blueprint database (jalankan dulu!)
├── login.html                 # Halaman login
├── dashboard.html             # Dashboard (ringkasan + grafik Chart.js)
├── kasir.html                 # POS: keranjang, diskon, struk thermal 80mm
├── produk.html                # CRUD master produk
├── pengaturan.html            # Edit nama/alamat/telepon toko + manajemen user (admin)
├── includes/
│   └── config.php             # Koneksi PDO + session + helper auth/JSON
└── api/
    ├── login.php  logout.php  me.php
    ├── produk_list.php  produk_simpan.php  produk_hapus.php
    ├── pelanggan_list.php  pelanggan_simpan.php
    ├── pengaturan.php                # Identitas toko (GET publik, POST admin)
    ├── user_list.php  user_simpan.php  user_hapus.php   # Manajemen user (admin)
    ├── penjualan_simpan.php          # Transaksi POS (DB transaction)
    └── dashboard.php                 # Data ringkasan + grafik + terlaris
```

---

## 🚀 Cara Menjalankan (XAMPP)

1. **Install & jalankan XAMPP** → aktifkan modul **Apache** dan **MySQL**.

2. **Pindahkan folder ini** ke `C:\xampp\htdocs\TOKO`.

3. **Import database:**
   - Buka `http://localhost/phpmyadmin` (atau via `mysql` CLI),
   - Import file `db_tokobangunan.sql`
     (script sudah berisi `CREATE DATABASE`, tabel, trigger, view,
     dan data contoh).
   - Bila database sudah ada dari versi lama, jalankan saja
     `upgrade_v2.sql` (menambah tabel `pengaturan`).
   - Password seed sudah ter-hash `password_hash()`:
     - `admin` / `admin123` (role admin)
     - `kasir1` / `kasir123` (role kasir)

4. **Sesuaikan koneksi** bila perlu di `includes/config.php`
   (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).

5. **Buka aplikasi:** `http://localhost/TOKO/login.html`

---

## 🔐 Hak Akses Role

| Aksi                          | admin | kasir |
|-------------------------------|:-----:|:-----:|
| Login & transaksi kasir (POS) |  ✅   |  ✅   |
| Lihat dashboard/produk        |  ✅   |  ✅   |
| Tambah / edit / hapus produk  |  ✅   |  ❌   |
| Tambah pelanggan              |  ✅   |  ✅   |
| Pengaturan nama toko          |  ✅   |  ❌   |
| Manajemen user + level        |  ✅   |  ❌   |

Guard dilakukan per-API (`require_login([roles])` di `includes/config.php`),
HTTP 401 bila belum login (frontend redirect ke `login.html`), HTTP 403 bila
role tidak diizinkan.

---

## 💡 Hal Penting Desain

- **Stok otomatis**: potong stok ditangani **trigger SQL**
  (`trg_kurangi_stok`) saat `INSERT detail_penjualan` — backend tidak
  mengubah stok secara manual, sehingga tidak bisa "terlewat" oleh bug PHP.
  Trigger juga `SIGNAL` 45000 bila stok kurang → transaksi dibatalkan.
- **Total dihitung ulang di server**: `api/penjualan_simpan.php` mengabaikan
  harga dari client, membaca `harga_jual` terkini dengan `SELECT ... FOR UPDATE`
  dalam satu **DB transaction** — mencegah manipulasi & race condition stok.
- **No. faktur**: generator `TRX-YYYYMMDD-0001` di API dengan pengecekan
  duplikat (aman dari race condition bila 2 kasir men-doubleclick).
- **Struk**: nominal struk mengambil data faktur yang dikembalikan server,
  bukan hitungan sisi client; cetak via `window.print()` (CSS `@media print`,
  lebar 80mm untuk printer thermal).
- **Keamanan**: PDO prepared statement (anti SQL injection),
  `password_verify` bcrypt, `session_regenerate_id`, cek role per endpoint.

---

## 🧪 Uji Cepat Setelah Instalasi

1. Login sebagai `kasir1`.
2. Buka **Transaksi Kasir** → ketik `semen` di pencarian → pilih produk →
   set jumlah 2 → klik **Pas** → **BAYAR & CETAK STRUK**.
3. Struk muncul dengan no. faktur `TRX-...`; stok Semen Gresik berkurang 2
   (cek di **Manajemen Produk**).
4. Buka **Dashboard** → pendapatan hari ini bertambah.

## ✅ Hasil Pengujian Otomatis

`php test_pengujian.php` menguji end-to-end seluruh API (46 test):

| Kategori | Cakupan |
|---|---|
| Autentikasi | login benar/salah, guard 401, session |
| Produk | read, insert, update, duplikat 409, validasi 422 |
| Kasir & trigger | no. faktur, kembalian, stok otomatis 118→116, rollback stok kurang |
| Dashboard | omzet, grafik 7/14/30 hari, stok menipis, terlaris |
| Pengaturan | edit nama toko + guard role |
| User & level | CRUD user, reset password, hapus admin terakhir ditolak, kasir ditolak 403 |
| Security | harga palsu dari client diabaikan, FK produk tertransaksi |

Status terakhir: **46 PASS / 0 GAGAL** (MySQL + `php -S` harus aktif,
database di-reset dulu dengan import `db_tokobangunan.sql`).

---

## ➕ Pengembangan Selanjutnya (opsional)

- Halaman **Pelanggan & Supplier menu** (API `pelanggan_*` sudah siap,
  tinggal tambah `supplier_list.php`/`supplier_simpan.php`).
- Menu **Laporan Penjualan** (query contoh ada di bawah file
  `db_tokobangunan.sql`, view `v_laporan_harian` siap pakai).
- Upload foto produk, nota A4 untuk kontraktor, dan export Excel laporan.

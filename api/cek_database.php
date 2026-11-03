<?php
/**
 * api/cek_database.php - Diagnostik database (GET)
 * Dipanggil halaman login & tombol "Periksa & Perbaiki" di Pengaturan.
 * Data tunggal: status kesehatan (presence booleans) + daftar kendala
 * yang bisa diperbaiki. Tidak membocorkan data sensitif.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/dbhealth.php';

json_out(dbhealth_status());

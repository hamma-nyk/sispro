<?php
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'database.php') {
    http_response_code(403);
    die("Forbidden");
}

$env_path = dirname(__DIR__) . '/.env';
$env = file_exists($env_path) ? parse_ini_file($env_path, false, INI_SCANNER_RAW) : [];

$host   = $env['PG_SISPRO_HOST']; 
$port   = $env['PG_SISPRO_PORT'];
$dbname = $env['PG_SISPRO_DB'];
$user   = $env['PG_SISPRO_USER'];
$pass   = $env['PG_SISPRO_PASS'];

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Koneksi Database PostgreSQL Gagal.");
}

// Koneksi ke PostgreSQL HRMS (NBIM)
$pg_hrms_host   = $env['PG_HRMS_HOST'];
$pg_hrms_port   = $env['PG_HRMS_PORT'];
$pg_hrms_dbname = $env['PG_HRMS_DB'];
$pg_hrms_user   = $env['PG_HRMS_USER'];
$pg_hrms_pass   = $env['PG_HRMS_PASS'];

try {
    $dsn_hrms = "pgsql:host=$pg_hrms_host;port=$pg_hrms_port;dbname=$pg_hrms_dbname";
    $pdo_hrms = new PDO($dsn_hrms, $pg_hrms_user, $pg_hrms_pass);
    $pdo_hrms->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_hrms->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $GLOBALS['hrms_error'] = $e->getMessage();
    error_log("Koneksi Database HRMS (NBIM) Gagal: " . $e->getMessage());
}

// Koneksi ke PostgreSQL CRM
$pg_crm_host   = $env['PG_CRM_HOST'];
$pg_crm_port   = $env['PG_CRM_PORT'];
$pg_crm_dbname = $env['PG_CRM_DB'];
$pg_crm_user   = $env['PG_CRM_USER'];
$pg_crm_pass   = $env['PG_CRM_PASS'];

try {
    $dsn_crm = "pgsql:host=$pg_crm_host;port=$pg_crm_port;dbname=$pg_crm_dbname";
    $pdo_crm = new PDO($dsn_crm, $pg_crm_user, $pg_crm_pass);
    $pdo_crm->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_crm->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $GLOBALS['crm_error'] = $e->getMessage();
    error_log("Koneksi Database CRM Gagal: " . $e->getMessage());
}

// Koneksi ke MS Access (File MDB Absensi)
$mdb_path = $env['MDB_ABSENSI_PATH'] ?? '\\\\192.168.10.10\\Absensi\\C_ABSEN.mdb';

try {
    $dsn_mdb = "odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=$mdb_path;";
    $pdo_mdb = new PDO($dsn_mdb);
    $pdo_mdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_mdb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $GLOBALS['mdb_error'] = $e->getMessage();
    error_log("Koneksi MS Access (C_ABSEN.mdb) Gagal: " . $e->getMessage());
}

// Koneksi ke SQL Server (Database d_master)
$ms_host   = $env['MS_SQL_HOST'];
$ms_dbname = $env['MS_SQL_DB'];
$ms_user   = $env['MS_SQL_USER'];
$ms_pass   = $env['MS_SQL_PASS'];

try {
    // $dsn_ms = "sqlsrv:Server=$ms_host;Database=$ms_dbname";
    // Jika pakai ODBC:
    $dsn_ms = "odbc:Driver={SQL Server};Server=$ms_host;Database=$ms_dbname;";
    
    $pdo_ms = new PDO($dsn_ms, $ms_user, $ms_pass);
    $pdo_ms->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_ms->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Simpan pesan error asli ke global agar bisa ditampilkan di UI
    $GLOBALS['ms_error'] = $e->getMessage();
    error_log("Koneksi Database SQL Server Gagal: " . $e->getMessage());
}

try {
    $pdo->exec("CREATE SCHEMA IF NOT EXISTS sc_master");

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sc_master.role (
            id SERIAL PRIMARY KEY,
            nama VARCHAR(50) UNIQUE NOT NULL,
            keterangan VARCHAR(255) DEFAULT NULL,
            is_superadmin BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sc_master.module (
            id SERIAL PRIMARY KEY,
            kode VARCHAR(50) UNIQUE NOT NULL,
            nama VARCHAR(100) NOT NULL,
            icon TEXT DEFAULT NULL,
            url VARCHAR(100) DEFAULT NULL,
            grup VARCHAR(50) DEFAULT NULL,
            urutan INT NOT NULL DEFAULT 0,
            is_active BOOLEAN NOT NULL DEFAULT TRUE
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sc_master.role_module (
            id SERIAL PRIMARY KEY,
            role_id INT NOT NULL REFERENCES sc_master.role(id) ON DELETE CASCADE,
            module_id INT NOT NULL REFERENCES sc_master.module(id) ON DELETE CASCADE,
            UNIQUE(role_id, module_id)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sc_master."user" (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            nama_lengkap VARCHAR(100) NOT NULL,
            role_id INT REFERENCES sc_master.role(id) ON DELETE SET NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $colCheck = $pdo->query("
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema='sc_master' AND table_name='user' AND column_name='role_id'
    ")->fetchColumn();
    if (!$colCheck) {
        $pdo->exec('ALTER TABLE sc_master."user" ADD COLUMN role_id INT REFERENCES sc_master.role(id) ON DELETE SET NULL');
        $pdo->exec('ALTER TABLE sc_master."user" DROP COLUMN IF EXISTS role');
    }

    $check = $pdo->query("SELECT COUNT(*) FROM sc_master.role")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("INSERT INTO sc_master.role (nama, keterangan, is_superadmin) VALUES ('Superadmin', 'Akses penuh ke semua modul', TRUE)");
    }

    $check = $pdo->query('SELECT COUNT(*) FROM sc_master."user"')->fetchColumn();
    if ($check == 0) {
        $saRole = $pdo->query("SELECT id FROM sc_master.role WHERE is_superadmin = TRUE LIMIT 1")->fetchColumn();
        $pdo->prepare('INSERT INTO sc_master."user" (username, password, nama_lengkap, role_id) VALUES (?, ?, ?, ?)')
            ->execute(['superadmin', password_hash('superadmin', PASSWORD_BCRYPT), 'Super Administrator', $saRole]);
    }

    $check = $pdo->query("SELECT COUNT(*) FROM sc_master.module")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("
                        INSERT INTO sc_master.module (kode, nama, icon, url, grup, urutan) VALUES
            ('dashboard', 'Dashboard', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z\"></path><polyline points=\"9 22 9 12 15 12 15 22\"></polyline></svg>', 'index.php', NULL, 1),
            ('master_user', 'Data Pengguna', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2\"></path><circle cx=\"9\" cy=\"7\" r=\"4\"></circle><path d=\"M23 21v-2a4 4 0 0 0-3-3.87\"></path><path d=\"M16 3.13a4 4 0 0 1 0 7.75\"></path></svg>', 'master_user.php', 'Master Data', 10),
            ('master_role', 'Data Role & Akses', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z\"></path></svg>', 'master_role.php', 'Master Data', 11),
            ('master_module', 'Data Modul', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"3\" width=\"7\" height=\"7\"></rect><rect x=\"14\" y=\"3\" width=\"7\" height=\"7\"></rect><rect x=\"14\" y=\"14\" width=\"7\" height=\"7\"></rect><rect x=\"3\" y=\"14\" width=\"7\" height=\"7\"></rect></svg>', 'master_module.php', 'Master Data', 12),
            ('copy_module', 'Copy Module User', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"9\" y=\"9\" width=\"13\" height=\"13\" rx=\"2\" ry=\"2\"></rect><path d=\"M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1\"></path></svg>', 'copy_module.php', 'Tools', 20),
            ('cetak_ulang_po', 'Cetak Ulang PO', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"6 9 6 2 18 2 18 9\"></polyline><path d=\"M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2\"></path><rect x=\"6\" y=\"14\" width=\"12\" height=\"8\"></rect></svg>', 'cetak_ulang_po.php', 'Tools', 21),
            ('ubah_tipe_pallet', 'Ubah Tipe Pallet & Franco', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"16.5\" y1=\"9.4\" x2=\"7.5\" y2=\"4.21\"></line><path d=\"M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z\"></path><polyline points=\"3.27 6.96 12 12.01 20.73 6.96\"></polyline><line x1=\"12\" y1=\"22.08\" x2=\"12\" y2=\"12\"></line></svg>', 'ubah_tipe_pallet.php', 'Tools', 22),
            ('ubah_top_duedate', 'Ubah TOP & Due Date', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"4\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"></rect><line x1=\"16\" y1=\"2\" x2=\"16\" y2=\"6\"></line><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"6\"></line><line x1=\"3\" y1=\"10\" x2=\"21\" y2=\"10\"></line></svg>', 'ubah_top_duedate.php', 'Tools', 23),
            ('update_penimbangan', 'Update Penimbangan', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"22 12 18 12 15 21 9 3 6 12 2 12\"></polyline></svg>', 'update_penimbangan.php', 'Tools', 25),
            ('ubah_tgl_diterima', 'Ubah Tanggal Diterima', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\" ry=\"2\"></rect><line x1=\"16\" y1=\"2\" x2=\"16\" y2=\"6\"></line><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"6\"></line><line x1=\"3\" y1=\"10\" x2=\"21\" y2=\"10\"></line><path d=\"m9 16 2 2 4-4\"></path></svg>', 'ubah_tgl_diterima.php', 'SisPro', 25),
            ('session_management', 'Session & System Lock', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"11\" width=\"18\" height=\"11\" rx=\"2\" ry=\"2\"></rect><path d=\"M7 11V7a5 5 0 0 1 10 0v4\"></path></svg>', 'session_management.php', 'Tools', 24),
            ('release_bbk', 'Release BBK', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"22\" y1=\"2\" x2=\"11\" y2=\"13\"></line><polygon points=\"22 2 15 22 11 13 2 9 22 2\"></polygon></svg>', 'release_bbk.php', 'Tools', 26),
            ('fix_transfer_bj', 'Fix Transfer Produk Jadi', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"1\" y=\"3\" width=\"15\" height=\"13\"></rect><polygon points=\"16 8 20 8 23 11 23 16 16 16 16 8\"></polygon><circle cx=\"5.5\" cy=\"18.5\" r=\"2.5\"></circle><circle cx=\"18.5\" cy=\"18.5\" r=\"2.5\"></circle></svg>', 'fix_transfer_bj.php', 'Tools', 27),
            ('ubah_tujuan', 'Update Tujuan Kirim', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z\"></path><circle cx=\"12\" cy=\"10\" r=\"3\"></circle></svg>', 'update_tujuan.php', 'Tools', 28),
            ('hapus_rekap_kbkb', 'Status Rekap KBKB', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"3 6 5 6 21 6\"></polyline><path d=\"M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\"></path></svg>', 'hapus_rekap_kbkb.php', 'Tools', 29),
            ('fix_po_gantung', 'Fix PO Gantung', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z\"></path></svg>', 'fix_po_gantung.php', 'Tools', 30),
            ('hapus_spr', 'Hapus SPR', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\"></path><polyline points=\"14 2 14 8 20 8\"></polyline><line x1=\"9\" y1=\"15\" x2=\"15\" y2=\"9\"></line><line x1=\"15\" y1=\"15\" x2=\"9\" y2=\"9\"></line></svg>', 'hapus_spr.php', 'Tools', 31),
            ('batal_spk_driver', 'Batal SPK Driver', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polygon points=\"7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2\"></polygon><line x1=\"15\" y1=\"9\" x2=\"9\" y2=\"15\"></line><line x1=\"9\" y1=\"9\" x2=\"15\" y2=\"15\"></line></svg>', 'batal_spk_driver.php', 'Tools', 32),
            ('fix_spk_kbkb', 'Fix SPK di KBKB', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z\"></path></svg>', 'fix_spk_kbkb.php', 'Tools', 33),
            ('update_nama_barang', 'Update Nama Barang', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z\"></path><line x1=\"7\" y1=\"7\" x2=\"7.01\" y2=\"7\"></line></svg>', 'update_nama_barang.php', 'Master Data', 34),
            ('copy_module_hrms', 'Copy Akses Menu', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"9\" y=\"9\" width=\"13\" height=\"13\" rx=\"2\" ry=\"2\"></rect><path d=\"M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1\"></path></svg>', 'copy_module_hrms.php', 'HRMS', 40),
            ('hapus_lembur', 'Hapus Lembur', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2\"></path><circle cx=\"8.5\" cy=\"7\" r=\"4\"></circle><line x1=\"23\" y1=\"11\" x2=\"17\" y2=\"11\"></line></svg>', 'hapus_lembur.php', 'HRMS', 41),
            ('edit_jam_peminjaman', 'Edit Jam Peminjaman', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"12\" cy=\"12\" r=\"10\"></circle><polyline points=\"12 6 12 12 16 14\"></polyline></svg>', 'edit_jam_peminjaman.php', 'HRMS', 42),
            ('cek_lembur', 'Cek Lembur', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"11\" cy=\"11\" r=\"8\"></circle><line x1=\"21\" y1=\"21\" x2=\"16.65\" y2=\"16.65\"></line></svg>', 'cek_lembur.php', 'HRMS', 43),
            ('cek_belum_regu', 'Belum Masuk Regu', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2\"></path><circle cx=\"8.5\" cy=\"7\" r=\"4\"></circle><line x1=\"23\" y1=\"11\" x2=\"17\" y2=\"11\"></line></svg>', 'cek_belum_regu.php', 'HRMS', 44),
            ('cek_masuk_regu', 'Sudah Masuk Regu', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2\"></path><circle cx=\"8.5\" cy=\"7\" r=\"4\"></circle><polyline points=\"17 11 19 13 23 9\"></polyline></svg>', 'cek_masuk_regu.php', 'HRMS', 44),
            ('cek_belum_terjadwal', 'Regu Belum Terjadwal', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"4\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"></rect><line x1=\"16\" y1=\"2\" x2=\"16\" y2=\"6\"></line><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"6\"></line><line x1=\"3\" y1=\"10\" x2=\"21\" y2=\"10\"></line><line x1=\"10\" y1=\"14\" x2=\"14\" y2=\"18\"></line><line x1=\"14\" y1=\"14\" x2=\"10\" y2=\"18\"></line></svg>', 'cek_belum_terjadwal.php', 'HRMS', 45),
            ('cek_presensi', 'Cek Presensi', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M22 11.08V12a10 10 0 1 1-5.93-9.14\"></path><polyline points=\"22 4 12 14.01 9 11.01\"></polyline></svg>', 'cek_presensi.php', 'HRMS', 46),
            ('bypass_presensi', 'Bypass Presensi', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"11\" width=\"18\" height=\"11\" rx=\"2\" ry=\"2\"></rect><path d=\"M7 11V7a5 5 0 0 1 9.9-1\"></path></svg>', 'bypass_presensi.php', 'HRMS', 47),
            ('edit_presensi_nik', 'Edit Presensi', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7\"></path><path d=\"M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z\"></path></svg>', 'edit_presensi_nik.php', 'HRMS', 48),
            ('reset_mutasi_promosi', 'Reset Status Mutasi', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 2v6h-6\"></path><path d=\"M3 12a9 9 0 0 1 15-6.7L21 8\"></path><path d=\"M3 22v-6h6\"></path><path d=\"M21 12a9 9 0 0 1-15 6.7L3 16\"></path></svg>', 'reset_mutasi_promosi.php', 'HRMS', 49),
            ('reset_dinas', 'Reset Status Dinas', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16\"></path><rect width=\"20\" height=\"14\" x=\"2\" y=\"6\" rx=\"2\"></rect></svg>', 'reset_dinas.php', 'HRMS', 50),
            ('tarikan_checkin', 'Tarikan Checkin', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4\"></path><polyline points=\"7 10 12 15 17 10\"></polyline><line x1=\"12\" y1=\"15\" x2=\"12\" y2=\"3\"></line></svg>', 'tarikan_checkin.php', 'CRM', 50),
            ('tarikan_jadwal_kunjungan', 'Jadwal Kunjungan', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"4\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"></rect><line x1=\"16\" y1=\"2\" x2=\"16\" y2=\"6\"></line><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"6\"></line><line x1=\"3\" y1=\"10\" x2=\"21\" y2=\"10\"></line><path d=\"M8 14h.01\"></path><path d=\"M12 14h.01\"></path><path d=\"M16 14h.01\"></path><path d=\"M8 18h.01\"></path><path d=\"M12 18h.01\"></path><path d=\"M16 18h.01\"></path></svg>', 'tarikan_jadwal_kunjungan.php', 'CRM', 51),
            ('tarikan_data_outlet', 'Data Outlet', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z\"></path><polyline points=\"9 22 9 12 15 12 15 22\"></polyline></svg>', 'tarikan_data_outlet.php', 'CRM', 52),
            ('tarikan_data_distributor', 'Data Distributor', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"1\" y=\"3\" width=\"15\" height=\"13\"></rect><polygon points=\"16 8 20 8 23 11 23 16 16 16 16 8\"></polygon><circle cx=\"5.5\" cy=\"18.5\" r=\"2.5\"></circle><circle cx=\"18.5\" cy=\"18.5\" r=\"2.5\"></circle></svg>', 'tarikan_data_distributor.php', 'CRM', 53),
            ('crud_absensi', 'Edit Data Mesin Absen', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"2\" y=\"3\" width=\"20\" height=\"14\" rx=\"2\" ry=\"2\"></rect><line x1=\"8\" y1=\"21\" x2=\"16\" y2=\"21\"></line><line x1=\"12\" y1=\"17\" x2=\"12\" y2=\"21\"></line></svg>', 'crud_absensi.php', 'ABSENSI', 60),
            ('hitung_piutang', 'Hitung Ulang Kartu Piutang', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"12\" y1=\"1\" x2=\"12\" y2=\"23\"></line><path d=\"M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6\"></path></svg>', 'hitung_piutang.php', 'SISPRO', 99)
        ");
    }
} catch(PDOException $e) {
    error_log("Migration gagal: " . $e->getMessage());
}
?>

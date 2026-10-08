<?php
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'database.php') {
    http_response_code(403);
    die("Forbidden");
}

$host   = '192.168.10.16'; 
$port   = '5432';
$dbname = 'ikoit_sispro';
$user   = 'postgres';
$pass   = 'postgres';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Koneksi Database PostgreSQL Gagal.");
}

// Koneksi ke PostgreSQL HRMS (NBIM)
$pg_hrms_host   = '192.168.10.12'; // Asumsi IP yang sama, ubah jika beda
$pg_hrms_port   = '5432';
$pg_hrms_dbname = 'NBIM';
$pg_hrms_user   = 'postgres';
$pg_hrms_pass   = '89$CrM@*nBi_';

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
$pg_crm_host   = '192.168.3.15';
$pg_crm_port   = '3925';
$pg_crm_dbname = 'CRM2020'; // Asumsi default, sesuaikan kalau ada db spesifik
$pg_crm_user   = 'postgres';
$pg_crm_pass   = '89$CrM@*nBi_';

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
$mdb_path = '\\\\192.168.10.10\\Absensi\\C_ABSEN.mdb';
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
// Sesuaikan kredensial dan IP dengan server SQL Server yang digunakan
$ms_host   = '192.168.10.11'; // Ganti jika beda IP
$ms_dbname = 'D_Master';
$ms_user   = 'sa';
$ms_pass   = 'PASSWORDSETUPSRVNUSANTARASIDIANYA'; // Sesuaikan

try {
    // Bisa pakai odbc: atau sqlsrv: (di sini pakai sqlsrv sebagai standar PHP Windows, ubah ke odbc jika driver yang ada odbc)
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
            ('dashboard', 'Dashboard', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"7\" height=\"9\" x=\"3\" y=\"3\" rx=\"1\"/><rect width=\"7\" height=\"5\" x=\"14\" y=\"3\" rx=\"1\"/><rect width=\"7\" height=\"9\" x=\"14\" y=\"12\" rx=\"1\"/><rect width=\"7\" height=\"5\" x=\"3\" y=\"16\" rx=\"1\"/></svg>', 'index.php', NULL, 1),
            ('master_user', 'Data Pengguna', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M22 21v-2a4 4 0 0 0-3-3.87\"/><path d=\"M16 3.13a4 4 0 0 1 0 7.75\"/></svg>', 'master_user.php', 'Master Data', 10),
            ('master_role', 'Data Role & Akses', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"m21 16-4 4-4-4\"/><path d=\"M17 20V4\"/><path d=\"m3 8 4-4 4 4\"/><path d=\"M7 4v16\"/></svg>', 'master_role.php', 'Master Data', 11),
            ('master_module', 'Data Modul', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z\"/><polyline points=\"9 22 9 12 15 12 15 22\"/></svg>', 'master_module.php', 'Master Data', 12),
            ('copy_module', 'Copy Module User', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"14\" height=\"14\" x=\"8\" y=\"8\" rx=\"2\" ry=\"2\"/><path d=\"M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2\"/></svg>', 'copy_module.php', 'Tools', 20),
            ('cetak_ulang_po', 'Cetak Ulang PO', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2\"/><path d=\"M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6\"/><rect x=\"6\" y=\"14\" width=\"12\" height=\"8\" rx=\"1\"/></svg>', 'cetak_ulang_po.php', 'Tools', 21),
            ('ubah_tipe_pallet', 'Ubah Tipe Pallet & Franco', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"m7.5 4.27 9 5.15\"/><path d=\"M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z\"/><path d=\"m3.3 7 8.7 5 8.7-5\"/><path d=\"M12 22V12\"/></svg>', 'ubah_tipe_pallet.php', 'Tools', 22),
            ('ubah_top_duedate', 'Ubah TOP & Due Date', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M8 2v4\"/><path d=\"M16 2v4\"/><rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\"/><path d=\"M3 10h18\"/><path d=\"m14 14-4 4\"/><path d=\"m10 14 4 4\"/></svg>', 'ubah_top_duedate.php', 'Tools', 23),
            ('update_penimbangan', 'Update Penimbangan', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2\"/><path d=\"M15 18H9\"/><path d=\"M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14\"/><circle cx=\"17\" cy=\"18\" r=\"2\"/><circle cx=\"7\" cy=\"18\" r=\"2\"/></svg>', 'update_penimbangan.php', 'Tools', 25),
            ('session_management', 'Session & System Lock', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"11\" x=\"3\" y=\"11\" rx=\"2\" ry=\"2\"/><path d=\"M7 11V7a5 5 0 0 1 10 0v4\"/></svg>', 'session_management.php', 'Tools', 24),
            ('release_bbk', 'Release BBK', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"11\" x=\"3\" y=\"11\" rx=\"2\" ry=\"2\"/><path d=\"M7 11V7a5 5 0 0 1 9.9-1\"/></svg>', 'release_bbk.php', 'Tools', 26),
            ('fix_transfer_bj', 'Fix Transfer Produk Jadi', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z\"/></svg>', 'fix_transfer_bj.php', 'Tools', 27),
            ('ubah_tujuan', 'Update Tujuan Kirim', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z\"/><circle cx=\"12\" cy=\"10\" r=\"3\"/></svg>', 'update_tujuan.php', 'Tools', 28),
            ('hapus_rekap_kbkb', 'Status Rekap KBKB', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8\"/><path d=\"M3 3v5h5\"/><path d=\"M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16\"/><path d=\"M16 16h5v5\"/></svg>', 'hapus_rekap_kbkb.php', 'Tools', 29),
            ('fix_po_gantung', 'Fix PO Gantung', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M12 2v20\"/><path d=\"M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6\"/></svg>', 'fix_po_gantung.php', 'Tools', 30),
            ('hapus_spr', 'Hapus SPR', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/><line x1=\"9\" x2=\"15\" y1=\"15\" y2=\"15\"/><line x1=\"12\" x2=\"12\" y1=\"12\" y2=\"18\"/></svg>', 'hapus_spr.php', 'Tools', 31),
            ('batal_spk_driver', 'Batal SPK Driver', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2\"/><path d=\"M15 18H9\"/><path d=\"M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14\"/><circle cx=\"17\" cy=\"18\" r=\"2\"/><circle cx=\"7\" cy=\"18\" r=\"2\"/><line x1=\"3\" y1=\"3\" x2=\"21\" y2=\"21\"/></svg>', 'batal_spk_driver.php', 'Tools', 32),
            ('fix_spk_kbkb', 'Fix SPK di KBKB', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"3\" rx=\"2\" ry=\"2\"/><path d=\"m9 12 2 2 4-4\"/></svg>', 'fix_spk_kbkb.php', 'Tools', 33),
            ('update_nama_barang', 'Update Nama Barang', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z\"/><polyline points=\"3.27 6.96 12 12.01 20.73 6.96\"/><line x1=\"12\" y1=\"22.08\" x2=\"12\" y2=\"12\"/><path d=\"m7.5 4.27 9 5.15\"/></svg>', 'update_nama_barang.php', 'Master Data', 34),
            ('copy_module_hrms', 'Copy Akses Menu', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M22 21v-2a4 4 0 0 0-3-3.87\"/><path d=\"M16 3.13a4 4 0 0 1 0 7.75\"/></svg>', 'copy_module_hrms.php', 'HRMS', 40),
            ('hapus_lembur', 'Hapus Lembur', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><polyline points=\"12 6 12 12 16 14\"/><line x1=\"4\" y1=\"4\" x2=\"20\" y2=\"20\"/></svg>', 'hapus_lembur.php', 'HRMS', 41),
            ('edit_jam_peminjaman', 'Edit Jam Peminjaman', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4\"/><path d=\"m21 2-9.6 9.6\"/><circle cx=\"7.5\" cy=\"15.5\" r=\"5.5\"/></svg>', 'edit_jam_peminjaman.php', 'HRMS', 42),
            ('cek_lembur', 'Cek Lembur', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><polyline points=\"12 6 12 12 16 14\"/></svg>', 'cek_lembur.php', 'HRMS', 43),
            ('cek_belum_regu', 'Belum Masuk Regu', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><line x1=\"17\" y1=\"11\" x2=\"22\" y2=\"11\"/></svg>', 'cek_belum_regu.php', 'HRMS', 44),
            ('cek_belum_terjadwal', 'Regu Belum Terjadwal', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\" ry=\"2\"/><line x1=\"16\" x2=\"16\" y1=\"2\" y2=\"6\"/><line x1=\"8\" x2=\"8\" y1=\"2\" y2=\"6\"/><line x1=\"3\" x2=\"21\" y1=\"10\" y2=\"10\"/><line x1=\"10\" y1=\"14\" x2=\"14\" y2=\"14\"/></svg>', 'cek_belum_terjadwal.php', 'HRMS', 45),
            ('cek_presensi', 'Cek Presensi', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\" ry=\"2\"/><line x1=\"16\" x2=\"16\" y1=\"2\" y2=\"6\"/><line x1=\"8\" x2=\"8\" y1=\"2\" y2=\"6\"/><line x1=\"3\" x2=\"21\" y1=\"10\" y2=\"10\"/><path d=\"M8 14h.01\"/><path d=\"M12 14h.01\"/><path d=\"M16 14h.01\"/><path d=\"M8 18h.01\"/><path d=\"M12 18h.01\"/><path d=\"M16 18h.01\"/></svg>', 'cek_presensi.php', 'HRMS', 46),
            ('bypass_presensi', 'Bypass Presensi', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"m11 17 2 2a1 1 0 1 0 3-3\"/><path d=\"m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-5.88 5.88a2 2 0 1 1-2.83-2.83l5.88-5.88a3 3 0 0 1 4.24 0l1.27 1.27\"/><path d=\"m9 9 2 2a1 1 0 1 0 3-3\"/></svg>', 'bypass_presensi.php', 'HRMS', 47),
            ('edit_presensi_nik', 'Edit Presensi', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\" ry=\"2\"/><line x1=\"16\" x2=\"16\" y1=\"2\" y2=\"6\"/><line x1=\"8\" x2=\"8\" y1=\"2\" y2=\"6\"/><line x1=\"3\" x2=\"21\" y1=\"10\" y2=\"10\"/><path d=\"m16 20 2 2 4-4\"/><path d=\"M8 14h.01\"/><path d=\"M12 14h.01\"/></svg>', 'edit_presensi_nik.php', 'HRMS', 48),
            ('tarikan_checkin', 'Tarikan Checkin', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4\"/><polyline points=\"7 10 12 15 17 10\"/><line x1=\"12\" x2=\"12\" y1=\"15\" y2=\"3\"/></svg>', 'tarikan_checkin.php', 'CRM', 50),
            ('tarikan_jadwal_kunjungan', 'Jadwal Kunjungan', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\" ry=\"2\"/><line x1=\"16\" x2=\"16\" y1=\"2\" y2=\"6\"/><line x1=\"8\" x2=\"8\" y1=\"2\" y2=\"6\"/><line x1=\"3\" x2=\"21\" y1=\"10\" y2=\"10\"/><path d=\"M8 14h.01\"/><path d=\"M12 14h.01\"/><path d=\"M16 14h.01\"/><path d=\"M8 18h.01\"/><path d=\"M12 18h.01\"/><path d=\"M16 18h.01\"/></svg>', 'tarikan_jadwal_kunjungan.php', 'CRM', 51),
            ('tarikan_data_outlet', 'Data Outlet', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M3 9 12 2 21 9v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z\"/><polyline points=\"9 22 9 12 15 12 15 22\"/></svg>', 'tarikan_data_outlet.php', 'CRM', 52),
            ('tarikan_data_distributor', 'Data Distributor', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2\"/><path d=\"M15 18H9\"/><path d=\"M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14\"/><circle cx=\"17\" cy=\"18\" r=\"2\"/><circle cx=\"7\" cy=\"18\" r=\"2\"/></svg>', 'tarikan_data_distributor.php', 'CRM', 53),
            ('crud_absensi', 'Edit Data Mesin Absen', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z\"/><polyline points=\"14 2 14 8 20 8\"/><line x1=\"16\" y1=\"13\" x2=\"8\" y2=\"13\"/><line x1=\"16\" y1=\"17\" x2=\"8\" y2=\"17\"/><line x1=\"10\" y1=\"9\" x2=\"8\" y2=\"9\"/></svg>', 'crud_absensi.php', 'ABSENSI', 60)
        ");
    }
} catch(PDOException $e) {
    error_log("Migration gagal: " . $e->getMessage());
}
?>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('bypass_presensi');

$success = null;
$error = null;
$tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$raw_nik = $_GET['nik'] ?? '';

$data_preview = [];

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS gagal. Error: " . htmlspecialchars($err);
}

function parseInput($raw) {
    $parts = preg_split('/[\s,]+/', $raw);
    $clean = [];
    foreach ($parts as $p) {
        $p = strtoupper(trim($p));
        if ($p !== '') $clean[] = $p;
    }
    return array_unique($clean);
}

// 1. Fetch Preview Data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $raw_nik !== '') {
    $list_nik = parseInput($raw_nik);
    if (!empty($list_nik) && isset($pdo_hrms)) {
        try {
            $placeholders = implode(',', array_fill(0, count($list_nik), '?'));
            $sql = "
                SELECT a.nik, k.nmlengkap, a.tgl, a.kdjamkerja, a.shiftke, 
                       to_char(b.jam_masuk, 'HH24:MI:ss') as target_jam_masuk, 
                       to_char(b.jam_pulang, 'HH24:MI:ss') as target_jam_pulang
                FROM sc_trx.transready a
                JOIN sc_mst.jam_kerja b ON a.kdjamkerja = b.kdjam_kerja
                LEFT JOIN sc_mst.karyawan k ON a.nik = k.nik
                WHERE to_char(a.tgl, 'YYYY-MM-DD') BETWEEN ? AND ?
                  AND a.jam_masuk_absen IS NULL
                  AND a.nik IN ($placeholders)
                ORDER BY a.tgl DESC, a.nik ASC
            ";
            
            $params = array_merge([$tgl_awal, $tgl_akhir], $list_nik);
            $stmt = $pdo_hrms->prepare($sql);
            $stmt->execute($params);
            $data_preview = $stmt->fetchAll();
            
            if (empty($data_preview)) {
                $error = "Tidak ada data presensi yang kosong (jam_masuk_absen IS NULL) untuk NIK dan rentang tanggal tersebut.";
            } else {
                $_SESSION['bypass_valid_niks'] = $list_nik;
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data preview: " . $e->getMessage();
        }
    }
}

// 2. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $valid_niks = $_SESSION['bypass_valid_niks'] ?? [];
    
    if (empty($valid_niks)) {
        $error = "Data sesi tidak valid atau kosong. Lakukan pencarian/preview ulang.";
    } elseif (isset($pdo_hrms)) {
        try {
            $placeholders = implode(',', array_fill(0, count($valid_niks), '?'));
            
            $sql = "
                UPDATE sc_trx.transready a
                SET jam_masuk_absen = b.jam_masuk,
                    jam_pulang_absen = b.jam_pulang,
                    editan = 't'
                FROM sc_mst.jam_kerja b
                WHERE a.kdjamkerja = b.kdjam_kerja 
                  AND to_char(a.tgl, 'YYYY-MM-DD') BETWEEN ? AND ?
                  AND a.jam_masuk_absen IS NULL 
                  AND a.nik IN ($placeholders)
            ";
            
            $params = array_merge([$tgl_awal, $tgl_akhir], $valid_niks);
            $stmt = $pdo_hrms->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            
            if ($affected > 0) {
                $success = "Proses bypass berhasil! $affected record presensi yang kosong telah disesuaikan dengan jam kerja master.";
                write_security_log('info', "Bypass Presensi HRMS. Tgl: $tgl_awal to $tgl_akhir. Affected: $affected. NIKs: " . implode(',', $valid_niks));
            } else {
                $error = "Tidak ada record presensi yang berhasil di-bypass. Pastikan ada presensi yang kosong (jam_masuk_absen IS NULL).";
            }
            
            // Refresh preview
            $sql_refresh = "
                SELECT a.nik, k.nmlengkap, a.tgl, a.kdjamkerja, a.shiftke, 
                       to_char(b.jam_masuk, 'HH24:MI:ss') as target_jam_masuk, 
                       to_char(b.jam_pulang, 'HH24:MI:ss') as target_jam_pulang
                FROM sc_trx.transready a
                JOIN sc_mst.jam_kerja b ON a.kdjamkerja = b.kdjam_kerja
                LEFT JOIN sc_mst.karyawan k ON a.nik = k.nik
                WHERE to_char(a.tgl, 'YYYY-MM-DD') BETWEEN ? AND ?
                  AND a.jam_masuk_absen IS NULL
                  AND a.nik IN ($placeholders)
                ORDER BY a.tgl DESC, a.nik ASC
            ";
            $stmt = $pdo_hrms->prepare($sql_refresh);
            $stmt->execute($params);
            $data_preview = $stmt->fetchAll();

        } catch (PDOException $e) {
            $error = "Gagal memproses bypass: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Bypass Presensi</h1>
            <p class="text-sm text-muted-foreground">Isi otomatis presensi yang kosong disesuaikan dengan jam kerja karyawan yang bersangkutan.</p>
        </div>
    </div>

    <?php if($success): ?>
    <div class="rounded-md border border-primary/30 bg-primary/10 px-4 py-3 text-sm text-primary flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?= e($success) ?>
    </div>
    <?php endif; ?>
    <?php if($error): ?>
    <div class="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <?= e($error) ?>
    </div>
    <?php endif; ?>

    <div class="grid gap-6 md:grid-cols-3 items-start">
        
        <!-- Form Pencarian -->
        <div class="rounded-lg border bg-card shadow-sm p-5 md:col-span-1">
            <h3 class="font-semibold mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Pencarian Target Bypass
            </h3>
            <form method="GET" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-sm font-medium">Dari Tanggal</label>
                        <input type="date" name="tgl_awal" value="<?= e($tgl_awal) ?>" required
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-medium">Sampai Tanggal</label>
                        <input type="date" name="tgl_akhir" value="<?= e($tgl_akhir) ?>" required
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium">NIK Karyawan</label>
                    <textarea name="nik" rows="6" required placeholder="Contoh:&#10;123456&#10;987654"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase resize-y"><?= e($raw_nik) ?></textarea>
                    <p class="text-xs text-muted-foreground">Bisa memasukkan banyak NIK sekaligus, pisahkan dengan koma atau enter.</p>
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Preview Data
                </button>
            </form>

            <div class="rounded-md bg-muted/50 border p-3 mt-4">
                <p class="text-xs text-muted-foreground leading-relaxed">
                    <b>Catatan:</b><br>
                    Hanya record yang memiliki <code>jam_masuk_absen IS NULL</code> yang akan terpengaruh.
                </p>
            </div>
        </div>

        <!-- Tabel Preview & Update -->
        <div class="rounded-lg border bg-card shadow-sm md:col-span-2 overflow-hidden flex flex-col h-full">
            <div class="p-5 border-b flex items-center justify-between bg-muted/20">
                <h3 class="font-semibold">Data Membutuhkan Bypass</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($data_preview) ?> baris kosong</span>
            </div>
            
            <div class="overflow-y-auto max-h-[400px]">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                        <tr>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12 text-center">#</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Karyawan</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Tanggal</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Kd Jam/Shift</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground border-l text-primary bg-primary/5">Target Jam Masuk</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground bg-primary/5">Target Jam Pulang</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($data_preview)): ?>
                        <tr><td colspan="6" class="h-24 text-center text-muted-foreground">Belum ada data kosong yang di-preview pada tanggal & NIK tersebut.</td></tr>
                        <?php endif; ?>
                        
                        <?php foreach($data_preview as $idx => $r): ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-2 text-center text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                            <td class="px-4 py-2">
                                <span class="font-mono text-xs text-muted-foreground"><?= e($r['nik']) ?></span><br>
                                <span class="font-medium truncate max-w-[150px] inline-block" title="<?= e($r['nmlengkap']) ?>"><?= e($r['nmlengkap']) ?></span>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs"><?= $r['tgl'] ? date('d/m/Y', strtotime($r['tgl'])) : '-' ?></td>
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium bg-background"><?= e($r['kdjamkerja']) ?></span>
                                <span class="text-xs text-muted-foreground ml-1">S<?= e($r['shiftke']) ?></span>
                            </td>
                            <td class="px-4 py-2 border-l font-mono text-xs font-bold text-primary bg-primary/5">
                                <?= e($r['target_jam_masuk']) ?>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs font-bold text-primary bg-primary/5">
                                <?= e($r['target_jam_pulang']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if(!empty($data_preview)): ?>
            <div class="p-5 border-t mt-auto bg-muted/20 flex items-center justify-between">
                <p class="text-xs text-muted-foreground hidden sm:block">Update jam_masuk & jam_pulang sesuai Target serta editan='t'</p>
                <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin mem-BYPASS <?= count($data_preview) ?> data presensi yang kosong tersebut? (Tgl <?= e($tgl_awal) ?> s/d <?= e($tgl_akhir) ?>)')">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-5.88 5.88a2 2 0 1 1-2.83-2.83l5.88-5.88a3 3 0 0 1 4.24 0l1.27 1.27"/><path d="m9 9 2 2a1 1 0 1 0 3-3"/></svg>
                        Eksekusi Bypass
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

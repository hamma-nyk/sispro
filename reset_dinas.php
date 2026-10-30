<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('reset_dinas');

$success = null;
$error = null;
$nodok_search = trim($_GET['nodok'] ?? '');
$nik_search = trim($_GET['nik'] ?? '');
$data_preview = [];

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS (NBIM) gagal. Error: " . htmlspecialchars($err);
}

// 1. Fetch Preview Data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($nodok_search !== '' || $nik_search !== '')) {
    if (isset($pdo_hrms)) {
        try {
            $sql = "SELECT d.nodok, d.nik, k.nmlengkap, d.tgl_dok, d.tgl_mulai, d.tgl_selesai, d.status, d.keperluan, d.tujuan_kota
                    FROM sc_trx.dinas d
                    LEFT JOIN sc_mst.karyawan k ON TRIM(k.nik) = TRIM(d.nik)
                    WHERE 1=1";
            $params = [];

            if ($nodok_search !== '') {
                $sql .= " AND TRIM(d.nodok) = ?";
                $params[] = $nodok_search;
            }
            if ($nik_search !== '') {
                $sql .= " AND TRIM(d.nik) = ?";
                $params[] = $nik_search;
            }

            $sql .= " ORDER BY d.tgl_dok DESC LIMIT 50";

            $stmt = $pdo_hrms->prepare($sql);
            $stmt->execute($params);
            $data_preview = $stmt->fetchAll();

            if (empty($data_preview)) {
                $error = "Data surat dinas tidak ditemukan dengan kriteria tersebut.";
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data: " . $e->getMessage();
        }
    }
}

// 2. Handle Update Status to 'A'
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $target_nodok = trim($_POST['target_nodok'] ?? '');
    $target_nik = trim($_POST['target_nik'] ?? '');

    if ($target_nodok === '' || $target_nik === '') {
        $error = "No. Dokumen dan NIK target tidak valid.";
    } elseif (isset($pdo_hrms)) {
        try {
            $stmt = $pdo_hrms->prepare("UPDATE sc_trx.dinas SET status = 'A' WHERE TRIM(nodok) = ? AND TRIM(nik) = ?");
            $stmt->execute([$target_nodok, $target_nik]);
            $affected = $stmt->rowCount();

            if ($affected > 0) {
                $success = "Status dokumen dinas $target_nodok (NIK: $target_nik) berhasil di-reset menjadi 'A' (Perlu Persetujuan).";
                write_security_log('info', "Reset Status Dinas ke 'A' (Perlu Persetujuan). Nodok: $target_nodok, NIK: $target_nik");
            } else {
                $error = "Tidak ada baris yang diperbarui. Pastikan No. Dokumen dan NIK sesuai.";
            }

            // Re-fetch preview list
            $sql = "SELECT d.nodok, d.nik, k.nmlengkap, d.tgl_dok, d.tgl_mulai, d.tgl_selesai, d.status, d.keperluan, d.tujuan_kota
                    FROM sc_trx.dinas d
                    LEFT JOIN sc_mst.karyawan k ON TRIM(k.nik) = TRIM(d.nik)
                    WHERE 1=1";
            $params = [];
            if ($nodok_search !== '') {
                $sql .= " AND TRIM(d.nodok) = ?";
                $params[] = $nodok_search;
            }
            if ($nik_search !== '') {
                $sql .= " AND TRIM(d.nik) = ?";
                $params[] = $nik_search;
            }
            if (empty($params)) {
                $sql .= " AND TRIM(d.nodok) = ? AND TRIM(d.nik) = ?";
                $params = [$target_nodok, $target_nik];
            }
            $sql .= " ORDER BY d.tgl_dok DESC LIMIT 50";

            $stmt = $pdo_hrms->prepare($sql);
            $stmt->execute($params);
            $data_preview = $stmt->fetchAll();

        } catch (PDOException $e) {
            $error = "Gagal memproses reset status dinas: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Reset Status Dinas</h1>
            <p class="text-sm text-muted-foreground">Reset status surat dinas karyawan kembali ke 'A' (Perlu Persetujuan) agar dapat diproses ulang.</p>
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
        <!-- Form Filter -->
        <div class="rounded-lg border bg-card shadow-sm p-5 md:col-span-1">
            <h3 class="font-semibold mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Pencarian Dokumen Dinas
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">No. Dokumen (nodok)</label>
                    <input type="text" name="nodok" placeholder="Contoh: DL241585"
                        value="<?= e($nodok_search) ?>"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium">NIK Karyawan</label>
                    <input type="text" name="nik" placeholder="Contoh: 52782510"
                        value="<?= e($nik_search) ?>"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <p class="text-xs text-muted-foreground">Bisa diisi salah satu atau kedua kolom di atas.</p>
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="inline-flex flex-1 items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                        Cari Data
                    </button>
                    <?php if($nodok_search !== '' || $nik_search !== ''): ?>
                    <a href="reset_dinas" class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm hover:bg-muted transition-colors h-9">
                        Reset
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Tabel Preview & Aksi -->
        <div class="rounded-lg border bg-card shadow-sm md:col-span-2 overflow-hidden flex flex-col h-full">
            <div class="p-5 border-b flex items-center justify-between bg-muted/20">
                <h3 class="font-semibold">Hasil Pencarian</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($data_preview) ?> Dokumen</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 border-b">
                        <tr>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">No. Dokumen</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Karyawan</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Periode Dinas</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground">Status</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground w-24">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($data_preview)): ?>
                        <tr>
                            <td colspan="5" class="h-28 text-center text-muted-foreground">
                                Silakan masukkan No. Dokumen atau NIK pada form di sebelah kiri untuk mencari data.
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach($data_preview as $r): 
                            $c_nodok = trim($r['nodok'] ?? '');
                            $c_nik = trim($r['nik'] ?? '');
                            $c_nama = trim($r['nmlengkap'] ?? '-');
                            $c_status = trim($r['status'] ?? '');
                            $c_mulai = !empty($r['tgl_mulai']) ? date('d/m/Y', strtotime($r['tgl_mulai'])) : '-';
                            $c_selesai = !empty($r['tgl_selesai']) ? date('d/m/Y', strtotime($r['tgl_selesai'])) : '-';
                        ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3 font-mono font-medium">
                                <?= e($c_nodok) ?>
                                <?php if(!empty($r['tgl_dok'])): ?>
                                    <div class="text-xs text-muted-foreground font-sans mt-0.5">Tgl Dok: <?= e(date('d/m/Y', strtotime($r['tgl_dok']))) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium"><?= e($c_nama) ?></div>
                                <div class="text-xs text-muted-foreground font-mono"><?= e($c_nik) ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <div><?= e($c_mulai) ?> s/d <?= e($c_selesai) ?></div>
                                <?php if(!empty($r['keperluan'])): ?>
                                    <div class="text-xs text-muted-foreground line-clamp-1 max-w-xs mt-0.5"><?= e($r['keperluan']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if($c_status === 'P'): ?>
                                    <span class="inline-flex items-center text-xs font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded">
                                        P (Approved)
                                    </span>
                                <?php elseif($c_status === 'A'): ?>
                                    <span class="inline-flex items-center text-xs font-bold text-amber-600 dark:text-amber-400 bg-amber-500/10 border border-amber-500/20 px-2 py-0.5 rounded">
                                        A (Perlu Persetujuan)
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center text-xs font-bold bg-muted px-2 py-0.5 rounded">
                                        <?= e($c_status !== '' ? $c_status : 'NULL') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if($c_status !== 'A'): ?>
                                <form method="POST" onsubmit="return confirm('Yakin ingin mereset status dokumen dinas <?= e($c_nodok) ?> menjadi \'A\' (Perlu Persetujuan)?')">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                    <input type="hidden" name="target_nodok" value="<?= e($c_nodok) ?>">
                                    <input type="hidden" name="target_nik" value="<?= e($c_nik) ?>">
                                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium px-2.5 py-1.5 shadow-sm transition-colors gap-1">
                                        Reset ke 'A'
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="text-xs text-muted-foreground font-medium">Sudah 'A'</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

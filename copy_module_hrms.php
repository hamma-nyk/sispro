<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('copy_module_hrms');

$success = null;
$error = null;
$raw_targets = $_GET['targets'] ?? '';
$src_nik = trim($_GET['src_nik'] ?? '');
$data_preview = [];

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS (NBIM) gagal. Error: " . htmlspecialchars($err);
}

function parseInput($raw) {
    $parts = preg_split('/[\s,]+/', $raw);
    $clean = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $clean[] = $p;
    }
    return array_unique($clean);
}

// 1. Fetch Preview Data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $src_nik !== '' && $raw_targets !== '') {
    $list_targets = parseInput($raw_targets);
    if (!empty($list_targets) && isset($pdo_hrms)) {
        try {
            // Verifikasi NIK Sumber
            $stmtSrc = $pdo_hrms->prepare("SELECT TRIM(nik) as nik, username FROM sc_mst.user WHERE TRIM(nik) = ?");
            $stmtSrc->execute([$src_nik]);
            $userSrc = $stmtSrc->fetch();

            if (!$userSrc) {
                $error = "User dengan NIK sumber '$src_nik' tidak ditemukan di HRMS.";
            } else {
                // Verifikasi NIK Tujuan & Ambil Info Menu
                $placeholders = implode(',', array_fill(0, count($list_targets), '?'));
                $stmtTgt = $pdo_hrms->prepare("SELECT TRIM(nik) as nik, username FROM sc_mst.user WHERE TRIM(nik) IN ($placeholders)");
                $stmtTgt->execute($list_targets);
                $usersTgt = $stmtTgt->fetchAll();
                
                $found_niks = array_column($usersTgt, 'nik');
                $missing_niks = array_diff($list_targets, $found_niks);
                
                if (empty($usersTgt)) {
                    $error = "Tidak ada satupun NIK tujuan yang ditemukan di database HRMS.";
                } else {
                    // Ambil list menu source
                    $stmtSrcMenu = $pdo_hrms->prepare("SELECT kodemenu FROM sc_mst.akses WHERE TRIM(nik) = ?");
                    $stmtSrcMenu->execute([$src_nik]);
                    $srcMenus = $stmtSrcMenu->fetchAll(PDO::FETCH_COLUMN);
                    $srcMenuCount = count($srcMenus);

                    // Ambil list menu per target
                    $stmtTgtMenu = $pdo_hrms->prepare("SELECT TRIM(nik) as nik, kodemenu FROM sc_mst.akses WHERE TRIM(nik) IN ($placeholders)");
                    $stmtTgtMenu->execute($found_niks);
                    $tgtMenusRaw = $stmtTgtMenu->fetchAll();

                    $tgtMenusByNik = [];
                    foreach ($tgtMenusRaw as $tm) {
                        $tgtMenusByNik[$tm['nik']][] = $tm['kodemenu'];
                    }

                    // Gabungkan ke usersTgt
                    foreach ($usersTgt as &$tgt) {
                        $myMenus = $tgtMenusByNik[$tgt['nik']] ?? [];
                        $overlap = array_intersect($srcMenus, $myMenus);
                        $tgt['total_menu'] = count($myMenus);
                        $tgt['overlap'] = count($overlap);
                        $tgt['will_add'] = $srcMenuCount - count($overlap);
                    }

                    if (!empty($missing_niks)) {
                        $error = "PERHATIAN: Beberapa NIK tidak ditemukan dan akan diabaikan: " . implode(', ', $missing_niks);
                    }

                    $data_preview = [
                        'source' => $userSrc,
                        'source_menu_count' => $srcMenuCount,
                        'targets' => $usersTgt
                    ];
                    // Simpan NIK yang BENAR-BENAR ada ke session
                    $_SESSION['hrms_copy_source'] = trim($src_nik);
                    $_SESSION['hrms_copy_targets'] = $found_niks;
                }
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data preview: " . $e->getMessage();
        }
    }
}

// 2. Handle Action Copy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $src = $_SESSION['hrms_copy_source'] ?? '';
    $targets = $_SESSION['hrms_copy_targets'] ?? [];

    if ($src === '' || empty($targets)) {
        $error = "Data sesi tidak valid. Silakan cari/preview ulang.";
    } elseif (isset($pdo_hrms)) {
        try {
            // Membangun query menggunakan fitur CTE postgresql:
            // WITH target_nik(nik) AS ( VALUES ('A'), ('B') )
            
            $values = [];
            foreach ($targets as $nik) {
                // escape NIK manual karena akan dibuild di query CTE values
                $safe_nik = $pdo_hrms->quote($nik);
                $values[] = "($safe_nik)";
            }
            $values_str = implode(',', $values);
            
            $sql = "
            WITH target_nik(nik) AS (
                VALUES $values_str
            )
            INSERT INTO sc_mst.akses (
                nik, kodemenu, hold_id, aksesview, aksesinput, 
                aksesupdate, aksesdelete, aksesapprove, aksesconvert, 
                aksesprint, aksesdownload, aksesapprove2, aksesapprove3, 
                aksesfilter, username
            )
            SELECT
                t.nik, a.kodemenu, a.hold_id, a.aksesview, a.aksesinput, 
                a.aksesupdate, a.aksesdelete, a.aksesapprove, a.aksesconvert, 
                a.aksesprint, a.aksesdownload, a.aksesapprove2, a.aksesapprove3, 
                a.aksesfilter, u.username
            FROM sc_mst.akses a
            CROSS JOIN target_nik t
            JOIN sc_mst.user u ON TRIM(u.nik) = t.nik
            WHERE TRIM(a.nik) = ?
            AND NOT EXISTS (
                SELECT 1
                FROM sc_mst.akses b
                WHERE TRIM(b.nik) = t.nik
                  AND b.kodemenu = a.kodemenu
            )";

            $stmt = $pdo_hrms->prepare($sql);
            $stmt->execute([$src]);
            $affected = $stmt->rowCount();

            $success = "Copy akses menu berhasil. $affected record akses baru ditambahkan untuk " . count($targets) . " NIK tujuan.";
            write_security_log('info', "HRMS Copy Module: $src to " . count($targets) . " targets. Rows: $affected");

            unset($_SESSION['hrms_copy_source']);
            unset($_SESSION['hrms_copy_targets']);
            $data_preview = [];
            $src_nik = '';
            $raw_targets = '';

        } catch (PDOException $e) {
            $error = "Gagal memproses copy modul: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Copy Akses Menu (HRMS)</h1>
            <p class="text-sm text-muted-foreground">Menduplikasi data akses menu dari satu NIK Sumber ke banyak NIK Tujuan.</p>
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
                Pilih User Target
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">NIK Sumber (Asal)</label>
                    <input type="text" name="src_nik" value="<?= e($src_nik) ?>" required placeholder="Contoh: 01840412"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono">
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium">NIK Tujuan (Penerima)</label>
                    <textarea name="targets" rows="8" required placeholder="Contoh:&#10;00190401&#10;02100502&#10;02230504"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-mono resize-y"><?= e($raw_targets) ?></textarea>
                    <p class="text-xs text-muted-foreground">Masukkan banyak NIK sekaligus (pisahkan dengan koma atau enter).</p>
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Cek Validitas NIK
                </button>
            </form>
        </div>

        <!-- Tabel Preview & Update -->
        <div class="rounded-lg border bg-card shadow-sm md:col-span-2 overflow-hidden flex flex-col h-full">
            <div class="p-5 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-muted/20">
                <h3 class="font-semibold">Review Target Duplikasi</h3>
            </div>
            
            <div class="p-5 flex-1 space-y-6">
                <?php if(empty($data_preview)): ?>
                <div class="h-32 flex items-center justify-center text-center text-muted-foreground">
                    <p>Belum ada data di-preview. Silakan cek validitas NIK di sebelah kiri.</p>
                </div>
                <?php else: ?>
                    <!-- Source Info -->
                    <div class="space-y-2">
                        <h4 class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Sumber Hak Akses</h4>
                        <div class="rounded-md border p-3 bg-muted/30 flex items-center gap-3">
                            <div class="h-10 w-10 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            <div class="flex-1">
                                <p class="font-bold"><?= e($data_preview['source']['username']) ?></p>
                                <p class="text-sm font-mono text-muted-foreground">NIK: <?= e($data_preview['source']['nik']) ?></p>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex items-center rounded-full border border-primary/20 bg-primary/10 text-primary px-2.5 py-0.5 text-xs font-semibold">
                                    <?= $data_preview['source_menu_count'] ?> menu
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-center text-muted-foreground">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>
                    </div>

                    <!-- Targets Info -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Penerima Hak Akses</h4>
                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($data_preview['targets']) ?> user</span>
                        </div>
                        <div class="grid gap-3 grid-cols-1 md:grid-cols-2 max-h-80 overflow-y-auto pr-1">
                            <?php foreach($data_preview['targets'] as $tgt): ?>
                            <div class="rounded-md border p-3 flex flex-col gap-2">
                                <div class="flex justify-between items-start">
                                    <div class="min-w-0">
                                        <p class="font-medium text-sm truncate"><?= e($tgt['username']) ?></p>
                                        <p class="text-xs font-mono text-muted-foreground"><?= e($tgt['nik']) ?></p>
                                    </div>
                                    <span class="inline-flex shrink-0 items-center rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium" title="Menu saat ini yang dimiliki">
                                        <?= $tgt['total_menu'] ?> menu
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 text-xs">
                                    <?php if($tgt['overlap'] > 0): ?>
                                    <span class="inline-flex items-center gap-1 text-muted-foreground bg-secondary px-1.5 py-0.5 rounded border">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/></svg>
                                        <?= $tgt['overlap'] ?> overlap
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if($tgt['will_add'] > 0): ?>
                                    <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                                        <?= $tgt['will_add'] ?> baru ditambahkan
                                    </span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center gap-1 text-muted-foreground italic">
                                        Sudah punya semua
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if(!empty($data_preview)): ?>
            <div class="p-5 border-t bg-muted/20">
                <form method="POST" class="flex justify-end" onsubmit="return confirm('Apakah Anda yakin menduplikasi SEMUA modul akses dari NIK <?= e($src_nik) ?> ke <?= count($data_preview['targets']) ?> user tujuan? Modul yang sudah ada di tujuan tidak akan ter-override.')">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        Eksekusi Copy
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

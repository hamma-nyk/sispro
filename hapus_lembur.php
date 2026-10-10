<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('hapus_lembur');

$success = null;
$error = null;
$raw_input = $_GET['nomor'] ?? '';
$data_preview = [];

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS (NBIM) gagal. Error: " . htmlspecialchars($err);
}

function parseInput($raw) {
    $parts = preg_split('/[\s,]+/', $raw);
    $clean = [];
    foreach ($parts as $p) {
        $p = strtoupper(trim($p));
        if ($p !== '') $clean[] = $p;
    }
    return array_values(array_unique($clean));
}

// 1. Fetch Preview Data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $raw_input !== '') {
    $list_nomor = parseInput($raw_input);
    if (!empty($list_nomor) && isset($pdo_hrms)) {
        try {
            $placeholders = implode(',', array_fill(0, count($list_nomor), '?'));
            $stmt = $pdo_hrms->prepare("SELECT nodok, status FROM sc_trx.lembur WHERE TRIM(nodok) IN ($placeholders)");
            $stmt->execute($list_nomor);
            $data_preview = $stmt->fetchAll();
            
            if (empty($data_preview)) {
                $error = "Tidak ada satupun nomor dokumen lembur yang ditemukan di database.";
            } else {
                $_SESSION['lembur_valid_ids'] = array_map(function($r) { return trim($r['nodok']); }, $data_preview);
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data preview: " . $e->getMessage();
        }
    }
}

// 2. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $valid_ids = $_SESSION['lembur_valid_ids'] ?? [];
    
    if (empty($valid_ids)) {
        $error = "Data sesi tidak valid atau kosong. Lakukan pencarian/preview ulang.";
    } elseif (isset($pdo_hrms)) {
        try {
            $placeholders = implode(',', array_fill(0, count($valid_ids), '?'));
            $sql = "UPDATE sc_trx.lembur SET status = 'C' WHERE TRIM(nodok) IN ($placeholders) AND status = 'P'";
            
            $stmt = $pdo_hrms->prepare($sql);
            $stmt->execute($valid_ids);
            $affected = $stmt->rowCount();
            
            if ($affected > 0) {
                $success = "Proses hapus lembur berhasil! $affected dokumen lembur (status P) telah diubah menjadi C (Cancelled).";
                write_security_log('info', "Hapus Lembur HRMS (P -> C). Affected: $affected. IDs: " . implode(',', $valid_ids));
            } else {
                $error = "Tidak ada dokumen lembur yang berubah. Pastikan status dokumen saat ini adalah 'P'.";
            }
            
            // Refresh preview
            $stmt = $pdo_hrms->prepare("SELECT nodok, status FROM sc_trx.lembur WHERE TRIM(nodok) IN ($placeholders)");
            $stmt->execute($valid_ids);
            $data_preview = $stmt->fetchAll();

        } catch (PDOException $e) {
            $error = "Gagal memproses update: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Hapus Lembur</h1>
            <p class="text-sm text-muted-foreground">Batalkan dokumen lembur (HRMS) dengan mengubah status dari P menjadi C (Cancelled).</p>
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
                Pencarian Lembur
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">Nomor Dokumen Lembur</label>
                    <textarea name="nomor" rows="5" required placeholder="Contoh:&#10;LBR2468046&#10;LBR2461027"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase resize-y"><?= e($raw_input) ?></textarea>
                    <p class="text-xs text-muted-foreground">Bisa memasukkan banyak nomor sekaligus, pisahkan dengan koma atau enter.</p>
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Cek Status
                </button>
            </form>

            <div class="rounded-md bg-muted/50 border p-3 mt-4">
                <p class="text-xs text-muted-foreground leading-relaxed">
                    <b>Catatan:</b><br>
                    Hanya dokumen lembur berstatus <code class="bg-card border px-1 rounded">P</code> (Posted) yang dapat dibatalkan menjadi <code class="bg-card border px-1 text-destructive rounded">C</code> (Cancelled).
                </p>
            </div>
        </div>

        <!-- Tabel Preview & Update -->
        <div class="rounded-lg border bg-card shadow-sm md:col-span-2 overflow-hidden flex flex-col h-full">
            <div class="p-5 border-b flex items-center justify-between bg-muted/20">
                <h3 class="font-semibold">Data Ditemukan</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($data_preview) ?> dokumen</span>
            </div>
            
            <div class="overflow-y-auto max-h-[400px]">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                        <tr>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12 text-center">#</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">No. Dokumen (nodok)</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($data_preview)): ?>
                        <tr><td colspan="3" class="h-24 text-center text-muted-foreground">Belum ada data di-preview. Silakan lakukan pencarian di sebelah kiri.</td></tr>
                        <?php endif; ?>
                        
                        <?php 
                        $can_update = false;
                        foreach($data_preview as $idx => $r): 
                            $stat = trim($r['status'] ?? '');
                            if ($stat === 'P') $can_update = true;
                        ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-2 text-center text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                            <td class="px-4 py-2 font-mono font-medium"><?= e(trim($r['nodok'] ?? '')) ?></td>
                            <td class="px-4 py-2 text-center">
                                <?php if($stat === 'P'): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-bold text-amber-600 dark:text-amber-400 bg-amber-500/10 border border-amber-500/20 px-2 py-0.5 rounded">
                                        P (Posted)
                                    </span>
                                <?php elseif($stat === 'C'): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-bold text-destructive bg-destructive/10 border border-destructive/20 px-2 py-0.5 rounded">
                                        C (Cancelled)
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-bold bg-secondary px-2 py-0.5 rounded">
                                        <?= $stat !== '' ? e($stat) : 'NULL' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if(!empty($data_preview) && $can_update): ?>
            <div class="p-5 border-t mt-auto bg-muted/20">
                <form method="POST" class="flex justify-end" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan dokumen lembur yang berstatus P? (Mengubah status menjadi C)')">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-destructive px-5 py-2 text-sm font-medium text-destructive-foreground shadow hover:bg-destructive/90 transition-colors h-9 gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/><line x1="4" y1="4" x2="20" y2="20"/></svg>
                        Batalkan Lembur (Set Status C)
                    </button>
                </form>
            </div>
            <?php elseif(!empty($data_preview)): ?>
            <div class="p-5 border-t mt-auto bg-muted/20 text-center text-sm text-muted-foreground">
                Tidak ada dokumen lembur dengan status 'P' yang dapat dibatalkan.
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

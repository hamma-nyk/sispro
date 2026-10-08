<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('copy_module');

$success = null;
$error = null;
$previewAsal = [];
$previewTujuan = [];
$src_userid = '';
$dst_userid = '';

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// Handler Preview
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['preview'])) {
    $src_userid = trim($_GET['src_userid'] ?? '');
    $dst_userid = trim($_GET['dst_userid'] ?? '');

    if ($src_userid !== '' && isset($pdo_ms)) {
        try {
            $stmt = $pdo_ms->prepare("SELECT * FROM d_master..t_usermdl WHERE fc_userid LIKE ?");
            $stmt->execute([$src_userid]);
            $previewAsal = $stmt->fetchAll();
        } catch (PDOException $e) {
            $error = "Gagal mengambil data user asal: " . $e->getMessage();
        }
    }

    if ($dst_userid !== '' && isset($pdo_ms)) {
        try {
            $stmt = $pdo_ms->prepare("SELECT * FROM d_master..t_usermdl WHERE fc_userid LIKE ?");
            $stmt->execute([$dst_userid]);
            $previewTujuan = $stmt->fetchAll();
        } catch (PDOException $e) {
            $error = "Gagal mengambil data user tujuan: " . $e->getMessage();
        }
    }
}

// Handler Proses Copy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $src_userid = trim($_POST['src_userid'] ?? '');
    $dst_userid = trim($_POST['dst_userid'] ?? '');

    if ($src_userid === '' || $dst_userid === '') {
        $error = "User Asal dan User Tujuan wajib diisi.";
    } elseif ($src_userid === $dst_userid) {
        $error = "User Asal dan Tujuan tidak boleh sama.";
    } elseif (isset($pdo_ms)) {
        try {
            // Cek apakah user asal punya modul
            $check = $pdo_ms->prepare("SELECT COUNT(*) FROM d_master..t_usermdl WHERE fc_userid LIKE ?");
            $check->execute([$src_userid]);
            $countAsal = $check->fetchColumn();

            if ($countAsal == 0) {
                $error = "User asal ($src_userid) tidak memiliki modul.";
            } else {
                // Eksekusi query copy yang diminta
                $sql = "
                INSERT INTO d_master..t_usermdl (FC_BRANCH, FC_USERID, FC_MDLPRG, FC_MODUL)
                SELECT src.FC_BRANCH, ?, src.FC_MDLPRG, src.FC_MODUL
                FROM d_master..t_usermdl AS src
                WHERE src.FC_USERID LIKE ?
                AND NOT EXISTS (
                    SELECT 1 FROM d_master..t_usermdl AS dst
                    WHERE dst.FC_BRANCH LIKE CAST(src.FC_BRANCH AS VARCHAR(8000))
                    AND dst.FC_USERID LIKE ?
                    AND dst.FC_MDLPRG LIKE CAST(src.FC_MDLPRG AS VARCHAR(8000))
                    AND dst.FC_MODUL LIKE CAST(src.FC_MODUL AS VARCHAR(8000))
                )
                ";
                $stmt = $pdo_ms->prepare($sql);
                // Bind parameter: dst, src, dst
                $stmt->execute([$dst_userid, $src_userid, $dst_userid]);
                $affected = $stmt->rowCount();
                
                $success = "Proses copy berhasil. $affected modul baru ditambahkan ke user $dst_userid.";
                
                // Refresh preview tujuan setelah dicopy
                $stmt = $pdo_ms->prepare("SELECT * FROM d_master..t_usermdl WHERE fc_userid LIKE ?");
                $stmt->execute([$dst_userid]);
                $previewTujuan = $stmt->fetchAll();
            }
        } catch (PDOException $e) {
            $error = "Terjadi kesalahan saat memproses copy: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Copy Module User</h1>
            <p class="text-sm text-muted-foreground">Duplikasi hak akses modul dari satu user ke user lain.</p>
        </div>
    </div>

    <?php if($success): ?>
    <div class="rounded-md border border-primary/30 bg-primary/10 px-4 py-3 text-sm text-primary flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?= e($success) ?>
    </div>
    <?php endif; ?>
    <?php if($error): ?>
    <div class="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <?= e($error) ?>
    </div>
    <?php endif; ?>

    <div class="rounded-lg border bg-card shadow-sm p-6">
        <form method="GET" class="grid gap-4 md:grid-cols-2 lg:grid-cols-5 items-end">
            <input type="hidden" name="preview" value="1">
            <div class="space-y-2 lg:col-span-2">
                <label class="text-sm font-medium">User Asal (Sumber)</label>
                <input type="text" name="src_userid" value="<?= e($src_userid) ?>" required placeholder="Contoh: RULLY"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
            </div>
            <div class="space-y-2 lg:col-span-2">
                <label class="text-sm font-medium">User Tujuan</label>
                <input type="text" name="dst_userid" value="<?= e($dst_userid) ?>" required placeholder="Contoh: MEME"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
            </div>
            <div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium shadow-sm hover:bg-accent hover:text-accent-foreground h-9 transition-colors gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    Preview
                </button>
            </div>
        </form>

        <?php if (isset($_GET['preview']) && !$error && isset($pdo_ms)): ?>
        <hr class="my-6">
        
        <div class="grid gap-6 md:grid-cols-2">
            <!-- Table Asal -->
            <div class="space-y-3">
                <h3 class="text-sm font-semibold flex items-center justify-between">
                    <span>Modul User Asal <code class="bg-muted px-1.5 py-0.5 rounded ml-1 font-mono text-xs"><?= e(strtoupper($src_userid)) ?></code></span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 border-transparent bg-secondary text-secondary-foreground"><?= count($previewAsal) ?> Modul</span>
                </h3>
                <div class="rounded-md border bg-muted/20 h-64 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-muted/80 backdrop-blur">
                            <tr class="border-b">
                                <th class="py-2 px-3 text-left font-medium text-muted-foreground">BRANCH</th>
                                <th class="py-2 px-3 text-left font-medium text-muted-foreground">MDLPRG</th>
                                <th class="py-2 px-3 text-left font-medium text-muted-foreground">MODUL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($previewAsal)): ?>
                            <tr><td colspan="3" class="py-6 text-center text-muted-foreground italic">Tidak ada modul / User tidak ditemukan</td></tr>
                            <?php else: ?>
                                <?php foreach($previewAsal as $a): ?>
                                <tr class="border-b last:border-0 hover:bg-muted/50">
                                    <td class="py-1.5 px-3 font-mono"><?= e($a['FC_BRANCH'] ?? $a['fc_branch'] ?? '') ?></td>
                                    <td class="py-1.5 px-3 font-mono"><?= e($a['FC_MDLPRG'] ?? $a['fc_mdlprg'] ?? '') ?></td>
                                    <td class="py-1.5 px-3 font-mono"><?= e($a['FC_MODUL'] ?? $a['fc_modul'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Table Tujuan -->
            <div class="space-y-3">
                <h3 class="text-sm font-semibold flex items-center justify-between">
                    <span>Modul User Tujuan <code class="bg-muted px-1.5 py-0.5 rounded ml-1 font-mono text-xs"><?= e(strtoupper($dst_userid)) ?></code></span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 border-transparent bg-secondary text-secondary-foreground"><?= count($previewTujuan) ?> Modul</span>
                </h3>
                <div class="rounded-md border bg-muted/20 h-64 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-muted/80 backdrop-blur">
                            <tr class="border-b">
                                <th class="py-2 px-3 text-left font-medium text-muted-foreground">BRANCH</th>
                                <th class="py-2 px-3 text-left font-medium text-muted-foreground">MDLPRG</th>
                                <th class="py-2 px-3 text-left font-medium text-muted-foreground">MODUL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($previewTujuan)): ?>
                            <tr><td colspan="3" class="py-6 text-center text-muted-foreground italic">Belum ada modul / User baru</td></tr>
                            <?php else: ?>
                                <?php foreach($previewTujuan as $t): ?>
                                <tr class="border-b last:border-0 hover:bg-muted/50">
                                    <td class="py-1.5 px-3 font-mono"><?= e($t['FC_BRANCH'] ?? $t['fc_branch'] ?? '') ?></td>
                                    <td class="py-1.5 px-3 font-mono"><?= e($t['FC_MDLPRG'] ?? $t['fc_mdlprg'] ?? '') ?></td>
                                    <td class="py-1.5 px-3 font-mono"><?= e($t['FC_MODUL'] ?? $t['fc_modul'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menyalin modul dari <?= e(strtoupper($src_userid)) ?> ke <?= e(strtoupper($dst_userid)) ?>? Modul yang sudah ada di user tujuan tidak akan digandakan.')">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="src_userid" value="<?= e($src_userid) ?>">
                <input type="hidden" name="dst_userid" value="<?= e($dst_userid) ?>">
                <button type="submit" <?= empty($previewAsal) ? 'disabled' : '' ?> class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                    Jalankan Copy Module
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

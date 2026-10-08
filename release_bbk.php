<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('release_bbk');

$success = null;
$error = null;
$bbk_data = [];

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// Handle Delete All
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo_ms)) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    try {
        $pdo_ms->query("DELETE FROM d_temporary..t_bbkmst");
        $success = "Semua data BBK temporary berhasil di-release.";
        write_security_log('warning', "Release ALL BBK dari d_temporary..t_bbkmst");
    } catch (PDOException $e) {
        $error = "Gagal memproses release: " . $e->getMessage();
    }
}

// Fetch data
if (isset($pdo_ms)) {
    try {
        $stmt = $pdo_ms->query("SELECT * FROM d_temporary..t_bbkmst");
        $bbk_data = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Gagal mengambil data BBK: " . $e->getMessage();
    }
}

$columns = [];
if (!empty($bbk_data)) {
    $columns = array_keys($bbk_data[0]);
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Release BBK</h1>
            <p class="text-sm text-muted-foreground">Hapus semua data lock BBK di tabel temporary agar bisa diproses ulang.</p>
        </div>
        <?php if(!empty($bbk_data)): ?>
        <form method="POST" onsubmit="return confirm('RELEASE SEMUA DATA BBK? Semua lock dokumen BBK akan dihapus dari temporary.')">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-destructive text-destructive-foreground px-4 py-2 text-sm font-medium shadow hover:bg-destructive/90 transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                Release Semua
            </button>
        </form>
        <?php endif; ?>
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

    <div class="rounded-lg border bg-card shadow-sm overflow-hidden">
        <div class="p-5 border-b bg-muted/20 flex items-center justify-between">
            <h3 class="font-semibold">Data BBK Temporary</h3>
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($bbk_data) ?> data terkunci</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b bg-muted/50">
                        <th class="h-10 px-4 text-left font-medium text-muted-foreground w-12 text-center">#</th>
                        <?php foreach($columns as $col): ?>
                        <th class="h-10 px-4 text-left font-medium text-muted-foreground text-xs uppercase"><?= e($col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($bbk_data)): ?>
                    <tr><td colspan="<?= max(count($columns) + 1, 2) ?>" class="h-24 text-center text-muted-foreground">Tidak ada data BBK yang terkunci.</td></tr>
                    <?php endif; ?>
                    <?php foreach($bbk_data as $idx => $r): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 text-center text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                        <?php foreach($columns as $col): ?>
                        <td class="px-4 py-3 text-xs font-mono text-muted-foreground"><?= e(trim($r[$col] ?? '')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

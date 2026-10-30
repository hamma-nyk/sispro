<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('session_management');

$success = null;
$error = null;

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// Handler Aksi (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo_ms)) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'kick_all') {
            $stmt = $pdo_ms->query("DELETE FROM d_temporary..t_user");
            $affected = $stmt->rowCount();
            $success = "Semua user aktif ($affected user) telah di-kick dari sistem.";
            write_security_log('warning', "KICK ALL USER di d_temporary..t_user");
        } 
        elseif ($action === 'kick_except') {
            $except_user = strtoupper(trim($_POST['except_user'] ?? ''));
            if ($except_user === '') {
                $error = "User ID pengecualian wajib diisi.";
            } else {
                $stmt = $pdo_ms->prepare("DELETE FROM d_temporary..t_user WHERE fc_userid <> ?");
                $stmt->execute([$except_user]);
                $affected = $stmt->rowCount();
                $success = "User aktif selain '$except_user' ($affected user) telah di-kick dari sistem.";
                write_security_log('warning', "KICK USER KECUALI $except_user di d_temporary..t_user");
            }
        } 
        elseif ($action === 'kick_specific') {
            $userid = strtoupper(trim($_POST['userid'] ?? ''));
            if ($userid !== '') {
                $stmt = $pdo_ms->prepare("DELETE FROM d_temporary..t_user WHERE fc_userid = ?");
                $stmt->execute([$userid]);
                $success = "User '$userid' berhasil di-kick dari sistem.";
                write_security_log('info', "KICK SPECIFIC USER $userid di d_temporary..t_user");
            }
        } 
        elseif ($action === 'system_unlock') {
            $pdo_ms->query("UPDATE d_master..t_setup SET FV_VALUE='NO' WHERE fv_parameter LIKE '%SYSTEMHOL%'");
            $success = "Sistem berhasil di-Unlock (SYSTEMHOL = NO). User sekarang bisa login kembali.";
            write_security_log('warning', "SYSTEM UNLOCK (SYSTEMHOL = NO) di d_master..t_setup");
        } 
        elseif ($action === 'system_lock') {
            $pdo_ms->query("UPDATE d_master..t_setup SET FV_VALUE='YES' WHERE fv_parameter LIKE '%SYSTEMHOL%'");
            $success = "Sistem berhasil di-Lock (SYSTEMHOL = YES). User baru tidak bisa login.";
            write_security_log('warning', "SYSTEM LOCK (SYSTEMHOL = YES) di d_master..t_setup");
        }
    } catch (PDOException $e) {
        $error = "Terjadi kesalahan saat mengeksekusi aksi: " . $e->getMessage();
    }
}

// Tarik data System Hold
$sys_hold_status = 'UNKNOWN';
if (isset($pdo_ms)) {
    try {
        $stmt = $pdo_ms->query("SELECT FV_VALUE FROM d_master..t_setup WHERE fv_parameter LIKE '%SYSTEMHOL%'");
        $sys_hold = $stmt->fetch();
        if ($sys_hold) {
            $sys_hold_status = trim($sys_hold['FV_VALUE'] ?? '');
        }
    } catch (PDOException $e) {}
}

// Tarik data User Aktif di Temporary
$active_users = [];
$total_users = 0;
$search = trim($_GET['q'] ?? '');

// Pagination config
$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

if (isset($pdo_ms)) {
    try {
        if ($search !== '') {
            $stmtC = $pdo_ms->prepare("SELECT COUNT(*) FROM d_temporary..t_user WHERE fc_userid LIKE ?");
            $stmtC->execute(["%$search%"]);
            $total_users = $stmtC->fetchColumn();

            // SQL Server offset pagination syntax
            $stmt = $pdo_ms->prepare("
                SELECT fc_userid, fc_branch 
                FROM d_temporary..t_user 
                WHERE fc_userid LIKE ? 
                ORDER BY fc_userid 
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            ");
            $stmt->execute(["%$search%", $offset, $limit]);
        } else {
            $total_users = $pdo_ms->query("SELECT COUNT(*) FROM d_temporary..t_user")->fetchColumn();
            
            $stmt = $pdo_ms->prepare("
                SELECT fc_userid, fc_branch 
                FROM d_temporary..t_user 
                ORDER BY fc_userid 
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            ");
            $stmt->execute([$offset, $limit]);
        }
        $active_users = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Jika syntax OFFSET FETCH tidak disupport (SQL Server 2000/2008)
        // Lakukan fallback pagination di level PHP array
        if ($search !== '') {
            $stmt = $pdo_ms->prepare("SELECT fc_userid, fc_branch FROM d_temporary..t_user WHERE fc_userid LIKE ? ORDER BY fc_userid");
            $stmt->execute(["%$search%"]);
        } else {
            $stmt = $pdo_ms->query("SELECT fc_userid, fc_branch FROM d_temporary..t_user ORDER BY fc_userid");
        }
        $all_data = $stmt->fetchAll();
        $total_users = count($all_data);
        $active_users = array_slice($all_data, $offset, $limit);
    }
}
$total_pages = ceil($total_users / $limit);


require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Session & System Lock</h1>
            <p class="text-sm text-muted-foreground">Kelola user yang sedang aktif dan atur status penguncian sistem (Maintenance).</p>
        </div>
        <div class="h-9 hidden sm:block"></div> <!-- Spacer untuk menyamakan tinggi header dengan page lain -->
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

    <div class="grid gap-6 md:grid-cols-12 items-start">
        
        <!-- SIDE PANEL: System Status & Global Actions -->
        <div class="md:col-span-4 lg:col-span-3 space-y-6">
            
            <!-- SYSTEM STATUS CARD -->
            <div class="rounded-lg border bg-card shadow-sm overflow-hidden">
                <div class="p-5 border-b bg-muted/20">
                    <h3 class="font-semibold flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                        Status Sistem Inti
                    </h3>
                </div>
                <div class="p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-muted-foreground">Status Login</span>
                        <?php if(strtoupper($sys_hold_status) === 'NO'): ?>
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Terbuka (NO)
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-destructive/20 bg-destructive/10 px-2.5 py-0.5 text-xs font-semibold text-destructive">
                                <span class="h-1.5 w-1.5 rounded-full bg-destructive animate-pulse"></span>Terkunci (YES)
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <hr>

                    <?php if(strtoupper($sys_hold_status) === 'YES'): ?>
                        <form method="POST" onsubmit="return confirm('Yakin ingin membuka akses sistem (Unlock)?')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="system_unlock">
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 text-sm font-medium shadow-sm transition-colors h-9 gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                                Unlock Sistem
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Yakin ingin MENGUNCI akses login sistem? (User tidak bisa login)')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="system_lock">
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-destructive hover:bg-destructive/90 text-destructive-foreground px-4 py-2 text-sm font-medium shadow-sm transition-colors h-9 gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                Lock Sistem
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- GLOBAL KICK ACTIONS -->
            <div class="rounded-lg border border-destructive/20 bg-destructive/5 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-destructive/20">
                    <h3 class="font-semibold text-destructive flex items-center gap-2 text-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                        Global Kick
                    </h3>
                </div>
                <div class="p-4 space-y-4">
                    <form method="POST" onsubmit="return confirm('KICK SEMUA USER yang sedang aktif? Ini akan memaksa semua user logout.')">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="kick_all">
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-destructive hover:bg-destructive/90 text-destructive-foreground px-4 py-2 text-sm font-medium shadow-sm transition-colors h-9">
                            Kick SEMUA User Aktif
                        </button>
                    </form>
                    
                    <hr class="border-destructive/20">

                    <form method="POST" class="space-y-2" onsubmit="return confirm('Kick semua user KECUALI ' + this.except_user.value + '?')">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="kick_except">
                        <label class="text-xs font-medium text-muted-foreground uppercase">Kecualikan User (Aman):</label>
                        <div class="flex gap-2">
                            <input type="text" name="except_user" required placeholder="Contoh: DITOIT"
                                class="flex h-9 w-full rounded-md border border-destructive/30 bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:border-destructive uppercase">
                            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-secondary text-secondary-foreground hover:bg-secondary/80 border border-destructive/20 px-3 py-2 text-sm font-medium shadow-sm transition-colors h-9">
                                Eksekusi
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>

        <!-- MAIN PANEL: Active User List -->
        <div class="md:col-span-8 lg:col-span-9 rounded-lg border bg-card shadow-sm overflow-hidden flex flex-col">
            <div class="p-5 border-b bg-muted/20 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <h3 class="font-semibold">User Aktif Saat Ini</h3>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= $total_users ?> total</span>
                </div>
                
                <form method="GET" class="flex gap-2 w-full sm:w-auto">
                    <div class="relative flex-1 sm:w-64">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari User ID..."
                            class="flex h-8 w-full rounded-md border border-input bg-background pl-8 pr-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                    </div>
                    <?php if($search !== ''): ?>
                        <a href="session_management" class="inline-flex items-center justify-center rounded-md border h-8 px-3 text-xs font-medium hover:bg-accent">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 border-b">
                        <tr>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12 text-center">#</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">User ID</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Branch</th>
                            <th class="h-9 px-4 text-right font-medium text-muted-foreground">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($active_users)): ?>
                        <tr><td colspan="4" class="h-32 text-center text-muted-foreground">Tidak ada user yang sedang aktif.</td></tr>
                        <?php endif; ?>
                        
                        <?php foreach($active_users as $idx => $r): ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3 text-center text-muted-foreground text-xs"><?= $offset + $idx + 1 ?></td>
                            <td class="px-4 py-3 font-mono font-medium">
                                <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span><?= e($r['fc_userid'] ?? '') ?></span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground text-xs font-mono"><?= e($r['fc_branch'] ?? '-') ?></td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" onsubmit="return confirm('Putus koneksi user <?= e($r['fc_userid']) ?> dari sistem?')">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="kick_specific">
                                    <input type="hidden" name="userid" value="<?= e($r['fc_userid']) ?>">
                                    <button type="submit" class="inline-flex items-center justify-center rounded-md h-7 px-3 text-xs font-medium text-destructive hover:bg-destructive/10 border border-transparent hover:border-destructive/20 transition-colors">
                                        Kick User
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if($total_pages > 1): ?>
            <div class="p-4 border-t flex items-center justify-between bg-muted/10">
                <p class="text-xs text-muted-foreground">Menampilkan <b><?= count($active_users) ?></b> dari <b><?= $total_users ?></b> data</p>
                <div class="flex items-center gap-1">
                    <?php 
                    $qparam = $search !== '' ? '&q='.urlencode($search) : '';
                    if ($page > 1): 
                    ?>
                        <a href="?page=<?= $page - 1 ?><?= $qparam ?>" class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-background hover:bg-accent text-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></a>
                    <?php else: ?>
                        <span class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-muted/50 text-muted-foreground/50 text-sm cursor-not-allowed"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></span>
                    <?php endif; ?>
                    
                    <span class="text-xs font-medium px-2">Halaman <?= $page ?> dari <?= $total_pages ?></span>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?><?= $qparam ?>" class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-background hover:bg-accent text-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>
                    <?php else: ?>
                        <span class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-muted/50 text-muted-foreground/50 text-sm cursor-not-allowed"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

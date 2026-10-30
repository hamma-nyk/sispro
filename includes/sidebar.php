<?php
$role_aktif = $_SESSION['role'] ?? '';
$userModules = $_SESSION['modules'] ?? [];
$isSuperadmin = $_SESSION['is_superadmin'] ?? false;

$sidebarModules = [];
try {
    if ($isSuperadmin) {
        $stmtSb = $pdo->query('SELECT * FROM sc_master.module WHERE is_active = true ORDER BY urutan');
    } else {
        $stmtSb = $pdo->prepare('
            SELECT m.* FROM sc_master.module m
            JOIN sc_master.role_module rm ON rm.module_id = m.id
            WHERE rm.role_id = ? AND m.is_active = true
            ORDER BY m.urutan
        ');
        $stmtSb->execute([$_SESSION['role_id'] ?? 0]);
    }
    $sidebarModules = $stmtSb->fetchAll();
} catch(PDOException $e) {}

$grupMenu = [];
foreach ($sidebarModules as $m) {
    $g = $m['grup'] ?? '';
    $grupMenu[$g][] = $m;
}
?>

<div id="sidebar-overlay" class="sidebar-overlay hidden md:hidden" onclick="toggleSidebar()"></div>

<aside id="sidebar" class="sidebar-mobile md:translate-x-0 fixed inset-y-0 left-0 z-40 flex w-[260px] flex-col bg-sidebar text-sidebar-foreground border-r border-sidebar-border">
    <div class="flex h-14 items-center border-b px-6">
        <a href="./" class="flex items-center gap-2">
            <img src="assets/img/nbi.png" class="h-7 w-auto dark:hidden" alt="Logo">
            <img src="assets/img/nbi_dark.png" class="h-7 w-auto hidden dark:block" alt="Logo Dark">
            <span class="font-semibold text-sm tracking-tight text-foreground">IT Support</span>
        </a>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4">
        <?php 
        $currentScript = pathinfo($_SERVER['PHP_SELF'], PATHINFO_FILENAME);
        foreach($grupMenu as $grup => $items): 
        ?>
            <?php if($grup !== ''): ?>
            <div class="mt-6">
                <p class="px-3 mb-2 text-xs font-medium text-muted-foreground uppercase tracking-wider"><?= e($grup) ?></p>
                <div class="space-y-1">
            <?php else: ?>
            <div class="space-y-1">
            <?php endif; ?>

            <?php foreach($items as $m): 
                $cleanUrl = preg_replace('/\.php$/', '', $m['url'] ?? '');
                $targetScript = pathinfo($m['url'] ?? '', PATHINFO_FILENAME);
                $moduleHref = ($cleanUrl === 'index' || $cleanUrl === '') ? './' : $cleanUrl;
                $isActive = ($currentScript === $targetScript);
            ?>
                <a href="<?= e($moduleHref) ?>" class="sidebar-link flex items-center gap-3 rounded-md px-3 py-2 text-sm <?= $isActive ? 'active font-medium text-foreground' : 'text-muted-foreground hover:text-foreground' ?>">
                    <?= $m['icon'] ?>
                    <?= e($m['nama']) ?>
                </a>
            <?php endforeach; ?>

            <?php if($grup !== ''): ?>
                </div>
            </div>
            <?php else: ?>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="mt-6">
            <p class="px-3 mb-2 text-xs font-medium text-muted-foreground uppercase tracking-wider">Akun</p>
            <div class="space-y-1">
                <a href="logout" class="sidebar-link flex items-center gap-3 rounded-md px-3 py-2 text-sm text-destructive hover:bg-destructive/10">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                    Keluar Sistem
                </a>
            </div>
        </div>
    </nav>

    <div class="border-t p-4">
        <div class="flex items-center gap-3">
            <div class="h-8 w-8 rounded-full bg-primary flex items-center justify-center flex-shrink-0">
                <span class="text-xs font-medium text-primary-foreground"><?= mb_strtoupper(mb_substr($_SESSION['nama_lengkap'] ?? 'U', 0, 1)) ?></span>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium truncate"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'Pengguna') ?></p>
                <p class="text-xs text-muted-foreground truncate"><?= htmlspecialchars($_SESSION['role'] ?? 'Guest') ?></p>
            </div>
        </div>
    </div>
</aside>

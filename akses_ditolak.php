<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}
require_once 'includes/auth.php';
require_once 'includes/header.php';
?>

<div class="flex flex-col items-center justify-center min-h-[60vh] text-center space-y-4">
    <div class="rounded-full bg-destructive/10 p-4">
        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-destructive"><circle cx="12" cy="12" r="10"/><line x1="4.93" x2="19.07" y1="4.93" y2="19.07"/></svg>
    </div>
    <div class="space-y-2">
        <h1 class="text-3xl font-bold tracking-tight">403</h1>
        <h2 class="text-lg font-semibold">Akses Ditolak</h2>
        <p class="text-sm text-muted-foreground max-w-md">Anda tidak memiliki wewenang untuk mengakses halaman ini. Hubungi Admin IT jika ini kesalahan sistem.</p>
    </div>
    <a href="index.php" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9">
        Kembali ke Dashboard
    </a>
</div>

<?php require_once 'includes/footer.php'; ?>

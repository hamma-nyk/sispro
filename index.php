<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

$nama_user = $_SESSION['nama_lengkap'] ?? 'Pengguna';
$role_aktif = $_SESSION['role'] ?? 'Guest';

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
        <p class="text-sm text-muted-foreground">Selamat datang kembali di sistem.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-lg border bg-card p-6 shadow-sm">
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Nama Pengguna
            </div>
            <p class="mt-2 text-xl font-bold"><?= htmlspecialchars($nama_user) ?></p>
        </div>

        <div class="rounded-lg border bg-card p-6 shadow-sm">
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                Role Aktif
            </div>
            <p class="mt-2 text-xl font-bold"><?= htmlspecialchars($role_aktif) ?></p>
        </div>

        <div class="rounded-lg border bg-card p-6 shadow-sm">
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Waktu Login
            </div>
            <p class="mt-2 text-xl font-bold"><?= date('H:i') ?></p>
            <p class="text-xs text-muted-foreground"><?= date('d M Y') ?></p>
        </div>
    </div>

    <div class="rounded-lg border bg-card shadow-sm">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold">Informasi Sistem</h3>
            <p class="text-sm text-muted-foreground">Template siap digunakan untuk pengembangan fitur baru.</p>
        </div>
        <div class="p-6">
            <div class="rounded-md bg-muted/50 border p-4">
                <p class="text-sm text-muted-foreground leading-relaxed">
                    Sistem ini menggunakan arsitektur PHP native dengan Tailwind CSS. 
                    Tambahkan halaman baru dengan meng-include <code class="relative rounded bg-muted px-[0.3rem] py-[0.2rem] font-mono text-sm">header.php</code> dan <code class="relative rounded bg-muted px-[0.3rem] py-[0.2rem] font-mono text-sm">footer.php</code>, 
                    lalu daftarkan menu di <code class="relative rounded bg-muted px-[0.3rem] py-[0.2rem] font-mono text-sm">sidebar.php</code>.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

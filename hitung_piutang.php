<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('hitung_piutang');

$success = null;
$error = null;
$customer_code = trim($_POST['customer_code'] ?? '');

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    
    if (empty($customer_code)) {
        $error = "Kode Customer tidak boleh kosong.";
    } elseif (isset($pdo_ms)) {
        try {
            $stmt = $pdo_ms->prepare("EXEC d_transaksi..pr_arblc1 ?");
            $stmt->execute([$customer_code]);
            $stmt->closeCursor();
            
            $success = "Proses hitung ulang kartu piutang untuk customer " . htmlspecialchars($customer_code) . " berhasil dijalankan.";
            write_security_log('info', "Hitung ulang kartu piutang (pr_arblc1). Customer: $customer_code");
        } catch (PDOException $e) {
            $error = "Gagal memproses hitung ulang piutang: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Hitung Ulang Kartu Piutang</h1>
            <p class="text-sm text-muted-foreground">Jalankan proses pr_arblc1 untuk menghitung ulang piutang customer tertentu.</p>
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

    <div class="grid gap-6 md:grid-cols-2 items-start">
        <div class="rounded-lg border bg-card shadow-sm p-5">
            <h3 class="font-semibold mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Eksekusi Hitung Ulang
            </h3>
            <form method="POST" class="space-y-4" onsubmit="return confirm('Yakin ingin menghitung ulang kartu piutang customer ini?')">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="space-y-2">
                    <label class="text-sm font-medium">Kode Customer</label>
                    <input type="text" name="customer_code" required placeholder="Contoh: CS-450" value="<?= e($customer_code) ?>"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                    <p class="text-xs text-muted-foreground">Masukkan kode customer dengan benar untuk proses hitung ulang.</p>
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary text-primary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-primary/90 transition-colors h-9 gap-2">
                    Jalankan Proses
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

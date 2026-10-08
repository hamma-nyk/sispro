<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('update_nama_barang');

$success = null;
$error = null;
$search_code = trim($_GET['stockcode'] ?? '');
$item_data = null;

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// 1. Fetch Preview Data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $search_code !== '' && isset($pdo_ms)) {
    try {
        $stmt = $pdo_ms->prepare("SELECT fc_stockcode, fv_stockname FROM d_master..t_stock WHERE CAST(fc_stockcode AS VARCHAR(8000)) LIKE ?");
        $stmt->execute([$search_code]);
        $item_data = $stmt->fetch();

        if (!$item_data) {
            $error = "Data barang dengan kode '$search_code' tidak ditemukan di database.";
        }
    } catch (PDOException $e) {
        $error = "Gagal mengambil data barang: " . $e->getMessage();
    }
}

// 2. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo_ms)) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $stockcode = trim($_POST['stockcode'] ?? '');
    $new_name = trim($_POST['new_name'] ?? '');

    if ($stockcode === '' || $new_name === '') {
        $error = "Kode Barang dan Nama Baru wajib diisi.";
    } else {
        try {
            $stmt = $pdo_ms->prepare("UPDATE d_master..t_stock SET fv_stockname = ? WHERE CAST(fc_stockcode AS VARCHAR(8000)) LIKE ?");
            $stmt->execute([$new_name, $stockcode]);
            $affected = $stmt->rowCount();

            if ($affected > 0) {
                $success = "Nama barang untuk kode '$stockcode' berhasil diubah menjadi '$new_name'.";
                write_security_log('info', "Update Nama Barang: $stockcode -> $new_name");
            } else {
                $error = "Tidak ada data yang diubah. Pastikan kode barang benar.";
            }

            // Refresh data
            $search_code = $stockcode;
            $stmtR = $pdo_ms->prepare("SELECT fc_stockcode, fv_stockname FROM d_master..t_stock WHERE CAST(fc_stockcode AS VARCHAR(8000)) LIKE ?");
            $stmtR->execute([$stockcode]);
            $item_data = $stmtR->fetch();

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
            <h1 class="text-2xl font-semibold tracking-tight">Update Nama Barang</h1>
            <p class="text-sm text-muted-foreground">Ubah deskripsi atau nama barang (fv_stockname) di master stok (d_master..t_stock).</p>
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

    <div class="grid gap-6 md:grid-cols-12 items-start">
        
        <!-- PENCARIAN -->
        <div class="rounded-lg border bg-card shadow-sm p-5 md:col-span-4 lg:col-span-4">
            <h3 class="font-semibold mb-4 flex items-center gap-2 text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Pencarian Barang
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">Kode Barang (fc_stockcode)</label>
                    <input type="text" name="stockcode" value="<?= e($search_code) ?>" required placeholder="Contoh: CPH00016"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Cek Data Barang
                </button>
            </form>
        </div>

        <!-- HASIL & FORM UPDATE -->
        <div class="md:col-span-8 lg:col-span-8 space-y-6">
            <?php if($item_data): ?>
            <div class="rounded-lg border bg-card shadow-sm overflow-hidden">
                <div class="p-5 border-b bg-muted/20">
                    <h3 class="font-semibold text-sm">Detail Master Barang</h3>
                </div>
                <div class="p-5 grid grid-cols-1 gap-4">
                    <div class="space-y-1">
                        <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider">Kode Barang</p>
                        <p class="font-mono font-bold text-lg"><?= e(trim($item_data['fc_stockcode'] ?? '')) ?></p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider">Nama Barang Saat Ini</p>
                        <p class="font-medium text-lg text-primary"><?= e(trim($item_data['fv_stockname'] ?? '')) ?></p>
                    </div>
                </div>
                
                <div class="p-5 border-t bg-muted/5">
                    <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin mengubah nama barang ini?')" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="stockcode" value="<?= e(trim($item_data['fc_stockcode'] ?? '')) ?>">

                        <div class="space-y-2 max-w-lg">
                            <label class="text-sm font-medium">Ubah Nama Menjadi</label>
                            <input type="text" name="new_name" required placeholder="Ketik nama baru (Contoh: PLUG)" value="<?= e(trim($item_data['fv_stockname'] ?? '')) ?>"
                                class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring font-medium">
                        </div>

                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            Simpan Perubahan
                        </button>
                    </form>
                </div>
            </div>
            <?php elseif($search_code !== '' && !$error): ?>
            <div class="rounded-lg border bg-card shadow-sm p-12 flex flex-col items-center justify-center text-center text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="mb-4 opacity-50"><path d="m21 16-4 4-4-4"/><path d=\"M17 20V4\"/><path d=\"m3 8 4-4 4 4\"/><path d=\"M7 4v16\"/></svg>
                <p>Barang dengan kode <b><?= e($search_code) ?></b> tidak ditemukan di master data.</p>
            </div>
            <?php else: ?>
            <div class="rounded-lg border bg-card shadow-sm p-12 flex flex-col items-center justify-center text-center text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="mb-4 opacity-50"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/><path d="m7.5 4.27 9 5.15"/></svg>
                <p>Masukkan Kode Barang (stockcode) di panel kiri untuk melihat dan memperbarui namanya.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

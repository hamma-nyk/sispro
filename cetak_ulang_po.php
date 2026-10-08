<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('cetak_ulang_po');

$success = null;
$error = null;
$po_data = null;
$search_po = '';

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// Handler Search PO
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pono'])) {
    $search_po = trim($_GET['pono']);

    if ($search_po !== '' && isset($pdo_ms)) {
        try {
            $stmt = $pdo_ms->prepare("SELECT FC_PONO, FC_POSTATUS FROM d_transaksi..t_pomst WHERE FC_PONO LIKE ?");
            // Bisa jadi PO-nya ada spasi dll, kita strict
            $stmt->execute([$search_po]);
            $po_data = $stmt->fetch();

            if (!$po_data) {
                $error = "Nomor PO '$search_po' tidak ditemukan di database.";
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data PO: " . $e->getMessage();
        }
    }
}

// Handler Update Status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $pono = trim($_POST['pono'] ?? '');
    
    if ($pono !== '' && isset($pdo_ms)) {
        try {
            // Ambil status terkini dulu untuk jaga-jaga
            $stmt = $pdo_ms->prepare("SELECT FC_POSTATUS FROM d_transaksi..t_pomst WHERE FC_PONO LIKE ?");
            $stmt->execute([$pono]);
            $current_status_row = $stmt->fetch();

            if (!$current_status_row) {
                $error = "PO tidak ditemukan saat mau diproses.";
            } else {
                $current_status = trim($current_status_row['FC_POSTATUS'] ?? '');

                if ($action === 'set_p') {
                    if ($current_status === 'P') {
                        $error = "Status PO saat ini sudah 'P'.";
                    } else {
                        // Simpan status lama ke session
                        $_SESSION['po_status_history'][$pono] = $current_status;

                        $update = $pdo_ms->prepare("UPDATE d_transaksi..t_pomst SET FC_POSTATUS = 'P' WHERE FC_PONO LIKE ?");
                        $update->execute([$pono]);
                        
                        $success = "Status PO $pono berhasil diubah menjadi 'P' (Print). Anda sekarang bisa mencetak ulang PO tersebut.";
                        write_security_log('info', "Cetak Ulang PO: Ubah status $pono dari '$current_status' ke 'P'");
                    }
                } 
                elseif ($action === 'restore') {
                    $old_status = $_SESSION['po_status_history'][$pono] ?? null;
                    
                    if ($old_status === null) {
                        $error = "Tidak ada riwayat status sebelumnya untuk PO ini di sesi Anda.";
                    } elseif ($current_status === $old_status) {
                        $error = "Status PO saat ini sudah '$old_status'.";
                    } else {
                        $update = $pdo_ms->prepare("UPDATE d_transaksi..t_pomst SET FC_POSTATUS = ? WHERE FC_PONO LIKE ?");
                        $update->execute([$old_status, $pono]);
                        
                        $success = "Status PO $pono berhasil dikembalikan ke '$old_status'.";
                        write_security_log('info', "Cetak Ulang PO: Restore status $pono dari '$current_status' ke '$old_status'");
                        
                        // Hapus history setelah dikembalikan
                        unset($_SESSION['po_status_history'][$pono]);
                    }
                }

                // Refresh data PO untuk ditampilkan kembali
                $search_po = $pono;
                $stmt = $pdo_ms->prepare("SELECT FC_PONO, FC_POSTATUS FROM d_transaksi..t_pomst WHERE FC_PONO LIKE ?");
                $stmt->execute([$search_po]);
                $po_data = $stmt->fetch();
            }
        } catch (PDOException $e) {
            $error = "Terjadi kesalahan saat memproses status: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Cetak Ulang PO</h1>
            <p class="text-sm text-muted-foreground">Ubah sementara status PO menjadi 'P' agar bisa dicetak ulang di sistem utama.</p>
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

    <div class="grid gap-6 md:grid-cols-2">
        <div class="rounded-lg border bg-card shadow-sm p-6">
            <h3 class="text-lg font-semibold mb-4">Cari Purchase Order</h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">Nomor PO</label>
                    <input type="text" name="pono" value="<?= e($search_po) ?>" required placeholder="Contoh: P24030000328"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    Cek Status
                </button>
            </form>
        </div>

        <?php if($po_data): ?>
        <div class="rounded-lg border bg-card shadow-sm p-6 flex flex-col justify-center">
            <div class="text-center space-y-4">
                <div>
                    <p class="text-sm text-muted-foreground">Status saat ini untuk PO</p>
                    <p class="text-xl font-bold font-mono mt-1"><?= e($po_data['FC_PONO'] ?? $po_data['fc_pono'] ?? '') ?></p>
                </div>

                <?php 
                $status = trim($po_data['FC_POSTATUS'] ?? $po_data['fc_postatus'] ?? ''); 
                $old_status_session = $_SESSION['po_status_history'][$po_data['FC_PONO'] ?? $po_data['fc_pono']] ?? null;
                ?>
                
                <div class="inline-flex items-center justify-center rounded-full border px-4 py-1.5 text-lg font-bold
                    <?= $status === 'P' ? 'bg-primary/10 text-primary border-primary/20' : 'bg-secondary text-secondary-foreground' ?>">
                    STATUS: <?= $status !== '' ? e($status) : '[KOSONG]' ?>
                </div>

                <div class="pt-4 flex justify-center gap-3">
                    <?php if($status !== 'P'): ?>
                        <form method="POST" onsubmit="return confirm('Ubah status ke P? (Status asli <?= e($status) ?> akan disimpan agar bisa dikembalikan)')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="set_p">
                            <input type="hidden" name="pono" value="<?= e($po_data['FC_PONO'] ?? $po_data['fc_pono']) ?>">
                            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>
                                Ubah ke 'P' (Print)
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if($old_status_session !== null && $status === 'P'): ?>
                        <form method="POST" onsubmit="return confirm('Kembalikan status ke <?= e($old_status_session) ?>?')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="pono" value="<?= e($po_data['FC_PONO'] ?? $po_data['fc_pono']) ?>">
                            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-destructive text-destructive-foreground px-4 py-2 text-sm font-medium shadow hover:bg-destructive/90 transition-colors h-9 gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                Kembalikan ke '<?= e($old_status_session) ?>'
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if($old_status_session !== null && $status === 'P'): ?>
                    <p class="text-xs text-muted-foreground mt-2">Status asli '<?= e($old_status_session) ?>' tersimpan. Jangan lupa dikembalikan setelah selesai cetak.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

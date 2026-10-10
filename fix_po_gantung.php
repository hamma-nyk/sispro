<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('fix_po_gantung');

$success = null;
$error = null;
$search_po = trim($_GET['po'] ?? '');
$po_master = null;
$po_details = [];

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// 1. Fetch Preview Data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $search_po !== '' && isset($pdo_ms)) {
    try {
        $stmtMst = $pdo_ms->prepare("SELECT * FROM d_transaksi..t_pomst WHERE CAST(fc_pono AS VARCHAR(8000)) LIKE ?");
        $stmtMst->execute(["%$search_po%"]);
        $po_master = $stmtMst->fetch();

        if ($po_master) {
            $stmtDtl = $pdo_ms->prepare("SELECT * FROM d_transaksi..t_podtl WHERE CAST(fc_pono AS VARCHAR(8000)) = ?");
            $stmtDtl->execute([trim($po_master['fc_pono'] ?? $po_master['FC_PONO'])]);
            $po_details = $stmtDtl->fetchAll();
        } else {
            $error = "Data Master PO untuk '$search_po' tidak ditemukan.";
        }
    } catch (PDOException $e) {
        $error = "Gagal mengambil data PO: " . $e->getMessage();
    }
}

// 2. Handle Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo_ms)) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $pono = $_POST['pono'] ?? '';

    if ($pono !== '') {
        try {
            $pdo_ms->beginTransaction();
            if ($action === 'force_close') {
                $stmt = $pdo_ms->prepare("UPDATE d_transaksi..t_pomst SET FC_POSTATUS = 'C' WHERE CAST(fc_pono AS VARCHAR(8000)) = ?");
                $stmt->execute([$pono]);
                $success = "Status PO '$pono' berhasil di-Force Close ('C').";
                write_security_log('warning', "Fix PO Gantung: Force Close (C) pada PO $pono");
            } elseif ($action === 'force_final') {
                $stmt = $pdo_ms->prepare("UPDATE d_transaksi..t_pomst SET FC_POSTATUS = 'F' WHERE CAST(fc_pono AS VARCHAR(8000)) = ?");
                $stmt->execute([$pono]);
                $success = "Status PO '$pono' berhasil di-set Final ('F').";
                write_security_log('warning', "Fix PO Gantung: Set Final (F) pada PO $pono");
            } elseif ($action === 'delete') {
                $pdo_ms->prepare("DELETE FROM d_transaksi..t_podtl WHERE CAST(fc_pono AS VARCHAR(8000)) = ?")->execute([$pono]);
                $pdo_ms->prepare("DELETE FROM d_transaksi..t_pomst WHERE CAST(fc_pono AS VARCHAR(8000)) = ?")->execute([$pono]);
                $success = "Master dan Detail PO '$pono' berhasil dihapus permanen.";
                write_security_log('danger', "Fix PO Gantung: Delete Permanen PO $pono");
                $po_master = null;
                $po_details = [];
            }
            $pdo_ms->commit();

            if ($action !== 'delete') {
                $stmtMst = $pdo_ms->prepare("SELECT * FROM d_transaksi..t_pomst WHERE CAST(fc_pono AS VARCHAR(8000)) = ?");
                $stmtMst->execute([$pono]);
                $po_master = $stmtMst->fetch();

                $stmtDtl = $pdo_ms->prepare("SELECT * FROM d_transaksi..t_podtl WHERE CAST(fc_pono AS VARCHAR(8000)) = ?");
                $stmtDtl->execute([$pono]);
                $po_details = $stmtDtl->fetchAll();
            }

        } catch (PDOException $e) {
            $pdo_ms->rollBack();
            $error = "Gagal memproses aksi pada PO: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Fix PO Gantung</h1>
            <p class="text-sm text-muted-foreground">Tangani master Purchase Order (t_pomst) yang bermasalah. Force Close, Open, atau Hapus.</p>
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
        <div class="rounded-lg border bg-card shadow-sm p-5 md:col-span-4 lg:col-span-3">
            <h3 class="font-semibold mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Pencarian PO
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">Nomor Purchase Order (PO)</label>
                    <input type="text" name="po" value="<?= e($search_po) ?>" required placeholder="Contoh: MEME"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Preview PO
                </button>
            </form>
        </div>

        <!-- HASIL -->
        <div class="md:col-span-8 lg:col-span-9 space-y-6">
            
            <?php if($po_master): ?>
            <!-- Master Info -->
            <div class="rounded-lg border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b bg-muted/20 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                        <h3 class="font-semibold">Master PO</h3>
                    </div>
                    <?php 
                    $stat = trim($po_master['FC_POSTATUS'] ?? $po_master['fc_postatus'] ?? '');
                    if ($stat === 'C') $badge = 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20';
                    elseif ($stat === 'F') $badge = 'bg-blue-500/10 text-blue-600 border-blue-500/20';
                    elseif ($stat === 'P') $badge = 'bg-amber-500/10 text-amber-600 border-amber-500/20';
                    else $badge = 'bg-muted text-muted-foreground border-border';
                    ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full border text-xs font-semibold <?= $badge ?>">
                        Status: <?= e($stat) ?: 'NULL' ?>
                    </span>
                </div>
                <div class="p-5 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <p class="text-xs text-muted-foreground uppercase tracking-wider mb-1">Nomor PO</p>
                        <p class="font-mono font-semibold"><?= e(trim($po_master['fc_pono'] ?? $po_master['FC_PONO'] ?? '-')) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground uppercase tracking-wider mb-1">Tanggal</p>
                        <?php $tgl = trim($po_master['fd_podate'] ?? $po_master['FD_PODATE'] ?? ''); ?>
                        <p class="font-semibold"><?= $tgl ? date('d/m/Y', strtotime($tgl)) : '-' ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground uppercase tracking-wider mb-1">Supplier</p>
                        <p class="font-mono font-semibold"><?= e(trim($po_master['fc_suppcode'] ?? $po_master['FC_SUPPCODE'] ?? '-')) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground uppercase tracking-wider mb-1">Total Item</p>
                        <p class="font-semibold"><?= count($po_details) ?> baris</p>
                    </div>
                </div>
                
                <div class="p-4 bg-muted/10 border-t flex flex-wrap gap-2">
                    <form method="POST" onsubmit="return confirm('Yakin mengubah status PO menjadi Force Close (C)?')" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="force_close">
                        <input type="hidden" name="pono" value="<?= e(trim($po_master['fc_pono'] ?? $po_master['FC_PONO'])) ?>">
                        <button type="submit" class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-1.5 text-xs font-medium shadow-sm hover:bg-accent hover:text-accent-foreground transition-colors gap-1.5">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Set Closed (C)
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Yakin mengubah status PO menjadi Final (F)?')" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="force_final">
                        <input type="hidden" name="pono" value="<?= e(trim($po_master['fc_pono'] ?? $po_master['FC_PONO'])) ?>">
                        <button type="submit" class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-1.5 text-xs font-medium shadow-sm hover:bg-accent hover:text-accent-foreground transition-colors gap-1.5">
                            <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span> Set Final (F)
                        </button>
                    </form>
                    <div class="flex-1"></div>
                    <form method="POST" onsubmit="return confirm('PERINGATAN: Yakin MENGHAPUS PERMANEN master dan detail PO ini dari database? Aksi ini tidak bisa di-undo!')" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="pono" value="<?= e(trim($po_master['fc_pono'] ?? $po_master['FC_PONO'])) ?>">
                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-destructive text-destructive-foreground px-3 py-1.5 text-xs font-medium shadow hover:bg-destructive/90 transition-colors gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                            Delete PO Permanen
                        </button>
                    </form>
                </div>
            </div>

            <!-- Detail Items -->
            <div class="rounded-lg border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b bg-muted/20">
                    <h3 class="font-semibold flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg>
                        Item Details (t_podtl)
                    </h3>
                </div>
                <div class="overflow-x-auto max-h-[300px]">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-muted/80 backdrop-blur border-b">
                            <tr>
                                <th class="h-8 px-4 text-left font-medium text-muted-foreground w-12 text-center">Seq</th>
                                <th class="h-8 px-4 text-left font-medium text-muted-foreground">Item Code</th>
                                <th class="h-8 px-4 text-left font-medium text-muted-foreground">Deskripsi (Jika Ada)</th>
                                <th class="h-8 px-4 text-right font-medium text-muted-foreground">Qty</th>
                                <th class="h-8 px-4 text-right font-medium text-muted-foreground">Harga</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($po_details)): ?>
                            <tr><td colspan="5" class="h-16 text-center text-muted-foreground">Tidak ada detail item.</td></tr>
                            <?php endif; ?>
                            
                            <?php foreach($po_details as $d): ?>
                            <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                                <td class="px-4 py-2 text-center text-muted-foreground"><?= e(trim($d['fn_poseq'] ?? $d['FN_POSEQ'] ?? '-')) ?></td>
                                <td class="px-4 py-2 font-mono font-medium"><?= e(trim($d['fc_stockcode'] ?? $d['FC_STOCKCODE'] ?? '-')) ?></td>
                                <td class="px-4 py-2 truncate max-w-[200px]"><?= e(trim($d['fv_description'] ?? $d['FV_DESCRIPTION'] ?? '-')) ?></td>
                                <td class="px-4 py-2 text-right font-mono"><?= number_format((float)($d['fn_poqty'] ?? $d['FN_POQTY'] ?? 0), 2) ?></td>
                                <td class="px-4 py-2 text-right font-mono text-muted-foreground"><?= number_format((float)($d['fm_price'] ?? $d['FM_PRICE'] ?? 0), 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php elseif($search_po !== '' && !$error): ?>
            <div class="rounded-lg border bg-card shadow-sm p-12 flex flex-col items-center justify-center text-center text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="mb-4 opacity-50"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><line x1="9" x2="15" y1="15" y2="9"/><line x1="9" x2="15" y1="9" y2="15"/></svg>
                <p>PO dengan nomor <b><?= e($search_po) ?></b> tidak ditemukan di sistem.</p>
            </div>
            <?php else: ?>
            <div class="rounded-lg border bg-card shadow-sm p-12 flex flex-col items-center justify-center text-center text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="mb-4 opacity-50"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                <p>Masukkan Nomor PO di panel sebelah kiri untuk melihat detail dan memperbaiki PO yang gantung.</p>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

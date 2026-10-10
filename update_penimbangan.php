<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('update_penimbangan');

$success = null;
$error = null;
$data_preview = [];
$search_nopol = trim($_GET['nopol'] ?? '');
$search_docno = trim($_GET['docno'] ?? '');

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// 1. Fetch Preview Data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($search_nopol !== '' || $search_docno !== '')) {
    if (isset($pdo_ms)) {
        try {
            $query = "SELECT fc_docno, fc_nopol, FN_QBRUTTO, FN_QTARA, FN_QNETTO, FN_QFINAL, fc_status FROM d_transaksi..t_scales WHERE 1=1";
            $params = [];
            
            if ($search_nopol !== '') {
                $query .= " AND CAST(fc_nopol AS VARCHAR(8000)) LIKE ?";
                $params[] = "%$search_nopol%";
            }
            if ($search_docno !== '') {
                $query .= " AND CAST(fc_docno AS VARCHAR(8000)) LIKE ?";
                $params[] = "%$search_docno%";
            }
            
            $query .= " ORDER BY fc_docno DESC";
            
            $stmt = $pdo_ms->prepare($query);
            $stmt->execute($params);
            $data_preview = $stmt->fetchAll();
            
            if (empty($data_preview)) {
                $error = "Tidak ada data penimbangan yang ditemukan.";
            } else {
                $_SESSION['scale_valid_docs'] = array_column($data_preview, 'fc_docno');
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data penimbangan: " . $e->getMessage();
        }
    }
}

// 2. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    
    $docno = trim($_POST['docno'] ?? '');
    $valid_docs = $_SESSION['scale_valid_docs'] ?? [];

    if (!in_array($docno, $valid_docs)) {
        $error = "Data sesi tidak valid untuk dokumen ini. Silakan cari ulang.";
    } elseif (isset($pdo_ms)) {
        try {
            $brutto = $_POST['brutto'] ?? '';
            $tara = $_POST['tara'] ?? '';
            $netto = $_POST['netto'] ?? '';
            $final = $_POST['final'] ?? '';
            $status = $_POST['status'] ?? '';

            if ($brutto === '' || $tara === '' || $netto === '' || $final === '' || $status === '') {
                $error = "Semua field nilai penimbangan dan status wajib diisi.";
            } else {
                $sql = "UPDATE d_transaksi..t_scales SET FN_QBRUTTO=?, FN_QTARA=?, FN_QNETTO=?, FN_QFINAL=?, fc_status=? WHERE CAST(FC_DOCNO AS VARCHAR(8000)) LIKE ?";
                $stmt = $pdo_ms->prepare($sql);
                $stmt->execute([
                    (float)$brutto, 
                    (float)$tara, 
                    (float)$netto, 
                    (float)$final, 
                    strtoupper($status),
                    $docno
                ]);
                
                $success = "Data penimbangan untuk Dokumen '$docno' berhasil diperbarui.";
                write_security_log('info', "Update Penimbangan: $docno | B:$brutto T:$tara N:$netto F:$final S:$status");

                // Refresh data
                $stmtRefresh = $pdo_ms->prepare("SELECT fc_docno, fc_nopol, FN_QBRUTTO, FN_QTARA, FN_QNETTO, FN_QFINAL, fc_status FROM d_transaksi..t_scales WHERE CAST(FC_DOCNO AS VARCHAR(8000)) LIKE ?");
                $stmtRefresh->execute([$docno]);
                $refreshed = $stmtRefresh->fetch();
                
                if ($refreshed) {
                    foreach ($data_preview as &$row) {
                        if ($row['fc_docno'] === $docno) {
                            $row = $refreshed;
                            break;
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $error = "Gagal memproses update penimbangan: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Update Penimbangan</h1>
            <p class="text-sm text-muted-foreground">Koreksi nilai timbangan (Brutto, Tara, Netto) dan ubah status timbangan truk.</p>
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
        
        <!-- Form Input & Pencarian -->
        <div class="rounded-lg border bg-card shadow-sm p-5 md:col-span-4 lg:col-span-3">
            <h3 class="font-semibold mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Cari Penimbangan
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">No. Polisi (Kendaraan)</label>
                    <input type="text" name="nopol" value="<?= e($search_nopol) ?>" placeholder="Contoh: L8845UB"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium">No. Dokumen <span class="text-xs text-muted-foreground">(Opsional)</span></label>
                    <input type="text" name="docno" value="<?= e($search_docno) ?>" placeholder="Contoh: SCL240700149"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Cari Data
                </button>
                <?php if($search_nopol !== '' || $search_docno !== ''): ?>
                <a href="update_penimbangan.php" class="inline-flex w-full items-center justify-center rounded-md text-muted-foreground hover:bg-muted border border-transparent hover:border-border transition-colors h-9 text-sm">
                    Reset
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tabel Preview -->
        <div class="md:col-span-8 lg:col-span-9 space-y-4">
            <?php foreach($data_preview as $r): ?>
            <div class="rounded-lg border bg-card shadow-sm overflow-hidden flex flex-col sm:flex-row relative">
                
                <!-- Info Utama -->
                <div class="p-5 border-b sm:border-b-0 sm:border-r bg-muted/10 sm:w-1/3 flex flex-col justify-center relative">
                    <?php 
                    $stat = trim($r['fc_status'] ?? ''); 
                    if ($stat === 'A') {
                        echo '<span class="absolute top-3 right-3 inline-flex items-center gap-1.5 rounded-full border border-amber-500/20 bg-amber-500/10 px-2.5 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>Timbang Masuk (A)</span>';
                    } elseif ($stat === 'U') {
                        echo '<span class="absolute top-3 right-3 inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Timbang Keluar (U)</span>';
                    } else {
                        echo '<span class="absolute top-3 right-3 inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-muted text-muted-foreground">' . e($stat) . '</span>';
                    }
                    ?>
                    
                    <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider mb-1 mt-4 sm:mt-0">Nopol Truk</p>
                    <p class="text-2xl font-bold font-mono tracking-wide"><?= e($r['fc_nopol']) ?></p>
                    
                    <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider mt-4 mb-1">Nomor Dokumen</p>
                    <p class="text-sm font-mono"><?= e($r['fc_docno']) ?></p>
                </div>

                <!-- Form Edit -->
                <div class="p-5 flex-1 bg-background">
                    <form method="POST" onsubmit="return confirm('Update nilai penimbangan untuk dokumen <?= e($r['fc_docno']) ?>?')">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="docno" value="<?= e($r['fc_docno']) ?>">
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
                            <div class="space-y-1.5">
                                <label class="text-xs font-medium text-muted-foreground">Brutto</label>
                                <input type="number" step="0.01" name="brutto" required value="<?= (float)($r['FN_QBRUTTO'] ?? 0) ?>"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring font-mono"
                                    oninput="kalkulasiNetto(this.form)">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-medium text-muted-foreground">Tara</label>
                                <input type="number" step="0.01" name="tara" required value="<?= (float)($r['FN_QTARA'] ?? 0) ?>"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring font-mono"
                                    oninput="kalkulasiNetto(this.form)">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-medium text-muted-foreground">Netto <span class="font-normal opacity-50">(Auto)</span></label>
                                <input type="number" step="0.01" name="netto" required value="<?= (float)($r['FN_QNETTO'] ?? 0) ?>"
                                    class="flex h-9 w-full rounded-md border border-input bg-muted/50 px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring font-mono text-primary font-bold">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-medium text-muted-foreground">Final Qty</label>
                                <input type="number" step="0.01" name="final" required value="<?= (float)($r['FN_QFINAL'] ?? 0) ?>"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring font-mono font-bold">
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t">
                            <div class="w-full sm:w-64 space-y-1.5">
                                <label class="text-xs font-medium text-muted-foreground">Status Proses Penimbangan</label>
                                <select name="status" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                                    <option value="A" <?= $stat === 'A' ? 'selected' : '' ?>>A - Baru Timbang Masuk</option>
                                    <option value="U" <?= $stat === 'U' ? 'selected' : '' ?>>U - Sudah Timbang Keluar</option>
                                    <option value="C" <?= $stat === 'C' ? 'selected' : '' ?>>C - Cancel</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2 w-full sm:w-auto">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                Simpan Perubahan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if(empty($data_preview) && ($search_nopol !== '' || $search_docno !== '')): ?>
                <!-- Not found state is handled in error box -->
            <?php elseif(empty($data_preview)): ?>
            <div class="rounded-lg border bg-card shadow-sm p-12 flex flex-col items-center justify-center text-center text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="mb-4 opacity-50"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
                <p>Masukkan Nopol atau Nomor Dokumen untuk melihat dan mengedit data penimbangan.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function kalkulasiNetto(form) {
    const brutto = parseFloat(form.brutto.value) || 0;
    const tara = parseFloat(form.tara.value) || 0;
    const netto = brutto - tara;
    form.netto.value = netto > 0 ? netto : 0;
    
    // Opsional: otomatis samakan QFINAL dengan Netto
    // form.final.value = form.netto.value; 
}
</script>

<?php require_once 'includes/footer.php'; ?>

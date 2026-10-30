<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('fix_transfer_bj');

$success = null;
$error = null;
$found_data = null;
$search_prefix = trim($_GET['prefix'] ?? '');
$search_document = trim($_GET['document'] ?? '');

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// 1. Fetch Preview
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($search_prefix !== '' || $search_document !== '')) {
    if (isset($pdo_ms)) {
        try {
            $query = "SELECT fv_prefix, fv_document, fn_docno FROM d_master..t_nomor WHERE 1=1";
            $params = [];

            if ($search_prefix !== '') {
                $query .= " AND CAST(fv_prefix AS VARCHAR(8000)) LIKE ?";
                $params[] = "%$search_prefix%";
            }
            if ($search_document !== '') {
                $query .= " AND CAST(fv_document AS VARCHAR(8000)) LIKE ?";
                $params[] = "%$search_document%";
            }

            $stmt = $pdo_ms->prepare($query);
            $stmt->execute($params);
            $found_data = $stmt->fetchAll();

            if (empty($found_data)) {
                $error = "Tidak ada data nomor dokumen yang ditemukan untuk prefix/document tersebut.";
                $found_data = null;
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data: " . $e->getMessage();
        }
    }
}

// 2. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo_ms)) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $upd_prefix = trim($_POST['prefix'] ?? '');
    $upd_document = trim($_POST['document'] ?? '');
    $new_docno = trim($_POST['new_docno'] ?? '');
    $old_docno = trim($_POST['old_docno'] ?? '');

    if ($upd_prefix === '' || $upd_document === '' || $new_docno === '') {
        $error = "Prefix, Document, dan Nomor Baru wajib diisi.";
    } else {
        try {
            $stmt = $pdo_ms->prepare("UPDATE d_master..t_nomor SET fn_docno = ? WHERE CAST(fv_prefix AS VARCHAR(8000)) LIKE ? AND CAST(fv_document AS VARCHAR(8000)) LIKE ?");
            $stmt->execute([$new_docno, $upd_prefix, $upd_document]);
            $affected = $stmt->rowCount();

            if ($affected > 0) {
                $success = "Nomor dokumen berhasil diubah dari '$old_docno' menjadi '$new_docno' untuk prefix '$upd_prefix' document '$upd_document'.";
                write_security_log('warning', "Fix Transfer BJ: $upd_prefix / $upd_document | $old_docno -> $new_docno");
            } else {
                $error = "Tidak ada data yang berubah. Pastikan prefix dan document sesuai.";
            }

            // Refresh data
            $search_prefix = $upd_prefix;
            $search_document = $upd_document;
            $stmtR = $pdo_ms->prepare("SELECT fv_prefix, fv_document, fn_docno FROM d_master..t_nomor WHERE CAST(fv_prefix AS VARCHAR(8000)) LIKE ? AND CAST(fv_document AS VARCHAR(8000)) LIKE ?");
            $stmtR->execute([$upd_prefix, $upd_document]);
            $found_data = $stmtR->fetchAll();
            if (empty($found_data)) $found_data = null;

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
            <h1 class="text-2xl font-semibold tracking-tight">Fix Transfer Produk Jadi</h1>
            <p class="text-sm text-muted-foreground">Koreksi nomor urut dokumen transfer (t_nomor) saat terjadi error penomoran.</p>
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

        <!-- Form Pencarian -->
        <div class="rounded-lg border bg-card shadow-sm p-5 md:col-span-4 lg:col-span-3">
            <h3 class="font-semibold mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Cari Dokumen
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">Prefix</label>
                    <input type="text" name="prefix" value="<?= e($search_prefix) ?>" required placeholder="Contoh: TRF2407"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium">Document</label>
                    <input type="text" name="document" value="<?= e($search_document) ?>" required placeholder="Contoh: TRF-LOG-BJ"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Cek Nomor Saat Ini
                </button>
                <?php if($search_prefix !== '' || $search_document !== ''): ?>
                <a href="fix_transfer_bj" class="inline-flex w-full items-center justify-center rounded-md text-muted-foreground hover:bg-muted border border-transparent hover:border-border transition-colors h-9 text-sm">Reset</a>
                <?php endif; ?>
            </form>

            <div class="rounded-md bg-muted/50 border p-3 mt-4">
                <p class="text-xs text-muted-foreground leading-relaxed">
                    <b>Cara Pakai:</b><br>
                    1. Tanyakan nomor terakhir yang benar kepada user.<br>
                    2. Cari prefix & document di form ini.<br>
                    3. Bandingkan nomor saat ini dengan yang seharusnya.<br>
                    4. Ubah ke nomor yang benar.
                </p>
            </div>
        </div>

        <!-- Hasil & Form Edit -->
        <div class="md:col-span-8 lg:col-span-9 space-y-4">
            <?php if($found_data): ?>
                <?php foreach($found_data as $row): ?>
                <div class="rounded-lg border bg-card shadow-sm overflow-hidden">
                    <div class="p-5 border-b bg-muted/20">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider">Prefix / Document</p>
                                <p class="text-lg font-bold font-mono mt-1"><?= e(trim($row['fv_prefix'])) ?> <span class="text-muted-foreground">/</span> <?= e(trim($row['fv_document'])) ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-muted-foreground font-medium uppercase tracking-wider">Nomor Saat Ini</p>
                                <p class="text-3xl font-bold font-mono text-primary mt-1"><?= e(trim($row['fn_docno'])) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="p-5">
                        <form method="POST" onsubmit="return confirm('Ubah nomor dokumen dari <?= e(trim($row['fn_docno'])) ?> menjadi ' + this.new_docno.value + '? Pastikan nomor sudah benar.')" class="flex flex-col sm:flex-row items-end gap-4">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="prefix" value="<?= e(trim($row['fv_prefix'])) ?>">
                            <input type="hidden" name="document" value="<?= e(trim($row['fv_document'])) ?>">
                            <input type="hidden" name="old_docno" value="<?= e(trim($row['fn_docno'])) ?>">

                            <div class="space-y-1.5 flex-1 w-full">
                                <label class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Ubah Nomor Menjadi</label>
                                <input type="number" name="new_docno" required placeholder="Masukkan nomor yang benar" value="<?= e(trim($row['fn_docno'])) ?>"
                                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring font-mono text-lg font-bold">
                            </div>

                            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2 whitespace-nowrap w-full sm:w-auto">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                Update Nomor
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
            <div class="rounded-lg border bg-card shadow-sm p-12 flex flex-col items-center justify-center text-center text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="mb-4 opacity-50"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                <p>Masukkan Prefix dan Document untuk mencari nomor dokumen yang perlu dikoreksi.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

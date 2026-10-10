<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('ubah_top_duedate');

$success = null;
$error = null;
$raw_input = $_GET['nomor'] ?? '';
$data_preview = [];

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

function parseInput($raw) {
    $parts = preg_split('/[\s,]+/', $raw);
    $clean = [];
    foreach ($parts as $p) {
        $p = strtoupper(trim($p));
        if ($p !== '') $clean[] = $p;
    }
    return array_unique($clean);
}

// 1. Fetch Preview Data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $raw_input !== '') {
    $list_nomor = parseInput($raw_input);
    if (!empty($list_nomor) && isset($pdo_ms)) {
        try {
            $placeholders = implode(',', array_fill(0, count($list_nomor), '?'));
            
            // Kita cari dari t_dotmpmst karena ini tabel jembatan yang punya ketiga nomor (OPF, SJF, FKF/INVNOTEMP)
            // Logika: Cari kalau nomor ada di fc_sono, ATAU fc_sjpno, ATAU fc_invnotemp
            // Agar query optimal di SQL Server jadul, kita pakai query terpisah lalu UNION atau digabung di PHP.
            // Lebih baik pakai 3 klausa LIKE/OR karena jumlah parameter tidak bisa ditebak mudah pakai IN untuk multi-kolom di PDO.
            
            $sql_find = "SELECT fc_sono AS opf, fc_sjpno AS sjf, fc_invnotemp AS fkf, fn_payterm, fd_tglbarangditerima 
                         FROM d_transaksi..t_dotmpmst 
                         WHERE fc_sono IN ($placeholders) 
                            OR fc_sjpno IN ($placeholders) 
                            OR fc_invnotemp IN ($placeholders)";
            
            // Parameternya diulang 3x karena 3 klausa IN
            $params = array_merge($list_nomor, $list_nomor, $list_nomor);
            $stmtFind = $pdo_ms->prepare($sql_find);
            $stmtFind->execute($params);
            $found_docs = $stmtFind->fetchAll();

            if (empty($found_docs)) {
                $error = "Tidak ada dokumen yang ditemukan terkait nomor yang diinput (OPF/SJF/FKF tidak terdeteksi).";
            } else {
                // Untuk setiap baris, kita tarik DueDate dari t_invmst jika ada FKF nya
                $preview_results = [];
                foreach ($found_docs as $doc) {
                    $opf = trim($doc['opf'] ?? '');
                    $sjf = trim($doc['sjf'] ?? '');
                    $fkf = trim($doc['fkf'] ?? '');
                    $top = trim($doc['fn_payterm'] ?? '');
                    $tgl_terima = trim($doc['fd_tglbarangditerima'] ?? '');
                    $duedate = '';

                    if ($fkf !== '') {
                        $stmtInv = $pdo_ms->prepare("SELECT fd_duedate FROM d_transaksi..t_invmst WHERE fc_invno = ?");
                        $stmtInv->execute([$fkf]);
                        $inv = $stmtInv->fetch();
                        if ($inv && $inv['fd_duedate']) {
                            $duedate = date('Y-m-d', strtotime($inv['fd_duedate']));
                        }
                    }

                    if ($tgl_terima !== '') {
                        $tgl_terima = date('Y-m-d', strtotime($tgl_terima));
                    }

                    $preview_results[] = [
                        'opf' => $opf,
                        'sjf' => $sjf,
                        'fkf' => $fkf,
                        'top' => $top,
                        'tgl_terima' => $tgl_terima,
                        'duedate' => $duedate
                    ];
                }

                $data_preview = $preview_results;
                // Simpan ke session untuk dieksekusi
                $_SESSION['top_valid_data'] = $data_preview;
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data preview: " . $e->getMessage();
        }
    }
}

// 2. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $target_top = trim($_POST['target_top'] ?? '');
    $target_duedate = trim($_POST['target_duedate'] ?? ''); 
    $valid_data = $_SESSION['top_valid_data'] ?? [];
    
    if (empty($valid_data)) {
        $error = "Data sesi tidak valid atau kosong. Lakukan pencarian/preview ulang.";
    } elseif ($target_top === '' || $target_duedate === '') {
        $error = "TOP dan Due Date wajib diisi keduanya.";
    } elseif (isset($pdo_ms)) {
        try {
            $pdo_ms->beginTransaction();
            $log_updated = [];

            // Format duedate sesuai SQL Server
            $formatted_duedate = date('Y-m-d 00:00:00.000', strtotime($target_duedate));

            foreach ($valid_data as $data) {
                $opf = $data['opf'];
                $sjf = $data['sjf'];
                $fkf = $data['fkf'];

                // 1. UPDATE t_sotmpdtl (By OPF)
                if ($opf !== '') {
                    $pdo_ms->prepare("UPDATE d_transaksi..t_sotmpdtl SET fn_payterm = ? WHERE fc_sono = ?")->execute([$target_top, $opf]);
                }

                if ($sjf !== '') {
                    // 2. UPDATE t_sodtl (By SJF)
                    $pdo_ms->prepare("UPDATE d_transaksi..t_sodtl SET fn_payterm = ? WHERE fc_sono = ?")->execute([$target_top, $sjf]);
                    // 3. UPDATE t_sodtlconf (By SJF)
                    $pdo_ms->prepare("UPDATE d_transaksi..t_sodtlconf SET fn_payterm = ? WHERE fc_sono = ?")->execute([$target_top, $sjf]);
                }

                // 4. UPDATE t_dotmpmst (By OPF AND SJF)
                if ($opf !== '' && $sjf !== '') {
                    $pdo_ms->prepare("UPDATE d_transaksi..t_dotmpmst SET fn_payterm = ? WHERE fc_sono = ? AND fc_sjpno = ?")->execute([$target_top, $opf, $sjf]);
                }

                if ($fkf !== '') {
                    // 5. UPDATE t_invmst (By FKF) - Ubah DueDate dan TOP
                    $pdo_ms->prepare("UPDATE d_transaksi..t_invmst SET fd_duedate = ?, fn_payterm = ? WHERE fc_invno = ?")->execute([$formatted_duedate, $target_top, $fkf]);
                    // 6. UPDATE t_arblc1 (By FKF) - Ubah DueDate saja
                    $pdo_ms->prepare("UPDATE d_transaksi..t_arblc1 SET fd_duedate = ? WHERE fc_docno = ?")->execute([$formatted_duedate, $fkf]);
                }

                // Kumpulkan log
                $log_updated[] = "OPF:$opf|SJF:$sjf|FKF:$fkf";
            }

            $pdo_ms->commit();
            
            $success = "Proses update berhasil! " . count($log_updated) . " set dokumen telah diupdate (TOP = $target_top, DueDate = $target_duedate).";
            write_security_log('info', "Update TOP/DueDate. Target TOP: $target_top, DueDate: $target_duedate. Dokumen: " . implode(', ', $log_updated));
            
            // Kosongkan session dan form
            unset($_SESSION['top_valid_data']);
            $data_preview = [];
            $raw_input = '';

        } catch (PDOException $e) {
            $pdo_ms->rollBack();
            $error = "Gagal memproses update: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Ubah TOP & Due Date</h1>
            <p class="text-sm text-muted-foreground">Otomatis update status TOP & DueDate pada 6 tabel terkait (t_sotmpdtl, t_sodtl, t_sodtlconf, t_dotmpmst, t_invmst, t_arblc1).</p>
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

    <div class="grid gap-6 md:grid-cols-3 items-start">
        
        <!-- Form Input & Pencarian -->
        <div class="rounded-lg border bg-card shadow-sm p-5 md:col-span-1">
            <h3 class="font-semibold mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Pencarian Dokumen
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">Nomor OPF / SJF / FKF</label>
                    <textarea name="nomor" rows="5" required placeholder="Contoh:&#10;OPF240703333&#10;SJF240703640&#10;FKF240703640"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase resize-y"><?= e($raw_input) ?></textarea>
                    <p class="text-xs text-muted-foreground">Masukkan satu atau lebih nomor dokumen (dipisah koma/enter). Sistem otomatis mencari relasinya.</p>
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Preview & Cek Relasi
                </button>
            </form>
        </div>

        <!-- Tabel Preview & Aksi Update -->
        <div class="rounded-lg border bg-card shadow-sm md:col-span-2 overflow-hidden flex flex-col h-full">
            <div class="p-5 border-b flex items-center justify-between bg-muted/20">
                <h3 class="font-semibold">Relasi Dokumen</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($data_preview) ?> set ditemukan</span>
            </div>
            
            <div class="overflow-y-auto max-h-[400px]">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                        <tr>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Set Dokumen (OPF/SJF/FKF)</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground">TOP (Hari)</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground">Tgl Terima</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground">Due Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($data_preview)): ?>
                        <tr><td colspan="4" class="h-24 text-center text-muted-foreground">Belum ada data di-preview. Silakan lakukan pencarian di sebelah kiri.</td></tr>
                        <?php endif; ?>
                        
                        <?php foreach($data_preview as $idx => $r): ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3">
                                <div class="space-y-1 font-mono text-xs">
                                    <div class="flex justify-between items-center"><span class="text-muted-foreground">OPF:</span> <span class="font-medium <?= $r['opf'] ? '' : 'text-destructive' ?>"><?= $r['opf'] ?: 'N/A' ?></span></div>
                                    <div class="flex justify-between items-center"><span class="text-muted-foreground">SJF:</span> <span class="font-medium <?= $r['sjf'] ? '' : 'text-destructive' ?>"><?= $r['sjf'] ?: 'N/A' ?></span></div>
                                    <div class="flex justify-between items-center"><span class="text-muted-foreground">FKF:</span> <span class="font-medium <?= $r['fkf'] ? '' : 'text-destructive' ?>"><?= $r['fkf'] ?: 'N/A' ?></span></div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center rounded border px-2 py-0.5 text-sm font-bold bg-muted/50">
                                    <?= e($r['top']) ?: '-' ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center text-muted-foreground text-xs font-medium">
                                <?= $r['tgl_terima'] ? date('d/m/Y', strtotime($r['tgl_terima'])) : '-' ?>
                            </td>
                            <td class="px-4 py-3 text-center font-bold text-xs">
                                <span class="<?= $r['duedate'] ? 'text-primary' : 'text-muted-foreground' ?>">
                                    <?= $r['duedate'] ? date('d/m/Y', strtotime($r['duedate'])) : '-' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if(!empty($data_preview)): ?>
            <div class="p-5 border-t mt-auto bg-muted/20">
                <form method="POST" class="flex flex-col sm:flex-row items-end gap-4" onsubmit="return confirm('Apakah Anda yakin ingin UPDATE KETIGA DOKUMEN (OPF, SJF, FKF) pada 6 tabel di database d_transaksi?')">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="space-y-1.5 flex-1 w-full">
                        <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Ubah TOP (Hari) Ke</label>
                        <input type="number" name="target_top" required placeholder="Contoh: 45"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    </div>

                    <div class="space-y-1.5 flex-1 w-full">
                        <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Ubah Due Date Ke</label>
                        <input type="date" name="target_duedate" required
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring text-center">
                    </div>

                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2 whitespace-nowrap w-full sm:w-auto">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        Eksekusi 6 Tabel
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('ubah_tgl_diterima');

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
    return array_values(array_unique($clean));
}

// 1. Fetch Preview Data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $raw_input !== '') {
    $list_nomor = parseInput($raw_input);
    if (!empty($list_nomor) && isset($pdo_ms)) {
        try {
            $placeholders = implode(',', array_fill(0, count($list_nomor), '?'));

            $sql_find = "SELECT fc_sono, fc_sjpno, fc_invno, fc_invnotemp, fc_custcode, fc_shiptoname, fd_tglbarangditerima 
                         FROM d_transaksi..t_dotmpmst 
                         WHERE fc_sono IN ($placeholders) 
                            OR fc_sjpno IN ($placeholders) 
                            OR fc_invnotemp IN ($placeholders)";

            $params = array_merge($list_nomor, $list_nomor, $list_nomor);
            $stmtFind = $pdo_ms->prepare($sql_find);
            $stmtFind->execute($params);
            $found_docs = $stmtFind->fetchAll();

            if (empty($found_docs)) {
                $error = "Tidak ada dokumen yang ditemukan terkait nomor yang diinput (OPF/SJF/FKF tidak terdeteksi).";
            } else {
                $preview_results = [];
                foreach ($found_docs as $doc) {
                    $sono = trim($doc['fc_sono'] ?? '');
                    $sjpno = trim($doc['fc_sjpno'] ?? '');
                    $fkf = trim($doc['fc_invnotemp'] ?? '');
                    $invno = trim($doc['fc_invno'] ?? '');
                    $custcode = trim($doc['fc_custcode'] ?? '');
                    $shipto = trim($doc['fc_shiptoname'] ?? '');
                    $tgl_terima = trim($doc['fd_tglbarangditerima'] ?? '');

                    if ($tgl_terima !== '') {
                        $tgl_terima = date('Y-m-d', strtotime($tgl_terima));
                    }

                    $preview_results[] = [
                        'fc_sono' => $sono,
                        'fc_sjpno' => $sjpno,
                        'fc_invnotemp' => $fkf,
                        'fc_invno' => $invno,
                        'fc_custcode' => $custcode,
                        'fc_shiptoname' => $shipto,
                        'fd_tglbarangditerima' => $tgl_terima
                    ];
                }

                $data_preview = $preview_results;
                $_SESSION['tgl_diterima_valid_data'] = $data_preview;
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data preview: " . $e->getMessage();
        }
    }
}

// 2. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $target_date = trim($_POST['target_date'] ?? '');
    $valid_data = $_SESSION['tgl_diterima_valid_data'] ?? [];

    if (empty($valid_data)) {
        $error = "Data sesi tidak valid atau kosong. Lakukan pencarian/preview ulang.";
    } elseif ($target_date === '') {
        $error = "Tanggal Diterima wajib dipilih.";
    } elseif (isset($pdo_ms)) {
        try {
            $pdo_ms->beginTransaction();
            $log_updated = [];
            $formatted_date = date('Y-m-d 00:00:00.000', strtotime($target_date));

            $stmtUpdate = $pdo_ms->prepare("UPDATE d_transaksi..t_dotmpmst SET fd_tglbarangditerima = ? WHERE fc_sono = ? AND fc_sjpno = ?");

            foreach ($valid_data as $data) {
                $sono = $data['fc_sono'];
                $sjpno = $data['fc_sjpno'];

                if ($sono !== '' && $sjpno !== '') {
                    $stmtUpdate->execute([$formatted_date, $sono, $sjpno]);
                    $log_updated[] = "OPF:$sono|SJF:$sjpno";
                }
            }

            $pdo_ms->commit();

            $success = "Proses update berhasil! " . count($log_updated) . " set dokumen telah diupdate tanggal diterimanya ke " . date('d/m/Y', strtotime($target_date)) . ".";
            write_security_log('info', "Ubah Tanggal Diterima t_dotmpmst ke $target_date. Dokumen: " . implode(', ', $log_updated));

            // Refresh preview
            $placeholders = implode(',', array_fill(0, count($valid_data), '?'));
            $sql_refresh = "SELECT fc_sono, fc_sjpno, fc_invno, fc_invnotemp, fc_custcode, fc_shiptoname, fd_tglbarangditerima 
                            FROM d_transaksi..t_dotmpmst 
                            WHERE fc_sono IN ($placeholders)";
            $refresh_sonos = array_column($valid_data, 'fc_sono');
            $stmtRef = $pdo_ms->prepare($sql_refresh);
            $stmtRef->execute($refresh_sonos);
            $found_docs = $stmtRef->fetchAll();

            $data_preview = [];
            foreach ($found_docs as $doc) {
                $tgl_terima = trim($doc['fd_tglbarangditerima'] ?? '');
                if ($tgl_terima !== '') $tgl_terima = date('Y-m-d', strtotime($tgl_terima));
                $data_preview[] = [
                    'fc_sono' => trim($doc['fc_sono'] ?? ''),
                    'fc_sjpno' => trim($doc['fc_sjpno'] ?? ''),
                    'fc_invnotemp' => trim($doc['fc_invnotemp'] ?? ''),
                    'fc_invno' => trim($doc['fc_invno'] ?? ''),
                    'fc_custcode' => trim($doc['fc_custcode'] ?? ''),
                    'fc_shiptoname' => trim($doc['fc_shiptoname'] ?? ''),
                    'fd_tglbarangditerima' => $tgl_terima
                ];
            }
            $_SESSION['tgl_diterima_valid_data'] = $data_preview;

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
            <h1 class="text-2xl font-semibold tracking-tight">Ubah Tanggal Barang Diterima</h1>
            <p class="text-sm text-muted-foreground">Ubah kolom <code class="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">fd_tglbarangditerima</code> pada tabel <code class="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">d_transaksi..t_dotmpmst</code> berdasarkan OPF / SJF / FKF.</p>
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
                    <textarea name="nomor" rows="5" required placeholder="Contoh:&#10;OPF261002203&#10;SJF261002282&#10;FKF261002282"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase resize-y"><?= e($raw_input) ?></textarea>
                    <p class="text-xs text-muted-foreground">Bisa memasukkan banyak nomor sekaligus (pisahkan dengan koma atau baris baru).</p>
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Cari Dokumen
                </button>
            </form>
        </div>

        <!-- Tabel Preview & Aksi Update -->
        <div class="rounded-lg border bg-card shadow-sm md:col-span-2 overflow-hidden flex flex-col h-full">
            <div class="p-5 border-b flex items-center justify-between bg-muted/20">
                <h3 class="font-semibold">Relasi Dokumen</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($data_preview) ?> dokumen ditemukan</span>
            </div>

            <div class="overflow-y-auto max-h-[420px]">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                        <tr>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Dokumen (OPF/SJF/FKF)</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Customer</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground">Tgl Terima Saat Ini</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($data_preview)): ?>
                        <tr><td colspan="3" class="h-24 text-center text-muted-foreground">Belum ada data di-preview. Silakan lakukan pencarian di sebelah kiri.</td></tr>
                        <?php endif; ?>

                        <?php foreach($data_preview as $idx => $r): ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3">
                                <div class="space-y-1 font-mono text-xs">
                                    <div class="flex justify-between items-center"><span class="text-muted-foreground">OPF:</span> <span class="font-medium"><?= e($r['fc_sono']) ?: '-' ?></span></div>
                                    <div class="flex justify-between items-center"><span class="text-muted-foreground">SJF:</span> <span class="font-medium"><?= e($r['fc_sjpno']) ?: '-' ?></span></div>
                                    <div class="flex justify-between items-center"><span class="text-muted-foreground">FKF:</span> <span class="font-medium text-muted-foreground"><?= e($r['fc_invnotemp']) ?: '-' ?></span></div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono font-medium text-xs bg-muted/60 px-1.5 py-0.5 rounded"><?= e($r['fc_custcode']) ?: '-' ?></span>
                                <?php if($r['fc_shiptoname']): ?>
                                    <div class="text-xs text-muted-foreground mt-1 truncate max-w-xs"><?= e($r['fc_shiptoname']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if(!empty($r['fd_tglbarangditerima'])): ?>
                                    <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20">
                                        <?= date('d/m/Y', strtotime($r['fd_tglbarangditerima'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20">
                                        Belum Ada (NULL)
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if(!empty($data_preview)): ?>
            <div class="p-5 border-t mt-auto bg-muted/20">
                <form method="POST" class="flex flex-col sm:flex-row items-end gap-4" onsubmit="return confirm('Apakah Anda yakin ingin UPDATE Tanggal Barang Diterima untuk semua dokumen di atas?')">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="space-y-1.5 flex-1 w-full">
                        <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Ubah Tanggal Barang Diterima Ke</label>
                        <input type="date" name="target_date" required value="<?= date('Y-m-d') ?>"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring text-center">
                    </div>

                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2 whitespace-nowrap w-full sm:w-auto">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        Update Tanggal
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

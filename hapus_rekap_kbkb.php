<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('hapus_rekap_kbkb');

$success = null;
$error = null;
$raw_input = $_GET['nomor'] ?? '';
$data_preview = [];

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// Parsing raw input to array of valid numbers
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
            $stmt = $pdo_ms->prepare("SELECT fc_sjpno as no_dokumen, fc_kbkbrekap FROM d_transaksi..t_dotmpmst WHERE fc_sjpno IN ($placeholders)");
            $stmt->execute($list_nomor);
            $data_preview = $stmt->fetchAll();
            
            if (empty($data_preview)) {
                $error = "Tidak ada satupun nomor SJF yang ditemukan di database.";
            } else {
                $_SESSION['kbkb_valid_ids'] = array_column($data_preview, 'no_dokumen');
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data preview: " . $e->getMessage();
        }
    }
}

// 2. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $target_status = $_POST['target_status'] ?? ''; // YES atau NULL
    $valid_ids = $_SESSION['kbkb_valid_ids'] ?? [];
    
    if (empty($valid_ids)) {
        $error = "Data sesi tidak valid atau kosong. Lakukan pencarian/preview ulang.";
    } elseif ($target_status === '') {
        $error = "Pilih aksi yang diinginkan (Ubah ke YES atau Kosongkan).";
    } elseif (isset($pdo_ms)) {
        try {
            $placeholders = implode(',', array_fill(0, count($valid_ids), '?'));
            
            if ($target_status === 'NULL') {
                $sql = "UPDATE d_transaksi..t_dotmpmst SET fc_kbkbrekap = NULL WHERE fc_sjpno IN ($placeholders)";
                $msg_status = "KOSONG / Dihapus";
            } else {
                $sql = "UPDATE d_transaksi..t_dotmpmst SET fc_kbkbrekap = 'YES' WHERE fc_sjpno IN ($placeholders)";
                $msg_status = "YES / Sudah Rekap";
            }
            
            $stmt = $pdo_ms->prepare($sql);
            $stmt->execute($valid_ids);
            $affected = $stmt->rowCount();
            
            $success = "Proses update berhasil! $affected SJF telah diubah status KBKB-nya menjadi $msg_status.";
            write_security_log('info', "Update Rekap KBKB. Target: $target_status, IDs: " . implode(',', $valid_ids));
            
            // Refresh preview
            $stmt = $pdo_ms->prepare("SELECT fc_sjpno as no_dokumen, fc_kbkbrekap FROM d_transaksi..t_dotmpmst WHERE fc_sjpno IN ($placeholders)");
            $stmt->execute($valid_ids);
            $data_preview = $stmt->fetchAll();

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
            <h1 class="text-2xl font-semibold tracking-tight">Status Rekap KBKB</h1>
            <p class="text-sm text-muted-foreground">Ubah status fc_kbkbrekap pada Surat Jalan (SJF) menjadi YES atau dikosongkan.</p>
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
        
        <!-- Form Pencarian -->
        <div class="rounded-lg border bg-card shadow-sm p-5 md:col-span-1">
            <h3 class="font-semibold mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Pencarian SJF
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">Nomor Surat Jalan (SJF)</label>
                    <textarea name="nomor" rows="5" required placeholder="Contoh:&#10;SJF240900791&#10;SJF240900792"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase resize-y"><?= e($raw_input) ?></textarea>
                    <p class="text-xs text-muted-foreground">Bisa memasukkan banyak nomor sekaligus, pisahkan dengan koma atau enter.</p>
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Cek Status KBKB
                </button>
            </form>

            <div class="rounded-md bg-muted/50 border p-3 mt-4">
                <p class="text-xs text-muted-foreground leading-relaxed">
                    <b>Informasi:</b><br>
                    Jika sudah di-rekap, kolom akan berisi <code>YES</code>. Cek SJ di menu gudang produk jadi = penggajian driver.<br>
                    Anda bisa mengosongkan status ini (menjadi NULL) atau mengaturnya kembali ke YES secara massal.
                </p>
            </div>
        </div>

        <!-- Tabel Preview & Update -->
        <div class="rounded-lg border bg-card shadow-sm md:col-span-2 overflow-hidden flex flex-col h-full">
            <div class="p-5 border-b flex items-center justify-between bg-muted/20">
                <h3 class="font-semibold">Data Ditemukan</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($data_preview) ?> SJF</span>
            </div>
            
            <div class="overflow-y-auto max-h-[400px]">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                        <tr>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12">#</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Nomor Surat Jalan</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground">Status KBKB (fc_kbkbrekap)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($data_preview)): ?>
                        <tr><td colspan="3" class="h-24 text-center text-muted-foreground">Belum ada data di-preview. Silakan lakukan pencarian di sebelah kiri.</td></tr>
                        <?php endif; ?>
                        
                        <?php foreach($data_preview as $idx => $r): ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-2 text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                            <td class="px-4 py-2 font-mono font-medium"><?= e($r['no_dokumen']) ?></td>
                            <td class="px-4 py-2 text-center">
                                <?php $kbkb = trim($r['fc_kbkbrekap'] ?? ''); ?>
                                <?php if($kbkb === 'YES'): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> YES
                                    </span>
                                <?php elseif($kbkb !== ''): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-bold bg-secondary px-2 py-0.5 rounded">
                                        <?= e($kbkb) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-xs text-muted-foreground italic opacity-70">NULL / Kosong</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if(!empty($data_preview)): ?>
            <div class="p-5 border-t mt-auto bg-muted/20">
                <form method="POST" class="flex flex-col sm:flex-row items-end gap-4" onsubmit="return confirm('Apakah Anda yakin ingin memodifikasi status KBKB untuk <?= count($data_preview) ?> dokumen ini?')">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="space-y-1.5 flex-1 w-full">
                        <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Aksi Massal</label>
                        <select name="target_status" required class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                            <option value="">-- Pilih Aksi --</option>
                            <option value="NULL">Hapus Rekap (Set KOSONG / NULL)</option>
                            <option value="YES">Tandai Sudah Rekap (Set YES)</option>
                        </select>
                    </div>

                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2 whitespace-nowrap w-full sm:w-auto">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        Eksekusi Update
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

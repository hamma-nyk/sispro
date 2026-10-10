<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('edit_jam_peminjaman');

$success = null;
$error = null;
$search = trim($_GET['q'] ?? '');
$edit_data = null;

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS gagal. Error: " . htmlspecialchars($err);
}

// 1. Pagination Params
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;
$total_data = 0;
$total_pages = 1;
$list_peminjaman = [];

// 2. Load Data for Edit Modal
if (isset($_GET['edit']) && isset($pdo_hrms)) {
    try {
        $stmtEdit = $pdo_hrms->prepare("
            SELECT a.key_lend_id, a.borrower, a.returner, a.checkin, a.checkout,
                   COALESCE(b.nmlengkap, a.borrower) as borrower_name,
                   COALESCE(c.nmlengkap, a.returner) as returner_name
            FROM sc_trx.key_lend a
            LEFT JOIN sc_mst.karyawan b ON b.nik = a.borrower
            LEFT JOIN sc_mst.karyawan c ON c.nik = a.returner
            WHERE a.key_lend_id = ?
        ");
        $stmtEdit->execute([(int)$_GET['edit']]);
        $edit_data = $stmtEdit->fetch();
    } catch (PDOException $e) {
        $error = "Gagal mengambil data edit: " . $e->getMessage();
    }
}

// 3. Handle Update (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo_hrms)) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $key_lend_id = (int)($_POST['key_lend_id'] ?? 0);
    $checkin = $_POST['checkin'] ?? '';
    $checkout = $_POST['checkout'] ?? '';

    // Convert empty string back to null if no checkout
    $checkin_val = $checkin !== '' ? date('Y-m-d H:i:s', strtotime($checkin)) : null;
    $checkout_val = $checkout !== '' ? date('Y-m-d H:i:s', strtotime($checkout)) : null;

    if (!$checkin_val) {
        $error = "Waktu Peminjam (Checkin) wajib diisi.";
    } elseif ($key_lend_id > 0) {
        try {
            $stmt = $pdo_hrms->prepare("UPDATE sc_trx.key_lend SET checkin = ?, checkout = ? WHERE key_lend_id = ?");
            $stmt->execute([$checkin_val, $checkout_val, $key_lend_id]);
            
            $success = "Waktu peminjaman kunci berhasil diupdate.";
            write_security_log('info', "Edit Jam Peminjaman Kunci: ID $key_lend_id -> in:$checkin_val out:$checkout_val");
            $edit_data = null; // Tutup modal edit
        } catch (PDOException $e) {
            $error = "Gagal update data peminjaman: " . $e->getMessage();
        }
    }
}

// 4. Fetch Table Data
if (isset($pdo_hrms)) {
    try {
        $sql_base = "
            FROM sc_trx.key_lend a
            LEFT JOIN sc_mst.karyawan b ON b.nik = a.borrower
            LEFT JOIN sc_mst.karyawan c ON c.nik = a.returner
            WHERE 1=1
        ";
        $params = [];

        if ($search !== '') {
            $sql_base .= " AND (a.borrower ILIKE ? OR a.returner ILIKE ? OR b.nmlengkap ILIKE ? OR c.nmlengkap ILIKE ?)";
            $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
        }

        // Hitung total data
        $stmtC = $pdo_hrms->prepare("SELECT COUNT(*) " . $sql_base);
        $stmtC->execute($params);
        $total_data = $stmtC->fetchColumn();
        $total_pages = max(1, ceil($total_data / $limit));

        // Ambil data limit
        $sql_data = "
            SELECT a.key_lend_id, a.borrower, a.returner, a.checkin, a.checkout,
                   b.nmlengkap as borrower_name, c.nmlengkap as returner_name
            $sql_base 
            ORDER BY a.key_lend_id DESC
            LIMIT ? OFFSET ?
        ";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo_hrms->prepare($sql_data);
        $stmt->execute($params);
        $list_peminjaman = $stmt->fetchAll();

    } catch (PDOException $e) {
        $error = "Gagal mengambil daftar peminjaman: " . $e->getMessage();
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Edit Jam Peminjaman Kunci</h1>
            <p class="text-sm text-muted-foreground">Ubah data Checkin (Pinjam) dan Checkout (Kembali).</p>
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

    <div class="rounded-lg border bg-card shadow-sm overflow-hidden flex flex-col">
        <div class="p-5 border-b bg-muted/20 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <h3 class="font-semibold">Log Peminjaman</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= $total_data ?> data</span>
            </div>
            
            <form method="GET" class="flex gap-2 w-full sm:w-80">
                <div class="relative flex-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari NIK atau Nama..."
                        class="flex h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-secondary text-secondary-foreground hover:bg-secondary/80 border px-3 h-9 text-sm font-medium shadow-sm transition-colors">
                    Cari
                </button>
            </form>
        </div>
        
        <div class="overflow-x-auto flex-1">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 border-b">
                    <tr>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12 text-center">ID</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Peminjam (Borrower)</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Dikembalikan Oleh (Returner)</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Waktu Pinjam (Check In)</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Waktu Kembali (Check Out)</th>
                        <th class="h-9 px-4 text-right font-medium text-muted-foreground">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($list_peminjaman)): ?>
                    <tr><td colspan="6" class="h-32 text-center text-muted-foreground">Tidak ada riwayat peminjaman yang ditemukan.</td></tr>
                    <?php endif; ?>
                    
                    <?php foreach($list_peminjaman as $r): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 text-center text-muted-foreground text-xs"><?= e($r['key_lend_id']) ?></td>
                        <td class="px-4 py-3">
                            <span class="font-medium"><?= e($r['borrower_name'] ?? '-') ?></span>
                            <br><span class="font-mono text-xs text-muted-foreground opacity-80"><?= e($r['borrower']) ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <?php if($r['returner']): ?>
                            <span class="font-medium"><?= e($r['returner_name'] ?? '-') ?></span>
                            <br><span class="font-mono text-xs text-muted-foreground opacity-80"><?= e($r['returner']) ?></span>
                            <?php else: ?>
                            <span class="text-xs text-amber-600 dark:text-amber-400 font-medium italic">Belum dikembalikan</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-emerald-600 dark:text-emerald-400">
                            <?= $r['checkin'] ? date('d/m/Y H:i', strtotime($r['checkin'])) : '-' ?>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-blue-600 dark:text-blue-400">
                            <?= $r['checkout'] ? date('d/m/Y H:i', strtotime($r['checkout'])) : '-' ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <?php $qparam = $search !== '' ? '&q='.urlencode($search) : ''; ?>
                            <a href="?edit=<?= $r['key_lend_id'] ?>&page=<?= $page ?><?= $qparam ?>" class="inline-flex items-center justify-center rounded-md border h-8 px-3 text-xs font-medium hover:bg-accent transition-colors gap-1.5 shadow-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                Edit Jam
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if($total_pages > 1): ?>
        <div class="p-4 border-t flex items-center justify-between bg-muted/10">
            <p class="text-xs text-muted-foreground">Halaman <?= $page ?> dari <?= $total_pages ?></p>
            <div class="flex items-center gap-1">
                <?php 
                $qparam = $search !== '' ? '&q='.urlencode($search) : '';
                if ($page > 1): 
                ?>
                    <a href="?page=<?= $page - 1 ?><?= $qparam ?>" class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-background hover:bg-accent text-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></a>
                <?php else: ?>
                    <span class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-muted/50 text-muted-foreground/50 text-sm cursor-not-allowed"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></span>
                <?php endif; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?><?= $qparam ?>" class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-background hover:bg-accent text-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>
                <?php else: ?>
                    <span class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-muted/50 text-muted-foreground/50 text-sm cursor-not-allowed"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Edit -->
<div id="modal-edit" class="<?= $edit_data ? '' : 'hidden' ?> fixed inset-0 z-50 flex items-center justify-center">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal()"></div>
    <div class="relative bg-card border rounded-lg shadow-lg w-full max-w-md mx-4 p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">Koreksi Jam Peminjaman Kunci</h2>
            <button onclick="closeModal()" class="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php if($edit_data): ?>
            <input type="hidden" name="key_lend_id" value="<?= $edit_data['key_lend_id'] ?>">
            <?php endif; ?>

            <div class="rounded-md bg-muted/30 p-3 space-y-2 text-sm border">
                <div class="flex justify-between border-b border-border/50 pb-1.5">
                    <span class="text-muted-foreground">Peminjam:</span>
                    <span class="font-medium text-right"><?= e($edit_data['borrower_name'] ?? '') ?> <br><span class="text-[10px] font-mono opacity-70"><?= e($edit_data['borrower'] ?? '') ?></span></span>
                </div>
                <div class="flex justify-between pt-0.5">
                    <span class="text-muted-foreground">Kembali:</span>
                    <span class="font-medium text-right"><?= e($edit_data['returner_name'] ?? 'Belum') ?> <br><span class="text-[10px] font-mono opacity-70"><?= e($edit_data['returner'] ?? '') ?></span></span>
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-sm font-medium">Checkin Time (Waktu Pinjam)</label>
                <input type="datetime-local" name="checkin" required step="1" 
                    value="<?= $edit_data && $edit_data['checkin'] ? date('Y-m-d\TH:i:s', strtotime($edit_data['checkin'])) : '' ?>"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>

            <div class="space-y-2">
                <label class="text-sm font-medium">Checkout Time (Waktu Kembali)</label>
                <input type="datetime-local" name="checkout" step="1" 
                    value="<?= $edit_data && $edit_data['checkout'] ? date('Y-m-d\TH:i:s', strtotime($edit_data['checkout'])) : '' ?>"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                <p class="text-xs text-muted-foreground">Kosongkan jika kunci belum dikembalikan.</p>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal()" class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-medium hover:bg-accent transition-colors h-9">Batal</button>
                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    Simpan Jam Baru
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('modal-edit').classList.add('hidden');
    // Remove edit parameter from URL cleanly
    const url = new URL(window.location);
    url.searchParams.delete('edit');
    window.history.replaceState({}, '', url);
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php require_once 'includes/footer.php'; ?>

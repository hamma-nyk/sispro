<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('crud_absensi');

$success = null;
$error = null;

$tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-d');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$search_id = trim($_GET['userid'] ?? '');
$limit_data = $_GET['limit'] ?? '10';

// Pagination variables
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$total_data = 0;
$total_pages = 1;
$data_absen_all = [];
$data_absen = [];

if (!isset($pdo_mdb)) {
    $err = $GLOBALS['mdb_error'] ?? 'Unknown Error';
    $error = "Koneksi ke File MS Access Absensi gagal. Pastikan path \\\\192.168.10.10\\Absensi\\C_ABSEN.mdb dapat diakses server PHP dan driver terinstal. Error: " . htmlspecialchars($err);
} else {
    try {
        // Karena permission read MSysObjects di Access MDB secara default terkunci,
        // kita hardcode saja nama tabel standarnya ZKTeco yaitu 'CHECKINOUT'
        $table_name = 'CHECKINOUT';
        
        // Handle Delete
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
            verifyCsrfToken($_POST['csrf_token'] ?? '');
            $uid = $_POST['uid'];
            $time = $_POST['checktime'];
            // Access ODBC format tanggal string standar: 'YYYY-MM-DD HH:mm:ss' biasanya diterima as-is
            $stmtDel = $pdo_mdb->prepare("DELETE FROM $table_name WHERE USERID = ? AND CHECKTIME = ?");
            $stmtDel->execute([$uid, $time]);
            $success = "Data absen berhasil dihapus.";
            write_security_log('warning', "Hapus Absensi MDB: UID $uid pada $time");
        }

        // Fetch Data
        $sql = "SELECT * FROM $table_name WHERE CHECKTIME >= ? AND CHECKTIME <= ?";
        $params = [
            $tgl_awal . ' 00:00:00',
            $tgl_akhir . ' 23:59:59'
        ];
        
        if ($search_id !== '') {
            $sql .= " AND USERID = ?";
            $params[] = $search_id;
        }
        
        $sql .= " ORDER BY CHECKTIME DESC";
        
        $stmt = $pdo_mdb->prepare($sql);
        $stmt->execute($params);
        $data_absen_all = $stmt->fetchAll();
        
        $total_data = count($data_absen_all);
        if ($limit_data === 'all') {
            $limit_num = $total_data > 0 ? $total_data : 1;
            $data_absen = $data_absen_all;
        } else {
            $limit_num = (int)$limit_data;
            if ($limit_num < 1) $limit_num = 10;
            $offset = ($page - 1) * $limit_num;
            $data_absen = array_slice($data_absen_all, $offset, $limit_num);
        }
        $total_pages = ceil($total_data / $limit_num);

    } catch (PDOException $e) {
        $error = "Gagal menjalankan query MS Access: " . $e->getMessage();
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Data Mesin Absen (MDB)</h1>
            <p class="text-sm text-muted-foreground">Baca dan modifikasi data mentah langsung dari file C_ABSEN.mdb.</p>
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
        
        <!-- Filter Pencarian -->
        <div class="rounded-lg border bg-card shadow-sm p-5 md:col-span-4 lg:col-span-3">
            <h3 class="font-semibold mb-4 flex items-center gap-2 text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Filter Absensi
            </h3>
            <form method="GET" class="space-y-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium">Dari Tanggal</label>
                    <input type="date" name="tgl_awal" value="<?= e($tgl_awal) ?>" required
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium">Sampai Tanggal</label>
                    <input type="date" name="tgl_akhir" value="<?= e($tgl_akhir) ?>" required
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium">User ID (Opsional)</label>
                    <input type="text" name="userid" value="<?= e($search_id) ?>" placeholder="Contoh: 12345"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium">Tampilkan Data</label>
                    <select name="limit" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="10" <?= $limit_data === '10' ? 'selected' : '' ?>>10 Baris</option>
                        <option value="20" <?= $limit_data === '20' ? 'selected' : '' ?>>20 Baris</option>
                        <option value="50" <?= $limit_data === '50' ? 'selected' : '' ?>>50 Baris</option>
                        <option value="100" <?= $limit_data === '100' ? 'selected' : '' ?>>100 Baris</option>
                        <option value="all" <?= $limit_data === 'all' ? 'selected' : '' ?>>Semua Data</option>
                    </select>
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-primary text-primary-foreground border-transparent px-4 py-2 text-sm font-medium shadow-sm hover:bg-primary/90 transition-colors h-9 gap-2">
                    Cari Data
                </button>
            </form>
        </div>

        <!-- Tabel Data -->
        <div class="md:col-span-8 lg:col-span-9 rounded-lg border bg-card shadow-sm overflow-hidden flex flex-col h-full">
            <div class="p-5 border-b bg-muted/20 flex items-center justify-between">
                <h3 class="font-semibold">Data Absen Mentah</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background">
                    <?= $total_data ?> data
                </span>
            </div>
            
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-sm whitespace-nowrap">
                    <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                        <tr>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12 text-center">#</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">User ID</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Check Time</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Check Type</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Sensor ID</th>
                            <th class="h-9 px-4 text-right font-medium text-muted-foreground">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($data_absen)): ?>
                        <tr><td colspan="6" class="h-32 text-center text-muted-foreground">Tidak ada data absen ditemukan pada rentang tanggal ini.</td></tr>
                        <?php endif; ?>
                        
                        <?php foreach($data_absen as $idx => $r): ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-2 text-center text-muted-foreground text-xs"><?= ($limit_data === 'all' ? 0 : ($page - 1) * (int)$limit_data) + $idx + 1 ?></td>
                            <td class="px-4 py-2 font-mono font-medium"><?= e($r['USERID'] ?? $r['userid'] ?? '') ?></td>
                            <td class="px-4 py-2 font-mono text-xs"><?= e($r['CHECKTIME'] ?? $r['checktime'] ?? '') ?></td>
                            <td class="px-4 py-2 text-xs"><?= e($r['CHECKTYPE'] ?? $r['checktype'] ?? '') ?></td>
                            <td class="px-4 py-2 text-xs"><?= e($r['SENSORID'] ?? $r['sensorid'] ?? '') ?></td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" onsubmit="return confirm('Hapus permanen log absen ini dari MDB?')">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="uid" value="<?= e($r['USERID'] ?? $r['userid'] ?? '') ?>">
                                    <input type="hidden" name="checktime" value="<?= e($r['CHECKTIME'] ?? $r['checktime'] ?? '') ?>">
                                    <button type="submit" class="inline-flex items-center justify-center rounded-md h-7 px-3 text-xs font-medium text-destructive hover:bg-destructive/10 border border-transparent hover:border-destructive/20 transition-colors">
                                        Hapus
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="p-4 border-t flex items-center justify-between bg-muted/10">
                <p class="text-xs text-muted-foreground">Menampilkan <b><?= count($data_absen) ?></b> dari <b><?= $total_data ?></b> data</p>
                <div class="flex items-center gap-1">
                    <?php 
                    $qparam = "&tgl_awal=".urlencode($tgl_awal)."&tgl_akhir=".urlencode($tgl_akhir)."&userid=".urlencode($search_id)."&limit=".urlencode($limit_data);
                    if ($page > 1): 
                    ?>
                        <a href="?page=<?= $page - 1 ?><?= $qparam ?>" class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-background hover:bg-accent text-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></a>
                    <?php else: ?>
                        <span class="inline-flex items-center justify-center h-8 w-8 rounded-md border bg-muted/50 text-muted-foreground/50 text-sm cursor-not-allowed"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></span>
                    <?php endif; ?>
                    
                    <span class="text-xs font-medium px-2">Halaman <?= $page ?> dari <?= $total_pages ?></span>
                    
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
</div>

<?php require_once 'includes/footer.php'; ?>

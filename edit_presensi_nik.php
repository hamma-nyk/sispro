<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('edit_presensi_nik');

$success = null;
$error = null;
$tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$search_nik = trim($_GET['nik'] ?? '');
$edit_data = null;
$data_preview = [];

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS gagal. Error: " . htmlspecialchars($err);
}

// 1. Fetch Modal Edit Data
if (isset($_GET['edit_tgl']) && isset($_GET['edit_nik']) && isset($pdo_hrms)) {
    try {
        $edit_tgl = trim($_GET['edit_tgl']);
        $edit_nik = trim($_GET['edit_nik']);
        
        $sqlE = "
            SELECT 
                a.nik, k.nmlengkap, a.tgl, a.kdjamkerja, a.shiftke, 
                to_char(a.jam_masuk_absen, 'HH24:MI:ss') as jam_masuk_absen,
                to_char(a.jam_pulang_absen, 'HH24:MI:ss') as jam_pulang_absen,
                to_char(b.jam_masuk, 'HH24:MI:ss') as master_jam_masuk, 
                to_char(b.jam_pulang, 'HH24:MI:ss') as master_jam_pulang
            FROM sc_trx.transready a
            LEFT JOIN sc_mst.jam_kerja b ON a.kdjamkerja = b.kdjam_kerja
            LEFT JOIN sc_mst.karyawan k ON a.nik = k.nik
            WHERE a.nik = ? AND to_char(a.tgl, 'YYYY-MM-DD') = ?
        ";
        $stmtE = $pdo_hrms->prepare($sqlE);
        $stmtE->execute([$edit_nik, $edit_tgl]);
        $edit_data = $stmtE->fetch();
        
        if (!$edit_data) {
            $error = "Data presensi untuk NIK $edit_nik tanggal $edit_tgl tidak ditemukan.";
        }
    } catch (PDOException $e) {
        $error = "Gagal mengambil data edit: " . $e->getMessage();
    }
}

// 2. Handle Update (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo_hrms)) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $upd_nik = trim($_POST['upd_nik'] ?? '');
    $upd_tgl = trim($_POST['upd_tgl'] ?? '');
    $upd_in  = trim($_POST['jam_masuk'] ?? '');
    $upd_out = trim($_POST['jam_pulang'] ?? '');

    if ($upd_nik === '' || $upd_tgl === '') {
        $error = "NIK dan Tanggal wajib ada untuk update.";
    } else {
        try {
            // Null-kan jika kosong. Pastikan stringnya utuh jika ada format
            $val_in = $upd_in === '' ? null : $upd_in;
            $val_out = $upd_out === '' ? null : $upd_out;
            
            $sqlU = "
                UPDATE sc_trx.transready 
                SET jam_masuk_absen = ?, 
                    jam_pulang_absen = ?, 
                    editan = 't'
                WHERE nik = ? AND to_char(tgl, 'YYYY-MM-DD') = ?
            ";
            
            $stmtU = $pdo_hrms->prepare($sqlU);
            // Binding jam sebagai string, PostgreSQL otomatis mengkonversi ke tipe time yg valid
            $stmtU->execute([$val_in, $val_out, $upd_nik, $upd_tgl]);
            
            if ($stmtU->rowCount() > 0) {
                $success = "Presensi NIK $upd_nik pada $upd_tgl berhasil diubah menjadi In: ".($val_in?:'NULL')." Out: ".($val_out?:'NULL').".";
                write_security_log('info', "Edit Presensi: $upd_nik di tgl $upd_tgl -> in:$val_in out:$val_out");
            } else {
                $error = "Tidak ada perubahan data / data tidak ditemukan.";
            }
            $edit_data = null; // Tutup modal
        } catch (PDOException $e) {
            $error = "Gagal memproses update presensi: " . $e->getMessage();
        }
    }
}

// 3. Fetch Preview List
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $search_nik !== '' && isset($pdo_hrms)) {
    try {
        $sqlL = "
            SELECT 
                a.nik, k.nmlengkap, a.tgl, a.kdjamkerja, a.shiftke, 
                to_char(a.jam_masuk_absen, 'HH24:MI:ss') as jam_masuk_absen,
                to_char(a.jam_pulang_absen, 'HH24:MI:ss') as jam_pulang_absen,
                to_char(b.jam_masuk, 'HH24:MI:ss') as master_jam_masuk, 
                to_char(b.jam_pulang, 'HH24:MI:ss') as master_jam_pulang
            FROM sc_trx.transready a
            LEFT JOIN sc_mst.jam_kerja b ON a.kdjamkerja = b.kdjam_kerja
            LEFT JOIN sc_mst.karyawan k ON a.nik = k.nik
            WHERE a.nik = ? AND to_char(a.tgl, 'YYYY-MM-DD') BETWEEN ? AND ?
            ORDER BY a.tgl DESC
        ";
        
        $stmtL = $pdo_hrms->prepare($sqlL);
        $stmtL->execute([$search_nik, $tgl_awal, $tgl_akhir]);
        $data_preview = $stmtL->fetchAll();
        
        if (empty($data_preview) && empty($error)) {
            $error = "Data presensi tidak ditemukan untuk NIK '$search_nik' pada rentang tanggal tersebut.";
        }
    } catch (PDOException $e) {
        $error = "Gagal mengambil data list presensi: " . $e->getMessage();
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Edit Presensi Karyawan</h1>
            <p class="text-sm text-muted-foreground">Koreksi manual jam masuk dan pulang per karyawan di tabel transready (HRMS).</p>
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
                Filter Data
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
                    <label class="text-sm font-medium">NIK Karyawan</label>
                    <input type="text" name="nik" value="<?= e($search_nik) ?>" required placeholder="Contoh: 123456"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Cari Presensi
                </button>
            </form>
        </div>

        <!-- HASIL -->
        <div class="md:col-span-8 lg:col-span-9 rounded-lg border bg-card shadow-sm overflow-hidden flex flex-col h-full min-h-[500px]">
            <div class="p-5 border-b flex items-center justify-between bg-muted/20">
                <h3 class="font-semibold">Log Presensi Karyawan</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($data_preview) ?> record</span>
            </div>
            
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                        <tr class="bg-muted/30">
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12 text-center" rowspan="2">#</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground" rowspan="2">Karyawan</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground" rowspan="2">Tanggal</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground" rowspan="2">Kd.Jam/Shift</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground border-l" colspan="2">Jam Kerja Master</th>
                            <th class="h-9 px-4 text-center font-medium text-primary bg-primary/5 border-l" colspan="2">Aktual Transready</th>
                            <th class="h-9 px-4 text-right font-medium text-muted-foreground border-l" rowspan="2">Aksi</th>
                        </tr>
                        <tr class="bg-muted/10 border-b">
                            <th class="h-8 px-4 text-xs text-left font-medium text-muted-foreground border-l">In</th>
                            <th class="h-8 px-4 text-xs text-left font-medium text-muted-foreground">Out</th>
                            <th class="h-8 px-4 text-xs text-left font-medium text-primary border-l bg-primary/5">In</th>
                            <th class="h-8 px-4 text-xs text-left font-medium text-primary bg-primary/5">Out</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($data_preview)): ?>
                        <tr><td colspan="9" class="h-32 text-center text-muted-foreground">Isi filter di kiri untuk menampilkan data.</td></tr>
                        <?php endif; ?>
                        
                        <?php foreach($data_preview as $idx => $r): ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3 text-center text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                            <td class="px-4 py-3">
                                <span class="font-medium truncate max-w-[150px] inline-block" title="<?= e($r['nmlengkap']) ?>"><?= e($r['nmlengkap']) ?></span>
                                <br><span class="font-mono text-xs text-muted-foreground"><?= e($r['nik']) ?></span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs font-semibold"><?= $r['tgl'] ? date('d/m/Y', strtotime($r['tgl'])) : '-' ?></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium bg-background"><?= e($r['kdjamkerja']) ?></span>
                                <span class="text-xs text-muted-foreground ml-1">S<?= e($r['shiftke']) ?></span>
                            </td>
                            <td class="px-4 py-3 border-l font-mono text-xs text-muted-foreground"><?= e($r['master_jam_masuk'] ?: '-') ?></td>
                            <td class="px-4 py-3 font-mono text-xs text-muted-foreground"><?= e($r['master_jam_pulang'] ?: '-') ?></td>
                            <td class="px-4 py-3 border-l font-mono text-xs font-bold text-primary bg-primary/5"><?= e($r['jam_masuk_absen'] ?: '-') ?></td>
                            <td class="px-4 py-3 font-mono text-xs font-bold text-primary bg-primary/5"><?= e($r['jam_pulang_absen'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-right border-l">
                                <?php $qparam = "&tgl_awal=".urlencode($tgl_awal)."&tgl_akhir=".urlencode($tgl_akhir)."&nik=".urlencode($search_nik); ?>
                                <a href="?edit_nik=<?= e($r['nik']) ?>&edit_tgl=<?= e(date('Y-m-d', strtotime($r['tgl']))) ?><?= $qparam ?>" 
                                   class="inline-flex items-center justify-center rounded-md border h-8 px-3 text-xs font-medium hover:bg-accent transition-colors gap-1.5 shadow-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                    Edit
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- Modal Edit -->
<div id="modal-edit" class="<?= $edit_data ? '' : 'hidden' ?> fixed inset-0 z-50 flex items-center justify-center">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal()"></div>
    <div class="relative bg-card border rounded-lg shadow-lg w-full max-w-md mx-4 p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">Koreksi Transready</h2>
            <button onclick="closeModal()" class="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php if($edit_data): ?>
            <input type="hidden" name="upd_nik" value="<?= e($edit_data['nik']) ?>">
            <input type="hidden" name="upd_tgl" value="<?= e(date('Y-m-d', strtotime($edit_data['tgl']))) ?>">
            <?php endif; ?>

            <div class="rounded-md bg-muted/30 p-3 space-y-2 text-sm border">
                <div class="flex justify-between border-b border-border/50 pb-1.5">
                    <span class="text-muted-foreground">Karyawan:</span>
                    <span class="font-medium text-right"><?= e($edit_data['nmlengkap'] ?? '') ?> <br><span class="text-[10px] font-mono opacity-70"><?= e($edit_data['nik'] ?? '') ?></span></span>
                </div>
                <div class="flex justify-between pt-0.5">
                    <span class="text-muted-foreground">Tanggal Kerja:</span>
                    <span class="font-medium font-mono text-right"><?= $edit_data && $edit_data['tgl'] ? date('d/m/Y', strtotime($edit_data['tgl'])) : '' ?></span>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-xs font-medium text-muted-foreground">Ref Master Masuk</label>
                    <div class="flex h-9 w-full rounded-md border bg-muted/50 px-3 py-1.5 text-sm shadow-sm font-mono opacity-70">
                        <?= e($edit_data['master_jam_masuk'] ?? 'NULL') ?>
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="text-xs font-medium text-muted-foreground">Ref Master Pulang</label>
                    <div class="flex h-9 w-full rounded-md border bg-muted/50 px-3 py-1.5 text-sm shadow-sm font-mono opacity-70">
                        <?= e($edit_data['master_jam_pulang'] ?? 'NULL') ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-sm font-bold text-primary">Jam Masuk</label>
                    <input type="time" name="jam_masuk" step="1" 
                        value="<?= e($edit_data['jam_masuk_absen'] ?? '') ?>"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring font-mono font-medium">
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-bold text-primary">Jam Pulang</label>
                    <input type="time" name="jam_pulang" step="1" 
                        value="<?= e($edit_data['jam_pulang_absen'] ?? '') ?>"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring font-mono font-medium">
                </div>
            </div>
            <p class="text-xs text-muted-foreground text-center">Biarkan kosong bila ingin men-set NULL.</p>

            <div class="flex justify-end gap-2 pt-2 border-t">
                <button type="button" onclick="closeModal()" class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-medium hover:bg-accent transition-colors h-9">Batal</button>
                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('modal-edit').classList.add('hidden');
    // Bersihkan param url
    const url = new URL(window.location);
    url.searchParams.delete('edit_nik');
    url.searchParams.delete('edit_tgl');
    window.history.replaceState({}, '', url);
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php require_once 'includes/footer.php'; ?>

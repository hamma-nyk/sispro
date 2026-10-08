<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('update_tujuan');

$success = null;
$error = null;
$opf_data = null;
$area_results = [];
$search_sono = trim($_GET['sono'] ?? '');
$search_wilayah = trim($_GET['wilayah'] ?? '');

if (!isset($pdo_ms)) {
    $err = $GLOBALS['ms_error'] ?? 'Unknown Error';
    $error = "Koneksi SQL Server gagal. Error: " . htmlspecialchars($err);
}

// 1. Cari OPF
if ($search_sono !== '' && isset($pdo_ms)) {
    try {
        $stmt = $pdo_ms->prepare("SELECT fc_sono, fc_provcode, fc_areacode, fc_citycode, fc_districtcode FROM d_transaksi..t_sotmpmst WHERE CAST(fc_sono AS VARCHAR(8000)) LIKE ?");
        $stmt->execute([$search_sono]);
        $opf_data = $stmt->fetch();
        if (!$opf_data) {
            $error = "Nomor OPF '$search_sono' tidak ditemukan.";
        }
    } catch (PDOException $e) {
        $error = "Gagal mengambil data OPF: " . $e->getMessage();
    }
}

// 2. Cari Wilayah
if ($search_wilayah !== '' && isset($pdo_ms)) {
    try {
        $stmt = $pdo_ms->prepare("
            SELECT d.fc_areacode, d.fc_citycode, d.fc_districtcode, d.fc_districtdesc, 
                   a.fv_areaname
            FROM d_master..t_areadtl d
            JOIN d_master..t_areamst a ON CAST(a.fc_areacode AS VARCHAR(8000)) LIKE CAST(d.fc_areacode AS VARCHAR(8000))
            WHERE CAST(d.fc_districtdesc AS VARCHAR(8000)) LIKE ?
               OR CAST(a.fv_areaname AS VARCHAR(8000)) LIKE ?
               OR CAST(d.fc_areacode AS VARCHAR(8000)) LIKE ?
            ORDER BY d.fc_areacode, d.fc_districtcode
        ");
        $stmt->execute(["%$search_wilayah%", "%$search_wilayah%", "%$search_wilayah%"]);
        $area_results = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Gagal mencari wilayah: " . $e->getMessage();
    }
}

// 3. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo_ms)) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $upd_sono = trim($_POST['sono'] ?? '');
    $upd_areacode = trim($_POST['areacode'] ?? '');
    $upd_citycode = trim($_POST['citycode'] ?? '');
    $upd_districtcode = trim($_POST['districtcode'] ?? '');

    if ($upd_sono === '' || $upd_areacode === '' || $upd_citycode === '' || $upd_districtcode === '') {
        $error = "Semua field tujuan wajib diisi.";
    } else {
        try {
            $sql = "UPDATE d_transaksi..t_sotmpmst SET fc_areacode=?, fc_citycode=?, fc_districtcode=? WHERE CAST(fc_sono AS VARCHAR(8000)) LIKE ?";
            $params = [$upd_areacode, $upd_citycode, $upd_districtcode, $upd_sono];

            $stmt = $pdo_ms->prepare($sql);
            $stmt->execute($params);

            $success = "Tujuan kirim OPF '$upd_sono' berhasil diubah ke area '$upd_areacode', city '$upd_citycode', district '$upd_districtcode'.";
            write_security_log('info', "Update Tujuan: $upd_sono => area:$upd_areacode city:$upd_citycode dist:$upd_districtcode");

            // Refresh OPF data
            $search_sono = $upd_sono;
            $stmtR = $pdo_ms->prepare("SELECT fc_sono, fc_provcode, fc_areacode, fc_citycode, fc_districtcode FROM d_transaksi..t_sotmpmst WHERE CAST(fc_sono AS VARCHAR(8000)) LIKE ?");
            $stmtR->execute([$upd_sono]);
            $opf_data = $stmtR->fetch();
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
            <h1 class="text-2xl font-semibold tracking-tight">Update Tujuan Kirim</h1>
            <p class="text-sm text-muted-foreground">Ubah kode wilayah tujuan pengiriman (Area, City, District) pada dokumen OPF.</p>
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

        <!-- PANEL KIRI: Cari OPF + Info Tujuan Saat Ini -->
        <div class="md:col-span-4 lg:col-span-3 space-y-4">
            <div class="rounded-lg border bg-card shadow-sm p-5">
                <h3 class="font-semibold mb-4 flex items-center gap-2 text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    Cari Dokumen OPF
                </h3>
                <form method="GET" class="space-y-4">
                    <input type="hidden" name="wilayah" value="<?= e($search_wilayah) ?>">
                    <div class="space-y-2">
                        <label class="text-sm font-medium">Nomor OPF</label>
                        <input type="text" name="sono" value="<?= e($search_sono) ?>" required placeholder="Contoh: OPF260502253"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9">
                        Cek Tujuan
                    </button>
                </form>
            </div>

            <?php if($opf_data): ?>
            <div class="rounded-lg border bg-card shadow-sm p-5">
                <h3 class="text-sm font-semibold mb-3">Tujuan Saat Ini</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">OPF</span>
                        <span class="font-mono font-bold"><?= e(trim($opf_data['fc_sono'])) ?></span>
                    </div>
                    <hr>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">Provinsi</span>
                        <code class="bg-muted px-1.5 py-0.5 rounded text-xs font-mono"><?= e(trim($opf_data['fc_provcode'] ?? '-')) ?></code>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">Area</span>
                        <code class="bg-muted px-1.5 py-0.5 rounded text-xs font-mono"><?= e(trim($opf_data['fc_areacode'] ?? '-')) ?></code>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">City</span>
                        <code class="bg-muted px-1.5 py-0.5 rounded text-xs font-mono"><?= e(trim($opf_data['fc_citycode'] ?? '-')) ?></code>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-muted-foreground">District</span>
                        <code class="bg-muted px-1.5 py-0.5 rounded text-xs font-mono"><?= e(trim($opf_data['fc_districtcode'] ?? '-')) ?></code>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- PANEL KANAN: Cari Wilayah + Pilih & Update -->
        <div class="md:col-span-8 lg:col-span-9 space-y-4">

            <!-- Search Wilayah -->
            <div class="rounded-lg border bg-card shadow-sm p-5">
                <h3 class="font-semibold mb-4 flex items-center gap-2 text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-muted-foreground"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    Cari Kode Wilayah Tujuan
                </h3>
                <form method="GET" class="flex gap-2">
                    <input type="hidden" name="sono" value="<?= e($search_sono) ?>">
                    <div class="relative flex-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        <input type="text" name="wilayah" value="<?= e($search_wilayah) ?>" required placeholder="Cari nama wilayah... (contoh: CIANJUR)"
                            class="flex h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
                    </div>
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9">
                        Cari
                    </button>
                </form>
            </div>

            <!-- Hasil Pencarian Wilayah -->
            <?php if(!empty($area_results)): ?>
            <div class="rounded-lg border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b bg-muted/20 flex items-center justify-between">
                    <h3 class="font-semibold text-sm">Hasil Pencarian Wilayah</h3>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($area_results) ?> ditemukan</span>
                </div>
                <div class="overflow-x-auto max-h-[400px] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-muted/80 backdrop-blur border-b">
                            <tr>
                                <th class="h-9 px-4 text-left font-medium text-muted-foreground">Area</th>
                                <th class="h-9 px-4 text-left font-medium text-muted-foreground">Nama Area</th>
                                <th class="h-9 px-4 text-left font-medium text-muted-foreground">City</th>
                                <th class="h-9 px-4 text-left font-medium text-muted-foreground">District</th>
                                <th class="h-9 px-4 text-left font-medium text-muted-foreground">Nama District</th>
                                <?php if($opf_data): ?>
                                <th class="h-9 px-4 text-right font-medium text-muted-foreground">Aksi</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($area_results as $a): ?>
                            <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                                <td class="px-4 py-2 font-mono font-medium text-xs"><?= e(trim($a['fc_areacode'] ?? '')) ?></td>
                                <td class="px-4 py-2 text-xs"><?= e(trim($a['fv_areaname'] ?? '')) ?></td>
                                <td class="px-4 py-2 font-mono text-xs"><?= e(trim($a['fc_citycode'] ?? '')) ?></td>
                                <td class="px-4 py-2 font-mono text-xs"><?= e(trim($a['fc_districtcode'] ?? '')) ?></td>
                                <td class="px-4 py-2 text-xs font-medium"><?= e(trim($a['fc_districtdesc'] ?? '')) ?></td>
                                <?php if($opf_data): ?>
                                <td class="px-4 py-2 text-right">
                                    <form method="POST" onsubmit="return confirm('Update tujuan OPF <?= e(trim($opf_data['fc_sono'])) ?> ke <?= e(trim($a['fc_districtdesc'] ?? '')) ?> (<?= e(trim($a['fc_areacode'])) ?>/<?= e(trim($a['fc_citycode'])) ?>/<?= e(trim($a['fc_districtcode'])) ?>)?')">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="sono" value="<?= e(trim($opf_data['fc_sono'])) ?>">
                                        <input type="hidden" name="areacode" value="<?= e(trim($a['fc_areacode'])) ?>">
                                        <input type="hidden" name="citycode" value="<?= e(trim($a['fc_citycode'])) ?>">
                                        <input type="hidden" name="districtcode" value="<?= e(trim($a['fc_districtcode'])) ?>">
                                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary text-primary-foreground hover:bg-primary/90 h-7 px-3 text-xs font-medium shadow-sm transition-colors gap-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                            Pilih
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php elseif($search_wilayah !== ''): ?>
            <div class="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                Tidak ada wilayah yang cocok dengan "<?= e($search_wilayah) ?>".
            </div>
            <?php else: ?>
            <div class="rounded-lg border bg-card shadow-sm p-12 flex flex-col items-center justify-center text-center text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="mb-4 opacity-50"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <p><?= $opf_data ? 'Cari nama wilayah tujuan baru di atas, lalu klik "Pilih" untuk mengupdate.' : 'Masukkan nomor OPF terlebih dahulu di panel kiri, lalu cari wilayah tujuan.' ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

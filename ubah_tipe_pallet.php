<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('ubah_tipe_pallet');

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
            // Karena parameter dinamis, buat placeholder (?, ?, ?)
            $placeholders = implode(',', array_fill(0, count($list_nomor), '?'));
            
            // Cari di t_dotmpmst (Sebagai SJF)
            // Asumsi: Apapun yang dimasukkan user, kita coba cari di t_dotmpmst dulu.
            $placeholders = implode(',', array_fill(0, count($list_nomor), '?'));
            $stmtSJF = $pdo_ms->prepare("SELECT fc_sjpno as no_dokumen, FC_PALLETBUY, '' as fc_franco, 'SJF' as tipe_dokumen, FC_SONO as opf_terkait FROM d_transaksi..t_dotmpmst WHERE fc_sjpno IN ($placeholders)");
            $stmtSJF->execute($list_nomor);
            $resSJF = $stmtSJF->fetchAll();

            // Kumpulkan OPF terkait dari hasil pencarian SJF
            $opf_terkait_list = [];
            foreach ($resSJF as $row) {
                $opf = trim($row['opf_terkait'] ?? '');
                if ($opf !== '') {
                    $opf_terkait_list[] = $opf;
                }
            }

            // Gabungkan input asli user dengan OPF terkait yang ditemukan (buang duplikat)
            $list_cari_opf = array_unique(array_merge($list_nomor, $opf_terkait_list));

            // Cari di t_sotmpmst (Sebagai OPF)
            $resOPF = [];
            if (!empty($list_cari_opf)) {
                $placeholders_opf = implode(',', array_fill(0, count($list_cari_opf), '?'));
                $stmtOPF = $pdo_ms->prepare("SELECT fc_sono as no_dokumen, FC_PALLETBUY, fc_franco, 'OPF' as tipe_dokumen, '' as opf_terkait FROM d_transaksi..t_sotmpmst WHERE fc_sono IN ($placeholders_opf)");
                $stmtOPF->execute($list_cari_opf);
                $resOPF = $stmtOPF->fetchAll();
            }

            $data_preview = array_merge($resOPF, $resSJF);
            
            if (empty($data_preview)) {
                $error = "Tidak ada satupun nomor dokumen yang ditemukan di database.";
            } else {
                // Simpan format lengkap [tipe_dokumen, no_dokumen] ke session agar aman saat update
                // tidak menebak-nebak tipe dari stringnya
                $_SESSION['pallet_valid_data'] = array_map(function($item) {
                    return ['tipe' => $item['tipe_dokumen'], 'no' => $item['no_dokumen']];
                }, $data_preview);
            }
        } catch (PDOException $e) {
            $error = "Gagal mengambil data preview: " . $e->getMessage();
        }
    }
}

// 2. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $target_pallet = $_POST['target_pallet'] ?? '';
    $target_franco = $_POST['target_franco'] ?? ''; // Khusus OPF
    $valid_data = $_SESSION['pallet_valid_data'] ?? [];
    
    if (empty($valid_data)) {
        $error = "Data sesi tidak valid atau kosong. Lakukan pencarian/preview ulang.";
    } elseif ($target_pallet === '' && $target_franco === '') {
        $error = "Tidak ada perubahan yang dipilih (Pallet / Franco).";
    } elseif (isset($pdo_ms)) {
        try {
            $pdo_ms->beginTransaction();
            $updated_opf = 0;
            $updated_sjf = 0;

            // Pisahkan OPF dan SJF berdasarkan tipe yang sudah dipastikan dari DB saat preview
            $ids_opf = [];
            $ids_sjf = [];
            foreach ($valid_data as $data) {
                if ($data['tipe'] === 'OPF') {
                    $ids_opf[] = $data['no'];
                } elseif ($data['tipe'] === 'SJF') {
                    $ids_sjf[] = $data['no'];
                }
            }

            // Update OPF (bisa ubah Pallet DAN Franco)
            if (!empty($ids_opf)) {
                $ids_opf = array_unique($ids_opf); // prevent duplicate queries
                $placeholders_opf = implode(',', array_fill(0, count($ids_opf), '?'));
                $set_queries = [];
                $params_opf = [];
                
                if ($target_pallet !== '') {
                    $set_queries[] = "FC_PALLETBUY = ?";
                    $params_opf[] = $target_pallet;
                }
                if ($target_franco !== '') {
                    $set_queries[] = "FC_FRANCO = ?";
                    $params_opf[] = $target_franco;
                }
                
                if (!empty($set_queries)) {
                    $sql_opf = "UPDATE d_transaksi..t_sotmpmst SET " . implode(', ', $set_queries) . " WHERE fc_sono IN ($placeholders_opf)";
                    // Gabungkan parameter SET dan WHERE
                    $params_opf = array_merge($params_opf, $ids_opf);
                    
                    $stmt = $pdo_ms->prepare($sql_opf);
                    $stmt->execute($params_opf);
                    $updated_opf = $stmt->rowCount();
                }
            }

            // Update SJF (hanya bisa ubah Pallet)
            if (!empty($ids_sjf) && $target_pallet !== '') {
                $ids_sjf = array_unique($ids_sjf);
                $placeholders_sjf = implode(',', array_fill(0, count($ids_sjf), '?'));
                $sql_sjf = "UPDATE d_transaksi..t_dotmpmst SET FC_PALLETBUY = ? WHERE fc_sjpno IN ($placeholders_sjf)";
                
                $params_sjf = array_merge([$target_pallet], $ids_sjf);
                $stmt = $pdo_ms->prepare($sql_sjf);
                $stmt->execute($params_sjf);
                $updated_sjf = $stmt->rowCount();
            }

            $pdo_ms->commit();
            
            $msg = "Proses update berhasil! ";
            if ($updated_opf > 0) $msg .= "$updated_opf OPF diperbarui. ";
            if ($updated_sjf > 0) $msg .= "$updated_sjf SJF diperbarui. ";
            if ($updated_opf == 0 && $updated_sjf == 0) $msg .= "Tidak ada data yang cocok / diubah.";
            
            $success = $msg;
            $all_ids_log = array_column($valid_data, 'no');
            write_security_log('info', "Update Tipe Pallet massal. Target P: $target_pallet, F: $target_franco, IDs: " . implode(',', $all_ids_log));
            
            // Kosongkan session dan raw_input agar tabel bersih
            unset($_SESSION['pallet_valid_data']);
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
            <h1 class="text-2xl font-semibold tracking-tight">Ubah Tipe Pallet & Franco</h1>
            <p class="text-sm text-muted-foreground">Ubah status Tipe Pallet (C/K) dan tipe Franco (Y/N) secara massal untuk OPF dan SJF.</p>
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
                    <label class="text-sm font-medium">Nomor OPF / SJF</label>
                    <textarea name="nomor" rows="5" required placeholder="Contoh:&#10;OPF240301238&#10;SS2601000001, SS2601000002"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase resize-y"><?= e($raw_input) ?></textarea>
                    <p class="text-xs text-muted-foreground">Bisa memasukkan banyak nomor sekaligus, pisahkan dengan koma atau enter.</p>
                </div>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                    Preview Data Saat Ini
                </button>
            </form>
        </div>

        <!-- Tabel Preview & Aksi Update -->
        <div class="rounded-lg border bg-card shadow-sm md:col-span-2 overflow-hidden flex flex-col h-full">
            <div class="p-5 border-b flex items-center justify-between bg-muted/20">
                <h3 class="font-semibold">Data Ditemukan</h3>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= count($data_preview) ?> dokumen</span>
            </div>
            
            <div class="overflow-y-auto max-h-[400px]">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                        <tr>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">Tipe</th>
                            <th class="h-9 px-4 text-left font-medium text-muted-foreground">No. Dokumen</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground">Pallet Saat Ini</th>
                            <th class="h-9 px-4 text-center font-medium text-muted-foreground">Franco (Khusus OPF)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($data_preview)): ?>
                        <tr><td colspan="4" class="h-24 text-center text-muted-foreground">Belum ada data di-preview. Silakan lakukan pencarian di sebelah kiri.</td></tr>
                        <?php endif; ?>
                        
                        <?php foreach($data_preview as $r): ?>
                        <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center rounded border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider <?= $r['tipe_dokumen'] === 'OPF' ? 'border-blue-500/50 text-blue-600 dark:text-blue-400 bg-blue-500/10' : 'border-amber-500/50 text-amber-600 dark:text-amber-400 bg-amber-500/10' ?>">
                                    <?= e($r['tipe_dokumen']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-2 font-mono font-medium">
                                <?= e($r['no_dokumen']) ?>
                                <?php if($r['tipe_dokumen'] === 'SJF' && !empty($r['opf_terkait'])): ?>
                                    <div class="text-[10px] text-muted-foreground mt-0.5">OPF: <?= e($r['opf_terkait']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 text-center">
                                <?php $pal = trim($r['FC_PALLETBUY'] ?? ''); ?>
                                <?php if($pal === 'C'): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Curah (C)</span>
                                <?php elseif($pal === 'K'): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium"><span class="h-2 w-2 rounded-full bg-indigo-500"></span>Palet (K)</span>
                                <?php else: ?>
                                    <span class="text-xs text-muted-foreground italic"><?= $pal !== '' ? e($pal) : 'Kosong' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 text-center">
                                <?php if($r['tipe_dokumen'] === 'OPF'): ?>
                                    <?php $fran = trim($r['fc_franco'] ?? ''); ?>
                                    <span class="text-xs font-mono font-bold <?= $fran==='Y' ? 'text-emerald-500' : ($fran==='N' ? 'text-rose-500' : 'text-muted-foreground') ?>">
                                        <?= $fran !== '' ? e($fran) : '-' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-xs text-muted-foreground opacity-50">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if(!empty($data_preview)): ?>
            <div class="p-5 border-t mt-auto bg-muted/20">
                <form method="POST" class="flex flex-col sm:flex-row items-end gap-4" onsubmit="return confirm('Apakah Anda yakin ingin memodifikasi data dokumen ini di database transaksi?')">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="space-y-1.5 flex-1 w-full">
                        <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Ubah Tipe Pallet Ke</label>
                        <select name="target_pallet" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                            <option value="">-- Abaikan (Tidak Diubah) --</option>
                            <option value="C">Curah (C)</option>
                            <option value="K">Palet (K)</option>
                        </select>
                    </div>

                    <div class="space-y-1.5 flex-1 w-full">
                        <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Ubah Franco Ke <span class="lowercase normal-case">(OPF Saja)</span></label>
                        <select name="target_franco" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                            <option value="">-- Abaikan (Tidak Diubah) --</option>
                            <option value="Y">Ya (Y)</option>
                            <option value="N">Tidak (N) / Locco</option>
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

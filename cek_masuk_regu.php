<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('cek_masuk_regu');

$error = null;
$tgl_awal = trim($_GET['tgl_awal'] ?? date('Y-m-01'));
$tgl_akhir = trim($_GET['tgl_akhir'] ?? date('Y-m-t'));
$search = trim($_GET['q'] ?? '');
$action = $_GET['action'] ?? 'preview';

$data_preview = [];
$total_data = 0;

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS gagal. Error: " . htmlspecialchars($err);
} else {
    function getMasukReguData($pdo_hrms, $tgl_awal, $tgl_akhir, $search) {
        $sql = "
            SELECT a.kdregu, a.nik, b.nmlengkap, a.tglefektif, a.status 
            FROM sc_mst.regu_opr a 
            LEFT JOIN sc_mst.karyawan b ON a.nik = b.nik 
            WHERE a.status = 'P'
        ";
        $params = [];

        if ($tgl_awal !== '' && $tgl_akhir !== '') {
            $sql .= " AND a.tglefektif BETWEEN ? AND ?";
            $params[] = $tgl_awal;
            $params[] = $tgl_akhir;
        } elseif ($tgl_awal !== '') {
            $sql .= " AND a.tglefektif >= ?";
            $params[] = $tgl_awal;
        } elseif ($tgl_akhir !== '') {
            $sql .= " AND a.tglefektif <= ?";
            $params[] = $tgl_akhir;
        }

        if ($search !== '') {
            $sql .= " AND (a.nik ILIKE ? OR b.nmlengkap ILIKE ? OR a.kdregu ILIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY a.kdregu ASC, a.nik ASC";

        $stmt = $pdo_hrms->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Export CSV / Excel
    if ($action === 'export_csv' || $action === 'export_excel') {
        if (ob_get_length()) ob_end_clean();

        try {
            $stmt = getMasukReguData($pdo_hrms, $tgl_awal, $tgl_akhir, $search);
            $headers = ['Kode Regu', 'NIK', 'Nama Lengkap', 'Tgl Efektif', 'Status'];

            if ($action === 'export_excel') {
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header('Content-Disposition: attachment; filename=Karyawan_Sudah_Masuk_Regu_' . date('Ymd_His') . '.xls');

                $output = fopen('php://output', 'w');
                fwrite($output, '<?xml version="1.0"?>' . "\n");
                fwrite($output, '<?mso-application progid="Excel.Sheet"?>' . "\n");
                fwrite($output, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
                fwrite($output, ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n");
                fwrite($output, '<Worksheet ss:Name="MasukRegu"><Table>' . "\n");

                fwrite($output, '<Row>');
                foreach ($headers as $h) {
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>');
                }
                fwrite($output, '</Row>' . "\n");

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fwrite($output, '<Row>');
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars(trim($row['kdregu'] ?? '')) . '</Data></Cell>');
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars(trim($row['nik'] ?? '')) . '</Data></Cell>');
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars(trim($row['nmlengkap'] ?? '')) . '</Data></Cell>');
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars(trim($row['tglefektif'] ?? '')) . '</Data></Cell>');
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars(trim($row['status'] ?? '')) . '</Data></Cell>');
                    fwrite($output, '</Row>' . "\n");
                }

                fwrite($output, '</Table></Worksheet></Workbook>');
                fclose($output);
                exit;
            }

            if ($action === 'export_csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=Karyawan_Sudah_Masuk_Regu_' . date('Ymd_His') . '.csv');

                $output = fopen('php://output', 'w');
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($output, $headers);

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, [
                        trim($row['kdregu'] ?? ''),
                        trim($row['nik'] ?? ''),
                        trim($row['nmlengkap'] ?? ''),
                        trim($row['tglefektif'] ?? ''),
                        trim($row['status'] ?? '')
                    ]);
                }

                fclose($output);
                exit;
            }

        } catch (PDOException $e) {
            $error = "Gagal export data: " . $e->getMessage();
        }
    }

    // Preview
    try {
        $stmt = getMasukReguData($pdo_hrms, $tgl_awal, $tgl_akhir, $search);
        $data_preview = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total_data = count($data_preview);
    } catch (PDOException $e) {
        $error = "Gagal mengambil data: " . $e->getMessage();
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Karyawan Sudah Masuk Regu</h1>
            <p class="text-sm text-muted-foreground">Monitoring data karyawan yang sudah terdaftar di regu.
        </div>
        <div class="flex items-center gap-2">
            <a href="?action=export_excel&tgl_awal=<?= urlencode($tgl_awal) ?>&tgl_akhir=<?= urlencode($tgl_akhir) ?>&q=<?= urlencode($search) ?>" class="inline-flex items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-3 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-600 dark:text-emerald-400"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
                Excel
            </a>
            <a href="?action=export_csv&tgl_awal=<?= urlencode($tgl_awal) ?>&tgl_akhir=<?= urlencode($tgl_akhir) ?>&q=<?= urlencode($search) ?>" class="inline-flex items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-3 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                CSV
            </a>
        </div>
    </div>

    <?php if($error): ?>
    <div class="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <?= e($error) ?>
    </div>
    <?php endif; ?>

    <!-- Filter Form -->
    <div class="rounded-lg border bg-card shadow-sm p-5">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 items-end">
            <div class="space-y-1.5">
                <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Tgl Efektif Awal</label>
                <input type="date" name="tgl_awal" value="<?= e($tgl_awal) ?>"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>

            <div class="space-y-1.5">
                <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Tgl Efektif Akhir</label>
                <input type="date" name="tgl_akhir" value="<?= e($tgl_akhir) ?>"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>

            <div class="space-y-1.5">
                <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Cari NIK / Nama / Regu</label>
                <input type="text" name="q" placeholder="Cari..." value="<?= e($search) ?>"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>

            <div class="flex gap-2">
                <button type="submit" class="inline-flex flex-1 items-center justify-center rounded-md bg-primary text-primary-foreground text-sm font-medium shadow-sm hover:bg-primary/90 transition-colors h-9 gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    Filter
                </button>
                <a href="cek_masuk_regu" class="inline-flex items-center justify-center rounded-md border px-3 text-sm hover:bg-muted transition-colors h-9">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <div class="rounded-lg border bg-card shadow-sm overflow-hidden flex flex-col">
        <div class="p-5 border-b flex items-center justify-between bg-muted/20">
            <h3 class="font-semibold">Daftar Karyawan Masuk Regu (Status P)</h3>
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= number_format($total_data) ?> Karyawan</span>
        </div>

        <div class="overflow-x-auto max-h-[550px]">
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                    <tr>
                        <th class="h-9 px-4 text-center font-medium text-muted-foreground w-12">#</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Kode Regu</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">NIK</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Nama Lengkap</th>
                        <th class="h-9 px-4 text-center font-medium text-muted-foreground">Tgl Efektif</th>
                        <th class="h-9 px-4 text-center font-medium text-muted-foreground">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($data_preview)): ?>
                    <tr>
                        <td colspan="6" class="h-28 text-center text-muted-foreground">
                            Tidak ada data karyawan masuk regu (status P) yang ditemukan untuk filter tersebut.
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach($data_preview as $idx => $r): 
                        $c_kdregu = trim($r['kdregu'] ?? '');
                        $c_nik = trim($r['nik'] ?? '');
                        $c_nama = trim($r['nmlengkap'] ?? '-');
                        $c_tgl = !empty($r['tglefektif']) ? date('d/m/Y', strtotime($r['tglefektif'])) : '-';
                        $c_status = trim($r['status'] ?? '');
                    ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-2.5 text-center text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                        <td class="px-4 py-2.5 font-bold font-mono text-primary">
                            <span class="bg-primary/10 border border-primary/20 px-2 py-0.5 rounded text-xs"><?= e($c_kdregu) ?></span>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs font-medium"><?= e($c_nik) ?></td>
                        <td class="px-4 py-2.5 font-medium"><?= e($c_nama) ?></td>
                        <td class="px-4 py-2.5 text-center text-xs text-muted-foreground"><?= e($c_tgl) ?></td>
                        <td class="px-4 py-2.5 text-center">
                            <span class="inline-flex items-center text-xs font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded">
                                P
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

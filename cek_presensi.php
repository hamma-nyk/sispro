<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('cek_presensi');

$error = null;
$tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$search_nik = trim($_GET['nik'] ?? '');
$action = $_GET['action'] ?? 'preview';

$data_preview = [];
$total_data = 0;

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS gagal. Error: " . htmlspecialchars($err);
} else {
    function getPresensiData($pdo_hrms, $tgl_awal, $tgl_akhir, $nik) {
        $sql = "
            SELECT 
                a.nik,
                d.nmlengkap,
                a.tgl,
                a.kdjamkerja,
                a.shiftke,
                to_char(a.jam_masuk_absen, 'HH24:MI:ss') as jam_masuk_absen,
                to_char(a.jam_pulang_absen, 'HH24:MI:ss') as jam_pulang_absen,
                to_char(b.jam_masuk, 'HH24:MI:ss') as jam_masuk,
                to_char(b.jam_pulang, 'HH24:MI:ss') as jam_pulang,
                min(to_char(c.checktime, 'HH24:MI:ss')) as real_jammasuk,
                max(to_char(c.checktime, 'HH24:MI:ss')) as real_jampulang
            FROM sc_trx.transready a
            LEFT OUTER JOIN sc_mst.jam_kerja b ON a.kdjamkerja = b.kdjam_kerja
            LEFT OUTER JOIN sc_tmp.checkinout c ON a.nik = c.badgenumber AND to_char(a.tgl, 'yyyy-MM-dd') = to_char(c.checktime, 'yyyy-MM-dd')
            LEFT OUTER JOIN sc_mst.karyawan d ON a.nik = d.nik
            WHERE to_char(a.tgl, 'yyyy-MM-dd') BETWEEN ? AND ?
        ";
        
        $params = [$tgl_awal, $tgl_akhir];

        if ($nik !== '') {
            $sql .= " AND a.nik = ?";
            $params[] = $nik;
        }

        $sql .= " 
            GROUP BY a.nik, d.nmlengkap, a.tgl, a.kdjamkerja, a.shiftke, b.jam_masuk, b.jam_pulang, a.jam_masuk_absen, a.jam_pulang_absen
            ORDER BY a.tgl DESC
        ";

        $stmt = $pdo_hrms->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    if ($action === 'export_csv' || $action === 'export_excel') {
        if (ob_get_length()) ob_end_clean();

        try {
            $stmt = getPresensiData($pdo_hrms, $tgl_awal, $tgl_akhir, $search_nik);
            $headers = ['NIK', 'Nama Lengkap', 'Tgl Transready', 'Kode Jam Kerja', 'Shift Ke', 'Jam Masuk (Master)', 'Jam Pulang (Master)', 'Jam Masuk (Absen/Transready)', 'Jam Pulang (Absen/Transready)', 'Real Jam Masuk (Mesin)', 'Real Jam Pulang (Mesin)'];

            if ($action === 'export_excel') {
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header('Content-Disposition: attachment; filename=Cek_Presensi_' . $tgl_awal . '_to_' . $tgl_akhir . '.xls');

                $output = fopen('php://output', 'w');
                fwrite($output, '<?xml version="1.0"?>' . "\n");
                fwrite($output, '<?mso-application progid="Excel.Sheet"?>' . "\n");
                fwrite($output, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n");
                fwrite($output, '<Worksheet ss:Name="Cek Presensi"><Table>' . "\n");

                fwrite($output, '<Row>' . "\n");
                foreach ($headers as $h) fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>' . "\n");
                fwrite($output, '</Row>' . "\n");

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fwrite($output, '<Row>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['nik']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['nmlengkap']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['tgl']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['kdjamkerja']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['shiftke']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['jam_masuk']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['jam_pulang']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['jam_masuk_absen']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['jam_pulang_absen']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['real_jammasuk']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['real_jampulang']) . '</Data></Cell>' . "\n");
                    fwrite($output, '</Row>' . "\n");
                }

                fwrite($output, '</Table></Worksheet></Workbook>' . "\n");
                fclose($output);
            } else {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=Cek_Presensi_' . $tgl_awal . '_to_' . $tgl_akhir . '.csv');

                $output = fopen('php://output', 'w');
                fputs($output, "\xEF\xBB\xBF");
                fputcsv($output, $headers);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, [
                        $row['nik'], $row['nmlengkap'], $row['tgl'], $row['kdjamkerja'], $row['shiftke'],
                        $row['jam_masuk'], $row['jam_pulang'], $row['jam_masuk_absen'], $row['jam_pulang_absen'],
                        $row['real_jammasuk'], $row['real_jampulang']
                    ]);
                }
                fclose($output);
            }
            exit;

        } catch (PDOException $e) {
            $error = "Gagal export: " . $e->getMessage();
        }
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        try {
            $stmt = getPresensiData($pdo_hrms, $tgl_awal, $tgl_akhir, $search_nik);
            $count = 0;
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) && $count < 100) {
                $data_preview[] = $row;
                $count++;
            }
            $total_data = ($count === 100) ? '100+' : $count;
        } catch (PDOException $e) {
            $error = "Gagal mengambil data: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Cek Presensi</h1>
            <p class="text-sm text-muted-foreground">Bandingkan jam masuk/pulang master, jam transready, dan riil tarikan mesin absen.</p>
        </div>
    </div>

    <?php if($error): ?>
    <div class="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <?= e($error) ?>
    </div>
    <?php endif; ?>

    <div class="rounded-lg border bg-card shadow-sm p-5">
        <form method="GET" class="flex flex-col sm:flex-row items-end gap-4">
            <div class="space-y-2 flex-1 w-full">
                <label class="text-sm font-medium">Tanggal Awal</label>
                <input type="date" name="tgl_awal" value="<?= e($tgl_awal) ?>" required
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="space-y-2 flex-1 w-full">
                <label class="text-sm font-medium">Tanggal Akhir</label>
                <input type="date" name="tgl_akhir" value="<?= e($tgl_akhir) ?>" required
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="space-y-2 flex-1 w-full">
                <label class="text-sm font-medium">NIK Karyawan</label>
                <input type="text" name="nik" value="<?= e($search_nik) ?>" placeholder="Contoh: 123456"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring uppercase">
            </div>

            <button type="submit" name="action" value="preview" class="inline-flex w-full sm:w-auto items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Preview
            </button>
            <button type="submit" name="action" value="export_csv" class="inline-flex w-full sm:w-auto items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium shadow-sm hover:bg-accent hover:text-accent-foreground transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                CSV
            </button>
            <button type="submit" name="action" value="export_excel" class="inline-flex w-full sm:w-auto items-center justify-center rounded-md bg-emerald-600 text-white border-transparent px-4 py-2 text-sm font-medium shadow-sm hover:bg-emerald-700 transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Excel
            </button>
        </form>
    </div>

    <?php if(!empty($data_preview)): ?>
    <div class="rounded-lg border bg-card shadow-sm overflow-hidden flex flex-col">
        <div class="p-5 border-b flex items-center justify-between bg-muted/20">
            <h3 class="font-semibold">Hasil Pencarian</h3>
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= $total_data ?> data</span>
        </div>

        <div class="overflow-x-auto max-h-[500px]">
            <table class="w-full text-sm whitespace-nowrap">
                <thead class="sticky top-0 bg-background/95 backdrop-blur z-10">
                    <tr class="border-b">
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12 text-center" rowspan="2">#</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground" rowspan="2">NIK</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground" rowspan="2">Nama Lengkap</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground" rowspan="2">Tanggal</th>
                        <th class="h-9 px-4 text-center font-medium text-muted-foreground bg-muted/30 border-l" colspan="3">Jadwal Shift</th>
                        <th class="h-9 px-4 text-center font-medium text-muted-foreground bg-primary/5 border-l" colspan="2">Transready (Absen)</th>
                        <th class="h-9 px-4 text-center font-medium text-muted-foreground bg-amber-500/5 border-l" colspan="2">Mesin Real (CheckInOut)</th>
                    </tr>
                    <tr class="border-b bg-muted/10">
                        <th class="h-8 px-4 text-xs text-left font-medium text-muted-foreground border-l">Kode/Shift</th>
                        <th class="h-8 px-4 text-xs text-left font-medium text-muted-foreground">In</th>
                        <th class="h-8 px-4 text-xs text-left font-medium text-muted-foreground">Out</th>
                        
                        <th class="h-8 px-4 text-xs text-left font-medium text-primary border-l">In</th>
                        <th class="h-8 px-4 text-xs text-left font-medium text-primary">Out</th>
                        
                        <th class="h-8 px-4 text-xs text-left font-medium text-amber-600 border-l">In</th>
                        <th class="h-8 px-4 text-xs text-left font-medium text-amber-600">Out</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data_preview as $idx => $r): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-2 text-center text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                        <td class="px-4 py-2 font-mono text-xs font-medium"><?= e($r['nik']) ?></td>
                        <td class="px-4 py-2 font-medium max-w-[200px] truncate" title="<?= e($r['nmlengkap']) ?>"><?= e($r['nmlengkap']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs text-muted-foreground"><?= $r['tgl'] ? date('d/m/Y', strtotime($r['tgl'])) : '-' ?></td>
                        
                        <td class="px-4 py-2 border-l">
                            <span class="inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium"><?= e($r['kdjamkerja']) ?></span>
                            <span class="text-xs text-muted-foreground ml-1">S<?= e($r['shiftke']) ?></span>
                        </td>
                        <td class="px-4 py-2 font-mono text-xs text-muted-foreground"><?= e($r['jam_masuk']) ?: '-' ?></td>
                        <td class="px-4 py-2 font-mono text-xs text-muted-foreground"><?= e($r['jam_pulang']) ?: '-' ?></td>
                        
                        <td class="px-4 py-2 font-mono text-xs font-semibold text-primary border-l"><?= e($r['jam_masuk_absen']) ?: '-' ?></td>
                        <td class="px-4 py-2 font-mono text-xs font-semibold text-primary"><?= e($r['jam_pulang_absen']) ?: '-' ?></td>
                        
                        <td class="px-4 py-2 font-mono text-xs font-bold text-amber-600 dark:text-amber-400 border-l"><?= e($r['real_jammasuk']) ?: '-' ?></td>
                        <td class="px-4 py-2 font-mono text-xs font-bold text-amber-600 dark:text-amber-400"><?= e($r['real_jampulang']) ?: '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>

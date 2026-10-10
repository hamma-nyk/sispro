<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('cek_belum_terjadwal');

$error = null;
// Parameter dari query "TextBox1.Text" format YYYYMM. Di UI kita pakai YYYY-MM
$periode = $_GET['periode'] ?? date('Y-m');
$action = $_GET['action'] ?? 'preview';

$data_preview = [];
$total_data = 0;

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS gagal. Error: " . htmlspecialchars($err);
} else {
    function getBelumTerjadwalData($pdo_hrms, $periode) {
        // Convert YYYY-MM ke YYYYMM
        $periodeYm = str_replace('-', '', $periode);

        $sql = "
            SELECT a.kdregu, a.nik, b.nmlengkap, b.bag_dept, j.nmjabatan
            FROM sc_mst.regu_opr a
            LEFT JOIN sc_mst.karyawan b ON a.nik = b.nik
            LEFT JOIN sc_mst.jabatan j ON b.jabatan = j.kdjabatan
            WHERE a.nik NOT IN (
                SELECT nik FROM sc_trx.dtljadwalkerja 
                WHERE to_char(tgl, 'YYYYMM') = ?
            )
            AND b.statuskepegawaian <> 'KO'
            ORDER BY a.kdregu ASC, a.nik ASC
        ";

        $stmt = $pdo_hrms->prepare($sql);
        $stmt->execute([$periodeYm]);
        return $stmt;
    }

    if ($action === 'export_csv' || $action === 'export_excel') {
        if (ob_get_length()) ob_end_clean();

        try {
            $stmt = getBelumTerjadwalData($pdo_hrms, $periode);
            $headers = ['Kode Regu', 'NIK', 'Nama Lengkap', 'Departemen', 'Jabatan'];

            if ($action === 'export_excel') {
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header('Content-Disposition: attachment; filename=Regu_Belum_Terjadwal_' . $periode . '.xls');

                $output = fopen('php://output', 'w');
                fwrite($output, '<?xml version="1.0"?>' . "\n");
                fwrite($output, '<?mso-application progid="Excel.Sheet"?>' . "\n");
                fwrite($output, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n");
                fwrite($output, '<Worksheet ss:Name="Belum Terjadwal"><Table>' . "\n");

                fwrite($output, '<Row>' . "\n");
                foreach ($headers as $h) fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>' . "\n");
                fwrite($output, '</Row>' . "\n");

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fwrite($output, '<Row>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['kdregu']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['nik']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['nmlengkap']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['bag_dept']) . '</Data></Cell>' . "\n");
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$row['nmjabatan']) . '</Data></Cell>' . "\n");
                    fwrite($output, '</Row>' . "\n");
                }

                fwrite($output, '</Table></Worksheet></Workbook>' . "\n");
                fclose($output);
            } else {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=Regu_Belum_Terjadwal_' . $periode . '.csv');

                $output = fopen('php://output', 'w');
                fputs($output, "\xEF\xBB\xBF");
                fputcsv($output, $headers);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, [$row['kdregu'], $row['nik'], $row['nmlengkap'], $row['bag_dept'], $row['nmjabatan']]);
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
            $stmt = getBelumTerjadwalData($pdo_hrms, $periode);
            $data_preview = $stmt->fetchAll();
            $total_data = count($data_preview);
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
            <h1 class="text-2xl font-semibold tracking-tight">Regu Belum Terjadwal</h1>
            <p class="text-sm text-muted-foreground">Cek anggota regu yang belum masuk jadwal kerja pada bulan tertentu.</p>
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
            <div class="space-y-2 flex-1 w-full max-w-xs">
                <label class="text-sm font-medium">Bulan Periode</label>
                <input type="month" name="periode" value="<?= e($periode) ?>" required
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
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
            <h3 class="font-semibold">Hasil</h3>
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= $total_data ?> anggota regu</span>
        </div>

        <div class="overflow-x-auto max-h-[500px]">
            <table class="w-full text-sm whitespace-nowrap">
                <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                    <tr>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12 text-center">#</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Kode Regu</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">NIK</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Nama Lengkap</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Departemen</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Jabatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data_preview as $idx => $r): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-2 text-center text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                        <td class="px-4 py-2 font-mono text-xs font-bold text-primary"><?= e($r['kdregu']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs text-muted-foreground"><?= e($r['nik']) ?></td>
                        <td class="px-4 py-2 font-medium"><?= e($r['nmlengkap']) ?></td>
                        <td class="px-4 py-2">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium"><?= e($r['bag_dept'] ?? '-') ?></span>
                        </td>
                        <td class="px-4 py-2 text-xs"><?= e($r['nmjabatan'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>

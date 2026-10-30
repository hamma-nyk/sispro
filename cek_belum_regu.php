<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('cek_belum_regu');

$error = null;
$search = trim($_GET['q'] ?? '');
$action = $_GET['action'] ?? 'preview';

$data_preview = [];
$total_data = 0;

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS gagal. Error: " . htmlspecialchars($err);
} else {
    function getBelumReguData($pdo_hrms, $search) {
        $sql = "
            SELECT 
                a.nik, a.nmlengkap, a.bag_dept, a.subbag_dept,
                b.nmjabatan, c.kdregu
            FROM sc_mst.karyawan a
            LEFT JOIN sc_mst.jabatan b ON a.jabatan = b.kdjabatan
            LEFT JOIN sc_mst.regu_opr c ON a.nik = c.nik
            WHERE a.statuskepegawaian <> 'KO'
            AND c.nik IS NULL
        ";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (a.nik ILIKE ? OR a.nmlengkap ILIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY a.bag_dept ASC, a.subbag_dept ASC, a.nik ASC";

        $stmt = $pdo_hrms->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    if ($action === 'export_csv' || $action === 'export_excel') {
        if (ob_get_length()) ob_end_clean();

        try {
            $stmt = getBelumReguData($pdo_hrms, $search);
            $headers = ['NIK', 'Nama Lengkap', 'Departemen', 'Sub Departemen', 'Jabatan', 'Kode Regu'];

            if ($action === 'export_excel') {
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header('Content-Disposition: attachment; filename=Belum_Masuk_Regu_' . date('Ymd_His') . '.xls');

                $output = fopen('php://output', 'w');
                fwrite($output, '<?xml version="1.0"?>' . "\n");
                fwrite($output, '<?mso-application progid="Excel.Sheet"?>' . "\n");
                fwrite($output, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n");
                fwrite($output, '<Worksheet ss:Name="Belum Masuk Regu"><Table>' . "\n");

                fwrite($output, '<Row>' . "\n");
                foreach ($headers as $h) fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>' . "\n");
                fwrite($output, '</Row>' . "\n");

                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fwrite($output, '<Row>' . "\n");
                    foreach ($row as $val) fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>' . "\n");
                    fwrite($output, '</Row>' . "\n");
                }

                fwrite($output, '</Table></Worksheet></Workbook>' . "\n");
                fclose($output);
            } else {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=Belum_Masuk_Regu_' . date('Ymd_His') . '.csv');

                $output = fopen('php://output', 'w');
                fputs($output, "\xEF\xBB\xBF");
                fputcsv($output, $headers);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) fputcsv($output, $row);
                fclose($output);
            }
            exit;

        } catch (PDOException $e) {
            $error = "Gagal export: " . $e->getMessage();
        }
    } else {
        // Otomatis fetch saat page dibuka
        try {
            $stmt = getBelumReguData($pdo_hrms, $search);
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
            <h1 class="text-2xl font-semibold tracking-tight">Karyawan Belum Masuk Regu</h1>
            <p class="text-sm text-muted-foreground">Daftar karyawan aktif yang belum terdaftar di tabel regu operasional (<code class="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">sc_mst.regu_opr</code>).</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="?action=export_excel&q=<?= urlencode($search) ?>" class="inline-flex items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-3 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-600 dark:text-emerald-400"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
                Excel
            </a>
            <a href="?action=export_csv&q=<?= urlencode($search) ?>" class="inline-flex items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-3 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
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

    <div class="rounded-lg border bg-card shadow-sm p-5">
        <form method="GET" class="flex flex-col sm:flex-row items-end gap-4">
            <div class="space-y-1.5 flex-1 w-full">
                <label class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Cari NIK / Nama</label>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Masukkan NIK atau Nama karyawan..."
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
            </div>

            <div class="flex gap-2 w-full sm:w-auto">
                <button type="submit" class="inline-flex flex-1 sm:flex-initial items-center justify-center rounded-md bg-primary text-primary-foreground px-4 py-2 text-sm font-medium shadow-sm hover:bg-primary/90 transition-colors h-9 gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    Cari
                </button>
                <?php if($search !== ''): ?>
                <a href="cek_belum_regu" class="inline-flex items-center justify-center rounded-md border px-3 text-sm hover:bg-muted transition-colors h-9">
                    Reset
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="rounded-lg border bg-card shadow-sm overflow-hidden flex flex-col">
        <div class="p-5 border-b flex items-center justify-between bg-muted/20">
            <h3 class="font-semibold">Daftar Karyawan Belum Masuk Regu</h3>
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= number_format($total_data) ?> Karyawan</span>
        </div>

        <div class="overflow-x-auto max-h-[550px]">
            <table class="w-full text-sm whitespace-nowrap">
                <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                    <tr>
                        <th class="h-9 px-4 text-center font-medium text-muted-foreground w-12">#</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">NIK</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Nama Lengkap</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Departemen</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Sub Dept</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Jabatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($data_preview)): ?>
                    <tr>
                        <td colspan="6" class="h-28 text-center text-muted-foreground">
                            Semua karyawan aktif sudah terdaftar di regu operasional.
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach($data_preview as $idx => $r): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-2.5 text-center text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                        <td class="px-4 py-2.5 font-mono text-xs font-medium"><?= e($r['nik']) ?></td>
                        <td class="px-4 py-2.5 font-medium"><?= e($r['nmlengkap']) ?></td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium bg-muted/50"><?= e($r['bag_dept'] ?? '-') ?></span>
                        </td>
                        <td class="px-4 py-2.5 text-xs text-muted-foreground"><?= e($r['subbag_dept'] ?? '-') ?></td>
                        <td class="px-4 py-2.5 text-xs"><?= e($r['nmjabatan'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

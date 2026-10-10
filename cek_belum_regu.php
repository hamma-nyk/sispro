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
$dept = trim($_GET['dept'] ?? '');
$action = $_GET['action'] ?? 'preview';

$data_preview = [];
$total_data = 0;
$list_dept = [];

if (!isset($pdo_hrms)) {
    $err = $GLOBALS['hrms_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL HRMS gagal. Error: " . htmlspecialchars($err);
} else {
    try {
        $list_dept = $pdo_hrms->query("SELECT DISTINCT bag_dept FROM sc_mst.karyawan WHERE bag_dept IS NOT NULL AND bag_dept <> '' ORDER BY bag_dept")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}

    function getBelumReguData($pdo_hrms, $dept, $search) {
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

        if ($dept !== '') {
            $sql .= " AND a.bag_dept = ?";
            $params[] = $dept;
        }
        if ($search !== '') {
            $sql .= " AND (a.nik ILIKE ? OR a.nmlengkap ILIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY a.bag_dept ASC, a.subbag_dept ASC";

        $stmt = $pdo_hrms->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    if ($action === 'export_csv' || $action === 'export_excel') {
        if (ob_get_length()) ob_end_clean();

        try {
            $stmt = getBelumReguData($pdo_hrms, $dept, $search);
            $headers = ['NIK', 'Nama Lengkap', 'Departemen', 'Sub Departemen', 'Jabatan', 'Kode Regu'];

            if ($action === 'export_excel') {
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header('Content-Disposition: attachment; filename=Belum_Masuk_Regu.xls');

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
                header('Content-Disposition: attachment; filename=Belum_Masuk_Regu.csv');

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
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        try {
            $stmt = getBelumReguData($pdo_hrms, $dept, $search);
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
            <p class="text-sm text-muted-foreground">Daftar karyawan aktif yang belum terdaftar di tabel regu operasional.</p>
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
                <label class="text-sm font-medium">Departemen</label>
                <select name="dept" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="">-- Semua Dept --</option>
                    <?php foreach($list_dept as $d): ?>
                    <option value="<?= e($d) ?>" <?= $dept === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="space-y-2 flex-1 w-full">
                <label class="text-sm font-medium">Cari NIK / Nama</label>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari..."
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
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
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background"><?= $total_data ?> karyawan</span>
        </div>

        <div class="overflow-x-auto max-h-[500px]">
            <table class="w-full text-sm whitespace-nowrap">
                <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                    <tr>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground w-12 text-center">#</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">NIK</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Nama Lengkap</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Departemen</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Sub Dept</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Jabatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data_preview as $idx => $r): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-2 text-center text-muted-foreground text-xs"><?= $idx + 1 ?></td>
                        <td class="px-4 py-2 font-mono text-xs font-medium"><?= e($r['nik']) ?></td>
                        <td class="px-4 py-2 font-medium"><?= e($r['nmlengkap']) ?></td>
                        <td class="px-4 py-2">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium"><?= e($r['bag_dept'] ?? '-') ?></span>
                        </td>
                        <td class="px-4 py-2 text-xs text-muted-foreground"><?= e($r['subbag_dept'] ?? '-') ?></td>
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

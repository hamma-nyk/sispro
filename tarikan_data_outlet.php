<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('tarikan_data_outlet');

$error = null;
$tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$action = $_GET['action'] ?? 'preview';

$data_preview = [];
$total_data = 0;

if (!isset($pdo_crm)) {
    $err = $GLOBALS['crm_error'] ?? 'Unknown Error';
    $error = "Koneksi PostgreSQL CRM gagal. Error: " . htmlspecialchars($err);
} else {
    // Fungsi untuk mendapatkan data
    function getOutletData($pdo_crm, $tgl_awal, $tgl_akhir) {
        $sql = "
        SELECT
            trim(a.branch) as branch,
            trim(a.custcode) as custcode,
            trim(a.customercodelocal) as customercodelocal,
            trim(b.subjenis) as parentid,
            trim(c.custname) as parentname,
            trim(a.custname) as custname,
            trim(concat(trim(a.custadd1), ' ', trim(a.custadd2))) as custadd,
            trim(a.custcity) as custcity,
            trim(a.custpro) as custpro,
            trim(a.custzip) as custzip,
            trim(a.custtel) as custtel,
            trim(a.custfax) as custfax,
            trim(a.ownname) as ownname,
            trim(a.ownadd) as ownadd,
            trim(a.owncity) as owncity,
            trim(a.telephone) as telephone,
            a.inputdate as inputdate,
            trim(a.reference_no) as reference_no,
            a.updatedate as updatedate,
            trim(a.updateby) as updateby,
            trim(a.colladm) as colladm,
            trim(a.custtype) as custtype,
            trim(a.custarea) as custarea,
            concat(ST_X(a.coordinate), ', ', ST_Y(a.coordinate)) as coordinate,
            coalesce(ST_X(a.coordinate), 0.00) as latitude,
            coalesce(ST_Y(a.coordinate), 0.00) as longitude,
            trim(a.checkcoordinate) as checkcoordinate,
            CASE WHEN trim(a.checkcoordinate) = 'Y' THEN 'Iya' ELSE 'Tidak' END as formatcheckcoordinate,
            trim(a.validcoordinate) as validcoordinate,
            CASE WHEN trim(a.validcoordinate) = 'Y' THEN 'Iya' ELSE 'Tidak' END as formatvalidcoordinate,
            a.countcheckin as countcheckin,
            trim(a.type) as type,
            trim(d.approver) as approver,
            trim(a.status) as status
        FROM sc_mst.customer a
        LEFT OUTER JOIN sc_mst.custparam b 
            ON trim(b.branch) = trim(a.branch)
            AND trim(b.custcode) = trim(a.custcode)
            AND trim(b.customercodelocal) = trim(a.customercodelocal)
        LEFT OUTER JOIN sc_mst.customer c 
            ON trim(c.branch) = trim(b.branch)
            AND trim(c.custcode) = trim(b.subjenis)
        LEFT JOIN sc_mst.userapproval d 
            ON trim(d.userid) = trim(a.sales) 
            AND trim(d.action) = 'O.A'
        WHERE trim(a.type) = 'O' 
        AND a.inputdate::date BETWEEN ? AND ?
        ORDER BY a.inputdate DESC
        ";
        $stmt = $pdo_crm->prepare($sql);
        $stmt->execute([$tgl_awal, $tgl_akhir]);
        return $stmt;
    }

    if ($action === 'export_csv' || $action === 'export_excel') {
        if (ob_get_length()) ob_end_clean();
        
        try {
            $stmt = getOutletData($pdo_crm, $tgl_awal, $tgl_akhir);
            
            $headers = [
                'Branch', 'Cust Code', 'Cust Code Local', 'Parent ID', 'Parent Name', 
                'Cust Name', 'Cust Add', 'Cust City', 'Cust Pro', 'Cust Zip', 
                'Cust Tel', 'Cust Fax', 'Owner Name', 'Owner Add', 'Owner City', 
                'Telephone', 'Input Date', 'Reference No', 'Update Date', 'Update By', 
                'Coll Adm', 'Cust Type', 'Cust Area', 'Coordinate', 'Latitude', 
                'Longitude', 'Check Coord', 'Format Check Coord', 'Valid Coord', 'Format Valid Coord', 
                'Count Checkin', 'Type', 'Approver', 'Status'
            ];
            
            if ($action === 'export_excel') {
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header('Content-Disposition: attachment; filename=Tarikan_Data_Outlet_' . $tgl_awal . '_to_' . $tgl_akhir . '.xls');
                
                $output = fopen('php://output', 'w');
                
                fwrite($output, '<?xml version="1.0"?>' . "\n");
                fwrite($output, '<?mso-application progid="Excel.Sheet"?>' . "\n");
                fwrite($output, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
                fwrite($output, ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n");
                fwrite($output, ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n");
                fwrite($output, ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
                fwrite($output, ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n");
                
                fwrite($output, '<Worksheet ss:Name="Data Outlet">' . "\n");
                fwrite($output, '<Table>' . "\n");
                
                fwrite($output, '<Row>' . "\n");
                foreach ($headers as $head) {
                    fwrite($output, '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$head) . '</Data></Cell>' . "\n");
                }
                fwrite($output, '</Row>' . "\n");
                
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fwrite($output, '<Row>' . "\n");
                    foreach ($row as $val) {
                        $clean_val = htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                        fwrite($output, '<Cell><Data ss:Type="String">' . $clean_val . '</Data></Cell>' . "\n");
                    }
                    fwrite($output, '</Row>' . "\n");
                }
                
                fwrite($output, '</Table>' . "\n");
                fwrite($output, '</Worksheet>' . "\n");
                fwrite($output, '</Workbook>' . "\n");
                
                fclose($output);
                exit;
            } else {
                // Export CSV
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=Tarikan_Data_Outlet_' . $tgl_awal . '_to_' . $tgl_akhir . '.csv');
                
                $output = fopen('php://output', 'w');
                fputs($output, "\xEF\xBB\xBF"); 
                fputcsv($output, $headers);
                
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fputcsv($output, $row);
                }
                fclose($output);
            }
            exit;
            
        } catch (PDOException $e) {
            $error = "Gagal mengambil data untuk export: " . $e->getMessage();
        }
    } 
    elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        try {
            $stmt = getOutletData($pdo_crm, $tgl_awal, $tgl_akhir);
            
            $count = 0;
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) && $count < 100) {
                $data_preview[] = $row;
                $count++;
            }
            $total_data = ($count === 100) ? '100+' : $count;

        } catch (PDOException $e) {
            $error = "Gagal mengambil data preview: " . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Tarikan Data Outlet (CRM)</h1>
            <p class="text-sm text-muted-foreground">Tarik data customer tipe Outlet (Type 'O') dari tabel sc_mst.customer berdasarkan Tanggal Input.</p>
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
                <label class="text-sm font-medium">Tanggal Awal Input</label>
                <input type="date" name="tgl_awal" value="<?= e($tgl_awal) ?>" required
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="space-y-2 flex-1 w-full">
                <label class="text-sm font-medium">Tanggal Akhir Input</label>
                <input type="date" name="tgl_akhir" value="<?= e($tgl_akhir) ?>" required
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            
            <button type="submit" name="action" value="preview" class="inline-flex w-full sm:w-auto items-center justify-center rounded-md bg-secondary text-secondary-foreground border px-4 py-2 text-sm font-medium shadow-sm hover:bg-secondary/80 transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                Preview
            </button>
            <button type="submit" name="action" value="export_csv" class="inline-flex w-full sm:w-auto items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium shadow-sm hover:bg-accent hover:text-accent-foreground transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                Export CSV
            </button>
            <button type="submit" name="action" value="export_excel" class="inline-flex w-full sm:w-auto items-center justify-center rounded-md bg-emerald-600 text-white border-transparent px-4 py-2 text-sm font-medium shadow-sm hover:bg-emerald-700 transition-colors h-9 gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h2"/><path d="M10 13v4"/><path d="M14 13h2"/><path d="M14 17h2"/><path d="M16 15h-2"/></svg>
                Export Excel
            </button>
        </form>
    </div>

    <?php if(!empty($data_preview)): ?>
    <div class="rounded-lg border bg-card shadow-sm overflow-hidden flex flex-col">
        <div class="p-5 border-b flex items-center justify-between bg-muted/20">
            <h3 class="font-semibold">Preview Data</h3>
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-background">
                <?= $total_data === '100+' ? 'Menampilkan 100 data pertama' : $total_data . ' data ditemukan' ?>
            </span>
        </div>
        
        <div class="overflow-x-auto max-h-[500px]">
            <table class="w-full text-sm whitespace-nowrap">
                <thead class="sticky top-0 bg-background/95 backdrop-blur z-10 border-b">
                    <tr>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Branch</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Cust Code</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Cust Name</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Parent Name</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Address</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">City</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Phone</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Input Date</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Coordinate</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data_preview as $r): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-2 font-mono text-xs"><?= e($r['branch']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs"><?= e($r['custcode']) ?></td>
                        <td class="px-4 py-2 max-w-[200px] truncate" title="<?= e($r['custname']) ?>"><?= e($r['custname']) ?></td>
                        <td class="px-4 py-2 max-w-[200px] truncate text-muted-foreground" title="<?= e($r['parentname']) ?>"><?= e($r['parentname'] ?: '-') ?></td>
                        <td class="px-4 py-2 max-w-[250px] truncate text-xs text-muted-foreground" title="<?= e($r['custadd']) ?>"><?= e($r['custadd']) ?></td>
                        <td class="px-4 py-2 text-xs"><?= e($r['custcity']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs"><?= e($r['custtel'] ?: $r['telephone']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs text-muted-foreground"><?= e(substr($r['inputdate'], 0, 10)) ?></td>
                        <td class="px-4 py-2 font-mono text-xs text-primary"><?= e($r['coordinate']) ?></td>
                        <td class="px-4 py-2 text-xs font-bold"><?= e($r['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>

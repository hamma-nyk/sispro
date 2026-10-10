<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('tarikan_checkin');

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
    function getCheckinData($pdo_crm, $tgl_awal, $tgl_akhir) {
        $sql = "
        SELECT * FROM (
            -- Bagian 1: Data Checkin Distributor (Customer)
            SELECT 
                trim(a.branch::text) as branch,
                trim(a.checkinid::text) as checkinid,
                trim(a.userid::text) as userid,
                trim(a.checkindate::text) as checkindate,
                trim(a.visitdate::text) as visitdate,
                trim(a.customeroutletcode::text) as customeroutletcode,
                trim(b.custname::text) as customeroutletname,
                'distributor' as type,
                concat(ST_X(a.coordinate), ', ', ST_Y(a.coordinate)) as coordinate,
                trim(a.uploaddate::text) as uploaddate,
                trim(a.inputdate::text) as inputdate,
                trim(a.inputby::text) as inputby,
                trim(a.checkintime::text) as checkintime,
                trim(a.checkouttime::text) as checkouttime,
                CASE     
                    WHEN extract(epoch FROM (a.checkouttime - a.checkintime)) / 3600 > 1 THEN trunc(extract(epoch FROM (a.checkouttime - a.checkintime)) / 3600) || ' jam'
                    WHEN extract(epoch FROM (a.checkouttime - a.checkintime)) / 60 > 1 THEN trunc(extract(epoch FROM (a.checkouttime - a.checkintime)) / 60) || ' menit'
                    WHEN extract(epoch FROM (a.checkouttime - a.checkintime)) > 0 THEN trunc(extract(epoch FROM (a.checkouttime - a.checkintime))) || ' detik'
                    ELSE ''
                END as duration,
                trim(a.description::text) as description,
                trim(a.checkoutreason::text) as alasan_checkout,
                trim(a.fakegps::text) as fakegps,
                trim(a.status::text) as status
            FROM sc_trx.checkin a
            INNER JOIN sc_mst.customer b ON trim(b.custcode) = trim(a.customeroutletcode)
            WHERE a.branch <> '' 
            AND a.checkindate::date BETWEEN ? AND ?
            AND trim(a.type::text) = 'C'
            
            UNION ALL
            
            -- Bagian 2: Data Checkin Outlet
            SELECT 
                trim(a.branch::text) as branch,
                trim(a.checkinid::text) as checkinid,
                trim(a.userid::text) as userid,
                trim(a.checkindate::text) as checkindate,
                trim(a.visitdate::text) as visitdate,
                trim(a.customeroutletcode::text) as customeroutletcode,
                trim(b.outletname::text) as customeroutletname,
                'outlet' as type,
                concat(ST_X(a.coordinate), ', ', ST_Y(a.coordinate)) as coordinate,
                trim(a.uploaddate::text) as uploaddate,
                trim(a.inputdate::text) as inputdate,
                trim(a.inputby::text) as inputby,
                trim(a.checkintime::text) as checkintime,
                trim(a.checkouttime::text) as checkouttime,
                CASE     
                    WHEN extract(epoch FROM (a.checkouttime - a.checkintime)) / 3600 > 1 THEN trunc(extract(epoch FROM (a.checkouttime - a.checkintime)) / 3600) || ' jam'
                    WHEN extract(epoch FROM (a.checkouttime - a.checkintime)) / 60 > 1 THEN trunc(extract(epoch FROM (a.checkouttime - a.checkintime)) / 60) || ' menit'
                    WHEN extract(epoch FROM (a.checkouttime - a.checkintime)) > 0 THEN trunc(extract(epoch FROM (a.checkouttime - a.checkintime))) || ' detik'
                    ELSE ''
                END as duration,
                trim(a.description::text) as description,
                trim(a.checkoutreason::text) as alasan_checkout,
                trim(a.fakegps::text) as fakegps,
                trim(a.status::text) as status
                
            FROM sc_trx.checkin a
            INNER JOIN sc_mst.outlet b ON trim(b.outletcode) = trim(a.customeroutletcode)
            WHERE a.branch <> '' 
            AND a.checkindate::date BETWEEN ? AND ?
            AND trim(a.type::text) <> 'C'
        ) as t1
        ORDER BY checkindate DESC, checkintime DESC
        ";
        $stmt = $pdo_crm->prepare($sql);
        // Bind parameter untuk dua bagian UNION
        $stmt->execute([$tgl_awal, $tgl_akhir, $tgl_awal, $tgl_akhir]);
        return $stmt;
    }

    if ($action === 'export_csv' || $action === 'export_excel') {
        if (ob_get_length()) ob_end_clean();
        
        try {
            $stmt = getCheckinData($pdo_crm, $tgl_awal, $tgl_akhir);
            
            $headers = ['Branch', 'Checkin ID', 'User ID', 'Checkin Date', 'Visit Date', 'Customer/Outlet Code', 'Customer/Outlet Name', 'Type', 'Coordinate', 'Upload Date', 'Input Date', 'Input By', 'Checkin Time', 'Checkout Time', 'Duration', 'Description', 'Alasan Checkout', 'Fake GPS', 'Status'];
            
            if ($action === 'export_excel') {
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header('Content-Disposition: attachment; filename=Tarikan_Checkin_CRM_' . $tgl_awal . '_to_' . $tgl_akhir . '.xls');
                
                $output = fopen('php://output', 'w');
                
                // XML Spreadsheet 2003 Stream
                fwrite($output, '<?xml version="1.0"?>' . "\n");
                fwrite($output, '<?mso-application progid="Excel.Sheet"?>' . "\n");
                fwrite($output, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
                fwrite($output, ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n");
                fwrite($output, ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n");
                fwrite($output, ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
                fwrite($output, ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n");
                
                fwrite($output, '<Worksheet ss:Name="Data Checkin">' . "\n");
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
                // Export sebagai CSV standar
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=Tarikan_Checkin_CRM_' . $tgl_awal . '_to_' . $tgl_akhir . '.csv');
                
                $output = fopen('php://output', 'w');
                // Tulis BOM UTF-8
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
        // Preview Action
        try {
            // Karena COUNT query kompleks berat, untuk preview kita langsung load saja 100 baris pertama
            // tanpa perlu fetchAll().
            $stmt = getCheckinData($pdo_crm, $tgl_awal, $tgl_akhir);
            
            $count = 0;
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) && $count < 100) {
                $data_preview[] = $row;
                $count++;
            }
            
            // Asumsi kasar untuk UI: Jika mencapai 100, berarti datanya 'Banyak'
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
            <h1 class="text-2xl font-semibold tracking-tight">Tarikan Checkin (CRM)</h1>
            <p class="text-sm text-muted-foreground">Tarik data checkin distributor dan outlet berdasarkan rentang tanggal.</p>
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
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">User ID</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Tipe</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Outlet Name</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Tgl Checkin</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">In - Out</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Durasi</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Alasan Out</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Coordinate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data_preview as $r): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-2 font-mono font-medium"><?= e($r['userid']) ?></td>
                        <td class="px-4 py-2">
                            <span class="inline-flex items-center rounded border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider <?= $r['type'] === 'distributor' ? 'border-blue-500/50 text-blue-600 dark:text-blue-400 bg-blue-500/10' : 'border-amber-500/50 text-amber-600 dark:text-amber-400 bg-amber-500/10' ?>">
                                <?= e($r['type']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 max-w-[200px] truncate" title="<?= e($r['customeroutletname']) ?>"><?= e($r['customeroutletname']) ?></td>
                        <td class="px-4 py-2 text-muted-foreground text-xs"><?= e($r['checkindate']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs">
                            <span class="text-emerald-600 dark:text-emerald-400"><?= substr(e($r['checkintime']), 0, 8) ?></span> 
                            - 
                            <span class="text-rose-600 dark:text-rose-400"><?= substr(e($r['checkouttime']), 0, 8) ?></span>
                        </td>
                        <td class="px-4 py-2 text-xs font-medium"><?= e($r['duration']) ?></td>
                        <td class="px-4 py-2 text-xs text-muted-foreground max-w-[150px] truncate" title="<?= e($r['alasan_checkout']) ?>"><?= e($r['alasan_checkout']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs"><?= e($r['coordinate']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>

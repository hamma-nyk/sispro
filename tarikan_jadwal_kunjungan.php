<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('tarikan_jadwal_kunjungan');

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
    function getJadwalData($pdo_crm, $tgl_awal, $tgl_akhir) {
        // Query menggunakan CTE values untuk injeksi parameter ke subquery
        $sql = "
        WITH tanggal(var1, var2) as (
           values (?, ?)
        )
        select 
            trim(t1.branch::text) as branch,
            trim(t1.userid::text) as userid,
            trim(t1.area::text) as area,
            trim(t1.areaname::text) as areaname,
            trim(t1.scheduleid::text) as scheduleid,
            trim(t1.scheduledate::text) as scheduledate,
            trim(t1.locationid::text) as locationid,
            trim(t1.locationidlocal::text) as locationidlocal,
            trim(t1.custname::text) as custname,
            trim(t1.custadd1::text) as custadd1,
            trim(t1.custadd2::text) as custadd2,
            trim(t1.description::text) as description,
            trim(t1.createby::text) as createby,
            trim(t1.createdate::text) as createdate,
            trim(t1.updateby::text) as updateby,
            trim(t1.updatedate::text) as updatedate
        from
        (
            select
                a.branch, d.userid, e.area, e.areaname, a.scheduleid, c.scheduledate,
                a.locationid,
                case 
                    when trim(a.locationidlocal) <> '' then trim(a.locationidlocal)
                    else trim(b.customercodelocal)
                end as locationidlocal,
                b.custname, b.custadd1, b.custadd2, a.description, a.createby, a.createdate, a.updateby, a.updatedate
            from sc_trx.scheduletolocation a
            left outer join sc_mst.customer b on true 
                and trim(b.branch) = trim(a.branch)
                and trim(b.custcode) = trim(a.locationid)
                and ( 
                    ( coalesce(trim(b.custcode), '') = coalesce(trim(a.locationid), '') and coalesce(trim(b.custcode), '') <> '' ) 
                    or 
                    ( trim(b.customercodelocal) = trim(a.locationidlocal) and coalesce(trim(b.custcode), '') = '' ) 
                )
            left outer join sc_trx.schedule c on a.scheduleid = c.scheduleid
            left outer join sc_mst.user d on d.userid = c.userid
            left outer join sc_mst.carea e on e.area = d.custarea
        ) as t1, tanggal
        where scheduledate::date >= var1::date 
          and scheduledate::date <= var2::date
        order by userid, scheduledate
        ";
        $stmt = $pdo_crm->prepare($sql);
        $stmt->execute([$tgl_awal, $tgl_akhir]);
        return $stmt;
    }

    if ($action === 'export_csv' || $action === 'export_excel') {
        if (ob_get_length()) ob_end_clean();
        
        try {
            $stmt = getJadwalData($pdo_crm, $tgl_awal, $tgl_akhir);
            
            $headers = ['Branch', 'User ID', 'Area', 'Area Name', 'Schedule ID', 'Schedule Date', 'Location ID', 'Location ID Local', 'Customer Name', 'Cust Add 1', 'Cust Add 2', 'Description', 'Create By', 'Create Date', 'Update By', 'Update Date'];
            
            if ($action === 'export_excel') {
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header('Content-Disposition: attachment; filename=Tarikan_Jadwal_Kunjungan_' . $tgl_awal . '_to_' . $tgl_akhir . '.xls');
                
                $output = fopen('php://output', 'w');
                
                fwrite($output, '<?xml version="1.0"?>' . "\n");
                fwrite($output, '<?mso-application progid="Excel.Sheet"?>' . "\n");
                fwrite($output, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
                fwrite($output, ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n");
                fwrite($output, ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n");
                fwrite($output, ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n");
                fwrite($output, ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n");
                
                fwrite($output, '<Worksheet ss:Name="Data Jadwal Kunjungan">' . "\n");
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
                header('Content-Disposition: attachment; filename=Tarikan_Jadwal_Kunjungan_' . $tgl_awal . '_to_' . $tgl_akhir . '.csv');
                
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
            $stmt = getJadwalData($pdo_crm, $tgl_awal, $tgl_akhir);
            
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
            <h1 class="text-2xl font-semibold tracking-tight">Tarikan Jadwal Kunjungan (CRM)</h1>
            <p class="text-sm text-muted-foreground">Tarik data jadwal kunjungan (sc_trx.scheduletolocation) berdasarkan rentang tanggal.</p>
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
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Branch</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">User ID</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Area</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Customer Name</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Schedule Date</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Loc ID</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Loc ID Local</th>
                        <th class="h-9 px-4 text-left font-medium text-muted-foreground">Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($data_preview as $r): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-2 font-mono text-xs"><?= e($r['branch']) ?></td>
                        <td class="px-4 py-2 font-medium"><?= e($r['userid']) ?></td>
                        <td class="px-4 py-2 text-xs">
                            <span class="font-medium text-primary"><?= e($r['areaname']) ?></span>
                            <br><span class="text-muted-foreground font-mono opacity-75"><?= e($r['area']) ?></span>
                        </td>
                        <td class="px-4 py-2 max-w-[200px] truncate" title="<?= e($r['custname']) ?>"><?= e($r['custname']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs"><?= e($r['scheduledate']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs text-muted-foreground"><?= e($r['locationid']) ?></td>
                        <td class="px-4 py-2 font-mono text-xs text-muted-foreground"><?= e($r['locationidlocal']) ?></td>
                        <td class="px-4 py-2 max-w-[200px] truncate text-xs text-muted-foreground" title="<?= e($r['custadd1'] . ' ' . $r['custadd2']) ?>">
                            <?= e($r['custadd1'] . ' ' . $r['custadd2']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>

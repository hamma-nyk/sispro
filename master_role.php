<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('master_role');

$success = null;
$error = null;
$editRole = null;
$editModules = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nama = trim($_POST['nama'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');
        $module_ids = $_POST['module_ids'] ?? [];

        if ($nama === '') {
            $error = "Nama role wajib diisi.";
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO sc_master.role (nama, keterangan) VALUES (?, ?) RETURNING id');
                $stmt->execute([$nama, $keterangan]);
                $role_id = $stmt->fetchColumn();

                foreach ($module_ids as $mid) {
                    $pdo->prepare('INSERT INTO sc_master.role_module (role_id, module_id) VALUES (?, ?)')->execute([$role_id, (int)$mid]);
                }
                $pdo->commit();
                $success = "Role berhasil ditambahkan.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = str_contains($e->getMessage(), 'unique') ? "Nama role sudah digunakan." : "Gagal menambahkan role.";
            }
        }
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $nama = trim($_POST['nama'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');
        $module_ids = $_POST['module_ids'] ?? [];

        if ($nama === '') {
            $error = "Nama role wajib diisi.";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE sc_master.role SET nama=?, keterangan=? WHERE id=?')->execute([$nama, $keterangan, $id]);
                $pdo->prepare('DELETE FROM sc_master.role_module WHERE role_id=?')->execute([$id]);
                foreach ($module_ids as $mid) {
                    $pdo->prepare('INSERT INTO sc_master.role_module (role_id, module_id) VALUES (?, ?)')->execute([$id, (int)$mid]);
                }
                $pdo->commit();
                $success = "Role berhasil diperbarui.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = str_contains($e->getMessage(), 'unique') ? "Nama role sudah digunakan." : "Gagal memperbarui role.";
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $check = $pdo->prepare('SELECT is_superadmin FROM sc_master.role WHERE id=?');
        $check->execute([$id]);
        $r = $check->fetch();
        if ($r && $r['is_superadmin']) {
            $error = "Role Superadmin tidak bisa dihapus.";
        } else {
            try {
                $pdo->prepare('DELETE FROM sc_master.role WHERE id=?')->execute([$id]);
                $success = "Role berhasil dihapus.";
            } catch (PDOException $e) {
                $error = "Gagal menghapus role. Pastikan tidak ada user yang menggunakan role ini.";
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM sc_master.role WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editRole = $stmt->fetch();
    if ($editRole) {
        $stmt2 = $pdo->prepare('SELECT module_id FROM sc_master.role_module WHERE role_id=?');
        $stmt2->execute([$editRole['id']]);
        $editModules = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    }
}

$roles = $pdo->query('
    SELECT r.*, 
        (SELECT COUNT(*) FROM sc_master."user" u WHERE u.role_id = r.id) AS jumlah_user,
        (SELECT COUNT(*) FROM sc_master.role_module rm WHERE rm.role_id = r.id) AS jumlah_modul
    FROM sc_master.role r ORDER BY r.id
')->fetchAll();

$allModules = $pdo->query('SELECT * FROM sc_master.module WHERE is_active = true ORDER BY urutan')->fetchAll();

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Data Role & Akses</h1>
            <p class="text-sm text-muted-foreground">Kelola role dan atur modul yang bisa diakses tiap role.</p>
        </div>
        <button onclick="document.getElementById('modal-role').classList.remove('hidden')" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Tambah Role
        </button>
    </div>

    <?php if($success): ?>
    <div class="rounded-md border border-primary/30 bg-primary/10 px-4 py-3 text-sm text-primary flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?= e($success) ?>
    </div>
    <?php endif; ?>
    <?php if($error): ?>
    <div class="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <?= e($error) ?>
    </div>
    <?php endif; ?>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <?php foreach($roles as $r): ?>
        <div class="rounded-lg border bg-card shadow-sm">
            <div class="p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-semibold flex items-center gap-2">
                            <?= e($r['nama']) ?>
                            <?php if($r['is_superadmin']): ?>
                            <span class="inline-flex items-center rounded-full bg-primary/10 text-primary px-2 py-0.5 text-[10px] font-bold uppercase">Super</span>
                            <?php endif; ?>
                        </h3>
                        <p class="text-sm text-muted-foreground mt-1"><?= $r['keterangan'] ? e($r['keterangan']) : '<span class="italic">Tanpa keterangan</span>' ?></p>
                    </div>
                    <?php if(!$r['is_superadmin']): ?>
                    <div class="flex items-center gap-1">
                        <a href="?edit=<?= $r['id'] ?>" class="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent transition-colors" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                        </a>
                        <form method="POST" onsubmit="return confirm('Hapus role <?= e($r['nama']) ?>?')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-destructive/10 text-destructive transition-colors" title="Hapus">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-4 mt-4 text-xs text-muted-foreground">
                    <span class="flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <?= $r['jumlah_user'] ?> user
                    </span>
                    <span class="flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                        <?= $r['is_superadmin'] ? 'Semua modul' : $r['jumlah_modul'] . ' modul' ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="modal-role" class="<?= $editRole ? '' : 'hidden' ?> fixed inset-0 z-50 flex items-center justify-center">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal()"></div>
    <div class="relative bg-card border rounded-lg shadow-lg w-full max-w-lg mx-4 p-6 space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold"><?= $editRole ? 'Edit Role' : 'Tambah Role Baru' ?></h2>
            <button onclick="closeModal()" class="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="<?= $editRole ? 'update' : 'create' ?>">
            <?php if($editRole): ?>
            <input type="hidden" name="id" value="<?= $editRole['id'] ?>">
            <?php endif; ?>

            <div class="space-y-2">
                <label class="text-sm font-medium">Nama Role</label>
                <input type="text" name="nama" required value="<?= $editRole ? e($editRole['nama']) : '' ?>"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="space-y-2">
                <label class="text-sm font-medium">Keterangan</label>
                <input type="text" name="keterangan" value="<?= $editRole ? e($editRole['keterangan']) : '' ?>"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
            </div>

            <div class="space-y-3">
                <label class="text-sm font-medium">Akses Modul</label>
                <div class="rounded-md border p-3 space-y-2">
                    <?php
                    $lastGrup = null;
                    foreach($allModules as $m):
                        if ($m['grup'] !== $lastGrup):
                            if ($lastGrup !== null) echo '</div>';
                            $lastGrup = $m['grup'];
                    ?>
                    <?php if($m['grup']): ?>
                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider <?= $lastGrup ? 'mt-3' : '' ?>"><?= e($m['grup']) ?></p>
                    <?php endif; ?>
                    <div class="space-y-1">
                    <?php endif; ?>
                        <label class="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-muted/50 cursor-pointer transition-colors">
                            <input type="checkbox" name="module_ids[]" value="<?= $m['id'] ?>" <?= in_array($m['id'], $editModules) ? 'checked' : '' ?>
                                class="h-4 w-4 rounded border-input accent-primary">
                            <span class="text-sm"><?= e($m['nama']) ?></span>
                            <span class="text-xs text-muted-foreground ml-auto"><?= e($m['url'] ?? '') ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if($lastGrup !== null) echo '</div>'; ?>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal()" class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-medium hover:bg-accent transition-colors h-9">Batal</button>
                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9">
                    <?= $editRole ? 'Simpan' : 'Tambah' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('modal-role').classList.add('hidden');
    if (window.location.search.includes('edit=')) {
        window.history.replaceState({}, '', 'master_role.php');
    }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php require_once 'includes/footer.php'; ?>

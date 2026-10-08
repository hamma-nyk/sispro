<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_MESIN');
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';
requireModule('master_user');

$success = null;
$error = null;
$editUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $nama = trim($_POST['nama_lengkap'] ?? '');
        $role_id = ($_POST['role_id'] ?? '') !== '' ? (int)$_POST['role_id'] : null;
        $pass = $_POST['password'] ?? '';

        if ($username === '' || $nama === '' || $pass === '') {
            $error = "Semua field wajib diisi.";
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO sc_master."user" (username, password, nama_lengkap, role_id) VALUES (?, ?, ?, ?)');
                $stmt->execute([$username, password_hash($pass, PASSWORD_BCRYPT), $nama, $role_id]);
                $success = "User berhasil ditambahkan.";
            } catch (PDOException $e) {
                $error = str_contains($e->getMessage(), 'unique') ? "Username sudah digunakan." : "Gagal menambahkan user.";
            }
        }
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $nama = trim($_POST['nama_lengkap'] ?? '');
        $role_id = ($_POST['role_id'] ?? '') !== '' ? (int)$_POST['role_id'] : null;
        $is_active = isset($_POST['is_active']) ? true : false;
        $pass = $_POST['password'] ?? '';

        if ($username === '' || $nama === '') {
            $error = "Username dan nama lengkap wajib diisi.";
        } else {
            try {
                if ($pass !== '') {
                    $stmt = $pdo->prepare('UPDATE sc_master."user" SET username=?, password=?, nama_lengkap=?, role_id=?, is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
                    $stmt->execute([$username, password_hash($pass, PASSWORD_BCRYPT), $nama, $role_id, $is_active ? 't' : 'f', $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE sc_master."user" SET username=?, nama_lengkap=?, role_id=?, is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
                    $stmt->execute([$username, $nama, $role_id, $is_active ? 't' : 'f', $id]);
                }
                $success = "User berhasil diperbarui.";
            } catch (PDOException $e) {
                $error = str_contains($e->getMessage(), 'unique') ? "Username sudah digunakan." : "Gagal memperbarui user.";
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare('DELETE FROM sc_master."user" WHERE id=?');
            $stmt->execute([$id]);
            $success = "User berhasil dihapus.";
        } catch (PDOException $e) {
            $error = "Gagal menghapus user.";
        }
    }
}

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM sc_master."user" WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch();
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare('
        SELECT u.*, r.nama AS role_nama 
        FROM sc_master."user" u 
        LEFT JOIN sc_master.role r ON r.id = u.role_id
        WHERE u.username ILIKE ? OR u.nama_lengkap ILIKE ? 
        ORDER BY u.id
    ');
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $stmt = $pdo->query('
        SELECT u.*, r.nama AS role_nama 
        FROM sc_master."user" u 
        LEFT JOIN sc_master.role r ON r.id = u.role_id
        ORDER BY u.id
    ');
}
$users = $stmt->fetchAll();

$allRoles = $pdo->query('SELECT * FROM sc_master.role ORDER BY nama')->fetchAll();

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Data Pengguna</h1>
            <p class="text-sm text-muted-foreground">Kelola akun pengguna sistem.</p>
        </div>
        <button onclick="document.getElementById('modal-create').classList.remove('hidden')" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9 gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Tambah User
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

    <div class="flex items-center gap-2">
        <form method="GET" class="flex-1 max-w-sm">
            <div class="relative">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari username atau nama..."
                    class="flex h-9 w-full rounded-md border border-input bg-transparent pl-9 pr-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
        </form>
        <?php if($search !== ''): ?>
        <a href="master_user.php" class="inline-flex items-center justify-center rounded-md h-9 px-3 border text-sm hover:bg-accent transition-colors">Reset</a>
        <?php endif; ?>
    </div>

    <div class="rounded-lg border bg-card shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b bg-muted/50">
                        <th class="h-10 px-4 text-left font-medium text-muted-foreground">Username</th>
                        <th class="h-10 px-4 text-left font-medium text-muted-foreground">Nama Lengkap</th>
                        <th class="h-10 px-4 text-left font-medium text-muted-foreground">Role</th>
                        <th class="h-10 px-4 text-left font-medium text-muted-foreground">Status</th>
                        <th class="h-10 px-4 text-left font-medium text-muted-foreground">Dibuat</th>
                        <th class="h-10 px-4 text-right font-medium text-muted-foreground">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($users)): ?>
                    <tr><td colspan="6" class="h-24 text-center text-muted-foreground">Tidak ada data pengguna.</td></tr>
                    <?php endif; ?>
                    <?php foreach($users as $u): ?>
                    <tr class="border-b last:border-0 hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 font-medium"><?= e($u['username']) ?></td>
                        <td class="px-4 py-3"><?= e($u['nama_lengkap']) ?></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"><?= e($u['role_nama'] ?? 'Tanpa Role') ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <?php if($u['is_active']): ?>
                            <span class="inline-flex items-center gap-1 text-xs font-medium text-primary"><span class="h-1.5 w-1.5 rounded-full bg-primary"></span>Aktif</span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground"><span class="h-1.5 w-1.5 rounded-full bg-muted-foreground"></span>Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="?edit=<?= $u['id'] ?>" class="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent transition-colors" title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                </a>
                                <form method="POST" onsubmit="return confirm('Hapus user <?= e($u['username']) ?>?')">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-destructive/10 text-destructive transition-colors" title="Hapus">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="modal-create" class="<?= $editUser ? '' : 'hidden' ?> fixed inset-0 z-50 flex items-center justify-center">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal()"></div>
    <div class="relative bg-card border rounded-lg shadow-lg w-full max-w-md mx-4 p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold"><?= $editUser ? 'Edit User' : 'Tambah User Baru' ?></h2>
            <button onclick="closeModal()" class="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
            <?php if($editUser): ?>
            <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
            <?php endif; ?>

            <div class="space-y-2">
                <label class="text-sm font-medium">Username</label>
                <input type="text" name="username" required value="<?= $editUser ? e($editUser['username']) : '' ?>"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="space-y-2">
                <label class="text-sm font-medium">Nama Lengkap</label>
                <input type="text" name="nama_lengkap" required value="<?= $editUser ? e($editUser['nama_lengkap']) : '' ?>"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="space-y-2">
                <label class="text-sm font-medium">Password <?= $editUser ? '<span class="text-muted-foreground font-normal">(kosongkan jika tidak diubah)</span>' : '' ?></label>
                <input type="password" name="password" <?= $editUser ? '' : 'required' ?>
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="space-y-2">
                <label class="text-sm font-medium">Role</label>
                <select name="role_id" class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="">-- Pilih Role --</option>
                    <?php foreach($allRoles as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= ($editUser && $editUser['role_id'] == $r['id']) ? 'selected' : '' ?>><?= e($r['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if($editUser): ?>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_active" id="is_active" value="1" <?= $editUser['is_active'] ? 'checked' : '' ?>
                    class="h-4 w-4 rounded border-input accent-primary">
                <label for="is_active" class="text-sm font-medium">Aktif</label>
            </div>
            <?php endif; ?>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal()" class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-medium hover:bg-accent transition-colors h-9">Batal</button>
                <button type="submit" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors h-9">
                    <?= $editUser ? 'Simpan' : 'Tambah' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('modal-create').classList.add('hidden');
    if (window.location.search.includes('edit=')) {
        window.history.replaceState({}, '', 'master_user.php');
    }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php require_once 'includes/footer.php'; ?>

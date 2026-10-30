<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_name('APP_ITSUPPORT');
    session_start();
}

require_once 'config/database.php';

if (isset($_SESSION['login']) && $_SESSION['login'] === true) {
    header("Location: ./");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('write_security_log')) {
    function write_security_log($level, $message, $extra = []) {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
        $line = date('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] ' . $message
              . ' | IP=' . ($_SERVER['REMOTE_ADDR'] ?? '')
              . ' | UA=' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120)
              . (!empty($extra) ? ' | ' . http_build_query($extra) : '') . PHP_EOL;
        @file_put_contents($logDir . '/security_' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}

$error = null;

$maxAttempts = 5;
$lockTime = 300;
if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= $maxAttempts) {
    $wait = $lockTime - (time() - ($_SESSION['login_first_fail'] ?? time()));
    if ($wait > 0) {
        $error = "Terlalu banyak percobaan. Coba lagi dalam " . ceil($wait / 60) . " menit.";
    } else {
        unset($_SESSION['login_attempts'], $_SESSION['login_first_fail']);
    }
}

if (isset($_POST['login']) && !$error) {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (empty($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
        $error = "Token keamanan tidak valid. Silakan muat ulang halaman.";
    } else {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('
        SELECT u.*, r.nama AS role_nama, r.is_superadmin 
        FROM sc_master."user" u 
        LEFT JOIN sc_master.role r ON r.id = u.role_id 
        WHERE u.username = ? AND u.is_active = true
    ');
    $stmt->execute([$user]);
    $data = $stmt->fetch();

    if ($data && password_verify($pass, $data['password'])) {
        session_regenerate_id(true);

        $_SESSION['login']        = true;
        $_SESSION['user_id']      = $data['id'];
        $_SESSION['username']     = $data['username'];
        $_SESSION['nama_lengkap'] = $data['nama_lengkap'];
        $_SESSION['role_id']      = $data['role_id'];
        $_SESSION['role']         = $data['role_nama'] ?? 'Tanpa Role';
        $_SESSION['is_superadmin'] = (bool)($data['is_superadmin'] ?? false);

        $modules = [];
        if ($_SESSION['is_superadmin']) {
            $stmtM = $pdo->query('SELECT kode FROM sc_master.module WHERE is_active = true');
            $modules = $stmtM->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($data['role_id']) {
            $stmtM = $pdo->prepare('
                SELECT m.kode FROM sc_master.role_module rm 
                JOIN sc_master.module m ON m.id = rm.module_id AND m.is_active = true
                WHERE rm.role_id = ?
            ');
            $stmtM->execute([$data['role_id']]);
            $modules = $stmtM->fetchAll(PDO::FETCH_COLUMN);
        }
        $_SESSION['modules'] = $modules;

        $_SESSION['user_agent']   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        unset($_SESSION['login_attempts'], $_SESSION['login_first_fail']);

        write_security_log('info', "Login berhasil: {$data['username']}");

        header("Location: ./");
        exit;
    } else {
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] === 1) {
            $_SESSION['login_first_fail'] = time();
        }
        write_security_log('security', "Login gagal untuk username: " . ($user !== '' ? $user : '(kosong)'));
        $error = "Username atau password tidak valid.";
    }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PT. NBI - Login</title>
    <link rel="icon" href="assets/icon/favicon.ico" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        (function(){
            const t = localStorage.getItem('theme');
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        border: 'hsl(var(--border))',
                        input: 'hsl(var(--input))',
                        ring: 'hsl(var(--ring))',
                        background: 'hsl(var(--background))',
                        foreground: 'hsl(var(--foreground))',
                        primary: { DEFAULT: 'hsl(var(--primary))', foreground: 'hsl(var(--primary-foreground))' },
                        muted: { DEFAULT: 'hsl(var(--muted))', foreground: 'hsl(var(--muted-foreground))' },
                        destructive: { DEFAULT: 'hsl(var(--destructive))', foreground: 'hsl(var(--destructive-foreground))' },
                        card: { DEFAULT: 'hsl(var(--card))', foreground: 'hsl(var(--card-foreground))' },
                    }
                }
            },
            plugins: [{
                handler({ addBase }) {
                    addBase({
                        ':root': {
                            '--background': '0 0% 100%', '--foreground': '222.2 84% 4.9%',
                            '--card': '0 0% 100%', '--card-foreground': '222.2 84% 4.9%',
                            '--primary': '142.1 76.2% 36.3%', '--primary-foreground': '355.7 100% 97.3%',
                            '--muted': '210 40% 96.1%', '--muted-foreground': '215.4 16.3% 46.9%',
                            '--destructive': '0 84.2% 60.2%', '--destructive-foreground': '210 40% 98%',
                            '--border': '214.3 31.8% 91.4%', '--input': '214.3 31.8% 91.4%',
                            '--ring': '142.1 76.2% 36.3%'
                        },
                        '.dark': {
                            '--background': '0 0% 7%', '--foreground': '0 0% 93%',
                            '--card': '0 0% 9%', '--card-foreground': '0 0% 93%',
                            '--primary': '142.1 70.6% 45.3%', '--primary-foreground': '144.9 80.4% 10%',
                            '--muted': '0 0% 13%', '--muted-foreground': '0 0% 55%',
                            '--destructive': '0 62.8% 50.6%', '--destructive-foreground': '0 0% 95%',
                            '--border': '0 0% 18%', '--input': '0 0% 18%',
                            '--ring': '142.1 70.6% 45.3%'
                        },
                        '*, *::before, *::after': { 'border-color': 'hsl(var(--border))' },
                        'body': { 'font-family': "'Inter', system-ui, sans-serif", '-webkit-font-smoothing': 'antialiased' }
                    })
                }
            }]
        }
    </script>
    <link rel="stylesheet" href="assets/css/custom.css">
</head>
<body class="h-full bg-background text-foreground theme-transition">
<div class="flex min-h-full items-center justify-center px-4 py-12">
    <div class="w-full max-w-sm space-y-6">
        <div class="flex justify-end">
            <button onclick="toggleTheme()" class="inline-flex items-center justify-center rounded-md h-9 w-9 hover:bg-muted transition-colors" title="Ganti tema">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dark:hidden"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
        </div>

        <div class="text-center space-y-2">
            <!-- <img src="assets/img/nbi.png" class="h-10 mx-auto mb-4 dark:hidden" alt="Logo"> -->
            <!-- <img src="assets/img/nbi_dark.png" class="h-10 mx-auto mb-4 hidden dark:block" alt="Logo Dark"> -->
            <!-- <h1 class="text-xl font-semibold tracking-tight">Masuk ke Sistem</h1> -->
            <p class="text-sm text-muted-foreground">PT. NBI - IT Support</p>
        </div>

        <div class="rounded-lg border bg-card shadow-sm p-6">
            <?php if($error): ?>
            <div class="mb-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                <?= $error ?>
            </div>
            <?php endif; ?>

            <form action="" method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="space-y-2">
                    <label for="username" class="text-sm font-medium leading-none">Username</label>
                    <input type="text" id="username" name="username" required autocomplete="off" placeholder="Masukkan username"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <div class="space-y-2">
                    <label for="password" class="text-sm font-medium leading-none">Password</label>
                    <input type="password" id="password" name="password" required placeholder="Masukkan password"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <button type="submit" name="login"
                    class="inline-flex w-full items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 transition-colors h-9">
                    Masuk
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-muted-foreground">
            &copy; 2026 PT. NBI &mdash; Confidential & Internal Only
        </p>
    </div>
</div>
<script src="assets/js/script.js"></script>
</body>
</html>

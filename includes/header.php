<?php 
$currentPage = basename($_SERVER['PHP_SELF']); 

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

$nama_user = $_SESSION['nama_lengkap'] ?? 'Pengguna';
$role_aktif = $_SESSION['role'] ?? 'Guest';
$initials = mb_strtoupper(mb_substr($nama_user, 0, 1));
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PT. NBI - IT Support</title>
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
                        secondary: { DEFAULT: 'hsl(var(--secondary))', foreground: 'hsl(var(--secondary-foreground))' },
                        muted: { DEFAULT: 'hsl(var(--muted))', foreground: 'hsl(var(--muted-foreground))' },
                        accent: { DEFAULT: 'hsl(var(--accent))', foreground: 'hsl(var(--accent-foreground))' },
                        destructive: { DEFAULT: 'hsl(var(--destructive))', foreground: 'hsl(var(--destructive-foreground))' },
                        card: { DEFAULT: 'hsl(var(--card))', foreground: 'hsl(var(--card-foreground))' },
                        sidebar: { DEFAULT: 'hsl(var(--sidebar))', foreground: 'hsl(var(--sidebar-foreground))', border: 'hsl(var(--sidebar-border))', accent: 'hsl(var(--sidebar-accent))', ring: 'hsl(var(--ring))' }
                    },
                    borderRadius: { lg: '0.5rem', md: 'calc(0.5rem - 2px)', sm: 'calc(0.5rem - 4px)' }
                }
            },
            plugins: [{
                handler({ addBase }) {
                    addBase({
                        ':root': {
                            '--background': '0 0% 100%', '--foreground': '222.2 84% 4.9%',
                            '--card': '0 0% 100%', '--card-foreground': '222.2 84% 4.9%',
                            '--primary': '142.1 76.2% 36.3%', '--primary-foreground': '355.7 100% 97.3%',
                            '--secondary': '210 40% 96.1%', '--secondary-foreground': '222.2 47.4% 11.2%',
                            '--muted': '210 40% 96.1%', '--muted-foreground': '215.4 16.3% 46.9%',
                            '--accent': '210 40% 96.1%', '--accent-foreground': '222.2 47.4% 11.2%',
                            '--destructive': '0 84.2% 60.2%', '--destructive-foreground': '210 40% 98%',
                            '--border': '214.3 31.8% 91.4%', '--input': '214.3 31.8% 91.4%',
                            '--ring': '142.1 76.2% 36.3%',
                            '--sidebar': '0 0% 98%', '--sidebar-foreground': '240 5.3% 26.1%',
                            '--sidebar-border': '220 13% 91%', '--sidebar-accent': '240 4.8% 95.9%'
                        },
                        '.dark': {
                            '--background': '0 0% 7%', '--foreground': '0 0% 93%',
                            '--card': '0 0% 9%', '--card-foreground': '0 0% 93%',
                            '--primary': '142.1 70.6% 45.3%', '--primary-foreground': '144.9 80.4% 10%',
                            '--secondary': '0 0% 13%', '--secondary-foreground': '0 0% 93%',
                            '--muted': '0 0% 13%', '--muted-foreground': '0 0% 55%',
                            '--accent': '0 0% 13%', '--accent-foreground': '0 0% 93%',
                            '--destructive': '0 62.8% 50.6%', '--destructive-foreground': '0 0% 95%',
                            '--border': '0 0% 18%', '--input': '0 0% 18%',
                            '--ring': '142.1 70.6% 45.3%',
                            '--sidebar': '0 0% 5.5%', '--sidebar-foreground': '0 0% 85%',
                            '--sidebar-border': '0 0% 18%', '--sidebar-accent': '0 0% 13%'
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
<div class="flex h-full">

    <?php require_once 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-h-screen md:ml-[260px]" id="main-content">
        <header class="shrink-0 sticky top-0 z-30 flex h-14 items-center gap-4 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 px-6">
            <button onclick="toggleSidebar()" class="md:hidden inline-flex items-center justify-center rounded-md text-sm font-medium h-9 w-9 hover:bg-accent hover:text-accent-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
            </button>
            <div class="flex-1"></div>
            <div class="flex items-center gap-3">
                <button onclick="toggleTheme()" class="inline-flex items-center justify-center rounded-md h-9 w-9 hover:bg-accent hover:text-accent-foreground transition-colors" title="Ganti tema">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dark:hidden"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                </button>
                <div class="text-right hidden sm:block">
                    <p class="text-sm font-medium leading-none"><?= htmlspecialchars($nama_user) ?></p>
                    <p class="text-xs text-muted-foreground"><?= htmlspecialchars($role_aktif) ?></p>
                </div>
                <div class="relative h-8 w-8 rounded-full bg-primary flex items-center justify-center">
                    <span class="text-xs font-medium text-primary-foreground"><?= $initials ?></span>
                </div>
            </div>
        </header>

        <main class="flex-1 p-6">

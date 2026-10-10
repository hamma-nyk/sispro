function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('hidden');
}

function toggleTheme() {
    const html = document.documentElement;
    const isDark = html.classList.contains('dark');
    html.classList.toggle('dark', !isDark);
    localStorage.setItem('theme', isDark ? 'light' : 'dark');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('open')) {
            toggleSidebar();
        }
    }
});

window.addEventListener('beforeunload', () => {
    const nav = document.querySelector('#sidebar nav');
    if (nav) sessionStorage.setItem('sidebarScrollPos', nav.scrollTop);
});

document.addEventListener('DOMContentLoaded', () => {
    const nav = document.querySelector('#sidebar nav');
    if (nav) {
        // Record scroll position instantly when a sidebar link is clicked
        nav.querySelectorAll('a.sidebar-link').forEach(link => {
            link.addEventListener('click', () => {
                sessionStorage.setItem('sidebarScrollPos', nav.scrollTop);
            });
        });

        const pos = sessionStorage.getItem('sidebarScrollPos');
        if (pos) {
            setTimeout(() => {
                nav.scrollTop = parseInt(pos, 10);
            }, 10);
        }
    }
});

        </main>

        <footer class="border-t py-4 px-6">
            <p class="text-xs text-muted-foreground text-center">&copy; 2026 PT. NBI &mdash; All rights reserved.</p>
        </footer>
    </div>
</div>

<script src="assets/js/script.js"></script>
<?php if (isset($_SESSION['global_error'])): ?>
<div id="error-toast" class="fixed top-4 right-4 z-50 max-w-sm animate-in slide-in-from-top">
    <div class="rounded-lg border border-destructive/50 bg-destructive/10 p-4 shadow-lg">
        <div class="flex items-start gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-destructive mt-0.5 flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
            <div class="flex-1">
                <p class="text-sm font-medium text-destructive">Akses Terlarang</p>
                <p class="text-sm text-muted-foreground mt-1"><?= htmlspecialchars(html_entity_decode($_SESSION['global_error'])) ?></p>
            </div>
            <button onclick="this.closest('#error-toast').remove()" class="text-muted-foreground hover:text-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
            </button>
        </div>
    </div>
</div>
<script>setTimeout(()=>{const t=document.getElementById('error-toast');if(t)t.remove()},5000);</script>
<?php unset($_SESSION['global_error']); ?>
<?php endif; ?>
</body>
</html>

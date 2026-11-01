        </div><!-- /.content-area -->
    </main><!-- /.main-wrapper -->
</div><!-- /.app-shell -->
<script src="<?= url('/assets/js/main.js') ?>"></script>

<!-- ── Auto-Logout: 1 hour inactivity ─────────────────────────────────────── -->
<script>
(function () {
    const TIMEOUT_MS  = 60 * 60 * 1000;   // 1 hour  = 3,600,000 ms
    const WARNING_MS  = 5  * 60 * 1000;   // warning 5 minutes before logout
    const LOGOUT_URL  = '<?= url('/logout.php') ?>';
    const PING_URL    = '<?= url('/includes/ping.php') ?>';

    let warningTimer, logoutTimer;
    let warningShown = false;

    // ── countdown banner ────────────────────────────────────────────────────
    const banner = document.createElement('div');
    banner.id = 'idle-warning-banner';
    Object.assign(banner.style, {
        display: 'none', position: 'fixed', bottom: '20px', left: '50%',
        transform: 'translateX(-50%)', background: '#e74c3c', color: '#fff',
        padding: '12px 24px', borderRadius: '8px', fontSize: '15px',
        fontWeight: '600', zIndex: '99999', boxShadow: '0 4px 15px rgba(0,0,0,0.3)',
        cursor: 'pointer', userSelect: 'none', textAlign: 'center'
    });
    banner.title = 'Click to stay logged in';
    banner.addEventListener('click', resetTimers);
    document.body.appendChild(banner);

    let countdownInterval;

    function showWarning() {
        warningShown = true;
        banner.style.display = 'block';
        let secs = Math.floor(WARNING_MS / 1000);
        banner.textContent = `⚠️ غیر فعالیت – ${secs} سیکنڈ میں لاگ آؤٹ ہو جائے گا۔ جاری رکھنے کے لیے کلک کریں۔`;
        countdownInterval = setInterval(() => {
            secs--;
            if (secs <= 0) { clearInterval(countdownInterval); return; }
            banner.textContent = `⚠️ غیر فعالیت – ${secs} سیکنڈ میں لاگ آؤٹ ہو جائے گا۔ جاری رکھنے کے لیے کلک کریں۔`;
        }, 1000);
    }

    function hideWarning() {
        warningShown = false;
        banner.style.display = 'none';
        clearInterval(countdownInterval);
    }

    // ── ping server to refresh session ──────────────────────────────────────
    function pingServer() {
        fetch(PING_URL, { method: 'POST', credentials: 'same-origin' }).catch(() => {});
    }

    // ── reset both timers ───────────────────────────────────────────────────
    function resetTimers() {
        clearTimeout(warningTimer);
        clearTimeout(logoutTimer);
        if (warningShown) { hideWarning(); pingServer(); }

        warningTimer = setTimeout(showWarning,   TIMEOUT_MS - WARNING_MS);
        logoutTimer  = setTimeout(() => { window.location.href = LOGOUT_URL; }, TIMEOUT_MS);
    }

    // ── track every user activity ────────────────────────────────────────────
    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(evt => {
        document.addEventListener(evt, resetTimers, { passive: true });
    });

    // start the timers on page load
    resetTimers();
})();
</script>
<!-- ─────────────────────────────────────────────────────────────────────── -->

</body>
</html>

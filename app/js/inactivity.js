// ============================================================
//  INACTIVITY.JS
//  Auto-logs out admin/staff after 3 minutes of inactivity.
//  Shows a 30-second countdown warning at 2:30.
//  Any user interaction resets the full 3-minute timer.
// ============================================================
(function () {
    'use strict';

    var TIMEOUT_MS = 3 * 60 * 1000;  // 3 minutes total
    var WARN_MS    = 30 * 1000;       // warn 30 s before logout (at 2:30)

    // Build API and sign-in URL from _APP_ROOT set by sidebar
    function getBase() {
        return (typeof window._APP_ROOT === 'string') ? window._APP_ROOT : '../';
    }

    var warningEl   = null;
    var countdownEl = null;
    var warnTimer   = null;
    var logoutTimer = null;
    var countdownInterval = null;

    // ── Build the warning banner once ────────────────────────
    function buildBanner() {
        if (document.getElementById('_inactivity_banner')) return;
        var el = document.createElement('div');
        el.id = '_inactivity_banner';
        el.style.cssText = [
            'display:none',
            'position:fixed',
            'bottom:24px',
            'left:50%',
            'transform:translateX(-50%)',
            'background:#1a1a2e',
            'color:#fff',
            'border-radius:12px',
            'padding:14px 22px',
            'font-size:.88rem',
            'font-weight:600',
            'z-index:99999',
            'box-shadow:0 4px 20px rgba(0,0,0,.4)',
            'display:none',
            'align-items:center',
            'gap:14px',
            'white-space:nowrap',
            'max-width:95vw',
        ].join(';');
        el.innerHTML = [
            '<i class="fa-solid fa-clock" style="color:#f59e0b;font-size:1rem;flex-shrink:0;"></i>',
            '<span>Session expires in <strong id="_inactivity_countdown">30</strong>s</span>',
            '<button onclick="window._inactivityReset()" style="',
            'background:#2563eb;color:#fff;border:none;border-radius:8px;',
            'padding:6px 14px;font-size:.82rem;font-weight:700;cursor:pointer;',
            'font-family:inherit;flex-shrink:0;">Stay Signed In</button>',
        ].join('');
        document.body.appendChild(el);
        warningEl   = el;
        countdownEl = document.getElementById('_inactivity_countdown');
    }

    function showWarning() {
        if (!warningEl) return;
        warningEl.style.display = 'flex';
        var secs = Math.ceil(WARN_MS / 1000);
        if (countdownEl) countdownEl.textContent = secs;
        clearInterval(countdownInterval);
        countdownInterval = setInterval(function () {
            secs = Math.max(0, secs - 1);
            if (countdownEl) countdownEl.textContent = secs;
        }, 1000);
    }

    function hideWarning() {
        if (warningEl) warningEl.style.display = 'none';
        clearInterval(countdownInterval);
    }

    // ── Perform logout ────────────────────────────────────────
    function doLogout() {
        hideWarning();
        var fd = new FormData();
        fd.append('action', 'logout');
        var target = getBase() + 'shared/auth_actions.php';
        var signin = getBase() + 'auth/signin.php?reason=timeout';

        // Use a sync XHR as fallback so it fires even on page unload
        var xhr = new XMLHttpRequest();
        xhr.open('POST', target, false); // synchronous
        try { xhr.send(fd); } catch(e) {}
        window.location.href = signin;
    }

    // ── Reset timer on any activity ───────────────────────────
    function reset() {
        hideWarning();
        clearTimeout(warnTimer);
        clearTimeout(logoutTimer);

        warnTimer = setTimeout(function () {
            showWarning();
            logoutTimer = setTimeout(doLogout, WARN_MS);
        }, TIMEOUT_MS - WARN_MS);
    }

    window._inactivityReset = reset;

    // ── Listen for any user activity ──────────────────────────
    var events = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'];
    events.forEach(function (evt) {
        document.addEventListener(evt, reset, { passive: true, capture: false });
    });

    // ── Start on DOM ready ────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            buildBanner();
            reset();
        });
    } else {
        buildBanner();
        reset();
    }

}());

// ============================================================
//  SIDEBAR.JS  — loaded with defer so DOM is fully parsed
//  Handles sidebar toggle, overlay, dropdowns.
// ============================================================
(function () {
    'use strict';

    var sidebar   = document.getElementById('sidebar');
    var hamburger = document.getElementById('hamburgerBtn');
    var overlay   = document.getElementById('sidebarOverlay');

    if (!sidebar) return;

    var MOBILE_BP = 900;

    function isMobile() { return window.innerWidth <= MOBILE_BP; }

    // ── Sync .main left margin with sidebar open/closed state ─
    function syncMain() {
        var main = document.querySelector('.main');
        if (!main) return;
        if (isMobile()) {
            main.style.marginLeft = '';          // CSS handles it: margin-left:0 !important
        } else {
            main.style.marginLeft = sidebar.classList.contains('collapsed') ? '0' : '210px';
        }
    }

    // ── Initial state ─────────────────────────────────────────
    if (isMobile()) {
        sidebar.classList.add('collapsed');
    }
    syncMain();

    // ── Hamburger click ───────────────────────────────────────
    if (hamburger) {
        hamburger.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            var open = !sidebar.classList.contains('collapsed');
            if (overlay) overlay.classList.toggle('active', open && isMobile());
            syncMain();
        });
    }

    // ── Overlay click closes sidebar ──────────────────────────
    if (overlay) {
        overlay.addEventListener('click', function () {
            sidebar.classList.add('collapsed');
            overlay.classList.remove('active');
            syncMain();
        });
    }

    // ── Resize: auto-collapse on mobile, restore on desktop ───
    window.addEventListener('resize', function () {
        if (isMobile()) {
            sidebar.classList.add('collapsed');
            if (overlay) overlay.classList.remove('active');
            var main = document.querySelector('.main');
            if (main) main.style.marginLeft = '';
        } else {
            syncMain();
        }
    });

    // ── Dropdown toggles ──────────────────────────────────────
    sidebar.querySelectorAll('.dropdown-trigger').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var menu   = document.getElementById(this.getAttribute('data-target'));
            if (!menu) return;
            var isOpen = menu.classList.contains('open');
            sidebar.querySelectorAll('.dropdown-menu.open').forEach(function (m) { m.classList.remove('open'); });
            sidebar.querySelectorAll('.dropdown-trigger.open').forEach(function (b) { b.classList.remove('open'); });
            if (!isOpen) { menu.classList.add('open'); this.classList.add('open'); }
        });
    });

    // ── Keep active dropdown open on load ────────────────────
    var cur = window.location.pathname;
    sidebar.querySelectorAll('.dropdown-item').forEach(function (link) {
        var lp = (link.getAttribute('href') || '').split('?')[0].split('#')[0];
        if (lp && cur.endsWith(lp.replace(/^.*\//, '/'))) {
            var menu = link.closest('.dropdown-menu');
            var btn  = menu && menu.previousElementSibling;
            if (menu) menu.classList.add('open');
            if (btn && btn.classList.contains('dropdown-trigger')) btn.classList.add('open');
        }
    });

}());

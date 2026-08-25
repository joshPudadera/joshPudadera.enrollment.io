// ============================================================
//  SIDEBAR.JS
//  Handles ALL sidebar/navbar behaviour for every admin page.
//  Safe to load on any page — all selectors are null-guarded.
// ============================================================

(function () {
    'use strict';

    // ── 1. Element refs ──────────────────────────────────────
    var sidebar        = document.getElementById('sidebar');
    var hamburger      = document.getElementById('hamburgerBtn');
    var overlay        = document.getElementById('sidebarOverlay');

    if (!sidebar) return; // no sidebar on this page, nothing to do

    // ── 2. Mobile: start collapsed ──────────────────────────
    if (window.innerWidth <= 900) {
        sidebar.classList.add('collapsed');
    }

    // ── 3. Sync .main margin with sidebar state ─────────────
    function syncMain() {
        var main = document.querySelector('.main');
        if (!main) return;
        var isCollapsed = sidebar.classList.contains('collapsed');
        main.style.marginLeft = isCollapsed ? '0' : '';
    }
    syncMain();

    // ── 4. Hamburger toggle ──────────────────────────────────
    if (hamburger) {
        hamburger.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            if (overlay) {
                var open = !sidebar.classList.contains('collapsed');
                overlay.classList.toggle('active', open);
            }
            syncMain();
        });
    }

    // ── 5. Overlay click closes sidebar ─────────────────────
    if (overlay) {
        overlay.addEventListener('click', function () {
            sidebar.classList.add('collapsed');
            overlay.classList.remove('active');
            syncMain();
        });
    }

    // ── 6. Dropdown toggles ──────────────────────────────────
    var triggers = sidebar.querySelectorAll('.dropdown-trigger');

    triggers.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();

            var targetId = this.getAttribute('data-target');
            var menu     = document.getElementById(targetId);
            if (!menu) return;

            var isOpen = menu.classList.contains('open');

            // Close every other open dropdown first
            sidebar.querySelectorAll('.dropdown-menu.open').forEach(function (m) {
                m.classList.remove('open');
            });
            sidebar.querySelectorAll('.dropdown-trigger.open').forEach(function (b) {
                b.classList.remove('open');
            });

            // Toggle the clicked one
            if (!isOpen) {
                menu.classList.add('open');
                this.classList.add('open');
            }
        });
    });

    // ── 7. Keep active dropdown open on page load ────────────
    // If a dropdown-item inside this menu is the current URL, open its parent
    var currentPath = window.location.pathname;
    sidebar.querySelectorAll('.dropdown-item').forEach(function (link) {
        var href = link.getAttribute('href') || '';
        // Strip query/hash for comparison
        var linkPath = href.split('?')[0].split('#')[0];
        if (linkPath && currentPath.endsWith(linkPath.replace(/^.*\//, '/'))) {
            var menu = link.closest('.dropdown-menu');
            var btn  = menu && menu.previousElementSibling;
            if (menu) menu.classList.add('open');
            if (btn && btn.classList.contains('dropdown-trigger')) btn.classList.add('open');
        }
    });

}());

<?php
$APP_ROOT   = $APP_ROOT   ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <a href="<?= $APP_ROOT ?>staff_dashboard/dashboard.php" title="Dashboard">
        <img src="<?= $APP_ROOT ?>images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
      </a>
      <span class="sidebar-notif" id="bellBtn" title="Notifications">
        <i class="fa-solid fa-bell"></i>
        <span class="sidebar-notif-badge" id="bellBadge"></span>
      </span>
    </div>
  </div>
  <div class="sidebar-nav">

    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Staff Portal</div>
      <div class="brand-sub">Management</div>
    </div>

    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>staff_dashboard/dashboard.php"
         class="sidebar-item <?= $ACTIVE_NAV==='home'?'active':'' ?>">
        <i class="fa-solid fa-gauge"></i><span>Dashboard</span>
      </a>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Student Management</div>
      <div class="brand-sub">Records &amp; Enrollment</div>
    </div>

    <!-- Direct link — no dropdown, no Add Student -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>staff_dashboard/students.php"
         class="sidebar-item <?= $ACTIVE_NAV==='students'?'active':'' ?>">
        <i class="fa-solid fa-user-graduate"></i><span>Students</span>
      </a>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='enrollment'?'active':'' ?> dropdown-trigger" data-target="sf-drop2">
        <i class="fa-solid fa-graduation-cap"></i><span>Enrollment</span><i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="sf-drop2">
        <a href="<?= $APP_ROOT ?>enrollment_tab/validation.php"         class="dropdown-item">Validation</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/cross_enrollment.php"   class="dropdown-item">Cross Enrollment</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/grade_assignment.php"   class="dropdown-item">Grade Assignment</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/waiting_list.php"       class="dropdown-item">Waiting List</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/id_generation.php"      class="dropdown-item">ID Generation</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/section_assignment.php" class="dropdown-item">Section Assignment</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='documents'?'active':'' ?> dropdown-trigger" data-target="sf-drop3">
        <i class="fa-solid fa-file-shield"></i><span>Documents</span><i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="sf-drop3">
        <a href="<?= $APP_ROOT ?>admin/applicants.php"          class="dropdown-item">Applicants &amp; Docs</a>
        <a href="<?= $APP_ROOT ?>admin/document_review.php"     class="dropdown-item">AI Document Review</a>
        <a href="<?= $APP_ROOT ?>admin/document_redaction.php"  class="dropdown-item <?= $ACTIVE_NAV==='redaction'?'active':'' ?>"><i class="fa-solid fa-shield-halved" style="margin-right:4px;font-size:.78rem;"></i>Redaction Review</a>
        <a href="<?= $APP_ROOT ?>admin/generated_documents.php" class="dropdown-item <?= $ACTIVE_NAV==='gen_docs'?'active':'' ?>"><i class="fa-solid fa-file-word" style="margin-right:4px;font-size:.78rem;color:#2563eb;"></i>Generated Documents</a>
      </div>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Reports</div>
      <div class="brand-sub">Analytics</div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='reports'?'active':'' ?> dropdown-trigger" data-target="sf-drop8">
        <i class="fa-solid fa-chart-bar"></i><span>Reports</span><i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="sf-drop8">
        <a href="<?= $APP_ROOT ?>reports_tab/reports.php"       class="dropdown-item">Monthly Report</a>
        <a href="<?= $APP_ROOT ?>reports_tab/annual_report.php" class="dropdown-item">Annual Report</a>
      </div>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">System</div>
      <div class="brand-sub">Settings</div>
    </div>

    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>staff_dashboard/account.php"
         class="sidebar-item <?= $ACTIVE_NAV==='account'?'active':'' ?>">
        <i class="fa-solid fa-user-gear"></i><span>Account Settings</span>
      </a>
    </div>

    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>system_tab/about.php"
         class="sidebar-item <?= $ACTIVE_NAV==='about'?'active':'' ?>">
        <i class="fa-solid fa-circle-info"></i><span>About</span>
      </a>
    </div>

  </div>
</aside>

<!-- ── Notification Panel (global — works on every page) ── -->
<div class="notif-overlay" id="notifOverlay"></div>
<div class="notif-panel" id="notifPanel">
  <div class="notif-header">
    <span><i class="fa-solid fa-bell" style="margin-right:6px;"></i>Notifications</span>
    <div class="notif-header-actions">
      <button class="notif-close" id="notifClose">&times;</button>
    </div>
  </div>
  <div class="notif-list" id="notifList">
    <div style="padding:24px;text-align:center;color:#aaa;font-size:.82rem;">
      <i class="fa-solid fa-spinner fa-spin"></i> Loading…
    </div>
  </div>
</div>

<script src="<?= $APP_ROOT ?>js/sidebar.js" defer></script>
<script>window._APP_ROOT = '<?= $APP_ROOT ?>';</script>
<script src="<?= $APP_ROOT ?>js/inactivity.js" defer></script>
<script>
// ── Global notification bell — runs on every staff page ───────
(function () {
    var NOTIF_API = window._APP_ROOT + 'shared/notifications.php';
    var POLL_MS   = 20000;
    var _timer    = null;

    var bellBtn    = document.getElementById('bellBtn');
    var bellBadge  = document.getElementById('bellBadge');
    var panel      = document.getElementById('notifPanel');
    var overlay    = document.getElementById('notifOverlay');
    var closeBtn   = document.getElementById('notifClose');
    var list       = document.getElementById('notifList');

    function openPanel()  { if (panel) panel.classList.add('open');    if (overlay) overlay.classList.add('active'); }
    function closePanel() { if (panel) panel.classList.remove('open'); if (overlay) overlay.classList.remove('active'); }

    if (bellBtn) bellBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panel && panel.classList.contains('open')) closePanel();
        else { openPanel(); fetchNotifs(); }
    });
    if (closeBtn)  closeBtn.addEventListener('click',  closePanel);
    if (overlay)   overlay.addEventListener('click',   closePanel);

    function renderItems(items) {
        if (!list) return;
        if (!items || items.length === 0) {
            list.innerHTML = '<div style="padding:24px;text-align:center;color:#aaa;font-size:.82rem;">'
                + '<i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:1.4rem;display:block;margin-bottom:8px;"></i>'
                + 'All caught up — no pending items.</div>';
            return;
        }
        var html = '';
        items.forEach(function (n) {
            html += '<a href="' + window._APP_ROOT + n.url + '" class="notif-item unread" style="text-decoration:none;display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-bottom:1px solid #f3f4f6;cursor:pointer;transition:background .12s;" onmouseenter="this.style.background=\'#f8fafc\'" onmouseleave="this.style.background=\'\'">'
                + '<div style="width:36px;height:36px;border-radius:50%;background:' + n.bg + ';color:' + n.color + ';display:flex;align-items:center;justify-content:center;flex-shrink:0;">'
                + '<i class="fa-solid ' + n.icon + '" style="font-size:.82rem;"></i></div>'
                + '<div style="flex:1;min-width:0;">'
                + '<div style="font-size:.82rem;font-weight:700;color:#1a1a2e;">' + n.title + '</div>'
                + '<div style="font-size:.75rem;color:#555;margin-top:2px;line-height:1.45;">' + n.desc + '</div>'
                + '</div>'
                + '<span style="font-size:.68rem;color:#aaa;flex-shrink:0;margin-top:2px;">' + n.time + '</span>'
                + '</a>';
        });
        list.innerHTML = html;
    }

    function updateBadge(count) {
        if (!bellBadge) return;
        if (count > 0) {
            bellBadge.textContent = count > 99 ? '99+' : count;
            bellBadge.style.display = '';
        } else {
            bellBadge.textContent = '';
            bellBadge.style.display = 'none';
        }
    }

    function fetchNotifs() {
        fetch(NOTIF_API, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                updateBadge(d.count);
                if (panel && panel.classList.contains('open')) renderItems(d.items);
            })
            .catch(function () {});
    }

    function start() { fetchNotifs(); _timer = setInterval(fetchNotifs, POLL_MS); }
    function stop()  { if (_timer) { clearInterval(_timer); _timer = null; } }
    document.addEventListener('visibilitychange', function () {
        document.hidden ? stop() : start();
    });
    start();
}());
</script>

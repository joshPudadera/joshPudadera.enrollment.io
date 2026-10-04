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
        <a href="<?= $APP_ROOT ?>enrollment_tab/id_generation.php"      class="dropdown-item">ID Generation</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/grade_assignment.php"   class="dropdown-item">Grade Assignment</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/waiting_list.php"       class="dropdown-item">Waiting List</a>
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

    <!-- Users: view only — no Add User -->
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>system_tab/permissions.php"
         class="sidebar-item <?= $ACTIVE_NAV==='users'?'active':'' ?>">
        <i class="fa-solid fa-users"></i><span>Users</span>
      </a>
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
<script src="<?= $APP_ROOT ?>js/sidebar.js" defer></script>
<script>window._APP_ROOT = '<?= $APP_ROOT ?>';</script>
<script src="<?= $APP_ROOT ?>js/inactivity.js" defer></script>

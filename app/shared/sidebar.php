<?php
// ============================================================
//  SIDEBAR.PHP  (shared/)
//  Reusable sidebar. Include this in any page that needs it.
//
//  Before including, define the path from your page to app/:
//    $APP_ROOT = '../';        // e.g. from app/dashboard/
//    $APP_ROOT = './';         // e.g. from app/ root level
//    $APP_ROOT = '../';        // e.g. from app/home_tab/
//
//  Also optionally set $ACTIVE_NAV to highlight a tab:
//    $ACTIVE_NAV = 'dashboard';  // matches the data-nav values below
// ============================================================

$APP_ROOT   = $APP_ROOT   ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" title="Go to Dashboard">
        <img src="<?= $APP_ROOT ?>images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
      </a>
      <span class="sidebar-notif" id="bellBtn" title="Notifications">
        <i class="fa-solid fa-bell"></i>
        <span class="sidebar-notif-badge" id="bellBadge"></span>
      </span>
    </div>
  </div>

  <div class="sidebar-nav">

    <!-- ══════════════════════════════════════
         GROUP 1 — Main Navigation
    ══════════════════════════════════════ -->
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Main Navigation</div>
      <div class="brand-sub">General</div>
    </div>

    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV==='dashboard'?'active':'' ?>">
        <i class="fa-solid fa-gauge"></i>
        <span>Dashboard</span>
      </a>
    </div>
<!-- ══════════════════════════════════════
         change the $ACTIVE_NAV==='home' depending on the active nav inside of your file
    ══════════════════════════════════════ -->
    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='home'?'active':'' ?> dropdown-trigger" data-target="drop2">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop2">
        <a href="<?= $APP_ROOT ?>home_tab/overview.php" class="dropdown-item">Overview</a>
        <a href="<?= $APP_ROOT ?>home_tab/announcements.php" class="dropdown-item">Announcements</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='reports'?'active':'' ?> dropdown-trigger" data-target="drop3">
        <i class="fa-solid fa-chart-bar"></i>
        <span>Reports</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop3">
        <a href="<?= $APP_ROOT ?>reports_tab/reports.php" class="dropdown-item">Monthly Report</a>
        <a href="<?= $APP_ROOT ?>reports_tab/annual_report.php" class="dropdown-item">Annual Report</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='schedule'?'active':'' ?> dropdown-trigger" data-target="drop4">
        <i class="fa-solid fa-calendar-days"></i>
        <span>Schedule</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop4">
        <a href="<?= $APP_ROOT ?>schedule_tab/class_schedule.php" class="dropdown-item">Class Schedule</a>
        <a href="<?= $APP_ROOT ?>schedule_tab/exam_schedule.php" class="dropdown-item">Exam Schedule</a>
      </div>
    </div>

    <div class="sidebar-divider"></div>

    <!-- ══════════════════════════════════════
         GROUP 2 — Student Management
    ══════════════════════════════════════ -->
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Student Management</div>
      <div class="brand-sub">Records & Enrollment</div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='students'?'active':'' ?> dropdown-trigger" data-target="drop5">
        <i class="fa-solid fa-user-graduate"></i>
        <span>Students</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop5">
        <a href="<?= $APP_ROOT ?>dashboard/dashboard.php#crud-table" class="dropdown-item">All Students</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/pre_registration.php" class="dropdown-item">Add Student</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='enrollment'?'active':'' ?> dropdown-trigger" data-target="drop6">
        <i class="fa-solid fa-graduation-cap"></i>
        <span>Enrollment</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop6">
        <a href="<?= $APP_ROOT ?>enrollment_tab/enrollment_dashboard.php" class="dropdown-item">Enrollment Dashboard</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/pre_registration.php"     class="dropdown-item">Pre-Registration</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/document_upload.php"      class="dropdown-item">Document Upload</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/validation.php"           class="dropdown-item">Validation</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/id_generation.php"        class="dropdown-item">ID Generation</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/grade_assignment.php"     class="dropdown-item">Grade Assignment</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/waiting_list.php"         class="dropdown-item">Waiting List</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/cross_enrollment.php"     class="dropdown-item">Cross Enrollment</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/section_assignment.php"   class="dropdown-item">Section Assignment</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='sections'?'active':'' ?> dropdown-trigger" data-target="drop7">
        <i class="fa-solid fa-school"></i>
        <span>Sections</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop7">
        <a href="<?= $APP_ROOT ?>enrollment_tab/section_assignment.php" class="dropdown-item">View Sections</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/section_assignment.php" class="dropdown-item">Manage Sections</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='advisers'?'active':'' ?> dropdown-trigger" data-target="drop8">
        <i class="fa-solid fa-chalkboard-user"></i>
        <span>Advisers</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop8">
        <a href="<?= $APP_ROOT ?>academic_tab/advisers.php" class="dropdown-item">All Advisers</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/section_assignment.php" class="dropdown-item">Assign Adviser</a>
      </div>
    </div>

    <div class="sidebar-divider"></div>

    <!-- ══════════════════════════════════════
         GROUP 3 — Academic
    ══════════════════════════════════════ -->
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Academic</div>
      <div class="brand-sub">Courses & Grades</div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='subjects'?'active':'' ?> dropdown-trigger" data-target="drop9">
        <i class="fa-solid fa-book"></i>
        <span>Subjects</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop9">
        <a href="<?= $APP_ROOT ?>academic_tab/subjects.php" class="dropdown-item">Subject List</a>
        <a href="<?= $APP_ROOT ?>academic_tab/add_subject.php" class="dropdown-item">Add Subject</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='grades'?'active':'' ?> dropdown-trigger" data-target="drop10">
        <i class="fa-solid fa-star"></i>
        <span>Grades</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop10">
        <a href="<?= $APP_ROOT ?>academic_tab/grades.php" class="dropdown-item">Enter Grades</a>
        <a href="<?= $APP_ROOT ?>academic_tab/grades.php" class="dropdown-item">Grade Reports</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='attendance'?'active':'' ?> dropdown-trigger" data-target="drop11">
        <i class="fa-solid fa-clock-rotate-left"></i>
        <span>Attendance</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop11">
        <a href="<?= $APP_ROOT ?>academic_tab/attendance.php" class="dropdown-item">Mark Attendance</a>
        <a href="<?= $APP_ROOT ?>reports_tab/reports.php" class="dropdown-item">Attendance Report</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='documents'?'active':'' ?> dropdown-trigger" data-target="drop12">
        <i class="fa-solid fa-file-lines"></i>
        <span>Documents</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop12">
        <a href="<?= $APP_ROOT ?>enrollment_tab/document_upload.php" class="dropdown-item">Certificates</a>
        <a href="<?= $APP_ROOT ?>enrollment_tab/pre_registration.php" class="dropdown-item">Forms</a>
        <a href="<?= $APP_ROOT ?>admin/document_review.php" class="dropdown-item">AI Document Review</a>
      </div>
    </div>

    <div class="sidebar-divider"></div>

    <!-- ══════════════════════════════════════
         GROUP 4 — System
    ══════════════════════════════════════ -->
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">System</div>
      <div class="brand-sub">Settings & Admin</div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='users'?'active':'' ?> dropdown-trigger" data-target="drop13">
        <i class="fa-solid fa-users"></i>
        <span>Users</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop13">
        <a href="<?= $APP_ROOT ?>system_tab/permissions.php" class="dropdown-item">All Users</a>
        <a href="<?= $APP_ROOT ?>auth/register.php" class="dropdown-item">Add User</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='settings'?'active':'' ?> dropdown-trigger" data-target="drop14">
        <i class="fa-solid fa-gear"></i>
        <span>Settings</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop14">
        <a href="<?= $APP_ROOT ?>system_tab/settings.php" class="dropdown-item">General Settings</a>
        <a href="<?= $APP_ROOT ?>system_tab/settings.php" class="dropdown-item">System Config</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='permissions'?'active':'' ?> dropdown-trigger" data-target="drop15">
        <i class="fa-solid fa-shield-halved"></i>
        <span>Permissions</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop15">
        <a href="<?= $APP_ROOT ?>system_tab/permissions.php" class="dropdown-item">Roles</a>
        <a href="<?= $APP_ROOT ?>system_tab/permissions.php" class="dropdown-item">Access Control</a>
      </div>
    </div>

    <div class="nav-group">
      <button class="sidebar-item <?= $ACTIVE_NAV==='about'?'active':'' ?> dropdown-trigger" data-target="drop16">
        <i class="fa-solid fa-circle-info"></i>
        <span>About</span>
        <i class="fa-solid fa-chevron-down arrow"></i>
      </button>
      <div class="dropdown-menu" id="drop16">
        <a href="<?= $APP_ROOT ?>system_tab/about.php" class="dropdown-item">System Info</a>
        <a href="<?= $APP_ROOT ?>landing.php" class="dropdown-item">Public Landing Page</a>
        <a href="<?= $APP_ROOT ?>system_tab/about.php" class="dropdown-item">Help & Support</a>
      </div>
    </div>

  </div><!-- end sidebar-nav -->
</aside><!-- end sidebar -->

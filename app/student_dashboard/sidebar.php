<?php
// Student sidebar — only student-relevant nav items
$APP_ROOT   = $APP_ROOT   ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <a href="<?= $APP_ROOT ?>student_dashboard/dashboard.php" title="Dashboard">
        <img src="<?= $APP_ROOT ?>images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
      </a>
    </div>
  </div>

  <div class="sidebar-nav">

    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Student Portal</div>
      <div class="brand-sub">My Account</div>
    </div>

    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>student_dashboard/dashboard.php"
         class="sidebar-item <?= $ACTIVE_NAV==='home'?'active':'' ?>">
        <i class="fa-solid fa-gauge"></i>
        <span>Dashboard</span>
      </a>
    </div>

    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>student_dashboard/enrollment.php"
         class="sidebar-item <?= $ACTIVE_NAV==='enrollment'?'active':'' ?>">
        <i class="fa-solid fa-graduation-cap"></i>
        <span>My Enrollment</span>
      </a>
    </div>

    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>student_dashboard/requirements.php"
         class="sidebar-item <?= $ACTIVE_NAV==='requirements'?'active':'' ?>">
        <i class="fa-solid fa-upload"></i>
        <span>Submit Requirements</span>
      </a>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title">Account</div>
      <div class="brand-sub">Settings</div>
    </div>

    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>student_dashboard/account.php"
         class="sidebar-item <?= $ACTIVE_NAV==='account'?'active':'' ?>">
        <i class="fa-solid fa-user-gear"></i>
        <span>Account Settings</span>
      </a>
    </div>

  </div><!-- end sidebar-nav -->
</aside>

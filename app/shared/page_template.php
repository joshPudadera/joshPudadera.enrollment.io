<?php
// ============================================================
//  PAGE_TEMPLATE.PHP  (shared/)
//  Generic page builder for admin-only pages.
//  Set before including: $PAGE_TITLE, $PAGE_ICON, $ACTIVE_NAV,
//  $APP_ROOT = '../', $page_content = ob_get_clean()
// ============================================================
if (!isset($_SESSION)) session_start();
if (empty($_SESSION['user_id'])) {
    header("Location: {$APP_ROOT}auth/signin.php"); exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: {$APP_ROOT}student_dashboard/dashboard.php"); exit;
}
$_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($PAGE_TITLE ?? 'BCP Admin') ?> – BCP</title>
  <link rel="stylesheet" href="<?= $APP_ROOT ?>css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <a href="<?= $APP_ROOT ?>admin_dashboard/account.php" class="avatar"
         title="Account Settings"><?= $_initial ?></a>
    </div>
  </div>
  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="<?= $PAGE_ICON ?? 'fa-solid fa-circle' ?>"></i>
        <?= htmlspecialchars($PAGE_TITLE ?? '') ?>
      </h2>
    </div>
    <?= $page_content ?? '' ?>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="<?= $APP_ROOT ?>js/dashboard.js"></script>
</body>
</html>
<?php if (isset($conn)) $conn->close(); ?>

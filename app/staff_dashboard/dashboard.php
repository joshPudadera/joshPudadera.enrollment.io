<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))  { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'staff'){ header('Location: ../auth/signin.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'S', 0, 1));

// Reuse admin dashboard stats
$total_students = $active_students = $inactive_students = 0;
$r = $conn->query("SELECT COUNT(*) c FROM students");                       if ($r) $total_students  = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM students WHERE status='Active'"); if ($r) $active_students = (int)$r->fetch_assoc()['c'];
$inactive_students = $total_students - $active_students;

$pending_enr = $approved_enr = $enrolled_count = $waiting_count = 0;
$r = $conn->query("SHOW TABLES LIKE 'pre_registrations'");
if ($r && $r->num_rows > 0) {
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Pending'");  if ($r) $pending_enr   = (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Approved'"); if ($r) $approved_enr  = (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Enrolled'"); if ($r) $enrolled_count = (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SHOW TABLES LIKE 'waiting_list'");
    if ($r && $r->num_rows > 0) {
        $r = $conn->query("SELECT COUNT(*) c FROM waiting_list WHERE status='Waiting'"); if ($r) $waiting_count = (int)$r->fetch_assoc()['c'];
    }
}

// Recent pre-registrations
$recent_apps = [];
$r = $conn->query("SHOW TABLES LIKE 'pre_registrations'");
if ($r && $r->num_rows > 0) {
    $res = $conn->query("SELECT * FROM pre_registrations ORDER BY submitted_at DESC LIMIT 5");
    if ($res) while ($row = $res->fetch_assoc()) $recent_apps[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Staff Dashboard – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'home'; require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <a href="account.php" class="avatar" title="Account Settings"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-gauge"></i> Staff Dashboard</h2>
    </div>

    <!-- Read-only notice -->
    <div style="margin:0 24px 18px;background:#fffbeb;border:1.5px solid #fcd34d;
                border-radius:10px;padding:10px 16px;font-size:.82rem;color:#92400e;
                display:flex;align-items:center;gap:10px;">
      <i class="fa-solid fa-shield-halved" style="flex-shrink:0;"></i>
      You are logged in as <strong>Staff</strong>. You can view and approve data but cannot add or delete records.
    </div>

    <!-- Stat cards -->
    <div class="info-row">
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-users"></i> Total Students</div>
        <div class="card-amount"><?= $total_students ?></div>
        <div class="card-detail">
          <span class="badge-active">Active: <?= $active_students ?></span> &nbsp;
          <span class="badge-inactive">Inactive: <?= $inactive_students ?></span>
        </div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Pending</div>
        <div class="card-amount" style="color:#f59e0b;"><?= $pending_enr ?></div>
        <div class="card-detail">Awaiting validation</div>
        <a href="../enrollment_tab/validation.php" class="card-btn" style="margin-top:8px;">
          <i class="fa-solid fa-arrow-right"></i> Review
        </a>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-id-badge" style="color:#2563eb;"></i> Enrolled</div>
        <div class="card-amount" style="color:#2563eb;"><?= $enrolled_count ?></div>
        <div class="card-detail"><?= $approved_enr ?> approved</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-hourglass-half" style="color:#8b5cf6;"></i> Waiting List</div>
        <div class="card-amount" style="color:#8b5cf6;"><?= $waiting_count ?></div>
        <div class="card-detail">Pending section assignment</div>
        <a href="../enrollment_tab/waiting_list.php" class="card-btn" style="margin-top:8px;">
          <i class="fa-solid fa-arrow-right"></i> View
        </a>
      </div>
    </div>

    <!-- Recent Applications -->
    <?php if (!empty($recent_apps)): ?>
    <div class="crud-card" style="margin-bottom:24px;">
      <div class="crud-header">
        <h3>Recent Applications</h3>
        <a href="../enrollment_tab/validation.php" class="btn-add" style="background:#2563eb;">
          <i class="fa-solid fa-arrow-right"></i> All Applications
        </a>
      </div>
      <table class="crud-table">
        <thead><tr><th>Name</th><th>Course</th><th>Status</th><th>Submitted</th></tr></thead>
        <tbody>
          <?php foreach ($recent_apps as $app):
            $sc = match($app['status']) { 'Approved' => 'badge-active', 'Rejected' => 'badge-inactive', default => '' };
            $ps = $app['status'] === 'Pending' ? 'background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;' : '';
          ?>
          <tr>
            <td><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></td>
            <td style="font-size:.75rem;"><?= htmlspecialchars(str_replace('Bachelor of Science in ','BS ',$app['course'])) ?></td>
            <td>
              <?php if ($ps): ?>
              <span style="<?= $ps ?>"><?= $app['status'] ?></span>
              <?php else: ?>
              <span class="<?= $sc ?>"><?= $app['status'] ?></span>
              <?php endif; ?>
            </td>
            <td style="font-size:.75rem;color:#888;"><?= date('M d, Y', strtotime($app['submitted_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- Notification panel -->
<div class="notif-overlay" id="notifOverlay"></div>
<div class="notif-panel" id="notifPanel">
  <div class="notif-header">
    <span>Notifications</span>
    <div class="notif-header-actions">
      <button class="notif-mark-all" id="notifMarkAll">Mark all as read</button>
      <button class="notif-close" id="notifClose">&times;</button>
    </div>
  </div>
  <div class="notif-list" id="notifList">
    <?php if ($pending_enr > 0): ?>
    <div class="notif-item unread">
      <span class="notif-dot"></span>
      <div class="notif-text">
        <div class="notif-title">Pending Applications</div>
        <div class="notif-desc"><?= $pending_enr ?> application<?= $pending_enr !== 1 ? 's' : '' ?> awaiting validation.</div>
      </div>
      <span class="notif-time">Now</span>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
</body>
</html>
<?php $conn->close(); ?>

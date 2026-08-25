<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

$queue = [];
$res = $conn->query(
    "SELECT w.*, p.first_name, p.last_name, p.email
     FROM waiting_list w
     JOIN pre_registrations p ON w.pre_reg_id = p.id
     ORDER BY w.queue_position ASC"
);
if ($res) while ($r = $res->fetch_assoc()) $queue[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Waiting List – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'enrollment'; require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap"><input type="text" placeholder="Search..."/><i class="fa-solid fa-magnifying-glass"></i></div>
      <a href="../admin_dashboard/account.php" class="avatar"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-list-ol"></i> Waiting List Queue</h2>
    </div>

    <div class="info-row">
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-clock"></i> Total Queued</div>
        <div class="card-amount" style="color:#ef4444;"><?= count(array_filter($queue, fn($q) => $q['status']==='Waiting')) ?></div>
        <div class="card-detail">Students waiting for a section</div>
      </div>
    </div>

    <div class="crud-card">
      <div class="crud-header"><h3>Waiting List (<?= count($queue) ?>)</h3></div>
      <table class="crud-table">
        <thead><tr>
          <th>#</th><th>Name</th><th>Course</th><th>Year Level</th><th>Reason</th><th>Status</th><th>Queued</th>
        </tr></thead>
        <tbody>
          <?php if ($queue): foreach ($queue as $q):
            $badge = match($q['status']) { 'Promoted'=>'badge-active','Cancelled'=>'badge-inactive', default=>'' };
            $style = $q['status']==='Waiting' ? 'background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:600;' : '';
          ?>
          <tr>
            <td><strong style="color:#2563eb;"><?= $q['queue_position'] ?></strong></td>
            <td><?= htmlspecialchars($q['first_name'].' '.$q['last_name']) ?></td>
            <td style="font-size:0.75rem;"><?= htmlspecialchars($q['course']) ?></td>
            <td><?= htmlspecialchars($q['year_level']) ?></td>
            <td style="font-size:0.75rem;color:#888;"><?= htmlspecialchars($q['reason']) ?></td>
            <td><?php if ($q['status']==='Waiting'): ?><span style="<?= $style ?>">Waiting</span>
                <?php else: ?><span class="<?= $badge ?>"><?= $q['status'] ?></span><?php endif; ?></td>
            <td style="font-size:0.75rem;color:#888;"><?= date('M d, Y', strtotime($q['queued_at'])) ?></td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="7" style="text-align:center;padding:24px;color:#aaa;">Waiting list is empty.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
</body></html>
<?php $conn->close(); ?>

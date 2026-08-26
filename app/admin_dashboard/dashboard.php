<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../student_dashboard/dashboard.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

// Stats
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

// Chart data — students per course
$chart_labels = $chart_active = $chart_inactive = [];
$res = $conn->query("SELECT course, SUM(status='Active') a, SUM(status='Inactive') i FROM students GROUP BY course ORDER BY COUNT(*) DESC");
if ($res) while ($row = $res->fetch_assoc()) {
    $chart_labels[]   = preg_replace('/Bachelor of Science in /i', 'BS ', $row['course']);
    $chart_active[]   = (int)$row['a'];
    $chart_inactive[] = (int)$row['i'];
}

// Donut chart — enrollment status breakdown
$enr_labels = ['Pending', 'Approved', 'Enrolled', 'Rejected'];
$enr_data   = [$pending_enr, $approved_enr, $enrolled_count, 0];
$r = $conn->query("SHOW TABLES LIKE 'pre_registrations'");
if ($r && $r->num_rows > 0) {
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Rejected'");
    if ($r) $enr_data[3] = (int)$r->fetch_assoc()['c'];
}

// Monthly student registrations (last 6 months)
$monthly_labels = $monthly_counts = [];
for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-$i months"));
    $month_end   = date('Y-m-t', strtotime("-$i months"));
    $label       = date('M Y', strtotime($month_start));
    $monthly_labels[] = $label;
    $r = $conn->query("SELECT COUNT(*) c FROM students WHERE created_at BETWEEN '$month_start' AND '$month_end 23:59:59'");
    $monthly_counts[] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}

// Recent pre-registrations (last 5)
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
  <title>Admin Dashboard – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    .quick-actions-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .quick-action-card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 22px 20px;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 10px;
      text-decoration: none;
      color: inherit;
      transition: box-shadow .18s, transform .18s;
    }
    .quick-action-card:hover {
      box-shadow: 0 4px 18px rgba(37,99,235,.10);
      transform: translateY(-2px);
    }
    .quick-action-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
    }
    .quick-action-title {
      font-size: .88rem;
      font-weight: 700;
      color: #1e293b;
    }
    .quick-action-sub {
      font-size: .75rem;
      color: #888;
    }
  </style>
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
      <h2 class="page-title"><i class="fa-solid fa-gauge"></i> Admin Dashboard</h2>
    </div>

    <!-- 4 Stat cards -->
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
        <div class="card-label"><i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Pending Applications</div>
        <div class="card-amount" style="color:#f59e0b;"><?= $pending_enr ?></div>
        <div class="card-detail">Awaiting validation</div>
        <a href="../enrollment_tab/validation.php" class="card-btn" style="margin-top:8px;">
          <i class="fa-solid fa-arrow-right"></i> Review
        </a>
      </div>

      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-id-badge" style="color:#2563eb;"></i> Enrolled Students</div>
        <div class="card-amount" style="color:#2563eb;"><?= $enrolled_count ?></div>
        <div class="card-detail"><?= $approved_enr ?> approved</div>
        <a href="../enrollment_tab/enrollment_dashboard.php" class="card-btn" style="margin-top:8px;">
          <i class="fa-solid fa-arrow-right"></i> Manage
        </a>
      </div>

      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-hourglass-half" style="color:#8b5cf6;"></i> Waiting List</div>
        <div class="card-amount" style="color:#8b5cf6;"><?= $waiting_count ?></div>
        <div class="card-detail">Pending slot assignment</div>
        <a href="../enrollment_tab/waiting_list.php" class="card-btn" style="margin-top:8px;">
          <i class="fa-solid fa-arrow-right"></i> View
        </a>
      </div>
    </div>

    <!-- Students by Course chart -->
    <div class="chart-card">
      <div class="chart-header">
        <div><h3>Students by Course</h3><div class="chart-sub">Active vs Inactive</div></div>
        <a href="../reports_tab/reports.php" class="card-btn">
          <i class="fa-solid fa-arrow-right"></i> Full Report
        </a>
      </div>
      <div class="chart-wrap"><canvas id="reportChart"></canvas></div>
    </div>
    <script>
    window._dashChartLabels   = <?= json_encode($chart_labels) ?>;
    window._dashChartActive   = <?= json_encode($chart_active) ?>;
    window._dashChartInactive = <?= json_encode($chart_inactive) ?>;
    </script>

    <!-- Two new charts side by side -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

      <!-- Donut: Enrollment Status -->
      <div class="chart-card" style="margin-bottom:0;">
        <div class="chart-header">
          <div><h3>Enrollment Status</h3><div class="chart-sub">Applications breakdown</div></div>
        </div>
        <div class="chart-wrap" style="height:220px;">
          <canvas id="donutChart"></canvas>
        </div>
      </div>

      <!-- Line: Monthly Student Registrations -->
      <div class="chart-card" style="margin-bottom:0;">
        <div class="chart-header">
          <div><h3>Monthly Students</h3><div class="chart-sub">Student records added per month</div></div>
        </div>
        <div class="chart-wrap" style="height:220px;">
          <canvas id="lineChart"></canvas>
        </div>
      </div>

    </div>
    <script>
    // Donut chart — enrollment status
    (function() {
        var ctx = document.getElementById('donutChart');
        if (!ctx) return;
        new Chart(ctx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($enr_labels) ?>,
                datasets: [{
                    data: <?= json_encode($enr_data) ?>,
                    backgroundColor: ['#f59e0b','#22c55e','#2563eb','#ef4444'],
                    borderWidth: 2,
                    borderColor: '#fff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
                },
                cutout: '65%'
            }
        });
    })();

    // Line chart — monthly registrations
    (function() {
        var ctx = document.getElementById('lineChart');
        if (!ctx) return;
        new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?= json_encode($monthly_labels) ?>,
                datasets: [{
                    label: 'Students Added',
                    data: <?= json_encode($monthly_counts) ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,.08)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#2563eb',
                    pointRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { font: { size: 10 }, stepSize: 1 }, grid: { color: '#f0f0f0' } },
                    x: { ticks: { font: { size: 10 } }, grid: { display: false } }
                }
            }
        });
    })();
    </script>

    <!-- Recent Applications -->
    <?php if (!empty($recent_apps)): ?>
    <div class="crud-card" style="margin-bottom:24px;">
      <div class="crud-header">
        <h3>Recent Applications</h3>
        <a href="../enrollment_tab/validation.php" class="btn-add">
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
            <td style="font-size:.75rem;"><?= htmlspecialchars(str_replace('Bachelor of Science in ', 'BS ', $app['course'])) ?></td>
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

    <!-- Quick Actions -->
    <div class="crud-card" style="margin-bottom:24px;">
      <div class="crud-header" style="margin-bottom:16px;">
        <h3>Quick Actions</h3>
      </div>
      <div class="quick-actions-grid">
        <a href="students.php" class="quick-action-card">
          <div class="quick-action-icon" style="background:#eff6ff;">
            <i class="fa-solid fa-user-graduate" style="color:#2563eb;"></i>
          </div>
          <div>
            <div class="quick-action-title">View All Students</div>
            <div class="quick-action-sub">Manage student records</div>
          </div>
        </a>
        <a href="../enrollment_tab/validation.php" class="quick-action-card">
          <div class="quick-action-icon" style="background:#fff7ed;">
            <i class="fa-solid fa-clipboard-check" style="color:#f59e0b;"></i>
          </div>
          <div>
            <div class="quick-action-title">Validate Applications</div>
            <div class="quick-action-sub">Review pending enrollments</div>
          </div>
        </a>
        <a href="../enrollment_tab/id_generation.php" class="quick-action-card">
          <div class="quick-action-icon" style="background:#f0fdf4;">
            <i class="fa-solid fa-id-card" style="color:#16a34a;"></i>
          </div>
          <div>
            <div class="quick-action-title">Generate IDs</div>
            <div class="quick-action-sub">Create student ID cards</div>
          </div>
        </a>
        <a href="../enrollment_tab/section_assignment.php" class="quick-action-card">
          <div class="quick-action-icon" style="background:#faf5ff;">
            <i class="fa-solid fa-object-group" style="color:#8b5cf6;"></i>
          </div>
          <div>
            <div class="quick-action-title">Assign Sections</div>
            <div class="quick-action-sub">Manage class sections</div>
          </div>
        </a>
      </div>
    </div>

  </div><!-- end content -->
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
    <?php
    $notif_pending = $pending_enr;
    if ($notif_pending > 0): ?>
    <div class="notif-item unread" data-notif="enr">
      <span class="notif-dot"></span>
      <div class="notif-text">
        <div class="notif-title">Pending Applications</div>
        <div class="notif-desc"><?= $notif_pending ?> application<?= $notif_pending !== 1 ? 's' : '' ?> awaiting validation.</div>
      </div>
      <span class="notif-time">Now</span>
    </div>
    <?php endif; ?>
    <?php $nr = $conn->query("SELECT first_name,last_name,created_at FROM students ORDER BY created_at DESC LIMIT 3");
    if ($nr) while ($ns = $nr->fetch_assoc()):
      $ago = max(1, round((time() - strtotime($ns['created_at'])) / 60));
      $ts  = $ago < 60 ? $ago . 'm ago' : ($ago < 1440 ? round($ago / 60) . 'h ago' : date('M d', strtotime($ns['created_at'])));
    ?>
    <div class="notif-item" data-notif="s">
      <span class="notif-dot"></span>
      <div class="notif-text">
        <div class="notif-title">Student record</div>
        <div class="notif-desc"><?= htmlspecialchars($ns['first_name'] . ' ' . $ns['last_name']) ?> is in the system.</div>
      </div>
      <span class="notif-time"><?= $ts ?></span>
    </div>
    <?php endwhile; ?>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>
const STUDENT_API = 'students.php';
</script>
<script src="../js/dashboard.js"></script>
</body>
</html>
<?php $conn->close(); ?>

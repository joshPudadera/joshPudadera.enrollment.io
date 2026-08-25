<?php
// ============================================================
//  REPORTS.PHP  (app/reports_tab/)
//  Monthly & enrollment data reports with live charts.
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// ── Student stats ────────────────────────────────────────────
$total = $active = $inactive = 0;
$r = $conn->query("SELECT COUNT(*) c FROM students");                         if ($r) $total    = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM students WHERE status='Active'");   if ($r) $active   = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM students WHERE status='Inactive'"); if ($r) $inactive = (int)$r->fetch_assoc()['c'];

// ── Students per course ──────────────────────────────────────
$course_labels = $course_data = [];
$res = $conn->query("SELECT course, COUNT(*) c FROM students GROUP BY course ORDER BY c DESC");
if ($res) while ($row = $res->fetch_assoc()) {
    // Shorten course names for chart labels
    $short = preg_replace('/Bachelor of Science in /i', 'BS ', $row['course']);
    $course_labels[] = $short;
    $course_data[]   = (int)$row['c'];
}

// ── Students per year level ──────────────────────────────────
$year_labels = $year_data = [];
$res = $conn->query("SELECT year_level, COUNT(*) c FROM students GROUP BY year_level ORDER BY year_level ASC");
if ($res) while ($row = $res->fetch_assoc()) {
    $year_labels[] = $row['year_level'];
    $year_data[]   = (int)$row['c'];
}

// ── Monthly registrations (last 6 months) ───────────────────
$monthly_labels = $monthly_data = [];
$res = $conn->query(
    "SELECT DATE_FORMAT(created_at,'%b %Y') AS mo, COUNT(*) c
     FROM students
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY YEAR(created_at), MONTH(created_at)
     ORDER BY YEAR(created_at), MONTH(created_at) ASC"
);
if ($res) while ($row = $res->fetch_assoc()) {
    $monthly_labels[] = $row['mo'];
    $monthly_data[]   = (int)$row['c'];
}

// ── Enrollment stats (if table exists) ──────────────────────
$enr_pending = $enr_approved = $enr_enrolled = $enr_rejected = 0;
$has_enrollment = false;
$r = $conn->query("SHOW TABLES LIKE 'pre_registrations'");
if ($r && $r->num_rows > 0) {
    $has_enrollment = true;
    $r = $conn->query("SELECT status, COUNT(*) c FROM pre_registrations GROUP BY status");
    if ($r) while ($row = $r->fetch_assoc()) {
        match($row['status']) {
            'Pending'  => $enr_pending  = (int)$row['c'],
            'Approved' => $enr_approved = (int)$row['c'],
            'Enrolled' => $enr_enrolled = (int)$row['c'],
            'Rejected' => $enr_rejected = (int)$row['c'],
            default    => null,
        };
    }
}

// ── Top 5 recent students ────────────────────────────────────
$recent = [];
$res = $conn->query("SELECT * FROM students ORDER BY created_at DESC LIMIT 5");
if ($res) while ($row = $res->fetch_assoc()) $recent[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reports – BCP Student Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'reports'; require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <a href="../admin_dashboard/account.php" class="avatar" title="Account Settings"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-chart-bar"></i> Reports</h2>
    </div>

    <!-- ── SUMMARY CARDS ── -->
    <div class="info-row">
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-users"></i> Total Students</div>
        <div class="card-amount"><?= $total ?></div>
        <div class="card-detail">All registered students</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#22c55e;"></i> Active</div>
        <div class="card-amount" style="color:#22c55e;"><?= $active ?></div>
        <div class="card-detail"><?= $total > 0 ? round($active/$total*100) : 0 ?>% of total</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-circle-xmark" style="color:#ef4444;"></i> Inactive</div>
        <div class="card-amount" style="color:#ef4444;"><?= $inactive ?></div>
        <div class="card-detail"><?= $total > 0 ? round($inactive/$total*100) : 0 ?>% of total</div>
      </div>
    </div>

    <?php if ($has_enrollment): ?>
    <div class="info-row">
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Pending Applications</div>
        <div class="card-amount" style="color:#f59e0b;"><?= $enr_pending ?></div>
        <div class="card-detail">Awaiting validation</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#22c55e;"></i> Approved</div>
        <div class="card-amount" style="color:#22c55e;"><?= $enr_approved ?></div>
        <div class="card-detail">Ready for enrollment</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-id-badge" style="color:#2563eb;"></i> Enrolled</div>
        <div class="card-amount" style="color:#2563eb;"><?= $enr_enrolled ?></div>
        <div class="card-detail">ID numbers issued</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── CHARTS ROW ── -->
    <div class="tables-row">

      <!-- Students by Course -->
      <div class="table-card" style="flex:1.5;">
        <h3>Students by Course</h3>
        <div style="height:220px; position:relative; margin-top:12px;">
          <canvas id="courseChart"></canvas>
        </div>
      </div>

      <!-- Students by Year Level -->
      <div class="table-card">
        <h3>Students by Year Level</h3>
        <div style="height:220px; position:relative; margin-top:12px;">
          <canvas id="yearChart"></canvas>
        </div>
      </div>

    </div>

    <!-- ── MONTHLY REGISTRATION CHART ── -->
    <div class="chart-card">
      <div class="chart-header">
        <div>
          <h3>Monthly Registrations</h3>
          <div class="chart-sub">New students added in the last 6 months</div>
        </div>
        <a href="../dashboard/dashboard.php" class="card-btn">
          <i class="fa-solid fa-arrow-right"></i> Dashboard
        </a>
      </div>
      <div class="chart-wrap">
        <canvas id="monthlyChart"></canvas>
      </div>
    </div>

    <!-- ── RECENT STUDENTS TABLE ── -->
    <div class="crud-card">
      <div class="crud-header">
        <h3>Recently Added Students</h3>
        <a href="../dashboard/dashboard.php#crud-table" class="btn-add">
          <i class="fa-solid fa-arrow-right"></i> View All
        </a>
      </div>
      <table class="crud-table">
        <thead>
          <tr>
            <th>Name</th><th>Course</th><th>Year / Section</th><th>Status</th><th>Added</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recent): foreach ($recent as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
            <td style="font-size:0.75rem;"><?= htmlspecialchars($s['course']) ?></td>
            <td><?= htmlspecialchars($s['year_level'] . ' / ' . $s['section']) ?></td>
            <td><span class="badge-<?= strtolower($s['status']) ?>"><?= $s['status'] ?></span></td>
            <td style="font-size:0.75rem; color:#888;"><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="5" style="text-align:center;padding:24px;color:#aaa;">No students yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
const courseLabels  = <?= json_encode($course_labels) ?>;
const courseData    = <?= json_encode($course_data) ?>;
const yearLabels    = <?= json_encode($year_labels) ?>;
const yearData      = <?= json_encode($year_data) ?>;
const monthlyLabels = <?= json_encode($monthly_labels) ?>;
const monthlyData   = <?= json_encode($monthly_data) ?>;

const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 11, font: { size: 10 } } } }
};

// Students by course — horizontal bar
new Chart(document.getElementById('courseChart'), {
    type: 'bar',
    data: {
        labels: courseLabels,
        datasets: [{
            label: 'Students',
            data: courseData,
            backgroundColor: ['#3b82f6','#a78bfa','#f9a8d4','#34d399','#fbbf24'],
            borderRadius: 5,
        }]
    },
    options: { ...chartDefaults, indexAxis: 'y',
        scales: { x: { beginAtZero: true, ticks: { font:{size:10} } },
                  y: { ticks: { font:{size:10} } } } }
});

// Students by year level — doughnut
new Chart(document.getElementById('yearChart'), {
    type: 'doughnut',
    data: {
        labels: yearLabels,
        datasets: [{
            data: yearData,
            backgroundColor: ['#3b82f6','#a78bfa','#34d399','#fbbf24'],
            borderWidth: 2,
        }]
    },
    options: { ...chartDefaults }
});

// Monthly registrations — line chart
new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: monthlyLabels.length ? monthlyLabels : ['No data'],
        datasets: [{
            label: 'New Students',
            data: monthlyData.length ? monthlyData : [0],
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,0.08)',
            borderWidth: 2.5,
            pointBackgroundColor: '#2563eb',
            fill: true,
            tension: 0.4,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { font:{size:10} }, grid: { color:'#f0f0f0' } },
            x: { ticks: { font:{size:10} }, grid: { display: false } }
        }
    }
});
</script>
</body>
</html>
<?php $conn->close(); ?>

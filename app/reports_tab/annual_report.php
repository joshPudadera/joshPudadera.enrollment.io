<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'reports';
$PAGE_TITLE = 'Annual Report';
$PAGE_ICON  = 'fa-solid fa-chart-line';

// Stats by course & year level
$by_course = $by_year = [];
$r = $conn->query("SELECT course, COUNT(*) c, SUM(status='Active') active FROM students GROUP BY course ORDER BY c DESC");
if ($r) while ($row = $r->fetch_assoc()) $by_course[] = $row;

$r = $conn->query("SELECT year_level, COUNT(*) c FROM students GROUP BY year_level ORDER BY year_level");
if ($r) while ($row = $r->fetch_assoc()) $by_year[] = $row;

$total = array_sum(array_column($by_course, 'c'));

ob_start();
?>
<!-- Summary -->
<div class="info-row">
  <div class="info-card">
    <div class="card-label"><i class="fa-solid fa-users"></i> Total Enrolled</div>
    <div class="card-amount"><?= $total ?></div>
    <div class="card-detail">A.Y. 2025–2026</div>
  </div>
  <div class="info-card">
    <div class="card-label"><i class="fa-solid fa-book"></i> Courses Offered</div>
    <div class="card-amount"><?= count($by_course) ?></div>
    <div class="card-detail">Active programs</div>
  </div>
  <div class="info-card">
    <div class="card-label"><i class="fa-solid fa-layer-group"></i> Year Levels</div>
    <div class="card-amount"><?= count($by_year) ?></div>
    <div class="card-detail">Tracked year levels</div>
  </div>
</div>

<!-- By course -->
<div class="crud-card">
  <div class="crud-header"><h3>Students by Course</h3></div>
  <table class="crud-table">
    <thead><tr><th>Course</th><th>Total</th><th>Active</th><th>Inactive</th><th>% of Total</th></tr></thead>
    <tbody>
      <?php foreach ($by_course as $row):
        $pct      = $total > 0 ? round($row['c']/$total*100, 1) : 0;
        $inactive = $row['c'] - $row['active'];
        $short    = preg_replace('/Bachelor of Science in /i', 'BS ', $row['course']);
      ?>
      <tr>
        <td><?= htmlspecialchars($short) ?></td>
        <td><strong><?= $row['c'] ?></strong></td>
        <td style="color:#16a34a;font-weight:600;"><?= $row['active'] ?></td>
        <td style="color:#dc2626;font-weight:600;"><?= $inactive ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="flex:1;background:#e8edf4;border-radius:4px;height:6px;">
              <div style="background:#2563eb;width:<?= $pct ?>%;height:6px;border-radius:4px;"></div>
            </div>
            <span style="font-size:0.75rem;color:#888;min-width:36px;"><?= $pct ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- By year level -->
<div class="crud-card">
  <div class="crud-header"><h3>Students by Year Level</h3></div>
  <table class="crud-table">
    <thead><tr><th>Year Level</th><th>Count</th><th>% of Total</th></tr></thead>
    <tbody>
      <?php foreach ($by_year as $row):
        $pct = $total > 0 ? round($row['c']/$total*100, 1) : 0;
      ?>
      <tr>
        <td><?= htmlspecialchars($row['year_level']) ?></td>
        <td><strong><?= $row['c'] ?></strong></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="flex:1;background:#e8edf4;border-radius:4px;height:6px;">
              <div style="background:#a78bfa;width:<?= $pct ?>%;height:6px;border-radius:4px;"></div>
            </div>
            <span style="font-size:0.75rem;color:#888;min-width:36px;"><?= $pct ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';

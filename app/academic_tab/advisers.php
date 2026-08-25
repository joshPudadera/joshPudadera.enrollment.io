<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'advisers';
$PAGE_TITLE = 'Advisers';
$PAGE_ICON  = 'fa-solid fa-chalkboard-user';

// Pull advisers from sections table (adviser_name column)
$advisers = [];
$res = $conn->query("SHOW TABLES LIKE 'sections'");
if ($res && $res->num_rows > 0) {
    $r = $conn->query(
        "SELECT DISTINCT adviser_name, course, year_level, section_code, current_count, max_capacity
         FROM sections WHERE adviser_name IS NOT NULL AND is_active=1
         ORDER BY adviser_name ASC"
    );
    if ($r) while ($row = $r->fetch_assoc()) $advisers[] = $row;
}

ob_start();
?>
<div class="form-card">
  <h3>About Advisers</h3>
  <p style="font-size:.82rem;color:#666;line-height:1.65;">
    Advisers are assigned to sections. Each section in the system has a designated adviser.
    To add or update an adviser, edit the <strong>sections</strong> table directly or use the
    <a href="../enrollment_tab/section_assignment.php" style="color:#2563eb;">Section Assignment</a> module.
  </p>
</div>

<div class="crud-card">
  <div class="crud-header">
    <h3>Assigned Advisers (<?= count($advisers) ?>)</h3>
    <a href="../enrollment_tab/section_assignment.php" class="btn-add">
      <i class="fa-solid fa-arrow-right"></i> Manage Sections
    </a>
  </div>
  <?php if ($advisers): ?>
  <table class="crud-table">
    <thead>
      <tr>
        <th>Adviser Name</th>
        <th>Section</th>
        <th>Course</th>
        <th>Year Level</th>
        <th>Capacity</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($advisers as $a):
        $pct = $a['max_capacity'] > 0 ? round($a['current_count'] / $a['max_capacity'] * 100) : 0;
        $color = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#22c55e');
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($a['adviser_name']) ?></strong></td>
        <td><span class="badge-active"><?= htmlspecialchars($a['section_code']) ?></span></td>
        <td style="font-size:.75rem;"><?= htmlspecialchars(str_replace('Bachelor of Science in ', '', $a['course'])) ?></td>
        <td><?= htmlspecialchars($a['year_level']) ?></td>
        <td>
          <span style="font-size:.78rem; color:<?= $color ?>; font-weight:600;">
            <?= $a['current_count'] ?>/<?= $a['max_capacity'] ?>
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p style="text-align:center;padding:24px;color:#aaa;">
    No sections found. Run the
    <a href="../enrollment_tab/setup_enrollment.php" style="color:#2563eb;">enrollment setup</a>
    to seed section data.
  </p>
  <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';

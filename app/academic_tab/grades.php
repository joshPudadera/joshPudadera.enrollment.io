<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'grades';
$PAGE_TITLE = 'Grade Records';
$PAGE_ICON  = 'fa-solid fa-star';

// Pull student list
$students = [];
$res = $conn->query("SELECT id, first_name, last_name, course, year_level, section FROM students ORDER BY last_name ASC LIMIT 50");
if ($res) while ($r = $res->fetch_assoc()) $students[] = $r;

$grade_cols = ['Midterm', 'Finals', 'Final Grade'];

ob_start();
?>
<div class="form-card">
  <h3>Grade Entry</h3>
  <p style="font-size:.82rem;color:#888;margin-bottom:16px;">
    Enter or update grades for enrolled students. Connect a <code>grades</code> table to persist records.
  </p>
  <div class="alert-info" style="margin-bottom:16px;">
    <i class="fa-solid fa-circle-info"></i>
    This is a demo view. Run <code>CREATE TABLE grades</code> to enable saving.
  </div>
</div>

<div class="crud-card">
  <div class="crud-header">
    <h3>Student Grade Sheet (showing first 50)</h3>
    <a href="../reports_tab/annual_report.php" class="btn-add">
      <i class="fa-solid fa-chart-bar"></i> View Reports
    </a>
  </div>
  <div style="overflow-x:auto;">
    <table class="crud-table" style="min-width:600px;">
      <thead>
        <tr>
          <th>Name</th>
          <th>Course</th>
          <th>Year / Section</th>
          <?php foreach ($grade_cols as $col): ?>
          <th><?= $col ?></th>
          <?php endforeach; ?>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($students): foreach ($students as $s): ?>
        <tr>
          <td><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></td>
          <td style="font-size:.75rem;"><?= htmlspecialchars(str_replace('Bachelor of Science in ','',$s['course'])) ?></td>
          <td style="font-size:.78rem;"><?= htmlspecialchars($s['year_level'].' / '.$s['section']) ?></td>
          <?php foreach ($grade_cols as $col): ?>
          <td>
            <input type="number" min="0" max="100" step="0.5" placeholder="—"
                   style="width:70px;height:30px;border:1.5px solid #d0d7e2;border-radius:5px;
                          padding:0 6px;font-size:.78rem;text-align:center;"/>
          </td>
          <?php endforeach; ?>
          <td>
            <select style="height:30px;border:1.5px solid #d0d7e2;border-radius:5px;
                           padding:0 8px;font-size:.75rem;">
              <option>Passed</option>
              <option>Failed</option>
              <option>Inc.</option>
              <option>Dropped</option>
            </select>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="6" style="text-align:center;padding:24px;color:#aaa;">No students found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($students): ?>
  <div style="text-align:right;margin-top:12px;">
    <button class="btn-submit" onclick="showAlertModal('Connect a grades table to save records.', 'warning', 'Grades')">
      <i class="fa-solid fa-floppy-disk"></i> Save Grades
    </button>
  </div>
  <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';

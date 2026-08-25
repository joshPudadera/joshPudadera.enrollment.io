<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'attendance';
$PAGE_TITLE = 'Attendance';
$PAGE_ICON  = 'fa-solid fa-clock-rotate-left';

// Pull student list for attendance marking
$students = [];
$res = $conn->query("SELECT id, first_name, last_name, year_level, section FROM students WHERE status='Active' ORDER BY last_name ASC LIMIT 30");
if ($res) while ($r = $res->fetch_assoc()) $students[] = $r;

$today = date('Y-m-d');

ob_start();
?>
<div class="form-card">
  <h3>Mark Attendance — <?= date('F d, Y') ?></h3>
  <p style="font-size:0.8rem;color:#888;margin-bottom:16px;">
    Select the attendance status for each student. This is a demo view — connect to an
    <code>attendance</code> table to persist records.
  </p>
  <div class="alert-info" style="margin-bottom:16px;">
    <i class="fa-solid fa-circle-info"></i>
    Run <code>CREATE TABLE attendance</code> from your DB to enable saving.
  </div>
</div>

<div class="crud-card">
  <div class="crud-header">
    <h3>Active Students (showing first 30)</h3>
    <button class="btn-add" onclick="markAll('Present')">
      <i class="fa-solid fa-check"></i> Mark All Present
    </button>
  </div>
  <table class="crud-table">
    <thead>
      <tr><th>Name</th><th>Year / Section</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php foreach ($students as $s): ?>
      <tr>
        <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
        <td style="font-size:0.78rem;"><?= htmlspecialchars($s['year_level'] . ' / ' . $s['section']) ?></td>
        <td>
          <select class="att-select" data-id="<?= $s['id'] ?>"
                  style="height:32px;border:1.5px solid #d0d7e2;border-radius:6px;padding:0 10px;font-size:0.78rem;">
            <option value="Present">Present</option>
            <option value="Absent">Absent</option>
            <option value="Late">Late</option>
            <option value="Excused">Excused</option>
          </select>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (empty($students)): ?>
  <p style="text-align:center;padding:24px;color:#aaa;">No active students found.</p>
  <?php endif; ?>
</div>

<script>
function markAll(status) {
    document.querySelectorAll('.att-select').forEach(sel => sel.value = status);
}
</script>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';

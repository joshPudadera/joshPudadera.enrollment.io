<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'schedule';
$PAGE_TITLE = 'Exam Schedule';
$PAGE_ICON  = 'fa-solid fa-file-pen';

$exams = [
    ['Midterm Examination',  'October 6–10, 2025',  'All Year Levels', 'Pending'],
    ['Final Examination',    'December 8–12, 2025', 'All Year Levels', 'Pending'],
    ['Qualifying Exam (4th)','September 20, 2025',  '4th Year Only',   'Upcoming'],
];

ob_start();
?>
<div class="crud-card">
  <div class="crud-header"><h3>Examination Schedule — A.Y. 2025-2026</h3></div>
  <table class="crud-table">
    <thead>
      <tr><th>Examination</th><th>Date</th><th>Scope</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php foreach ($exams as [$name, $date, $scope, $status]):
        $color = $status === 'Upcoming' ? '#f59e0b' : '#888';
      ?>
      <tr>
        <td style="font-weight:600;"><?= $name ?></td>
        <td><?= $date ?></td>
        <td style="font-size:0.78rem;color:#666;"><?= $scope ?></td>
        <td><span style="font-size:0.72rem;font-weight:700;color:<?= $color ?>;"><?= $status ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p style="font-size:0.78rem;color:#aaa;margin-top:12px;">
    <i class="fa-solid fa-circle-info"></i>
    Exact room assignments will be posted one week before each exam period.
  </p>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';

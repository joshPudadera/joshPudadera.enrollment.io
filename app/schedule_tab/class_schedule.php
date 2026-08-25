<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'schedule';
$PAGE_TITLE = 'Class Schedule';
$PAGE_ICON  = 'fa-solid fa-calendar-days';

$days    = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$periods = ['7:00–8:00 AM','8:00–9:00 AM','9:00–10:00 AM','10:00–11:00 AM',
            '11:00 AM–12:00 PM','1:00–2:00 PM','2:00–3:00 PM','3:00–4:00 PM','4:00–5:00 PM'];

ob_start();
?>
<div class="crud-card" style="overflow-x:auto;">
  <div class="crud-header">
    <h3>Weekly Class Schedule — A.Y. 2025-2026, 1st Semester</h3>
  </div>
  <table class="crud-table" style="min-width:700px;">
    <thead>
      <tr>
        <th style="width:110px;">Time</th>
        <?php foreach ($days as $d): ?><th><?= $d ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($periods as $p): ?>
      <tr>
        <td style="font-size:0.72rem;color:#888;white-space:nowrap;"><?= $p ?></td>
        <?php foreach ($days as $d): ?>
        <td style="font-size:0.75rem;color:#aaa;text-align:center;">—</td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p style="font-size:0.78rem;color:#aaa;margin-top:12px;padding:0 4px;">
    <i class="fa-solid fa-circle-info"></i>
    Schedules are assigned by your adviser. Contact the registrar for details.
  </p>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';

<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))       { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin')     { header('Location: ../student_dashboard/dashboard.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

// Stats
$total_students = $active_students = $inactive_students = 0;
$r = $conn->query("SELECT COUNT(*) c FROM students");                          if($r) $total_students   = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM students WHERE status='Active'");    if($r) $active_students  = (int)$r->fetch_assoc()['c'];
$inactive_students = $total_students - $active_students;

$pending_enr = $approved_enr = $enrolled_count = $waiting_count = 0;
$r = $conn->query("SHOW TABLES LIKE 'pre_registrations'");
if ($r && $r->num_rows > 0) {
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Pending'");  if($r) $pending_enr   = (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Approved'"); if($r) $approved_enr  = (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Enrolled'"); if($r) $enrolled_count= (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SHOW TABLES LIKE 'waiting_list'");
    if ($r && $r->num_rows > 0) {
        $r = $conn->query("SELECT COUNT(*) c FROM waiting_list WHERE status='Waiting'"); if($r) $waiting_count = (int)$r->fetch_assoc()['c'];
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

// Paginated student table
$rows_per_page = 5;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $rows_per_page;
$total_rows   = $total_students;
$total_pages  = max(1, (int)ceil($total_rows / $rows_per_page));
$page         = min($page, $total_pages);
$students = $conn->query("SELECT * FROM students ORDER BY id DESC LIMIT $rows_per_page OFFSET $offset");

// Recent pre-registrations
$recent_apps = [];
$r = $conn->query("SHOW TABLES LIKE 'pre_registrations'");
if ($r && $r->num_rows > 0) {
    $res = $conn->query("SELECT * FROM pre_registrations ORDER BY submitted_at DESC LIMIT 5");
    if ($res) while ($row = $res->fetch_assoc()) $recent_apps[] = $row;
}

function page_button(int $n, int $cur, int $tot): string {
    $active   = $n===$cur?' active':''; $disabled=($n<1||$n>$tot)?' disabled':'';
    $link     = ($n<1||$n>$tot)?'#':"?page={$n}#crud-table";
    return "<a href=\"$link\" class=\"pg-btn{$active}{$disabled}\">$n</a>";
}
function page_label(string $label, int $n, int $cur, int $tot): string {
    $disabled=($n<1||$n>$tot)?' disabled':'';
    $link=($n<1||$n>$tot)?'#':"?page={$n}#crud-table";
    return "<a href=\"$link\" class=\"pg-btn pg-label{$disabled}\">$label</a>";
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
</head>
<body>
<?php $APP_ROOT='../'; $ACTIVE_NAV='home'; require_once __DIR__.'/sidebar.php'; ?>

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

    <!-- Stat cards row 1 -->
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
        <div class="card-label"><i class="fa-solid fa-id-badge" style="color:#2563eb;"></i> Enrolled</div>
        <div class="card-amount" style="color:#2563eb;"><?= $enrolled_count ?></div>
        <div class="card-detail"><?= $approved_enr ?> approved, <?= $waiting_count ?> waiting</div>
        <a href="../enrollment_tab/enrollment_dashboard.php" class="card-btn" style="margin-top:8px;">
          <i class="fa-solid fa-arrow-right"></i> Manage
        </a>
      </div>
    </div>

    <!-- Chart -->
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

    <!-- Recent applications -->
    <?php if (!empty($recent_apps)): ?>
    <div class="crud-card" style="margin-bottom:20px;">
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
            $sc = match($app['status']){'Approved'=>'badge-active','Rejected'=>'badge-inactive',default=>''};
            $ps = $app['status']==='Pending'?'background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;':'';
          ?>
          <tr>
            <td><?= htmlspecialchars($app['first_name'].' '.$app['last_name']) ?></td>
            <td style="font-size:.75rem;"><?= htmlspecialchars(str_replace('Bachelor of Science in ','BS ',$app['course'])) ?></td>
            <td><?php if($ps):?><span style="<?=$ps?>"><?=$app['status']?></span><?php else:?><span class="<?=$sc?>"><?=$app['status']?></span><?php endif;?></td>
            <td style="font-size:.75rem;color:#888;"><?= date('M d, Y',strtotime($app['submitted_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Students CRUD table -->
    <div class="crud-card" id="crud-table">
      <div class="crud-header">
        <h3>Students</h3>
        <button class="btn-add" id="btnAddStudent">
          <i class="fa-solid fa-plus"></i> Add
        </button>
      </div>
      <div class="bulk-toolbar" id="bulkToolbar">
        <span class="bulk-count" id="bulkCount">0 selected</span>
        <div class="bulk-actions">
          <button class="btn-bulk-delete" id="btnBulkDelete"><i class="fa-solid fa-trash"></i> Delete Selected</button>
          <button class="btn-bulk-active" id="btnBulkActive">Set Active</button>
          <button class="btn-bulk-inactive" id="btnBulkInactive">Set Inactive</button>
        </div>
      </div>
      <table class="crud-table">
        <thead><tr>
          <th style="width:38px;"><input type="checkbox" id="checkAll"/></th>
          <th>Name</th><th>Course</th><th>Year / Section</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody id="crudTbody">
          <?php if($students && $students->num_rows>0):
            while($row=$students->fetch_assoc()):
              $full_name=htmlspecialchars($row['first_name'].' '.$row['last_name']);
              $course=htmlspecialchars($row['course']);
              $year_sec=htmlspecialchars($row['year_level'].' / '.$row['section']);
              $status=$row['status']; $sid=(int)$row['id'];
          ?>
          <tr data-id="<?=$sid?>">
            <td><input type="checkbox" class="row-check" value="<?=$sid?>"/></td>
            <td><?=$full_name?></td><td><?=$course?></td><td><?=$year_sec?></td>
            <td><span class="badge-<?=strtolower($status)?>"><?=$status?></span></td>
            <td class="actions-cell">
              <button class="btn-icon btn-view" title="View" data-id="<?=$sid?>"><i class="fa-solid fa-eye" style="color:#22c55e;"></i></button>
              <button class="btn-icon btn-edit" title="Edit" data-id="<?=$sid?>"><i class="fa-solid fa-pen-to-square" style="color:#f59e0b;"></i></button>
              <button class="btn-icon btn-delete" title="Delete" data-id="<?=$sid?>" data-name="<?=$full_name?>"><i class="fa-solid fa-trash" style="color:#ef4444;"></i></button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:#aaa;">No students found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <?php if($total_pages>0): ?>
      <div class="crud-pagination">
        <?php echo page_label('&laquo; Previous',$page-1,$page,$total_pages);
        $win=2;$s=max(1,$page-$win);$e=min($total_pages,$page+$win);
        if($s>1){echo page_button(1,$page,$total_pages);if($s>2)echo '<span class="pg-ellipsis">&hellip;</span>';}
        for($p=$s;$p<=$e;$p++)echo page_button($p,$page,$total_pages);
        if($e<$total_pages){if($e<$total_pages-1)echo '<span class="pg-ellipsis">&hellip;</span>';echo page_button($total_pages,$page,$total_pages);}
        echo page_label('Next &raquo;',$page+1,$page,$total_pages); ?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- Modals (same as old dashboard) -->
<div class="modal-overlay" id="viewModal"><div class="modal"><div class="modal-header"><span>Student Information</span><button class="modal-close" data-close="viewModal">&times;</button></div><div class="modal-body"><div class="modal-section-title"><i class="fa-solid fa-user"></i> Personal Information</div><div class="modal-row"><span>Name:</span><span id="vName"></span></div><div class="modal-row"><span>Birthday:</span><span id="vBirthday"></span></div><div class="modal-row"><span>Phone:</span><span id="vPhone"></span></div><div class="modal-section-title" style="margin-top:14px;"><i class="fa-solid fa-school"></i> Enrollment</div><div class="modal-row"><span>Course:</span><span id="vCourse"></span></div><div class="modal-row"><span>Year:</span><span id="vYear"></span></div><div class="modal-row"><span>Section:</span><span id="vSection"></span></div></div><div class="modal-footer"><button class="btn-modal-close" data-close="viewModal">Close</button></div></div></div>
<div class="modal-overlay" id="formModal"><div class="modal modal-lg"><div class="modal-header"><span id="formModalTitle">Student Form</span><button class="modal-close" data-close="formModal">&times;</button></div><div class="modal-body"><form id="studentCrudForm"><input type="hidden" id="crudId" name="id" value=""/><input type="hidden" id="crudAction" name="action" value="add"/><div class="form-grid"><div class="form-field"><label>First Name</label><input type="text" id="cFirst" name="first_name" placeholder="First name"/><span class="field-error"></span></div><div class="form-field"><label>Last Name</label><input type="text" id="cLast" name="last_name" placeholder="Last name"/><span class="field-error"></span></div><div class="form-field"><label>Birthday</label><input type="date" id="cBday" name="birthday"/><span class="field-error"></span></div><div class="form-field"><label>Status</label><select id="cStatus" name="status"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div><div class="form-field full"><label>Course</label><input type="text" id="cCourse" name="course" placeholder="e.g. BS Information Technology"/><span class="field-error"></span></div><div class="form-field"><label>Year Level</label><input type="text" id="cYear" name="year_level" placeholder="e.g. 4th Year"/><span class="field-error"></span></div><div class="form-field"><label>Section</label><input type="text" id="cSection" name="section" placeholder="e.g. 41018"/><span class="field-error"></span></div><div class="form-field"><label>Phone</label><input type="text" id="cPhone" name="phone" placeholder="09XXXXXXXXX"/><span class="field-error"></span></div></div></form></div><div class="modal-footer modal-footer-split"><button class="btn-modal-cancel" data-close="formModal">Cancel</button><button class="btn-modal-submit" id="btnCrudSubmit">Submit</button></div></div></div>
<div class="modal-overlay" id="deleteModal"><div class="modal modal-sm"><div class="modal-body" style="padding:28px 24px 16px;"><h3 style="font-size:1.1rem;font-weight:700;margin-bottom:10px;">Are you sure?</h3><p style="font-size:.85rem;color:#555;">Delete <strong id="deleteStudentName" style="color:#2563eb;"></strong>?</p></div><div class="modal-footer modal-footer-split"><button class="btn-modal-cancel" data-close="deleteModal">Cancel</button><button class="btn-modal-confirm" id="btnConfirmDelete">Confirm</button></div></div></div>
<div class="modal-overlay" id="bulkDeleteModal"><div class="modal modal-sm"><div class="modal-body" style="padding:28px 24px 16px;"><h3 style="font-size:1.1rem;font-weight:700;margin-bottom:10px;">Are you sure?</h3><p style="font-size:.85rem;color:#555;">Delete <strong id="bulkDeleteCount" style="color:#2563eb;"></strong> student(s)?</p></div><div class="modal-footer modal-footer-split"><button class="btn-modal-cancel" data-close="bulkDeleteModal">Cancel</button><button class="btn-modal-confirm" id="btnConfirmBulkDelete">Confirm</button></div></div></div>
<div class="notif-overlay" id="notifOverlay"></div>
<div class="notif-panel" id="notifPanel">
  <div class="notif-header"><span>Notifications</span><div class="notif-header-actions"><button class="notif-mark-all" id="notifMarkAll">Mark all as read</button><button class="notif-close" id="notifClose">&times;</button></div></div>
  <div class="notif-list" id="notifList">
    <?php
    $notif_pending = 0;
    if (!empty($pending_enr)) $notif_pending = $pending_enr;
    if ($notif_pending > 0): ?>
    <div class="notif-item unread" data-notif="enr">
      <span class="notif-dot"></span>
      <div class="notif-text"><div class="notif-title">Pending Applications</div>
      <div class="notif-desc"><?= $notif_pending ?> application<?= $notif_pending!==1?'s':'' ?> awaiting validation.</div></div>
      <span class="notif-time">Now</span>
    </div>
    <?php endif; ?>
    <?php $nr=$conn->query("SELECT first_name,last_name,created_at FROM students ORDER BY created_at DESC LIMIT 3");
    if($nr) while($ns=$nr->fetch_assoc()):
      $ago=max(1,round((time()-strtotime($ns['created_at']))/60));
      $ts=$ago<60?$ago.'m ago':($ago<1440?round($ago/60).'h ago':date('M d',strtotime($ns['created_at'])));
    ?>
    <div class="notif-item" data-notif="s">
      <span class="notif-dot"></span>
      <div class="notif-text"><div class="notif-title">Student record</div>
      <div class="notif-desc"><?= htmlspecialchars($ns['first_name'].' '.$ns['last_name']) ?> is in the system.</div></div>
      <span class="notif-time"><?= $ts ?></span>
    </div>
    <?php endwhile; ?>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>
const STUDENT_API = '../shared/student_actions.php';
</script>
<script src="../js/dashboard.js"></script>
</body></html>
<?php $conn->close(); ?>

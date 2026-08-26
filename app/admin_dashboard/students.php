<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../student_dashboard/dashboard.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

// Search / filter params
$search = trim($_GET['q']      ?? '');
$filter = trim($_GET['status'] ?? '');

// Build WHERE clause
$where_parts = [];
if ($search !== '') {
    $esc = $conn->real_escape_string($search);
    $where_parts[] = "(first_name LIKE '%$esc%' OR last_name LIKE '%$esc%' OR course LIKE '%$esc%')";
}
if ($filter === 'Active' || $filter === 'Inactive') {
    $esc = $conn->real_escape_string($filter);
    $where_parts[] = "status = '$esc'";
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Pagination
$rows_per_page = 10;
$page          = max(1, (int)($_GET['page'] ?? 1));
$count_res     = $conn->query("SELECT COUNT(*) c FROM students $where_sql");
$total_rows    = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;
$total_pages   = max(1, (int)ceil($total_rows / $rows_per_page));
$page          = min($page, $total_pages);
$offset        = ($page - 1) * $rows_per_page;

$students = $conn->query("SELECT * FROM students $where_sql ORDER BY id DESC LIMIT $rows_per_page OFFSET $offset");

function page_btn(int $n, int $cur, int $tot, string $q, string $st): string {
    $active   = $n === $cur ? ' active' : '';
    $disabled = ($n < 1 || $n > $tot) ? ' disabled' : '';
    $qs = http_build_query(['q' => $q, 'status' => $st, 'page' => $n]);
    $link = ($n < 1 || $n > $tot) ? '#' : "?{$qs}#crud-table";
    return "<a href=\"$link\" class=\"pg-btn{$active}{$disabled}\">$n</a>";
}
function page_lbl(string $label, int $n, int $cur, int $tot, string $q, string $st): string {
    $disabled = ($n < 1 || $n > $tot) ? ' disabled' : '';
    $qs = http_build_query(['q' => $q, 'status' => $st, 'page' => $n]);
    $link = ($n < 1 || $n > $tot) ? '#' : "?{$qs}#crud-table";
    return "<a href=\"$link\" class=\"pg-btn pg-label{$disabled}\">$label</a>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Students — BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'students'; require_once __DIR__ . '/sidebar.php'; ?>

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
      <h2 class="page-title"><i class="fa-solid fa-user-graduate"></i> Students</h2>
    </div>

    <!-- Search / filter form -->
    <form method="get" action="students.php" class="crud-search-bar" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;align-items:center;">
      <div class="search-wrap" style="flex:1;min-width:200px;max-width:360px;">
        <input type="text" name="q" placeholder="Search by name or course…"
               value="<?= htmlspecialchars($search) ?>"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <select name="status" style="padding:8px 14px;border:1px solid #ddd;border-radius:8px;font-size:.85rem;background:#fff;cursor:pointer;">
        <option value="">All Status</option>
        <option value="Active"   <?= $filter==='Active'   ? 'selected' : '' ?>>Active</option>
        <option value="Inactive" <?= $filter==='Inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
      <button type="submit" class="card-btn" style="padding:8px 18px;"><i class="fa-solid fa-filter"></i> Filter</button>
      <?php if ($search !== '' || $filter !== ''): ?>
      <a href="students.php" class="card-btn" style="padding:8px 14px;background:#f3f4f6;color:#555;text-decoration:none;">
        <i class="fa-solid fa-xmark"></i> Clear
      </a>
      <?php endif; ?>
    </form>

    <!-- CRUD table -->
    <div class="crud-card" id="crud-table">
      <div class="crud-header">
        <h3>
          All Students
          <?php if ($total_rows > 0): ?>
          <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:8px;"><?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?></span>
          <?php endif; ?>
        </h3>
        <button class="btn-add" id="btnAddStudent">
          <i class="fa-solid fa-plus"></i> Add Student
        </button>
      </div>

      <!-- Bulk toolbar -->
      <div class="bulk-toolbar" id="bulkToolbar">
        <span class="bulk-count" id="bulkCount">0 selected</span>
        <div class="bulk-actions">
          <button class="btn-bulk-delete"   id="btnBulkDelete">  <i class="fa-solid fa-trash"></i> Delete Selected</button>
          <button class="btn-bulk-active"   id="btnBulkActive">  Set Active</button>
          <button class="btn-bulk-inactive" id="btnBulkInactive">Set Inactive</button>
        </div>
      </div>

      <table class="crud-table">
        <thead><tr>
          <th style="width:38px;"><input type="checkbox" id="checkAll"/></th>
          <th>Name</th>
          <th>Course</th>
          <th>Year Level</th>
          <th>Section</th>
          <th>Phone</th>
          <th>Status</th>
          <th>Actions</th>
        </tr></thead>
        <tbody id="crudTbody">
          <?php if ($students && $students->num_rows > 0):
            while ($row = $students->fetch_assoc()):
              $full_name = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
              $course    = htmlspecialchars($row['course']);
              $year_lv   = htmlspecialchars($row['year_level']);
              $section   = htmlspecialchars($row['section']);
              $phone     = htmlspecialchars($row['phone']);
              $status    = $row['status'];
              $sid       = (int)$row['id'];
          ?>
          <tr data-id="<?= $sid ?>">
            <td><input type="checkbox" class="row-check" value="<?= $sid ?>"/></td>
            <td><?= $full_name ?></td>
            <td style="font-size:.8rem;"><?= $course ?></td>
            <td><?= $year_lv ?></td>
            <td><?= $section ?></td>
            <td><?= $phone ?></td>
            <td><span class="badge-<?= strtolower($status) ?>"><?= $status ?></span></td>
            <td class="actions-cell">
              <button class="btn-icon btn-view"   title="View"   data-id="<?= $sid ?>"><i class="fa-solid fa-eye"            style="color:#22c55e;"></i></button>
              <button class="btn-icon btn-edit"   title="Edit"   data-id="<?= $sid ?>"><i class="fa-solid fa-pen-to-square"  style="color:#f59e0b;"></i></button>
              <button class="btn-icon btn-delete" title="Delete" data-id="<?= $sid ?>" data-name="<?= $full_name ?>"><i class="fa-solid fa-trash" style="color:#ef4444;"></i></button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="8" style="text-align:center;padding:32px;color:#aaa;">
            <?= ($search !== '' || $filter !== '') ? 'No students match your search.' : 'No students found.' ?>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($total_pages > 1): ?>
      <div class="crud-pagination">
        <?php
        echo page_lbl('&laquo; Previous', $page - 1, $page, $total_pages, $search, $filter);
        $win = 2; $s = max(1, $page - $win); $e = min($total_pages, $page + $win);
        if ($s > 1) { echo page_btn(1, $page, $total_pages, $search, $filter); if ($s > 2) echo '<span class="pg-ellipsis">&hellip;</span>'; }
        for ($p = $s; $p <= $e; $p++) echo page_btn($p, $page, $total_pages, $search, $filter);
        if ($e < $total_pages) { if ($e < $total_pages - 1) echo '<span class="pg-ellipsis">&hellip;</span>'; echo page_btn($total_pages, $page, $total_pages, $search, $filter); }
        echo page_lbl('Next &raquo;', $page + 1, $page, $total_pages, $search, $filter);
        ?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- View Modal -->
<div class="modal-overlay" id="viewModal">
  <div class="modal">
    <div class="modal-header">
      <span>Student Information</span>
      <button class="modal-close" data-close="viewModal">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-section-title"><i class="fa-solid fa-user"></i> Personal Information</div>
      <div class="modal-row"><span>Name:</span><span id="vName"></span></div>
      <div class="modal-row"><span>Birthday:</span><span id="vBirthday"></span></div>
      <div class="modal-row"><span>Phone:</span><span id="vPhone"></span></div>
      <div class="modal-section-title" style="margin-top:14px;"><i class="fa-solid fa-school"></i> Enrollment</div>
      <div class="modal-row"><span>Course:</span><span id="vCourse"></span></div>
      <div class="modal-row"><span>Year:</span><span id="vYear"></span></div>
      <div class="modal-row"><span>Section:</span><span id="vSection"></span></div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-close" data-close="viewModal">Close</button>
    </div>
  </div>
</div>

<!-- Form Modal (Add / Edit) -->
<div class="modal-overlay" id="formModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span id="formModalTitle">Student Form</span>
      <button class="modal-close" data-close="formModal">&times;</button>
    </div>
    <div class="modal-body">
      <form id="studentCrudForm">
        <input type="hidden" id="crudId"     name="id"     value=""/>
        <input type="hidden" id="crudAction" name="action" value="add"/>
        <div class="form-grid">
          <div class="form-field">
            <label>First Name</label>
            <input type="text" id="cFirst"  name="first_name"  placeholder="First name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Last Name</label>
            <input type="text" id="cLast"   name="last_name"   placeholder="Last name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Birthday</label>
            <input type="date" id="cBday"   name="birthday"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Status</label>
            <select id="cStatus" name="status">
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
          <div class="form-field full">
            <label>Course</label>
            <input type="text" id="cCourse" name="course"      placeholder="e.g. BS Information Technology"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Year Level</label>
            <input type="text" id="cYear"    name="year_level" placeholder="e.g. 4th Year"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Section</label>
            <input type="text" id="cSection" name="section"    placeholder="e.g. 41018"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Phone</label>
            <input type="text" id="cPhone"   name="phone"      placeholder="09XXXXXXXXX"/>
            <span class="field-error"></span>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="formModal">Cancel</button>
      <button class="btn-modal-submit" id="btnCrudSubmit">Submit</button>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal modal-sm">
    <div class="modal-body" style="padding:28px 24px 16px;">
      <h3 style="font-size:1.1rem;font-weight:700;margin-bottom:10px;">Are you sure?</h3>
      <p style="font-size:.85rem;color:#555;">Delete <strong id="deleteStudentName" style="color:#2563eb;"></strong>?</p>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="deleteModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmDelete">Confirm</button>
    </div>
  </div>
</div>

<!-- Bulk Delete Modal -->
<div class="modal-overlay" id="bulkDeleteModal">
  <div class="modal modal-sm">
    <div class="modal-body" style="padding:28px 24px 16px;">
      <h3 style="font-size:1.1rem;font-weight:700;margin-bottom:10px;">Are you sure?</h3>
      <p style="font-size:.85rem;color:#555;">Delete <strong id="bulkDeleteCount" style="color:#2563eb;"></strong> student(s)?</p>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="bulkDeleteModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmBulkDelete">Confirm</button>
    </div>
  </div>
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
    $nr = $conn->query("SELECT first_name,last_name,created_at FROM students ORDER BY created_at DESC LIMIT 3");
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
const STUDENT_API = '../shared/student_actions.php';
</script>
<script src="../js/dashboard.js"></script>
</body>
</html>
<?php $conn->close(); ?>

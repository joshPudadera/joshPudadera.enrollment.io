<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))          { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'staff')        { header('Location: ../auth/signin.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'S', 0, 1));

$search       = trim($_GET['q']      ?? '');
$filter       = trim($_GET['status'] ?? '');
$filter_step  = trim($_GET['step']   ?? '');

$col_check   = $conn->query("SHOW COLUMNS FROM students LIKE 'pre_reg_id'");
$has_pre_reg = $col_check && $col_check->num_rows > 0;

$where_parts = ['1=1'];

if ($search !== '') {
    $esc = $conn->real_escape_string($search);
    $where_parts[] = "(s.first_name LIKE '%$esc%'
                    OR s.last_name  LIKE '%$esc%'
                    OR s.course     LIKE '%$esc%'"
        . ($has_pre_reg ? " OR p.ref_number LIKE '%$esc%' OR e.id_number LIKE '%$esc%'" : '') . ")";
}
if ($filter === 'Active' || $filter === 'Inactive') {
    $esc = $conn->real_escape_string($filter);
    $where_parts[] = "s.status = '$esc'";
}
if ($filter_step === 'no_id') {
    $where_parts[] = "e.id IS NULL";
} elseif ($filter_step === 'no_grade') {
    $where_parts[] = "e.id IS NOT NULL AND e.grade_confirmed = 0";
} elseif ($filter_step === 'no_section') {
    $where_parts[] = "e.id IS NOT NULL AND e.grade_confirmed = 1
                      AND (e.section IS NULL OR e.section = '' OR e.section = 'TBA')";
} elseif ($filter_step === 'complete') {
    $where_parts[] = "e.id IS NOT NULL AND e.grade_confirmed = 1
                      AND e.section IS NOT NULL AND e.section != '' AND e.section != 'TBA'";
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

$join_sql = $has_pre_reg
    ? "FROM students s
       LEFT JOIN pre_registrations p ON s.pre_reg_id = p.id
       LEFT JOIN enrollments e ON e.pre_reg_id = s.pre_reg_id"
    : "FROM students s
       LEFT JOIN enrollments e ON e.pre_reg_id = s.pre_reg_id";

$rows_per_page = 10;
$page          = max(1, (int)($_GET['page'] ?? 1));
$count_res     = $conn->query("SELECT COUNT(*) c $join_sql $where_sql");
$total_rows    = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;
$total_pages   = max(1, (int)ceil($total_rows / $rows_per_page));
$page          = min($page, $total_pages);
$offset        = ($page - 1) * $rows_per_page;

$select_extra = $has_pre_reg
    ? ", p.ref_number, p.status AS app_status"
    : ", NULL AS ref_number, NULL AS app_status";

$students = $conn->query(
    "SELECT s.*
     $select_extra,
     e.id          AS enrollment_db_id,
     e.id_number,
     e.year_level  AS enr_year_level,
     e.section     AS enr_section,
     e.grade_confirmed,
     e.is_cross,
     e.enrolled_at
     $join_sql
     $where_sql
     ORDER BY s.id DESC
     LIMIT $rows_per_page OFFSET $offset"
);

$step_counts = ['no_id' => 0, 'no_grade' => 0, 'no_section' => 0, 'complete' => 0];
if ($has_pre_reg) {
    $base_join = "FROM students s
                  LEFT JOIN pre_registrations p ON s.pre_reg_id = p.id
                  LEFT JOIN enrollments e ON e.pre_reg_id = s.pre_reg_id
                  WHERE (s.pre_reg_id IS NOT NULL OR p.status IN ('Approved','Enrolled'))";
    $sc = $conn->query("SELECT
        SUM(e.id IS NULL) AS no_id,
        SUM(e.id IS NOT NULL AND e.grade_confirmed=0) AS no_grade,
        SUM(e.id IS NOT NULL AND e.grade_confirmed=1 AND (e.section IS NULL OR e.section='' OR e.section='TBA')) AS no_section,
        SUM(e.id IS NOT NULL AND e.grade_confirmed=1 AND e.section IS NOT NULL AND e.section!='' AND e.section!='TBA') AS complete
        $base_join");
    if ($sc && $r = $sc->fetch_assoc()) {
        $step_counts = [
            'no_id'      => (int)$r['no_id'],
            'no_grade'   => (int)$r['no_grade'],
            'no_section' => (int)$r['no_section'],
            'complete'   => (int)$r['complete'],
        ];
    }
}

function course_abbr(string $course): string {
    $map = [
        'Bachelor of Science in Information Technology' => 'BSIT',
        'Bachelor of Science in Computer Science'       => 'BSCS',
        'Bachelor of Science in Information Systems'    => 'BSIS',
        'Bachelor of Science in Business Administration'=> 'BSBA',
        'Bachelor of Science in Accountancy'            => 'BSA',
        'Bachelor of Science in Nursing'                => 'BSN',
        'Bachelor of Science in Education'              => 'BSEd',
        'Bachelor of Science in Psychology'             => 'BSPsych',
    ];
    if (isset($map[$course])) return $map[$course];
    $clean = preg_replace('/^Bachelor of (Science|Arts) in\s*/i', '', $course);
    $words = preg_split('/\s+/', $clean);
    $skip  = ['of','in','and','the','&'];
    $abbr  = '';
    foreach ($words as $w) {
        if (!in_array(strtolower($w), $skip)) $abbr .= strtoupper($w[0]);
    }
    return $abbr ?: $course;
}

function page_btn(int $n, int $cur, int $tot, string $q, string $st, string $step): string {
    $active   = $n === $cur ? ' active' : '';
    $disabled = ($n < 1 || $n > $tot) ? ' disabled' : '';
    $qs   = http_build_query(['q' => $q, 'status' => $st, 'step' => $step, 'page' => $n]);
    $link = ($n < 1 || $n > $tot) ? '#' : "?{$qs}#crud-table";
    return "<a href=\"$link\" class=\"pg-btn{$active}{$disabled}\">$n</a>";
}
function page_lbl(string $label, int $n, int $cur, int $tot, string $q, string $st, string $step): string {
    $disabled = ($n < 1 || $n > $tot) ? ' disabled' : '';
    $qs   = http_build_query(['q' => $q, 'status' => $st, 'step' => $step, 'page' => $n]);
    $link = ($n < 1 || $n > $tot) ? '#' : "?{$qs}#crud-table";
    return "<a href=\"$link\" class=\"pg-btn pg-label{$disabled}\">$label</a>";
}

function pipeline_badge(array $row): string {
    if (!$row['enrollment_db_id']) {
        return '<span style="display:inline-flex;align-items:center;gap:4px;background:#fee2e2;
                color:#dc2626;border-radius:20px;padding:3px 9px;font-size:.68rem;font-weight:700;">
                <i class="fa-solid fa-id-badge"></i> No ID</span>';
    }
    if (!$row['grade_confirmed']) {
        return '<span style="display:inline-flex;align-items:center;gap:4px;background:#fff7ed;
                color:#d97706;border-radius:20px;padding:3px 9px;font-size:.68rem;font-weight:700;">
                <i class="fa-solid fa-layer-group"></i> Grade Pending</span>';
    }
    $sec = $row['enr_section'] ?? '';
    if (!$sec || $sec === 'TBA') {
        return '<span style="display:inline-flex;align-items:center;gap:4px;background:#fffbeb;
                color:#b45309;border-radius:20px;padding:3px 9px;font-size:.68rem;font-weight:700;">
                <i class="fa-solid fa-clock"></i> Awaiting Section</span>';
    }
    return '<span style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;
            color:#16a34a;border-radius:20px;padding:3px 9px;font-size:.68rem;font-weight:700;">
            <i class="fa-solid fa-circle-check"></i> Complete</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Students — BCP Staff</title>
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

    <div style="margin:0 24px 16px;background:#eff6ff;border:1.5px solid #bfdbfe;
                border-radius:10px;padding:12px 16px;font-size:.82rem;color:#1e40af;
                display:flex;align-items:center;gap:10px;">
      <i class="fa-solid fa-circle-info" style="font-size:1rem;flex-shrink:0;"></i>
      <span>
        This list only shows students whose applications have been
        <strong>Approved or Enrolled</strong> through the validation process.
      </span>
    </div>

    <form method="get" action="students.php"
          style="display:flex;gap:10px;flex-wrap:wrap;margin:0 24px 14px;align-items:center;">
      <div class="search-wrap" style="flex:1;min-width:200px;max-width:360px;">
        <input type="text" name="q" placeholder="Search by name, course, ref no. or ID…"
               value="<?= htmlspecialchars($search) ?>"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <select name="status"
              style="padding:8px 14px;border:1px solid #ddd;border-radius:8px;font-size:.85rem;background:#fff;cursor:pointer;">
        <option value="">All Status</option>
        <option value="Active"   <?= $filter==='Active'  ?'selected':'' ?>>Active</option>
        <option value="Inactive" <?= $filter==='Inactive'?'selected':'' ?>>Inactive</option>
      </select>
      <input type="hidden" name="step" value="<?= htmlspecialchars($filter_step) ?>"/>
      <button type="submit" class="card-btn" style="padding:8px 18px;">
        <i class="fa-solid fa-filter"></i> Filter
      </button>
      <?php if ($search !== '' || $filter !== '' || $filter_step !== ''): ?>
      <a href="students.php" class="card-btn" style="padding:8px 14px;background:#f3f4f6;color:#555;text-decoration:none;">
        <i class="fa-solid fa-xmark"></i> Clear
      </a>
      <?php endif; ?>
    </form>

    <?php if ($has_pre_reg): ?>
    <div style="margin:0 24px 18px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <span style="font-size:.75rem;color:#888;font-weight:600;">Filter by pipeline step:</span>
      <?php
      $step_filters = [
          ''           => ['All',              '#6b7280','#f3f4f6'],
          'no_id'      => ['No ID Generated',  '#dc2626','#fee2e2'],
          'no_grade'   => ['Grade Pending',    '#d97706','#fff7ed'],
          'no_section' => ['Awaiting Section', '#b45309','#fffbeb'],
          'complete'   => ['Complete',         '#16a34a','#dcfce7'],
      ];
      $count_map = array_merge([''=>$total_rows], $step_counts);
      foreach ($step_filters as $key => [$label, $color, $bg]):
          $active_cls = $filter_step === $key ? "border:2px solid $color;" : 'border:1.5px solid #e5e7eb;';
          $qs = http_build_query(['q'=>$search,'status'=>$filter,'step'=>$key]);
      ?>
      <a href="?<?= $qs ?>#crud-table"
         style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;
                background:<?= $bg ?>;<?= $active_cls ?>font-size:.75rem;font-weight:600;
                color:<?= $color ?>;text-decoration:none;">
        <?= $label ?>
        <span style="background:<?= $color ?>;color:#fff;border-radius:50%;width:18px;height:18px;
                     display:inline-flex;align-items:center;justify-content:center;font-size:.65rem;">
          <?= $count_map[$key] ?? 0 ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="crud-card" id="crud-table">
      <div class="crud-header">
        <h3>
          Validated Students
          <?php if ($total_rows > 0): ?>
          <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:8px;">
            <?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?>
          </span>
          <?php endif; ?>
        </h3>
      </div>

      <table class="crud-table">
        <thead><tr>
          <th>Name</th><th>Course</th><th>ID Number</th><th>Year Level</th>
          <th>Section</th><th>Phone</th><th>Ref No.</th><th>Pipeline</th>
          <th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody id="crudTbody">
          <?php if ($students && $students->num_rows > 0):
            while ($row = $students->fetch_assoc()):
              $full_name = htmlspecialchars($row['first_name'].' '.$row['last_name']);
              $course    = htmlspecialchars($row['course']);
              $ref_num   = htmlspecialchars($row['ref_number'] ?? '—');
              $phone     = htmlspecialchars($row['phone']);
              $status    = $row['status'];
              $sid       = (int)$row['id'];
              $id_number = $row['id_number']      ?? null;
              $year_lv   = $row['enr_year_level'] ?? $row['year_level'];
              $section   = $row['enr_section']    ?? $row['section'];
              $confirmed = (bool)($row['grade_confirmed'] ?? false);
          ?>
          <tr data-id="<?= $sid ?>">
            <td><?= $full_name ?></td>
            <td style="font-size:.78rem;" title="<?= $course ?>"><?= htmlspecialchars(course_abbr($row['course'])) ?></td>
            <td>
              <?php if ($id_number): ?>
              <code style="font-size:.72rem;background:#eff6ff;color:#2563eb;padding:2px 8px;border-radius:4px;white-space:nowrap;">
                <?= htmlspecialchars($id_number) ?>
              </code>
              <?php else: ?>
              <span style="color:#ef4444;font-size:.72rem;font-weight:600;"><i class="fa-solid fa-clock"></i> Pending</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($confirmed): ?>
              <span class="badge-active" style="font-size:.72rem;"><?= htmlspecialchars($year_lv) ?></span>
              <?php else: ?>
              <span style="color:#888;font-size:.78rem;"><?= htmlspecialchars($year_lv) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php $sec_clean = $section ?? '';
              if ($sec_clean && $sec_clean !== 'TBA'): ?>
              <span class="badge-active" style="font-size:.72rem;"><?= htmlspecialchars($sec_clean) ?></span>
              <?php elseif ($id_number && !$confirmed): ?>
              <span style="color:#d97706;font-size:.72rem;font-weight:600;"><i class="fa-solid fa-layer-group"></i> Grade first</span>
              <?php elseif ($id_number): ?>
              <span style="color:#f59e0b;font-size:.72rem;font-weight:600;"><i class="fa-solid fa-clock"></i> In queue</span>
              <?php else: ?>
              <span style="color:#aaa;font-size:.72rem;">—</span>
              <?php endif; ?>
            </td>
            <td><?= $phone ?></td>
            <td style="font-size:.72rem;color:#2563eb;font-weight:600;"><?= $ref_num ?></td>
            <td><?= pipeline_badge($row) ?></td>
            <td><span class="badge-<?= strtolower($status) ?>"><?= $status ?></span></td>
            <td class="actions-cell">
              <!-- View only — no edit, no delete for staff -->
              <button class="btn-icon btn-view" title="View" data-id="<?= $sid ?>">
                <i class="fa-solid fa-eye" style="color:#22c55e;"></i>
              </button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="10" style="text-align:center;padding:32px;color:#aaa;">
            <?= ($search!==''||$filter!==''||$filter_step!=='') ? 'No students match your filters.' : 'No validated students yet.' ?>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($total_pages > 1): ?>
      <div class="crud-pagination">
        <?php
        echo page_lbl('&laquo; Previous', $page-1, $page, $total_pages, $search, $filter, $filter_step);
        $win=$win??2; $s=max(1,$page-$win); $e=min($total_pages,$page+$win);
        if ($s>1){ echo page_btn(1,$page,$total_pages,$search,$filter,$filter_step); if($s>2) echo '<span class="pg-ellipsis">&hellip;</span>'; }
        for ($p=$s;$p<=$e;$p++) echo page_btn($p,$page,$total_pages,$search,$filter,$filter_step);
        if ($e<$total_pages){ if($e<$total_pages-1) echo '<span class="pg-ellipsis">&hellip;</span>'; echo page_btn($total_pages,$page,$total_pages,$search,$filter,$filter_step); }
        echo page_lbl('Next &raquo;', $page+1, $page, $total_pages, $search, $filter, $filter_step);
        ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- View Modal (read-only) -->
<div class="modal-overlay" id="viewModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span>Student Information</span>
      <button class="modal-close" data-close="viewModal">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-section-title"><i class="fa-solid fa-user"></i> Personal Information</div>
      <div class="modal-row"><span>Name:</span><span id="vName"></span></div>
      <div class="modal-row"><span>Birthday:</span><span id="vBirthday"></span></div>
      <div class="modal-row"><span>Phone:</span><span id="vPhone"></span></div>
      <div class="modal-section-title" style="margin-top:14px;"><i class="fa-solid fa-id-badge"></i> Enrollment Details</div>
      <div class="modal-row"><span>Ref No.:</span><span id="vRef"></span></div>
      <div class="modal-row"><span>ID Number:</span><span id="vIdNum"></span></div>
      <div class="modal-row"><span>Course:</span><span id="vCourse"></span></div>
      <div class="modal-row"><span>Year Level:</span><span id="vYear"></span></div>
      <div class="modal-row"><span>Section:</span><span id="vSection"></span></div>
      <div class="modal-section-title" style="margin-top:14px;"><i class="fa-solid fa-list-check"></i> Pipeline Status</div>
      <div class="modal-row"><span>ID Generated:</span><span id="vIdDone"></span></div>
      <div class="modal-row"><span>Grade Confirmed:</span><span id="vGradeDone"></span></div>
      <div class="modal-row"><span>Section Assigned:</span><span id="vSecDone"></span></div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-close" data-close="viewModal">Close</button>
    </div>
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

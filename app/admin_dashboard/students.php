<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../student_dashboard/dashboard.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

// Search / filter params
$search       = trim($_GET['q']         ?? '');
$filter       = trim($_GET['status']    ?? '');
$filter_step  = trim($_GET['step']      ?? '');   // pipeline step filter

// Check if pre_reg_id column exists yet
$col_check   = $conn->query("SHOW COLUMNS FROM students LIKE 'pre_reg_id'");
$has_pre_reg = $col_check && $col_check->num_rows > 0;

// Build WHERE
// Always JOIN enrollments so we get live pipeline data.
$where_parts = ['1=1'];
if ($has_pre_reg) {
    $where_parts = ["(s.pre_reg_id IS NOT NULL OR p.status IN ('Approved','Enrolled'))"];
}

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

// Pipeline step filter
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

// Pagination
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

// Pipeline step counts for filter badges
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
    // Known mappings first
    $map = [
        'Bachelor of Science in Information Technology' => 'BSIT',
        'Bachelor of Science in Computer Science'       => 'BSCS',
        'Bachelor of Science in Information Systems'    => 'BSIS',
        'Bachelor of Science in Business Administration'=> 'BSBA',
        'Bachelor of Science in Accountancy'            => 'BSA',
        'Bachelor of Science in Nursing'                => 'BSN',
        'Bachelor of Science in Education'              => 'BSEd',
        'Bachelor of Science in Civil Engineering'      => 'BSCE',
        'Bachelor of Science in Electrical Engineering' => 'BSEE',
        'Bachelor of Science in Mechanical Engineering' => 'BSME',
        'Bachelor of Science in Psychology'             => 'BSPsych',
        'Bachelor of Arts in Communication'             => 'BAComm',
    ];
    if (isset($map[$course])) return $map[$course];

    // Generic: strip "Bachelor of Science in" / "Bachelor of Arts in",
    // then build acronym from remaining significant words.
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
    $qs = http_build_query(['q' => $q, 'status' => $st, 'step' => $step, 'page' => $n]);
    $link = ($n < 1 || $n > $tot) ? '#' : "?{$qs}#crud-table";
    return "<a href=\"$link\" class=\"pg-btn{$active}{$disabled}\">$n</a>";
}
function page_lbl(string $label, int $n, int $cur, int $tot, string $q, string $st, string $step): string {
    $disabled = ($n < 1 || $n > $tot) ? ' disabled' : '';
    $qs = http_build_query(['q' => $q, 'status' => $st, 'step' => $step, 'page' => $n]);
    $link = ($n < 1 || $n > $tot) ? '#' : "?{$qs}#crud-table";
    return "<a href=\"$link\" class=\"pg-btn pg-label{$disabled}\">$label</a>";
}

// Pipeline step helper
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

    <!-- Info notice -->
    <?php if (!$has_pre_reg): ?>
    <div style="margin:0 24px 16px;background:#fff7ed;border:1.5px solid #fcd34d;
                border-radius:10px;padding:12px 16px;font-size:.82rem;color:#92400e;
                display:flex;align-items:center;gap:10px;">
      <i class="fa-solid fa-triangle-exclamation" style="font-size:1rem;flex-shrink:0;"></i>
      <span>
        Database migration required.
        <a href="../shared/migrate_students.php" style="color:#d97706;font-weight:700;">
          Run the migration →
        </a>
        to link students to their validated pre-registrations and remove unvalidated records.
        Showing all students until migration is complete.
      </span>
    </div>
    <?php else: ?>
    <div style="margin:0 24px 16px;background:#eff6ff;border:1.5px solid #bfdbfe;
                border-radius:10px;padding:12px 16px;font-size:.82rem;color:#1e40af;
                display:flex;align-items:center;gap:10px;">
      <i class="fa-solid fa-circle-info" style="font-size:1rem;flex-shrink:0;"></i>
      <span>
        This list only shows students whose applications have been
        <strong>Approved or Enrolled</strong> through the validation process.
        To add a new student, submit an application via
        <a href="../enrollment_tab/pre_registration.php" style="color:#2563eb;font-weight:700;">
          Pre-Registration
        </a> and approve it.
      </span>
    </div>
    <?php endif; ?>

    <!-- Search / filter form -->
    <form method="get" action="students.php"
          style="display:flex;gap:10px;flex-wrap:wrap;margin:0 24px 14px;align-items:center;">
      <div class="search-wrap" style="flex:1;min-width:200px;max-width:360px;">
        <input type="text" name="q" placeholder="Search by name, course, ref no. or ID…"
               value="<?= htmlspecialchars($search) ?>"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <select name="status"
              style="padding:8px 14px;border:1px solid #ddd;border-radius:8px;
                     font-size:.85rem;background:#fff;cursor:pointer;">
        <option value="">All Status</option>
        <option value="Active"   <?= $filter==='Active'   ? 'selected' : '' ?>>Active</option>
        <option value="Inactive" <?= $filter==='Inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
      <input type="hidden" name="step" value="<?= htmlspecialchars($filter_step) ?>"/>
      <button type="submit" class="card-btn" style="padding:8px 18px;">
        <i class="fa-solid fa-filter"></i> Filter
      </button>
      <?php if ($search !== '' || $filter !== '' || $filter_step !== ''): ?>
      <a href="students.php" class="card-btn"
         style="padding:8px 14px;background:#f3f4f6;color:#555;text-decoration:none;">
        <i class="fa-solid fa-xmark"></i> Clear
      </a>
      <?php endif; ?>
    </form>

    <!-- Pipeline step quick-filters -->
    <?php if ($has_pre_reg): ?>
    <div style="margin:0 24px 18px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <span style="font-size:.75rem;color:#888;font-weight:600;">Filter by pipeline step:</span>
      <?php
      $step_filters = [
          ''           => ['All',              '#6b7280', '#f3f4f6'],
          'no_id'      => ['No ID Generated',  '#dc2626', '#fee2e2'],
          'no_grade'   => ['Grade Pending',    '#d97706', '#fff7ed'],
          'no_section' => ['Awaiting Section', '#b45309', '#fffbeb'],
          'complete'   => ['Complete',         '#16a34a', '#dcfce7'],
      ];
      $count_map = array_merge([''=>$total_rows], $step_counts);
      foreach ($step_filters as $key => [$label, $color, $bg]):
          $active_cls = $filter_step === $key ? "border:2px solid $color;" : 'border:1.5px solid #e5e7eb;';
          $qs = http_build_query(['q' => $search, 'status' => $filter, 'step' => $key]);
      ?>
      <a href="?<?= $qs ?>#crud-table"
         style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;
                border-radius:20px;background:<?= $bg ?>;<?= $active_cls ?>
                font-size:.75rem;font-weight:600;color:<?= $color ?>;text-decoration:none;">
        <?= $label ?>
        <span style="background:<?= $color ?>;color:#fff;border-radius:50%;
                     width:18px;height:18px;display:inline-flex;align-items:center;
                     justify-content:center;font-size:.65rem;">
          <?= $count_map[$key] ?? 0 ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- CRUD table -->
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
        <a href="../enrollment_tab/pre_registration.php" class="btn-add"
           title="New students come through pre-registration">
          <i class="fa-solid fa-plus"></i> New Pre-Registration
        </a>
      </div>

      <!-- Bulk toolbar -->
      <div class="bulk-toolbar" id="bulkToolbar">
        <span class="bulk-count" id="bulkCount">0 selected</span>
        <div class="bulk-actions">
          <button class="btn-bulk-delete"   id="btnBulkDelete">
            <i class="fa-solid fa-trash"></i> Delete Selected
          </button>
          <button class="btn-bulk-active"   id="btnBulkActive">Set Active</button>
          <button class="btn-bulk-inactive" id="btnBulkInactive">Set Inactive</button>
        </div>
      </div>

      <table class="crud-table">
        <thead><tr>
          <th style="width:38px;"><input type="checkbox" id="checkAll"/></th>
          <th>Name</th>
          <th>Course</th>
          <th>ID Number</th>
          <th>Year Level</th>
          <th>Section</th>
          <th>Phone</th>
          <th>Ref No.</th>
          <th>Pipeline</th>
          <th>Status</th>
          <th>Actions</th>
        </tr></thead>
        <tbody id="crudTbody">
          <?php if ($students && $students->num_rows > 0):
            while ($row = $students->fetch_assoc()):
              $full_name  = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
              $course     = htmlspecialchars($row['course']);
              $ref_num    = htmlspecialchars($row['ref_number'] ?? '—');
              $phone      = htmlspecialchars($row['phone']);
              $status     = $row['status'];
              $sid        = (int)$row['id'];

              // Live data from enrollments (source of truth)
              $id_number  = $row['id_number']       ?? null;
              $year_lv    = $row['enr_year_level']  ?? $row['year_level'];
              $section    = $row['enr_section']     ?? $row['section'];
              $confirmed  = (bool)($row['grade_confirmed'] ?? false);
          ?>
          <tr data-id="<?= $sid ?>">
            <td><input type="checkbox" class="row-check" value="<?= $sid ?>"/></td>
            <td><?= $full_name ?></td>
            <td style="font-size:.78rem;" title="<?= $course ?>">
              <?= htmlspecialchars(course_abbr($row['course'])) ?>
            </td>
            <td>
              <?php if ($id_number): ?>
              <code style="font-size:.72rem;background:#eff6ff;color:#2563eb;
                           padding:2px 8px;border-radius:4px;white-space:nowrap;">
                <?= htmlspecialchars($id_number) ?>
              </code>
              <?php else: ?>
              <span style="color:#ef4444;font-size:.72rem;font-weight:600;">
                <i class="fa-solid fa-clock"></i> Pending
              </span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($confirmed): ?>
              <span class="badge-active" style="font-size:.72rem;">
                <?= htmlspecialchars($year_lv) ?>
              </span>
              <?php else: ?>
              <span style="color:#888;font-size:.78rem;"><?= htmlspecialchars($year_lv) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php
              $sec_clean = $section ?? '';
              if ($sec_clean && $sec_clean !== 'TBA'): ?>
              <span class="badge-active" style="font-size:.72rem;">
                <?= htmlspecialchars($sec_clean) ?>
              </span>
              <?php elseif ($id_number && !$confirmed): ?>
              <span style="color:#d97706;font-size:.72rem;font-weight:600;">
                <i class="fa-solid fa-layer-group"></i> Grade first
              </span>
              <?php elseif ($id_number): ?>
              <span style="color:#f59e0b;font-size:.72rem;font-weight:600;">
                <i class="fa-solid fa-clock"></i> In queue
              </span>
              <?php else: ?>
              <span style="color:#aaa;font-size:.72rem;">—</span>
              <?php endif; ?>
            </td>
            <td><?= $phone ?></td>
            <td style="font-size:.72rem;color:#2563eb;font-weight:600;"><?= $ref_num ?></td>
            <td><?= pipeline_badge($row) ?></td>
            <td>
              <span class="badge-<?= strtolower($status) ?>"><?= $status ?></span>
            </td>
            <td class="actions-cell">
              <button class="btn-icon btn-view" title="View"   data-id="<?= $sid ?>">
                <i class="fa-solid fa-eye" style="color:#22c55e;"></i>
              </button>
              <button class="btn-icon btn-edit" title="Edit"   data-id="<?= $sid ?>">
                <i class="fa-solid fa-pen-to-square" style="color:#f59e0b;"></i>
              </button>
              <button class="btn-icon btn-delete" title="Delete"
                      data-id="<?= $sid ?>" data-name="<?= $full_name ?>">
                <i class="fa-solid fa-trash" style="color:#ef4444;"></i>
              </button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="11" style="text-align:center;padding:32px;color:#aaa;">
            <?php if ($search !== '' || $filter !== '' || $filter_step !== ''): ?>
              No students match your filters.
            <?php else: ?>
              No validated students yet.
              <a href="../enrollment_tab/pre_registration.php" style="color:#2563eb;font-weight:600;">
                Start a pre-registration →
              </a>
            <?php endif; ?>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($total_pages > 1): ?>
      <div class="crud-pagination">
        <?php
        echo page_lbl('&laquo; Previous', $page - 1, $page, $total_pages, $search, $filter, $filter_step);
        $win = 2; $s = max(1, $page - $win); $e = min($total_pages, $page + $win);
        if ($s > 1) {
            echo page_btn(1, $page, $total_pages, $search, $filter, $filter_step);
            if ($s > 2) echo '<span class="pg-ellipsis">&hellip;</span>';
        }
        for ($p = $s; $p <= $e; $p++) echo page_btn($p, $page, $total_pages, $search, $filter, $filter_step);
        if ($e < $total_pages) {
            if ($e < $total_pages - 1) echo '<span class="pg-ellipsis">&hellip;</span>';
            echo page_btn($total_pages, $page, $total_pages, $search, $filter, $filter_step);
        }
        echo page_lbl('Next &raquo;', $page + 1, $page, $total_pages, $search, $filter, $filter_step);
        ?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- View Modal -->
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

      <div class="modal-section-title" style="margin-top:14px;">
        <i class="fa-solid fa-id-badge"></i> Enrollment Details
      </div>
      <div class="modal-row"><span>Ref No.:</span><span id="vRef"></span></div>
      <div class="modal-row"><span>ID Number:</span><span id="vIdNum"></span></div>
      <div class="modal-row"><span>Course:</span><span id="vCourse"></span></div>
      <div class="modal-row"><span>Year Level:</span><span id="vYear"></span></div>
      <div class="modal-row"><span>Section:</span><span id="vSection"></span></div>

      <div class="modal-section-title" style="margin-top:14px;">
        <i class="fa-solid fa-list-check"></i> Pipeline Status
      </div>
      <div class="modal-row"><span>ID Generated:</span><span id="vIdDone"></span></div>
      <div class="modal-row"><span>Grade Confirmed:</span><span id="vGradeDone"></span></div>
      <div class="modal-row"><span>Section Assigned:</span><span id="vSecDone"></span></div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-close" data-close="viewModal">Close</button>
    </div>
  </div>
</div>

<!-- Form Modal (Edit only — Add goes through pre-registration) -->
<div class="modal-overlay" id="formModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span id="formModalTitle">Edit Student</span>
      <button class="modal-close" data-close="formModal">&times;</button>
    </div>
    <div class="modal-body">
      <form id="studentCrudForm">
        <input type="hidden" id="crudId"     name="id"     value=""/>
        <input type="hidden" id="crudAction" name="action" value="edit"/>
        <div class="form-grid">
          <div class="form-field">
            <label>First Name</label>
            <input type="text" id="cFirst"   name="first_name"  placeholder="First name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Last Name</label>
            <input type="text" id="cLast"    name="last_name"   placeholder="Last name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Birthday</label>
            <input type="date" id="cBday"    name="birthday"/>
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
            <input type="text" id="cCourse"  name="course"
                   placeholder="e.g. BS Information Technology"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Year Level</label>
            <input type="text" id="cYear"    name="year_level"  placeholder="e.g. 1st Year"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Section</label>
            <input type="text" id="cSection" name="section"     placeholder="e.g. TBA"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Phone</label>
            <input type="text" id="cPhone"   name="phone"       placeholder="09XXXXXXXXX"/>
            <span class="field-error"></span>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="formModal">Cancel</button>
      <button class="btn-modal-submit" id="btnCrudSubmit">Save Changes</button>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal modal-sm">
    <div class="modal-body" style="padding:28px 24px 16px;">
      <h3 style="font-size:1.1rem;font-weight:700;margin-bottom:10px;">Are you sure?</h3>
      <p style="font-size:.85rem;color:#555;">
        Delete <strong id="deleteStudentName" style="color:#2563eb;"></strong>?
      </p>
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
      <p style="font-size:.85rem;color:#555;">
        Delete <strong id="bulkDeleteCount" style="color:#2563eb;"></strong> student(s)?
      </p>
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
    $nr = $conn->query(
        "SELECT s.first_name, s.last_name, s.created_at
         FROM students s
         WHERE s.pre_reg_id IS NOT NULL
         ORDER BY s.created_at DESC LIMIT 3"
    );
    if ($nr) while ($ns = $nr->fetch_assoc()):
        $ago = max(1, round((time() - strtotime($ns['created_at'])) / 60));
        $ts  = $ago < 60
            ? $ago . 'm ago'
            : ($ago < 1440 ? round($ago / 60) . 'h ago' : date('M d', strtotime($ns['created_at'])));
    ?>
    <div class="notif-item" data-notif="s">
      <span class="notif-dot"></span>
      <div class="notif-text">
        <div class="notif-title">Student approved</div>
        <div class="notif-desc">
          <?= htmlspecialchars($ns['first_name'] . ' ' . $ns['last_name']) ?> is now enrolled.
        </div>
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

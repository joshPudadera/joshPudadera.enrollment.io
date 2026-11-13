<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff()) { header('Location: ../auth/signin.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// ── Ensure columns exist ──────────────────────────────────────
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS cross_status ENUM('Unchecked','Flagged','Cleared') NOT NULL DEFAULT 'Unchecked'");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS cross_flag_reason VARCHAR(255) DEFAULT NULL");

// ── Auto-clear: Unchecked students with no name conflicts ────
// Sweep all Unchecked Approved students. For each, check if their name
// exists elsewhere in the system. If no match found, auto-set to Cleared
// so they proceed to Grade Assignment without manual review.
$auto_cleared = 0;
$unchecked_q = $conn->query(
    "SELECT id, first_name, last_name FROM pre_registrations
     WHERE status = 'Approved' AND cross_status = 'Unchecked'"
);
if ($unchecked_q) {
    while ($row = $unchecked_q->fetch_assoc()) {
        $uid = (int)$row['id'];
        $fn_e = $conn->real_escape_string(trim($row['first_name']));
        $ln_e = $conn->real_escape_string(trim($row['last_name']));

        $has_match = false;

        $mc1 = $conn->query(
            "SELECT id FROM pre_registrations
             WHERE id != $uid
               AND status IN ('Approved','Enrolled')
               AND LOWER(TRIM(first_name)) = LOWER('$fn_e')
               AND LOWER(TRIM(last_name))  = LOWER('$ln_e')
             LIMIT 1"
        );
        if ($mc1 && $mc1->num_rows > 0) $has_match = true;

        if (!$has_match) {
            $mc2 = $conn->query(
                "SELECT id FROM students
                 WHERE LOWER(TRIM(first_name)) = LOWER('$fn_e')
                   AND LOWER(TRIM(last_name))  = LOWER('$ln_e')
                   AND pre_reg_id != $uid
                 LIMIT 1"
            );
            if ($mc2 && $mc2->num_rows > 0) $has_match = true;
        }

        if (!$has_match) {
            $conn->query("UPDATE pre_registrations SET cross_status='Cleared' WHERE id=$uid");
            // Resolve any waiting_list entry — student no longer needs cross-enrollment review
            $conn->query("UPDATE waiting_list SET status='Resolved' WHERE pre_reg_id=$uid AND status='Waiting'");
            $auto_cleared++;
        }
    }
}

// ── Auto-insert waiting_list entries for students still pending cross-enrollment ──
// Unchecked students (with name conflicts) and Flagged students should appear
// in the waiting list so admins can see who is blocked at this step.
$pending_cross_q = $conn->query(
    "SELECT id, course, year_level, cross_status FROM pre_registrations
     WHERE status = 'Approved' AND cross_status IN ('Unchecked','Flagged')"
);
if ($pending_cross_q) {
    while ($row = $pending_cross_q->fetch_assoc()) {
        $prid     = (int)$row['id'];
        $course_e = $conn->real_escape_string($row['course'] ?? '');
        $yr_e     = $conn->real_escape_string($row['year_level'] ?? '');

        $reason = $row['cross_status'] === 'Flagged'
            ? 'Blocked: duplicate name — awaiting clearance'
            : 'Awaiting cross-enrollment review';
        $reason_e = $conn->real_escape_string($reason);

        // Only insert if no active waiting_list entry exists for this student
        $check = $conn->query("SELECT id FROM waiting_list WHERE pre_reg_id=$prid AND status='Waiting' LIMIT 1");
        if ($check && $check->num_rows === 0) {
            $pos_res = $conn->query("SELECT COUNT(*)+1 AS n FROM waiting_list WHERE status='Waiting'");
            $pos = $pos_res ? (int)$pos_res->fetch_assoc()['n'] : 1;
            $conn->query(
                "INSERT INTO waiting_list (pre_reg_id, course, year_level, queue_position, reason, status)
                 VALUES ($prid, '$course_e', '$yr_e', $pos, '$reason_e', 'Waiting')"
            );
        } else {
            // Update the reason in case the status changed (e.g. Flagged → Unchecked)
            $conn->query("UPDATE waiting_list SET reason='$reason_e' WHERE pre_reg_id=$prid AND status='Waiting'");
        }
    }
}

// ── Step 3 of 7: Cross Enrollment ────────────────────────────
// Gate:  p.status = 'Approved'
// For each student, look up name matches in:
//   - pre_registrations (other rows with same first+last, status Approved/Enrolled)
//   - students table (already fully enrolled students)
// Show matches inline so admin can decide to Flag or Clear.

$search        = trim($_GET['q']      ?? '');
$filter_course = trim($_GET['course'] ?? '');
$filter_status = trim($_GET['cs']     ?? '');
$rows_per_page = 10;
$page          = max(1, (int)($_GET['page'] ?? 1));

$base_where = "p.status = 'Approved'";
if ($search) {
    $esc = $conn->real_escape_string($search);
    $base_where .= " AND (p.first_name LIKE '%$esc%' OR p.last_name LIKE '%$esc%' OR p.ref_number LIKE '%$esc%')";
}
if ($filter_course) {
    $esc = $conn->real_escape_string($filter_course);
    $base_where .= " AND p.course LIKE '%$esc%'";
}
if ($filter_status) {
    $esc = $conn->real_escape_string($filter_status);
    $base_where .= " AND p.cross_status = '$esc'";
}

// Tab counts
$cnt_q = $conn->query("SELECT cross_status, COUNT(*) c FROM pre_registrations WHERE status='Approved' GROUP BY cross_status");
$status_counts = ['Unchecked'=>0,'Flagged'=>0,'Cleared'=>0];
if ($cnt_q) while ($r = $cnt_q->fetch_assoc()) $status_counts[$r['cross_status']] = (int)$r['c'];

$total_count = (int)$conn->query(
    "SELECT COUNT(*) c FROM pre_registrations p WHERE $base_where"
)->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total_count / $rows_per_page));
$page = min($page, $total_pages);

$students = [];
$res = $conn->query(
    "SELECT p.*
     FROM pre_registrations p
     WHERE $base_where
     ORDER BY FIELD(p.cross_status,'Flagged','Unchecked','Cleared'), p.last_name ASC
     LIMIT $rows_per_page OFFSET " . (($page - 1) * $rows_per_page)
);
if ($res) while ($r = $res->fetch_assoc()) $students[] = $r;

// ── For each student, find name conflicts ────────────────────
// Check both pre_registrations (other applications) and students table
foreach ($students as &$p) {
    $fn = $conn->real_escape_string(trim($p['first_name']));
    $ln = $conn->real_escape_string(trim($p['last_name']));
    $id = (int)$p['id'];

    $conflicts = [];

    // 1. Other pre_registration rows with same name (excluding self), status Approved or Enrolled
    $q1 = $conn->query(
        "SELECT first_name, last_name, course, year_level, status, ref_number, applicant_type
         FROM pre_registrations
         WHERE id != $id
           AND status IN ('Approved','Enrolled')
           AND LOWER(TRIM(first_name)) = LOWER('$fn')
           AND LOWER(TRIM(last_name))  = LOWER('$ln')
         ORDER BY status ASC"
    );
    if ($q1) while ($row = $q1->fetch_assoc()) {
        $conflicts[] = [
            'source'  => 'Application',
            'name'    => $row['first_name'] . ' ' . $row['last_name'],
            'course'  => $row['course'],
            'year'    => $row['year_level'],
            'status'  => $row['status'],
            'ref'     => $row['ref_number'] ?? '',
            'type'    => $row['applicant_type'] ?? '',
        ];
    }

    // 2. Students table (fully enrolled, has ID)
    $q2 = $conn->query(
        "SELECT s.first_name, s.last_name, s.course, s.year_level, s.section,
                e.id_number
         FROM students s
         LEFT JOIN enrollments e ON e.pre_reg_id = s.pre_reg_id
         WHERE LOWER(TRIM(s.first_name)) = LOWER('$fn')
           AND LOWER(TRIM(s.last_name))  = LOWER('$ln')
           AND s.pre_reg_id != $id
         ORDER BY s.last_name ASC"
    );
    if ($q2) while ($row = $q2->fetch_assoc()) {
        $conflicts[] = [
            'source'  => 'Enrolled Student',
            'name'    => $row['first_name'] . ' ' . $row['last_name'],
            'course'  => $row['course'],
            'year'    => $row['year_level'],
            'status'  => 'Enrolled',
            'ref'     => $row['id_number'] ?? '',
            'type'    => $row['section'] ?? '',
        ];
    }

    // Deduplicate by course+status
    $seen = [];
    $unique = [];
    foreach ($conflicts as $c) {
        $key = strtolower($c['course']) . '|' . strtolower($c['status']);
        if (!isset($seen[$key])) { $unique[] = $c; $seen[$key] = true; }
    }
    $p['conflicts'] = $unique;
    $p['has_conflict'] = count($unique) > 0;
}
unset($p);

$pending_val = (int)$conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Pending'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Cross Enrollment – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    .conflict-badge {
      display:inline-flex;align-items:center;gap:4px;
      background:#fff0f0;color:#dc2626;border:1.5px solid #fca5a5;
      border-radius:20px;padding:2px 8px;font-size:.68rem;font-weight:700;
    }
    .no-conflict-badge {
      display:inline-flex;align-items:center;gap:4px;
      background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac;
      border-radius:20px;padding:2px 8px;font-size:.68rem;font-weight:700;
    }
    .conflict-matches {
      margin-top:8px;display:flex;flex-direction:row;flex-wrap:wrap;gap:8px;
    }
    .conflict-match-row {
      display:flex;align-items:flex-start;gap:8px;
      background:#fff8f8;border:1px solid #fecaca;border-radius:8px;
      padding:8px 12px;font-size:.75rem;flex:1;min-width:200px;
    }
    .conflict-match-row .match-source {
      font-size:.65rem;font-weight:700;color:#fff;
      background:#dc2626;border-radius:10px;padding:1px 6px;
      white-space:nowrap;flex-shrink:0;margin-top:1px;
    }
    .conflict-match-row .match-source.enrolled {
      background:#7c3aed;
    }
    .conflict-match-row .match-details { flex:1;line-height:1.5; }
    .conflict-match-row .match-name  { font-weight:700;color:#1a1a2e; }
    .conflict-match-row .match-course { color:#6b7280; }
    .conflict-match-row .match-status-pill {
      font-size:.62rem;font-weight:700;border-radius:10px;padding:1px 6px;
    }
  </style>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'enrollment'; require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap"><input type="text" placeholder="Search..."/><i class="fa-solid fa-magnifying-glass"></i></div>
      <a href="../admin_dashboard/account.php" class="avatar"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-arrow-right-arrow-left"></i> Cross Enrollment</h2>
    </div>

    <?php if ($pending_val > 0): ?>
    <div style="margin:0 24px 14px;background:#fff7ed;border:1.5px solid #fcd34d;
                border-radius:10px;padding:10px 16px;font-size:.82rem;color:#92400e;
                display:flex;align-items:center;gap:10px;">
      <i class="fa-solid fa-triangle-exclamation" style="flex-shrink:0;"></i>
      <span>
        <strong><?= $pending_val ?> student<?= $pending_val !== 1 ? 's' : '' ?></strong>
        still awaiting enrollment validation.
        <a href="validation.php" style="color:#d97706;font-weight:700;">Go to Validation →</a>
      </span>
    </div>
    <?php endif; ?>

    <!-- Status filter tabs -->
    <div style="padding:0 24px;margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
      <?php
      $tab_items = [
          ''          => ['All',       null],
          'Unchecked' => ['Unchecked', '#6b7280'],
          'Flagged'   => ['Flagged',   '#dc2626'],
          'Cleared'   => ['Cleared',   '#16a34a'],
      ];
      $qs_base = http_build_query(['q'=>$search,'course'=>$filter_course]);
      foreach ($tab_items as $val => [$label, $color]):
          $active_tab = ($filter_status === $val);
          $cnt = $val === '' ? array_sum($status_counts) : ($status_counts[$val] ?? 0);
      ?>
      <a href="?<?= $qs_base ?>&cs=<?= urlencode($val) ?>"
         style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;
                border-radius:20px;font-size:.78rem;font-weight:700;text-decoration:none;
                border:1.5px solid <?= $active_tab ? ($color ?? '#1a3a8c') : '#d0d7e2' ?>;
                background:<?= $active_tab ? ($color ? $color.'22' : '#eff6ff') : '#fff' ?>;
                color:<?= $active_tab ? ($color ?? '#1a3a8c') : '#6b7280' ?>;">
        <?php if ($color): ?>
        <span style="width:8px;height:8px;border-radius:50%;background:<?= $color ?>;display:inline-block;"></span>
        <?php endif; ?>
        <?= $label ?>
        <span style="background:<?= $active_tab ? ($color ?? '#1a3a8c') : '#f3f4f6' ?>;
                     color:<?= $active_tab ? '#fff' : '#6b7280' ?>;
                     padding:1px 7px;border-radius:10px;font-size:.72rem;">
          <?= $cnt ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" style="margin:0 24px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="hidden" name="cs" value="<?= htmlspecialchars($filter_status) ?>"/>
      <div class="search-wrap" style="flex:1;min-width:200px;max-width:320px;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or ref no.…"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <select name="course" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
        <option value="">All Courses</option>
        <?php foreach(['Information Technology','Computer Engineering','Library Information Science',
                      'Psychology','Elementary Education','Technology and Livelihood Education',
                      'Secondary Education','Physical Education','Criminology',
                      'Accounting Information System','Entrepreneurship',
                      'Marketing Management','Human Resource Management','Financial Management',
                      'Office Administration','Tourism Management','Hospitality Management'] as $c): ?>
        <option value="<?= $c ?>" <?= str_contains($filter_course,$c)?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-add" style="padding:7px 14px;font-size:.8rem;">
        <i class="fa-solid fa-filter"></i> Filter
      </button>
      <?php if($search||$filter_course): ?>
      <a href="?cs=<?= urlencode($filter_status) ?>" class="btn-secondary" style="padding:7px 12px;font-size:.8rem;text-decoration:none;">Clear</a>
      <?php endif; ?>
      <span style="font-size:.78rem;color:#aaa;white-space:nowrap;">
        <?= $total_count ?> record<?= $total_count!==1?'s':'' ?><?= ($search||$filter_course)?' (filtered)':'' ?>
      </span>
    </form>

    <div class="crud-card">
      <div class="crud-header">
        <h3>Approved Students — Name Conflict Check</h3>
      </div>
      <?php if ($students): ?>
      <table class="crud-table">
        <thead><tr>
          <th>Applicant</th>
          <th>Applying For</th>
          <th>Cross Status</th>
          <th>Action</th>
        </tr></thead>
        <tbody>
          <?php foreach ($students as $p):
            $cs = $p['cross_status'] ?? 'Unchecked';
            $cs_cfg = [
                'Unchecked' => ['#f3f4f6','#6b7280','fa-question-circle'],
                'Flagged'   => ['#fee2e2','#dc2626','fa-flag'],
                'Cleared'   => ['#dcfce7','#16a34a','fa-circle-check'],
            ][$cs] ?? ['#f3f4f6','#6b7280','fa-question-circle'];
            $has_conflict = $p['has_conflict'];
            $conflicts    = $p['conflicts'];
            $flag_reason  = $p['cross_flag_reason'] ?? '';
          ?>
          <tr id="crs-row-<?= (int)$p['id'] ?>" style="<?= $cs==='Flagged' ? 'background:#fff8f8;' : ($has_conflict&&$cs==='Unchecked' ? 'background:#fffbeb;' : '') ?>">

            <!-- Applicant info + conflict matches below the name -->
            <td>
              <div style="font-weight:700;font-size:.88rem;color:#1a1a2e;">
                <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?>
              </div>
              <div style="font-size:.7rem;color:#6b7280;margin-top:2px;">
                Ref: <?= htmlspecialchars($p['ref_number'] ?? '—') ?>
              </div>

              <?php if ($has_conflict): ?>
                <button class="conflict-toggle-btn"
                        data-target="conflicts-<?= (int)$p['id'] ?>"
                        style="display:inline-flex;align-items:center;gap:6px;
                               background:#fee2e2;color:#dc2626;border:1.5px solid #fca5a5;
                               border-radius:7px;padding:5px 12px;font-size:.72rem;font-weight:600;
                               cursor:pointer;font-family:inherit;margin-top:8px;">
                  <i class="fa-solid fa-triangle-exclamation"></i>
                  <?= count($conflicts) ?> match<?= count($conflicts)!==1?'es':'' ?> found
                  <i class="fa-solid fa-chevron-down conf-arrow" style="font-size:.65rem;transition:transform .2s;"></i>
                </button>
                <!-- Hidden by default — click to expand -->
                <div id="conflicts-<?= (int)$p['id'] ?>" class="conflict-matches" style="display:none;margin-top:8px;">
                  <?php foreach ($conflicts as $c):
                    $src_class = $c['source'] === 'Enrolled Student' ? 'enrolled' : '';
                    $status_style = match($c['status']) {
                        'Enrolled'  => 'background:#dcfce7;color:#16a34a;',
                        'Approved'  => 'background:#eff6ff;color:#2563eb;',
                        default     => 'background:#f3f4f6;color:#6b7280;',
                    };
                    $course_short = preg_replace('/Bachelor of Science in /i','BS ',$c['course']);
                  ?>
                  <div class="conflict-match-row">
                    <span class="match-source <?= $src_class ?>">
                      <?= htmlspecialchars($c['source']) ?>
                    </span>
                    <div class="match-details">
                      <span class="match-name"><?= htmlspecialchars($c['name']) ?></span>
                      <span style="color:#aaa;margin:0 4px;">·</span>
                      <span class="match-course"><?= htmlspecialchars($course_short) ?></span>
                      <?php if ($c['year']): ?>
                      <span style="color:#aaa;font-size:.7rem;"> <?= htmlspecialchars($c['year']) ?></span>
                      <?php endif; ?>
                      <?php if ($c['source']==='Enrolled Student' && $c['type']): ?>
                      <span style="color:#aaa;font-size:.7rem;"> · Sec <?= htmlspecialchars($c['type']) ?></span>
                      <?php endif; ?>
                      <br>
                      <span class="match-status-pill" style="<?= $status_style ?>">
                        <?= htmlspecialchars($c['status']) ?>
                      </span>
                      <?php if ($c['ref']): ?>
                      <span style="font-size:.65rem;color:#9ca3af;margin-left:4px;">
                        <?= htmlspecialchars($c['ref']) ?>
                      </span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="no-conflict-badge" style="margin-top:6px;">
                  <i class="fa-solid fa-circle-check"></i> No matches
                </span>
              <?php endif; ?>

              <?php if ($cs === 'Flagged' && $flag_reason): ?>
              <div style="margin-top:6px;background:#fff0f0;border:1px solid #fecaca;border-radius:6px;
                          padding:5px 8px;font-size:.7rem;color:#dc2626;">
                <i class="fa-solid fa-comment-dots"></i>
                <strong>Note:</strong> <?= htmlspecialchars($flag_reason) ?>
              </div>
              <?php endif; ?>
            </td>

            <!-- Course + Year -->
            <td>
              <div style="font-size:.8rem;font-weight:600;color:#1e40af;">
                <?= htmlspecialchars(preg_replace('/Bachelor of Science in /i','BS ',$p['course'])) ?>
              </div>
              <div style="font-size:.72rem;color:#6b7280;"><?= htmlspecialchars($p['year_level']) ?></div>
            </td>

            <!-- Cross Status -->
            <td>
              <span style="display:inline-flex;align-items:center;gap:5px;
                           background:<?= $cs_cfg[0] ?>;color:<?= $cs_cfg[1] ?>;
                           border-radius:20px;padding:3px 10px;font-size:.72rem;font-weight:700;">
                <i class="fa-solid <?= $cs_cfg[2] ?>"></i> <?= $cs ?>
              </span>
              <?php if ($cs === 'Flagged'): ?>
              <div style="font-size:.67rem;color:#dc2626;margin-top:3px;font-weight:600;">
                <i class="fa-solid fa-lock"></i> Blocked from Grade Assignment
              </div>
              <?php elseif ($cs === 'Cleared'): ?>
              <div style="font-size:.67rem;color:#16a34a;margin-top:3px;">
                <i class="fa-solid fa-circle-check"></i> Advances to Grade Assignment
              </div>
              <?php endif; ?>
            </td>

            <!-- Actions -->
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php if ($cs !== 'Flagged'): ?>
                <button class="btn-flag-cross"
                        data-id="<?= (int)$p['id'] ?>"
                        data-name="<?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?>"
                        data-has-conflict="<?= $has_conflict ? '1' : '0' ?>"
                        style="background:#fee2e2;color:#dc2626;border:1.5px solid #fca5a5;
                               border-radius:7px;padding:5px 10px;font-size:.72rem;font-weight:600;
                               cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                  <i class="fa-solid fa-flag"></i> Flag
                </button>
                <?php endif; ?>
                <?php if ($cs !== 'Cleared'): ?>
                <button class="btn-clear-cross"
                        data-id="<?= (int)$p['id'] ?>"
                        data-name="<?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?>"
                        data-has-conflict="<?= $has_conflict ? '1' : '0' ?>"
                        style="background:#dcfce7;color:#16a34a;border:1.5px solid #86efac;
                               border-radius:7px;padding:5px 10px;font-size:.72rem;font-weight:600;
                               cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                  <i class="fa-solid fa-circle-check"></i> Clear
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($total_pages > 1): ?>
      <div class="crud-pagination">
        <?php
        $qs = http_build_query(['q'=>$search,'course'=>$filter_course,'cs'=>$filter_status]);
        if ($page > 1) echo "<a href='?$qs&page=".($page-1)."' class='pg-btn pg-label'>&laquo;</a>";
        for ($pg = max(1,$page-2); $pg <= min($total_pages,$page+2); $pg++)
            echo "<a href='?$qs&page=$pg' class='pg-btn".($pg===$page?' active':'')."'>$pg</a>";
        if ($page < $total_pages) echo "<a href='?$qs&page=".($page+1)."' class='pg-btn pg-label'>&raquo;</a>";
        ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div style="padding:28px;text-align:center;color:#aaa;font-size:.88rem;">
        <?php if ($search||$filter_course||$filter_status): ?>
          No records match the current filters.
        <?php else: ?>
          No approved students awaiting cross enrollment review.
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- Flag Modal -->
<div class="modal-overlay" id="flagModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span><i class="fa-solid fa-flag" style="color:#dc2626;margin-right:6px;"></i> Flag Student</span>
      <button class="modal-close" data-close="flagModal">&times;</button>
    </div>
    <div class="modal-body">
      <div id="flagConflictWarning" style="display:none;margin-bottom:12px;background:#fff0f0;
           border:1.5px solid #fca5a5;border-radius:8px;padding:10px 12px;
           font-size:.8rem;color:#dc2626;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong>Name match detected</strong> — this student has a matching name already in the system.
      </div>
      <p style="font-size:.85rem;color:#555;margin-bottom:12px;">
        Flag <strong id="flagStudentName"></strong>? They will be
        <strong style="color:#dc2626;">blocked</strong> from Grade Assignment until cleared.
      </p>
      <input type="hidden" id="flagPreRegId"/>
      <div style="margin-top:4px;">
        <label style="font-size:.78rem;font-weight:600;color:#444;display:block;margin-bottom:5px;">
          Reason / Notes (optional)
        </label>
        <input type="text" id="flagReason"
               placeholder="e.g. Same name already enrolled in BS Computer Engineering"
               style="width:100%;height:40px;border:1.5px solid #d0d7e2;border-radius:8px;
                      padding:0 12px;font-size:.88rem;outline:none;box-sizing:border-box;font-family:inherit;"/>
      </div>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="flagModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmFlag" style="background:#dc2626;">
        <i class="fa-solid fa-flag"></i> Flag &amp; Block
      </button>
    </div>
  </div>
</div>

<!-- Clear Modal -->
<div class="modal-overlay" id="clearModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span><i class="fa-solid fa-circle-check" style="color:#16a34a;margin-right:6px;"></i> Clear Student</span>
      <button class="modal-close" data-close="clearModal">&times;</button>
    </div>
    <div class="modal-body">
      <div id="clearConflictWarning" style="display:none;margin-bottom:12px;background:#fff7ed;
           border:1.5px solid #fcd34d;border-radius:8px;padding:10px 12px;
           font-size:.8rem;color:#92400e;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong>Name match exists</strong> — you are clearing a student whose name appears elsewhere
        in the system. Make sure this is intentional.
      </div>
      <p style="font-size:.85rem;color:#555;margin-bottom:6px;">
        Mark <strong id="clearStudentName"></strong> as cleared?
        They will be allowed to proceed to <strong>Grade Assignment</strong>.
      </p>
      <input type="hidden" id="clearPreRegId"/>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="clearModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmClear">
        <i class="fa-solid fa-circle-check"></i> Confirm Clear
      </button>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
(function() {
var API = '../shared/enrollment_actions.php';

// ── Conflict expand toggle ────────────────────────────────────
document.querySelectorAll('.conflict-toggle-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var target = document.getElementById(btn.dataset.target);
        var arrow  = btn.querySelector('.conf-arrow');
        if (!target) return;
        var open = target.style.display === 'block';
        target.style.display = open ? 'none' : 'block';
        if (arrow) arrow.style.transform = open ? '' : 'rotate(180deg)';
    });
});

// ── Flag ──────────────────────────────────────────────────────
document.querySelectorAll('.btn-flag-cross').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('flagPreRegId').value          = btn.dataset.id;
        document.getElementById('flagStudentName').textContent = btn.dataset.name;
        document.getElementById('flagReason').value            = '';
        // Show conflict warning if name matches exist
        var warn = document.getElementById('flagConflictWarning');
        warn.style.display = btn.dataset.hasConflict === '1' ? 'block' : 'none';
        openModal('flagModal');
    });
});

document.getElementById('btnConfirmFlag').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Flagging…';

    var fd = new FormData();
    fd.set('action',     'flag_cross');
    fd.set('pre_reg_id', document.getElementById('flagPreRegId').value);
    fd.set('reason',     document.getElementById('flagReason').value.trim());
    fetch(API, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-flag"></i> Flag &amp; Block';
            if (d.success) location.reload();
            else showAlertModal(d.message, 'error');
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-flag"></i> Flag &amp; Block';
            showAlertModal('Request failed.', 'error');
        });
});

// ── Clear ─────────────────────────────────────────────────────
document.querySelectorAll('.btn-clear-cross').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('clearPreRegId').value          = btn.dataset.id;
        document.getElementById('clearStudentName').textContent = btn.dataset.name;
        // Show extra caution warning if conflict exists
        var warn = document.getElementById('clearConflictWarning');
        warn.style.display = btn.dataset.hasConflict === '1' ? 'block' : 'none';
        openModal('clearModal');
    });
});

document.getElementById('btnConfirmClear').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Clearing…';

    var fd = new FormData();
    fd.set('action',     'clear_cross');
    fd.set('pre_reg_id', document.getElementById('clearPreRegId').value);
    fetch(API, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirm Clear';
            if (d.success) location.reload();
            else showAlertModal(d.message, 'error');
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirm Clear';
            showAlertModal('Request failed.', 'error');
        });
});
}());
</script>
</body>
</html>
<?php $conn->close(); ?>

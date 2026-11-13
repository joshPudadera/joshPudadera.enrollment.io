<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff()) { header('Location: ../auth/signin.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// ── Handle Auto-Assign POST ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto_assign_all') {
    $assigned = 0;
    $created  = 0;

    // ── Ensure columns exist ─────────────────────────────────
    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS grade_confirmed TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS confirmed_year_level VARCHAR(50) DEFAULT NULL");

    // Gate: student has an enrollment row (ID generated at Step 6) but no section yet
    $unassigned_q = $conn->query(
        "SELECT e.id AS enrollment_id,
                e.pre_reg_id,
                e.course,
                COALESCE(e.year_level, p.year_level) AS year_level,
                p.first_name, p.last_name
         FROM enrollments e
         JOIN pre_registrations p ON p.id = e.pre_reg_id
         WHERE (e.section IS NULL OR e.section = '' OR e.section = 'TBA')
         ORDER BY e.course ASC, e.year_level ASC, p.last_name ASC"
    );
    $to_assign = [];
    if ($unassigned_q) while ($r = $unassigned_q->fetch_assoc()) $to_assign[] = $r;

    foreach ($to_assign as $student) {
        $course          = $student['course'];
        $year_lv         = $student['year_level'];
        $pre_reg_id_here = (int)$student['pre_reg_id'];
        $enrollment_id   = (int)$student['enrollment_id'];

        // Find a section with space (count from enrollments — source of truth after Step 6)
        $free = $conn->query(
            "SELECT s.section_code,
                    (SELECT COUNT(*) FROM enrollments en WHERE en.section = s.section_code) AS actual_count
             FROM sections s
             WHERE s.course = '" . $conn->real_escape_string($course) . "'
               AND s.year_level = '" . $conn->real_escape_string($year_lv) . "'
               AND s.is_active = 1
             HAVING actual_count < 50
             ORDER BY actual_count ASC
             LIMIT 1"
        );

        $target_section = null;
        if ($free && $free->num_rows > 0) {
            $target_section = $free->fetch_assoc()['section_code'];
        } else {
            // All sections full OR none exists — create a new one using new naming scheme
            // Format: [year_digit][sem_digit][seq_3digits]  e.g. 11038
            $year_digits = ['1st Year'=>1,'2nd Year'=>2,'3rd Year'=>3,'4th Year'=>4,
                            'Grade 11'=>1,'Grade 12'=>2];
            $yd = $year_digits[$year_lv] ?? 0;
            $sd = 1; // 1st semester default

            // Find next unused sequence number globally
            $next_seq = 1;
            $max_res = $conn->query("SELECT MAX(CAST(section_code AS UNSIGNED)) AS mx FROM sections");
            if ($max_res) {
                $mx = (int)$max_res->fetch_assoc()['mx'];
                $next_seq = ($mx % 1000) + 1;
                if ($next_seq <= 0) $next_seq = 1;
            }
            // Ensure no collision
            while (true) {
                $try_code = $yd . $sd . str_pad($next_seq, 3, '0', STR_PAD_LEFT);
                $ex = $conn->query("SELECT id FROM sections WHERE section_code='" .
                                   $conn->real_escape_string($try_code) . "' LIMIT 1");
                if ($ex && $ex->num_rows === 0) break;
                $next_seq++;
            }
            $new_code = $yd . $sd . str_pad($next_seq, 3, '0', STR_PAD_LEFT);

            $sem_val = '1st';
            $ins = $conn->prepare(
                'INSERT INTO sections (section_code, course, year_level, semester, max_capacity, current_count)
                 VALUES (?,?,?,?,50,0)'
            );
            $ins->bind_param('ssss', $new_code, $course, $year_lv, $sem_val);
            if ($ins->execute()) {
                $target_section = $new_code;
                $created++;
            }
            $ins->close();
        }

        if ($target_section) {
            // Update enrollments.section (student already has ID from Step 6)
            $upd = $conn->prepare('UPDATE enrollments SET section=? WHERE id=?');
            $upd->bind_param('si', $target_section, $enrollment_id);
            if ($upd->execute() && $upd->affected_rows > 0) {
                // Sync students.section
                $sync = $conn->prepare('UPDATE students SET section=? WHERE pre_reg_id=?');
                $sync->bind_param('si', $target_section, $pre_reg_id_here);
                $sync->execute();
                $sync->close();

                // Sync sections.current_count
                $conn->query("UPDATE sections SET current_count = (
                    SELECT COUNT(*) FROM enrollments WHERE section = '" .
                    $conn->real_escape_string($target_section) . "'
                ) WHERE section_code = '" . $conn->real_escape_string($target_section) . "'");

                // Mark waiting list entry as Promoted if present
                $promoted = 'Promoted';
                $wl_upd = $conn->prepare(
                    "UPDATE waiting_list SET status=? WHERE pre_reg_id=? AND status='Waiting'"
                );
                $wl_upd->bind_param('si', $promoted, $pre_reg_id_here);
                $wl_upd->execute();
                $wl_upd->close();

                $assigned++;
            }
            $upd->close();
        }
    }

    $add_msg = "$assigned student(s) assigned." . ($created > 0 ? " $created new section(s) created." : '');
}

// ── Handle Add Section POST ───────────────────────────────────
$add_msg = $add_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_section') {
    $course   = trim($_POST['course']       ?? '');
    $year_lv  = trim($_POST['year_level']   ?? '');
    $semester = trim($_POST['semester']     ?? '1st');
    $capacity = max(1, (int)($_POST['max_capacity'] ?? 50));

    if (!$course || !$year_lv) {
        $add_err = 'Course and year level are required.';
    } else {
        // Auto-generate section code: [year_digit][sem_digit][seq_3digits]
        $year_digits = ['1st Year'=>1,'2nd Year'=>2,'3rd Year'=>3,'4th Year'=>4,
                        'Grade 11'=>1,'Grade 12'=>2];
        $yd = $year_digits[$year_lv] ?? 0;
        $sd = (strpos($semester, '2') !== false) ? 2 : 1;

        $next_seq = 1;
        $max_res = $conn->query("SELECT MAX(CAST(section_code AS UNSIGNED)) AS mx FROM sections");
        if ($max_res) {
            $mx = (int)$max_res->fetch_assoc()['mx'];
            $next_seq = ($mx % 1000) + 1;
            if ($next_seq <= 0) $next_seq = 1;
        }
        while (true) {
            $try_code = $yd . $sd . str_pad($next_seq, 3, '0', STR_PAD_LEFT);
            $ex = $conn->query("SELECT id FROM sections WHERE section_code='" .
                               $conn->real_escape_string($try_code) . "' LIMIT 1");
            if ($ex && $ex->num_rows === 0) break;
            $next_seq++;
        }
        $code = $yd . $sd . str_pad($next_seq, 3, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare(
            'INSERT INTO sections (section_code, course, year_level, semester, max_capacity)
             VALUES (?,?,?,?,?)'
        );
        $stmt->bind_param('ssssi', $code, $course, $year_lv, $semester, $capacity);
        if ($stmt->execute()) {
            $add_msg = "Section <strong>$code</strong> created successfully.";
        } else {
            $add_err = $conn->errno == 1062
                ? "Section code '$code' already exists (collision — retry)."
                : 'Could not create section: ' . $conn->error;
        }
        $stmt->close();
    }
}

// ── Search / filter ───────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$where  = "WHERE s.is_active = 1";
if ($search) {
    $esc    = $conn->real_escape_string($search);
    $where .= " AND (s.section_code LIKE '%$esc%'
                  OR s.course       LIKE '%$esc%'
                  OR s.year_level   LIKE '%$esc%'
                  OR s.adviser_name LIKE '%$esc%')";
}

// Sections — simple query avoids collation mismatch (Error 1267)
$sections = [];
$res = $conn->query(
    "SELECT * FROM sections WHERE is_active = 1
     ORDER BY course ASC, year_level ASC, section_code ASC"
);
if ($res) while ($r = $res->fetch_assoc()) {
    if ((int)$r['max_capacity'] !== 50) {
        $conn->query("UPDATE sections SET max_capacity=50 WHERE id=" . (int)$r['id']);
        $r['max_capacity'] = 50;
    }
    $r['current_count'] = 0;
    $sections[] = $r;
}

// ── Students per section ──────────────────────────────────────
// Two sources: (1) enrollments JOIN pre_registrations, (2) students table directly
// Merge both so test-seeded students (with or without pre_reg) all appear.
$section_students = [];

// Source 1: via enrollments (pipeline students)
$res3 = $conn->query(
    "SELECT e.section,
            COALESCE(p.first_name, s.first_name, 'Unknown') AS first_name,
            COALESCE(p.last_name,  s.last_name,  'Student')  AS last_name,
            COALESCE(e.year_level, s.year_level, '') AS year_level,
            e.id_number,
            e.enrolled_at
     FROM enrollments e
     LEFT JOIN pre_registrations p ON e.pre_reg_id = p.id
     LEFT JOIN students s ON s.pre_reg_id = e.pre_reg_id
     WHERE e.section IS NOT NULL AND e.section != '' AND e.section != 'TBA'
     ORDER BY e.section ASC, COALESCE(p.last_name, s.last_name) ASC");
if ($res3) {
    while ($r = $res3->fetch_assoc()) {
        $section_students[$r['section']][] = $r;
    }
}

// Source 2: students with a section set directly (no enrollment row, e.g. direct edit)
$res4 = $conn->query(
    "SELECT s.section,
            s.first_name, s.last_name, s.year_level,
            e.id_number,
            s.created_at AS enrolled_at
     FROM students s
     LEFT JOIN enrollments e ON e.pre_reg_id = s.pre_reg_id
     WHERE s.section IS NOT NULL AND s.section != '' AND s.section != 'TBA'
       AND (e.id IS NULL OR e.section != s.section)
     ORDER BY s.section ASC, s.last_name ASC"
);
if ($res4) {
    while ($r = $res4->fetch_assoc()) {
        $section_students[$r['section']][] = $r;
    }
}

// ── Get all unassigned students — GATED: has enrollment row (ID generated) ──
$unassigned = [];

// Students with enrollment row but section still TBA/null (ID exists, section pending)
$res_ua = $conn->query(
    "SELECT e.id, e.pre_reg_id, e.id_number, e.year_level, e.course,
            COALESCE(p.first_name, s.first_name, 'Unknown') AS first_name,
            COALESCE(p.last_name,  s.last_name,  'Student')  AS last_name
     FROM enrollments e
     LEFT JOIN pre_registrations p ON e.pre_reg_id = p.id
     LEFT JOIN students s ON s.pre_reg_id = e.pre_reg_id
     WHERE (e.section IS NULL OR e.section = '' OR e.section = 'TBA')
     ORDER BY COALESCE(p.last_name, s.last_name) ASC"
);
if ($res_ua) while ($r = $res_ua->fetch_assoc()) $unassigned[] = $r;

// Backfill current_count on each section using enrollments (source of truth after Step 6)
foreach ($sections as &$sec) {
    $sc = $conn->real_escape_string($sec['section_code']);
    $cnt = $conn->query("SELECT COUNT(*) c FROM enrollments WHERE section='$sc'")->fetch_assoc()['c'];
    $sec['current_count'] = (int)$cnt;
}
unset($sec);

// Count students still blocked (grade not confirmed — from pre_registrations)
$blocked_grade = 0;
$bg = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Approved' AND grade_confirmed = 0 AND cross_status != 'Flagged'");
if ($bg) $blocked_grade = (int)$bg->fetch_assoc()['c'];

// Count students with grade confirmed but no ID yet (need to go through ID Generation first)
$not_id_yet = 0;
$ni = $conn->query(
    "SELECT COUNT(*) c FROM pre_registrations p
     LEFT JOIN enrollments e ON e.pre_reg_id = p.id
     WHERE p.status = 'Approved' AND p.grade_confirmed = 1 AND e.id IS NULL"
);
if ($ni) $not_id_yet = (int)$ni->fetch_assoc()['c'];

// All active sections for dropdowns
$all_sections = [];
$rs = $conn->query("SELECT * FROM sections WHERE is_active=1 ORDER BY section_code ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $all_sections[] = $r;

// Distinct courses for the Add Section dropdown
$courses_list = [
    'Bachelor of Science in Information Technology',
    'Bachelor of Science in Computer Science',
    'Bachelor of Science in Information Systems',
];
$year_levels = ['1st Year','2nd Year','3rd Year','4th Year'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Section Assignment – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    .btn-primary,.btn-approve,.btn-reject,.btn-secondary,.btn-add {
      font-family:'Segoe UI',sans-serif;font-size:.82rem;font-weight:700;
      cursor:pointer;border:none;border-radius:8px;padding:8px 18px;
      display:inline-flex;align-items:center;gap:7px;
      text-decoration:none;transition:background .15s;
    }
    .btn-primary  { background:#1a3a8c;color:#fff; }
    .btn-primary:hover  { background:#142d6e; }
    .btn-approve  { background:#16a34a;color:#fff; }
    .btn-approve:hover  { background:#15803d; }
    .btn-reject   { background:#ef4444;color:#fff; }
    .btn-secondary{ background:#f3f4f6;color:#444;border:1.5px solid #d0d7e2; }
    .btn-secondary:hover{ background:#e5e7eb; }
    .btn-add      { background:#22c55e;color:#fff; }
    .btn-add:hover{ background:#16a34a; }

    .section-card {
      background:#fff;border-radius:12px;border:1.5px solid #e8edf4;
      margin-bottom:16px;overflow:hidden;
    }
    .section-card-header {
      display:flex;align-items:center;justify-content:space-between;
      padding:14px 18px;background:#f8fafc;cursor:pointer;
      border-bottom:1px solid #f0f2f5;
      flex-wrap:wrap;gap:8px;
    }
    .section-card-header:hover { background:#eff6ff; }
    .section-card-body { display:none;padding:0; }
    .section-card-body.open { display:block; }
    .capacity-bar { height:6px;border-radius:3px;background:#e2e8f0;flex:1;min-width:80px; }
    .capacity-fill{ height:6px;border-radius:3px;transition:width .3s; }

    .modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
                     z-index:9999;align-items:center;justify-content:center; }
    .modal-overlay.active { display:flex; }
    .modal-box {
      background:#fff;border-radius:14px;padding:28px;
      max-width:480px;width:92%;box-shadow:0 8px 32px rgba(0,0,0,.2);
    }
    .modal-box h3 { font-size:1rem;font-weight:700;color:#1a1a2e;margin-bottom:16px; }
    .mfield { margin-bottom:14px; }
    .mfield label { display:block;font-size:.78rem;font-weight:600;color:#444;margin-bottom:5px; }
    .mfield input,.mfield select {
      width:100%;height:42px;border:1.5px solid #d0d7e2;border-radius:8px;
      padding:0 14px;font-size:.88rem;outline:none;font-family:inherit;
      transition:border-color .2s;
    }
    .mfield input:focus,.mfield select:focus { border-color:#1a3a8c; }
    .mfield-row { display:grid;grid-template-columns:1fr 1fr;gap:12px; }
    .modal-actions { display:flex;gap:10px;justify-content:flex-end;margin-top:20px; }
  </style>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'enrollment';
      require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <a href="../admin_dashboard/account.php" class="avatar"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-chalkboard"></i> Section Assignment</h2>
    </div>

    <?php if ($add_msg): ?>
    <div style="margin:0 24px 16px;background:#dcfce7;color:#16a34a;border:1px solid #86efac;
                border-radius:8px;padding:12px 18px;font-size:.85rem;">
      <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($add_msg) ?>
    </div>
    <?php endif; ?>
    <?php if ($add_err): ?>
    <div style="margin:0 24px 16px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;
                border-radius:8px;padding:12px 18px;font-size:.85rem;">
      <i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($add_err) ?>
    </div>
    <?php endif; ?>

    <!-- Search + Add bar -->
    <div style="padding:0 24px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <form method="GET" style="display:flex;gap:8px;flex:1;min-width:220px;">
        <div style="position:relative;flex:1;">
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                 placeholder="Search sections, courses, year level…"
                 style="width:100%;height:42px;border:1.5px solid #d0d7e2;border-radius:8px;
                        padding:0 14px 0 38px;font-size:.85rem;outline:none;font-family:inherit;"/>
          <i class="fa-solid fa-magnifying-glass"
             style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#aaa;"></i>
        </div>
        <button type="submit" class="btn-primary" style="height:42px;">
          <i class="fa-solid fa-magnifying-glass"></i> Search
        </button>
        <?php if ($search): ?>
        <a href="section_assignment.php" class="btn-secondary" style="height:42px;">
          <i class="fa-solid fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
      </form>
      <button class="btn-approve" id="btnAddSection">
        <i class="fa-solid fa-plus"></i> Add Section
      </button>
      <?php if ($unassigned): ?>
      <button class="btn-primary" id="btnAutoAssignAll">
        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Assign All (<?= count($unassigned) ?>)
      </button>
      <?php endif; ?>
    </div>

    <!-- ── Unassigned students (top, before sections) ────────── -->
    <?php if ($unassigned): ?>
    <div class="crud-card" style="margin:0 24px 20px;">
      <div class="crud-header">
        <h3><i class="fa-solid fa-user-clock" style="color:#f59e0b;margin-right:6px;"></i>
          Unassigned Students <span style="font-size:.75rem;font-weight:400;color:#888;">(<?= count($unassigned) ?>)</span>
        </h3>
      </div>
      <table class="crud-table">
        <thead>
          <tr>
            <th>ID Number</th><th>Name</th><th>Course</th><th>Year Level</th><th>Assign Section</th>
          </tr>
        </thead>
        <tbody id="unassignedTbody">
          <?php foreach ($unassigned as $e): ?>
          <tr data-id="<?= (int)$e['id'] ?>" data-course="<?= htmlspecialchars($e['course'] ?? '') ?>" data-year="<?= htmlspecialchars($e['year_level'] ?? '') ?>">
            <td><code style="font-size:.75rem;background:#eff6ff;color:#2563eb;padding:2px 8px;border-radius:4px;">
              <?= htmlspecialchars($e['id_number'] ?? '—') ?></code></td>
            <td><?= htmlspecialchars(($e['first_name'] ?? '').' '.($e['last_name'] ?? '')) ?></td>
            <td style="font-size:.75rem;"><?= htmlspecialchars(preg_replace('/Bachelor of Science in /i','BS ',$e['course'] ?? '')) ?></td>
            <td><?= htmlspecialchars($e['year_level'] ?? '') ?></td>
            <td>
              <div style="display:flex;gap:8px;align-items:center;">
                <select class="section-select"
                        style="height:34px;border:1.5px solid #d0d7e2;border-radius:6px;
                               padding:0 10px;font-size:.78rem;max-width:180px;font-family:inherit;">
                  <option value="">Select section…</option>
                  <?php foreach ($all_sections as $sec):
                    $full = $sec['current_count'] >= $sec['max_capacity'];
                  ?>
                  <option value="<?= htmlspecialchars($sec['section_code']) ?>"
                    <?= $full ? 'disabled' : '' ?>>
                    <?= htmlspecialchars($sec['section_code']) ?>
                    (<?= $sec['current_count'] ?>/<?= $sec['max_capacity'] ?>)<?= $full?' — FULL':'' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn-approve btn-assign-sec" data-id="<?= (int)$e['id'] ?>">
                  <i class="fa-solid fa-check"></i> Assign
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- ── Sections grouped by year level ────────────────────── -->
    <div style="padding:0 24px;">
      <?php if (empty($sections)): ?>
      <div class="crud-card" style="text-align:center;padding:40px;color:#aaa;">
        <i class="fa-solid fa-chalkboard" style="font-size:2rem;display:block;margin-bottom:12px;"></i>
        No sections found<?= $search ? " matching \"$search\"" : '' ?>.
      </div>
      <?php endif; ?>

      <?php
      // ── Three-level grouping: Course → Year Level → Semester ──
      $by_course = [];
      foreach ($sections as $sec) {
          $course_key = $sec['course'] ?: 'Unassigned Course';
          $yl = $sec['year_level'] ?: 'Unassigned';
          $sem = $sec['semester'] ?: '1st';
          // Normalize semester display
          if (strpos($sem, '2') !== false) $sem_key = '2nd';
          else $sem_key = '1st';

          $by_course[$course_key][$yl][$sem_key][] = $sec;
      }

      // Sort courses alphabetically
      ksort($by_course);

      $yl_order = ['1st Year'=>0,'2nd Year'=>1,'3rd Year'=>2,'4th Year'=>3,
                   'Grade 11'=>4,'Grade 12'=>5,'Unassigned'=>99];

      foreach ($by_course as $course_name => $course_years):
        $short = preg_replace('/Bachelor of Science in /i','BS ',$course_name);
        $course_id = 'crs-' . preg_replace('/[^a-z0-9]/i','_', $course_name);
        $total_course = 0;
        $cap_course = 0;
        foreach ($course_years as $yl => $sems) {
          foreach ($sems as $sem => $secs) {
            foreach ($secs as $s) {
              $total_course += $s['current_count'];
              $cap_course += $s['max_capacity'];
            }
          }
        }
        $total_sections_course = 0;
        foreach ($course_years as $sems) foreach ($sems as $secs) $total_sections_course += count($secs);
      ?>
      <div style="margin-bottom:24px;">
        <!-- Course header (level 1) -->
        <div onclick="toggleYearGroup('<?= $course_id ?>')"
             style="display:flex;align-items:center;justify-content:space-between;
                    padding:12px 16px;background:#1a3a8c;border-radius:10px;
                    cursor:pointer;user-select:none;margin-bottom:4px;">
          <div style="display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-graduation-cap" style="color:#93c5fd;font-size:.9rem;"></i>
            <span style="font-weight:700;color:#fff;font-size:.95rem;"><?= htmlspecialchars($short) ?></span>
            <span style="background:rgba(255,255,255,.2);color:#e0f2fe;border-radius:20px;
                         padding:1px 10px;font-size:.72rem;font-weight:600;">
              <?= $total_sections_course ?> section<?= $total_sections_course!==1?'s':'' ?>
            </span>
            <span style="color:#93c5fd;font-size:.72rem;"><?= $total_course ?>/<?= $cap_course ?> enrolled</span>
          </div>
          <i class="fa-solid fa-chevron-down" id="arrow-<?= $course_id ?>"
             style="color:#93c5fd;transition:transform .2s;"></i>
        </div>

        <!-- Course body (hidden by default) -->
        <div id="<?= $course_id ?>" style="display:none;padding-left:8px;margin-top:4px;">

          <?php
          // Sort year levels within this course
          uksort($course_years, fn($a,$b) => ($yl_order[$a]??50) <=> ($yl_order[$b]??50));

          foreach ($course_years as $year_label => $sem_groups):
            $year_id = $course_id . '-yr-' . preg_replace('/[^a-z0-9]/i','_', $year_label);
            $total_yr = 0; $cap_yr = 0;
            foreach ($sem_groups as $sem => $secs) {
              foreach ($secs as $s) {
                $total_yr += $s['current_count'];
                $cap_yr += $s['max_capacity'];
              }
            }
            $total_secs_yr = 0;
            foreach ($sem_groups as $secs) $total_secs_yr += count($secs);
          ?>
          <div style="margin-bottom:16px;">
            <!-- Year level header (level 2) -->
            <div onclick="toggleYearGroup('<?= $year_id ?>')"
                 style="display:flex;align-items:center;justify-content:space-between;
                        padding:8px 14px;background:#3b82f6;border-radius:8px;
                        cursor:pointer;user-select:none;margin-bottom:4px;">
              <div style="display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-layer-group" style="color:#dbeafe;font-size:.8rem;"></i>
                <span style="font-weight:700;color:#fff;font-size:.85rem;"><?= htmlspecialchars($year_label) ?></span>
                <span style="background:rgba(255,255,255,.2);color:#dbeafe;border-radius:20px;
                             padding:1px 8px;font-size:.68rem;font-weight:600;">
                  <?= $total_secs_yr ?> section<?= $total_secs_yr!==1?'s':'' ?>
                </span>
                <span style="color:#dbeafe;font-size:.68rem;"><?= $total_yr ?>/<?= $cap_yr ?> enrolled</span>
              </div>
              <i class="fa-solid fa-chevron-down" id="arrow-<?= $year_id ?>"
                 style="color:#dbeafe;transition:transform .2s;font-size:.75rem;"></i>
            </div>

            <!-- Year body (hidden by default) -->
            <div id="<?= $year_id ?>" style="display:none;padding-left:8px;margin-top:4px;">

              <?php
              // Sort semesters: 1st before 2nd
              uksort($sem_groups, fn($a,$b) => strcmp($a,$b));

              foreach ($sem_groups as $sem_key => $sem_sections):
                $sem_label = $sem_key === '2nd' ? '2nd Semester' : '1st Semester';
                $sem_id = $year_id . '-sem-' . $sem_key;
                $total_sem = 0; $cap_sem = 0;
                foreach ($sem_sections as $s) {
                  $total_sem += $s['current_count'];
                  $cap_sem += $s['max_capacity'];
                }
              ?>
              <div style="margin-bottom:12px;">
                <!-- Semester sub-header (level 3) — not collapsible, just a label -->
                <div style="display:flex;align-items:center;gap:8px;
                            padding:6px 10px;margin-bottom:6px;border-left:3px solid #93c5fd;
                            background:#eff6ff;border-radius:0 6px 6px 0;">
                  <i class="fa-solid fa-calendar-days" style="color:#3b82f6;font-size:.75rem;"></i>
                  <span style="font-weight:600;color:#1e40af;font-size:.8rem;"><?= htmlspecialchars($sem_label) ?></span>
                  <span style="color:#6b7280;font-size:.7rem;"><?= $total_sem ?>/<?= $cap_sem ?> enrolled</span>
                </div>

                <!-- Section cards under this semester -->
                <?php foreach ($sem_sections as $sec):
                  $pct   = $sec['max_capacity'] > 0 ? round(($sec['current_count']/$sec['max_capacity'])*100) : 0;
                  $color = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#22c55e');
                  $studs = $section_students[$sec['section_code']] ?? [];
                  $sec_uid = 'sec-' . preg_replace('/[^a-z0-9]/i','_', $sec['section_code']);
                ?>
                <div class="section-card">
                  <div class="section-card-header" onclick="toggleSection('<?= $sec_uid ?>')">
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                      <div>
                        <div style="font-size:.88rem;font-weight:700;color:#1a1a2e;">
                          <?= htmlspecialchars($sec['section_code']) ?>
                        </div>
                      </div>
                      <div style="display:flex;align-items:center;gap:8px;min-width:140px;">
                        <div class="capacity-bar">
                          <div class="capacity-fill" style="width:<?= min($pct,100) ?>%;background:<?= $color ?>;"></div>
                        </div>
                        <span style="font-size:.75rem;font-weight:700;color:<?= $color ?>;white-space:nowrap;">
                          <?= $sec['current_count'] ?>/<?= $sec['max_capacity'] ?>
                        </span>
                      </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                      <span style="font-size:.72rem;color:#aaa;"><?= count($studs) ?> student<?= count($studs)!=1?'s':'' ?></span>
                      <i class="fa-solid fa-chevron-down" id="arrow-<?= $sec_uid ?>"
                         style="color:#aaa;transition:transform .2s;font-size:.75rem;"></i>
                    </div>
                  </div>
                  <div class="section-card-body" id="<?= $sec_uid ?>">
                    <?php if (empty($studs)): ?>
                    <div style="padding:18px;text-align:center;color:#aaa;font-size:.82rem;">
                      <i class="fa-solid fa-user-slash" style="margin-right:4px;"></i>No students yet.
                    </div>
                    <?php else: ?>
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                      <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #f0f2f5;">
                          <th style="padding:8px 16px;text-align:left;color:#555;font-weight:600;font-size:.7rem;text-transform:uppercase;">#</th>
                          <th style="padding:8px 16px;text-align:left;color:#555;font-weight:600;font-size:.7rem;text-transform:uppercase;">Name</th>
                          <th style="padding:8px 16px;text-align:left;color:#555;font-weight:600;font-size:.7rem;text-transform:uppercase;">ID Number</th>
                          <th style="padding:8px 16px;text-align:left;color:#555;font-weight:600;font-size:.7rem;text-transform:uppercase;">Enrolled</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($studs as $i => $st): ?>
                        <tr style="border-bottom:1px solid #f9fafb;">
                          <td style="padding:9px 16px;color:#aaa;"><?= $i+1 ?></td>
                          <td style="padding:9px 16px;font-weight:600;color:#1a1a2e;">
                            <?= htmlspecialchars(($st['first_name'] ?? '').' '.($st['last_name'] ?? '')) ?>
                          </td>
                          <td style="padding:9px 16px;">
                            <code style="font-size:.73rem;background:#eff6ff;color:#2563eb;padding:2px 8px;border-radius:4px;">
                              <?= htmlspecialchars($st['id_number'] ?? '—') ?>
                            </code>
                          </td>
                          <td style="padding:9px 16px;font-size:.73rem;color:#888;">
                            <?= $st['enrolled_at'] ? date('M d, Y', strtotime($st['enrolled_at'])) : '—' ?>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
              </div><!-- end semester -->
              <?php endforeach; ?>
            </div><!-- end year body -->
          </div><!-- end year group -->
          <?php endforeach; ?>
        </div><!-- end course body -->
      </div><!-- end course group -->
      <?php endforeach; ?>
    </div>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- Add Section Modal -->
<div class="modal-overlay" id="addSectionModal">
  <div class="modal-box">
    <h3><i class="fa-solid fa-plus" style="color:#16a34a;margin-right:8px;"></i>Add New Section</h3>
    <p style="font-size:.78rem;color:#6b7280;margin-bottom:16px;margin-top:-8px;">
      The section code is generated automatically based on year level and semester.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="add_section"/>
      <div class="mfield">
        <label>Course <span style="color:#ef4444;">*</span></label>
        <select name="course" required>
          <option value="">Select course…</option>
          <?php foreach ($courses_list as $c): ?>
          <option value="<?= $c ?>"><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mfield-row">
        <div class="mfield">
          <label>Year Level <span style="color:#ef4444;">*</span></label>
          <select name="year_level" required>
            <option value="">Select…</option>
            <?php foreach ($year_levels as $y): ?>
            <option value="<?= $y ?>"><?= $y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mfield">
          <label>Semester <span style="color:#ef4444;">*</span></label>
          <select name="semester" required>
            <option value="1st">1st Semester</option>
            <option value="2nd">2nd Semester</option>
          </select>
        </div>
      </div>
      <div class="mfield">
          <label>Max Capacity</label>
          <input type="number" name="max_capacity" value="50" min="1" max="200"/>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="btnCancelAddSection">Cancel</button>
        <button type="submit" class="btn-approve">
          <i class="fa-solid fa-plus"></i> Create Section
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Confirm Modal -->
<div class="modal-overlay" id="secConfirmModal">
  <div class="modal-box" style="max-width:380px;">
    <h3>Confirm Auto-Assign</h3>
    <p style="font-size:.88rem;color:#555;margin-bottom:20px;line-height:1.55;">
      Auto-assign all <?= count($unassigned) ?> unassigned students to the best matching available section?
    </p>
    <div class="modal-actions">
      <button class="btn-secondary" id="secConfirmCancel">Cancel</button>
      <button class="btn-primary"   id="secConfirmOk">
        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Assign All
      </button>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
var ENROLL_API = '../shared/enrollment_actions.php';

// ── Year group toggle ─────────────────────────────────────────
function toggleYearGroup(id) {
    var body  = document.getElementById(id);
    var arrow = document.getElementById('arrow-' + id);
    if (!body) return;
    var open = body.style.display === 'block';
    body.style.display = open ? 'none' : 'block';
    if (arrow) arrow.style.transform = open ? '' : 'rotate(180deg)';
}

// ── Section card toggle ───────────────────────────────────────
function toggleSection(id) {
    var body  = document.getElementById(id);
    var arrow = document.getElementById('arrow-' + id);
    if (!body) return;
    var open = body.classList.toggle('open');
    if (arrow) arrow.style.transform = open ? 'rotate(180deg)' : '';
}

// ── Add Section Modal ─────────────────────────────────────────
var addModal = document.getElementById('addSectionModal');
document.getElementById('btnAddSection').addEventListener('click', function() {
    addModal.classList.add('active');
});
document.getElementById('btnCancelAddSection').addEventListener('click', function() {
    addModal.classList.remove('active');
});
addModal.addEventListener('click', function(e) {
    if (e.target === addModal) addModal.classList.remove('active');
});

// ── Assign single student ─────────────────────────────────────
document.querySelectorAll('.btn-assign-sec').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var row = btn.closest('tr');
        var sel = row.querySelector('.section-select');
        if (!sel.value) {
            showAlertModal('Please select a section first.', 'warning', 'No Section Selected');
            return;
        }
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        var fd = new FormData();
        fd.append('action',        'assign_section');
        fd.append('enrollment_id', btn.getAttribute('data-id'));
        fd.append('section_code',  sel.value);

        fetch(ENROLL_API, { method:'POST', body:fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    row.style.opacity = '0';
                    setTimeout(function() { row.remove(); }, 300);
                    showToast('Section assigned successfully.', 'success');
                } else {
                    showAlertModal(data.message, 'error', 'Assignment Failed');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> Assign';
                }
            })
            .catch(function() {
                showAlertModal('Request failed.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Assign';
            });
    });
});

// ── Auto-assign all ───────────────────────────────────────────
var secConfirmModal = document.getElementById('secConfirmModal');
var btnAutoAssign   = document.getElementById('btnAutoAssignAll');

if (btnAutoAssign) {
    btnAutoAssign.addEventListener('click', function() {
        secConfirmModal.classList.add('active');
    });
}

document.getElementById('secConfirmCancel').addEventListener('click', function() {
    secConfirmModal.classList.remove('active');
});
secConfirmModal.addEventListener('click', function(e) {
    if (e.target === secConfirmModal) secConfirmModal.classList.remove('active');
});

document.getElementById('secConfirmOk').addEventListener('click', function() {
    secConfirmModal.classList.remove('active');
    // Submit a form POST to the server for reliable processing
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'action';
    input.value = 'auto_assign_all';
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
});
</script>
</body>
</html>
<?php $conn->close(); ?>

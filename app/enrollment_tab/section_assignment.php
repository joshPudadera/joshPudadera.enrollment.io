<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// ── Handle Auto-Assign POST ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto_assign_all') {
    $assigned = 0;
    $created  = 0;

    // Get all unassigned enrolled students
    $unassigned_q = $conn->query(
        "SELECT e.id, e.course, e.year_level, p.first_name, p.last_name
         FROM enrollments e
         JOIN pre_registrations p ON e.pre_reg_id = p.id
         WHERE (e.section IS NULL OR e.section = '')
         ORDER BY e.course ASC, e.year_level ASC, p.last_name ASC"
    );
    $to_assign = [];
    if ($unassigned_q) while ($r = $unassigned_q->fetch_assoc()) $to_assign[] = $r;

    foreach ($to_assign as $student) {
        $course    = $student['course'];
        $year_lv   = $student['year_level'];
        $enroll_id = $student['id'];

        // Find sections for this course+year that are not full (count actual students)
        $free = $conn->query(
            "SELECT s.section_code,
                    COUNT(e2.id) AS actual_count
             FROM sections s
             LEFT JOIN enrollments e2 ON e2.section = s.section_code
             WHERE s.course = '" . $conn->real_escape_string($course) . "'
               AND s.year_level = '" . $conn->real_escape_string($year_lv) . "'
               AND s.is_active = 1
             GROUP BY s.section_code, s.max_capacity
             HAVING actual_count < 50
             ORDER BY actual_count ASC
             LIMIT 1"
        );

        $target_section = null;
        if ($free && $free->num_rows > 0) {
            $target_section = $free->fetch_assoc()['section_code'];
        } else {
            // All sections full OR no section exists — create a new one
            // Build next section code: derive prefix from course
            $words   = explode(' ', $course);
            $prefix  = '';
            foreach ($words as $w) {
                if (strlen($w) > 2 && !in_array(strtolower($w), ['of','in','and','the'])) {
                    $prefix .= strtoupper($w[0]);
                }
            }
            $prefix = strtoupper($prefix ?: 'SEC') . '-' . substr($year_lv, 0, 1);

            // Find next available letter
            $letter = 'A';
            while (true) {
                $code_try = $prefix . $letter;
                $exists   = $conn->query("SELECT id FROM sections WHERE section_code='" .
                            $conn->real_escape_string($code_try) . "' LIMIT 1");
                if ($exists && $exists->num_rows === 0) break;
                $letter = chr(ord($letter) + 1);
                if ($letter > 'Z') { $letter = 'A1'; break; } // safety
            }

            $new_code = $prefix . $letter;
            $ins = $conn->prepare(
                'INSERT INTO sections (section_code, course, year_level, max_capacity, current_count)
                 VALUES (?,?,?,50,0)'
            );
            $ins->bind_param('sss', $new_code, $course, $year_lv);
            if ($ins->execute()) {
                $target_section = $new_code;
                $created++;
            }
            $ins->close();
        }

        if ($target_section) {
            $upd = $conn->prepare('UPDATE enrollments SET section=? WHERE id=?');
            $upd->bind_param('si', $target_section, $enroll_id);
            if ($upd->execute()) {
                // Sync current_count
                $conn->query("UPDATE sections SET current_count = (
                    SELECT COUNT(*) FROM enrollments WHERE section = '" .
                    $conn->real_escape_string($target_section) . "'
                ) WHERE section_code = '" . $conn->real_escape_string($target_section) . "'");
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
    $code     = trim($_POST['section_code']  ?? '');
    $course   = trim($_POST['course']        ?? '');
    $year_lv  = trim($_POST['year_level']    ?? '');
    $adviser  = trim($_POST['adviser_name']  ?? '');
    $capacity = max(1, (int)($_POST['max_capacity'] ?? 40));

    if (!$code || !$course || !$year_lv) {
        $add_err = 'Section code, course, and year level are required.';
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO sections (section_code, course, year_level, adviser_name, max_capacity)
             VALUES (?,?,?,?,?)'
        );
        $stmt->bind_param('ssssi', $code, $course, $year_lv, $adviser, $capacity);
        if ($stmt->execute()) {
            $add_msg = "Section $code created successfully.";
        } else {
            $add_err = $conn->errno == 1062
                ? "Section code '$code' already exists."
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

// ── Sections with REAL student count ─────────────────────────
$sections = [];
$res = $conn->query(
    "SELECT s.*,
            COUNT(e.id) AS actual_count,
            50 AS effective_cap
     FROM sections s
     LEFT JOIN enrollments e ON e.section = s.section_code
     $where
     GROUP BY s.id
     ORDER BY s.course ASC, s.year_level ASC, s.section_code ASC"
);
if ($res) while ($r = $res->fetch_assoc()) {
    // Always use 50 as cap, update DB if different
    if ($r['max_capacity'] != 50) {
        $conn->query("UPDATE sections SET max_capacity=50 WHERE id={$r['id']}");
        $r['max_capacity'] = 50;
    }
    $r['current_count'] = (int)$r['actual_count']; // use real count
    $sections[] = $r;
}

// ── Students per section ──────────────────────────────────────
// Map section_code → [ student rows ]
$section_students = [];
$res3 = $conn->query(
    "SELECT e.section, p.first_name, p.last_name, p.year_level, e.id_number, e.enrolled_at
     FROM enrollments e
     JOIN pre_registrations p ON e.pre_reg_id = p.id
     WHERE e.section IS NOT NULL AND e.section != ''
     ORDER BY e.section ASC, p.last_name ASC"
);
if ($res3) {
    while ($r = $res3->fetch_assoc()) {
        $section_students[$r['section']][] = $r;
    }
}

// ── Unassigned enrollments ────────────────────────────────────
$unassigned = [];
$res4 = $conn->query(
    "SELECT e.*, p.first_name, p.last_name FROM enrollments e
     JOIN pre_registrations p ON e.pre_reg_id = p.id
     WHERE (e.section IS NULL OR e.section = '')
     ORDER BY p.last_name ASC"
);
if ($res4) while ($r = $res4->fetch_assoc()) $unassigned[] = $r;

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
    .modal-overlay.show { display:flex; }
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

    <!-- Sections list -->
    <div style="padding:0 24px;">
      <?php if (empty($sections)): ?>
      <div class="crud-card" style="text-align:center;padding:40px;color:#aaa;">
        <i class="fa-solid fa-chalkboard" style="font-size:2rem;display:block;margin-bottom:12px;"></i>
        No sections found<?= $search ? " matching \"$search\"" : '' ?>.
      </div>
      <?php endif; ?>

      <?php
      // Group by course
      $by_course = [];
      foreach ($sections as $sec) {
          $by_course[$sec['course']][] = $sec;
      }
      foreach ($by_course as $course_name => $course_sections):
        $short = preg_replace('/Bachelor of Science in /i','BS ',$course_name);
      ?>
      <div style="margin-bottom:24px;">
        <h3 style="font-size:.88rem;font-weight:700;color:#1a3a8c;margin-bottom:10px;
                   padding-bottom:6px;border-bottom:2px solid #eff6ff;">
          <i class="fa-solid fa-graduation-cap" style="margin-right:6px;"></i>
          <?= htmlspecialchars($short) ?>
        </h3>

        <?php foreach ($course_sections as $sec):
          $pct   = $sec['max_capacity'] > 0 ? round(($sec['current_count']/$sec['max_capacity'])*100) : 0;
          $color = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#22c55e');
          $studs = $section_students[$sec['section_code']] ?? [];
        ?>
        <div class="section-card">
          <!-- Section header — click to toggle -->
          <div class="section-card-header" onclick="toggleSection('sec-<?= htmlspecialchars($sec['section_code']) ?>')">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
              <div>
                <div style="font-size:.9rem;font-weight:700;color:#1a1a2e;">
                  <?= htmlspecialchars($sec['section_code']) ?>
                  <span style="font-size:.72rem;color:#aaa;font-weight:400;margin-left:6px;">
                    <?= htmlspecialchars($sec['year_level']) ?>
                  </span>
                </div>
                <?php if ($sec['adviser_name']): ?>
                <div style="font-size:.72rem;color:#888;margin-top:2px;">
                  <i class="fa-solid fa-chalkboard-user" style="margin-right:4px;"></i>
                  <?= htmlspecialchars($sec['adviser_name']) ?>
                </div>
                <?php endif; ?>
              </div>
              <div style="display:flex;align-items:center;gap:8px;min-width:160px;">
                <div class="capacity-bar">
                  <div class="capacity-fill"
                       style="width:<?= min($pct,100) ?>%;background:<?= $color ?>;"></div>
                </div>
                <span style="font-size:.75rem;font-weight:700;color:<?= $color ?>;white-space:nowrap;">
                  <?= $sec['current_count'] ?>/<?= $sec['max_capacity'] ?>
                </span>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="font-size:.75rem;color:#aaa;">
                <?= count($studs) ?> student<?= count($studs)!=1?'s':'' ?>
              </span>
              <i class="fa-solid fa-chevron-down"
                 style="color:#aaa;transition:transform .2s;"
                 id="arrow-sec-<?= htmlspecialchars($sec['section_code']) ?>"></i>
            </div>
          </div>

          <!-- Section body — students table -->
          <div class="section-card-body" id="sec-<?= htmlspecialchars($sec['section_code']) ?>">
            <?php if (empty($studs)): ?>
            <div style="padding:20px;text-align:center;color:#aaa;font-size:.82rem;">
              <i class="fa-solid fa-user-slash" style="margin-right:6px;"></i>
              No students assigned to this section yet.
            </div>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
              <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #f0f2f5;">
                  <th style="padding:10px 16px;text-align:left;color:#555;font-weight:600;font-size:.72rem;text-transform:uppercase;">#</th>
                  <th style="padding:10px 16px;text-align:left;color:#555;font-weight:600;font-size:.72rem;text-transform:uppercase;">Name</th>
                  <th style="padding:10px 16px;text-align:left;color:#555;font-weight:600;font-size:.72rem;text-transform:uppercase;">Year Level</th>
                  <th style="padding:10px 16px;text-align:left;color:#555;font-weight:600;font-size:.72rem;text-transform:uppercase;">ID Number</th>
                  <th style="padding:10px 16px;text-align:left;color:#555;font-weight:600;font-size:.72rem;text-transform:uppercase;">Enrolled</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($studs as $i => $st): ?>
                <tr style="border-bottom:1px solid #f9fafb;">
                  <td style="padding:10px 16px;color:#aaa;"><?= $i+1 ?></td>
                  <td style="padding:10px 16px;font-weight:600;color:#1a1a2e;">
                    <?= htmlspecialchars($st['first_name'].' '.$st['last_name']) ?>
                  </td>
                  <td style="padding:10px 16px;color:#555;"><?= htmlspecialchars($st['year_level']) ?></td>
                  <td style="padding:10px 16px;">
                    <code style="font-size:.75rem;background:#eff6ff;color:#2563eb;padding:2px 8px;border-radius:4px;">
                      <?= htmlspecialchars($st['id_number'] ?? '—') ?>
                    </code>
                  </td>
                  <td style="padding:10px 16px;font-size:.75rem;color:#888;">
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
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Unassigned students -->
    <?php if ($unassigned): ?>
    <div class="crud-card" style="margin:0 24px 24px;">
      <div class="crud-header">
        <h3><i class="fa-solid fa-user-clock" style="color:#f59e0b;margin-right:6px;"></i>
          Unassigned Students (<?= count($unassigned) ?>)
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
          <tr data-id="<?= $e['id'] ?>" data-course="<?= htmlspecialchars($e['course']) ?>" data-year="<?= htmlspecialchars($e['year_level']) ?>">
            <td><code style="font-size:.75rem;background:#eff6ff;color:#2563eb;padding:2px 8px;border-radius:4px;">
              <?= htmlspecialchars($e['id_number']) ?></code></td>
            <td><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></td>
            <td style="font-size:.75rem;"><?= htmlspecialchars(preg_replace('/Bachelor of Science in /i','BS ',$e['course'])) ?></td>
            <td><?= htmlspecialchars($e['year_level']) ?></td>
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
                <button class="btn-approve btn-assign-sec" data-id="<?= $e['id'] ?>">
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

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- Add Section Modal -->
<div class="modal-overlay" id="addSectionModal">
  <div class="modal-box">
    <h3><i class="fa-solid fa-plus" style="color:#16a34a;margin-right:8px;"></i>Add New Section</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_section"/>
      <div class="mfield-row">
        <div class="mfield">
          <label>Section Code <span style="color:#ef4444;">*</span></label>
          <input type="text" name="section_code" placeholder="e.g. BSIT-1B" required/>
        </div>
        <div class="mfield">
          <label>Max Capacity</label>
          <input type="number" name="max_capacity" value="40" min="1" max="200"/>
        </div>
      </div>
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
          <label>Adviser Name</label>
          <input type="text" name="adviser_name" placeholder="Prof. Santos"/>
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
<script src="../js/sidebar.js"></script>
<script src="../js/dashboard.js"></script>
<script>
var ENROLL_API = '../shared/enrollment_actions.php';

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
    addModal.classList.add('show');
});
document.getElementById('btnCancelAddSection').addEventListener('click', function() {
    addModal.classList.remove('show');
});
addModal.addEventListener('click', function(e) {
    if (e.target === addModal) addModal.classList.remove('show');
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
        secConfirmModal.classList.add('show');
    });
}

document.getElementById('secConfirmCancel').addEventListener('click', function() {
    secConfirmModal.classList.remove('show');
});
secConfirmModal.addEventListener('click', function(e) {
    if (e.target === secConfirmModal) secConfirmModal.classList.remove('show');
});

document.getElementById('secConfirmOk').addEventListener('click', function() {
    secConfirmModal.classList.remove('show');
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

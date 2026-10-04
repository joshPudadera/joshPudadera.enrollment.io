<?php
// ============================================================
//  DOCUMENT_ERRORS.PHP  (admin/)
//  Shows all enrollment_documents rows where name_match_status
//  = 'mismatch', with a search bar for filtering by student name.
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff()) { header('Location: ../auth/signin.php'); exit; }
if (!enrollment_tables_exist($conn)) { header('Location: ../shared/full_setup.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

// Ensure columns exist
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_name VARCHAR(255) DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_dob DATE DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_sex VARCHAR(20) DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_citizenship VARCHAR(80) DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS name_match_status ENUM('match','mismatch','unverified') NOT NULL DEFAULT 'unverified'");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS name_match_notes TEXT DEFAULT NULL");

// ── Search / filter ───────────────────────────────────────────
$search      = trim($_GET['q']      ?? '');
$filter_type = trim($_GET['type']   ?? '');   // BirthCertificate | ReportCard | GoodMoral | ''
$filter_step = trim($_GET['status'] ?? 'all'); // mismatch | unverified | all

$allowed_status = ['mismatch', 'unverified', 'all'];
if (!in_array($filter_step, $allowed_status)) $filter_step = 'all';

// ── Build query ───────────────────────────────────────────────
// Default 'all': show everything that is NOT a confirmed match
$where = ["d.name_match_status != 'match'"];

if ($filter_step === 'mismatch')    $where = ["d.name_match_status = 'mismatch'"];
elseif ($filter_step === 'unverified') $where = ["d.name_match_status = 'unverified'"];

if ($search !== '') {
    $esc     = $conn->real_escape_string($search);
    $where[] = "(p.first_name LIKE '%$esc%'
              OR p.last_name  LIKE '%$esc%'
              OR d.extracted_name LIKE '%$esc%'
              OR p.ref_number LIKE '%$esc%')";
}
if ($filter_type) {
    $esc_t   = $conn->real_escape_string($filter_type);
    $where[] = "d.document_type = '$esc_t'";
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$rows = [];
$res  = $conn->query(
    "SELECT d.id AS doc_id,
            d.document_type, d.file_name, d.file_path,
            d.extracted_name, d.extracted_dob, d.extracted_sex, d.extracted_citizenship,
            d.name_match_status, d.name_match_notes,
            d.redaction_status, d.ai_result,
            d.status AS doc_status, d.uploaded_at,
            p.id AS pre_reg_id, p.ref_number,
            p.first_name AS reg_first, p.last_name AS reg_last,
            p.email, p.course, p.year_level, p.applicant_type,
            p.status AS app_status
     FROM enrollment_documents d
     LEFT JOIN pre_registrations p ON d.pre_reg_id = p.id
     $where_sql
     ORDER BY d.uploaded_at DESC"
);
if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

// ── Summary counts ────────────────────────────────────────────
$count_mismatch    = 0;
$count_unverified  = 0;
$sc = $conn->query(
    "SELECT name_match_status, COUNT(*) c
     FROM enrollment_documents
     GROUP BY name_match_status"
);
if ($sc) while ($r = $sc->fetch_assoc()) {
    if ($r['name_match_status'] === 'mismatch')   $count_mismatch   = (int)$r['c'];
    if ($r['name_match_status'] === 'unverified')  $count_unverified = (int)$r['c'];
}

// ── Handle admin manual override (mark as match / dismiss) ────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'override') {
    $doc_id    = (int)($_POST['doc_id']  ?? 0);
    $new_nm    = in_array($_POST['new_status'] ?? '', ['match','unverified'])
                 ? $_POST['new_status'] : 'unverified';
    $note_add  = trim($_POST['admin_note'] ?? '');
    if ($doc_id) {
        $upd = $conn->prepare(
            "UPDATE enrollment_documents
             SET name_match_status=?,
                 name_match_notes = CONCAT(IFNULL(name_match_notes,''), '\n[Admin override: ', ?, ']')
             WHERE id=?"
        );
        $upd->bind_param('ssi', $new_nm, $note_add, $doc_id);
        $upd->execute();
        $upd->close();
    }
    header('Location: document_errors.php?q=' . urlencode($search) . '&status=' . $filter_step . '&type=' . $filter_type);
    exit;
}

// ── Re-run name check on an unverified document ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recheck') {
    $doc_id = (int)($_POST['doc_id'] ?? 0);
    if ($doc_id) {
        // Fetch document and its pre-registration name
        $drow = $conn->query(
            "SELECT d.file_path, d.document_type,
                    TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS reg_name
             FROM enrollment_documents d
             JOIN pre_registrations p ON d.pre_reg_id = p.id
             WHERE d.id = $doc_id LIMIT 1"
        )->fetch_assoc();

        if ($drow) {
            $abs_path = realpath(__DIR__ . '/../requirements/' . $drow['file_path']);
            $reg_name = $drow['reg_name'];

            if ($abs_path && file_exists($abs_path)) {
                // Run Python extraction
                require_once __DIR__ . '/../shared/ai_inspect.php';
                $pii = extract_pii_before_redact($abs_path, $drow['document_type']);

                $ext_name  = $pii['success'] ? ($pii['full_name'] ?? '') : '';
                $ext_dob   = $pii['success'] ? ($pii['date_of_birth'] ?: null) : null;
                $ext_sex   = $pii['success'] ? ($pii['sex'] ?? '') : '';
                $ext_cit   = $pii['success'] ? ($pii['citizenship'] ?? '') : '';

                $nm_status = 'unverified';
                $nm_notes  = $pii['success'] ? '' : ('Extraction failed: ' . ($pii['error'] ?? 'unknown'));

                if ($ext_name && $reg_name) {
                    $norm = function(string $s): array {
                        $s = strtolower(preg_replace('/[^a-z\s]/i', '', $s));
                        $t = array_values(array_filter(explode(' ', trim($s))));
                        sort($t);
                        return $t;
                    };
                    $dt = $norm($ext_name);
                    $rt = $norm($reg_name);
                    $m  = count(array_intersect($dt, $rt));
                    $to = max(count($rt), 1);

                    if ($m >= max(1, ceil($to * 0.5))) {
                        $nm_status = 'match';
                        $nm_notes  = "Extracted: \"{$ext_name}\" — Registration: \"{$reg_name}\"";
                    } else {
                        $nm_status = 'mismatch';
                        $nm_notes  = "MISMATCH — Document: \"{$ext_name}\" vs Registration: \"{$reg_name}\" (matched {$m}/{$to} tokens)";
                    }
                }

                $upd = $conn->prepare(
                    "UPDATE enrollment_documents
                     SET extracted_name=?, extracted_dob=?, extracted_sex=?,
                         extracted_citizenship=?, name_match_status=?, name_match_notes=?
                     WHERE id=?"
                );
                $upd->bind_param('ssssssi',
                    $ext_name, $ext_dob, $ext_sex, $ext_cit,
                    $nm_status, $nm_notes, $doc_id
                );
                $upd->execute();
                $upd->close();
            }
        }
    }
    header('Location: document_errors.php?q=' . urlencode($search) . '&status=' . $filter_step . '&type=' . $filter_type . '&recheckdone=1');
    exit;
}

$conn->close();

$APP_ROOT   = '../';
$ACTIVE_NAV = 'doc_errors';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Name Mismatch Errors — Admin</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    .error-card {
      background: #fff;
      border-radius: 12px;
      border: 1.5px solid #e5e7eb;
      margin-bottom: 16px;
      overflow: hidden;
      transition: box-shadow .15s;
    }
    .error-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); }
    .error-card-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 20px; gap: 12px; flex-wrap: wrap;
      border-left: 4px solid #ef4444;
      background: #fff1f2;
    }
    .error-card-header.unverified {
      border-left-color: #f59e0b;
      background: #fffbeb;
    }
    .error-card-body {
      padding: 16px 20px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
    }
    .ec-section h5 {
      font-size: .7rem; font-weight: 700; text-transform: uppercase;
      color: #1a3a8c; letter-spacing: .04em;
      margin-bottom: 8px; padding-bottom: 4px;
      border-bottom: 1.5px solid #eff6ff;
    }
    .ec-row { display: flex; gap: 8px; font-size: .80rem; margin-bottom: 5px; }
    .ec-label { color: #888; font-weight: 600; min-width: 100px; flex-shrink: 0; }
    .ec-val   { color: #1a1a2e; word-break: break-word; }
    .ec-val.highlight { color: #dc2626; font-weight: 700; }
    .ec-val.ok        { color: #16a34a; font-weight: 700; }
    .badge-mismatch   { background:#fee2e2;color:#dc2626;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700; }
    .badge-unverified { background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700; }
    .badge-match      { background:#dcfce7;color:#16a34a;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700; }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap"><input type="text" placeholder="Search..."/><i class="fa-solid fa-magnifying-glass"></i></div>
      <a href="../admin_dashboard/account.php" class="avatar" title="Account"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-user-xmark"></i> Name Mismatch Errors
      </h2>
    </div>

    <!-- ── Info banner ── -->
    <?php if (isset($_GET['recheckdone'])): ?>
    <div style="margin:0 24px 14px;background:#f0fdf4;border:1.5px solid #86efac;
                border-radius:10px;padding:12px 16px;font-size:.82rem;color:#16a34a;">
      <i class="fa-solid fa-circle-check"></i>
      Re-check complete. Results updated above.
    </div>
    <?php endif; ?>
    <div style="margin:0 24px 18px;background:#fff1f2;border:1.5px solid #fca5a5;
                border-radius:10px;padding:12px 16px;font-size:.82rem;color:#991b1b;
                display:flex;align-items:flex-start;gap:10px;">
      <i class="fa-solid fa-circle-info" style="flex-shrink:0;margin-top:2px;"></i>
      <span>
        These are documents where the name extracted from the document (via AI) does
        <strong>not match</strong> the name the student entered in pre-registration.
        Review each case and use the <strong>Override</strong> button to accept or flag it.
      </span>
    </div>

    <!-- ── Stat cards ── -->
    <div class="info-row">
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-user-xmark" style="color:#dc2626;"></i> Mismatches</div>
        <div class="card-amount" style="color:#dc2626;"><?= $count_mismatch ?></div>
        <div class="card-detail">Document name ≠ registration name</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Unverified</div>
        <div class="card-amount" style="color:#f59e0b;"><?= $count_unverified ?></div>
        <div class="card-detail">Extraction failed or not yet run</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-triangle-exclamation" style="color:#7c3aed;"></i> Total Issues</div>
        <div class="card-amount" style="color:#7c3aed;"><?= $count_mismatch + $count_unverified ?></div>
        <div class="card-detail">Require admin review</div>
      </div>
    </div>

    <!-- ── Search + filters ── -->
    <div style="padding:0 24px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">

      <form method="GET" style="display:flex;gap:8px;flex:1;min-width:260px;flex-wrap:wrap;">
        <!-- Search box -->
        <div style="position:relative;flex:1;min-width:200px;">
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                 placeholder="Search by student name, extracted name, or ref no.…"
                 style="width:100%;height:40px;border:1.5px solid #d0d7e2;border-radius:8px;
                        padding:0 14px 0 38px;font-size:.85rem;outline:none;font-family:inherit;"/>
          <i class="fa-solid fa-magnifying-glass"
             style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#aaa;"></i>
        </div>

        <!-- Status filter -->
        <select name="status" style="height:40px;border:1.5px solid #d0d7e2;border-radius:8px;
                padding:0 12px;font-size:.82rem;background:#fff;font-family:inherit;">
          <option value="all"        <?= $filter_step==='all'        ?'selected':'' ?>>All issues</option>
          <option value="mismatch"   <?= $filter_step==='mismatch'   ?'selected':'' ?>>Mismatches only</option>
          <option value="unverified" <?= $filter_step==='unverified' ?'selected':'' ?>>Unverified only</option>
        </select>

        <!-- Doc type filter -->
        <select name="type" style="height:40px;border:1.5px solid #d0d7e2;border-radius:8px;
                padding:0 12px;font-size:.82rem;background:#fff;font-family:inherit;">
          <option value="">All document types</option>
          <option value="BirthCertificate" <?= $filter_type==='BirthCertificate'?'selected':'' ?>>Birth Certificate</option>
          <option value="ReportCard"       <?= $filter_type==='ReportCard'      ?'selected':'' ?>>Report Card</option>
          <option value="GoodMoral"        <?= $filter_type==='GoodMoral'       ?'selected':'' ?>>Good Moral</option>
        </select>

        <button type="submit" class="btn-primary"
                style="height:40px;padding:0 18px;background:#1a3a8c;color:#fff;border:none;
                       border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;
                       display:inline-flex;align-items:center;gap:6px;">
          <i class="fa-solid fa-magnifying-glass"></i> Search
        </button>
        <?php if ($search || $filter_type || $filter_step !== 'all'): ?>
        <a href="document_errors.php"
           style="height:40px;padding:0 14px;background:#f3f4f6;color:#555;border:1.5px solid #e5e7eb;
                  border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;
                  display:inline-flex;align-items:center;gap:6px;">
          <i class="fa-solid fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
      </form>

      <span style="font-size:.75rem;color:#888;white-space:nowrap;align-self:center;">
        <?= count($rows) ?> result<?= count($rows) !== 1 ? 's' : '' ?>
      </span>
    </div>

    <!-- ── Error cards ── -->
    <div style="padding:0 24px;">

      <?php if (empty($rows)): ?>
      <div class="crud-card" style="text-align:center;padding:48px;color:#aaa;">
        <i class="fa-solid fa-user-check" style="font-size:2rem;color:#16a34a;display:block;margin-bottom:12px;opacity:.5;"></i>
        <?= $search ? 'No results matching your search.' : 'No name mismatch errors found.' ?>
      </div>
      <?php endif; ?>

      <?php foreach ($rows as $row):
        $nm         = $row['name_match_status'];
        $hdr_cls    = $nm === 'mismatch' ? '' : 'unverified';
        $reg_name   = htmlspecialchars(trim($row['reg_first'] . ' ' . $row['reg_last']));
        $ext_name   = htmlspecialchars($row['extracted_name'] ?? '—');
        $ai_raw     = $row['ai_result'] ? json_decode($row['ai_result'], true) : null;
        $ai_v       = $ai_raw['is_authentic']  ?? null;
        $ai_conf    = (int)($ai_raw['confidence'] ?? 0);
        $doc_labels = [
            'BirthCertificate' => 'PSA Birth Certificate',
            'ReportCard'       => 'Report Card (Form 138)',
            'GoodMoral'        => 'Good Moral Certificate',
        ];
        $doc_label  = $doc_labels[$row['document_type']] ?? $row['document_type'];
      ?>
      <div class="error-card">

        <!-- Card header -->
        <div class="error-card-header <?= $hdr_cls ?>">
          <div>
            <div style="font-weight:700;font-size:.9rem;color:#1a1a2e;margin-bottom:3px;">
              <i class="fa-solid fa-file-lines" style="color:#1a3a8c;margin-right:6px;"></i>
              <?= $doc_label ?>
              <span style="font-size:.72rem;font-weight:400;color:#aaa;margin-left:6px;">#<?= $row['doc_id'] ?></span>
            </div>
            <div style="font-size:.78rem;color:#666;">
              Applicant: <strong><?= $reg_name ?></strong>
              &nbsp;·&nbsp; <span style="color:#2563eb;"><?= htmlspecialchars($row['ref_number'] ?? '—') ?></span>
              &nbsp;·&nbsp; <?= htmlspecialchars($row['course'] ?? '—') ?>
              &nbsp;·&nbsp; Uploaded: <?= date('M d, Y g:i A', strtotime($row['uploaded_at'])) ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex-shrink:0;">
            <?php if ($nm === 'mismatch'): ?>
            <span class="badge-mismatch">
              <i class="fa-solid fa-user-xmark"></i> Name Mismatch
            </span>
            <?php elseif ($nm === 'unverified'): ?>
            <span class="badge-unverified">
              <i class="fa-solid fa-clock"></i> Unverified
            </span>
            <?php endif; ?>
            <!-- Override button -->
            <button class="btn-override"
                    data-doc-id="<?= $row['doc_id'] ?>"
                    data-reg-name="<?= htmlspecialchars($reg_name) ?>"
                    data-ext-name="<?= $ext_name ?>"
                    style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:7px;
                           padding:6px 12px;color:#2563eb;font-size:.75rem;font-weight:600;
                           cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
              <i class="fa-solid fa-pen-to-square"></i> Override
            </button>
            <!-- Re-check button (for unverified rows) -->
            <?php if ($nm === 'unverified'): ?>
            <form method="POST" style="display:inline;"
                  onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Running…';">
              <input type="hidden" name="action"  value="recheck"/>
              <input type="hidden" name="doc_id"  value="<?= $row['doc_id'] ?>"/>
              <input type="hidden" name="status"  value="<?= htmlspecialchars($filter_step) ?>"/>
              <input type="hidden" name="q"       value="<?= htmlspecialchars($search) ?>"/>
              <input type="hidden" name="type"    value="<?= htmlspecialchars($filter_type) ?>"/>
              <button type="submit"
                      style="background:#fff7ed;border:1.5px solid #fcd34d;border-radius:7px;
                             padding:6px 12px;color:#d97706;font-size:.75rem;font-weight:600;
                             cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
                <i class="fa-solid fa-rotate-right"></i> Re-check Name
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- Card body -->
        <div class="error-card-body">

          <!-- Column 1: Name comparison -->
          <div class="ec-section">
            <h5><i class="fa-solid fa-user"></i> Name Comparison</h5>
            <div class="ec-row">
              <span class="ec-label">Registered as:</span>
              <span class="ec-val ok"><?= $reg_name ?></span>
            </div>
            <div class="ec-row">
              <span class="ec-label">In document:</span>
              <span class="ec-val <?= $nm === 'mismatch' ? 'highlight' : '' ?>">
                <?= $ext_name ?>
              </span>
            </div>
            <?php if ($row['name_match_notes']): ?>
            <div style="margin-top:8px;background:#f8fafc;border-left:3px solid #cbd5e1;
                        padding:6px 10px;font-size:.75rem;color:#555;border-radius:0 6px 6px 0;
                        line-height:1.5;white-space:pre-wrap;word-break:break-word;">
              <?= htmlspecialchars($row['name_match_notes']) ?>
            </div>
            <?php endif; ?>
          </div>

          <!-- Column 2: Extracted PII -->
          <div class="ec-section">
            <h5><i class="fa-solid fa-id-card"></i> Extracted from Document</h5>
            <div class="ec-row">
              <span class="ec-label">Full Name:</span>
              <span class="ec-val"><?= htmlspecialchars($row['extracted_name'] ?? '—') ?></span>
            </div>
            <div class="ec-row">
              <span class="ec-label">Date of Birth:</span>
              <span class="ec-val"><?= htmlspecialchars($row['extracted_dob'] ?? '—') ?></span>
            </div>
            <div class="ec-row">
              <span class="ec-label">Sex:</span>
              <span class="ec-val"><?= htmlspecialchars($row['extracted_sex'] ?? '—') ?></span>
            </div>
            <div class="ec-row">
              <span class="ec-label">Citizenship:</span>
              <span class="ec-val"><?= htmlspecialchars($row['extracted_citizenship'] ?? '—') ?></span>
            </div>
          </div>

          <!-- Column 3: AI check + file -->
          <div class="ec-section">
            <h5><i class="fa-solid fa-robot"></i> AI Structural Check</h5>
            <?php if ($ai_raw): ?>
            <div class="ec-row">
              <span class="ec-label">Verdict:</span>
              <span class="ec-val">
                <?php if ($ai_v === true): ?>
                <span style="color:#16a34a;font-weight:700;"><i class="fa-solid fa-circle-check"></i> Valid</span>
                <?php elseif ($ai_v === false): ?>
                <span style="color:#dc2626;font-weight:700;"><i class="fa-solid fa-circle-xmark"></i> Suspicious</span>
                <?php else: ?>
                <span style="color:#d97706;font-weight:700;"><i class="fa-solid fa-circle-question"></i> Uncertain</span>
                <?php endif; ?>
                (<?= $ai_conf ?>%)
              </span>
            </div>
            <?php if (!empty($ai_raw['notes'])): ?>
            <div style="font-size:.75rem;color:#555;margin-top:6px;line-height:1.5;">
              <?= htmlspecialchars($ai_raw['notes']) ?>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div style="font-size:.78rem;color:#aaa;font-style:italic;">No AI result recorded.</div>
            <?php endif; ?>

            <div style="margin-top:12px;">
              <a href="../requirements/file.php?path=<?= urlencode($row['file_path']) ?>"
                 target="_blank"
                 style="display:inline-flex;align-items:center;gap:5px;background:#eff6ff;
                        color:#2563eb;border:1.5px solid #bfdbfe;border-radius:7px;
                        padding:5px 12px;font-size:.75rem;font-weight:600;text-decoration:none;
                        transition:background .15s;">
                <i class="fa-solid fa-eye"></i> View Original
              </a>
            </div>

            <!-- Applicant type badge -->
            <?php if ($row['applicant_type']): ?>
            <div style="margin-top:8px;">
              <?php
              $tc = match($row['applicant_type']) {
                'Transferee'   => ['#fff7ed','#d97706'],
                'Senior High'  => ['#eff6ff','#2563eb'],
                'Octoberian'   => ['#faf5ff','#7c3aed'],
                default        => ['#dcfce7','#16a34a'],
              };
              ?>
              <span style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;border-radius:20px;
                           padding:2px 9px;font-size:.70rem;font-weight:700;">
                <?= htmlspecialchars($row['applicant_type']) ?>
              </span>
            </div>
            <?php endif; ?>
          </div>

        </div><!-- end card body -->
      </div><!-- end error-card -->
      <?php endforeach; ?>

    </div><!-- end padding wrapper -->
  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- ── Override Modal ── -->
<div class="modal-overlay" id="overrideModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span><i class="fa-solid fa-pen-to-square" style="margin-right:6px;"></i> Override Name Match</span>
      <button class="modal-close" data-close="overrideModal">&times;</button>
    </div>
    <div class="modal-body" style="padding:20px 22px;">
      <div style="font-size:.82rem;color:#555;margin-bottom:14px;line-height:1.6;">
        Registered: <strong id="ovRegName" style="color:#16a34a;"></strong><br>
        In document: <strong id="ovExtName" style="color:#dc2626;"></strong>
      </div>
      <form method="POST" id="overrideForm">
        <input type="hidden" name="action"  value="override"/>
        <input type="hidden" name="doc_id"  id="ovDocId"/>
        <input type="hidden" name="status"  value="<?= htmlspecialchars($filter_step) ?>"/>
        <input type="hidden" name="q"       value="<?= htmlspecialchars($search) ?>"/>
        <input type="hidden" name="type"    value="<?= htmlspecialchars($filter_type) ?>"/>

        <div style="margin-bottom:14px;">
          <label style="font-size:.78rem;font-weight:600;color:#444;display:block;margin-bottom:6px;">
            Override status <span style="color:#ef4444;">*</span>
          </label>
          <select name="new_status"
                  style="width:100%;height:40px;border:1.5px solid #d0d7e2;border-radius:8px;
                         padding:0 12px;font-size:.88rem;outline:none;font-family:inherit;">
            <option value="match">Accept as Match — names refer to the same person</option>
            <option value="unverified">Mark Unverified — requires manual re-check</option>
          </select>
        </div>

        <div>
          <label style="font-size:.78rem;font-weight:600;color:#444;display:block;margin-bottom:6px;">
            Admin note <span style="color:#888;font-weight:400;">(optional)</span>
          </label>
          <input type="text" name="admin_note"
                 placeholder="e.g. Nickname used, typo in pre-reg, etc."
                 style="width:100%;height:40px;border:1.5px solid #d0d7e2;border-radius:8px;
                        padding:0 12px;font-size:.88rem;outline:none;font-family:inherit;box-sizing:border-box;"/>
        </div>
      </form>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="overrideModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmOverride">
        <i class="fa-solid fa-check"></i> Apply Override
      </button>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
// ── Wire up override buttons ──────────────────────────────────
document.querySelectorAll('.btn-override').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('ovDocId').value   = btn.dataset.docId;
        document.getElementById('ovRegName').textContent = btn.dataset.regName;
        document.getElementById('ovExtName').textContent = btn.dataset.extName;
        openModal('overrideModal');
    });
});

document.getElementById('btnConfirmOverride').addEventListener('click', function() {
    document.getElementById('overrideForm').submit();
});
</script>
</body>
</html>
<?php /* $conn already closed above */ ?>

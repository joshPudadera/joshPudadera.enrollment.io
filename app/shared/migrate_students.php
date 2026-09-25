<?php
// ============================================================
//  MIGRATE_STUDENTS.PHP  (shared/)
//  ONE-TIME migration — run once, then this file auto-deletes.
//
//  What it does:
//    1. Adds `pre_reg_id` column to students (if missing).
//    2. Backfills pre_reg_id by matching first+last name
//       against validated pre_registrations (Approved/Enrolled).
//    3. Deletes any students row with no matching pre_registration.
//
//  Visit: https://enrollment.bcpsms2.com/shared/migrate_students.php
// ============================================================
session_start();
require_once __DIR__ . '/db.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    die('<p style="font-family:sans-serif;padding:30px;color:red;">
         Admin only. <a href="../auth/signin.php">Sign in</a> first.</p>');
}

$log    = [];
$errors = [];

function run_sql(mysqli $conn, string $sql, string $label, array &$log, array &$errors): bool {
    if ($conn->query($sql)) {
        $log[] = "✓ $label";
        return true;
    }
    $errors[] = "✗ $label — " . $conn->error;
    return false;
}

// ── Step 1: Add pre_reg_id column if missing ──────────────────
$col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'pre_reg_id'");
if ($col_check && $col_check->num_rows === 0) {
    run_sql(
        $conn,
        "ALTER TABLE students ADD COLUMN pre_reg_id INT UNSIGNED NULL DEFAULT NULL,
         ADD INDEX idx_pre_reg_id (pre_reg_id)",
        'Added pre_reg_id column to students',
        $log, $errors
    );
} else {
    $log[] = '— pre_reg_id column already exists, skipped.';
}

// ── Step 2: Backfill pre_reg_id by name match ─────────────────
$backfill_sql = "
    UPDATE students s
    JOIN (
        SELECT id, first_name, last_name
        FROM pre_registrations
        WHERE status IN ('Approved', 'Enrolled')
    ) p ON LOWER(TRIM(CONVERT(s.first_name USING utf8mb4))) COLLATE utf8mb4_unicode_ci
         = LOWER(TRIM(CONVERT(p.first_name USING utf8mb4))) COLLATE utf8mb4_unicode_ci
       AND LOWER(TRIM(CONVERT(s.last_name  USING utf8mb4))) COLLATE utf8mb4_unicode_ci
         = LOWER(TRIM(CONVERT(p.last_name  USING utf8mb4))) COLLATE utf8mb4_unicode_ci
    SET s.pre_reg_id = p.id
    WHERE s.pre_reg_id IS NULL
";
run_sql($conn, $backfill_sql, 'Backfilled pre_reg_id by name match', $log, $errors);
$log[] = '  → Rows updated: ' . $conn->affected_rows;

// ── Step 3: Delete unmatched students (no pre_reg_id) ─────────
$count_res  = $conn->query("SELECT COUNT(*) c FROM students WHERE pre_reg_id IS NULL");
$orphan_count = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;
$log[] = "  → Orphaned students (no validated pre-registration): $orphan_count";

if ($orphan_count > 0) {
    run_sql(
        $conn,
        "DELETE FROM students WHERE pre_reg_id IS NULL",
        "Deleted $orphan_count orphaned student row(s)",
        $log, $errors
    );
}

// ── Step 4: Report remaining students ─────────────────────────
$remaining = $conn->query("SELECT COUNT(*) c FROM students")->fetch_assoc()['c'];
$log[] = "✓ Students remaining after cleanup: $remaining";

$conn->close();

// ── Auto-delete this file after running ───────────────────────
$self = __FILE__;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Students Migration</title>
  <style>
    body { font-family:'Segoe UI',sans-serif; background:#f0f4f8; padding:40px; }
    .box { background:#fff; border-radius:12px; padding:28px 32px; max-width:640px;
           margin:0 auto; box-shadow:0 4px 20px rgba(0,0,0,.08); }
    h2   { color:#1a3a8c; margin-bottom:20px; }
    .log-line { font-size:.85rem; line-height:2; }
    .log-line.ok  { color:#16a34a; }
    .log-line.err { color:#dc2626; font-weight:600; }
    .done-box { background:#f0fdf4; border:1px solid #86efac; border-radius:8px;
                padding:14px 18px; margin-top:20px; font-size:.85rem; color:#15803d; }
    .err-box  { background:#fff1f2; border:1px solid #fca5a5; border-radius:8px;
                padding:14px 18px; margin-top:20px; font-size:.85rem; color:#dc2626; }
    a.btn { display:inline-block; margin-top:20px; background:#1a3a8c; color:#fff;
            padding:10px 24px; border-radius:8px; text-decoration:none; font-weight:600; }
  </style>
</head>
<body>
<div class="box">
  <h2>🔧 Students Migration</h2>

  <?php foreach ($log as $line): ?>
  <div class="log-line <?= str_starts_with($line,'✗') ? 'err' : 'ok' ?>">
    <?= htmlspecialchars($line) ?>
  </div>
  <?php endforeach; ?>

  <?php if (empty($errors)): ?>
  <div class="done-box">
    ✓ Migration completed successfully. This file will now delete itself.
  </div>
  <?php @unlink($self); ?>
  <?php else: ?>
  <div class="err-box">
    Some steps failed. Check the log above. This file was NOT deleted — fix the errors and re-run.
    <ul style="margin-top:8px;">
      <?php foreach ($errors as $e): ?>
      <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <a href="../admin_dashboard/dashboard.php" class="btn">← Back to Dashboard</a>
</div>
</body>
</html>

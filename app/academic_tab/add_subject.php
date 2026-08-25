<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: subjects.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'subjects';
$PAGE_TITLE = 'Add Subject';
$PAGE_ICON  = 'fa-solid fa-plus';

$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code  = trim($_POST['code']       ?? '');
    $name  = trim($_POST['name']       ?? '');
    $units = (int)($_POST['units']     ?? 3);
    $year  = trim($_POST['year_level'] ?? '');
    $type  = trim($_POST['type']       ?? 'Lec');

    if (!$code || !$name || !$year) {
        $err = 'Code, Name and Year Level are required.';
    } else {
        // Subjects table doesn't exist yet — show helpful message
        $r = $conn->query("SHOW TABLES LIKE 'subjects'");
        if ($r->num_rows === 0) {
            $err = 'The <code>subjects</code> table does not exist yet. Add it to your database schema to enable this feature.';
        } else {
            $stmt = $conn->prepare("INSERT INTO subjects (code, name, units, year_level, type) VALUES (?,?,?,?,?)");
            $stmt->bind_param('ssiss', $code, $name, $units, $year, $type);
            if ($stmt->execute()) { $msg = "Subject \"$name\" added successfully."; }
            else { $err = $conn->error; }
            $stmt->close();
        }
    }
}

ob_start();
?>
<?php if ($msg): ?><div class="auth-success" style="margin-bottom:14px;"><?= $msg ?></div><?php endif; ?>
<?php if ($err):  ?><div class="auth-error"   style="margin-bottom:14px;"><?= $err  ?></div><?php endif; ?>

<div class="form-card">
  <h3>New Subject</h3>
  <form method="POST">
    <div class="form-grid">
      <div class="form-field">
        <label>Subject Code <span class="req">* required</span></label>
        <input type="text" name="code" placeholder="e.g. IT 301" required/>
      </div>
      <div class="form-field">
        <label>Units</label>
        <input type="number" name="units" value="3" min="1" max="6"/>
      </div>
      <div class="form-field full">
        <label>Subject Name <span class="req">* required</span></label>
        <input type="text" name="name" placeholder="e.g. Web Systems & Technologies" required/>
      </div>
      <div class="form-field">
        <label>Year Level <span class="req">* required</span></label>
        <select name="year_level" required>
          <option value="">Select…</option>
          <option>1st Year</option><option>2nd Year</option>
          <option>3rd Year</option><option>4th Year</option><option>All</option>
        </select>
      </div>
      <div class="form-field">
        <label>Type</label>
        <select name="type">
          <option>Lec</option><option>Lab</option><option>Lec/Lab</option>
        </select>
      </div>
    </div>
    <div class="form-submit">
      <button type="submit" class="btn-submit">
        <i class="fa-solid fa-plus"></i> Add Subject
      </button>
    </div>
  </form>
</div>

<div class="form-card" style="margin-top:0;">
  <p style="font-size:.82rem;color:#888;">
    <i class="fa-solid fa-circle-info"></i>
    After adding, view all subjects on the <a href="subjects.php" style="color:#2563eb;">Subject List</a> page.
  </p>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';

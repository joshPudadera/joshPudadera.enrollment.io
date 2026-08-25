<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
require_enrollment_tables($conn);
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_email   = htmlspecialchars($_SESSION['email']      ?? '');

// Check if user already has a pre-reg
$existing = null;
$stmt = $conn->prepare('SELECT * FROM pre_registrations WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1');
$stmt->bind_param('i', $_SESSION['user_id']); $stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Pre-Registration – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
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
      <h2 class="page-title"><i class="fa-solid fa-file-pen"></i> Online Pre-Registration</h2>
    </div>

    <?php if ($existing): ?>
    <!-- Existing application status -->
    <div class="form-card">
      <h3>Your Application Status</h3>
      <?php
        $color = match($existing['status']) {
          'Approved' => '#22c55e', 'Rejected' => '#ef4444',
          'Enrolled' => '#2563eb', default => '#f59e0b'
        };
      ?>
      <div style="display:flex; align-items:center; gap:16px; margin:16px 0; padding:16px;
                  background:#f9fafb; border-radius:10px; border-left:4px solid <?= $color ?>;">
        <i class="fa-solid fa-circle-info" style="font-size:1.5rem; color:<?= $color ?>;"></i>
        <div>
          <div style="font-weight:700; font-size:0.95rem; color:#1a1a2e;"><?= $existing['status'] ?></div>
          <div style="font-size:0.8rem; color:#666; margin-top:2px;">
            Submitted: <?= date('F d, Y', strtotime($existing['submitted_at'])) ?>
            <?php if ($existing['remarks']): ?> &nbsp;·&nbsp; Remarks: <?= htmlspecialchars($existing['remarks']) ?><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="form-grid" style="pointer-events:none; opacity:0.7;">
        <div class="form-field"><label>First Name</label><input type="text" value="<?= htmlspecialchars($existing['first_name']) ?>" readonly/></div>
        <div class="form-field"><label>Last Name</label><input type="text" value="<?= htmlspecialchars($existing['last_name']) ?>" readonly/></div>
        <div class="form-field full"><label>Course</label><input type="text" value="<?= htmlspecialchars($existing['course']) ?>" readonly/></div>
        <div class="form-field"><label>Year Level</label><input type="text" value="<?= htmlspecialchars($existing['year_level']) ?>" readonly/></div>
        <div class="form-field"><label>Phone</label><input type="text" value="<?= htmlspecialchars($existing['phone']) ?>" readonly/></div>
      </div>
      <?php if ($existing['status'] === 'Approved'): ?>
      <div class="form-submit">
        <a href="document_upload.php" class="btn-submit" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;">
          <i class="fa-solid fa-upload"></i> Upload Documents
        </a>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- New application form -->
    <div class="form-card">
      <h3>New Pre-Registration</h3>
      <p style="font-size:0.8rem; color:#888; margin-bottom:16px;">Fill out all required fields to submit your enrollment application.</p>
      <div id="formError" class="auth-error" style="display:none; margin-bottom:14px;"></div>
      <div id="formSuccess" class="auth-success" style="display:none; margin-bottom:14px;"></div>

      <form id="preRegForm">
        <div class="form-grid">
          <div class="form-field">
            <label>First Name <span class="req">* required</span></label>
            <input type="text" name="first_name" value="<?= $sess_first ?>" placeholder="First name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Last Name <span class="req">* required</span></label>
            <input type="text" name="last_name" value="<?= $sess_last ?>" placeholder="Last name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field full">
            <label>Email Address <span class="req">* required</span></label>
            <input type="email" name="email" value="<?= $sess_email ?>" placeholder="email@example.com"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Phone <span class="req">* required</span></label>
            <input type="text" name="phone" placeholder="09XXXXXXXXX"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Birthday <span class="req">* required</span></label>
            <input type="date" name="birthday"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field full">
            <label>Course <span class="req">* required</span></label>
            <select name="course">
              <option value="">Select course…</option>
              <option>Bachelor of Science in Information Technology</option>
              <option>Bachelor of Science in Computer Science</option>
              <option>Bachelor of Science in Information Systems</option>
            </select>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Year Level <span class="req">* required</span></label>
            <select name="year_level">
              <option value="">Select year level…</option>
              <option>1st Year</option>
              <option>2nd Year</option>
              <option>3rd Year</option>
              <option>4th Year</option>
            </select>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Previous School</label>
            <input type="text" name="prev_school" placeholder="Name of last school attended"/>
          </div>
        </div>
        <div class="form-submit">
          <button type="submit" class="btn-submit" id="btnSubmit">
            <i class="fa-solid fa-paper-plane"></i> Submit Application
          </button>
        </div>
      </form>
    </div>
    <?php endif; ?>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
const API = '../shared/enrollment_actions.php';

document.getElementById('preRegForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form    = e.target;
    const errBox  = document.getElementById('formError');
    const okBox   = document.getElementById('formSuccess');
    const btn     = document.getElementById('btnSubmit');
    errBox.style.display = okBox.style.display = 'none';

    const required = ['first_name','last_name','email','phone','birthday','course','year_level'];
    let valid = true;
    required.forEach(name => {
        const input = form.querySelector(`[name="${name}"]`);
        const field = input?.closest('.form-field');
        const err   = field?.querySelector('.field-error');
        if (!input?.value.trim()) {
            input?.classList.add('input-error'); field?.classList.add('has-error');
            if (err) err.textContent = `${name.replace('_',' ')} is required.`;
            valid = false;
        } else {
            input?.classList.remove('input-error'); field?.classList.remove('has-error');
            if (err) err.textContent = '';
        }
    });
    if (!valid) return;

    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting…';
    const fd = new FormData(form); fd.set('action','pre_register');
    try {
        const data = await fetch(API, {method:'POST', body:fd}).then(r=>r.json());
        if (data.success) {
            okBox.textContent = data.message + ' Reloading…';
            okBox.style.display = 'block';
            setTimeout(() => location.reload(), 1500);
        } else {
            errBox.textContent = data.message; errBox.style.display = 'block';
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Application';
        }
    } catch { errBox.textContent = 'Request failed.'; errBox.style.display='block'; btn.disabled=false; }
});
</script>
</body></html>
<?php $conn->close(); ?>

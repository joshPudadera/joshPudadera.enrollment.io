<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'settings';
$PAGE_TITLE = 'System Settings';
$PAGE_ICON  = 'fa-solid fa-gear';

ob_start();
?>
<div class="form-card">
  <h3>General Settings</h3>
  <p style="font-size:0.82rem;color:#888;margin-bottom:20px;">Configure system-wide preferences.</p>
  <div class="form-grid">
    <div class="form-field full">
      <label>School Name</label>
      <input type="text" value="Bestlink College of the Philippines" readonly/>
    </div>
    <div class="form-field">
      <label>Current School Year</label>
      <input type="text" value="2025–2026"/>
    </div>
    <div class="form-field">
      <label>Current Semester</label>
      <select>
        <option selected>1st Semester</option>
        <option>2nd Semester</option>
        <option>Summer</option>
      </select>
    </div>
    <div class="form-field">
      <label>Enrollment Status</label>
      <select>
        <option selected>Open</option>
        <option>Closed</option>
        <option>Waitlisting Only</option>
      </select>
    </div>
    <div class="form-field">
      <label>Max Section Capacity</label>
      <input type="number" value="40" min="1" max="100"/>
    </div>
  </div>
  <div class="form-submit">
    <button class="btn-submit" onclick="showAlertModal('Settings saved! (Connect to DB to persist)', 'info', 'Settings')">
      <i class="fa-solid fa-floppy-disk"></i> Save Settings
    </button>
  </div>
</div>

<div class="form-card">
  <h3>System Config</h3>
  <div class="form-grid">
    <div class="form-field full">
      <label>Database</label>
      <input type="text" value="sms_db @ localhost" readonly/>
    </div>
    <div class="form-field">
      <label>PHP Version</label>
      <input type="text" value="<?= phpversion() ?>" readonly/>
    </div>
    <div class="form-field">
      <label>Server</label>
      <input type="text" value="<?= $_SERVER['SERVER_SOFTWARE'] ?? 'Apache/XAMPP' ?>" readonly/>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';

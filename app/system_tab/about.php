<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'about';
$PAGE_TITLE = 'About';
$PAGE_ICON  = 'fa-solid fa-circle-info';

// Count tables
$table_count = (int)$conn->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema='sms_db'")->fetch_assoc()['c'];
$student_count = (int)$conn->query("SELECT COUNT(*) c FROM students")->fetch_assoc()['c'];
$user_count    = (int)$conn->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];

ob_start();
?>
<div class="form-card">
  <h3>BCP Student Management System</h3>
  <div class="avatar-row" style="margin:16px 0;">
    <img src="../images/BCP_LOGO.png" style="width:72px;height:auto;object-fit:contain;" alt="BCP Logo"/>
    <div>
      <div style="font-size:1rem;font-weight:700;color:#1a1a2e;">Bestlink College of the Philippines</div>
      <div style="font-size:0.8rem;color:#888;margin-top:2px;">Student Enrollment & Management Portal</div>
      <div style="font-size:0.75rem;color:#aaa;margin-top:4px;">Version 1.0.0 &nbsp;·&nbsp; A.Y. 2025–2026</div>
    </div>
  </div>
  <p style="font-size:0.82rem;color:#555;line-height:1.7;border-top:1px solid #f0f2f5;padding-top:14px;">
    This system provides a complete student information and enrollment management platform
    for BCP. It covers online pre-registration, document uploads, enrollment validation,
    ID generation, grade level and section assignment, and comprehensive reporting.
  </p>
</div>

<div class="tables-row">
  <div class="table-card">
    <h3>System Info</h3>
    <table class="data-table">
      <tbody>
        <tr><td style="color:#888;">PHP Version</td><td><?= phpversion() ?></td></tr>
        <tr><td style="color:#888;">Database</td><td>sms_db (MySQL)</td></tr>
        <tr><td style="color:#888;">DB Tables</td><td><?= $table_count ?></td></tr>
        <tr><td style="color:#888;">Web Server</td><td><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Apache') ?></td></tr>
        <tr><td style="color:#888;">Environment</td><td>XAMPP / localhost</td></tr>
      </tbody>
    </table>
  </div>

  <div class="table-card">
    <h3>Data Summary</h3>
    <table class="data-table">
      <tbody>
        <tr><td style="color:#888;">Total Students</td><td><strong><?= $student_count ?></strong></td></tr>
        <tr><td style="color:#888;">Registered Users</td><td><strong><?= $user_count ?></strong></td></tr>
        <tr><td style="color:#888;">School Year</td><td>2025–2026</td></tr>
        <tr><td style="color:#888;">Current Semester</td><td>1st Semester</td></tr>
        <tr><td style="color:#888;">Enrollment</td><td><span class="badge-active">Open</span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="form-card">
  <h3>Quick Links</h3>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;">
    <a href="../dashboard/dashboard.php" style="display:inline-flex;align-items:center;gap:8px;background:#eff6ff;border-radius:8px;padding:12px 18px;text-decoration:none;color:#2563eb;font-size:0.82rem;font-weight:600;">
      <i class="fa-solid fa-gauge"></i> Dashboard
    </a>
    <a href="../enrollment_tab/setup_enrollment.php" style="display:inline-flex;align-items:center;gap:8px;background:#f0fdf4;border-radius:8px;padding:12px 18px;text-decoration:none;color:#16a34a;font-size:0.82rem;font-weight:600;">
      <i class="fa-solid fa-database"></i> DB Setup
    </a>
    <a href="../shared/setup.php" style="display:inline-flex;align-items:center;gap:8px;background:#fff7ed;border-radius:8px;padding:12px 18px;text-decoration:none;color:#d97706;font-size:0.82rem;font-weight:600;">
      <i class="fa-solid fa-wrench"></i> Base Setup
    </a>
  </div>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';

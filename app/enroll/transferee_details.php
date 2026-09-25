<?php
session_start();
$type = $_GET['type'] ?? $_SESSION['enroll']['applicant_type'] ?? '';
if ($type !== 'Transferee') { header('Location: applicant_type.php'); exit; }
$_SESSION['enroll']['applicant_type'] = 'Transferee';

$current_step = 3;   // visually occupies step 3 in the bar (branch is 3 for others)
include __DIR__ . '/header.php';

$year_levels = ['1st Year','2nd Year','3rd Year','4th Year'];
$courses = [
    'Bachelor of Science in Information Technology',
    'Bachelor of Science in Computer Science',
    'Bachelor of Science in Information Systems',
    'Bachelor of Science in Business Administration',
    'Bachelor of Science in Accountancy',
    'Bachelor of Science in Criminology',
    'Bachelor of Science in Education',
    'Bachelor of Science in Nursing',
];
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Transferee Details</h2>
    <p style="text-align:center;font-size:.85rem;color:#666;margin-bottom:24px;">
      Tell us where you're transferring from and which year level you're entering.
    </p>

    <!-- Transferee badge -->
    <div style="display:inline-flex;align-items:center;gap:8px;background:#fff7ed;
                border:1.5px solid #fcd34d;border-radius:8px;padding:8px 14px;
                margin-bottom:22px;font-size:.82rem;font-weight:700;color:#d97706;">
      <i class="fa-solid fa-right-left"></i> Enrolling as: Transferee
    </div>

    <form id="transfereeForm" action="set_transferee.php" method="POST">

      <div class="enroll-form-grid">
        <div class="enroll-field">
          <label>Year Level you are entering <span class="req">*</span></label>
          <select name="transfer_year_level" required>
            <option value="">Select year level…</option>
            <?php foreach ($year_levels as $y): ?>
            <option value="<?= $y ?>"><?= $y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="enroll-field">
          <label>Course you are transferring into <span class="req">*</span></label>
          <select name="transfer_course" required>
            <option value="">Select course…</option>
            <?php foreach ($courses as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="enroll-field full">
          <label>Name of Previous School <span class="req">*</span></label>
          <input type="text" name="transfer_from_school"
                 placeholder="e.g. University of Santo Tomas" required
                 value="<?= htmlspecialchars($_SESSION['enroll']['transfer_from_school'] ?? '') ?>"/>
        </div>
      </div>

      <div class="enroll-actions">
        <a href="applicant_type.php" class="btn-back">
          <i class="fa-solid fa-arrow-left"></i> Back
        </a>
        <button type="submit" class="btn-proceed">
          Continue <i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>

    </form>
  </div>
</div>

<script>
document.getElementById('transfereeForm').addEventListener('submit', function(e) {
    var ok = true;
    this.querySelectorAll('[required]').forEach(function(el) {
        if (!el.value.trim()) { el.style.borderColor = '#ef4444'; ok = false; }
        else el.style.borderColor = '';
    });
    if (!ok) {
        e.preventDefault();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
});
</script>
</body>
</html>

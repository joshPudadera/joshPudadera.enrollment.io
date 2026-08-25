<?php
session_start();
$course = $_GET['course'] ?? $_SESSION['enroll']['course'] ?? '';
if (!$course) { header('Location: course.php'); exit; }
$_SESSION['enroll']['course'] = $course;
$branch = $_SESSION['enroll']['branch'] ?? 'N/A';

$current_step = 4;
include __DIR__ . '/header.php';
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Personal Information</h2>
    <p style="text-align:center; font-size:.82rem; color:#666; margin-bottom:4px;">
      Campus: <strong><?= htmlspecialchars($branch) ?></strong> &nbsp;·&nbsp;
      Program: <strong><?= htmlspecialchars($course) ?></strong>
    </p>
    <p style="text-align:center; font-size:.78rem; color:#aaa; margin-bottom:24px;">
      Fields marked <span style="color:#ef4444;">*</span> are required.
    </p>

    <div id="formAlert" style="display:none; margin-bottom:14px;" class="auth-error"></div>

    <form id="enrollForm" action="review.php" method="POST">
      <input type="hidden" name="branch" value="<?= htmlspecialchars($branch) ?>"/>
      <input type="hidden" name="course" value="<?= htmlspecialchars($course) ?>"/>

      <!-- Name -->
      <p class="enroll-section-heading">Full Name</p>
      <div class="enroll-form-grid">
        <div class="enroll-field">
          <label>Last Name <span class="req">*</span></label>
          <input type="text" name="last_name" placeholder="Dela Cruz" required/>
        </div>
        <div class="enroll-field">
          <label>First Name <span class="req">*</span></label>
          <input type="text" name="first_name" placeholder="Juan" required/>
        </div>
        <div class="enroll-field">
          <label>Middle Name</label>
          <input type="text" name="middle_name" placeholder="Santos"/>
        </div>
        <div class="enroll-field">
          <label>Suffix</label>
          <select name="suffix">
            <option value="">None</option>
            <option>Jr.</option><option>Sr.</option>
            <option>II</option><option>III</option><option>IV</option>
          </select>
        </div>
      </div>

      <!-- Personal details -->
      <p class="enroll-section-heading" style="margin-top:22px;">Personal Details</p>
      <div class="enroll-form-grid">
        <div class="enroll-field">
          <label>Date of Birth <span class="req">*</span></label>
          <input type="date" name="birthday" required/>
        </div>
        <div class="enroll-field">
          <label>Sex <span class="req">*</span></label>
          <select name="sex" required>
            <option value="">Select…</option>
            <option>Male</option>
            <option>Female</option>
          </select>
        </div>
        <div class="enroll-field">
          <label>Civil Status</label>
          <select name="civil_status">
            <option>Single</option><option>Married</option>
            <option>Widowed</option><option>Separated</option>
          </select>
        </div>
        <div class="enroll-field">
          <label>Nationality</label>
          <input type="text" name="nationality" value="Filipino"/>
        </div>
        <div class="enroll-field">
          <label>Religion</label>
          <input type="text" name="religion" placeholder="e.g. Roman Catholic"/>
        </div>
        <div class="enroll-field">
          <label>Place of Birth</label>
          <input type="text" name="place_of_birth" placeholder="City / Municipality"/>
        </div>
      </div>

      <!-- Contact -->
      <p class="enroll-section-heading" style="margin-top:22px;">Contact Information</p>
      <div class="enroll-form-grid">
        <div class="enroll-field">
          <label>Email Address <span class="req">*</span></label>
          <input type="email" name="email" placeholder="juan@email.com" required/>
        </div>
        <div class="enroll-field">
          <label>Mobile Number <span class="req">*</span></label>
          <input type="text" name="phone" placeholder="09XXXXXXXXX" required/>
        </div>
        <div class="enroll-field full">
          <label>Home Address <span class="req">*</span></label>
          <input type="text" name="address" placeholder="House No., Street, Barangay, City" required/>
        </div>
      </div>

      <!-- Previous school -->
      <p class="enroll-section-heading" style="margin-top:22px;">Previous School</p>
      <div class="enroll-form-grid">
        <div class="enroll-field full">
          <label>Name of Previous School</label>
          <input type="text" name="prev_school" placeholder="School name"/>
        </div>
        <div class="enroll-field">
          <label>Last Year Level Completed</label>
          <input type="text" name="last_year_level" placeholder="e.g. Grade 12 / 3rd Year College"/>
        </div>
        <div class="enroll-field">
          <label>School Year Graduated</label>
          <input type="text" name="grad_year" placeholder="e.g. 2024–2025"/>
        </div>
      </div>

      <!-- Emergency contact -->
      <p class="enroll-section-heading" style="margin-top:22px;">Emergency Contact</p>
      <div class="enroll-form-grid">
        <div class="enroll-field">
          <label>Contact Person <span class="req">*</span></label>
          <input type="text" name="emergency_name" placeholder="Full name" required/>
        </div>
        <div class="enroll-field">
          <label>Relationship</label>
          <input type="text" name="emergency_relation" placeholder="e.g. Mother, Father"/>
        </div>
        <div class="enroll-field">
          <label>Contact Number <span class="req">*</span></label>
          <input type="text" name="emergency_phone" placeholder="09XXXXXXXXX" required/>
        </div>
      </div>

      <div class="enroll-actions">
        <a href="course.php" class="btn-back">
          <i class="fa-solid fa-arrow-left"></i> Back
        </a>
        <button type="submit" class="btn-proceed">
          Review Application <i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>

    </form>
  </div>
</div>

<script>
document.getElementById('enrollForm').addEventListener('submit', function(e) {
    const required = this.querySelectorAll('[required]');
    let valid = true;
    required.forEach(el => {
        if (!el.value.trim()) { el.style.borderColor = '#ef4444'; valid = false; }
        else el.style.borderColor = '';
    });
    if (!valid) {
        e.preventDefault();
        const alert = document.getElementById('formAlert');
        alert.textContent = 'Please fill in all required fields.';
        alert.style.display = 'block';
        alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
</body>
</html>

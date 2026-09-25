<?php
session_start();
$course = $_GET['course'] ?? $_SESSION['enroll']['course'] ?? '';
if (!$course) { header('Location: course.php'); exit; }
$_SESSION['enroll']['course'] = $course;
$branch         = $_SESSION['enroll']['branch']              ?? 'N/A';
$applicant_type = $_SESSION['enroll']['applicant_type']      ?? 'Freshman';
$transfer_yr    = $_SESSION['enroll']['transfer_year_level'] ?? '';

$current_step = 5;
include __DIR__ . '/header.php';
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Personal Information</h2>
    <p style="text-align:center; font-size:.82rem; color:#666; margin-bottom:4px;">
      Campus: <strong><?= htmlspecialchars($branch) ?></strong> &nbsp;·&nbsp;
      Program: <strong><?= htmlspecialchars($course) ?></strong>
    </p>
    <!-- Applicant type badge -->
    <?php
    $type_styles = [
        'Freshman'    => ['#dcfce7','#16a34a','fa-graduation-cap'],
        'Senior High' => ['#eff6ff','#2563eb','fa-book-open'],
        'Octoberian'  => ['#faf5ff','#7c3aed','fa-calendar-day'],
        'Transferee'  => ['#fff7ed','#d97706','fa-right-left'],
    ];
    [$tbg,$tclr,$tico] = $type_styles[$applicant_type] ?? ['#f3f4f6','#6b7280','fa-user'];
    ?>
    <div style="display:inline-flex;align-items:center;gap:8px;background:<?= $tbg ?>;
                border:1.5px solid <?= $tclr ?>30;border-radius:8px;padding:7px 14px;
                margin:6px 0 20px;font-size:.82rem;font-weight:700;color:<?= $tclr ?>;">
      <i class="fa-solid <?= $tico ?>"></i>
      Enrolling as: <?= htmlspecialchars($applicant_type) ?>
      <?php if ($applicant_type === 'Transferee' && $transfer_yr): ?>
      &nbsp;·&nbsp; Entering: <strong><?= htmlspecialchars($transfer_yr) ?></strong>
      <?php endif; ?>
    </div>
    <p style="text-align:center; font-size:.78rem; color:#aaa; margin-bottom:24px;">
      Fields marked <span style="color:#ef4444;">*</span> are required.
    </p>

    <div id="formAlert" style="display:none; margin-bottom:14px;" class="auth-error"></div>

    <form id="enrollForm" action="review.php" method="POST">
      <input type="hidden" name="branch"               value="<?= htmlspecialchars($branch) ?>"/>
      <input type="hidden" name="course"               value="<?= htmlspecialchars($course) ?>"/>
      <input type="hidden" name="applicant_type"       value="<?= htmlspecialchars($applicant_type) ?>"/>
      <input type="hidden" name="transfer_year_level"  value="<?= htmlspecialchars($transfer_yr) ?>"/>

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

        <!-- ── Date of Birth — custom 3-part picker ── -->
        <div class="enroll-field" style="grid-column:1/-1;">
          <label>Date of Birth <span class="req">*</span></label>

          <!-- Hidden field that gets the assembled YYYY-MM-DD value -->
          <input type="hidden" name="birthday" id="birthdayValue" required/>

          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:2px;">

            <!-- Month -->
            <div style="position:relative;">
              <select id="dobMonth"
                      style="width:100%;height:46px;padding:0 38px 0 14px;
                             border:1.5px solid #d0d7e2;border-radius:10px;
                             font-size:.88rem;font-family:inherit;color:#1a1a2e;
                             background:#fff;appearance:none;-webkit-appearance:none;
                             cursor:pointer;outline:none;transition:border-color .2s,box-shadow .2s;">
                <option value="">Month</option>
                <?php
                $months = ['January','February','March','April','May','June',
                           'July','August','September','October','November','December'];
                foreach ($months as $i => $m): ?>
                <option value="<?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?>"><?= $m ?></option>
                <?php endforeach; ?>
              </select>
              <i class="fa-solid fa-chevron-down"
                 style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                        color:#94a3b8;font-size:.72rem;pointer-events:none;"></i>
            </div>

            <!-- Day -->
            <div style="position:relative;">
              <select id="dobDay"
                      style="width:100%;height:46px;padding:0 38px 0 14px;
                             border:1.5px solid #d0d7e2;border-radius:10px;
                             font-size:.88rem;font-family:inherit;color:#1a1a2e;
                             background:#fff;appearance:none;-webkit-appearance:none;
                             cursor:pointer;outline:none;transition:border-color .2s,box-shadow .2s;">
                <option value="">Day</option>
                <?php for ($d = 1; $d <= 31; $d++): ?>
                <option value="<?= str_pad($d,2,'0',STR_PAD_LEFT) ?>"><?= $d ?></option>
                <?php endfor; ?>
              </select>
              <i class="fa-solid fa-chevron-down"
                 style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                        color:#94a3b8;font-size:.72rem;pointer-events:none;"></i>
            </div>

            <!-- Year — starts at current year minus 15 -->
            <div style="position:relative;">
              <select id="dobYear"
                      style="width:100%;height:46px;padding:0 38px 0 14px;
                             border:1.5px solid #d0d7e2;border-radius:10px;
                             font-size:.88rem;font-family:inherit;color:#1a1a2e;
                             background:#fff;appearance:none;-webkit-appearance:none;
                             cursor:pointer;outline:none;transition:border-color .2s,box-shadow .2s;">
                <option value="">Year</option>
                <?php
                $maxYear = (int)date('Y') - 15;   // oldest allowed birth year to show first
                $minYear = $maxYear - 65;          // reasonable range: 15–80 years old
                for ($y = $maxYear; $y >= $minYear; $y--): ?>
                <option value="<?= $y ?>"><?= $y ?></option>
                <?php endfor; ?>
              </select>
              <i class="fa-solid fa-chevron-down"
                 style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                        color:#94a3b8;font-size:.72rem;pointer-events:none;"></i>
            </div>

          </div>

          <!-- Live preview of selected date -->
          <div id="dobPreview"
               style="margin-top:8px;font-size:.78rem;color:#64748b;min-height:18px;
                      display:flex;align-items:center;gap:6px;">
          </div>
          <span class="field-error" id="dobError"
                style="font-size:.72rem;color:#ef4444;display:none;margin-top:4px;"></span>
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
          <label>Name of Previous School<?= $applicant_type === 'Transferee' ? ' <span class="req">*</span>' : '' ?></label>
          <input type="text" name="prev_school" placeholder="School name"
                 value="<?= htmlspecialchars($_SESSION['enroll']['transfer_from_school'] ?? '') ?>"
                 <?= $applicant_type === 'Transferee' ? 'required' : '' ?>/>
        </div>
        <div class="enroll-field">
          <label>Last Year Level Completed</label>
          <input type="text" name="last_year_level"
                 placeholder="e.g. Grade 12 / 3rd Year College"
                 value="<?= htmlspecialchars($transfer_yr ?: '') ?>"/>
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
(function () {
  // ── Date of Birth picker ─────────────────────────────────────
  var mSel    = document.getElementById('dobMonth');
  var dSel    = document.getElementById('dobDay');
  var ySel    = document.getElementById('dobYear');
  var hidden  = document.getElementById('birthdayValue');
  var preview = document.getElementById('dobPreview');
  var dobErr  = document.getElementById('dobError');

  var MONTH_NAMES = ['January','February','March','April','May','June',
                     'July','August','September','October','November','December'];

  // Focus ring style
  var selectEls = [mSel, dSel, ySel];
  selectEls.forEach(function(sel) {
    sel.addEventListener('focus', function() {
      this.style.borderColor = '#2563eb';
      this.style.boxShadow   = '0 0 0 3px rgba(37,99,235,.12)';
    });
    sel.addEventListener('blur', function() {
      if (!this.classList.contains('sel-error')) {
        this.style.borderColor = '#d0d7e2';
        this.style.boxShadow   = '';
      }
    });
  });

  function updateDays() {
    var m = parseInt(mSel.value, 10);
    var y = parseInt(ySel.value, 10) || new Date().getFullYear();
    var max = 31;
    if (m) {
      // Days in the selected month
      max = new Date(y, m, 0).getDate();
    }
    var current = dSel.value;
    // Rebuild day options
    while (dSel.options.length > 1) dSel.remove(1);
    for (var i = 1; i <= max; i++) {
      var opt   = document.createElement('option');
      opt.value = i < 10 ? '0' + i : '' + i;
      opt.text  = '' + i;
      dSel.appendChild(opt);
    }
    // Restore previous selection if still valid
    if (current && parseInt(current, 10) <= max) dSel.value = current;
  }

  function assembleDate() {
    var m = mSel.value;
    var d = dSel.value;
    var y = ySel.value;

    if (m && d && y) {
      var iso  = y + '-' + m + '-' + d;
      hidden.value = iso;

      // Live preview
      var monthName = MONTH_NAMES[parseInt(m, 10) - 1];
      preview.innerHTML =
        '<i class="fa-solid fa-calendar-check" style="color:#16a34a;"></i> '
        + '<span style="color:#16a34a;font-weight:600;">'
        + monthName + ' ' + parseInt(d, 10) + ', ' + y
        + '</span>';

      // Age check — must be at least 15
      var today   = new Date();
      var dob     = new Date(parseInt(y,10), parseInt(m,10)-1, parseInt(d,10));
      var ageDiff = today - dob;
      var age     = Math.floor(ageDiff / (365.25 * 24 * 3600 * 1000));
      if (age < 15) {
        preview.innerHTML =
          '<i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i> '
          + '<span style="color:#ef4444;">Applicant must be at least 15 years old.</span>';
        hidden.value = '';   // invalidate
      }

      // Clear error styling
      [mSel, dSel, ySel].forEach(function(s) {
        s.classList.remove('sel-error');
        s.style.borderColor = '#d0d7e2';
        s.style.boxShadow   = '';
      });
      dobErr.style.display = 'none';
    } else {
      hidden.value   = '';
      preview.innerHTML = '';
    }
  }

  mSel.addEventListener('change', function() { updateDays(); assembleDate(); });
  dSel.addEventListener('change', assembleDate);
  ySel.addEventListener('change', function() { updateDays(); assembleDate(); });

  // ── Form validation ────────────────────────────────────────────
  document.getElementById('enrollForm').addEventListener('submit', function(e) {
    var alertBox = document.getElementById('formAlert');
    var valid    = true;

    // Validate all [required] fields except the hidden birthday
    this.querySelectorAll('[required]').forEach(function(el) {
      if (el.id === 'birthdayValue') return;   // handled separately below
      if (!el.value.trim()) {
        el.style.borderColor = '#ef4444';
        valid = false;
      } else {
        el.style.borderColor = '';
      }
    });

    // Validate DOB
    if (!hidden.value) {
      [mSel, dSel, ySel].forEach(function(s) {
        if (!s.value) {
          s.style.borderColor = '#ef4444';
          s.style.boxShadow   = '0 0 0 3px rgba(239,68,68,.12)';
          s.classList.add('sel-error');
        }
      });
      dobErr.textContent   = 'Please select a complete and valid date of birth.';
      dobErr.style.display = 'block';
      valid = false;
    }

    if (!valid) {
      e.preventDefault();
      alertBox.textContent   = 'Please fill in all required fields.';
      alertBox.style.display = 'block';
      alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
}());
</script>
</body>
</html>

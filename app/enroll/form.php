<?php
session_start();
$course = $_GET['course'] ?? $_SESSION['enroll']['course'] ?? '';
if (!$course) { header('Location: course.php'); exit; }
$_SESSION['enroll']['course'] = $course;
$branch         = $_SESSION['enroll']['branch']         ?? 'N/A';
$applicant_type = $_SESSION['enroll']['applicant_type'] ?? 'Freshman';

$current_step = 5;
include __DIR__ . '/header.php';
?>
<style>
.tab-btns { display:flex; gap:10px; margin-bottom:28px; flex-wrap:wrap; }
.tab-btn {
  flex:1; min-width:120px; padding:11px 10px;
  border:2px solid #d0d7e2; border-radius:9px;
  background:#fff; color:#444; font-size:.85rem; font-weight:600;
  cursor:pointer; font-family:inherit; transition:border-color .15s,background .15s,color .15s;
  text-align:center;
}
.tab-btn.active { border-color:#1a3a8c; background:#eff6ff; color:#1a3a8c; }
.tab-pane { display:none; }
.tab-pane.active { display:block; }
.section-block {
  border:1.5px solid #e8edf4; border-radius:10px;
  padding:20px 22px; margin-bottom:22px;
}
.section-block-title {
  font-size:.82rem; font-weight:700; color:#1a3a8c;
  text-transform:uppercase; letter-spacing:.04em;
  margin-bottom:16px; padding-bottom:8px;
  border-bottom:1.5px solid #e8edf4;
}
.inline-field-err {
  font-size:.72rem; color:#ef4444; margin-top:3px; display:block; line-height:1.35;
}
.tab-err-box {
  background:#fff1f2; border:1.5px solid #fca5a5; border-radius:10px;
  padding:14px 18px; margin-bottom:20px; font-size:.85rem; color:#991b1b;
}
.tab-err-box strong { display:block; margin-bottom:8px; font-size:.88rem; }
.tab-err-box ul { margin:0 0 0 18px; padding:0; line-height:1.9; }
</style>

<div class="enroll-body">
<div class="enroll-card">

  <h2 class="enroll-card-title">Student Information</h2>
  <p style="text-align:center;font-size:.82rem;color:#666;margin-bottom:4px;">
    Campus: <strong><?= htmlspecialchars($branch) ?></strong>
    &nbsp;·&nbsp; Program: <strong><?= htmlspecialchars($course) ?></strong>
  </p>
  <p style="text-align:center;font-size:.75rem;color:#aaa;margin-bottom:22px;">
    Fields marked <span style="color:#ef4444;">*</span> are required.
  </p>

  <div id="formAlert" style="display:none;margin-bottom:14px;font-size:.85rem;" class="auth-error"></div>

  <form id="enrollForm" action="review.php" method="POST">
    <input type="hidden" name="branch"         value="<?= htmlspecialchars($branch) ?>"/>
    <input type="hidden" name="course"         value="<?= htmlspecialchars($course) ?>"/>
    <input type="hidden" name="applicant_type" value="<?= htmlspecialchars($applicant_type) ?>"/>

    <!-- ══ TAB NAVIGATION ══ -->
    <div class="tab-btns" role="tablist">
      <button type="button" class="tab-btn active" onclick="switchTab('student',this)">1. Student Info</button>
      <button type="button" class="tab-btn"        onclick="switchTab('parent',this)">2. Parent / Guardian</button>
      <button type="button" class="tab-btn"        onclick="switchTab('school',this)">3. School Background</button>
    </div>

    <!-- ══ TAB 1: STUDENT INFORMATION ══ -->
    <div id="tab-student" class="tab-pane active">
      <div id="err-student" class="tab-err-box" style="display:none;"></div>

      <!-- Student type selector -->
      <div class="section-block">
        <div class="section-block-title">Student Category</div>
        <p style="font-size:.8rem;color:#555;margin-bottom:14px;">
          Select the category that applies to you for this enrollment.
        </p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <?php
          $stypes = [
              ['New Regular', '#16a34a', '#dcfce7'],
              ['Transferee',  '#d97706', '#fff7ed'],
              ['Returnee',    '#7c3aed', '#faf5ff'],
          ];
          foreach ($stypes as [$sl, $sc, $sbg]):
          ?>
          <label style="flex:1;min-width:120px;cursor:pointer;">
            <input type="radio" name="student_type" value="<?= $sl ?>"
                   <?= $sl==='New Regular'?'checked':'' ?>
                   class="stype-radio" style="display:none;"
                   onchange="highlightStype(this)"/>
            <div class="stype-card" style="border:2px solid #e5e7eb;border-radius:9px;
                 padding:12px 10px;text-align:center;font-size:.82rem;font-weight:700;
                 color:#444;transition:border-color .15s,background .15s,color .15s;"
                 data-color="<?= $sc ?>" data-bg="<?= $sbg ?>">
              <?= $sl ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Full Name -->
      <div class="section-block">
        <div class="section-block-title">Full Name</div>
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
            <input type="text" name="middle_name" placeholder="Santos (optional)"/>
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
      </div>

      <!-- Personal Details -->
      <div class="section-block">
        <div class="section-block-title">Personal Details</div>
        <div class="enroll-form-grid">

          <!-- Date of Birth -->
          <div class="enroll-field" style="grid-column:1/-1;">
            <label>Date of Birth <span class="req">*</span></label>
            <input type="hidden" name="birthday" id="birthdayValue" required/>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:2px;">
              <div style="position:relative;">
                <select id="dobMonth" style="width:100%;height:42px;padding:0 36px 0 12px;border:1.5px solid #d0d7e2;border-radius:8px;font-size:.88rem;font-family:inherit;background:#fff;appearance:none;-webkit-appearance:none;outline:none;">
                  <option value="">Month</option>
                  <?php $months=['January','February','March','April','May','June','July','August','September','October','November','December'];
                  foreach($months as $i=>$m): ?>
                  <option value="<?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?>"><?= $m ?></option>
                  <?php endforeach; ?>
                </select>
                <i class="fa-solid fa-chevron-down" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.7rem;pointer-events:none;"></i>
              </div>
              <div style="position:relative;">
                <select id="dobDay" style="width:100%;height:42px;padding:0 36px 0 12px;border:1.5px solid #d0d7e2;border-radius:8px;font-size:.88rem;font-family:inherit;background:#fff;appearance:none;-webkit-appearance:none;outline:none;">
                  <option value="">Day</option>
                  <?php for($d=1;$d<=31;$d++): ?>
                  <option value="<?= str_pad($d,2,'0',STR_PAD_LEFT) ?>"><?= $d ?></option>
                  <?php endfor; ?>
                </select>
                <i class="fa-solid fa-chevron-down" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.7rem;pointer-events:none;"></i>
              </div>
              <div style="position:relative;">
                <select id="dobYear" style="width:100%;height:42px;padding:0 36px 0 12px;border:1.5px solid #d0d7e2;border-radius:8px;font-size:.88rem;font-family:inherit;background:#fff;appearance:none;-webkit-appearance:none;outline:none;">
                  <option value="">Year</option>
                  <?php $maxY=(int)date('Y')-15; for($y=$maxY;$y>=$maxY-65;$y--): ?>
                  <option value="<?= $y ?>"><?= $y ?></option>
                  <?php endfor; ?>
                </select>
                <i class="fa-solid fa-chevron-down" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.7rem;pointer-events:none;"></i>
              </div>
            </div>
            <div id="dobPreview" style="margin-top:6px;font-size:.78rem;color:#64748b;min-height:18px;"></div>
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
            <label>Civil Status <span class="req">*</span></label>
            <select name="civil_status" required>
              <option value="">Select…</option>
              <option>Single</option><option>Married</option>
              <option>Widowed</option><option>Separated</option>
            </select>
          </div>
          <div class="enroll-field">
            <label>Nationality <span class="req">*</span></label>
            <input type="text" name="nationality" value="Filipino" required/>
          </div>
          <div class="enroll-field">
            <label>Religion <span class="req">*</span></label>
            <input type="text" name="religion" placeholder="e.g. Roman Catholic" required/>
          </div>
          <div class="enroll-field">
            <label>Place of Birth <span class="req">*</span></label>
            <input type="text" name="place_of_birth" placeholder="City / Municipality" required/>
          </div>
        </div>
      </div>

      <!-- Contact -->
      <div class="section-block">
        <div class="section-block-title">Contact Information</div>
        <div class="enroll-form-grid">
          <div class="enroll-field">
            <label>Email Address <span class="req">*</span></label>
            <input type="email" name="email" placeholder="juan@email.com" required/>
          </div>
          <div class="enroll-field">
            <label>Mobile Number <span class="req">*</span></label>
            <input type="text" name="phone" placeholder="09XXXXXXXXX" required
                   maxlength="11" minlength="11" pattern="\d{11}"
                   title="Must be exactly 11 digits"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)"/>
          </div>
          <div class="enroll-field full">
            <label>Home Address <span class="req">*</span></label>
            <input type="text" name="address" placeholder="House No., Street, Barangay, City" required/>
          </div>
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;">
        <button type="button" class="btn-proceed" onclick="nextToParent()">
          Next: Parent Info →
        </button>
      </div>
    </div><!-- end tab-student -->

    <!-- ══ TAB 2: PARENT / GUARDIAN INFORMATION ══ -->
    <div id="tab-parent" class="tab-pane">
      <div id="err-parent" class="tab-err-box" style="display:none;"></div>

      <div class="section-block">
        <div class="section-block-title">Father's Information</div>
        <div class="enroll-form-grid">
          <div class="enroll-field">
            <label>Father's Last Name <span class="req">*</span></label>
            <input type="text" name="father_last_name" placeholder="Dela Cruz" required/>
          </div>
          <div class="enroll-field">
            <label>Father's First Name <span class="req">*</span></label>
            <input type="text" name="father_first_name" placeholder="Jose" required/>
          </div>
          <div class="enroll-field">
            <label>Father's Middle Name</label>
            <input type="text" name="father_middle_name" placeholder="Optional"/>
          </div>
          <div class="enroll-field">
            <label>Occupation <span class="req">*</span></label>
            <input type="text" name="father_occupation" placeholder="e.g. Engineer" required/>
          </div>
          <div class="enroll-field">
            <label>Contact Number <span class="req">*</span></label>
            <input type="text" name="father_phone" placeholder="09XXXXXXXXX" required
                   maxlength="11" minlength="11" pattern="\d{11}"
                   title="Must be exactly 11 digits"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)"/>
          </div>
        </div>
      </div>

      <div class="section-block">
        <div class="section-block-title">Mother's Information</div>
        <div class="enroll-form-grid">
          <div class="enroll-field">
            <label>Mother's Last Name <span class="req">*</span></label>
            <input type="text" name="mother_last_name" placeholder="Santos" required/>
          </div>
          <div class="enroll-field">
            <label>Mother's First Name <span class="req">*</span></label>
            <input type="text" name="mother_first_name" placeholder="Maria" required/>
          </div>
          <div class="enroll-field">
            <label>Mother's Middle Name</label>
            <input type="text" name="mother_middle_name" placeholder="Optional"/>
          </div>
          <div class="enroll-field">
            <label>Occupation <span class="req">*</span></label>
            <input type="text" name="mother_occupation" placeholder="e.g. Teacher" required/>
          </div>
          <div class="enroll-field">
            <label>Contact Number <span class="req">*</span></label>
            <input type="text" name="mother_phone" placeholder="09XXXXXXXXX" required
                   maxlength="11" minlength="11" pattern="\d{11}"
                   title="Must be exactly 11 digits"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)"/>
          </div>
        </div>
      </div>

      <div class="section-block">
        <div class="section-block-title">Guardian (if different from parents)</div>
        <div class="enroll-form-grid">
          <div class="enroll-field">
            <label>Guardian's Full Name</label>
            <input type="text" name="guardian_name" placeholder="Optional"/>
          </div>
          <div class="enroll-field">
            <label>Relationship</label>
            <input type="text" name="guardian_relation" placeholder="e.g. Aunt, Uncle"/>
          </div>
          <div class="enroll-field">
            <label>Contact Number</label>
            <input type="text" name="guardian_phone" placeholder="09XXXXXXXXX"
                   maxlength="11" minlength="11" pattern="\d{11}"
                   title="Must be exactly 11 digits"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)"/>
          </div>
        </div>
      </div>

      <div style="display:flex;justify-content:space-between;">
        <button type="button" class="btn-back" onclick="goToTab('student')">← Back</button>
        <button type="button" class="btn-proceed" onclick="nextToSchool()">Next: School Background →</button>
      </div>
    </div><!-- end tab-parent -->

    <!-- ══ TAB 3: SCHOOL BACKGROUND ══ -->
    <div id="tab-school" class="tab-pane">
      <div id="err-school" class="tab-err-box" style="display:none;"></div>

      <div class="section-block">
        <div class="section-block-title">Previous School</div>
        <div class="enroll-form-grid">
          <div class="enroll-field full">
            <label>Name of Previous School <span class="req">*</span></label>
            <input type="text" name="prev_school" placeholder="School name" required/>
          </div>
          <div class="enroll-field">
            <label>School Address <span class="req">*</span></label>
            <input type="text" name="prev_school_address" placeholder="City / Province" required/>
          </div>
          <div class="enroll-field">
            <label>Last Year Level Completed <span class="req">*</span></label>
            <input type="text" name="last_year_level" placeholder="e.g. Grade 12 / 3rd Year" required/>
          </div>
          <div class="enroll-field">
            <label>School Year Graduated <span class="req">*</span></label>
            <input type="text" name="grad_year" placeholder="e.g. 2024–2025" required/>
          </div>
          <div class="enroll-field">
            <label>General Average / GPA <span class="req">*</span></label>
            <input type="text" name="general_average" placeholder="e.g. 88.5" required/>
          </div>
          <div class="enroll-field">
            <label>Honors / Awards Received</label>
            <input type="text" name="honors" placeholder="e.g. With Honors (optional)"/>
          </div>
        </div>
      </div>

      <div class="section-block">
        <div class="section-block-title">Emergency Contact</div>
        <div class="enroll-form-grid">
          <div class="enroll-field">
            <label>Contact Person <span class="req">*</span></label>
            <input type="text" name="emergency_name" placeholder="Full name" required/>
          </div>
          <div class="enroll-field">
            <label>Relationship <span class="req">*</span></label>
            <input type="text" name="emergency_relation" placeholder="e.g. Mother" required/>
          </div>
          <div class="enroll-field">
            <label>Contact Number <span class="req">*</span></label>
            <input type="text" name="emergency_phone" placeholder="09XXXXXXXXX" required
                   maxlength="11" minlength="11" pattern="\d{11}"
                   title="Must be exactly 11 digits"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)"/>
          </div>
        </div>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;">
        <button type="button" class="btn-back" onclick="goToTab('parent')">← Back</button>
        <button type="submit" class="btn-proceed">Review Application →</button>
      </div>
    </div><!-- end tab-school -->

  </form>
</div><!-- end enroll-card -->
</div><!-- end enroll-body -->

<script>
(function () {
  // ── Tab switching ─────────────────────────────────────────
  function switchTab(id, btn) {
    document.querySelectorAll('.tab-pane').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('tab-' + id).classList.add('active');
    if (btn) btn.classList.add('active');
    else {
      var btns = document.querySelectorAll('.tab-btn');
      var map  = { student:0, parent:1, school:2 };
      if (map[id] !== undefined) btns[map[id]].classList.add('active');
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  window.switchTab = switchTab;

  function goToTab(id) {
    switchTab(id, null);
  }
  window.goToTab = goToTab;

  // ── Student type highlight ────────────────────────────────
  function highlightStype(radio) {
    document.querySelectorAll('.stype-card').forEach(function(card) {
      card.style.borderColor = '#e5e7eb';
      card.style.background  = '#fff';
      card.style.color       = '#444';
    });
    var card = radio.nextElementSibling;
    card.style.borderColor = card.dataset.color;
    card.style.background  = card.dataset.bg;
    card.style.color       = card.dataset.color;
  }
  window.highlightStype = highlightStype;
  // Init highlight on page load
  var checked = document.querySelector('.stype-radio:checked');
  if (checked) highlightStype(checked);

  // ── DOB picker ─────────────────────────────────────────────
  var mSel   = document.getElementById('dobMonth');
  var dSel   = document.getElementById('dobDay');
  var ySel   = document.getElementById('dobYear');
  var hidden = document.getElementById('birthdayValue');
  var preview= document.getElementById('dobPreview');
  var MONTHS = ['January','February','March','April','May','June',
                'July','August','September','October','November','December'];

  function updateDays() {
    var m = parseInt(mSel.value,10), y = parseInt(ySel.value,10)||new Date().getFullYear();
    var max = m ? new Date(y,m,0).getDate() : 31;
    var cur = dSel.value;
    while (dSel.options.length > 1) dSel.remove(1);
    for (var i=1;i<=max;i++) {
      var o=document.createElement('option');
      o.value=i<10?'0'+i:''+i; o.text=''+i; dSel.appendChild(o);
    }
    if (cur && parseInt(cur,10)<=max) dSel.value=cur;
  }

  function assembleDate() {
    var m=mSel.value, d=dSel.value, y=ySel.value;
    if (m&&d&&y) {
      hidden.value = y+'-'+m+'-'+d;
      var today=new Date(), dob=new Date(parseInt(y),parseInt(m)-1,parseInt(d));
      var age=Math.floor((today-dob)/(365.25*24*3600*1000));
      if (age<15) {
        preview.innerHTML='<span style="color:#ef4444;">Applicant must be at least 15 years old.</span>';
        hidden.value='';
      } else {
        preview.innerHTML='<span style="color:#16a34a;">'
          +MONTHS[parseInt(m)-1]+' '+parseInt(d)+', '+y+'</span>';
      }
    } else {
      hidden.value=''; preview.innerHTML='';
    }
  }

  mSel.addEventListener('change',function(){updateDays();assembleDate();});
  dSel.addEventListener('change',assembleDate);
  ySel.addEventListener('change',function(){updateDays();assembleDate();});

  // ── Per-tab validation helpers ────────────────────────────
  var FIELD_LABELS = {
    last_name:           'Last Name',
    first_name:          'First Name',
    sex:                 'Sex',
    civil_status:        'Civil Status',
    nationality:         'Nationality',
    religion:            'Religion',
    place_of_birth:      'Place of Birth',
    email:               'Email Address',
    phone:               'Mobile Number',
    address:             'Home Address',
    father_last_name:    "Father's Last Name",
    father_first_name:   "Father's First Name",
    father_occupation:   "Father's Occupation",
    father_phone:        "Father's Contact Number",
    mother_last_name:    "Mother's Last Name",
    mother_first_name:   "Mother's First Name",
    mother_occupation:   "Mother's Occupation",
    mother_phone:        "Mother's Contact Number",
    guardian_phone:      "Guardian's Contact Number",
    emergency_name:      'Emergency Contact Person',
    emergency_relation:  'Emergency Contact Relationship',
    emergency_phone:     'Emergency Contact Number',
    prev_school:         'Previous School Name',
    prev_school_address: 'School Address',
    last_year_level:     'Last Year Level Completed',
    grad_year:           'Year Graduated',
    general_average:     'General Average / GPA',
  };

  var PHONE_FIELDS = {
    phone:           'Mobile Number',
    father_phone:    "Father's Contact Number",
    mother_phone:    "Mother's Contact Number",
    guardian_phone:  "Guardian's Contact Number",
    emergency_phone: 'Emergency Contact Number',
  };

  function fieldLabel(el) {
    return FIELD_LABELS[el.name] || (el.closest('.enroll-field') && el.closest('.enroll-field').querySelector('label')
      ? el.closest('.enroll-field').querySelector('label').textContent.replace('*','').trim()
      : el.name);
  }

  function markErr(el, msg) {
    el.style.borderColor = '#ef4444';
    el.style.boxShadow   = '0 0 0 2px rgba(239,68,68,.15)';
    var wrap  = el.closest('.enroll-field') || el.parentElement;
    var span  = wrap.querySelector('.inline-field-err');
    if (!span) { span = document.createElement('span'); span.className='inline-field-err'; wrap.appendChild(span); }
    span.textContent = msg;
  }

  function clearErr(el) {
    el.style.borderColor = '';
    el.style.boxShadow   = '';
    var wrap = el.closest('.enroll-field') || el.parentElement;
    var span = wrap && wrap.querySelector('.inline-field-err');
    if (span) span.textContent = '';
  }

  // Clears errors on interaction
  document.getElementById('enrollForm').addEventListener('input',  function(e){ if(e.target.name) clearErr(e.target); });
  document.getElementById('enrollForm').addEventListener('change', function(e){ if(e.target.name) clearErr(e.target); });

  // Validates a tab by ID. Returns true if valid, false + shows errors if not.
  function validateTab(tabId) {
    var pane   = document.getElementById('tab-' + tabId);
    var errBox = document.getElementById('err-' + tabId);
    var errors = [];

    // Clear previous highlights in this tab
    pane.querySelectorAll('input, select').forEach(function(el){ clearErr(el); });

    // 1. Required empty fields
    pane.querySelectorAll('[required]').forEach(function(el) {
      if (el.id === 'birthdayValue') return;
      if (!el.value.trim()) {
        var lbl = fieldLabel(el);
        markErr(el, lbl + ' is required.');
        errors.push(lbl + ' is required.');
      }
    });

    // 2. Phone fields in this tab — exactly 11 digits
    Object.keys(PHONE_FIELDS).forEach(function(name) {
      var el = pane.querySelector('[name="' + name + '"]');
      if (!el) return;
      var digits = el.value.replace(/\D/g,'');
      if (!el.hasAttribute('required') && digits === '') return;
      if (digits.length !== 11) {
        var msg = PHONE_FIELDS[name] + ' must be exactly 11 digits (you entered ' + digits.length + ').';
        markErr(el, msg);
        errors.push(msg);
      }
    });

    // 3. Date of birth (student tab only)
    if (tabId === 'student' && !hidden.value) {
      [mSel, dSel, ySel].forEach(function(s) {
        s.style.borderColor = '#ef4444';
        s.style.boxShadow   = '0 0 0 2px rgba(239,68,68,.15)';
      });
      var msg = 'Date of Birth is required (applicant must be at least 15 years old).';
      document.getElementById('dobPreview').innerHTML =
        '<span style="color:#ef4444;font-size:.75rem;">' + msg + '</span>';
      errors.push(msg);
    }

    // 4. Student category (student tab only)
    if (tabId === 'student' && !pane.querySelector('.stype-radio:checked')) {
      errors.push('Student Category is required — select New Regular, Transferee, or Returnee.');
    }

    if (errors.length === 0) {
      if (errBox) errBox.style.display = 'none';
      return true;
    }

    // Show error summary box at top of tab
    if (errBox) {
      var html = '<strong>Please fix the following before continuing:</strong><ul>';
      errors.forEach(function(m){ html += '<li>' + m + '</li>'; });
      html += '</ul>';
      errBox.innerHTML     = html;
      errBox.style.display = 'block';
      errBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    return false;
  }

  // ── Next button handlers (validate before switching) ──────
  function nextToParent() {
    if (validateTab('student')) goToTab('parent');
  }
  function nextToSchool() {
    if (validateTab('parent')) goToTab('school');
  }
  window.nextToParent = nextToParent;
  window.nextToSchool = nextToSchool;

  // ── Final submit: validate all three tabs ─────────────────
  document.getElementById('enrollForm').addEventListener('submit', function(e) {
    var s = validateTab('student');
    var p = validateTab('parent');
    var c = validateTab('school');
    if (!s || !p || !c) {
      e.preventDefault();
      // Jump to first failing tab
      if (!s) { goToTab('student'); return; }
      if (!p) { goToTab('parent');  return; }
      goToTab('school');
    }
  });
}());
</script>
</body>
</html>

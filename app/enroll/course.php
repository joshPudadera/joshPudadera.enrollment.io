<?php
session_start();
$branch = $_GET['branch'] ?? $_SESSION['enroll']['branch'] ?? '';
if (!$branch) { header('Location: branch.php'); exit; }
$_SESSION['enroll']['branch'] = $branch;

$applicant_type = $_SESSION['enroll']['applicant_type'] ?? '';
if (!$applicant_type) { header('Location: applicant_type.php'); exit; }

$current_step = 4;
include __DIR__ . '/header.php';

// Show only programs relevant to the applicant type
$college_programs = [
    ['Bachelor of Science in Information Technology',   'BSIT', 'fa-laptop-code'],
    ['Bachelor of Science in Computer Science',         'BSCS', 'fa-microchip'],
    ['Bachelor of Science in Information Systems',      'BSIS', 'fa-database'],
    ['Bachelor of Science in Business Administration',  'BSBA', 'fa-briefcase'],
    ['Bachelor of Science in Accountancy',              'BSA',  'fa-calculator'],
    ['Bachelor of Science in Criminology',              'BSCrim','fa-shield-halved'],
    ['Bachelor of Science in Education',                'BSEd', 'fa-chalkboard-user'],
    ['Bachelor of Science in Nursing',                  'BSN',  'fa-kit-medical'],
];
$shs_programs = [
    ['STEM (Science, Technology, Engineering, Math)',   'STEM', 'fa-flask'],
    ['ABM (Accountancy, Business & Management)',        'ABM',  'fa-chart-line'],
    ['HUMSS (Humanities & Social Sciences)',            'HUMSS','fa-book-open'],
    ['TVL (Technical-Vocational Livelihood)',           'TVL',  'fa-screwdriver-wrench'],
];

// Build the display groups based on type
$programs = [];
if ($applicant_type === 'Senior High') {
    $programs['Senior High School'] = $shs_programs;
} else {
    // Freshman, Transferee, Octoberian — college programs
    $programs['College'] = $college_programs;
    // Transferee may have a pre-selected course — highlight it
}

$pre_selected = $_SESSION['enroll']['transfer_course'] ?? '';
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Choose Your Program</h2>
    <p style="text-align:center; font-size:.85rem; color:#666; margin-bottom:4px;">
      Campus: <strong><?= htmlspecialchars($branch) ?></strong>
      &nbsp;·&nbsp; Enrolling as: <strong style="color:#2563eb;"><?= htmlspecialchars($applicant_type) ?></strong>
    </p>
    <?php if ($pre_selected): ?>
    <p style="text-align:center;font-size:.78rem;color:#d97706;margin-bottom:14px;">
      <i class="fa-solid fa-circle-info"></i>
      Your selected transfer course is highlighted below.
    </p>
    <?php endif; ?>

    <?php foreach ($programs as $level => $courses): ?>
    <p class="enroll-section-heading"><?= $level ?></p>
    <div class="option-grid">
      <?php foreach ($courses as [$name, $code, $icon]):
        $is_pre = ($name === $pre_selected);
      ?>
      <a href="form.php?course=<?= urlencode($name) ?>"
         class="option-card"
         style="<?= $is_pre ? 'border:2px solid #d97706;background:#fff7ed;' : '' ?>">
        <i class="fa-solid <?= $icon ?>"></i>
        <span class="option-card-title"><?= $code ?></span>
        <span class="option-card-sub"><?= htmlspecialchars($name) ?></span>
        <?php if ($is_pre): ?>
        <span style="font-size:.68rem;color:#d97706;font-weight:700;margin-top:4px;">
          <i class="fa-solid fa-star"></i> Your transfer course
        </span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="enroll-actions">
      <a href="branch.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>
    </div>

  </div>
</div>

</body>
</html>

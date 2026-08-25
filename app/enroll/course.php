<?php
session_start();
$branch = $_GET['branch'] ?? $_SESSION['enroll']['branch'] ?? '';
if (!$branch) { header('Location: branch.php'); exit; }
$_SESSION['enroll']['branch'] = $branch;

$current_step = 3;
include __DIR__ . '/header.php';

$programs = [
    'College' => [
        ['Bachelor of Science in Information Technology',   'BSIT', 'fa-laptop-code'],
        ['Bachelor of Science in Computer Science',         'BSCS', 'fa-microchip'],
        ['Bachelor of Science in Information Systems',      'BSIS', 'fa-database'],
        ['Bachelor of Science in Business Administration',  'BSBA', 'fa-briefcase'],
        ['Bachelor of Science in Accountancy',              'BSA',  'fa-calculator'],
        ['Bachelor of Science in Criminology',              'BSCrim','fa-shield-halved'],
        ['Bachelor of Science in Education',                'BSEd', 'fa-chalkboard-user'],
        ['Bachelor of Science in Nursing',                  'BSN',  'fa-kit-medical'],
    ],
    'Senior High School' => [
        ['STEM (Science, Technology, Engineering, Math)',   'STEM', 'fa-flask'],
        ['ABM (Accountancy, Business & Management)',        'ABM',  'fa-chart-line'],
        ['HUMSS (Humanities & Social Sciences)',            'HUMSS','fa-book-open'],
        ['TVL (Technical-Vocational Livelihood)',           'TVL',  'fa-screwdriver-wrench'],
    ],
];
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Choose Your Program</h2>
    <p style="text-align:center; font-size:.85rem; color:#666; margin-bottom:4px;">
      Campus: <strong><?= htmlspecialchars($branch) ?></strong>
    </p>

    <?php foreach ($programs as $level => $courses): ?>
    <p class="enroll-section-heading"><?= $level ?></p>
    <div class="option-grid">
      <?php foreach ($courses as [$name, $code, $icon]): ?>
      <a href="form.php?course=<?= urlencode($name) ?>" class="option-card">
        <i class="fa-solid <?= $icon ?>"></i>
        <span class="option-card-title"><?= $code ?></span>
        <span class="option-card-sub"><?= htmlspecialchars($name) ?></span>
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

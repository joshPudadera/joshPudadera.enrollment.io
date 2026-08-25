<?php
session_start();
$current_step = 2;
include __DIR__ . '/header.php';

$branches = [
    ['Main Campus',      'Baliuag, Bulacan',          'fa-building-columns'],
    ['Quezon City',      'Novaliches, Quezon City',    'fa-city'],
    ['Caloocan',         'Caloocan City, Metro Manila','fa-map-location-dot'],
    ['Fairview',         'Fairview, Quezon City',      'fa-location-dot'],
];
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Choose Your Campus</h2>
    <p style="text-align:center; font-size:.85rem; color:#666; margin-bottom:4px;">
      Select the BCP campus you wish to enroll in.
    </p>

    <div class="option-grid">
      <?php foreach ($branches as [$name, $loc, $icon]): ?>
      <a href="course.php?branch=<?= urlencode($name) ?>" class="option-card">
        <i class="fa-solid <?= $icon ?>"></i>
        <span class="option-card-title"><?= htmlspecialchars($name) ?></span>
        <span class="option-card-sub"><?= htmlspecialchars($loc) ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <div class="enroll-actions">
      <a href="index.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>
    </div>

  </div>
</div>

</body>
</html>

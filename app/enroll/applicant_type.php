<?php
session_start();
// Clear any stale enrollment session except what index.php already set
if (empty($_SESSION['enroll'])) $_SESSION['enroll'] = [];

$current_step = 2;
include __DIR__ . '/header.php';
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">How Are You Enrolling?</h2>
    <p style="text-align:center;font-size:.85rem;color:#666;margin-bottom:28px;">
      Select the category that best describes you. This determines the requirements
      and course options available to you.
    </p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px;">

      <?php
      $types = [
          [
              'value' => 'Freshman',
              'icon'  => 'fa-graduation-cap',
              'color' => '#16a34a',
              'bg'    => '#dcfce7',
              'label' => 'Freshman',
              'desc'  => 'New college student enrolling for the first time (1st Year).',
              'href'  => 'branch.php',
          ],
          [
              'value' => 'Senior High',
              'icon'  => 'fa-book-open',
              'color' => '#2563eb',
              'bg'    => '#eff6ff',
              'label' => 'Senior High School',
              'desc'  => 'Enrolling in Grade 11 or Grade 12 at BCP.',
              'href'  => 'branch.php',
          ],
          [
              'value' => 'Octoberian',
              'icon'  => 'fa-calendar-day',
              'color' => '#7c3aed',
              'bg'    => '#faf5ff',
              'label' => 'Octoberian',
              'desc'  => 'Mid-year enrollee starting second semester (October).',
              'href'  => 'branch.php',
          ],
          [
              'value' => 'Transferee',
              'icon'  => 'fa-right-left',
              'color' => '#d97706',
              'bg'    => '#fff7ed',
              'label' => 'Transferee',
              'desc'  => 'Transferring from another school or switching courses.',
              'href'  => 'transferee_details.php',   // extra step for transferees
          ],
      ];
      foreach ($types as $t):
      ?>
      <a href="<?= $t['href'] ?>?type=<?= urlencode($t['value']) ?>"
         onclick="setType('<?= htmlspecialchars($t['value'], ENT_QUOTES) ?>')"
         style="display:flex;flex-direction:column;align-items:center;gap:12px;
                background:#fff;border:2px solid #e5e7eb;border-radius:14px;
                padding:24px 18px;text-align:center;text-decoration:none;
                transition:border-color .15s, transform .15s, box-shadow .15s;
                cursor:pointer;"
         onmouseover="this.style.borderColor='<?= $t['color'] ?>';this.style.transform='translateY(-3px)';this.style.boxShadow='0 6px 20px rgba(0,0,0,.1)';"
         onmouseout="this.style.borderColor='#e5e7eb';this.style.transform='';this.style.boxShadow='';">
        <div style="width:56px;height:56px;border-radius:50%;background:<?= $t['bg'] ?>;
                    display:flex;align-items:center;justify-content:center;">
          <i class="fa-solid <?= $t['icon'] ?>" style="font-size:1.4rem;color:<?= $t['color'] ?>;"></i>
        </div>
        <div>
          <div style="font-size:.95rem;font-weight:700;color:#1a1a2e;"><?= $t['label'] ?></div>
          <div style="font-size:.75rem;color:#888;margin-top:4px;line-height:1.5;"><?= $t['desc'] ?></div>
        </div>
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

<script>
function setType(type) {
    // Save to session via a tiny fetch before navigating
    fetch('set_type.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'applicant_type=' + encodeURIComponent(type)
    });
    // Navigation happens via the <a> href — no need to wait
}
</script>
</body>
</html>

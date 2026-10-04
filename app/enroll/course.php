<?php
session_start();
$branch = $_GET['branch'] ?? $_SESSION['enroll']['branch'] ?? '';
if (!$branch) { header('Location: branch.php'); exit; }
$_SESSION['enroll']['branch'] = $branch;

$applicant_type = $_SESSION['enroll']['applicant_type'] ?? '';
if (!$applicant_type) { header('Location: applicant_type.php'); exit; }

$current_step = 4;
include __DIR__ . '/header.php';

// ── College programs (24 courses) ──────────────────────────────
$college_programs = [
    // Technology
    ['Bachelor of Science in Information Technology',                    'BSIT',        'fa-laptop-code',       'Technology'],
    ['Bachelor of Science in Computer Engineering',                      'BSCPE',       'fa-microchip',         'Technology'],
    ['Bachelor in Library Information Science',                          'BLIS',        'fa-book-bookmark',     'Technology'],
    // Sciences
    ['Bachelor of Science in Psychology',                                'BSP',         'fa-brain',             'Sciences'],
    // Education
    ['Bachelor of Elementary Education',                                 'BEED',        'fa-child',             'Education'],
    ['Bachelor of Technology and Livelihood Education',                  'BTLED',       'fa-toolbox',           'Education'],
    ['Bachelor of Secondary Education major in Filipino',                'BSED Filipino','fa-language',         'Education'],
    ['Bachelor of Secondary Education major in English',                 'BSED English','fa-spell-check',       'Education'],
    ['Bachelor of Secondary Education major in Values',                  'BSED Values', 'fa-heart',             'Education'],
    ['Bachelor of Secondary Education major in Social Studies',          'BSED Social Studies','fa-globe-asia', 'Education'],
    ['Bachelor of Secondary Education major in Science',                 'BSED Science','fa-flask',             'Education'],
    ['Bachelor of Secondary Education major in Mathematics',             'BSED Math',   'fa-square-root-variable','Education'],
    ['Bachelor in Physical Education',                                   'BPED',        'fa-person-running',    'Education'],
    ['Certificate of Professional Education',                            'CPE',         'fa-certificate',       'Education'],
    // Criminal Justice
    ['Bachelor of Science in Criminology',                               'BSCRIM',      'fa-shield-halved',     'Criminal Justice'],
    // Business
    ['Bachelor of Science in Accounting Information System',             'BSAIS',       'fa-calculator',        'Business'],
    ['Bachelor of Science in Entrepreneurship',                          'BSENTREP',    'fa-store',             'Business'],
    ['Bachelor of Science in Business Administration major in Marketing Management', 'BSBA MM', 'fa-chart-line', 'Business'],
    ['Bachelor of Science in Business Administration major in Human Resource Management', 'BSBA HRM', 'fa-people-group', 'Business'],
    ['Bachelor of Science in Business Administration major in Financial Management', 'BSBA FM', 'fa-coins',     'Business'],
    ['Bachelor of Science in Office Administration',                     'BSOA',        'fa-briefcase',         'Business'],
    // Hospitality & Tourism
    ['Bachelor of Science in Tourism Management',                        'BSTM',        'fa-plane-departure',   'Hospitality & Tourism'],
    ['Bachelor of Science in Hospitality Management',                    'BSHM',        'fa-concierge-bell',    'Hospitality & Tourism'],
];

// ── SHS strands ────────────────────────────────────────────────
$shs_programs = [
    ['STEM (Science, Technology, Engineering & Mathematics)', 'STEM',  'fa-flask'],
    ['ABM (Accountancy, Business & Management)',              'ABM',   'fa-chart-line'],
    ['HUMSS (Humanities & Social Sciences)',                  'HUMSS', 'fa-book-open'],
    ['TVL (Technical-Vocational Livelihood)',                 'TVL',   'fa-screwdriver-wrench'],
];

if ($applicant_type === 'Senior High') {
    $show_shs     = true;
    $show_college = false;
} else {
    $show_shs     = false;
    $show_college = true;
}

// Group college programs by department
$grouped = [];
foreach ($college_programs as $prog) {
    $grouped[$prog[3]][] = $prog;
}
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Choose Your Program</h2>
    <p style="text-align:center;font-size:.85rem;color:#666;margin-bottom:4px;">
      Campus: <strong><?= htmlspecialchars($branch) ?></strong>
      &nbsp;·&nbsp; Type: <strong style="color:#2563eb;"><?= htmlspecialchars($applicant_type) ?></strong>
    </p>

    <?php if ($show_college): ?>
    <!-- Search / filter -->
    <div style="margin:18px 0 4px;">
      <input type="text" id="courseSearch" placeholder="Search program or abbreviation…"
             oninput="filterCourses(this.value)"
             style="width:100%;height:42px;border:1.5px solid #d0d7e2;border-radius:8px;
                    padding:0 14px;font-size:.88rem;outline:none;font-family:inherit;"/>
    </div>

    <?php foreach ($grouped as $dept => $courses): ?>
    <p class="enroll-section-heading" data-dept style="margin-top:22px;"><?= htmlspecialchars($dept) ?></p>
    <div class="option-grid" data-dept-grid>
      <?php foreach ($courses as [$name, $code, $icon]): ?>
      <a href="form.php?course=<?= urlencode($name) ?>"
         class="option-card course-card"
         data-name="<?= strtolower(htmlspecialchars($name)) ?>"
         data-code="<?= strtolower(htmlspecialchars($code)) ?>">
        <i class="fa-solid <?= $icon ?>"></i>
        <span class="option-card-title"><?= htmlspecialchars($code) ?></span>
        <span class="option-card-sub"><?= htmlspecialchars($name) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <script>
    function filterCourses(q) {
        q = q.toLowerCase().trim();
        var cards = document.querySelectorAll('.course-card');
        cards.forEach(function(card) {
            var match = !q || card.dataset.name.includes(q) || card.dataset.code.includes(q);
            card.style.display = match ? '' : 'none';
        });
        // Hide dept headings where all cards are hidden
        document.querySelectorAll('[data-dept]').forEach(function(hd) {
            var grid = hd.nextElementSibling;
            var visible = grid ? Array.from(grid.querySelectorAll('.course-card')).some(function(c){ return c.style.display !== 'none'; }) : false;
            hd.style.display      = visible ? '' : 'none';
            if (grid) grid.style.display = visible ? '' : 'none';
        });
    }
    </script>

    <?php else: ?>
    <!-- SHS strands -->
    <div class="option-grid" style="margin-top:20px;">
      <?php foreach ($shs_programs as [$name, $code, $icon]): ?>
      <a href="form.php?course=<?= urlencode($name) ?>" class="option-card">
        <i class="fa-solid <?= $icon ?>"></i>
        <span class="option-card-title"><?= htmlspecialchars($code) ?></span>
        <span class="option-card-sub"><?= htmlspecialchars($name) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="enroll-actions" style="margin-top:28px;">
      <a href="branch.php" class="btn-back">Back</a>
    </div>

  </div>
</div>
</body>
</html>

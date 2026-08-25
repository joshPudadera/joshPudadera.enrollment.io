<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Bestlink College of the Philippines</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f4f8; }

    /* ── Topbar ── */
    .top-nav {
      background: #1a3a8c;
      padding: 0 32px;
      height: 58px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .top-nav-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
    }
    .top-nav-brand img { width: 38px; height: auto; }
    .top-nav-brand span { color: #fff; font-size: .95rem; font-weight: 700; }
    .top-nav-links { display: flex; gap: 8px; align-items: center; }
    .top-nav-links a {
      color: #c8d8f5; font-size: .82rem; text-decoration: none;
      padding: 6px 14px; border-radius: 6px; transition: background .15s;
    }
    .top-nav-links a:hover { background: rgba(255,255,255,.12); color: #fff; }
    .btn-signin-nav {
      background: #fff !important; color: #1a3a8c !important;
      font-weight: 700 !important; border-radius: 8px !important;
    }
    .btn-signin-nav:hover { background: #e8edf4 !important; }

    /* ── Hero ── */
    .hero {
      background: linear-gradient(135deg, #1a3a8c 0%, #0f2460 60%, #1e4db7 100%);
      padding: 80px 32px 90px;
      text-align: center;
      color: #fff;
    }
    .hero img { width: 90px; height: auto; margin-bottom: 18px; }
    .hero h1 { font-size: 2.2rem; font-weight: 800; line-height: 1.25; margin-bottom: 12px; }
    .hero p  { font-size: 1rem; color: #c8d8f5; max-width: 520px; margin: 0 auto 32px; line-height: 1.7; }
    .btn-enroll {
      background: #fff;
      color: #1a3a8c;
      border: none;
      border-radius: 10px;
      padding: 15px 40px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      transition: transform .15s, box-shadow .15s;
      box-shadow: 0 4px 20px rgba(0,0,0,.2);
    }
    .btn-enroll:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,0,0,.25); }
    .btn-signin-hero {
      background: transparent;
      color: #fff;
      border: 2px solid rgba(255,255,255,.5);
      border-radius: 10px;
      padding: 13px 32px;
      font-size: 1rem;
      font-weight: 600;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin-left: 12px;
      transition: background .15s;
    }
    .btn-signin-hero:hover { background: rgba(255,255,255,.12); }

    /* ── Info section ── */
    .info-section {
      max-width: 1000px;
      margin: 48px auto;
      padding: 0 24px;
    }
    .info-section h2 {
      text-align: center;
      font-size: 1.5rem;
      font-weight: 700;
      color: #1a3a8c;
      margin-bottom: 32px;
    }
    .cards-row {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 20px;
    }
    .info-card {
      background: #fff;
      border-radius: 12px;
      padding: 28px 22px;
      text-align: center;
      box-shadow: 0 2px 12px rgba(0,0,0,.07);
    }
    .info-card i { font-size: 2rem; color: #1a3a8c; margin-bottom: 12px; }
    .info-card h3 { font-size: .95rem; font-weight: 700; color: #1a1a2e; margin-bottom: 8px; }
    .info-card p  { font-size: .78rem; color: #666; line-height: 1.6; }

    /* ── Steps preview ── */
    .steps-section {
      background: #1a3a8c;
      padding: 52px 24px;
      color: #fff;
    }
    .steps-section h2 {
      text-align: center;
      font-size: 1.4rem;
      font-weight: 700;
      margin-bottom: 36px;
    }
    .steps-row {
      display: flex;
      justify-content: center;
      gap: 0;
      flex-wrap: wrap;
      max-width: 860px;
      margin: 0 auto;
    }
    .step-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      flex: 1;
      min-width: 110px;
      gap: 10px;
    }
    .step-num {
      width: 42px; height: 42px;
      background: rgba(255,255,255,.2);
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: .9rem; font-weight: 700;
    }
    .step-item p { font-size: .72rem; color: #c8d8f5; text-align: center; line-height: 1.4; }

    /* ── CTA ── */
    .cta-section {
      text-align: center;
      padding: 60px 24px;
    }
    .cta-section h2 { font-size: 1.6rem; font-weight: 700; color: #1a1a2e; margin-bottom: 12px; }
    .cta-section p  { font-size: .9rem; color: #666; margin-bottom: 28px; }

    /* ── Footer ── */
    footer {
      background: #1a3a8c;
      color: #c8d8f5;
      text-align: center;
      padding: 20px;
      font-size: .78rem;
    }

    @media (max-width: 600px) {
      .hero h1 { font-size: 1.5rem; }
      .btn-signin-hero { margin: 10px 0 0; display: flex; }
      .top-nav-links a:not(.btn-signin-nav) { display: none; }
    }
  </style>
</head>
<body>

<!-- Nav -->
<nav class="top-nav">
  <a class="top-nav-brand" href="landing.php">
    <img src="images/BCP_LOGO.png" alt="BCP Logo"/>
    <span>BCP Student Portal</span>
  </a>
  <div class="top-nav-links">
    <a href="#about">About</a>
    <a href="#programs">Programs</a>
    <a href="#steps">How to Enroll</a>
    <a href="auth/signin.php" class="btn-signin-nav">Sign In</a>
  </div>
</nav>

<!-- Hero -->
<section class="hero">
  <img src="images/BCP_LOGO.png" alt="BCP Logo"/>
  <h1>Bestlink College<br>of the Philippines</h1>
  <p>Quality education, accessible to all. Begin your academic journey with BCP today.</p>
  <a href="enroll/index.php" class="btn-enroll">
    <i class="fa-solid fa-graduation-cap"></i> Start Enrollment
  </a>
  <a href="auth/signin.php" class="btn-signin-hero">
    <i class="fa-solid fa-right-to-bracket"></i> Sign In
  </a>
</section>

<!-- About cards -->
<section class="info-section" id="about">
  <h2>Why Choose BCP?</h2>
  <div class="cards-row">
    <div class="info-card">
      <i class="fa-solid fa-award"></i>
      <h3>CHED Accredited</h3>
      <p>All programs are accredited by the Commission on Higher Education.</p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-users"></i>
      <h3>Expert Faculty</h3>
      <p>Learn from experienced professionals and dedicated educators.</p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-laptop-code"></i>
      <h3>Modern Facilities</h3>
      <p>State-of-the-art laboratories and technology-equipped classrooms.</p>
    </div>
    <div class="info-card">
      <i class="fa-solid fa-map-location-dot"></i>
      <h3>Multiple Campuses</h3>
      <p>Campuses in Bulacan, Quezon City, and Caloocan for your convenience.</p>
    </div>
  </div>
</section>

<!-- Programs -->
<section class="info-section" id="programs" style="margin-top:0;">
  <h2>Programs Offered</h2>
  <div class="cards-row">
    <?php
    $programs = [
      ['fa-laptop-code',       'BSIT',   'Bachelor of Science in Information Technology'],
      ['fa-microchip',         'BSCS',   'Bachelor of Science in Computer Science'],
      ['fa-database',          'BSIS',   'Bachelor of Science in Information Systems'],
      ['fa-briefcase',         'BSBA',   'Bachelor of Science in Business Administration'],
      ['fa-calculator',        'BSA',    'Bachelor of Science in Accountancy'],
      ['fa-shield-halved',     'BSCrim', 'Bachelor of Science in Criminology'],
      ['fa-chalkboard-user',   'BSEd',   'Bachelor of Science in Education'],
      ['fa-kit-medical',       'BSN',    'Bachelor of Science in Nursing'],
    ];
    foreach ($programs as [$icon, $code, $name]):
    ?>
    <div class="info-card">
      <i class="fa-solid <?= $icon ?>"></i>
      <h3><?= $code ?></h3>
      <p><?= $name ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- Enrollment steps -->
<section class="steps-section" id="steps">
  <h2>How to Enroll Online</h2>
  <div class="steps-row">
    <?php
    $steps = [
      [1,'Review Admission Info'],
      [2,'Choose Campus'],
      [3,'Choose Program'],
      [4,'Fill Personal Info'],
      [5,'Review & Submit'],
      [6,'Submit Requirements'],
    ];
    foreach ($steps as [$n, $label]):
    ?>
    <div class="step-item">
      <div class="step-num"><?= $n ?></div>
      <p><?= $label ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- CTA -->
<section class="cta-section">
  <h2>Ready to start your journey?</h2>
  <p>Complete your enrollment online in just a few minutes.</p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;align-items:center;">
    <a href="enroll/index.php" class="btn-enroll" style="display:inline-flex;">
      <i class="fa-solid fa-graduation-cap"></i> Enroll Now
    </a>
    <a href="requirements/index.php"
       style="display:inline-flex;align-items:center;gap:8px;
              background:#e8edf4;color:#1a3a8c;border-radius:10px;
              padding:15px 32px;font-size:1rem;font-weight:700;
              text-decoration:none;transition:background .15s;">
      <i class="fa-solid fa-upload"></i> Submit Requirements
    </a>
  </div>
</section>

<footer>
  &copy; 2026 Bestlink College of the Philippines &mdash; eLearning Commons
</footer>

</body>
</html>

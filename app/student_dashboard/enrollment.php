<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id']))     { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'student') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
require_enrollment_tables($conn);

$uid          = (int)$_SESSION['user_id'];
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// Fetch all applications for this user
$apps = [];
$r = $conn->prepare("SELECT * FROM pre_registrations WHERE user_id=? ORDER BY submitted_at DESC");
$r->bind_param('i',$uid); $r->execute();
$apps_result = $r->get_result();
while ($row = $apps_result->fetch_assoc()) $apps[] = $row;
$r->close();

// Fetch enrollment records (ID number, section, year level) for each application
$enrollments_map = [];
if (!empty($apps)) {
    $app_ids_str = implode(',', array_map('intval', array_column($apps, 'id')));
    $enr_res = $conn->query(
        "SELECT e.pre_reg_id, e.id_number, e.section, e.year_level, e.school_year, e.semester
         FROM enrollments e
         WHERE e.pre_reg_id IN ($app_ids_str)"
    );
    if ($enr_res) while ($enr_row = $enr_res->fetch_assoc()) {
        $enrollments_map[(int)$enr_row['pre_reg_id']] = $enr_row;
    }
}

// Fetch all uploaded documents for this user — one query, no duplicates
// Union: docs linked via pre_reg_id + any docs linked only via user_id
$docs = [];
$res = $conn->query(
    "SELECT d.*, COALESCE(p.course,'') AS course
     FROM enrollment_documents d
     LEFT JOIN pre_registrations p ON d.pre_reg_id = p.id
     WHERE d.user_id = $uid
     ORDER BY d.uploaded_at DESC"
);
if ($res) while ($row = $res->fetch_assoc()) $docs[(int)$row['id']] = $row;
$docs = array_values($docs); // re-index

$status_steps = ['Pending'=>1,'Approved'=>2,'Enrolled'=>3];
$doc_type_labels = [
    'Form137'=>'Form 137','BirthCertificate'=>'PSA Birth Certificate',
    'GoodMoral'=>'Good Moral','MedicalCert'=>'Medical Certificate',
    'IDPhoto'=>'ID Photo','Other'=>'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>My Enrollment – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php $APP_ROOT='../'; $ACTIVE_NAV='enrollment'; require_once __DIR__.'/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <a href="account.php" class="avatar" title="Account Settings"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-graduation-cap"></i> My Enrollment</h2>
    </div>

    <?php if (empty($apps)): ?>
    <div class="form-card" style="text-align:center;padding:40px;">
      <i class="fa-solid fa-file-pen" style="font-size:2.5rem;color:#d0d7e2;margin-bottom:16px;display:block;"></i>
      <h3 style="color:#888;margin-bottom:10px;">No Application Yet</h3>
      <p style="font-size:.85rem;color:#aaa;margin-bottom:20px;">
        You haven't submitted an enrollment application yet.
      </p>
      <a href="../enroll/index.php" class="btn-submit" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;">
        <i class="fa-solid fa-plus"></i> Start Enrollment Application
      </a>
    </div>
    <?php endif; ?>

    <?php foreach ($apps as $idx => $app):
      $step   = $status_steps[$app['status']] ?? 0;
      $sc     = match($app['status']) {'Approved'=>'#22c55e','Rejected'=>'#ef4444','Enrolled'=>'#2563eb',default=>'#f59e0b'};
      $si     = match($app['status']) {'Approved'=>'fa-circle-check','Rejected'=>'fa-circle-xmark','Enrolled'=>'fa-id-badge',default=>'fa-clock'};
      $course_short = preg_replace('/Bachelor of Science in /i','BS ',$app['course']);
      $app_docs = array_filter($docs, fn($d) => (int)$d['pre_reg_id'] === (int)$app['id']);
      $ref_num  = !empty($app['ref_number']) ? $app['ref_number'] : ('BCP-REF-' . str_pad($app['id'], 6, '0', STR_PAD_LEFT));
      $ref_id   = 'app-ref-' . $app['id'];
    ?>
    <div class="crud-card" style="margin-bottom:20px;">

      <!-- Reference number bar -->
      <div style="display:flex;align-items:center;justify-content:space-between;
                  background:#f8fafc;border-bottom:1px solid #f0f2f5;
                  padding:10px 20px;flex-wrap:wrap;gap:8px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <i class="fa-solid fa-hashtag" style="color:#1a3a8c;font-size:.85rem;"></i>
          <span style="font-size:.72rem;color:#aaa;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Reference Number</span>
          <code id="<?= $ref_id ?>"
                style="font-size:.88rem;font-weight:700;color:#1a3a8c;
                       background:#eff6ff;padding:3px 10px;border-radius:6px;
                       letter-spacing:.05em;"><?= htmlspecialchars($ref_num) ?></code>
          <span id="<?= $ref_id ?>-hidden"
                style="display:none;font-size:.78rem;color:#aaa;font-style:italic;">
            ••••••••••••
          </span>
        </div>
        <button onclick="toggleRef('<?= $ref_id ?>', this)"
          style="background:none;border:1.5px solid #d0d7e2;border-radius:7px;
                 padding:5px 14px;font-size:.75rem;font-weight:600;color:#555;
                 cursor:pointer;display:inline-flex;align-items:center;gap:6px;"
          onmouseover="this.style.background='#f0f4f8'"
          onmouseout="this.style.background='none'">
          <i class="fa-solid fa-eye-slash"></i> Hide
        </button>
      </div>

      <!-- Collapsible details wrapper -->
      <div style="padding:20px;">

      <!-- Application header -->
      <div style="display:flex;align-items:flex-start;justify-content:space-between;
                  flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <div>
          <div style="font-size:1rem;font-weight:700;color:#1a1a2e;">
            <?= htmlspecialchars($course_short) ?>
          </div>
          <div style="font-size:.78rem;color:#888;margin-top:3px;">
            <?= htmlspecialchars($app['year_level']) ?>
            &nbsp;·&nbsp;
            Submitted: <?= date('M d, Y', strtotime($app['submitted_at'])) ?>
          </div>
        </div>
        <span style="display:inline-flex;align-items:center;gap:7px;padding:6px 16px;
                     border-radius:20px;font-size:.8rem;font-weight:700;
                     background:<?= $sc ?>18;color:<?= $sc ?>;">
          <i class="fa-solid <?= $si ?>"></i>
          <?= $app['status'] ?>
        </span>
      </div>

      <!-- Progress bar -->
      <?php if ($app['status'] !== 'Rejected'): ?>
      <div class="enrollment-steps" style="margin:0 0 20px;padding:16px 20px;">
        <?php
        $steps_list = [1=>'Submitted',2=>'Approved',3=>'Enrolled'];
        foreach ($steps_list as $sn => $sl):
          $cls = $sn < $step ? 'done' : ($sn === $step ? 'active' : '');
        ?>
        <div class="enr-step <?= $cls ?>">
          <div class="enr-step-icon">
            <?= $sn < $step ? '<i class="fa-solid fa-check" style="font-size:.7rem;"></i>' : $sn ?>
          </div>
          <span class="enr-step-label"><?= $sl ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Remarks -->
      <?php if ($app['remarks']): ?>
      <div style="background:#fff7ed;border-left:4px solid #f59e0b;border-radius:6px;
                  padding:10px 14px;margin-bottom:16px;font-size:.82rem;color:#92400e;">
        <i class="fa-solid fa-comment"></i>
        <strong>Admin Note:</strong> <?= htmlspecialchars($app['remarks']) ?>
      </div>
      <?php endif; ?>

      <!-- Application details -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;
                  font-size:.82rem;margin-bottom:20px;">
        <?php
        $enr_data = $enrollments_map[(int)$app['id']] ?? null;
        $fields = [
          'Full Name'   => htmlspecialchars($app['first_name'].' '.$app['last_name']),
          'Email'       => htmlspecialchars($app['email']),
          'Phone'       => htmlspecialchars($app['phone']),
          'Birthday'    => $app['birthday'],
          'Course'      => htmlspecialchars($app['course']),
          'Year Level'  => htmlspecialchars($app['year_level']),
          'Prev. School'=> htmlspecialchars($app['prev_school'] ?? '—'),
        ];
        foreach ($fields as $lbl => $val): ?>
        <div>
          <div style="font-size:.68rem;color:#aaa;font-weight:600;text-transform:uppercase;margin-bottom:2px;"><?= $lbl ?></div>
          <div style="color:#1a1a2e;"><?= $val ?></div>
        </div>
        <?php endforeach; ?>

        <?php if ($enr_data): ?>
        <!-- Enrollment details from admin -->
        <div style="grid-column:1/-1;border-top:1px solid #f0f2f5;padding-top:12px;margin-top:4px;">
          <div style="font-size:.72rem;font-weight:700;color:#1a3a8c;text-transform:uppercase;
                      letter-spacing:.04em;margin-bottom:10px;">
            <i class="fa-solid fa-id-badge" style="margin-right:5px;"></i>
            Enrollment Details
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;">
            <?php
            $enr_fields = [
              'Student ID'  => '<code style="font-size:.88rem;font-weight:700;color:#2563eb;background:#eff6ff;padding:3px 10px;border-radius:6px;letter-spacing:.04em;">'.htmlspecialchars($enr_data['id_number']).'</code>',
              'Section'     => $enr_data['section'] ? '<strong style="color:#1a1a2e;">'.htmlspecialchars($enr_data['section']).'</strong>' : '<span style="color:#aaa;font-style:italic;">Not yet assigned</span>',
              'Year Level'  => htmlspecialchars($enr_data['year_level'] ?: ($app['year_level'] ?? '—')),
              'School Year' => htmlspecialchars(($enr_data['school_year'] ?? '2025-2026').' · '.($enr_data['semester'] ?? '1st').' Semester'),
            ];
            foreach ($enr_fields as $lbl => $val): ?>
            <div>
              <div style="font-size:.68rem;color:#aaa;font-weight:600;text-transform:uppercase;margin-bottom:2px;"><?= $lbl ?></div>
              <div><?= $val ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php elseif ($app['status'] === 'Enrolled'): ?>
        <div style="grid-column:1/-1;margin-top:8px;padding:10px 14px;
                    background:#fffbeb;border-left:3px solid #f59e0b;border-radius:6px;
                    font-size:.8rem;color:#92400e;">
          <i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i>
          Enrollment details are being finalized. Please check back shortly.
        </div>
        <?php endif; ?>
      </div>

      <!-- Documents section -->
      <div style="border-top:1px solid #f0f2f5;padding-top:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
          <h3 style="font-size:.9rem;font-weight:700;color:#1a1a2e;">
            <i class="fa-solid fa-folder-open" style="color:#2563eb;margin-right:6px;"></i>
            Uploaded Documents (<?= count($app_docs) ?>)
          </h3>
          <a href="requirements.php" class="btn-add" style="padding:6px 14px;font-size:.78rem;">
            <i class="fa-solid fa-plus"></i> Add More
          </a>
        </div>

        <?php if (!empty($app_docs)): ?>
        <table class="crud-table">
          <thead>
            <tr>
              <th>Document Type</th>
              <th>File Name</th>
              <th>Status</th>
              <th>AI Result</th>
              <th>View</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($app_docs as $doc):
              $ai      = !empty($doc['ai_result']) ? json_decode($doc['ai_result'],true) : null;
              $verdict = $ai['is_authentic'] ?? null;
              if ($verdict===true)          { $avc='#16a34a'; $avi='fa-circle-check';    $avl='Authentic'; }
              elseif ($verdict===false)     { $avc='#dc2626'; $avi='fa-circle-xmark';    $avl='Fake/Altered'; }
              elseif ($verdict==='uncertain'){ $avc='#f59e0b'; $avi='fa-circle-question'; $avl='Uncertain'; }
              else                          { $avc='#aaa';    $avi='fa-robot';           $avl='Not inspected'; }
              $doc_status_badge = $doc['status']==='Approved'?'badge-active':($doc['status']==='Rejected'?'badge-inactive':'');
              $doc_status_style = $doc['status']==='Pending' ? 'background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;' : '';
            ?>
            <tr>
              <td style="font-weight:600;">
                <?= htmlspecialchars($doc_type_labels[$doc['document_type']] ?? $doc['document_type']) ?>
              </td>
              <td style="font-size:.75rem;color:#555;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= htmlspecialchars($doc['file_name']) ?>
              </td>
              <td>
                <?php if ($doc_status_style): ?>
                <span style="<?= $doc_status_style ?>"><?= $doc['status'] ?></span>
                <?php else: ?>
                <span class="<?= $doc_status_badge ?>"><?= $doc['status'] ?></span>
                <?php endif; ?>
              </td>
              <td>
                <span style="font-size:.75rem;color:<?= $avc ?>;font-weight:600;">
                  <i class="fa-solid <?= $avi ?>"></i> <?= $avl ?>
                  <?php if ($ai && $ai['confidence']): ?>
                  <span style="color:#aaa;font-weight:400;">(<?= $ai['confidence'] ?>%)</span>
                  <?php endif; ?>
                </span>
              </td>
              <td>
                <a href="../requirements/file.php?path=<?= urlencode($doc['file_path']) ?>"
                   target="_blank"
                   class="btn-view-file">
                  <i class="fa-solid fa-eye"></i> View
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div style="text-align:center;padding:20px;color:#aaa;font-size:.82rem;">
          <i class="fa-solid fa-folder-open" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
          No documents uploaded yet.
          <a href="../requirements/index.php" style="color:#2563eb;display:block;margin-top:8px;">
            Upload Requirements →
          </a>
        </div>
        <?php endif; ?>
      </div><!-- end documents section -->

    </div><!-- end app card -->
    <?php endforeach; ?>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>
function toggleRef(id, btn) {
    var code   = document.getElementById(id);
    var hidden = document.getElementById(id + '-hidden');
    var showing = code.style.display !== 'none';
    code.style.display   = showing ? 'none' : '';
    hidden.style.display = showing ? '' : 'none';
    btn.innerHTML = showing
        ? '<i class="fa-solid fa-eye"></i> Show'
        : '<i class="fa-solid fa-eye-slash"></i> Hide';
}
</script>
<script src="../js/dashboard.js"></script>
</body></html>
<?php $conn->close(); ?>

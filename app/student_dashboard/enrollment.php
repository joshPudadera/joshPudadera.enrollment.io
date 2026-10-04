<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id']))     { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'student') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
require_enrollment_tables($conn);

$uid          = (int)$_SESSION['user_id'];
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// Fetch all applications for this user
// Primary: by user_id. Fallback: by email (pre-reg submitted before account existed).
$apps = [];
$r = $conn->prepare("SELECT * FROM pre_registrations WHERE user_id=? ORDER BY submitted_at DESC");
$r->bind_param('i',$uid); $r->execute();
$apps_result = $r->get_result();
while ($row = $apps_result->fetch_assoc()) $apps[] = $row;
$r->close();

// If no apps found by user_id, try email fallback
if (empty($apps)) {
    $er = $conn->query("SELECT email FROM users WHERE id=$uid LIMIT 1");
    if ($er && $erow = $er->fetch_assoc()) {
        $esc_email = $conn->real_escape_string($erow['email']);
        $r2 = $conn->prepare(
            "SELECT * FROM pre_registrations
             WHERE email=? AND (user_id IS NULL OR user_id=0)
             ORDER BY submitted_at DESC"
        );
        $r2->bind_param('s', $esc_email); $r2->execute();
        $res2 = $r2->get_result();
        while ($row2 = $res2->fetch_assoc()) {
            // Link permanently
            $conn->query("UPDATE pre_registrations SET user_id=$uid WHERE id=" . (int)$row2['id']);
            $row2['user_id'] = $uid;
            $apps[] = $row2;
        }
        $r2->close();
    }
}

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

// Fetch all uploaded documents — keyed by pre_reg_id for fast per-app lookup
$docs = [];
if (!empty($apps)) {
    $app_ids_str = implode(',', array_map('intval', array_column($apps, 'id')));
    $res = $conn->query(
        "SELECT d.*, COALESCE(p.course,'') AS course
         FROM enrollment_documents d
         LEFT JOIN pre_registrations p ON d.pre_reg_id = p.id
         WHERE d.pre_reg_id IN ($app_ids_str)
         ORDER BY d.uploaded_at ASC"
    );
    if ($res) while ($row = $res->fetch_assoc()) $docs[(int)$row['id']] = $row;
}
$docs = array_values($docs);

$status_steps = ['Pending'=>1,'Approved'=>2,'Enrolled'=>3];
$doc_type_labels = [
    'Form137'=>'Form 137','BirthCertificate'=>'PSA Birth Certificate',
    'GoodMoral'=>'Good Moral','MedicalCert'=>'Medical Certificate',
    'IDPhoto'=>'ID Photo','ReportCard'=>'Report Card (Form 138)','Other'=>'Other',
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
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <h3 style="font-size:.9rem;font-weight:700;color:#1a1a2e;">
            <i class="fa-solid fa-folder-open" style="color:#2563eb;margin-right:6px;"></i>
            Documents
          </h3>
          <a href="requirements.php" class="btn-add" style="padding:6px 14px;font-size:.78rem;">
            <i class="fa-solid fa-plus"></i> Upload / Manage
          </a>
        </div>

        <?php
        $app_docs_list = array_values($app_docs);
        $total_docs    = count($app_docs_list);
        $n_approved    = count(array_filter($app_docs_list, fn($d)=>$d['status']==='Approved'));
        $n_pending     = count(array_filter($app_docs_list, fn($d)=>$d['status']==='Pending'));
        $n_rejected    = count(array_filter($app_docs_list, fn($d)=>$d['status']==='Rejected'));

        // Required docs
        $req_types = ['BirthCertificate'=>'Birth Cert','ReportCard'=>'Report Card','GoodMoral'=>'Good Moral'];
        ?>

        <?php if ($total_docs > 0): ?>

        <!-- Quick summary stat row -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
          <div style="flex:1;min-width:80px;background:#f8fafc;border:1.5px solid #e5e7eb;border-radius:10px;padding:10px 14px;text-align:center;">
            <div style="font-size:1.4rem;font-weight:700;color:#1a1a2e;"><?= $total_docs ?></div>
            <div style="font-size:.68rem;color:#888;margin-top:2px;text-transform:uppercase;letter-spacing:.04em;">Total</div>
          </div>
          <div style="flex:1;min-width:80px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:10px 14px;text-align:center;">
            <div style="font-size:1.4rem;font-weight:700;color:#16a34a;"><?= $n_approved ?></div>
            <div style="font-size:.68rem;color:#16a34a;margin-top:2px;text-transform:uppercase;letter-spacing:.04em;">Approved</div>
          </div>
          <div style="flex:1;min-width:80px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:10px 14px;text-align:center;">
            <div style="font-size:1.4rem;font-weight:700;color:#d97706;"><?= $n_pending ?></div>
            <div style="font-size:.68rem;color:#d97706;margin-top:2px;text-transform:uppercase;letter-spacing:.04em;">Pending</div>
          </div>
          <?php if ($n_rejected > 0): ?>
          <div style="flex:1;min-width:80px;background:#fff1f2;border:1.5px solid #fca5a5;border-radius:10px;padding:10px 14px;text-align:center;">
            <div style="font-size:1.4rem;font-weight:700;color:#dc2626;"><?= $n_rejected ?></div>
            <div style="font-size:.68rem;color:#dc2626;margin-top:2px;text-transform:uppercase;letter-spacing:.04em;">Rejected</div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Required docs checklist -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
          <?php foreach ($req_types as $rtype => $rlabel):
            $rtype_docs  = array_values(array_filter($app_docs_list, fn($d) => $d['document_type'] === $rtype));
            $r_approved  = (bool)array_filter($rtype_docs, fn($d) => $d['status'] === 'Approved');
            $r_rejected  = !$r_approved && !empty($rtype_docs) && $rtype_docs[count($rtype_docs)-1]['status']==='Rejected';
            $r_pending   = !$r_approved && !$r_rejected && !empty($rtype_docs);
            [$rclr,$rbg,$rico] = $r_approved
              ? ['#16a34a','#dcfce7','fa-circle-check']
              : ($r_rejected
                ? ['#dc2626','#fee2e2','fa-circle-xmark']
                : ($r_pending
                  ? ['#d97706','#fff7ed','fa-clock']
                  : ['#9ca3af','#f3f4f6','fa-circle-xmark']));
            $rlbl_status = $r_approved?'Approved':($r_rejected?'Rejected':($r_pending?'Pending':'Missing'));
            $rattempts   = count($rtype_docs);
          ?>
          <div style="background:<?= $rbg ?>;border:1.5px solid <?= $rclr ?>30;border-radius:8px;
                      padding:8px 12px;min-width:100px;">
            <div style="font-size:.68rem;font-weight:700;color:<?= $rclr ?>;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">
              <i class="fa-solid <?= $rico ?>"></i> <?= htmlspecialchars($rlabel) ?>
            </div>
            <div style="font-size:.8rem;font-weight:700;color:<?= $rclr ?>;"><?= $rlbl_status ?></div>
            <?php if ($rattempts > 0): ?>
            <div style="font-size:.65rem;color:<?= $rclr ?>;opacity:.7;margin-top:2px;"><?= $rattempts ?> submission<?= $rattempts!==1?'s':'' ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Grouped document cards by type -->
        <?php
        $grouped_docs = [];
        foreach ($app_docs_list as $d) $grouped_docs[$d['document_type']][] = $d;
        $all_labels = array_merge(
            ['BirthCertificate'=>'PSA Birth Certificate','ReportCard'=>'Report Card (Form 138)','GoodMoral'=>'Certificate of Good Moral'],
            $doc_type_labels
        );
        ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
        <?php foreach ($grouped_docs as $gtype => $gdocs):
          $glabel      = $all_labels[$gtype] ?? $gtype;
          $g_approved  = (bool)array_filter($gdocs, fn($d)=>$d['status']==='Approved');
          $g_latest_st = $gdocs[count($gdocs)-1]['status'];
          [$ghbg,$ghclr,$ghico] = $g_approved
              ? ['#f0fdf4','#16a34a','fa-circle-check']
              : ($g_latest_st==='Rejected'
                  ? ['#fff1f2','#dc2626','fa-circle-xmark']
                  : ['#fffbeb','#d97706','fa-clock']);
        ?>
        <div style="border:1.5px solid #e5e7eb;border-radius:10px;overflow:hidden;">
          <!-- Type header -->
          <div style="background:<?= $ghbg ?>;padding:9px 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <span style="font-weight:700;font-size:.85rem;color:<?= $ghclr ?>;">
              <i class="fa-solid <?= $ghico ?>"></i> <?= htmlspecialchars($glabel) ?>
            </span>
            <div style="display:flex;gap:6px;align-items:center;">
              <?php if ($g_approved): ?>
              <span style="background:#dcfce7;color:#16a34a;padding:2px 9px;border-radius:20px;font-size:.7rem;font-weight:700;">✓ Approved</span>
              <?php elseif ($g_latest_st==='Rejected'): ?>
              <span style="background:#fee2e2;color:#dc2626;padding:2px 9px;border-radius:20px;font-size:.7rem;font-weight:700;">✗ Rejected</span>
              <?php else: ?>
              <span style="background:#fff7ed;color:#d97706;padding:2px 9px;border-radius:20px;font-size:.7rem;font-weight:700;">⏳ Pending</span>
              <?php endif; ?>
              <span style="font-size:.7rem;color:#888;"><?= count($gdocs) ?> submission<?= count($gdocs)!==1?'s':'' ?></span>
            </div>
          </div>
          <!-- Submissions grid -->
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;padding:12px;">
          <?php foreach ($gdocs as $sidx => $gdoc):
            $g_thumb   = '../requirements/file.php?path=' . urlencode($gdoc['file_path']);
            $g_ext     = strtolower(pathinfo($gdoc['file_name'], PATHINFO_EXTENSION));
            $g_is_img  = in_array($g_ext,['jpg','jpeg','png']);
            $gst       = $gdoc['status'];
            [$gsc,$gsi] = match($gst){
                'Approved'=>['#16a34a','fa-circle-check'],
                'Rejected'=>['#dc2626','fa-circle-xmark'],
                default   =>['#d97706','fa-clock'],
            };
            $gai       = !empty($gdoc['ai_result']) ? json_decode($gdoc['ai_result'],true) : null;
            $gconf     = $gai ? (int)($gai['confidence']??0) : 0;
          ?>
          <div style="border:1.5px solid #e8edf4;border-radius:8px;overflow:hidden;">
            <div style="background:#f8fafc;padding:4px 10px;font-size:.68rem;font-weight:700;
                        display:flex;justify-content:space-between;align-items:center;">
              <span style="color:#888;">Sub. #<?= $sidx+1 ?></span>
              <span style="color:<?= $gsc ?>;"><i class="fa-solid <?= $gsi ?>"></i> <?= $gst ?></span>
            </div>
            <?php if ($g_is_img): ?>
            <a href="<?= $g_thumb ?>" target="_blank">
              <img src="<?= $g_thumb ?>" alt="doc"
                   style="width:100%;height:70px;object-fit:cover;display:block;"
                   onerror="this.style.display='none'"/>
            </a>
            <?php else: ?>
            <div style="height:50px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;">
              <i class="fa-solid fa-file" style="color:#aaa;font-size:1.2rem;"></i>
            </div>
            <?php endif; ?>
            <div style="padding:6px 10px;">
              <div style="font-size:.68rem;color:#aaa;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= htmlspecialchars($gdoc['file_name']) ?>
              </div>
              <?php if ($gai): ?>
              <div style="font-size:.65rem;color:#888;">AI: <?= $gconf ?>% confidence</div>
              <?php endif; ?>
              <a href="<?= $g_thumb ?>" target="_blank"
                 style="font-size:.7rem;color:#2563eb;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:3px;margin-top:5px;">
                <i class="fa-solid fa-eye"></i> View
              </a>
            </div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        </div>

        <?php else: ?>
        <div style="text-align:center;padding:24px;color:#aaa;font-size:.82rem;background:#f8fafc;border-radius:10px;">
          <i class="fa-solid fa-folder-open" style="font-size:1.8rem;display:block;margin-bottom:10px;opacity:.4;"></i>
          No documents uploaded yet.
          <a href="requirements.php" style="color:#2563eb;display:block;margin-top:8px;font-weight:600;">
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

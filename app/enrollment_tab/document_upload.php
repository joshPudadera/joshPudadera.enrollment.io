<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$user_id      = (int)$_SESSION['user_id'];

// Handle upload POST
$upload_msg = $upload_ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doc_file'])) {
    $pre_reg_id   = (int)($_POST['pre_reg_id'] ?? 0);
    $doc_type     = $_POST['document_type'] ?? '';
    $allowed_ext  = ['pdf','jpg','jpeg','png'];
    $file         = $_FILES['doc_file'];
    $ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_ext)) {
        $upload_msg = 'Only PDF, JPG, and PNG files are allowed.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $upload_msg = 'File must be under 5MB.';
    } else {
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $new_name   = uniqid('doc_') . '.' . $ext;
        $dest       = $upload_dir . $new_name;
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $file_path = 'uploads/' . $new_name;
            $file_size = (int)$file['size'];
            $orig_name = htmlspecialchars($file['name']);
            $stmt = $conn->prepare(
                'INSERT INTO enrollment_documents (pre_reg_id,user_id,document_type,file_name,file_path,file_size)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->bind_param('iisssi', $pre_reg_id, $user_id, $doc_type, $orig_name, $file_path, $file_size);
            if ($stmt->execute()) $upload_ok = 'Document uploaded successfully.';
            else $upload_msg = 'DB error: ' . $conn->error;
        } else { $upload_msg = 'Failed to save file.'; }
    }
}

// Guard — redirect to setup if enrollment tables don't exist yet
$tables_exist = $conn->query("SHOW TABLES LIKE 'pre_registrations'")->num_rows > 0;
if (!$tables_exist) {
    die('
    <div style="font-family:sans-serif;padding:40px;max-width:480px;margin:60px auto;
                background:#fff7ed;border:1px solid #fcd34d;border-radius:12px;text-align:center;">
      <h2 style="color:#d97706;margin-bottom:10px;">&#9888; Database Not Set Up</h2>
      <p style="color:#555;font-size:.9rem;line-height:1.6;margin-bottom:20px;">
        The enrollment tables do not exist yet.<br>Run the database setup first.
      </p>
      <a href="../shared/full_setup.php"
         style="background:#2563eb;color:#fff;padding:10px 24px;border-radius:8px;
                text-decoration:none;font-weight:600;">Run Full Setup</a>
    </div>');
}

// Get pre-reg
$pre_reg = null;
$stmt = $conn->prepare('SELECT * FROM pre_registrations WHERE user_id=? AND status IN ("Approved","Enrolled") LIMIT 1');
$stmt->bind_param('i', $user_id); $stmt->execute();
$pre_reg = $stmt->get_result()->fetch_assoc();

// Get existing uploads
$docs = [];
if ($pre_reg) {
    $res = $conn->query("SELECT * FROM enrollment_documents WHERE pre_reg_id={$pre_reg['id']} ORDER BY uploaded_at DESC");
    while ($r = $res->fetch_assoc()) $docs[] = $r;
}

$doc_types = ['Form137','BirthCertificate','GoodMoral','MedicalCert','IDPhoto','Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Document Upload – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'enrollment'; require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap"><input type="text" placeholder="Search..."/><i class="fa-solid fa-magnifying-glass"></i></div>
      <a href="../admin_dashboard/account.php" class="avatar"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-upload"></i> Document Upload Portal</h2>
    </div>

    <?php if (!$pre_reg): ?>
    <div class="form-card">
      <p style="color:#888; font-size:0.85rem;">
        You need an <strong>approved pre-registration</strong> before uploading documents.
        <a href="pre_registration.php" style="color:#2563eb;">Submit your application here.</a>
      </p>
    </div>
    <?php else: ?>

    <?php if ($upload_msg): ?><div class="auth-error" style="margin:0 24px 16px;"><?= $upload_msg ?></div><?php endif; ?>
    <?php if ($upload_ok): ?><div class="auth-success" style="margin:0 24px 16px;"><?= $upload_ok ?></div><?php endif; ?>

    <div class="form-card">
      <h3>Upload Document</h3>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="pre_reg_id" value="<?= $pre_reg['id'] ?>"/>
        <div class="form-grid">
          <div class="form-field">
            <label>Document Type <span class="req">* required</span></label>
            <select name="document_type" required>
              <option value="">Select type…</option>
              <?php foreach ($doc_types as $t): ?>
              <option><?= $t ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-field">
            <label>File <span class="req">* required</span></label>
            <input type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png" required
                   style="height:auto; padding:8px 12px;"/>
            <span style="font-size:0.68rem; color:#aaa; margin-top:2px;">PDF, JPG, PNG · max 5MB</span>
          </div>
        </div>
        <div class="form-submit">
          <button type="submit" class="btn-submit">
            <i class="fa-solid fa-cloud-arrow-up"></i> Upload
          </button>
        </div>
      </form>
    </div>

    <div class="crud-card">
      <div class="crud-header"><h3>Uploaded Documents</h3></div>
      <table class="crud-table">
        <thead><tr><th>Type</th><th>File Name</th><th>Size</th><th>Status</th><th>Uploaded</th></tr></thead>
        <tbody>
          <?php if ($docs): foreach ($docs as $d):
            $badge = $d['status']==='Approved' ? 'badge-active' : ($d['status']==='Rejected' ? 'badge-inactive' : '');
            $pstyle = $d['status']==='Pending' ? 'background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:600;' : '';
          ?>
          <tr>
            <td><?= $d['document_type'] ?></td>
            <td style="font-size:0.78rem;"><?= htmlspecialchars($d['file_name']) ?></td>
            <td style="font-size:0.75rem;color:#888;"><?= round($d['file_size']/1024, 1) ?> KB</td>
            <td><?php if ($d['status']==='Pending'): ?><span style="<?= $pstyle ?>"><?= $d['status'] ?></span>
                <?php else: ?><span class="<?= $badge ?>"><?= $d['status'] ?></span><?php endif; ?></td>
            <td style="font-size:0.75rem;color:#888;"><?= date('M d, Y', strtotime($d['uploaded_at'])) ?></td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="5" style="text-align:center;padding:24px;color:#aaa;">No documents uploaded yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
</body></html>
<?php $conn->close(); ?>

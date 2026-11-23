<?php

$DB_HOST     = getenv('DB_HOST') ?: 'localhost';
$DB_USER     = getenv('DB_USER') ?: 'root';
$DB_PASS     = getenv('DB_PASS') ?: '';
$DB_NAME     = getenv('API_TEST1_DB')     ?: 'api_test1';
$SEND_API    = getenv('API_TEST1_SEND_URL')  ?: 'http://localhost/sms/app/api/test1_send.php';
$RECEIVE_API = getenv('API_TEST2_SEND_URL')  ?: 'http://localhost/sms/app/api/test2_send.php';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die("Cannot connect to <strong>$DB_NAME</strong>. Run <a href='setup.php'>setup.php</a> first.");
}

$msg     = '';
$msg_err = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $name       = $conn->real_escape_string(trim($_POST['name']       ?? ''));
    $section    = $conn->real_escape_string(trim($_POST['section']    ?? ''));
    $year_level = $conn->real_escape_string(trim($_POST['year_level'] ?? ''));
    if ($name && $section && $year_level) {
        $conn->query("INSERT INTO students (name, section, year_level, source)
                      VALUES ('$name', '$section', '$year_level', 'local')");
        $msg = "Added: $name ($section, $year_level)";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $id = (int)($_POST['student_id'] ?? 0);
    if ($id) {
        $response = @file_get_contents($SEND_API . '?id=' . $id);
        $resp = json_decode($response, true);
        if (($resp['status'] ?? '') === 'success') {
            $msg = "Sent student to Page 2. Inserted: " . ($resp['inserted'] ?? 0);
        } else {
            $msg_err = true;
            $msg = "Send failed: " . ($resp['message'] ?? 'No response from API.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_all') {
    $response = @file_get_contents($SEND_API);
    $resp = json_decode($response, true);
    if (($resp['status'] ?? '') === 'success') {
        $msg = "Sent all to Page 2. Inserted: " . ($resp['inserted'] ?? 0) . ", Duplicates: " . ($resp['duplicates'] ?? 0);
    } else {
        $msg_err = true;
        $msg = "Send failed: " . ($resp['message'] ?? 'No response from API.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive') {
    $response = @file_get_contents($RECEIVE_API);
    $resp = json_decode($response, true);
    if (($resp['status'] ?? '') === 'success') {
        $msg = "Received " . ($resp['inserted'] ?? 0) . " student(s) from Page 2. Duplicates: " . ($resp['duplicates'] ?? 0);
    } else {
        $msg_err = true;
        $msg = "Receive failed: " . ($resp['message'] ?? 'No response from API.');
    }
}

$students = [];
$res = $conn->query("SELECT * FROM students ORDER BY created_at DESC");
if ($res) while ($r = $res->fetch_assoc()) $students[] = $r;
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Page 1 — <?= htmlspecialchars($DB_NAME) ?></title>
  <link rel="stylesheet" href="css/style.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<div class="card">
  <h1><i class="fa-solid fa-database" style="color:#16a34a;"></i> Page 1 — <code><?= htmlspecialchars($DB_NAME) ?></code></h1>
  <p class="subtitle">Send API: <code>test1_send.php</code> &nbsp;|&nbsp; Receive API: <code>test1_receive.php</code></p>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_err ? 'alert-err' : 'alert-ok' ?>">
    <i class="fa-solid <?= $msg_err ? 'fa-circle-xmark' : 'fa-circle-check' ?>"></i> <?= $msg ?>
  </div>
  <?php endif; ?>

  <h2><i class="fa-solid fa-user-plus"></i> Add Student</h2>
  <form method="POST">
    <input type="hidden" name="action" value="add"/>
    <div class="form-grid">
      <div class="field"><label>Name</label><input type="text" name="name" required placeholder="Juan Dela Cruz"/></div>
      <div class="field"><label>Section</label><input type="text" name="section" required placeholder="11001"/></div>
      <div class="field"><label>Year Level</label><input type="text" name="year_level" required placeholder="1st Year"/></div>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn btn-add"><i class="fa-solid fa-plus"></i> Add Student</button>
    </div>
  </form>

  <h2><i class="fa-solid fa-right-left"></i> API Actions</h2>
  <div class="btn-row">
    <form method="POST" style="display:inline;">
      <input type="hidden" name="action" value="send_all"/>
      <button type="submit" class="btn btn-send"><i class="fa-solid fa-paper-plane"></i> Send All → Page 2</button>
    </form>
    <form method="POST" style="display:inline;">
      <input type="hidden" name="action" value="receive"/>
      <button type="submit" class="btn btn-receive"><i class="fa-solid fa-cloud-arrow-down"></i> Pull ← Page 2</button>
    </form>
  </div>

  <h2><i class="fa-solid fa-table"></i> Students in <?= htmlspecialchars($DB_NAME) ?> (<?= count($students) ?>)</h2>
  <table>
    <thead>
      <tr><th>#</th><th>Name</th><th>Section</th><th>Year Level</th><th>Source</th><th>Added</th><th>Action</th></tr>
    </thead>
    <tbody>
      <?php if (empty($students)): ?>
      <tr><td colspan="7" style="text-align:center;padding:24px;color:#aaa;">No students yet.</td></tr>
      <?php else: foreach ($students as $i => $s): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
        <td><?= htmlspecialchars($s['section']) ?></td>
        <td><?= htmlspecialchars($s['year_level']) ?></td>
        <td>
          <?php $badge = match($s['source']) {
            'sent'     => ['badge-sent',     'Sent'],
            'received' => ['badge-received', 'Received'],
            default    => ['badge-sent',     'Local'],
          }; ?>
          <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
        </td>
        <td style="font-size:.75rem;color:#888;"><?= date('M d Y g:i A', strtotime($s['created_at'])) ?></td>
        <td>
          <?php if ($s['source'] !== 'sent'): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="send"/>
            <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>"/>
            <button type="submit" class="btn btn-send" style="padding:5px 12px;font-size:.75rem;">
              <i class="fa-solid fa-paper-plane"></i> Send → P2
            </button>
          </form>
          <?php else: ?>
          <span style="font-size:.72rem;color:#aaa;">Sent</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div style="margin-top:20px;display:flex;gap:10px;">
    <a href="page2.php" class="btn btn-send" style="text-decoration:none;"><i class="fa-solid fa-arrow-right"></i> Go to Page 2</a>
    <a href="setup.php" class="btn btn-receive" style="text-decoration:none;font-size:.8rem;padding:7px 14px;opacity:.7;"><i class="fa-solid fa-wrench"></i> DB Setup</a>
  </div>
</div>
</body>
</html>

<?php
// ============================================================
//  ENROLLMENT_ACTIONS.PHP  (shared/)
//  AJAX handler for all enrollment module operations.
// ============================================================
ob_start(); // buffer any stray PHP warnings so they don't corrupt JSON
session_start();
require_once __DIR__ . '/db.php';
ob_clean(); // discard any warnings output by db.php
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']); exit;
}

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = (int)$_SESSION['user_id'];
$is_admin = ($_SESSION['role'] ?? '') === 'admin';

function respond(bool $ok, string $msg, array $extra = []): void {
    ob_clean(); // discard any PHP warnings before outputting JSON
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra)); exit;
}

switch ($action) {

// ── PRE-REGISTRATION ─────────────────────────────────────────
case 'pre_register': {
    $first_name  = trim($_POST['first_name']  ?? '');
    $last_name   = trim($_POST['last_name']   ?? '');
    $email       = trim($_POST['email']       ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $birthday    = trim($_POST['birthday']    ?? '');
    $course      = trim($_POST['course']      ?? '');
    $year_level  = trim($_POST['year_level']  ?? '');
    $prev_school = trim($_POST['prev_school'] ?? '');

    if (!$first_name||!$last_name||!$email||!$phone||!$birthday||!$course||!$year_level)
        respond(false, 'All required fields must be filled.');

    // Check for existing pending application
    $chk = $conn->prepare('SELECT id FROM pre_registrations WHERE user_id=? AND status IN ("Pending","Approved") LIMIT 1');
    $chk->bind_param('i', $user_id); $chk->execute();
    if ($chk->get_result()->num_rows > 0) respond(false, 'You already have a pending or approved application.');
    $chk->close();

    $stmt = $conn->prepare(
        'INSERT INTO pre_registrations (user_id,first_name,last_name,email,phone,birthday,course,year_level,prev_school)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->bind_param('issssssss', $user_id,$first_name,$last_name,$email,$phone,$birthday,$course,$year_level,$prev_school);
    if ($stmt->execute()) respond(true, 'Pre-registration submitted successfully.', ['id' => $conn->insert_id]);
    respond(false, 'Submission failed: ' . $conn->error);
}

// ── GET OWN PRE-REG STATUS ────────────────────────────────────
case 'get_my_prereg': {
    $stmt = $conn->prepare('SELECT * FROM pre_registrations WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1');
    $stmt->bind_param('i', $user_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    respond(true, 'OK', ['data' => $row]);
}

// ── CHECK EMAIL CONFLICT (admin) ──────────────────────────────
// Returns whether the applicant's email is already tied to another student account
// that has a different active application, so the admin can be informed before approving.
case 'check_email_conflict': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);

    $pr = $conn->prepare('SELECT email, user_id FROM pre_registrations WHERE id=? LIMIT 1');
    $pr->bind_param('i', $pre_reg_id); $pr->execute();
    $applicant = $pr->get_result()->fetch_assoc(); $pr->close();
    if (!$applicant) respond(false, 'Application not found.');

    // If already linked to a portal account, no conflict possible
    if (!empty($applicant['user_id'])) {
        respond(true, 'OK', ['conflict' => false]);
    }

    $email = $applicant['email'];
    $ec = $conn->prepare('SELECT id, role FROM users WHERE email=? LIMIT 1');
    $ec->bind_param('s', $email); $ec->execute();
    $eu = $ec->get_result()->fetch_assoc(); $ec->close();

    if (!$eu) respond(true, 'OK', ['conflict' => false]);

    if ($eu['role'] === 'admin') {
        respond(true, 'OK', [
            'conflict' => true,
            'type'     => 'admin_email',
            'message'  => "This email belongs to an admin account. A new student account will be created with a prefixed email (stu{$pre_reg_id}.{$email}).",
        ]);
    }

    // Student account exists — check if it owns another approved application
    $existing_uid = (int)$eu['id'];
    $conflict = $conn->prepare(
        'SELECT id FROM pre_registrations WHERE user_id=? AND id != ? AND status IN ("Approved","Enrolled") LIMIT 1'
    );
    $conflict->bind_param('ii', $existing_uid, $pre_reg_id);
    $conflict->execute();
    $conflict_row = $conflict->get_result()->fetch_assoc();
    $conflict->close();

    if ($conflict_row) {
        respond(true, 'OK', [
            'conflict' => true,
            'type'     => 'different_student',
            'message'  => "The email \"{$email}\" is already used by a different student with an active application. A separate student account will be created automatically.",
        ]);
    }

    respond(true, 'OK', ['conflict' => false]);
}

// ── CHECK NAME CONFLICT (admin) ───────────────────────────────
// Finds already-approved or enrolled applications with the same
// first + last name as the given applicant (different pre_reg_id).
// Covers same name in a different course as well.
case 'check_name_conflict': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);

    $pr = $conn->prepare('SELECT first_name, last_name FROM pre_registrations WHERE id=? LIMIT 1');
    $pr->bind_param('i', $pre_reg_id); $pr->execute();
    $applicant = $pr->get_result()->fetch_assoc(); $pr->close();
    if (!$applicant) respond(false, 'Application not found.');

    $first = trim($applicant['first_name']);
    $last  = trim($applicant['last_name']);

    // Find any other approved/enrolled application with the same full name
    $dup = $conn->prepare(
        'SELECT first_name, last_name, course, status, ref_number
         FROM pre_registrations
         WHERE id != ?
           AND status IN ("Approved","Enrolled")
           AND LOWER(TRIM(first_name)) = LOWER(?)
           AND LOWER(TRIM(last_name))  = LOWER(?)
         ORDER BY submitted_at DESC'
    );
    $dup->bind_param('iss', $pre_reg_id, $first, $last);
    $dup->execute();
    $rows = $dup->get_result()->fetch_all(MYSQLI_ASSOC);
    $dup->close();

    if (empty($rows)) {
        respond(true, 'OK', ['conflict' => false]);
    }

    // Return every match so the admin can see course + status
    $matches = array_map(fn($r) => [
        'name'    => $r['first_name'] . ' ' . $r['last_name'],
        'course'  => $r['course'],
        'status'  => $r['status'],
        'ref'     => $r['ref_number'] ?? '—',
    ], $rows);

    respond(true, 'OK', ['conflict' => true, 'matches' => $matches]);
}


case 'validate_application': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);
    $status     = $_POST['status'] ?? '';
    $remarks    = trim($_POST['remarks'] ?? '');
    if (!in_array($status, ['Approved','Rejected'])) respond(false, 'Invalid status.');

    $stmt = $conn->prepare('UPDATE pre_registrations SET status=?, remarks=? WHERE id=?');
    $stmt->bind_param('ssi', $status, $remarks, $pre_reg_id);
    if (!$stmt->execute()) respond(false, $conn->error);
    $stmt->close();

    if ($status !== 'Approved') respond(true, 'Application Rejected.');

    require_once __DIR__ . '/mailer.php';

    // Fetch applicant
    $pr = $conn->prepare('SELECT * FROM pre_registrations WHERE id=? LIMIT 1');
    $pr->bind_param('i', $pre_reg_id); $pr->execute();
    $applicant = $pr->get_result()->fetch_assoc(); $pr->close();
    if (!$applicant) respond(false, 'Applicant not found.');

    $first  = $applicant['first_name'];
    $last   = $applicant['last_name'];
    $email  = $applicant['email'];
    $course = $applicant['course'];

    // Build unique username
    $base = strtolower(preg_replace('/\s+/', '.', trim("$first.$last")));
    $username = $base; $n = 1;
    while (true) {
        $c = $conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
        $c->bind_param('s', $username); $c->execute();
        if ($c->get_result()->num_rows === 0) { $c->close(); break; }
        $c->close();
        $username = $base . $n++;
    }

    $hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    $new_uid = 0;

    // Check existing linked user — reject if admin
    $linked_uid = (int)($applicant['user_id'] ?? 0);
    if ($linked_uid > 0) {
        $rc = $conn->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        $rc->bind_param('i', $linked_uid); $rc->execute();
        $rr = $rc->get_result()->fetch_assoc(); $rc->close();
        if ($rr && $rr['role'] === 'admin') {
            // Unlink admin — do not reuse
            $conn->query("UPDATE pre_registrations SET user_id=NULL WHERE id=$pre_reg_id");
            $linked_uid = 0;
        } else {
            $new_uid = $linked_uid;
        }
    }

    if (!$new_uid) {
        // ── Resolve student account by email ─────────────────────────
        // Safety: only reuse an existing student account if it is NOT
        // already the owner of a *different* approved/enrolled application.
        // This prevents one portal account from accidentally being linked
        // to multiple applicants when two people share the same email.
        $ec = $conn->prepare('SELECT id, role, username FROM users WHERE email=? LIMIT 1');
        $ec->bind_param('s', $email); $ec->execute();
        $eu = $ec->get_result()->fetch_assoc(); $ec->close();

        if ($eu && $eu['role'] === 'student') {
            $existing_uid = (int)$eu['id'];

            // Check: does this user already own a DIFFERENT pre-registration?
            $conflict = $conn->prepare(
                'SELECT id FROM pre_registrations
                 WHERE user_id=? AND id != ? AND status IN ("Approved","Enrolled")
                 LIMIT 1'
            );
            $conflict->bind_param('ii', $existing_uid, $pre_reg_id);
            $conflict->execute();
            $conflict_row = $conflict->get_result()->fetch_assoc();
            $conflict->close();

            if ($conflict_row) {
                // Another approved application is already linked to that account.
                // Create a brand-new account with a suffixed email to avoid collision.
                $alt_email = 'stu' . $pre_reg_id . '.' . $email;
                $ins = $conn->prepare("INSERT INTO users (username,email,first_name,last_name,password_hash,role) VALUES (?,?,?,?,?,'student')");
                $ins->bind_param('sssss', $username, $alt_email, $first, $last, $hash);
                $new_uid = $ins->execute() ? (int)$conn->insert_id : 0;
                $ins->close();
            } else {
                // Safe to reuse — this student account has no conflicting approved applications
                $new_uid  = $existing_uid;
                $username = $eu['username'];
                // Try to upgrade to clean username if current one is a numbered fallback
                if ($username !== $base && preg_match('/\d+$/', $username)) {
                    $ucheck = $conn->prepare('SELECT id FROM users WHERE username=? AND id != ? LIMIT 1');
                    $ucheck->bind_param('si', $base, $new_uid); $ucheck->execute();
                    if ($ucheck->get_result()->num_rows === 0) $username = $base;
                    $ucheck->close();
                }
                // Sync name and username with the approved application
                $upd_user = $conn->prepare('UPDATE users SET first_name=?, last_name=?, username=? WHERE id=?');
                $upd_user->bind_param('sssi', $first, $last, $username, $new_uid);
                $upd_user->execute(); $upd_user->close();
            }

        } elseif ($eu && $eu['role'] === 'admin') {
            // Admin email conflict — prefix to avoid collision
            $alt_email = 'stu' . $pre_reg_id . '.' . $email;
            $ins = $conn->prepare("INSERT INTO users (username,email,first_name,last_name,password_hash,role) VALUES (?,?,?,?,?,'student')");
            $ins->bind_param('sssss', $username, $alt_email, $first, $last, $hash);
            $new_uid = $ins->execute() ? (int)$conn->insert_id : 0;
            $ins->close();
        } else {
            // No existing user — create fresh
            $ins = $conn->prepare("INSERT INTO users (username,email,first_name,last_name,password_hash,role) VALUES (?,?,?,?,?,'student')");
            $ins->bind_param('sssss', $username, $email, $first, $last, $hash);
            $new_uid = $ins->execute() ? (int)$conn->insert_id : 0;
            $ins->close();
        }

        if ($new_uid) {
            $ul = $conn->prepare('UPDATE pre_registrations SET user_id=? WHERE id=?');
            $ul->bind_param('ii', $new_uid, $pre_reg_id); $ul->execute(); $ul->close();
        }
    }

    if (!$new_uid) respond(false, 'Failed to create student account. Error: ' . $conn->error);

    // Generate one-time login token
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+72 hours'));
    $t = $conn->prepare('INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?,?,?)');
    $t->bind_param('iss', $new_uid, $token, $expires); $t->execute(); $t->close();

    $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $login_url = "$protocol://$host/sms/app/auth/login_via_token.php?token=$token";

    // Send email (silently fails if SMTP not configured)
    $sc   = preg_replace('/Bachelor of Science in /i', 'BS ', $course);
    $body = email_template(
        'Your BCP Student Account is Ready!',
        "<p style='color:#333;font-size:14px;line-height:1.7;'>Dear <strong>$first $last</strong>,</p>
         <p style='color:#333;font-size:14px;line-height:1.7;'>Your application for <strong>$sc</strong> has been <strong style='color:#16a34a;'>approved</strong>. Your student account is ready.</p>
         <p style='color:#333;font-size:14px;line-height:1.7;'><strong>Username:</strong> $username<br>Click the button below to set your password. Link expires in <strong>72 hours</strong>.</p>",
        $login_url, 'Set My Password &amp; Log In →'
    );
    @send_email($email, 'Your BCP Student Portal Account', $body);

    respond(true, 'Application Approved.', [
        'login_url'    => $login_url,
        'username'     => $username,
        'student_name' => "$first $last",
    ]);
}

// ── GENERATE ID NUMBER (admin) ────────────────────────────────
case 'generate_id': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);
    $course     = trim($_POST['course']      ?? '');
    $year_level = trim($_POST['year_level']  ?? '');

    // Check not already enrolled
    $chk = $conn->prepare('SELECT id FROM enrollments WHERE pre_reg_id=? LIMIT 1');
    $chk->bind_param('i', $pre_reg_id); $chk->execute();
    if ($chk->get_result()->num_rows > 0) respond(false, 'Already enrolled.');
    $chk->close();

    // Build ID: BCP-YY-XXXXX
    $year   = date('y');
    $count  = $conn->query("SELECT COUNT(*)+1 AS n FROM enrollments")->fetch_assoc()['n'];
    $id_num = 'BCP-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);

    $stmt = $conn->prepare(
        'INSERT INTO enrollments (pre_reg_id, id_number, course, year_level, validated_by, validated_at)
         VALUES (?,?,?,?,?,NOW())'
    );
    $stmt->bind_param('isssi', $pre_reg_id, $id_num, $course, $year_level, $user_id);
    if ($stmt->execute()) {
        $stmt->close();
        // Update pre_registration status to Enrolled
        $enrolled_status = 'Enrolled';
        $upd = $conn->prepare('UPDATE pre_registrations SET status=? WHERE id=?');
        $upd->bind_param('si', $enrolled_status, $pre_reg_id);
        $upd->execute();
        $upd->close();
        respond(true, 'ID generated.', ['id_number' => $id_num]);
    }
    $stmt->close();
    respond(false, $conn->error);
}

// ── ASSIGN SECTION (admin) ────────────────────────────────────
case 'assign_section': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
    $section_code  = trim($_POST['section_code']   ?? '');

    // Get section id and check capacity
    $sec = $conn->query("SELECT * FROM sections WHERE section_code='".
           $conn->real_escape_string($section_code)."' LIMIT 1")->fetch_assoc();
    if (!$sec) respond(false, 'Section not found.');
    if ($sec['current_count'] >= $sec['max_capacity']) {
        // Add to waiting list
        $enr = $conn->query("SELECT pre_reg_id,course,year_level FROM enrollments WHERE id=$enrollment_id")->fetch_assoc();
        $pos = (int)$conn->query("SELECT COUNT(*)+1 AS n FROM waiting_list WHERE course='".$conn->real_escape_string($enr['course'])."'")->fetch_assoc()['n'];
        $stmt = $conn->prepare('INSERT INTO waiting_list (pre_reg_id,course,year_level,queue_position) VALUES (?,?,?,?)');
        $stmt->bind_param('issi', $enr['pre_reg_id'], $enr['course'], $enr['year_level'], $pos);
        $stmt->execute();
        respond(false, 'Section is full. Added to waiting list at position ' . $pos . '.');
    }

    $stmt = $conn->prepare('UPDATE enrollments SET section=? WHERE id=?');
    $stmt->bind_param('si', $section_code, $enrollment_id);
    if ($stmt->execute()) {
        $conn->query("UPDATE sections SET current_count=current_count+1 WHERE section_code='".
                     $conn->real_escape_string($section_code)."'");
        respond(true, "Section $section_code assigned.");
    }
    respond(false, $conn->error);
}

// ── ASSIGN GRADE LEVEL (admin) ────────────────────────────────
case 'assign_grade': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
    $year_level    = trim($_POST['year_level']      ?? '');
    $stmt = $conn->prepare('UPDATE enrollments SET year_level=? WHERE id=?');
    $stmt->bind_param('si', $year_level, $enrollment_id);
    if ($stmt->execute()) respond(true, 'Grade level updated.');
    respond(false, $conn->error);
}

// ── CROSS ENROLLMENT ─────────────────────────────────────────
case 'cross_enroll': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
    $cross_from    = trim($_POST['cross_from']      ?? '');
    $stmt = $conn->prepare('UPDATE enrollments SET is_cross=1, cross_from=? WHERE id=?');
    $stmt->bind_param('si', $cross_from, $enrollment_id);
    if ($stmt->execute()) respond(true, 'Marked as cross-enrolled.');
    respond(false, $conn->error);
}

// ── LIST PRE-REGS (admin) ─────────────────────────────────────
case 'list_pre_regs': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $status = $_GET['status'] ?? '';
    $where  = $status ? "WHERE status='" . $conn->real_escape_string($status) . "'" : '';
    $rows   = [];
    $res    = $conn->query("SELECT * FROM pre_registrations $where ORDER BY submitted_at DESC");
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    respond(true, 'OK', ['data' => $rows]);
}

// ── LIST ENROLLMENTS (admin) ──────────────────────────────────
case 'list_enrollments': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $rows = [];
    $res  = $conn->query(
        'SELECT e.*, p.first_name, p.last_name, p.email, p.phone
         FROM enrollments e
         JOIN pre_registrations p ON e.pre_reg_id = p.id
         ORDER BY e.enrolled_at DESC'
    );
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    respond(true, 'OK', ['data' => $rows]);
}

// ── LIST WAITING LIST ─────────────────────────────────────────
case 'list_waiting': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $rows = [];
    $res  = $conn->query(
        'SELECT w.*, p.first_name, p.last_name FROM waiting_list w
         JOIN pre_registrations p ON w.pre_reg_id = p.id
         WHERE w.status="Waiting" ORDER BY w.queue_position ASC'
    );
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    respond(true, 'OK', ['data' => $rows]);
}

// ── LIST SECTIONS ─────────────────────────────────────────────
case 'list_sections': {
    $rows = [];
    $res  = $conn->query('SELECT * FROM sections WHERE is_active=1 ORDER BY section_code ASC');
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    respond(true, 'OK', ['data' => $rows]);
}

default:
    respond(false, 'Unknown action.');
}

$conn->close();

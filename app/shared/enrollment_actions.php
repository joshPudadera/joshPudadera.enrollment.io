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

// ── VALIDATE APPLICATION (admin) ──────────────────────────────
case 'validate_application': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);
    $status     = $_POST['status']  ?? '';
    $remarks    = trim($_POST['remarks'] ?? '');
    if (!in_array($status, ['Approved','Rejected'])) respond(false, 'Invalid status.');

    $stmt = $conn->prepare('UPDATE pre_registrations SET status=?, remarks=? WHERE id=?');
    $stmt->bind_param('ssi', $status, $remarks, $pre_reg_id);
    if (!$stmt->execute()) respond(false, $conn->error);
    $stmt->close();

    // ── On Approval: create student account + send login email ──
    if ($status === 'Approved') {
        require_once __DIR__ . '/mailer.php';

        // Fetch applicant details
        $pr = $conn->prepare('SELECT * FROM pre_registrations WHERE id=? LIMIT 1');
        $pr->bind_param('i', $pre_reg_id); $pr->execute();
        $applicant = $pr->get_result()->fetch_assoc();
        $pr->close();

        if ($applicant) {
            $first   = $applicant['first_name'];
            $last    = $applicant['last_name'];
            $email   = $applicant['email'];
            $course  = $applicant['course'];

            // Generate username: firstname.lastname (lowercase, no spaces)
            $base_username = strtolower(preg_replace('/\s+/', '.', trim("$first.$last")));
            $username = $base_username;
            $suffix   = 1;
            // Ensure username is unique
            while (true) {
                $chk = $conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
                $chk->bind_param('s', $username); $chk->execute();
                if ($chk->get_result()->num_rows === 0) break;
                $chk->close();
                $username = $base_username . $suffix++;
            }
            if (isset($chk)) $chk->close();

            // Temporary random password (student will change it via email link)
            $temp_password = bin2hex(random_bytes(8));
            $hash = password_hash($temp_password, PASSWORD_DEFAULT);

            // Check if user already exists for this applicant
            $existing_uid = (int)($applicant['user_id'] ?? 0);
            if (!$existing_uid) {
                // Check if a user with this email already exists (e.g. student registered before)
                $chk_email = $conn->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
                $chk_email->bind_param('s', $email); $chk_email->execute();
                $existing_by_email = $chk_email->get_result()->fetch_assoc();
                $chk_email->close();

                if ($existing_by_email) {
                    // Only reuse if it's a student account — never reuse admin accounts
                    if ($existing_by_email['role'] === 'student') {
                        $new_uid = (int)$existing_by_email['id'];
                        $username = $existing_by_email['username'];
                        // Link pre_registration to the existing student user
                        $upd = $conn->prepare('UPDATE pre_registrations SET user_id=? WHERE id=?');
                        $upd->bind_param('ii', $new_uid, $pre_reg_id);
                        $upd->execute(); $upd->close();
                    } else {
                        // Admin has same email — create a new student account with modified email/username
                        $username = $base_username . '_student';
                        $suffix2  = 1;
                        while (true) {
                            $chk2 = $conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
                            $chk2->bind_param('s', $username); $chk2->execute();
                            if ($chk2->get_result()->num_rows === 0) { $chk2->close(); break; }
                            $chk2->close();
                            $username = $base_username . '_student' . $suffix2++;
                        }
                        // Use a modified email to avoid unique constraint clash
                        $student_email = 'student_' . $email;
                        $ins = $conn->prepare('INSERT INTO users (username,email,first_name,last_name,password_hash,role) VALUES (?,?,?,?,?,\'student\')');
                        $ins->bind_param('sssss', $username, $student_email, $first, $last, $hash);
                        $new_uid = $ins->execute() ? (int)$conn->insert_id : 0;
                        $ins->close();
                        if ($new_uid) {
                            $upd = $conn->prepare('UPDATE pre_registrations SET user_id=? WHERE id=?');
                            $upd->bind_param('ii', $new_uid, $pre_reg_id);
                            $upd->execute(); $upd->close();
                        }
                    }
                } else {
                    $ins = $conn->prepare(
                        'INSERT INTO users (username, email, first_name, last_name, password_hash, role)
                         VALUES (?,?,?,?,?,\'student\')'
                    );
                    $ins->bind_param('sssss', $username, $email, $first, $last, $hash);
                    if ($ins->execute()) {
                        $new_uid = (int)$conn->insert_id;
                        // Link pre_registration to the new user
                        $upd = $conn->prepare('UPDATE pre_registrations SET user_id=? WHERE id=?');
                        $upd->bind_param('ii', $new_uid, $pre_reg_id);
                        $upd->execute(); $upd->close();
                    } else {
                        $new_uid = 0;
                    }
                    $ins->close();
                }
            } else {
                $new_uid = $existing_uid;
            }

            // Generate one-time login token (expires in 72 hours)
            if ($new_uid) {
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+72 hours'));
                $t = $conn->prepare('INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?,?,?)');
                $t->bind_param('iss', $new_uid, $token, $expires);
                $t->execute(); $t->close();

                // Build the login URL
                $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
                $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $login_url = "$protocol://$host/sms/app/auth/login_via_token.php?token=$token";

                // Send welcome email (works if SMTP configured, silent fail otherwise)
                $short_course = preg_replace('/Bachelor of Science in /i', 'BS ', $course);
                $body = email_template(
                    "Your BCP Student Account is Ready!",
                    "<p style='color:#333;font-size:14px;line-height:1.7;'>
                        Dear <strong>{$first} {$last}</strong>,
                     </p>
                     <p style='color:#333;font-size:14px;line-height:1.7;'>
                        Congratulations! Your enrollment application for
                        <strong>{$short_course}</strong> has been <strong style='color:#16a34a;'>approved</strong>.
                        Your student account has been created.
                     </p>
                     <p style='color:#333;font-size:14px;line-height:1.7;'>
                        <strong>Username:</strong> {$username}<br>
                        Click the button below to log in and set your password.
                        This link expires in <strong>72 hours</strong>.
                     </p>",
                    $login_url,
                    "Set My Password &amp; Log In →"
                );
                @send_email($email, 'Your BCP Student Portal Account', $body);

                // Always return the login link so admin can share it if email fails
                respond(true, "Application Approved.", [
                    'login_url'    => $login_url,
                    'username'     => $username,
                    'student_name' => "$first $last",
                ]);
            } else {
                respond(false, 'Application approved but failed to create student account. Check if the email is already registered.');
            }
        }
    }

    respond(true, "Application $status.");
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
        $conn->prepare('UPDATE pre_registrations SET status="Enrolled" WHERE id=?')
             ->bind_param('i', $pre_reg_id) && $conn->query("UPDATE pre_registrations SET status='Enrolled' WHERE id=$pre_reg_id");
        respond(true, 'ID generated.', ['id_number' => $id_num]);
    }
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

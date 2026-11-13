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
$is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'staff']);

function respond(bool $ok, string $msg, array $extra = []): void {
    ob_clean(); // discard any PHP warnings before outputting JSON
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra)); exit;
}

switch ($action) {

// ── PRE-REGISTRATION ─────────────────────────────────────────
case 'pre_register': {
    $first_name     = trim($_POST['first_name']          ?? '');
    $last_name      = trim($_POST['last_name']           ?? '');
    $email          = trim($_POST['email']               ?? '');
    $phone          = trim($_POST['phone']               ?? '');
    $birthday       = trim($_POST['birthday']            ?? '');
    $course         = trim($_POST['course']              ?? '');
    $year_level     = trim($_POST['year_level']          ?? '');
    $prev_school    = trim($_POST['prev_school']         ?? '');
    $applicant_type = trim($_POST['applicant_type']      ?? '');
    $transfer_yr    = trim($_POST['transfer_year_level'] ?? '');

    // Validate applicant_type
    $valid_types = ['Freshman', 'Senior High'];
    if (!$applicant_type || !in_array($applicant_type, $valid_types))
        respond(false, 'Please select an applicant type.');

    if (!$first_name||!$last_name||!$email||!$phone||!$birthday||!$course)
        respond(false, 'All required fields must be filled.');

    // For transferees, transfer_year_level is required
    if ($applicant_type === 'Transferee' && !$transfer_yr)
        respond(false, 'Please specify the year level you are transferring into.');

    // For Freshman, lock year_level to 1st Year
    if ($applicant_type === 'Freshman') $year_level = '1st Year';

    // Ensure new columns exist (safe no-op)
    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS applicant_type ENUM('Freshman','Senior High') DEFAULT NULL");
    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS transfer_year_level VARCHAR(50) DEFAULT NULL");

    // Check for existing pending application
    $chk = $conn->prepare('SELECT id FROM pre_registrations WHERE user_id=? AND status IN ("Pending","Approved") LIMIT 1');
    $chk->bind_param('i', $user_id); $chk->execute();
    if ($chk->get_result()->num_rows > 0) respond(false, 'You already have a pending or approved application.');
    $chk->close();

    $stmt = $conn->prepare(
        'INSERT INTO pre_registrations
            (user_id, first_name, last_name, email, phone, birthday, course, year_level,
             prev_school, applicant_type, transfer_year_level)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->bind_param('issssssssss',
        $user_id, $first_name, $last_name, $email, $phone, $birthday,
        $course, $year_level, $prev_school, $applicant_type, $transfer_yr
    );
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

    if ($status !== 'Approved') {
        log_action($conn, 'application_rejected',
            "Application rejected. Remarks: " . ($remarks ?: 'none'),
            'pre_registration', $pre_reg_id,
            trim(($conn->query("SELECT CONCAT(first_name,' ',last_name) n FROM pre_registrations WHERE id=$pre_reg_id")->fetch_assoc()['n'] ?? ''))
        );
        respond(true, 'Application Rejected.');
    }

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

    // ── Build structured username: fLast#YY0000001 ──────────────
    // Format: first initial + last name (no spaces) + # + 2-digit year + 7-digit sequence
    // Example: John Green → jGreen#260000001
    $year2      = date('y'); // e.g. "26"
    $seq        = str_pad($pre_reg_id, 7, '0', STR_PAD_LEFT); // use pre_reg_id as sequence
    $base_user  = strtolower(substr($first, 0, 1)) . preg_replace('/[^A-Za-z]/', '', $last);
    $username   = $base_user . '#' . $year2 . $seq;

    // ── Build temporary password: #XX8080 (last 2 chars of last name uppercase) ──
    $last_clean  = preg_replace('/[^A-Za-z]/', '', $last);
    $last2       = strtoupper(substr($last_clean, -2, 2));
    $temp_pass   = '#' . $last2 . '8080';
    $hash        = password_hash($temp_pass, PASSWORD_DEFAULT);

    $new_uid = 0;

    // Check existing linked user — reject if admin
    $linked_uid = (int)($applicant['user_id'] ?? 0);
    if ($linked_uid > 0) {
        $rc = $conn->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        $rc->bind_param('i', $linked_uid); $rc->execute();
        $rr = $rc->get_result()->fetch_assoc(); $rc->close();
        if ($rr && $rr['role'] === 'admin') {
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
                $ins = $conn->prepare("INSERT INTO users (username,email,first_name,last_name,password_hash,role,must_change_password) VALUES (?,?,?,?,?,'student',1)");
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
                $upd_user = $conn->prepare('UPDATE users SET first_name=?, last_name=?, username=?, must_change_password=1 WHERE id=?');
                $upd_user->bind_param('sssi', $first, $last, $username, $new_uid);
                $upd_user->execute(); $upd_user->close();
            }

        } elseif ($eu && $eu['role'] === 'admin') {
            // Admin email conflict — prefix to avoid collision
            $alt_email = 'stu' . $pre_reg_id . '.' . $email;
            $ins = $conn->prepare("INSERT INTO users (username,email,first_name,last_name,password_hash,role,must_change_password) VALUES (?,?,?,?,?,'student',1)");
            $ins->bind_param('sssss', $username, $alt_email, $first, $last, $hash);
            $new_uid = $ins->execute() ? (int)$conn->insert_id : 0;
            $ins->close();
        } else {
            // No existing user — create fresh
            $ins = $conn->prepare("INSERT INTO users (username,email,first_name,last_name,password_hash,role,must_change_password) VALUES (?,?,?,?,?,'student',1)");
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

    // ── Resolve APP_URL for signin link in email ──────────────
    $_app_url = '';
    $_env_file = __DIR__ . '/../.env';
    if (file_exists($_env_file)) {
        foreach (file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_ln) {
            $_ln = trim($_ln);
            if ($_ln === '' || $_ln[0] === '#') continue;
            if (str_starts_with($_ln, 'APP_URL=')) { $_app_url = trim(substr($_ln, 8)); break; }
        }
    }
    if (!$_app_url) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $app_root = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/shared/enrollment_actions.php'));
        $app_root = ($app_root === '/' || $app_root === '\\') ? '' : $app_root;
        $_app_url = "$proto://$host$app_root";
    }

    // ── Send credentials email ────────────────────────────────
    $sc   = preg_replace('/Bachelor of Science in /i', 'BS ', $course);
    $body = email_template(
        'Your BCP Student Account is Ready!',
        "<p style='color:#333;font-size:14px;line-height:1.7;'>Dear <strong>$first $last</strong>,</p>
         <p style='color:#333;font-size:14px;line-height:1.7;'>
           Your application for <strong>$sc</strong> has been
           <strong style='color:#16a34a;'>approved</strong>. Your student account has been created.
         </p>
         <p style='color:#333;font-size:14px;line-height:1.7;'>
           <strong>Username:</strong> <code style='background:#f3f4f6;padding:2px 6px;border-radius:4px;'>$username</code><br>
           <strong>Temporary Password:</strong> <code style='background:#f3f4f6;padding:2px 6px;border-radius:4px;'>$temp_pass</code>
         </p>
         <p style='color:#333;font-size:14px;line-height:1.7;'>
           You will be asked to change your password when you first log in.<br>
           Keep this information private and do not share it with anyone.
         </p>",
        rtrim($_app_url ?? '', '/') . '/auth/signin.php', 'Sign In to Student Portal →'
    );

    $email_sent  = false;
    $email_error = '';
    try {
        send_email($email, 'Your BCP Student Portal Account is Ready', $body);
        $email_sent = true;
    } catch (Throwable $e) {
        $email_error = $e->getMessage();
        error_log('[BCP Mailer] Failed to send approval email to ' . $email . ': ' . $email_error);
    }

    // ── Insert into students table (source of truth for the All Students page) ──
    // Only insert if not already present for this pre_registration.
    $dup_chk = $conn->prepare("SELECT id FROM students WHERE pre_reg_id = ? LIMIT 1");
    $dup_chk->bind_param('i', $pre_reg_id);
    $dup_chk->execute();
    $already_in_students = $dup_chk->get_result()->num_rows > 0;
    $dup_chk->close();

    if (!$already_in_students) {
        // Pull the fields we have from pre_registrations;
        // section defaults to 'TBA' until section assignment runs.
        $phone    = $applicant['phone']      ?? '';
        $birthday = $applicant['birthday']   ?? '2000-01-01';
        $yr_lvl   = $applicant['year_level'] ?? '';

        $s_ins = $conn->prepare(
            "INSERT INTO students
                (pre_reg_id, first_name, last_name, birthday, course, year_level, section, phone, status)
             VALUES (?, ?, ?, ?, ?, ?, 'TBA', ?, 'Active')"
        );
        $s_ins->bind_param('issssss',
            $pre_reg_id, $first, $last, $birthday, $course, $yr_lvl, $phone
        );
        $s_ins->execute();
        $s_ins->close();
    }

    // ── Auto-clear cross_status if no name conflicts exist ───────
    // When a student is approved, check if their name matches any
    // other approved/enrolled student or existing student record.
    // If no match → auto-set cross_status='Cleared' so they skip
    // the manual cross-enrollment review and go straight to Grade Assignment.
    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS cross_status ENUM('Unchecked','Flagged','Cleared') NOT NULL DEFAULT 'Unchecked'");

    $fn_esc = $conn->real_escape_string(trim($first));
    $ln_esc = $conn->real_escape_string(trim($last));

    $has_match = false;

    // Check other pre_registrations
    $mc1 = $conn->query(
        "SELECT id FROM pre_registrations
         WHERE id != $pre_reg_id
           AND status IN ('Approved','Enrolled')
           AND LOWER(TRIM(first_name)) = LOWER('$fn_esc')
           AND LOWER(TRIM(last_name))  = LOWER('$ln_esc')
         LIMIT 1"
    );
    if ($mc1 && $mc1->num_rows > 0) $has_match = true;

    // Check students table
    if (!$has_match) {
        $mc2 = $conn->query(
            "SELECT id FROM students
             WHERE LOWER(TRIM(first_name)) = LOWER('$fn_esc')
               AND LOWER(TRIM(last_name))  = LOWER('$ln_esc')
               AND pre_reg_id != $pre_reg_id
             LIMIT 1"
        );
        if ($mc2 && $mc2->num_rows > 0) $has_match = true;
    }

    if (!$has_match) {
        $cleared = 'Cleared';
        $acs = $conn->prepare('UPDATE pre_registrations SET cross_status=? WHERE id=?');
        $acs->bind_param('si', $cleared, $pre_reg_id);
        $acs->execute();
        $acs->close();
    }

    log_action($conn, 'application_approved',
        "Student account created. Username: $username. Email sent: " . ($email_sent ? 'yes' : 'no') . ". Course: $sc.",
        'pre_registration', $pre_reg_id, "$first $last"
    );
    respond(true, 'Application Approved.', [
        'username'     => $username,
        'temp_pass'    => $temp_pass,
        'student_name' => "$first $last",
        'email_sent'   => $email_sent,
        'email_to'     => $email,
        'email_error'  => $email_error,
    ]);
}
case 'generate_id': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);
    $course     = trim($_POST['course']      ?? '');
    $year_level = trim($_POST['year_level']  ?? '');

    if (!$pre_reg_id) respond(false, 'pre_reg_id is required.');

    // ── Gate checks ──────────────────────────────────────────
    // 1. Not already enrolled
    $chk = $conn->prepare('SELECT id FROM enrollments WHERE pre_reg_id=? LIMIT 1');
    $chk->bind_param('i', $pre_reg_id); $chk->execute();
    if ($chk->get_result()->num_rows > 0) respond(false, 'Already enrolled.');
    $chk->close();

    // 2. Fetch pre_reg — must be Approved + grade confirmed
    $pr = $conn->prepare('SELECT * FROM pre_registrations WHERE id=? LIMIT 1');
    $pr->bind_param('i', $pre_reg_id); $pr->execute();
    $pre_reg = $pr->get_result()->fetch_assoc(); $pr->close();
    if (!$pre_reg) respond(false, 'Application not found.');
    if ($pre_reg['status'] !== 'Approved') respond(false, 'Student application is not approved.');
    if (!(int)($pre_reg['grade_confirmed'] ?? 0)) respond(false, 'Grade level must be confirmed before generating an ID.');
    // Section is NOT required here — section assignment happens after ID generation.

    // Resolve final year level and course
    $final_year   = $pre_reg['confirmed_year_level'] ?: ($year_level ?: $pre_reg['year_level']);
    $final_course = $course ?: $pre_reg['course'];

    // ── Build ID: BCP-YY-XXXXX ───────────────────────────────
    $year   = date('y');
    $count  = $conn->query("SELECT COUNT(*)+1 AS n FROM enrollments")->fetch_assoc()['n'];
    $id_num = 'BCP-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);

    // Section starts as TBA — filled in by Section Assignment (Step 7)
    $tba = 'TBA';
    $stmt = $conn->prepare(
        'INSERT INTO enrollments (pre_reg_id, id_number, course, year_level, section, validated_by, validated_at)
         VALUES (?,?,?,?,?,?,NOW())'
    );
    $stmt->bind_param('issssi', $pre_reg_id, $id_num, $final_course, $final_year, $tba, $user_id);
    if ($stmt->execute()) {
        $stmt->close();

        // Update pre_registration status to Enrolled
        $enrolled_status = 'Enrolled';
        $upd = $conn->prepare('UPDATE pre_registrations SET status=? WHERE id=?');
        $upd->bind_param('si', $enrolled_status, $pre_reg_id);
        $upd->execute();
        $upd->close();

        // Sync students year_level; leave section as-is (still TBA until Step 7)
        $sync = $conn->prepare('UPDATE students SET year_level=? WHERE pre_reg_id=?');
        $sync->bind_param('si', $final_year, $pre_reg_id);
        $sync->execute();
        $sync->close();

        // ── Auto-send to external system ──────────────────────────────
        // Fires at ID generation time — section may still be TBA but the
        // student record is now official. Failure does not block the response.
        $send_script = __DIR__ . '/../../api/send_students.php';
        if (file_exists($send_script)) {
            require_once $send_script;
            $send_result = send_student_to_external((int)$pre_reg_id, $conn);
            if (!$send_result['success']) {
                error_log('[BCP API] send_student_to_external failed for pre_reg_id=' . $pre_reg_id . ': ' . ($send_result['error'] ?? 'unknown'));
            }
        }

        log_action($conn, 'id_generated',
            "Student ID $id_num generated. Course: $final_course, Year: $final_year.",
            'enrollment', $pre_reg_id,
            trim($pre_reg['first_name'] . ' ' . $pre_reg['last_name'])
        );
        respond(true, 'ID generated.', ['id_number' => $id_num]);
    }
    $stmt->close();
    respond(false, $conn->error);
}

// ── ASSIGN SECTION (admin) ────────────────────────────────────
// Step 7: Student already has an enrollment row (ID generated at Step 6).
// Update enrollments.section and sync students.section.
case 'assign_section': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
    $section_code  = trim($_POST['section_code']   ?? '');
    if (!$section_code) respond(false, 'Section code is required.');

    // enrollment_id is the enrollments.id (student has ID from Step 6)
    $chk_enr = $conn->prepare('SELECT id, pre_reg_id FROM enrollments WHERE id=? LIMIT 1');
    $chk_enr->bind_param('i', $enrollment_id);
    $chk_enr->execute();
    $enr_row = $chk_enr->get_result()->fetch_assoc();
    $chk_enr->close();
    if (!$enr_row) respond(false, 'Enrollment record not found. ID must be generated first.');

    $pre_reg_id = (int)$enr_row['pre_reg_id'];

    // Get section and check capacity
    $sec = $conn->query(
        "SELECT * FROM sections WHERE section_code='" .
        $conn->real_escape_string($section_code) . "' LIMIT 1"
    )->fetch_assoc();
    if (!$sec) respond(false, 'Section not found.');

    // Count students in this section from enrollments (source of truth after Step 6)
    $actual = (int)$conn->query(
        "SELECT COUNT(*) c FROM enrollments WHERE section='" .
        $conn->real_escape_string($section_code) . "'"
    )->fetch_assoc()['c'];

    if ($actual >= $sec['max_capacity']) {
        respond(false, 'Section is full. Student remains in waiting list.');
    }

    // Assign section in enrollments row (now exists from ID generation)
    $upd_enr = $conn->prepare('UPDATE enrollments SET section=? WHERE id=?');
    $upd_enr->bind_param('si', $section_code, $enrollment_id);
    if (!$upd_enr->execute()) respond(false, $conn->error);
    $upd_enr->close();

    // Sync students.section
    $sync = $conn->prepare('UPDATE students SET section=? WHERE pre_reg_id=?');
    $sync->bind_param('si', $section_code, $pre_reg_id);
    $sync->execute();
    $sync->close();

    // Sync sections.current_count
    $conn->query(
        "UPDATE sections SET current_count=(
            SELECT COUNT(*) FROM enrollments WHERE section='" .
            $conn->real_escape_string($section_code) . "'
        ) WHERE section_code='" . $conn->real_escape_string($section_code) . "'"
    );

    // Mark student as Promoted in waiting list
    $promoted = 'Promoted';
    $upd_wl = $conn->prepare(
        "UPDATE waiting_list SET status=? WHERE pre_reg_id=? AND status='Waiting'"
    );
    $upd_wl->bind_param('si', $promoted, $pre_reg_id);
    $upd_wl->execute();
    $upd_wl->close();

    log_action($conn, 'section_assigned',
        "Section $section_code assigned.",
        'enrollment', $pre_reg_id,
        (function() use ($conn, $pre_reg_id) {
            $r = $conn->query("SELECT CONCAT(first_name,' ',last_name) n FROM pre_registrations WHERE id=$pre_reg_id LIMIT 1");
            return $r ? ($r->fetch_assoc()['n'] ?? '') : '';
        })()
    );
    respond(true, "Section $section_code assigned.");
}

// ── ASSIGN GRADE LEVEL (admin) ────────────────────────────────
// Works on pre_registrations directly — no enrollment row required yet.
case 'assign_grade': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);
    $year_level = trim($_POST['year_level']   ?? '');
    if (!$pre_reg_id) respond(false, 'pre_reg_id is required.');
    if (!$year_level) respond(false, 'Year level is required.');

    // ── Gate: student must be Cleared in cross-enrollment ──────
    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS cross_status ENUM('Unchecked','Flagged','Cleared') NOT NULL DEFAULT 'Unchecked'");
    $gate = $conn->prepare('SELECT cross_status FROM pre_registrations WHERE id=? LIMIT 1');
    $gate->bind_param('i', $pre_reg_id);
    $gate->execute();
    $gate_row = $gate->get_result()->fetch_assoc();
    $gate->close();
    if (!$gate_row) respond(false, 'Application not found.');
    if ($gate_row['cross_status'] !== 'Cleared') {
        respond(false, 'Student must be cleared in Cross Enrollment before grade assignment. Current status: ' . $gate_row['cross_status']);
    }

    // Ensure columns exist (idempotent)
    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS grade_confirmed TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS confirmed_year_level VARCHAR(50) DEFAULT NULL");

    // Confirm grade level on pre_registrations
    $stmt = $conn->prepare(
        'UPDATE pre_registrations SET grade_confirmed=1, confirmed_year_level=? WHERE id=?'
    );
    $stmt->bind_param('si', $year_level, $pre_reg_id);
    if (!$stmt->execute()) respond(false, $conn->error);
    $stmt->close();

    // Also sync students table year_level
    $sync = $conn->prepare('UPDATE students SET year_level=? WHERE pre_reg_id=?');
    $sync->bind_param('si', $year_level, $pre_reg_id);
    $sync->execute();
    $sync->close();

    // Update waiting list if already in queue
    $new_reason = 'Awaiting section assignment';
    $upd_wl = $conn->prepare(
        "UPDATE waiting_list SET year_level=?, reason=? WHERE pre_reg_id=? AND status='Waiting'"
    );
    $upd_wl->bind_param('ssi', $year_level, $new_reason, $pre_reg_id);
    $upd_wl->execute();
    $upd_wl->close();

    log_action($conn, 'grade_confirmed',
        "Year level confirmed as: $year_level.",
        'pre_registration', $pre_reg_id,
        (function() use ($conn, $pre_reg_id) {
            $r = $conn->query("SELECT CONCAT(first_name,' ',last_name) n FROM pre_registrations WHERE id=$pre_reg_id LIMIT 1");
            return $r ? ($r->fetch_assoc()['n'] ?? '') : '';
        })()
    );
    respond(true, 'Grade level confirmed.');
}

// ── CROSS ENROLLMENT — FLAG (admin) ──────────────────────────
// Blocks the student from Grade Assignment until cleared.
case 'flag_cross': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);
    $reason     = trim($_POST['reason']      ?? '');
    if (!$pre_reg_id) respond(false, 'pre_reg_id is required.');

    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS cross_status ENUM('Unchecked','Flagged','Cleared') NOT NULL DEFAULT 'Unchecked'");
    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS cross_flag_reason VARCHAR(255) DEFAULT NULL");

    $flagged = 'Flagged';
    $stmt = $conn->prepare('UPDATE pre_registrations SET cross_status=?, cross_flag_reason=? WHERE id=?');
    $stmt->bind_param('ssi', $flagged, $reason, $pre_reg_id);
    if ($stmt->execute()) {
        $pr_n = $conn->query("SELECT CONCAT(first_name,' ',last_name) n FROM pre_registrations WHERE id=$pre_reg_id LIMIT 1");
        $nm = $pr_n ? ($pr_n->fetch_assoc()['n'] ?? '') : '';
        log_action($conn, 'cross_enrollment_flagged',
            "Flagged for duplicate name review." . ($reason ? " Note: $reason" : ''),
            'pre_registration', $pre_reg_id, $nm
        );
        respond(true, 'Student flagged and blocked from Grade Assignment.');
    }
    respond(false, $conn->error);
}

// ── CROSS ENROLLMENT — CLEAR (admin) ─────────────────────────
// Allows the student to proceed to Grade Assignment.
case 'clear_cross': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);
    if (!$pre_reg_id) respond(false, 'pre_reg_id is required.');

    @$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS cross_status ENUM('Unchecked','Flagged','Cleared') NOT NULL DEFAULT 'Unchecked'");

    $cleared = 'Cleared';
    $stmt = $conn->prepare('UPDATE pre_registrations SET cross_status=? WHERE id=?');
    $stmt->bind_param('si', $cleared, $pre_reg_id);
    if ($stmt->execute()) {
        $stmt->close();
        $conn->query("UPDATE waiting_list SET status='Resolved' WHERE pre_reg_id=$pre_reg_id AND status='Waiting'");
        $pr_n = $conn->query("SELECT CONCAT(first_name,' ',last_name) n FROM pre_registrations WHERE id=$pre_reg_id LIMIT 1");
        $nm = $pr_n ? ($pr_n->fetch_assoc()['n'] ?? '') : '';
        log_action($conn, 'cross_enrollment_cleared',
            "Cleared — allowed to proceed to Grade Assignment.",
            'pre_registration', $pre_reg_id, $nm
        );
        respond(true, 'Student cleared — can proceed to Grade Assignment.');
    }
    respond(false, $conn->error);
}

// ── CROSS ENROLLMENT (legacy — mark cross-enrolled institution) ──
case 'cross_enroll': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
    $cross_from    = trim($_POST['cross_from']      ?? '');
    $stmt = $conn->prepare('UPDATE enrollments SET is_cross=1, cross_from=? WHERE id=?');
    $stmt->bind_param('si', $cross_from, $enrollment_id);
    if ($stmt->execute()) respond(true, 'Marked as cross-enrolled.');
    respond(false, $conn->error);
}

// ── PROMOTE FROM WAITING LIST (admin) ────────────────────────
case 'promote_waiting': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $waiting_id    = (int)($_POST['waiting_id']    ?? 0);
    $section_code  = trim($_POST['section_code']   ?? '');
    if (!$waiting_id || !$section_code) respond(false, 'Missing parameters.');

    // Get waiting entry
    $wl = $conn->prepare('SELECT * FROM waiting_list WHERE id=? AND status=\'Waiting\' LIMIT 1');
    $wl->bind_param('i', $waiting_id);
    $wl->execute();
    $wl_row = $wl->get_result()->fetch_assoc();
    $wl->close();
    if (!$wl_row) respond(false, 'Waiting entry not found or already processed.');

    $pre_reg_id_wl = (int)$wl_row['pre_reg_id'];

    // Gate: grade must be confirmed (check pre_registrations)
    $pr_chk = $conn->prepare('SELECT grade_confirmed FROM pre_registrations WHERE id=? LIMIT 1');
    $pr_chk->bind_param('i', $pre_reg_id_wl);
    $pr_chk->execute();
    $pr_chk_row = $pr_chk->get_result()->fetch_assoc();
    $pr_chk->close();
    if (!$pr_chk_row || !$pr_chk_row['grade_confirmed']) respond(false, 'Grade level must be confirmed first.');

    // Check section capacity
    $sec = $conn->query(
        "SELECT * FROM sections WHERE section_code='" .
        $conn->real_escape_string($section_code) . "' LIMIT 1"
    )->fetch_assoc();
    if (!$sec) respond(false, 'Section not found.');
    $actual = (int)$conn->query(
        "SELECT COUNT(*) c FROM students WHERE section='" .
        $conn->real_escape_string($section_code) . "'"
    )->fetch_assoc()['c'];
    if ($actual >= $sec['max_capacity']) respond(false, 'Section is still full.');

    // Assign section in enrollments row (exists since ID was generated at Step 6)
    $upd_enr = $conn->prepare('UPDATE enrollments SET section=? WHERE pre_reg_id=?');
    $upd_enr->bind_param('si', $section_code, $pre_reg_id_wl);
    $upd_enr->execute();
    $upd_enr->close();

    // Also sync students.section
    $sync = $conn->prepare('UPDATE students SET section=? WHERE pre_reg_id=?');
    $sync->bind_param('si', $section_code, $pre_reg_id_wl);
    $sync->execute();
    $sync->close();

    // Sync sections.current_count
    $conn->query(
        "UPDATE sections SET current_count=(
            SELECT COUNT(*) FROM enrollments WHERE section='" .
            $conn->real_escape_string($section_code) . "'
         ) WHERE section_code='" . $conn->real_escape_string($section_code) . "'"
    );

    // Mark waiting entry as Promoted
    $pst = $conn->prepare("UPDATE waiting_list SET status='Promoted' WHERE id=?");
    $pst->bind_param('i', $waiting_id);
    $pst->execute();
    $pst->close();

    // Re-number remaining queue for this course/year
    $remaining = [];
    $rq = $conn->query(
        "SELECT id FROM waiting_list
         WHERE course='" . $conn->real_escape_string($wl_row['course']) . "'
           AND year_level='" . $conn->real_escape_string($wl_row['year_level']) . "'
           AND status='Waiting'
         ORDER BY queue_position ASC"
    );
    if ($rq) while ($r = $rq->fetch_assoc()) $remaining[] = $r['id'];
    foreach ($remaining as $pos => $rid) {
        $conn->query("UPDATE waiting_list SET queue_position=" . ($pos + 1) . " WHERE id=$rid");
    }

    respond(true, "Student promoted and assigned to section $section_code.");
}

// ── CANCEL WAITING LIST ENTRY (admin) ─────────────────────────
case 'cancel_waiting': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $waiting_id = (int)($_POST['waiting_id'] ?? 0);
    if (!$waiting_id) respond(false, 'Missing waiting_id.');

    $wl = $conn->prepare('SELECT course, year_level FROM waiting_list WHERE id=? LIMIT 1');
    $wl->bind_param('i', $waiting_id);
    $wl->execute();
    $wl_row = $wl->get_result()->fetch_assoc();
    $wl->close();
    if (!$wl_row) respond(false, 'Entry not found.');

    $upd = $conn->prepare("UPDATE waiting_list SET status='Cancelled' WHERE id=?");
    $upd->bind_param('i', $waiting_id);
    $upd->execute();
    $upd->close();

    // Re-number remaining queue
    $remaining = [];
    $rq = $conn->query(
        "SELECT id FROM waiting_list
         WHERE course='" . $conn->real_escape_string($wl_row['course']) . "'
           AND year_level='" . $conn->real_escape_string($wl_row['year_level']) . "'
           AND status='Waiting'
         ORDER BY queue_position ASC"
    );
    if ($rq) while ($r = $rq->fetch_assoc()) $remaining[] = $r['id'];
    foreach ($remaining as $pos => $rid) {
        $conn->query("UPDATE waiting_list SET queue_position=" . ($pos + 1) . " WHERE id=$rid");
    }

    respond(true, 'Waiting list entry cancelled.');
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

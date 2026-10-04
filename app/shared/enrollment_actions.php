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
    // Also respect reverse-proxy header (Nginx / Cloudflare / shared hosting)
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $protocol = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    }
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Prefer APP_URL from .env; fall back to deriving from SCRIPT_NAME
    $_app_url  = '';

    $_env_file = __DIR__ . '/../.env';
    if (file_exists($_env_file)) {
        foreach (file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
            $_line = trim($_line);
            if ($_line === '' || $_line[0] === '#') continue;
            if (str_starts_with($_line, 'APP_URL=')) { $_app_url = trim(substr($_line, 8)); break; }
        }
    }
    if (!$_app_url) {
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/shared/enrollment_actions.php');
        $app_root   = dirname($script_dir);
        $app_root   = ($app_root === '/' || $app_root === '\\') ? '' : $app_root;
        $_app_url   = "$protocol://$host{$app_root}";
    }
    $login_url  = rtrim($_app_url, '/') . '/auth/login_via_token.php?token=' . $token;

    // Send welcome email with one-time login link
    $sc   = preg_replace('/Bachelor of Science in /i', 'BS ', $course);
    $body = email_template(
        'Your BCP Student Account is Ready!',
        "<p style='color:#333;font-size:14px;line-height:1.7;'>Dear <strong>$first $last</strong>,</p>
         <p style='color:#333;font-size:14px;line-height:1.7;'>Your application for <strong>$sc</strong> has been <strong style='color:#16a34a;'>approved</strong>. Your student account is ready.</p>
         <p style='color:#333;font-size:14px;line-height:1.7;'><strong>Username:</strong> $username<br>Click the button below to set your password. This link expires in <strong>72 hours</strong> and can only be used once.</p>",
        $login_url, 'Set My Password &amp; Log In →'
    );

    $email_sent  = false;
    $email_error = '';
    try {
        send_email($email, 'Your BCP Student Portal Account is Ready', $body);
        $email_sent = true;
    } catch (Throwable $e) {
        // Log the reason but don't abort — admin still gets the link to share manually
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

    respond(true, 'Application Approved.', [
        'login_url'    => $login_url,
        'username'     => $username,
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

    // Ensure grade_confirmed column exists (added in pipeline gate update)
    @$conn->query("ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS grade_confirmed TINYINT(1) NOT NULL DEFAULT 0");

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
        $enrollment_id = (int)$conn->insert_id;
        $stmt->close();

        // Update pre_registration status to Enrolled
        $enrolled_status = 'Enrolled';
        $upd = $conn->prepare('UPDATE pre_registrations SET status=? WHERE id=?');
        $upd->bind_param('si', $enrolled_status, $pre_reg_id);
        $upd->execute();
        $upd->close();

        // ── Auto-insert into waiting list ──────────────────────────────
        // Every newly enrolled student starts in the waiting queue
        // (section is NULL). They advance to section assignment only
        // after grade level is confirmed (grade_confirmed = 1).
        $pos_res = $conn->query(
            "SELECT COUNT(*)+1 AS n FROM waiting_list
             WHERE course='" . $conn->real_escape_string($course) . "'
               AND year_level='" . $conn->real_escape_string($year_level) . "'
               AND status='Waiting'"
        );
        $queue_pos = $pos_res ? (int)$pos_res->fetch_assoc()['n'] : 1;

        $wl = $conn->prepare(
            'INSERT IGNORE INTO waiting_list (pre_reg_id, course, year_level, queue_position, reason, status)
             VALUES (?, ?, ?, ?, \'Awaiting grade level confirmation\', \'Waiting\')'
        );
        $wl->bind_param('issi', $pre_reg_id, $course, $year_level, $queue_pos);
        $wl->execute();
        $wl->close();

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

    // Gate: grade must be confirmed before section can be assigned
    $gate = $conn->prepare('SELECT grade_confirmed, pre_reg_id, course, year_level FROM enrollments WHERE id=? LIMIT 1');
    $gate->bind_param('i', $enrollment_id);
    $gate->execute();
    $enr_row = $gate->get_result()->fetch_assoc();
    $gate->close();
    if (!$enr_row) respond(false, 'Enrollment record not found.');
    if (!$enr_row['grade_confirmed']) respond(false, 'Grade level must be confirmed before assigning a section.');

    // Get section and check capacity
    $sec = $conn->query(
        "SELECT * FROM sections WHERE section_code='" .
        $conn->real_escape_string($section_code) . "' LIMIT 1"
    )->fetch_assoc();
    if (!$sec) respond(false, 'Section not found.');

    // Count real students in this section
    $actual = (int)$conn->query(
        "SELECT COUNT(*) c FROM enrollments WHERE section='" .
        $conn->real_escape_string($section_code) . "'"
    )->fetch_assoc()['c'];

    if ($actual >= $sec['max_capacity']) {
        // Section full — update waiting list reason
        $upd_wl = $conn->prepare(
            "UPDATE waiting_list SET reason='Section full — waiting for slot'
             WHERE pre_reg_id=? AND status='Waiting'"
        );
        $upd_wl->bind_param('i', $enr_row['pre_reg_id']);
        $upd_wl->execute();
        $upd_wl->close();
        respond(false, 'Section is full. Student remains in waiting list.');
    }

    // Assign the section
    $stmt = $conn->prepare('UPDATE enrollments SET section=? WHERE id=?');
    $stmt->bind_param('si', $section_code, $enrollment_id);
    if (!$stmt->execute()) respond(false, $conn->error);
    $stmt->close();

    // Sync current_count
    $conn->query(
        "UPDATE sections SET current_count=(
            SELECT COUNT(*) FROM enrollments WHERE section='" .
            $conn->real_escape_string($section_code) . "'
        ) WHERE section_code='" . $conn->real_escape_string($section_code) . "'"
    );

    // Mark student as Promoted in waiting list (section assigned = done)
    $promoted = 'Promoted';
    $upd_wl = $conn->prepare(
        "UPDATE waiting_list SET status=? WHERE pre_reg_id=? AND status='Waiting'"
    );
    $upd_wl->bind_param('si', $promoted, $enr_row['pre_reg_id']);
    $upd_wl->execute();
    $upd_wl->close();

    // Sync students.section so the All Students page stays up to date
    $sync = $conn->prepare(
        'UPDATE students SET section=? WHERE pre_reg_id=?'
    );
    $sync->bind_param('si', $section_code, $enr_row['pre_reg_id']);
    $sync->execute();
    $sync->close();

    respond(true, "Section $section_code assigned.");
}

// ── ASSIGN GRADE LEVEL (admin) ────────────────────────────────
case 'assign_grade': {
    if (!$is_admin) respond(false, 'Unauthorized.');
    $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
    $year_level    = trim($_POST['year_level']      ?? '');
    if (!$year_level) respond(false, 'Year level is required.');

    // Confirm grade level and mark step as done
    $stmt = $conn->prepare(
        'UPDATE enrollments SET year_level=?, grade_confirmed=1 WHERE id=?'
    );
    $stmt->bind_param('si', $year_level, $enrollment_id);
    if (!$stmt->execute()) respond(false, $conn->error);
    $stmt->close();

    // Update waiting list reason to reflect readiness for cross/section check
    $enr = $conn->prepare('SELECT pre_reg_id, course FROM enrollments WHERE id=? LIMIT 1');
    $enr->bind_param('i', $enrollment_id);
    $enr->execute();
    $enr_row = $enr->get_result()->fetch_assoc();
    $enr->close();

    if ($enr_row) {
        $new_reason = 'Awaiting section assignment';
        $upd_wl = $conn->prepare(
            'UPDATE waiting_list SET year_level=?, reason=?
             WHERE pre_reg_id=? AND status=\'Waiting\''
        );
        $upd_wl->bind_param('ssi', $year_level, $new_reason, $enr_row['pre_reg_id']);
        $upd_wl->execute();
        $upd_wl->close();
    }

    respond(true, 'Grade level confirmed.');
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

    // Get enrollment for this pre_reg_id
    $enr = $conn->prepare('SELECT id, grade_confirmed FROM enrollments WHERE pre_reg_id=? LIMIT 1');
    $enr->bind_param('i', $wl_row['pre_reg_id']);
    $enr->execute();
    $enr_row = $enr->get_result()->fetch_assoc();
    $enr->close();
    if (!$enr_row) respond(false, 'Enrollment record not found.');
    if (!$enr_row['grade_confirmed']) respond(false, 'Grade level must be confirmed first.');

    // Check section capacity
    $sec = $conn->query(
        "SELECT * FROM sections WHERE section_code='" .
        $conn->real_escape_string($section_code) . "' LIMIT 1"
    )->fetch_assoc();
    if (!$sec) respond(false, 'Section not found.');
    $actual = (int)$conn->query(
        "SELECT COUNT(*) c FROM enrollments WHERE section='" .
        $conn->real_escape_string($section_code) . "'"
    )->fetch_assoc()['c'];
    if ($actual >= $sec['max_capacity']) respond(false, 'Section is still full.');

    // Assign section in enrollments
    $upd = $conn->prepare('UPDATE enrollments SET section=? WHERE id=?');
    $upd->bind_param('si', $section_code, $enr_row['id']);
    $upd->execute();
    $upd->close();

    // Sync sections.current_count
    $conn->query(
        "UPDATE sections SET current_count=(
            SELECT COUNT(*) FROM enrollments WHERE section='" .
            $conn->real_escape_string($section_code) . "'
         ) WHERE section_code='" . $conn->real_escape_string($section_code) . "'"
    );

    // Mark waiting entry as Promoted
    $conn->prepare("UPDATE waiting_list SET status='Promoted' WHERE id=?")->execute()
        || respond(false, $conn->error);
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

    // Sync students.section
    $sync = $conn->prepare('UPDATE students SET section=? WHERE pre_reg_id=?');
    $sync->bind_param('si', $section_code, $wl_row['pre_reg_id']);
    $sync->execute();
    $sync->close();

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

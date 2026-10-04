<?php
// ============================================================
//  STUDENT_ACTIONS.PHP  (shared/)
//  Handles all student CRUD operations from dashboard.js.
// ============================================================
ob_start();
session_start();
require_once __DIR__ . '/db.php';
ob_clean();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'add':
        $first_name = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
        $last_name  = trim($conn->real_escape_string($_POST['last_name']  ?? ''));
        $birthday   = trim($conn->real_escape_string($_POST['birthday']   ?? ''));
        $course     = trim($conn->real_escape_string($_POST['course']     ?? ''));
        $year_level = trim($conn->real_escape_string($_POST['year_level'] ?? ''));
        $section    = trim($conn->real_escape_string($_POST['section']    ?? ''));
        $phone      = trim($conn->real_escape_string($_POST['phone']      ?? ''));
        $status     = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if (!$first_name || !$last_name || !$birthday || !$course || !$year_level || !$section || !$phone) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            break;
        }
        $sql = "INSERT INTO students (first_name,last_name,birthday,course,year_level,section,phone,status)
                VALUES ('$first_name','$last_name','$birthday','$course','$year_level','$section','$phone','$status')";
        if ($conn->query($sql)) {
            echo json_encode(['success' => true, 'message' => 'Student added.', 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); break; }
        $result = $conn->query(
            "SELECT s.*,
                    p.ref_number, p.status AS app_status,
                    e.id_number, e.year_level AS enr_year_level,
                    e.section AS enr_section, e.grade_confirmed,
                    e.is_cross, e.enrolled_at
             FROM students s
             LEFT JOIN pre_registrations p ON s.pre_reg_id = p.id
             LEFT JOIN enrollments e ON e.pre_reg_id = s.pre_reg_id
             WHERE s.id = $id LIMIT 1"
        );
        $student = $result ? $result->fetch_assoc() : null;
        if ($student) {
            // Overlay live enrollment values
            $student['year_level'] = $student['enr_year_level'] ?: $student['year_level'];
            $student['section']    = $student['enr_section']    ?: $student['section'];
            echo json_encode(['success' => true, 'student' => $student]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
        }
        break;

    case 'get_sections':
        // Returns active sections for a given course, with capacity info
        $course_q = trim($_GET['course'] ?? '');
        if (!$course_q) {
            echo json_encode(['success' => false, 'message' => 'Course required.']); break;
        }
        $esc = $conn->real_escape_string($course_q);
        $res = $conn->query(
            "SELECT s.section_code, s.year_level, s.max_capacity,
                    COUNT(e.id) AS actual_count
             FROM sections s
             LEFT JOIN enrollments e ON e.section = s.section_code
             WHERE s.course = '$esc' AND s.is_active = 1
             GROUP BY s.section_code, s.year_level, s.max_capacity
             ORDER BY s.year_level ASC, s.section_code ASC"
        );
        $sections = [];
        if ($res) while ($r = $res->fetch_assoc()) $sections[] = $r;
        echo json_encode(['success' => true, 'sections' => $sections]);
        break;

    case 'edit':
        $id         = (int)($_POST['id'] ?? 0);
        $first_name = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
        $last_name  = trim($conn->real_escape_string($_POST['last_name']  ?? ''));
        $birthday   = trim($conn->real_escape_string($_POST['birthday']   ?? ''));
        $course     = trim($conn->real_escape_string($_POST['course']     ?? ''));
        $year_level = trim($conn->real_escape_string($_POST['year_level'] ?? ''));
        $section    = trim($conn->real_escape_string($_POST['section']    ?? ''));
        $phone      = trim($conn->real_escape_string($_POST['phone']      ?? ''));
        $status     = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($id <= 0 || !$first_name || !$last_name || !$birthday || !$course || !$year_level || !$phone) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']); break;
        }

        // 'TBA' means place/keep in waiting list — treat as waiting
        $section_val = ($section === '' || $section === 'TBA' || $section === 'waiting') ? 'TBA' : $section;

        // Update students table
        $sql = "UPDATE students SET first_name='$first_name',last_name='$last_name',
                birthday='$birthday',course='$course',year_level='$year_level',
                section='$section_val',phone='$phone',status='$status'
                WHERE id=$id";
        if (!$conn->query($sql)) {
            echo json_encode(['success' => false, 'message' => $conn->error]); break;
        }

        // Sync enrollments.section if a matching enrollment record exists
        $pre = $conn->query("SELECT pre_reg_id FROM students WHERE id=$id LIMIT 1")->fetch_assoc();
        if ($pre && $pre['pre_reg_id']) {
            $pid = (int)$pre['pre_reg_id'];
            $conn->query("UPDATE enrollments SET section='$section_val' WHERE pre_reg_id=$pid");

            // If being placed back in waiting list, ensure waiting_list entry exists/is re-opened
            if ($section_val === 'TBA') {
                $wl_check = $conn->query("SELECT id,status FROM waiting_list WHERE pre_reg_id=$pid LIMIT 1");
                if ($wl_check && $wl_row = $wl_check->fetch_assoc()) {
                    if ($wl_row['status'] !== 'Waiting') {
                        $conn->query("UPDATE waiting_list SET status='Waiting' WHERE pre_reg_id=$pid");
                    }
                } else {
                    // No waiting list entry — create one
                    $pos_r = $conn->query("SELECT COUNT(*)+1 n FROM waiting_list WHERE status='Waiting'");
                    $pos   = $pos_r ? (int)$pos_r->fetch_assoc()['n'] : 1;
                    $conn->query("INSERT IGNORE INTO waiting_list (pre_reg_id,course,year_level,queue_position,reason,status)
                                  VALUES ($pid,'$course','$year_level',$pos,'Reassigned to waiting list','Waiting')");
                }
            } else {
                // Being assigned to a real section — mark waiting list as Promoted
                $conn->query("UPDATE waiting_list SET status='Promoted' WHERE pre_reg_id=$pid AND status='Waiting'");
                // Sync sections.current_count
                $conn->query("UPDATE sections SET current_count=(SELECT COUNT(*) FROM enrollments WHERE section='$section_val') WHERE section_code='$section_val'");
            }
        }

        echo json_encode(['success' => true, 'message' => 'Student updated.']);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); break; }
        echo $conn->query("DELETE FROM students WHERE id = $id")
            ? json_encode(['success' => true, 'message' => 'Student deleted.'])
            : json_encode(['success' => false, 'message' => $conn->error]);
        break;

    case 'bulk_delete':
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'No IDs provided.']); break; }
        $list = implode(',', $ids);
        echo $conn->query("DELETE FROM students WHERE id IN ($list)")
            ? json_encode(['success' => true, 'message' => count($ids) . ' student(s) deleted.'])
            : json_encode(['success' => false, 'message' => $conn->error]);
        break;

    case 'bulk_status':
        $ids    = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'No IDs provided.']); break; }
        $list = implode(',', $ids);
        echo $conn->query("UPDATE students SET status='$status' WHERE id IN ($list)")
            ? json_encode(['success' => true, 'message' => count($ids) . " student(s) set to $status."])
            : json_encode(['success' => false, 'message' => $conn->error]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}

$conn->close();
?>

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

        if ($id <= 0 || !$first_name || !$last_name || !$birthday || !$course || !$year_level || !$section || !$phone) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']); break;
        }
        $sql = "UPDATE students SET first_name='$first_name',last_name='$last_name',birthday='$birthday',
                course='$course',year_level='$year_level',section='$section',phone='$phone',status='$status'
                WHERE id=$id";
        echo $conn->query($sql)
            ? json_encode(['success' => true, 'message' => 'Student updated.'])
            : json_encode(['success' => false, 'message' => $conn->error]);
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

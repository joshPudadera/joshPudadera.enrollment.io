<?php
// ============================================================
//  dashboard_stats.php
//  Lightweight JSON endpoint polled by the admin dashboard
//  every 15 seconds to keep stat cards and recent apps live.
//
//  Returns:
//    stats        — the 6 numeric counters shown on the cards
//    recent_apps  — last 5 pre-registrations (HTML rows)
//    notif        — badge counts for the notification panel
//    pipeline     — step done/pending state
// ============================================================
session_start();
require_once __DIR__ . '/db.php';

// Auth guard — only admin, and only XHR/JSON requests
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── 1. Core student counts ───────────────────────────────────
$total_students = $active_students = 0;
$r = $conn->query("SELECT COUNT(*) c FROM students");
if ($r) $total_students  = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM students WHERE status='Active'");
if ($r) $active_students = (int)$r->fetch_assoc()['c'];
$inactive_students = $total_students - $active_students;

// ── 2. Enrollment counts ─────────────────────────────────────
$pending_enr = $approved_enr = $enrolled_count = $rejected_enr = $waiting_count = 0;
$has_prereg = $conn->query("SHOW TABLES LIKE 'pre_registrations'")->num_rows > 0;

if ($has_prereg) {
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Pending'");
    if ($r) $pending_enr   = (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Approved'");
    if ($r) $approved_enr  = (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Enrolled'");
    if ($r) $enrolled_count = (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Rejected'");
    if ($r) $rejected_enr  = (int)$r->fetch_assoc()['c'];

    $has_wl = $conn->query("SHOW TABLES LIKE 'waiting_list'")->num_rows > 0;
    if ($has_wl) {
        $r = $conn->query("SELECT COUNT(*) c FROM waiting_list WHERE status='Waiting'");
        if ($r) $waiting_count = (int)$r->fetch_assoc()['c'];
    }
}

$total_prereg_all = $pending_enr + $approved_enr + $enrolled_count;

// ── 3. Section count ─────────────────────────────────────────
$total_sections = 0;
$has_sections = $conn->query("SHOW TABLES LIKE 'sections'")->num_rows > 0;
if ($has_sections) {
    $r = $conn->query("SELECT COUNT(*) c FROM sections WHERE is_active=1");
    if ($r) $total_sections = (int)$r->fetch_assoc()['c'];
}

// ── 4. Recent applications — pre-rendered HTML rows ──────────
$recent_rows_html = '';
if ($has_prereg) {
    $res = $conn->query(
        "SELECT first_name, last_name, course, status, submitted_at
         FROM pre_registrations
         ORDER BY submitted_at DESC
         LIMIT 5"
    );
    if ($res) {
        while ($app = $res->fetch_assoc()) {
            $name   = htmlspecialchars($app['first_name'] . ' ' . $app['last_name']);
            $course = htmlspecialchars(str_replace('Bachelor of Science in ', 'BS ', $app['course']));
            $date   = date('M d, Y', strtotime($app['submitted_at']));
            $status = htmlspecialchars($app['status']);

            if ($status === 'Pending') {
                $badge = '<span style="background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;">' . $status . '</span>';
            } elseif ($status === 'Approved' || $status === 'Enrolled') {
                $badge = '<span class="badge-active">' . $status . '</span>';
            } else {
                $badge = '<span class="badge-inactive">' . $status . '</span>';
            }

            $recent_rows_html .= '<tr>'
                . '<td>' . $name . '</td>'
                . '<td style="font-size:.75rem;">' . $course . '</td>'
                . '<td>' . $badge . '</td>'
                . '<td style="font-size:.75rem;color:#888;">' . $date . '</td>'
                . '</tr>';
        }
    }
}

// ── 5. Pipeline step states ───────────────────────────────────
$pipeline = [
    'pre_reg'      => $total_prereg_all > 0,
    'validation'   => $approved_enr > 0,
    'id_generated' => $enrolled_count > 0,
    'grade_level'  => $enrolled_count > 0,
    'sections'     => $total_sections > 0,
];

// ── 6. Notification items HTML ────────────────────────────────
$notif_html = '';
if ($pending_enr > 0) {
    $notif_html .= '<div class="notif-item unread" data-notif="enr">'
        . '<span class="notif-dot"></span>'
        . '<div class="notif-text">'
        . '<div class="notif-title">Pending Applications</div>'
        . '<div class="notif-desc">' . $pending_enr . ' application' . ($pending_enr !== 1 ? 's' : '') . ' awaiting validation.</div>'
        . '</div>'
        . '<span class="notif-time">Now</span>'
        . '</div>';
}
$nr = $conn->query("SELECT first_name,last_name,created_at FROM students ORDER BY created_at DESC LIMIT 3");
if ($nr) {
    while ($ns = $nr->fetch_assoc()) {
        $ago = max(1, round((time() - strtotime($ns['created_at'])) / 60));
        $ts  = $ago < 60 ? $ago . 'm ago' : ($ago < 1440 ? round($ago / 60) . 'h ago' : date('M d', strtotime($ns['created_at'])));
        $notif_html .= '<div class="notif-item" data-notif="s">'
            . '<span class="notif-dot"></span>'
            . '<div class="notif-text">'
            . '<div class="notif-title">Student record</div>'
            . '<div class="notif-desc">' . htmlspecialchars($ns['first_name'] . ' ' . $ns['last_name']) . ' is in the system.</div>'
            . '</div>'
            . '<span class="notif-time">' . $ts . '</span>'
            . '</div>';
    }
}

// ── Response ──────────────────────────────────────────────────
echo json_encode([
    'stats' => [
        'total_students'    => $total_students,
        'active_students'   => $active_students,
        'inactive_students' => $inactive_students,
        'pending_enr'       => $pending_enr,
        'approved_enr'      => $approved_enr,
        'enrolled_count'    => $enrolled_count,
        'waiting_count'     => $waiting_count,
        'total_prereg_all'  => $total_prereg_all,
        'total_sections'    => $total_sections,
    ],
    'recent_rows_html' => $recent_rows_html,
    'notif_count'      => $pending_enr,     // badge number
    'notif_html'       => $notif_html,
    'pipeline'         => $pipeline,
]);

$conn->close();

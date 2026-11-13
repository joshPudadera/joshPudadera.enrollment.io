<?php
// ============================================================
//  notifications.php — AJAX endpoint for the notification bell
//  Works for both admin and staff roles.
//  Returns: { count, items: [{icon, color, title, desc, time, url}] }
// ============================================================
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','staff'])) {
    http_response_code(403);
    echo json_encode(['count' => 0, 'items' => []]);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

$items = [];

// ── 1. Pending applications (need validation) ─────────────────
$r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Pending'");
$pending = $r ? (int)$r->fetch_assoc()['c'] : 0;
if ($pending > 0) {
    $items[] = [
        'icon'  => 'fa-file-pen',
        'color' => '#d97706',
        'bg'    => '#fff7ed',
        'title' => 'Pending Applications',
        'desc'  => "$pending application" . ($pending !== 1 ? 's' : '') . " awaiting enrollment validation.",
        'time'  => 'Now',
        'url'   => 'enrollment_tab/validation.php',
    ];
}

// ── 2. Unchecked / Flagged cross-enrollment ───────────────────
$r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Approved' AND cross_status IN ('Unchecked','Flagged')");
$cross_pending = $r ? (int)$r->fetch_assoc()['c'] : 0;
if ($cross_pending > 0) {
    $r2 = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Approved' AND cross_status='Flagged'");
    $flagged = $r2 ? (int)$r2->fetch_assoc()['c'] : 0;
    $desc = $flagged > 0
        ? "$flagged flagged + " . ($cross_pending - $flagged) . " unchecked in cross-enrollment."
        : "$cross_pending student" . ($cross_pending !== 1 ? 's' : '') . " awaiting cross-enrollment review.";
    $items[] = [
        'icon'  => 'fa-arrow-right-arrow-left',
        'color' => $flagged > 0 ? '#dc2626' : '#d97706',
        'bg'    => $flagged > 0 ? '#fff0f0' : '#fff7ed',
        'title' => 'Cross Enrollment',
        'desc'  => $desc,
        'time'  => 'Now',
        'url'   => 'enrollment_tab/cross_enrollment.php',
    ];
}

// ── 3. Grade assignment pending ───────────────────────────────
$r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Approved' AND cross_status NOT IN ('Unchecked','Flagged') AND grade_confirmed=0");
$grade_pend = $r ? (int)$r->fetch_assoc()['c'] : 0;
if ($grade_pend > 0) {
    $items[] = [
        'icon'  => 'fa-layer-group',
        'color' => '#7c3aed',
        'bg'    => '#faf5ff',
        'title' => 'Grade Assignment',
        'desc'  => "$grade_pend student" . ($grade_pend !== 1 ? 's' : '') . " need grade level confirmation.",
        'time'  => 'Now',
        'url'   => 'enrollment_tab/grade_assignment.php',
    ];
}

// ── 4. ID generation pending ──────────────────────────────────
$r = $conn->query(
    "SELECT COUNT(*) c FROM pre_registrations p
     LEFT JOIN enrollments e ON e.pre_reg_id = p.id
     WHERE p.status='Approved' AND p.grade_confirmed=1 AND e.id IS NULL"
);
$id_pend = $r ? (int)$r->fetch_assoc()['c'] : 0;
if ($id_pend > 0) {
    $items[] = [
        'icon'  => 'fa-id-badge',
        'color' => '#2563eb',
        'bg'    => '#eff6ff',
        'title' => 'ID Generation',
        'desc'  => "$id_pend student" . ($id_pend !== 1 ? 's' : '') . " ready for ID generation.",
        'time'  => 'Now',
        'url'   => 'enrollment_tab/id_generation.php',
    ];
}

// ── 5. Section assignment pending ────────────────────────────
$r = $conn->query(
    "SELECT COUNT(*) c FROM enrollments WHERE section IS NULL OR section='' OR section='TBA'"
);
$sec_pend = $r ? (int)$r->fetch_assoc()['c'] : 0;
if ($sec_pend > 0) {
    $items[] = [
        'icon'  => 'fa-chalkboard',
        'color' => '#0891b2',
        'bg'    => '#ecfeff',
        'title' => 'Section Assignment',
        'desc'  => "$sec_pend enrolled student" . ($sec_pend !== 1 ? 's' : '') . " still need a section.",
        'time'  => 'Now',
        'url'   => 'enrollment_tab/section_assignment.php',
    ];
}

// ── 6. Waiting list ───────────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) c FROM waiting_list WHERE status='Waiting'");
$wl = $r ? (int)$r->fetch_assoc()['c'] : 0;
if ($wl > 0) {
    $items[] = [
        'icon'  => 'fa-list-ol',
        'color' => '#16a34a',
        'bg'    => '#f0fdf4',
        'title' => 'Waiting List',
        'desc'  => "$wl student" . ($wl !== 1 ? 's' : '') . " in the waiting queue.",
        'time'  => 'Now',
        'url'   => 'enrollment_tab/waiting_list.php',
    ];
}

$count = count($items);

echo json_encode(['count' => $count, 'items' => $items]);
$conn->close();

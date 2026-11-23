<?php
header('Content-Type: application/json');

$db_host     = getenv('DB_HOST') ?: 'localhost';
$db_user     = getenv('DB_USER') ?: 'root';
$db_pass     = getenv('DB_PASS') ?: '';
$db_name     = getenv('API_TEST1_DB')           ?: 'api_test1';
$receive_url = getenv('API_TEST2_RECEIVE_URL')  ?: '';
$token       = getenv('API_TEST_TOKEN')         ?: '';

$student_id = (int)($_GET['id'] ?? 0);

if (!$receive_url) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'API_TEST2_RECEIVE_URL not set']);
    exit;
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Cannot connect to ' . $db_name]);
    exit;
}

if ($student_id > 0) {
    $result = $conn->query("SELECT name, section, year_level FROM students WHERE id=$student_id LIMIT 1");
} else {
    $result = $conn->query("SELECT name, section, year_level FROM students WHERE source != 'sent'");
}

$data = [];
while ($row = $result->fetch_assoc()) $data[] = $row;

if (empty($data)) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'No students to send.']);
    exit;
}

$options = [
    'http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nX-API-Token: $token\r\n",
        'content'       => json_encode($data),
        'timeout'       => 15,
        'ignore_errors' => true,
    ]
];

$context  = stream_context_create($options);
$response = @file_get_contents($receive_url, false, $context);

if ($response === false) {
    $conn->close();
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Could not reach: ' . $receive_url]);
    exit;
}

$resp = json_decode($response, true);
if (($resp['status'] ?? '') === 'success') {
    if ($student_id > 0) {
        $conn->query("UPDATE students SET source='sent' WHERE id=$student_id");
    } else {
        $conn->query("UPDATE students SET source='sent' WHERE source != 'sent'");
    }
}

$conn->close();
echo $response;

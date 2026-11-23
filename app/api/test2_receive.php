<?php
header('Content-Type: application/json');

$expected = getenv('API_TEST_TOKEN')         ?: '';
$received = $_SERVER['HTTP_X_API_TOKEN']     ?? '';

if ($expected && $received !== $expected) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('API_TEST2_DB') ?: 'api_test2';

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (empty($data) || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No valid JSON received.']);
    exit;
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Cannot connect to ' . $db_name]);
    exit;
}

$inserted   = 0;
$duplicates = 0;

foreach ($data as $row) {
    $name       = $conn->real_escape_string(trim($row['name']       ?? ''));
    $section    = $conn->real_escape_string(trim($row['section']    ?? ''));
    $year_level = $conn->real_escape_string(trim($row['year_level'] ?? ''));

    if (!$name || !$section || !$year_level) continue;

    $check = $conn->query("SELECT id FROM students WHERE name='$name' AND section='$section' LIMIT 1");
    if ($check && $check->num_rows > 0) {
        $duplicates++;
        continue;
    }

    $conn->query("INSERT INTO students (name, section, year_level, source)
                  VALUES ('$name', '$section', '$year_level', 'received')");
    $inserted++;
}

$conn->close();

echo json_encode([
    'status'     => 'success',
    'message'    => 'Data received into ' . $db_name,
    'inserted'   => $inserted,
    'duplicates' => $duplicates,
]);

<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_GET['provider_id'])) {
    echo json_encode(['error' => 'Missing provider_id']);
    exit;
}

$provider_id = intval($_GET['provider_id']);

// Fetch provider lock status
$lock_res = $conn->query("SELECT availability_locked FROM users WHERE user_id = $provider_id");
$locked = ($lock_res && $lock_res->fetch_assoc()['availability_locked'] == 1);

// Fetch availability slots
$slots = [];
$slots_res = $conn->query("SELECT day_of_week, start_time, end_time FROM user_availability WHERE user_id = $provider_id ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time ASC");

if ($slots_res) {
    while ($row = $slots_res->fetch_assoc()) {
        $slots[] = $row;
    }
}

echo json_encode([
    'locked' => $locked,
    'slots' => $slots
]);

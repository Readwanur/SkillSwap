<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$escaped = $conn->real_escape_string($query);
$current_user = $_SESSION['user_id'];

$result = $conn->query("
    SELECT u.user_id, u.name, u.location, r.mentor_level
    FROM users u
    LEFT JOIN reputation r ON u.user_id = r.user_id
    WHERE u.name LIKE '%$escaped%' AND u.user_id != $current_user
    ORDER BY u.name ASC
    LIMIT 5
");

$users = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id' => $row['user_id'],
            'name' => htmlspecialchars($row['name']),
            'location' => htmlspecialchars($row['location'] ?? 'Unknown'),
            'badge' => htmlspecialchars($row['mentor_level'] ?? 'Novice')
        ];
    }
}

echo json_encode($users);

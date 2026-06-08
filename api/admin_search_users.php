<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    echo json_encode([]);
    exit;
}

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$escaped = $conn->real_escape_string($query);

// Fetch matching users
$result = $conn->query("
    SELECT user_id, name, email, location, status, IF(profile_photo IS NOT NULL AND LENGTH(profile_photo) > 0, 1, 0) AS has_photo
    FROM users
    WHERE name LIKE '%$escaped%' OR email LIKE '%$escaped%' OR location LIKE '%$escaped%'
    ORDER BY name ASC
    LIMIT 6
");

$users = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id' => $row['user_id'],
            'name' => htmlspecialchars($row['name']),
            'email' => htmlspecialchars($row['email']),
            'location' => htmlspecialchars($row['location'] ?? 'N/A'),
            'status' => htmlspecialchars($row['status']),
            'has_photo' => $row['has_photo']
        ];
    }
}

echo json_encode($users);

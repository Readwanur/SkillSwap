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

// Fetch matching skills
$result = $conn->query("
    SELECT s.skill_id, s.skill_name, s.catagory, s.difficulty_level
    FROM skills s
    WHERE s.skill_name LIKE '%$escaped%' OR s.catagory LIKE '%$escaped%'
    ORDER BY s.skill_name ASC
    LIMIT 6
");

$skills = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $skills[] = [
            'id' => $row['skill_id'],
            'name' => htmlspecialchars($row['skill_name']),
            'category' => htmlspecialchars($row['catagory'] ?? 'General'),
            'difficulty' => htmlspecialchars($row['difficulty_level'] ?? 'Beginner')
        ];
    }
}

echo json_encode($skills);

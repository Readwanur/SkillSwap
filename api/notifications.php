<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Fetch notifications
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $unread_only = isset($_GET['unread_only']) ? intval($_GET['unread_only']) : 1;
    
    $where_clause = "WHERE user_id = $user_id";
    if ($unread_only) {
        $where_clause .= " AND is_read = 0";
    }
    
    $result = $conn->query("
        SELECT notif_id, message, type, is_read, created_at
        FROM notifications
        $where_clause
        ORDER BY created_at DESC
        LIMIT $limit
    ");
    
    $notifications = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $notifications[] = [
                'id' => intval($row['notif_id']),
                'message' => htmlspecialchars($row['message']),
                'type' => htmlspecialchars($row['type']),
                'is_read' => (bool)$row['is_read'],
                'created_at' => $row['created_at']
            ];
        }
    }
    
    // Also get the total unread count
    $count_res = $conn->query("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = $user_id AND is_read = 0");
    $unread_count = 0;
    if ($count_res) {
        $count_row = $count_res->fetch_assoc();
        $unread_count = intval($count_row['unread_count']);
    }
    
    echo json_encode([
        'status' => 'success',
        'notifications' => $notifications,
        'unread_count' => $unread_count
    ]);
    exit;

} else if ($method === 'POST') {
    // Update notification status
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'mark_read') {
        $notif_id = isset($input['notif_id']) ? intval($input['notif_id']) : 0;
        if ($notif_id > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $notif_id, $user_id);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Notification marked as read']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Database error']);
            }
            $stmt->close();
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid notification ID']);
        }
        exit;
    } else if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'All notifications marked as read']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
        }
        $stmt->close();
        exit;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

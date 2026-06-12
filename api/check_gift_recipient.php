<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['valid' => false, 'message' => 'User not logged in.']);
    exit;
}

$from_user_id = $_SESSION['user_id'];
$recipient_email = trim($_GET['email'] ?? '');

if (empty($recipient_email)) {
    echo json_encode(['valid' => false, 'message' => 'Please enter a recipient email.']);
    exit;
}

if (!filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['valid' => false, 'message' => 'Invalid email format.']);
    exit;
}

// 1. Get recipient ID and active status
$stmt = $conn->prepare("SELECT user_id, status FROM users WHERE email = ?");
$stmt->bind_param("s", $recipient_email);
$stmt->execute();
$res = $stmt->get_result();
$recipient = $res->fetch_assoc();
$stmt->close();

if (!$recipient) {
    echo json_encode(['valid' => false, 'message' => 'Recipient email not found.']);
    exit;
}

if ($recipient['status'] !== 'active') {
    echo json_encode(['valid' => false, 'message' => 'Recipient account is suspended.']);
    exit;
}

$to_user_id = intval($recipient['user_id']);

// 2. Prevent self-gifting
if ($to_user_id === $from_user_id) {
    echo json_encode(['valid' => false, 'message' => 'You cannot gift credits to yourself.']);
    exit;
}

// 3. Check cooldown (14-day limit between identical sender/receiver pairs)
$stmt = $conn->prepare("
    SELECT COUNT(*) AS cnt 
    FROM transactions 
    WHERE from_user_id = ? 
      AND to_user_id = ? 
      AND type = 'gift'
      AND timestamp >= DATE_SUB(NOW(), INTERVAL 14 DAY)
");
$stmt->bind_param("ii", $from_user_id, $to_user_id);
$stmt->execute();
$res = $stmt->get_result();
$cooldown = $res->fetch_assoc();
$stmt->close();

if ($cooldown && $cooldown['cnt'] > 0) {
    echo json_encode(['valid' => false, 'message' => 'You have already sent a gift to this user recently. You must wait 2 weeks before gifting them again.']);
    exit;
}

// 4. Verify mutual session or established accounts
// Check mutual session history
$stmt = $conn->prepare("
    SELECT EXISTS (
        SELECT 1 FROM exchange_sessions
        WHERE status = 'completed'
          AND ((requester_id = ? AND provider_id = ?) 
            OR (requester_id = ? AND provider_id = ?))
    ) AS has_mutual
");
$stmt->bind_param("iiii", $from_user_id, $to_user_id, $to_user_id, $from_user_id);
$stmt->execute();
$res = $stmt->get_result();
$mutual_res = $res->fetch_assoc();
$has_mutual_session = $mutual_res ? (bool)$mutual_res['has_mutual'] : false;
$stmt->close();

// Check if both are established (>= 3 completed sessions globally)
$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM exchange_sessions WHERE (requester_id = ? OR provider_id = ?) AND status = 'completed') >= 3 AS sender_established,
        (SELECT COUNT(*) FROM exchange_sessions WHERE (requester_id = ? OR provider_id = ?) AND status = 'completed') >= 3 AS recipient_established
");
$stmt->bind_param("iiii", $from_user_id, $from_user_id, $to_user_id, $to_user_id);
$stmt->execute();
$res = $stmt->get_result();
$est_res = $res->fetch_assoc();
$both_established = ($est_res && (bool)$est_res['sender_established'] && (bool)$est_res['recipient_established']);
$stmt->close();

if (!$has_mutual_session && !$both_established) {
    echo json_encode(['valid' => false, 'message' => 'Gifting blocked. Both established or mutual session history required.']);
    exit;
}

// All checks passed
echo json_encode(['valid' => true, 'message' => 'Recipient is eligible for gifting.']);

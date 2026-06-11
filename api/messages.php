<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = intval($_SESSION['user_id']);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['conversation_id'])) {
        // Fetch thread history
        $conversation_id = intval($_GET['conversation_id']);
        
        // Verify user is member of this conversation
        $check_stmt = $conn->prepare("
            SELECT 1 FROM conversation_members 
            WHERE conversation_id = ? AND user_id = ? 
            LIMIT 1
        ");
        $check_stmt->bind_param("ii", $conversation_id, $user_id);
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();
        
        if ($check_res->num_rows === 0) {
            $check_stmt->close();
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $check_stmt->close();
        
        // Mark messages as read (where sender is not current user)
        $mark_stmt = $conn->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
        ");
        $mark_stmt->bind_param("ii", $conversation_id, $user_id);
        $mark_stmt->execute();
        $mark_stmt->close();
        
        // Get partner details
        $partner_stmt = $conn->prepare("
            SELECT u.user_id, u.name, u.last_active_at, IF(u.profile_photo IS NOT NULL AND LENGTH(u.profile_photo) > 0, 1, 0) AS has_photo 
            FROM conversation_members cm
            JOIN users u ON cm.user_id = u.user_id
            WHERE cm.conversation_id = ? AND cm.user_id != ?
            LIMIT 1
        ");
        $partner_stmt->bind_param("ii", $conversation_id, $user_id);
        $partner_stmt->execute();
        $partner_res = $partner_stmt->get_result();
        $partner_name = 'Partner';
        $partner_id = 0;
        $partner_has_photo = 0;
        $is_online = false;
        $last_active = null;
        if ($partner_res->num_rows > 0) {
            $row = $partner_res->fetch_assoc();
            $partner_name = $row['name'];
            $partner_id = intval($row['user_id']);
            $partner_has_photo = intval($row['has_photo']);
            $last_active = $row['last_active_at'];
            if ($last_active && strtotime($last_active) >= strtotime('-2 minutes')) {
                $is_online = true;
            }
        }
        $partner_stmt->close();
        
        // Get messages
        $msg_stmt = $conn->prepare("
            SELECT message_id, sender_id, message_text, message_type, media_url, is_read, sent_at 
            FROM messages 
            WHERE conversation_id = ? 
            ORDER BY sent_at ASC, message_id ASC 
            LIMIT 100
        ");
        $msg_stmt->bind_param("i", $conversation_id);
        $msg_stmt->execute();
        $msg_res = $msg_stmt->get_result();
        
        $messages = [];
        while ($row = $msg_res->fetch_assoc()) {
            $messages[] = [
                'message_id' => intval($row['message_id']),
                'sender_id' => intval($row['sender_id']),
                'message_text' => htmlspecialchars($row['message_text']),
                'message_type' => $row['message_type'] ?? 'text',
                'media_url' => $row['media_url'],
                'is_read' => (bool)$row['is_read'],
                'sent_at' => $row['sent_at']
            ];
        }
        $msg_stmt->close();
        
        echo json_encode([
            'status' => 'success',
            'conversation_id' => $conversation_id,
            'partner_name' => htmlspecialchars($partner_name),
            'partner_id' => $partner_id,
            'partner_has_photo' => $partner_has_photo,
            'is_online' => $is_online,
            'last_active' => $last_active,
            'messages' => $messages
        ]);
        exit;
    } else {
        // Fetch all active conversations for the current user
        // Order by last message time DESC, or conversation creation if empty
        $q = "
            SELECT 
                c.conversation_id,
                u.user_id AS other_user_id,
                u.name AS other_user_name,
                u.last_active_at,
                IF(u.profile_photo IS NOT NULL AND LENGTH(u.profile_photo) > 0, 1, 0) AS has_photo,
                m.message_text AS last_message_text,
                m.sent_at AS last_message_time,
                m.sender_id AS last_message_sender_id,
                (SELECT COUNT(*) FROM messages m2 
                 WHERE m2.conversation_id = c.conversation_id 
                   AND m2.is_read = 0 
                   AND m2.sender_id != ?) AS unread_count
            FROM conversations c
            INNER JOIN conversation_members cm1 ON c.conversation_id = cm1.conversation_id AND cm1.user_id = ? AND cm1.is_hidden = 0
            INNER JOIN conversation_members cm2 ON c.conversation_id = cm2.conversation_id AND cm2.user_id != ?
            INNER JOIN users u ON cm2.user_id = u.user_id
            LEFT JOIN (
                SELECT conversation_id, MAX(message_id) AS max_id 
                FROM messages GROUP BY conversation_id
            ) m_max ON m_max.conversation_id = c.conversation_id
            LEFT JOIN messages m ON m.message_id = m_max.max_id
            ORDER BY COALESCE(m.sent_at, c.created_at) DESC
        ";
        
        $stmt = $conn->prepare($q);
        $stmt->bind_param("iii", $user_id, $user_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $conversations = [];
        while ($row = $res->fetch_assoc()) {
            $is_online = false;
            if ($row['last_active_at'] && strtotime($row['last_active_at']) >= strtotime('-2 minutes')) {
                $is_online = true;
            }
            $conversations[] = [
                'conversation_id' => intval($row['conversation_id']),
                'other_user_id' => intval($row['other_user_id']),
                'other_user_name' => htmlspecialchars($row['other_user_name']),
                'has_photo' => intval($row['has_photo']),
                'is_online' => $is_online,
                'last_message_text' => $row['last_message_text'] ? htmlspecialchars($row['last_message_text']) : null,
                'last_message_time' => $row['last_message_time'],
                'last_message_sender_id' => $row['last_message_sender_id'] ? intval($row['last_message_sender_id']) : null,
                'unread_count' => intval($row['unread_count'])
            ];
        }
        $stmt->close();
        
        echo json_encode([
            'status' => 'success',
            'conversations' => $conversations
        ]);
        exit;
    }
} else if ($method === 'POST') {
    // Send message or mark read
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Fallback to $_POST if JSON body is empty (e.g. standard form post)
    if (empty($input)) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? 'send';
    
    if ($action === 'hide_inbox') {
        $conversation_id = isset($input['conversation_id']) ? intval($input['conversation_id']) : 0;
        if ($conversation_id > 0) {
            $stmt = $conn->prepare("UPDATE conversation_members SET is_hidden = 1 WHERE conversation_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $conversation_id, $user_id);
            $stmt->execute();
            $stmt->close();
            
            echo json_encode(['status' => 'success', 'message' => 'Inbox hidden']);
            exit;
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid conversation ID']);
            exit;
        }
    }
    
    if ($action === 'mark_read') {
        $conversation_id = isset($input['conversation_id']) ? intval($input['conversation_id']) : 0;
        if ($conversation_id > 0) {
            $stmt = $conn->prepare("
                UPDATE messages 
                SET is_read = 1 
                WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
            ");
            $stmt->bind_param("ii", $conversation_id, $user_id);
            $stmt->execute();
            $stmt->close();
            
            echo json_encode(['status' => 'success', 'message' => 'Thread marked as read']);
            exit;
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid conversation ID']);
            exit;
        }
    }
    
    // Default action: send message
    $conversation_id = isset($input['conversation_id']) ? intval($input['conversation_id']) : (isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0);
    $recipient_id = isset($input['recipient_id']) ? intval($input['recipient_id']) : (isset($_POST['recipient_id']) ? intval($_POST['recipient_id']) : 0);
    $message_text = trim($input['message_text'] ?? ($_POST['message_text'] ?? ''));
    
    $decline_offer_msg_id = isset($input['decline_offer_msg_id']) ? intval($input['decline_offer_msg_id']) : (isset($_POST['decline_offer_msg_id']) ? intval($_POST['decline_offer_msg_id']) : 0);
    
    if ($decline_offer_msg_id > 0) {
        $update_stmt = $conn->prepare("UPDATE messages SET message_text = REPLACE(message_text, '[BOOK_SKILL:', '[OFFER_DECLINED:') WHERE message_id = ?");
        $update_stmt->bind_param("i", $decline_offer_msg_id);
        $update_stmt->execute();
        $update_stmt->close();
    }
    
    $message_type = 'text';
    $media_url = null;
    
    if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
        $message_type = 'audio';
        $message_text = 'Voice message';
        
        $tmp_name = $_FILES['audio_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['webm', 'mp3', 'wav', 'ogg', 'm4a', 'mp4'];
        if (!in_array($ext, $allowed_exts)) {
            $ext = 'webm';
        }
        $new_name = 'audio_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $upload_dir = __DIR__ . '/../uploads/voice_messages/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
            $media_url = '/uploads/voice_messages/' . $new_name;
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save audio file']);
            exit;
        }
    }
    
    if ($message_text === '' && $message_type === 'text') {
        http_response_code(400);
        echo json_encode(['error' => 'Message text cannot be empty']);
        exit;
    }
    
    // Resolve conversation ID if recipient_id was provided
    if ($conversation_id === 0 && $recipient_id > 0) {
        if ($recipient_id === $user_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot start conversation with yourself']);
            exit;
        }
        
        // Check if recipient exists
        $user_check = $conn->prepare("SELECT 1 FROM users WHERE user_id = ? LIMIT 1");
        $user_check->bind_param("i", $recipient_id);
        $user_check->execute();
        if ($user_check->get_result()->num_rows === 0) {
            $user_check->close();
            http_response_code(404);
            echo json_encode(['error' => 'Recipient not found']);
            exit;
        }
        $user_check->close();
        
        // Check if conversation already exists between these two users
        $conv_check = $conn->prepare("
            SELECT cm1.conversation_id 
            FROM conversation_members cm1
            INNER JOIN conversation_members cm2 ON cm1.conversation_id = cm2.conversation_id
            WHERE cm1.user_id = ? AND cm2.user_id = ?
            LIMIT 1
        ");
        $conv_check->bind_param("ii", $user_id, $recipient_id);
        $conv_check->execute();
        $conv_res = $conv_check->get_result();
        
        if ($conv_res->num_rows > 0) {
            $conversation_id = intval($conv_res->fetch_assoc()['conversation_id']);
        }
        $conv_check->close();
        
        // If not exists, create it
        if ($conversation_id === 0) {
            $conn->begin_transaction();
            try {
                $conn->query("INSERT INTO conversations () VALUES ()");
                $conversation_id = $conn->insert_id;
                
                $member_stmt = $conn->prepare("INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)");
                
                // Add current user
                $member_stmt->bind_param("ii", $conversation_id, $user_id);
                $member_stmt->execute();
                
                // Add recipient
                $member_stmt->bind_param("ii", $conversation_id, $recipient_id);
                $member_stmt->execute();
                
                $member_stmt->close();
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                http_response_code(500);
                echo json_encode(['error' => 'Failed to initialize conversation: ' . $e->getMessage()]);
                exit;
            }
        }
    }
    
    // Ensure we have a valid conversation ID at this stage
    if ($conversation_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing conversation_id or recipient_id']);
        exit;
    }
    
    // Verify user membership in conversation
    $check_stmt = $conn->prepare("
        SELECT 1 FROM conversation_members 
        WHERE conversation_id = ? AND user_id = ? 
        LIMIT 1
    ");
    $check_stmt->bind_param("ii", $conversation_id, $user_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows === 0) {
        $check_stmt->close();
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $check_stmt->close();
    
    // Insert new message
    $msg_stmt = $conn->prepare("
        INSERT INTO messages (conversation_id, sender_id, message_text, message_type, media_url) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $msg_stmt->bind_param("iisss", $conversation_id, $user_id, $message_text, $message_type, $media_url);
    if ($msg_stmt->execute()) {
        $message_id = $conn->insert_id;
        $sent_at = date('Y-m-d H:i:s');
        
        $msg_stmt->close();
        
        // Unhide conversation for all members since there is a new message
        $unhide_stmt = $conn->prepare("UPDATE conversation_members SET is_hidden = 0 WHERE conversation_id = ?");
        $unhide_stmt->bind_param("i", $conversation_id);
        $unhide_stmt->execute();
        $unhide_stmt->close();
        
        echo json_encode([
            'status' => 'success',
            'message' => [
                'message_id' => $message_id,
                'conversation_id' => $conversation_id,
                'sender_id' => $user_id,
                'message_text' => htmlspecialchars($message_text),
                'message_type' => $message_type,
                'media_url' => $media_url,
                'sent_at' => $sent_at,
                'is_read' => false
            ]
        ]);
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send message']);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

<?php
require_once __DIR__ . '/../config/db.php';

$user_id = intval($_GET['user_id'] ?? 0);

if ($user_id > 0) {
    $stmt = $conn->prepare("SELECT profile_photo, profile_photo_mime FROM users WHERE user_id = ? AND profile_photo IS NOT NULL");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($photo_data, $photo_mime);
        $stmt->fetch();

        $mime = !empty($photo_mime) ? $photo_mime : 'image/jpeg';
        
        header("Content-Type: $mime");
        header("Cache-Control: max-age=86400"); // Cache for 24 hours
        echo $photo_data;
        $stmt->close();
        exit;
    }
    $stmt->close();
}

// Fallback logic if image doesn't exist
// Return a 1x1 transparent pixel or just 404
header("HTTP/1.0 404 Not Found");
exit;

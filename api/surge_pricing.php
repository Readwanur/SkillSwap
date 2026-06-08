<?php
require_once __DIR__ . '/../config/db.php';

// ============================================================
// FEATURE 6: DYNAMIC CREDIT PRICING (SURGE PRICING) API
// ============================================================

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$provider_id = intval($_GET['provider_id'] ?? 0);
if ($provider_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid provider ID']);
    exit;
}

// --- CQ: Calculate surge multiplier based on provider's booking velocity ---
// Compares this provider's 7-day booking count against the platform average.
// Uses a derived table (subquery) to calculate the platform-wide average,
// and CASE WHEN to assign tiered multiplier bands.
$result = $conn->query("
    SELECT 
        provider_sessions_7d,
        ROUND(platform_avg_7d, 2) AS platform_avg_7d,
        total_active_providers,
        CASE 
            WHEN platform_avg_7d IS NULL OR platform_avg_7d = 0 THEN 1.00
            WHEN provider_sessions_7d > platform_avg_7d * 3 THEN 1.50
            WHEN provider_sessions_7d > platform_avg_7d * 2 THEN 1.25
            WHEN provider_sessions_7d > platform_avg_7d * 1.5 THEN 1.10
            ELSE 1.00
        END AS surge_multiplier,
        CASE 
            WHEN platform_avg_7d IS NULL OR platform_avg_7d = 0 THEN 'normal'
            WHEN provider_sessions_7d > platform_avg_7d * 3 THEN 'extreme'
            WHEN provider_sessions_7d > platform_avg_7d * 2 THEN 'high'
            WHEN provider_sessions_7d > platform_avg_7d * 1.5 THEN 'moderate'
            ELSE 'normal'
        END AS demand_level
    FROM (
        SELECT
            (SELECT COUNT(*) FROM exchange_sessions 
             WHERE provider_id = $provider_id 
             AND scheduled_time > DATE_SUB(NOW(), INTERVAL 7 DAY)
             AND status IN ('scheduled', 'completed')) AS provider_sessions_7d,
            (SELECT AVG(cnt) FROM (
                SELECT COUNT(*) AS cnt 
                FROM exchange_sessions 
                WHERE scheduled_time > DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND status IN ('scheduled', 'completed')
                GROUP BY provider_id
            ) t) AS platform_avg_7d,
            (SELECT COUNT(DISTINCT provider_id) FROM exchange_sessions 
             WHERE scheduled_time > DATE_SUB(NOW(), INTERVAL 7 DAY)) AS total_active_providers
    ) calc
");

if ($result && $row = $result->fetch_assoc()) {
    echo json_encode([
        'status' => 'success',
        'provider_id' => $provider_id,
        'surge_multiplier' => floatval($row['surge_multiplier']),
        'demand_level' => $row['demand_level'],
        'provider_sessions_7d' => intval($row['provider_sessions_7d']),
        'platform_avg_7d' => floatval($row['platform_avg_7d']),
        'active_providers' => intval($row['total_active_providers'])
    ]);
} else {
    echo json_encode([
        'status' => 'success',
        'provider_id' => $provider_id,
        'surge_multiplier' => 1.00,
        'demand_level' => 'normal',
        'provider_sessions_7d' => 0,
        'platform_avg_7d' => 0,
        'active_providers' => 0
    ]);
}
?>

<?php
$host = 'localhost';
$dbname = 'skillswap';
$username = 'root';
$password = '';

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

date_default_timezone_set('Asia/Dhaka');

session_start();

if (isset($conn)) {
    // Check if the system_audit_log table is missing
    $check_table = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = '$dbname' AND table_name = 'system_audit_log' LIMIT 1");
    if (!$check_table || $check_table->num_rows == 0) {
        include_once __DIR__ . '/../Dbms Database/run_migration.php';
    }
}

// Global Sorting Helper Function
if (!function_exists('renderTableSort')) {
    function renderTableSort($col, $current_sort, $current_order, $sort_param_name = 'sort', $order_param_name = 'order') {
        $is_active = ($current_sort === $col);
        $next_order = ($is_active && strtolower($current_order) === 'asc') ? 'desc' : 'asc';
        
        $query = $_GET;
        $query[$sort_param_name] = $col;
        $query[$order_param_name] = $next_order;
        
        $url = basename($_SERVER['PHP_SELF']) . '?' . http_build_query($query);
        
        if ($is_active) {
            $arrow = (strtolower($current_order) === 'asc') ? '&#x25B4;' : '&#x25BE;';
            $title = (strtolower($current_order) === 'asc') ? 'Sorted Ascending. Click to sort Descending' : 'Sorted Descending. Click to sort Ascending';
            return '<span class="sort-arrows"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="sort-arrow active" title="' . $title . '">' . $arrow . '</a></span>';
        } else {
            return '<span class="sort-arrows"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="sort-arrow" title="Click to sort Ascending">&#x25B4;</a></span>';
        }
    }
}
?>
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
?>
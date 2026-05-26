<?php
require_once __DIR__ . '/../config/db.php';

// Ensure only admins or local execution can run this (we can just allow running it during migration)
echo "<h3>Starting SkillSwap Database Migration...</h3>";

// 1. Create system_audit_log table
$q1 = "CREATE TABLE IF NOT EXISTS system_audit_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action_type VARCHAR(50) NOT NULL,
    table_affected VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    details TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
)";
if ($conn->query($q1)) {
    echo "✓ Table system_audit_log created/verified.<br>";
} else {
    echo "✗ Error creating system_audit_log: " . $conn->error . "<br>";
}

// 2. Create notifications table
$q2 = "CREATE TABLE IF NOT EXISTS notifications (
    notif_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
)";
if ($conn->query($q2)) {
    echo "✓ Table notifications created/verified.<br>";
} else {
    echo "✗ Error creating notifications: " . $conn->error . "<br>";
}

// 3. Drop/Create Triggers
$conn->query("DROP TRIGGER IF EXISTS trg_after_users_update");
$q3 = "CREATE TRIGGER trg_after_users_update
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status OR OLD.reliability_score != NEW.reliability_score THEN
        INSERT INTO system_audit_log (user_id, action_type, table_affected, record_id, details)
        VALUES (NEW.user_id, 'UPDATE', 'users', NEW.user_id, 
                CONCAT('Status: ', OLD.status, ' -> ', NEW.status, 
                       ', Reliability: ', OLD.reliability_score, ' -> ', NEW.reliability_score));
    END IF;
END";
if ($conn->query($q3)) {
    echo "✓ Trigger trg_after_users_update created.<br>";
} else {
    echo "✗ Error creating trigger trg_after_users_update: " . $conn->error . "<br>";
}

$conn->query("DROP TRIGGER IF EXISTS trg_after_sessions_update");
$q4 = "CREATE TRIGGER trg_after_sessions_update
AFTER UPDATE ON exchange_sessions
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO system_audit_log (user_id, action_type, table_affected, record_id, details)
        VALUES (NEW.requester_id, 'UPDATE', 'exchange_sessions', NEW.session_id, 
                CONCAT('Session status change: ', OLD.status, ' -> ', NEW.status));
        
        IF NEW.status = 'completed' THEN
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.requester_id, 'Your exchange session has been marked as completed!', 'session_update');
        ELSEIF NEW.status = 'cancelled' THEN
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.provider_id, 'A scheduled session has been cancelled.', 'session_update');
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.requester_id, 'Your scheduled session has been cancelled.', 'session_update');
        END IF;
    END IF;
END";
if ($conn->query($q4)) {
    echo "✓ Trigger trg_after_sessions_update created.<br>";
} else {
    echo "✗ Error creating trigger trg_after_sessions_update: " . $conn->error . "<br>";
}

$conn->query("DROP TRIGGER IF EXISTS trg_after_session_insert");
$q5 = "CREATE TRIGGER trg_after_session_insert
AFTER INSERT ON exchange_sessions
FOR EACH ROW
BEGIN
    DECLARE v_req_name VARCHAR(100);
    SELECT name INTO v_req_name FROM users WHERE user_id = NEW.requester_id;
    INSERT INTO notifications (user_id, message, type)
    VALUES (NEW.provider_id, CONCAT(v_req_name, ' booked a session with you for ', NEW.scheduled_time), 'booking');
END";
if ($conn->query($q5)) {
    echo "✓ Trigger trg_after_session_insert created.<br>";
} else {
    echo "✗ Error creating trigger trg_after_session_insert: " . $conn->error . "<br>";
}

$conn->query("DROP TRIGGER IF EXISTS trg_after_loan_status_change");
$q6 = "CREATE TRIGGER trg_after_loan_status_change
AFTER UPDATE ON loans
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO loan_audit_log (loan_id, user_id, old_status, new_status)
        VALUES (NEW.loan_id, NEW.user_id, OLD.status, NEW.status);
        
        IF NEW.status = 'defaulted' THEN
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.user_id, '⚠️ Alert: Your loan has defaulted! Complete repayment immediately to unlock platform booking.', 'loan_default');
        ELSEIF NEW.status = 'paid' THEN
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.user_id, '✅ Success: Your loan has been fully repaid. Thank you for maintaining good credit.', 'loan_repaid');
        END IF;
    END IF;
END";
if ($conn->query($q6)) {
    echo "✓ Trigger trg_after_loan_status_change created/updated.<br>";
} else {
    echo "✗ Error creating trigger trg_after_loan_status_change: " . $conn->error . "<br>";
}

// 4. Create Star-Schema Views
$q7 = "CREATE OR REPLACE VIEW vw_dim_users AS
SELECT user_id, name, email, location, reliability_score, created_at
FROM users";
if ($conn->query($q7)) {
    echo "✓ View vw_dim_users created/updated.<br>";
} else {
    echo "✗ Error creating view vw_dim_users: " . $conn->error . "<br>";
}

$q8 = "CREATE OR REPLACE VIEW vw_dim_skills AS
SELECT skill_id, skill_name, catagory AS category, difficulty_level
FROM skills";
if ($conn->query($q8)) {
    echo "✓ View vw_dim_skills created/updated.<br>";
} else {
    echo "✗ Error creating view vw_dim_skills: " . $conn->error . "<br>";
}

$q9 = "CREATE OR REPLACE VIEW vw_dim_time AS
SELECT DISTINCT
    timestamp AS date_key,
    DATE(timestamp) AS full_date,
    YEAR(timestamp) AS year,
    QUARTER(timestamp) AS quarter,
    MONTH(timestamp) AS month,
    MONTHNAME(timestamp) AS month_name,
    DAY(timestamp) AS day,
    WEEK(timestamp) AS week_of_year
FROM transactions
UNION
SELECT DISTINCT
    scheduled_time AS date_key,
    DATE(scheduled_time) AS full_date,
    YEAR(scheduled_time) AS year,
    QUARTER(scheduled_time) AS quarter,
    MONTH(scheduled_time) AS month,
    MONTHNAME(scheduled_time) AS month_name,
    DAY(scheduled_time) AS day,
    WEEK(scheduled_time) AS week_of_year
FROM exchange_sessions";
if ($conn->query($q9)) {
    echo "✓ View vw_dim_time created/updated.<br>";
} else {
    echo "✗ Error creating view vw_dim_time: " . $conn->error . "<br>";
}

$q10 = "CREATE OR REPLACE VIEW vw_fact_sessions AS
SELECT
    es.session_id,
    es.requester_id,
    es.provider_id,
    es.skill_id,
    es.scheduled_time AS date_key,
    es.status,
    es.session_duration AS minutes,
    es.time_credit_transfer AS credits,
    es.rating,
    es.bonus_multiplier
FROM exchange_sessions es";
if ($conn->query($q10)) {
    if ($verbose) echo "✓ View vw_fact_sessions created/updated.<br>";
} else {
    $success = false;
    if ($verbose) echo "✗ Error creating view vw_fact_sessions: " . $conn->error . "<br>";
}

// 5. Update sp_book_session stored procedure
$conn->query("DROP PROCEDURE IF EXISTS sp_book_session");
$q_proc = "CREATE PROCEDURE sp_book_session(
    IN p_requester_id INT,
    IN p_provider_id INT,
    IN p_skill_id INT,
    IN p_scheduled_time DATETIME,
    IN p_duration INT,
    OUT p_status VARCHAR(50),
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_balance DECIMAL(15,2);
    DECLARE v_credit_cost DECIMAL(10,2);
    DECLARE v_otp VARCHAR(10);
    DECLARE v_has_defaulted_loan BOOLEAN;
    DECLARE v_has_conflict BOOLEAN;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Booking failed due to a database error.';
    END;

    SET v_credit_cost = (p_duration / 60.0) * 10;
    SET v_otp = LPAD(FLOOR(RAND() * 10000), 4, '0');

    -- Check balance using subquery
    SELECT balance INTO v_balance FROM wallet WHERE user_id = p_requester_id;

    -- Check for defaulted loans
    SELECT EXISTS (
        SELECT 1 FROM loans
        WHERE user_id = p_requester_id AND status = 'defaulted'
    ) INTO v_has_defaulted_loan;

    -- Check for overlapping active sessions (double-booking protection)
    SELECT EXISTS (
        SELECT 1 FROM exchange_sessions
        WHERE status = 'scheduled'
          AND (
             requester_id = p_requester_id 
             OR provider_id = p_requester_id
             OR requester_id = p_provider_id
             OR provider_id = p_provider_id
          )
          AND p_scheduled_time < DATE_ADD(scheduled_time, INTERVAL session_duration MINUTE)
          AND DATE_ADD(p_scheduled_time, INTERVAL p_duration MINUTE) > scheduled_time
    ) INTO v_has_conflict;

    IF v_has_defaulted_loan THEN
        SET p_status = 'error';
        SET p_message = 'Your account has a defaulted loan. Please repay it before booking new sessions.';
    ELSEIF v_has_conflict THEN
        SET p_status = 'error';
        SET p_message = 'Schedule conflict detected. Either you or the provider has an overlapping active session scheduled at this time.';
    ELSEIF v_balance IS NULL OR v_balance < v_credit_cost THEN
        SET p_status = 'error';
        SET p_message = CONCAT('Insufficient balance. Need ', v_credit_cost, ' TC, have ', COALESCE(v_balance, 0), ' TC.');
    ELSEIF p_scheduled_time <= NOW() THEN
        SET p_status = 'error';
        SET p_message = 'Cannot book a session in the past.';
    ELSE
        START TRANSACTION;

        -- Deduct escrow
        UPDATE wallet SET balance = balance - v_credit_cost WHERE user_id = p_requester_id;

        -- Create session
        INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status,
            scheduled_time, session_duration, time_credit_transfer, completion_otp)
        VALUES (p_requester_id, p_provider_id, p_skill_id, 'scheduled',
            p_scheduled_time, p_duration, v_credit_cost, v_otp);

        COMMIT;
        SET p_status = 'success';
        SET p_message = CONCAT('Session booked! ', v_credit_cost, ' TC held in escrow. OTP: ', v_otp);
    END IF;
END";
if ($conn->query($q_proc)) {
    if ($verbose) echo "✓ Stored procedure sp_book_session recreated/updated.<br>";
} else {
    $success = false;
    if ($verbose) echo "✗ Error creating stored procedure sp_book_session: " . $conn->error . "<br>";
}

if ($verbose) {
    echo "<h3>Migration Finished " . ($success ? "Successfully" : "with Errors") . "!</h3>";
}
?>

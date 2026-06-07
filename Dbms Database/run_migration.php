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
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.provider_id, 'You have completed a teaching session! Credits have been transferred to your wallet.', 'session_update');
        ELSEIF NEW.status = 'cancelled' THEN
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.provider_id, 'A scheduled session has been cancelled.', 'session_update');
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.requester_id, 'Your scheduled session has been cancelled.', 'session_update');
        ELSEIF NEW.status = 'disputed' THEN
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.provider_id, '⚠️ A formal dispute has been filed on one of your sessions. Admin will review shortly.', 'session_update');
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.requester_id, '⚠️ A formal dispute has been filed on one of your sessions. Admin will review shortly.', 'session_update');
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
    DECLARE v_base_cost DECIMAL(10,2);
    DECLARE v_otp VARCHAR(10);
    DECLARE v_has_defaulted_loan BOOLEAN;
    DECLARE v_has_conflict BOOLEAN;
    DECLARE v_surge_multiplier DECIMAL(4,2) DEFAULT 1.00;
    DECLARE v_prov_sessions INT DEFAULT 0;
    DECLARE v_platform_avg DECIMAL(10,2) DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Booking failed due to a database error.';
    END;

    -- FEATURE 6: Dynamic Surge Pricing ---
    -- Calculate provider's 7-day booking count via subquery
    SELECT COUNT(*) INTO v_prov_sessions
    FROM exchange_sessions
    WHERE provider_id = p_provider_id
      AND scheduled_time > DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND status IN ('scheduled', 'completed');

    -- Calculate platform-wide average bookings per provider (derived table)
    SELECT COALESCE(AVG(cnt), 0) INTO v_platform_avg
    FROM (
        SELECT COUNT(*) AS cnt
        FROM exchange_sessions
        WHERE scheduled_time > DATE_SUB(NOW(), INTERVAL 7 DAY)
          AND status IN ('scheduled', 'completed')
        GROUP BY provider_id
    ) t;

    -- Assign surge tier using CASE expression
    SET v_surge_multiplier = CASE
        WHEN v_platform_avg = 0 THEN 1.00
        WHEN v_prov_sessions > v_platform_avg * 3 THEN 1.50
        WHEN v_prov_sessions > v_platform_avg * 2 THEN 1.25
        WHEN v_prov_sessions > v_platform_avg * 1.5 THEN 1.10
        ELSE 1.00
    END;

    SET v_base_cost = (p_duration / 60.0) * 10;
    SET v_credit_cost = ROUND(v_base_cost * v_surge_multiplier, 2);
    SET v_otp = LPAD(FLOOR(RAND() * 10000), 4, '0');

    START TRANSACTION;

    -- Check balance using subquery with row lock
    SELECT balance INTO v_balance FROM wallet WHERE user_id = p_requester_id FOR UPDATE;

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
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Your account has a defaulted loan. Please repay it before booking new sessions.';
    ELSEIF v_has_conflict THEN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Schedule conflict detected. Either you or the provider has an overlapping active session scheduled at this time.';
    ELSEIF v_balance IS NULL OR v_balance < v_credit_cost THEN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = CONCAT('Insufficient balance. Need ', v_credit_cost, ' TC (', v_surge_multiplier, 'x surge), have ', COALESCE(v_balance, 0), ' TC.');
    ELSEIF p_scheduled_time <= NOW() THEN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Cannot book a session in the past.';
    ELSE
        -- Deduct escrow (surge-adjusted amount)
        UPDATE wallet SET balance = balance - v_credit_cost WHERE user_id = p_requester_id;

        -- Create session with surge multiplier stored in bonus_multiplier
        INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status,
            scheduled_time, session_duration, time_credit_transfer, bonus_multiplier, completion_otp)
        VALUES (p_requester_id, p_provider_id, p_skill_id, 'scheduled',
            p_scheduled_time, p_duration, v_credit_cost, v_surge_multiplier, v_otp);

        COMMIT;
        SET p_status = 'success';
        IF v_surge_multiplier > 1 THEN
            SET p_message = CONCAT('Session booked! ', v_credit_cost, ' TC held in escrow (', v_surge_multiplier, 'x surge). OTP: ', v_otp);
        ELSE
            SET p_message = CONCAT('Session booked! ', v_credit_cost, ' TC held in escrow. OTP: ', v_otp);
        END IF;
    END IF;
END";
if ($conn->query($q_proc)) {
    if ($verbose) echo "✓ Stored procedure sp_book_session recreated/updated.<br>";
} else {
    if ($verbose) echo "✗ Error creating stored procedure sp_book_session: " . $conn->error . "<br>";
}

// 5b. Update sp_complete_session stored procedure (fix: v_provider_id -> v_actual_provider_id)
$conn->query("DROP PROCEDURE IF EXISTS sp_complete_session");
$q_complete = "CREATE PROCEDURE sp_complete_session(
    IN p_session_id INT,
    IN p_provider_id INT,
    IN p_otp VARCHAR(10),
    OUT p_status VARCHAR(50),
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_actual_provider_id INT;
    DECLARE v_requester_id INT;
    DECLARE v_amount DECIMAL(10,2);
    DECLARE v_stored_otp VARCHAR(10);
    DECLARE v_session_status VARCHAR(20);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Transaction failed due to a database error.';
    END;

    SELECT provider_id, requester_id, time_credit_transfer, completion_otp, status
    INTO v_actual_provider_id, v_requester_id, v_amount, v_stored_otp, v_session_status
    FROM exchange_sessions
    WHERE session_id = p_session_id;

    IF v_actual_provider_id IS NULL THEN
        SET p_status = 'error';
        SET p_message = 'Session not found.';
    ELSEIF v_actual_provider_id != p_provider_id THEN
        SET p_status = 'error';
        SET p_message = 'You are not the provider for this session.';
    ELSEIF v_session_status != 'scheduled' THEN
        SET p_status = 'error';
        SET p_message = 'Session is not in scheduled status.';
    ELSEIF v_stored_otp != p_otp THEN
        SET p_status = 'error';
        SET p_message = 'Invalid OTP.';
    ELSE
        START TRANSACTION;

        UPDATE exchange_sessions
        SET status = 'completed', completion_time = NOW()
        WHERE session_id = p_session_id;

        UPDATE wallet SET balance = balance + v_amount
        WHERE user_id = v_actual_provider_id;

        INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, note)
        VALUES (p_session_id, v_requester_id, v_actual_provider_id, 'credit_transfer', v_amount, v_amount, 'Time credit transfer for completed session');

        UPDATE reputation
        SET completed_sessions = completed_sessions + 1
        WHERE user_id = v_actual_provider_id;

        COMMIT;
        SET p_status = 'success';
        SET p_message = CONCAT('Session completed! ', v_amount, ' TC transferred.');
    END IF;
END";
if ($conn->query($q_complete)) {
    if ($verbose) echo "✓ Stored procedure sp_complete_session recreated/updated.<br>";
} else {
    if ($verbose) echo "✗ Error creating stored procedure sp_complete_session: " . $conn->error . "<br>";
}

// 6. Create conversations table
$q_conv = "CREATE TABLE IF NOT EXISTS conversations (
    conversation_id INT AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($conn->query($q_conv)) {
    if ($verbose) echo "✓ Table conversations created/verified.<br>";
} else {
    $success = false;
    if ($verbose) echo "✗ Error creating conversations: " . $conn->error . "<br>";
}

// 7. Create conversation_members table
$q_members = "CREATE TABLE IF NOT EXISTS conversation_members (
    conversation_id INT,
    user_id INT,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
)";
if ($conn->query($q_members)) {
    if ($verbose) echo "✓ Table conversation_members created/verified.<br>";
} else {
    $success = false;
    if ($verbose) echo "✗ Error creating conversation_members: " . $conn->error . "<br>";
}

// 8. Create messages table
$q_msg = "CREATE TABLE IF NOT EXISTS messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    message_text TEXT,
    message_type ENUM('text', 'audio') DEFAULT 'text',
    media_url VARCHAR(255),
    is_read BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
)";
if ($conn->query($q_msg)) {
    if ($verbose) echo "✓ Table messages created/verified.<br>";
} else {
    $success = false;
    if ($verbose) echo "✗ Error creating messages: " . $conn->error . "<br>";
}

// 9. Create messages index (safely)
$check_index = $conn->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = '$dbname' AND table_name = 'messages' AND index_name = 'idx_messages_thread' LIMIT 1");
if ($check_index && $check_index->num_rows == 0) {
    if ($conn->query("CREATE INDEX idx_messages_thread ON messages(conversation_id, sent_at DESC)")) {
        if ($verbose) echo "✓ Index idx_messages_thread created.<br>";
    } else {
        $success = false;
        if ($verbose) echo "✗ Error creating index: " . $conn->error . "<br>";
    }
}

// Alter exchange_sessions status ENUM if not already done
$conn->query("ALTER TABLE exchange_sessions MODIFY COLUMN status ENUM('scheduled', 'under-review', 'completed', 'cancelled', 'refunded', 'disputed') DEFAULT 'scheduled'");

// 10. Create disputes table
$q_disp = "CREATE TABLE IF NOT EXISTS disputes (
    dispute_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    filed_by_user_id INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('open', 'resolved_refunded', 'resolved_payout') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES exchange_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (filed_by_user_id) REFERENCES users(user_id) ON DELETE CASCADE
)";
if ($conn->query($q_disp)) {
    if ($verbose) echo "✓ Table disputes created/verified.<br>";
} else {
    $success = false;
    if ($verbose) echo "✗ Error creating disputes: " . $conn->error . "<br>";
}

// 11. Create vw_public_users view
$q_pub_u = "CREATE OR REPLACE VIEW vw_public_users AS
SELECT user_id, name, location, bio, reliability_score, status, created_at, last_active_at
FROM users";
if ($conn->query($q_pub_u)) {
    if ($verbose) echo "✓ View vw_public_users created/verified.<br>";
} else {
    $success = false;
    if ($verbose) echo "✗ Error creating view vw_public_users: " . $conn->error . "<br>";
}

// 12. Create vw_smart_matches view
$q_smart = "CREATE OR REPLACE VIEW vw_smart_matches AS
SELECT DISTINCT
    my_req.user_id AS user_a_id,
    their_off.user_id AS user_b_id,
    s1.skill_id AS user_a_requests_skill_id,
    s1.skill_name AS user_a_requests_skill_name,
    s2.skill_id AS user_b_requests_skill_id,
    s2.skill_name AS user_b_requests_skill_name,
    u.name AS user_b_name,
    u.location AS user_b_location,
    u.reliability_score AS user_b_reliability,
    DENSE_RANK() OVER (
        PARTITION BY my_req.user_id 
        ORDER BY u.reliability_score DESC, u.user_id ASC
    ) as match_rank
FROM user_skills_requested my_req
JOIN user_skills_offered their_off ON my_req.skill_id = their_off.skill_id
JOIN user_skills_requested their_req ON their_off.user_id = their_req.user_id
JOIN user_skills_offered my_off ON their_req.skill_id = my_off.skill_id AND my_off.user_id = my_req.user_id
JOIN users u ON their_off.user_id = u.user_id
JOIN skills s1 ON my_req.skill_id = s1.skill_id
JOIN skills s2 ON their_req.skill_id = s2.skill_id
WHERE u.status = 'active'";
if ($conn->query($q_smart)) {
    if ($verbose) echo "✓ View vw_smart_matches created/verified.<br>";
} else {
    $success = false;
    if ($verbose) echo "✗ Error creating view vw_smart_matches: " . $conn->error . "<br>";
}

// 13. Create sp_resolve_dispute stored procedure
$conn->query("DROP PROCEDURE IF EXISTS sp_resolve_dispute");
$q_proc_disp = "CREATE PROCEDURE sp_resolve_dispute(
    IN p_dispute_id INT,
    IN p_verdict VARCHAR(20),
    OUT p_status VARCHAR(50),
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_session_id INT;
    DECLARE v_requester_id INT;
    DECLARE v_provider_id INT;
    DECLARE v_amount DECIMAL(10,2);
    DECLARE v_dispute_status VARCHAR(20);
    DECLARE v_session_status VARCHAR(20);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Dispute resolution failed due to a database error.';
    END;

    -- Fetch dispute and session details
    SELECT d.status, d.session_id, es.requester_id, es.provider_id, es.time_credit_transfer, es.status
    INTO v_dispute_status, v_session_id, v_requester_id, v_provider_id, v_amount, v_session_status
    FROM disputes d
    JOIN exchange_sessions es ON d.session_id = es.session_id
    WHERE d.dispute_id = p_dispute_id
    FOR UPDATE;

    IF v_dispute_status IS NULL THEN
        SET p_status = 'error';
        SET p_message = 'Dispute not found.';
    ELSEIF v_dispute_status != 'open' THEN
        SET p_status = 'error';
        SET p_message = 'Dispute is already resolved.';
    ELSE
        START TRANSACTION;

        IF p_verdict = 'refund' THEN
            -- Update session status
            UPDATE exchange_sessions SET status = 'cancelled' WHERE session_id = v_session_id;
            
            -- Refund held escrow credits back to requester
            UPDATE wallet SET balance = balance + v_amount WHERE user_id = v_requester_id;
            
            -- Log system refund transaction
            INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, details)
            VALUES (v_session_id, NULL, v_requester_id, 'full_refund', v_amount, v_amount, 'Refund via dispute resolution.');
            
            -- Update dispute status
            UPDATE disputes SET status = 'resolved_refunded' WHERE dispute_id = p_dispute_id;
            
            -- Penalize provider reliability in reputation
            UPDATE reputation 
            SET current_score = GREATEST(current_score - 0.50, 1.00),
                cancelled_sessions = cancelled_sessions + 1
            WHERE user_id = v_provider_id;

            COMMIT;
            SET p_status = 'success';
            SET p_message = CONCAT('Dispute refunded. ', v_amount, ' TC returned to requester.');

        ELSEIF p_verdict = 'payout' THEN
            -- Update session status
            UPDATE exchange_sessions SET status = 'completed', completion_time = NOW() WHERE session_id = v_session_id;
            
            -- Transfer escrow credits to provider
            UPDATE wallet SET balance = balance + v_amount WHERE user_id = v_provider_id;
            
            -- Log credit transfer transaction
            INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, details)
            VALUES (v_session_id, v_requester_id, v_provider_id, 'credit_transfer', v_amount, v_amount, 'Payout via dispute resolution.');
            
            -- Update dispute status
            UPDATE disputes SET status = 'resolved_payout' WHERE dispute_id = p_dispute_id;
            
            -- Increment provider completed sessions
            UPDATE reputation 
            SET completed_sessions = completed_sessions + 1 
            WHERE user_id = v_provider_id;

            COMMIT;
            SET p_status = 'success';
            SET p_message = CONCAT('Dispute paid out. ', v_amount, ' TC transferred to provider.');
        ELSE
            ROLLBACK;
            SET p_status = 'error';
            SET p_message = 'Invalid verdict. Use refund or payout.';
        END IF;
    END IF;
END";
if ($conn->query($q_proc_disp)) {
    if ($verbose) echo "✓ Stored procedure sp_resolve_dispute created.<br>";
} else {
    $success = false;
    if ($verbose) echo "✗ Error creating stored procedure sp_resolve_dispute: " . $conn->error . "<br>";
}

if ($verbose) {
    echo "<h3>Migration Finished " . ($success ? "Successfully" : "with Errors") . "!</h3>";
}
?>

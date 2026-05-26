DROP DATABASE IF EXISTS Skillswap;
CREATE DATABASE Skillswap;
USE skillswap;

-- ============================================================
-- 3NF NORMALIZATION NOTES
-- ============================================================
-- 1NF: All columns hold atomic values; no repeating groups.
-- 2NF: No partial dependencies — every non-key attribute depends
--       on the entire primary key (composite keys have no
--       extra non-key columns that depend on part of the key).
-- 3NF: No transitive dependencies — every non-key attribute
--       depends directly on the primary key and nothing else.
--
-- Junction tables (user_skills_offered, user_skills_requested)
-- eliminate many-to-many relationships.
-- Weak entities (reputation, wallet) use the parent PK
-- as their own PK, enforcing a strict 1:1 dependency.
-- ============================================================


-- =========================
-- ENTITY: skills
-- =========================
-- PK: skill_id
-- All non-key attrs (skill_name, catagory, description,
-- difficulty_level) depend solely on skill_id. ✓ 3NF
-- =========================
CREATE TABLE IF NOT EXISTS skills (
    skill_id INT AUTO_INCREMENT PRIMARY KEY,
    skill_name VARCHAR(100) NOT NULL,
    catagory VARCHAR(50),
    description TEXT,
    difficulty_level ENUM('Beginner', 'Intermediate', 'Advanced')
);

CREATE INDEX idx_skills_catagory ON skills(catagory);
CREATE INDEX idx_skills_difficulty ON skills(difficulty_level);


-- =========================
-- ENTITY: users
-- =========================
-- PK: user_id
-- All non-key attrs depend solely on user_id. ✓ 3NF
-- reliability_score is per-user, not derived from another
-- non-key column, so no transitive dependency.
-- =========================
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    location VARCHAR(100),
    bio TEXT,
    profile_photo VARCHAR(255),
    reliability_score DECIMAL(5, 2) DEFAULT 5.00,
    status ENUM('active', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    availability_schedule TEXT
);

-- email already has a UNIQUE index
CREATE INDEX idx_users_location ON users(location);
CREATE INDEX idx_users_last_active ON users(last_active_at);
CREATE INDEX idx_users_reliability ON users(reliability_score);

-- =========================
-- ENTITY: user_availability
-- =========================
-- PK: availability_id
-- =========================
CREATE TABLE IF NOT EXISTS user_availability (
    availability_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    day_of_week VARCHAR(20) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE INDEX idx_user_availability_user ON user_availability(user_id);

-- =========================
-- RELATIONSHIP: user_skills_offered (many-to-many junction)
-- =========================
-- Composite PK: (user_id, skill_id)
-- No non-key attributes → automatically 3NF. ✓
-- =========================
CREATE TABLE IF NOT EXISTS user_skills_offered (
    user_id INT,
    skill_id INT,
    PRIMARY KEY (user_id, skill_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(skill_id) ON DELETE CASCADE
);

-- Reverse lookup: find all users offering a specific skill
CREATE INDEX idx_uso_skill ON user_skills_offered(skill_id);


-- =========================
-- RELATIONSHIP: user_skills_requested (many-to-many junction)
-- =========================
-- Composite PK: (user_id, skill_id)
-- No non-key attributes → automatically 3NF. ✓
-- =========================
CREATE TABLE IF NOT EXISTS user_skills_requested (
    user_id INT,
    skill_id INT,
    PRIMARY KEY (user_id, skill_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(skill_id) ON DELETE CASCADE
);

-- Reverse lookup: find all users requesting a specific skill
CREATE INDEX idx_usr_skill ON user_skills_requested(skill_id);


-- =========================
-- WEAK ENTITY: reputation (1:1 with users)
-- =========================
-- PK: user_id (identifying relationship)
-- All non-key attrs (current_score, completed_sessions,
-- cancelled_sessions, no_show_decay, last_decay_date)
-- depend solely on user_id. ✓ 3NF
-- =========================
CREATE TABLE IF NOT EXISTS reputation (
    user_id INT PRIMARY KEY,
    current_score DECIMAL(5, 2) DEFAULT 5.00,
    completed_sessions INT DEFAULT 0,
    cancelled_sessions INT DEFAULT 0,
    no_show_decay DECIMAL(5, 2) DEFAULT 0.00,
    last_decay_date DATE,
    mentor_level VARCHAR(50) DEFAULT 'Novice',
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE INDEX idx_reputation_score ON reputation(current_score);


-- =========================
-- WEAK ENTITY: wallet (1:1 with users)
-- =========================
-- PK: wallet_id; user_id is a candidate key (UNIQUE)
-- All non-key attrs (balance, last_updated) depend
-- solely on wallet_id / user_id. ✓ 3NF
-- =========================
CREATE TABLE IF NOT EXISTS wallet (
    wallet_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    balance DECIMAL(15, 2) DEFAULT 0.00 CHECK (balance >= 0.00),
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- user_id already has a UNIQUE index


-- =========================
-- ENTITY: community_task
-- =========================
-- PK: task_id
-- All non-key attrs (user_id, task_type, status,
-- assigned_at, completed_at) depend solely on task_id. ✓ 3NF
-- =========================
CREATE TABLE IF NOT EXISTS community_task (
    task_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    task_type VARCHAR(50),
    description TEXT,
    location VARCHAR(100),
    credit_reward DECIMAL(10, 2) DEFAULT 5.00,
    status ENUM('pending', 'in-progress', 'under-review', 'completed', 'cancelled') DEFAULT 'pending',
    submission_note TEXT,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE INDEX idx_ctask_user ON community_task(user_id);
CREATE INDEX idx_ctask_status ON community_task(status);


-- =========================
-- ENTITY: exchange_sessions
-- =========================
-- PK: session_id
-- All non-key attrs depend solely on session_id. ✓ 3NF
-- Review (rating, comment, bonus_multiplier) is a
-- relationship in the ER diagram — its attributes are
-- stored here since they depend on session_id.
-- =========================
CREATE TABLE IF NOT EXISTS exchange_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    requester_id INT NOT NULL,
    provider_id INT NOT NULL,
    skill_id INT NOT NULL,
    status ENUM('scheduled', 'under-review', 'completed', 'cancelled', 'refunded', 'disputed') DEFAULT 'scheduled',
    scheduled_time DATETIME NOT NULL,
    completion_time DATETIME NULL,
    session_duration INT,
    feedback_given BOOLEAN DEFAULT FALSE,
    time_credit_transfer DECIMAL(10, 2),
    submission_note TEXT,
    rating INT CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    bonus_multiplier DECIMAL(3, 2) DEFAULT 1.00,
    completion_otp VARCHAR(10) NULL,
    FOREIGN KEY (requester_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    FOREIGN KEY (provider_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    FOREIGN KEY (skill_id) REFERENCES skills(skill_id) ON DELETE RESTRICT
);

CREATE INDEX idx_session_requester ON exchange_sessions(requester_id);
CREATE INDEX idx_session_provider ON exchange_sessions(provider_id);
CREATE INDEX idx_session_skill ON exchange_sessions(skill_id);
CREATE INDEX idx_session_status ON exchange_sessions(status);
CREATE INDEX idx_session_scheduled ON exchange_sessions(scheduled_time);


-- =========================
-- ENTITY: transactions
-- =========================
-- PK: transcation_id
-- All non-key attrs depend solely on transcation_id. ✓ 3NF
-- from_user_id / to_user_id are independent FKs, not
-- derived from session_id, so no transitive dependency.
-- =========================
CREATE TABLE IF NOT EXISTS transactions (
    transcation_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT,
    from_user_id INT NULL,
    to_user_id INT NULL,
    type VARCHAR(50),
    base_amount DECIMAL(10, 2) CHECK (base_amount > 0.00),
    final_amount DECIMAL(10, 2) CHECK (final_amount >= 0.00),
    note TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES exchange_sessions(session_id) ON DELETE SET NULL,
    FOREIGN KEY (from_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (to_user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE INDEX idx_txn_session ON transactions(session_id);
CREATE INDEX idx_txn_from_user ON transactions(from_user_id);
CREATE INDEX idx_txn_to_user ON transactions(to_user_id);
CREATE INDEX idx_txn_type ON transactions(type);
CREATE INDEX idx_txn_timestamp ON transactions(timestamp);


INSERT INTO skills (skill_name, catagory, description, difficulty_level) VALUES
-- Technology
('Python Programming', 'Technology', 'Learn core Python concepts and automation.', 'Intermediate'),
('Coding', 'Technology', 'Software development and logic.', 'Intermediate'),
('Web Development', 'Technology', 'Building modern websites with HTML, CSS and JavaScript.', 'Intermediate'),
('Mobile App Development', 'Technology', 'Creating apps for Android and iOS platforms.', 'Advanced'),
('Database Management', 'Technology', 'Designing and managing SQL and NoSQL databases.', 'Advanced'),
('Cybersecurity Basics', 'Technology', 'Understanding threats, encryption and safe browsing.', 'Beginner'),
-- Design
('Graphic Design', 'Design', 'Creating visual content using Adobe Illustrator.', 'Advanced'),
('UI/UX Design', 'Design', 'Designing user-friendly interfaces and experiences.', 'Intermediate'),
('Video Editing', 'Design', 'Editing and producing videos with professional tools.', 'Intermediate'),
-- Language
('Spanish Conversation', 'Language', 'Practice speaking Spanish with a native.', 'Beginner'),
('Japanese Basics', 'Language', 'Learn Hiragana, Katakana and basic conversation.', 'Beginner'),
('English Writing', 'Language', 'Improve academic and creative writing in English.', 'Intermediate'),
('French Conversation', 'Language', 'Conversational French for travel and daily life.', 'Beginner'),
-- Health
('Yoga Basics', 'Health', 'Introduction to Hatha Yoga poses and breathing.', 'Beginner'),
('Mental Health Awareness', 'Health', 'Understanding stress management and mindfulness.', 'Beginner'),
('Nutrition Planning', 'Health', 'Creating balanced meal plans for healthy living.', 'Intermediate'),
-- Marketing
('SEO Strategy', 'Marketing', 'Optimizing websites for search engine rankings.', 'Advanced'),
('Social Media Marketing', 'Marketing', 'Growing brand presence on Instagram, TikTok and X.', 'Intermediate'),
('Content Writing', 'Marketing', 'Writing engaging blogs, articles and ad copy.', 'Beginner'),
-- Academic
('Math', 'Academic', 'General mathematics and problem solving.', 'Intermediate'),
('Physics Tutoring', 'Academic', 'Mechanics, thermodynamics and electromagnetism.', 'Advanced'),
('Research Methods', 'Academic', 'Academic research design, citation and paper writing.', 'Intermediate'),
-- Arts
('Music', 'Arts', 'Musical theory or instrument practice.', 'Beginner'),
('Art', 'Arts', 'Visual arts, painting, or sketching.', 'Beginner'),
('Creative Writing', 'Arts', 'Fiction, poetry and storytelling techniques.', 'Intermediate'),
-- Fitness
('Home Workouts', 'Fitness', 'Bodyweight exercises you can do without a gym.', 'Beginner'),
('Weight Training', 'Fitness', 'Strength training with proper form and programs.', 'Intermediate'),
-- Finance
('Personal Budgeting', 'Finance', 'Managing income, expenses and savings effectively.', 'Beginner'),
('Stock Market Basics', 'Finance', 'Understanding stocks, ETFs and portfolio strategy.', 'Intermediate'),
-- Cooking
('Baking Fundamentals', 'Cooking', 'Breads, pastries and desserts from scratch.', 'Beginner'),
('International Cuisine', 'Cooking', 'Cooking dishes from around the world.', 'Intermediate'),
-- Photography
('Portrait Photography', 'Photography', 'Lighting, posing and editing for portrait shots.', 'Intermediate'),
('Mobile Photography', 'Photography', 'Taking stunning photos with just your phone.', 'Beginner'),
-- Lifestyle
('Public Speaking', 'Lifestyle', 'Confidence building and presentation skills.', 'Beginner'),
('Time Management', 'Lifestyle', 'Productivity techniques like Pomodoro and GTD.', 'Beginner');

-- NOTE: Admin (Admin@SkillSwap.com / Admin123) is NOT stored in the users table.
-- Admin credentials are handled separately in the application login logic.
-- The users table only contains regular platform users.

-- NOTE: All sample users below have the password "password" (securely hashed)
INSERT INTO users (name, email, password_hash, location, bio, reliability_score, availability_schedule) VALUES
('Mr. Bobuddin', 'bob@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'San Francisco', 'Professional designer with 10 years experience.', 4.50, 'Tue 10:00-15:00'),
('Fida Haque Charlie', 'charlie@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Madrid', 'Native Spanish speaker and travel blogger.', 4.90, 'Fri 18:00-21:00'),
('Bhondu Mahi', 'mahi@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Los Angeles', 'Certified Yoga instructor with a focus on mindfulness.', 5.00, 'Sat 08:00-10:00'),
('Hukna Rafi', 'rafi@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'London', 'Digital marketing specialist and SEO consultant.', 4.20, NULL);

INSERT INTO user_skills_offered (user_id, skill_id) VALUES
(1, 8), (2, 7), (3, 6), (4, 5);

INSERT INTO user_skills_requested (user_id, skill_id) VALUES
(1, 9), (2, 1), (3, 7), (4, 2);

INSERT INTO reputation (user_id, current_score, completed_sessions) VALUES
(1, 4.5, 8), (2, 4.9, 15), (3, 5.0, 5), (4, 4.2, 7);

INSERT INTO wallet (user_id, balance) VALUES
(1, 50.00), (2, 75.00), (3, 120.00), (4, 30.00);

INSERT INTO community_task (user_id, task_type, description, location, credit_reward, status, submission_note) VALUES
(NULL, 'Library', 'Organize and label study materials in the community library.', 'Main Campus Library', 10.00, 'pending', NULL),
(NULL, 'Physical', 'Help set up tables and chairs for the weekend workshop event.', 'Community Center Hall B', 8.00, 'pending', NULL),
(1, 'Library', 'Sort donated books by category and shelve them.', 'City Public Library', 12.00, 'in-progress', NULL),
(3, 'Physical', 'Clean and organize shared workspace area.', 'Co-Working Hub Floor 2', 10.00, 'completed', 'Swept floors and wiped down all desks.');

INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status, scheduled_time, session_duration, time_credit_transfer, rating, comment) VALUES
(1, 3, 6, 'completed', '2026-05-10 14:00:00', 60, 20.00, 5, 'David is a great Math tutor!'),
(3, 4, 5, 'scheduled', '2026-05-12 09:00:00', 60, 25.00, NULL, NULL),
(2, 1, 8, 'completed', '2026-05-09 18:00:00', 30, 10.00, 5, 'Bob is a fantastic art teacher, very creative.'),
(4, 2, 7, 'completed', '2026-05-11 10:00:00', 45, 15.00, 4, 'Charlie made music lessons so fun!');

INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount) VALUES
(1, 1, 3, 'credit_transfer', 20.00, 20.00),
(3, 2, 1, 'credit_transfer', 10.00, 10.00),
(4, 4, 2, 'credit_transfer', 15.00, 15.00);



-- ============================================================
-- ADVANCED DATABASE OBJECTS
-- ============================================================
-- Below: Audit table, Triggers, Views, Stored Procedures
-- demonstrating complex SQL features for DBMS coursework.
-- ============================================================


-- =========================
-- AUDIT TABLE: wallet_audit_log
-- =========================
-- Tracks every wallet balance change for auditing purposes.
-- Populated automatically by the trg_after_wallet_update trigger.
-- =========================
CREATE TABLE IF NOT EXISTS wallet_audit_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    wallet_id INT,
    user_id INT,
    old_balance DECIMAL(15,2),
    new_balance DECIMAL(15,2),
    change_amount DECIMAL(15,2),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wallet_id) REFERENCES wallet(wallet_id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE INDEX idx_audit_user ON wallet_audit_log(user_id);
CREATE INDEX idx_audit_time ON wallet_audit_log(changed_at);

-- =========================
-- AUDIT TABLE: system_audit_log
-- =========================
CREATE TABLE IF NOT EXISTS system_audit_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action_type VARCHAR(50) NOT NULL,
    table_affected VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    details TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);
CREATE INDEX idx_sys_audit_table ON system_audit_log(table_affected);

-- =========================
-- ENTITY: notifications
-- =========================
CREATE TABLE IF NOT EXISTS notifications (
    notif_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);
CREATE INDEX idx_notif_user_read ON notifications(user_id, is_read);


-- ============================================================
-- TRIGGERS
-- ============================================================


-- =========================
-- TRIGGER TR-1: Auto-create wallet & reputation on new user
-- =========================
DROP TRIGGER IF EXISTS trg_after_user_insert;
DELIMITER //
CREATE TRIGGER trg_after_user_insert
AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT IGNORE INTO wallet (user_id, balance) VALUES (NEW.user_id, 10.00);
    INSERT IGNORE INTO reputation (user_id, current_score, completed_sessions, mentor_level)
        VALUES (NEW.user_id, 5.00, 0, 'Novice');
END //
DELIMITER ;


-- =========================
-- TRIGGER TR-2: Update reputation score on session rating
-- =========================
DROP TRIGGER IF EXISTS trg_after_session_rated;
DELIMITER //
CREATE TRIGGER trg_after_session_rated
AFTER UPDATE ON exchange_sessions
FOR EACH ROW
BEGIN
    IF NEW.rating IS NOT NULL AND (OLD.rating IS NULL OR OLD.rating != NEW.rating) THEN
        UPDATE reputation
        SET current_score = (
            SELECT ROUND(AVG(rating), 2)
            FROM exchange_sessions
            WHERE provider_id = NEW.provider_id AND rating IS NOT NULL
        )
        WHERE user_id = NEW.provider_id;
    END IF;
END //
DELIMITER ;


-- =========================
-- TRIGGER TR-3: Auto-update mentor level on reputation change
-- =========================
DROP TRIGGER IF EXISTS trg_before_reputation_update;
DELIMITER //
CREATE TRIGGER trg_before_reputation_update
BEFORE UPDATE ON reputation
FOR EACH ROW
BEGIN
    SET NEW.mentor_level = (
        CASE
            WHEN NEW.completed_sessions >= 50 THEN 'Grandmaster'
            WHEN NEW.completed_sessions >= 25 THEN 'Expert'
            WHEN NEW.completed_sessions >= 10 THEN 'Mentor'
            WHEN NEW.completed_sessions >= 5  THEN 'Intermediate'
            ELSE 'Novice'
        END
    );
END //
DELIMITER ;


-- =========================
-- TRIGGER TR-4: Prevent booking session with yourself
-- =========================
DROP TRIGGER IF EXISTS trg_before_session_insert;
DELIMITER //
CREATE TRIGGER trg_before_session_insert
BEFORE INSERT ON exchange_sessions
FOR EACH ROW
BEGIN
    IF NEW.requester_id = NEW.provider_id THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot book a session with yourself.';
    END IF;
END //
DELIMITER ;


-- =========================
-- TRIGGER TR-5: Log wallet balance changes (audit trail)
-- =========================
DROP TRIGGER IF EXISTS trg_after_wallet_update;
DELIMITER //
CREATE TRIGGER trg_after_wallet_update
AFTER UPDATE ON wallet
FOR EACH ROW
BEGIN
    IF OLD.balance != NEW.balance THEN
        INSERT INTO wallet_audit_log (wallet_id, user_id, old_balance, new_balance, change_amount)
        VALUES (NEW.wallet_id, NEW.user_id, OLD.balance, NEW.balance, NEW.balance - OLD.balance);
    END IF;
END //
DELIMITER ;

-- =========================
-- TRIGGER TR-6: Audit users status and score updates
-- =========================
DROP TRIGGER IF EXISTS trg_after_users_update;
DELIMITER //
CREATE TRIGGER trg_after_users_update
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status OR OLD.reliability_score != NEW.reliability_score THEN
        INSERT INTO system_audit_log (user_id, action_type, table_affected, record_id, details)
        VALUES (NEW.user_id, 'UPDATE', 'users', NEW.user_id, 
                CONCAT('Status: ', OLD.status, ' -> ', NEW.status, 
                       ', Reliability: ', OLD.reliability_score, ' -> ', NEW.reliability_score));
    END IF;
END //
DELIMITER ;

-- =========================
-- TRIGGER TR-7: Audit session status changes & trigger notifications
-- =========================
DROP TRIGGER IF EXISTS trg_after_sessions_update;
DELIMITER //
CREATE TRIGGER trg_after_sessions_update
AFTER UPDATE ON exchange_sessions
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO system_audit_log (user_id, action_type, table_affected, record_id, details)
        VALUES (NEW.requester_id, 'UPDATE', 'exchange_sessions', NEW.session_id, 
                CONCAT('Session status change: ', OLD.status, ' -> ', NEW.status));
        
        -- Notification dispatch
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
END //
DELIMITER ;

-- =========================
-- TRIGGER TR-8: Notify provider on new session booking
-- =========================
DROP TRIGGER IF EXISTS trg_after_session_insert;
DELIMITER //
CREATE TRIGGER trg_after_session_insert
AFTER INSERT ON exchange_sessions
FOR EACH ROW
BEGIN
    DECLARE v_req_name VARCHAR(100);
    SELECT name INTO v_req_name FROM users WHERE user_id = NEW.requester_id;
    INSERT INTO notifications (user_id, message, type)
    VALUES (NEW.provider_id, CONCAT(v_req_name, ' booked a session with you for ', NEW.scheduled_time), 'booking');
END //
DELIMITER ;


-- ============================================================
-- VIEWS
-- ============================================================


-- =========================
-- STAR-SCHEMA DATA WAREHOUSE VIEWS
-- =========================
CREATE OR REPLACE VIEW vw_dim_users AS
SELECT user_id, name, email, location, reliability_score, created_at
FROM users;

CREATE OR REPLACE VIEW vw_dim_skills AS
SELECT skill_id, skill_name, catagory AS category, difficulty_level
FROM skills;

CREATE OR REPLACE VIEW vw_dim_time AS
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
FROM exchange_sessions;

CREATE OR REPLACE VIEW vw_fact_sessions AS
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
FROM exchange_sessions es;

-- =========================
-- VIEW: vw_user_dashboard
-- =========================
CREATE OR REPLACE VIEW vw_user_dashboard AS
SELECT
    u.user_id,
    u.name,
    u.email,
    u.location,
    u.status,
    u.created_at,
    COALESCE(w.balance, 0) AS wallet_balance,
    COALESCE(r.current_score, 5.00) AS reputation_score,
    COALESCE(r.completed_sessions, 0) AS total_completed_sessions,
    COALESCE(r.cancelled_sessions, 0) AS cancelled_sessions,
    r.mentor_level,
    (SELECT COUNT(*) FROM user_skills_offered WHERE user_id = u.user_id) AS skills_offered_count,
    (SELECT COUNT(*) FROM user_skills_requested WHERE user_id = u.user_id) AS skills_requested_count,
    (SELECT COUNT(*) FROM exchange_sessions
     WHERE (requester_id = u.user_id OR provider_id = u.user_id)
       AND status = 'scheduled') AS upcoming_sessions
FROM users u
LEFT JOIN wallet w ON u.user_id = w.user_id
LEFT JOIN reputation r ON u.user_id = r.user_id;


-- =========================
-- VIEW: vw_skill_marketplace
-- =========================
CREATE OR REPLACE VIEW vw_skill_marketplace AS
SELECT
    s.skill_id,
    s.skill_name,
    s.catagory,
    s.description,
    s.difficulty_level,
    COUNT(DISTINCT uso.user_id) AS provider_count,
    COUNT(DISTINCT usr.user_id) AS learner_count,
    (SELECT COUNT(*) FROM exchange_sessions WHERE skill_id = s.skill_id AND status = 'completed') AS total_sessions,
    (SELECT ROUND(AVG(rating), 1) FROM exchange_sessions
     WHERE skill_id = s.skill_id AND rating IS NOT NULL) AS avg_skill_rating
FROM skills s
LEFT JOIN user_skills_offered uso ON s.skill_id = uso.skill_id
LEFT JOIN user_skills_requested usr ON s.skill_id = usr.skill_id
GROUP BY s.skill_id;


-- =========================
-- VIEW: vw_transaction_ledger
-- =========================
CREATE OR REPLACE VIEW vw_transaction_ledger AS
SELECT
    t.transcation_id,
    t.session_id,
    t.from_user_id,
    t.to_user_id,
    t.type,
    t.base_amount,
    t.final_amount,
    t.note,
    t.timestamp,
    COALESCE(u_from.name, 'Platform / System') AS sender_name,
    COALESCE(u_from.email, 'system@skillswap.com') AS sender_email,
    COALESCE(u_to.name, 'Platform / System') AS receiver_name,
    COALESCE(u_to.email, 'system@skillswap.com') AS receiver_email,
    s.skill_name,
    es.status AS session_status,
    CASE
        WHEN t.type = 'credit_transfer' THEN 'Session Payment'
        WHEN t.type = 'community_reward' THEN 'Community Reward'
        WHEN t.type = 'full_refund' THEN 'Full Refund'
        WHEN t.type = 'partial_refund' THEN 'Partial Refund'
        WHEN t.type = 'loan_disbursement' THEN 'Loan Disbursement'
        WHEN t.type = 'loan_repayment' THEN 'Loan Repayment'
        WHEN t.type = 'gift' THEN 'Gift'
        ELSE 'Other'
    END AS transaction_category
FROM transactions t
LEFT JOIN users u_from ON t.from_user_id = u_from.user_id
LEFT JOIN users u_to ON t.to_user_id = u_to.user_id
LEFT JOIN exchange_sessions es ON t.session_id = es.session_id
LEFT JOIN skills s ON es.skill_id = s.skill_id;


-- ============================================================
-- STORED PROCEDURES
-- ============================================================


-- =========================
-- PROCEDURE: sp_complete_session
-- =========================
DELIMITER //
CREATE PROCEDURE sp_complete_session(
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

    -- Fetch session details
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

        -- 1. Mark session completed
        UPDATE exchange_sessions
        SET status = 'completed', completion_time = NOW()
        WHERE session_id = p_session_id;

        -- 2. Transfer credits to provider
        UPDATE wallet SET balance = balance + v_amount
        WHERE user_id = v_actual_provider_id;

        -- 3. Log transaction
        INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, note)
        VALUES (p_session_id, v_requester_id, v_actual_provider_id, 'credit_transfer', v_amount, v_amount, 'Time credit transfer for completed session');

        -- 4. Update provider reputation
        UPDATE reputation
        SET completed_sessions = completed_sessions + 1
        WHERE user_id = v_actual_provider_id;

        COMMIT;
        SET p_status = 'success';
        SET p_message = CONCAT('Session completed! ', v_amount, ' TC transferred.');
    END IF;
END //
DELIMITER ;


-- =========================
-- PROCEDURE: sp_book_session
-- =========================
-- Fixes applied:
-- [Flaw 7] Defaulted loans block session booking
-- =========================
DELIMITER //
CREATE PROCEDURE sp_book_session(
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

    START TRANSACTION;

    -- Check balance using subquery with row lock
    SELECT balance INTO v_balance FROM wallet WHERE user_id = p_requester_id FOR UPDATE;

    -- [Flaw 7] Check for defaulted loans
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
        -- [Flaw 7] Block session booking for defaulted users
        SET p_status = 'error';
        SET p_message = 'Your account has a defaulted loan. Please repay it before booking new sessions.';
    ELSEIF v_has_conflict THEN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Schedule conflict detected. Either you or the provider has an overlapping active session scheduled at this time.';
    ELSEIF v_balance IS NULL OR v_balance < v_credit_cost THEN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = CONCAT('Insufficient balance. Need ', v_credit_cost, ' TC, have ', COALESCE(v_balance, 0), ' TC.');
    ELSEIF p_scheduled_time <= NOW() THEN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Cannot book a session in the past.';
    ELSE
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
END //
DELIMITER ;


-- =========================
-- PROCEDURE: sp_issue_refund
-- =========================
DELIMITER //
CREATE PROCEDURE sp_issue_refund(
    IN p_session_id INT,
    IN p_refund_amount DECIMAL(10,2),
    IN p_reason TEXT,
    IN p_refund_window_days INT,
    OUT p_status VARCHAR(50),
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_max_amount DECIMAL(10,2);
    DECLARE v_requester_id INT;
    DECLARE v_provider_id INT;
    DECLARE v_session_status VARCHAR(20);
    DECLARE v_completion_time DATETIME;
    DECLARE v_days_since DECIMAL(10,2);
    DECLARE v_provider_balance DECIMAL(15,2);
    DECLARE v_refund_type VARCHAR(20);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Refund failed due to a database error.';
    END;

    -- Fetch session info
    SELECT status, time_credit_transfer, requester_id, provider_id,
           COALESCE(completion_time, scheduled_time)
    INTO v_session_status, v_max_amount, v_requester_id, v_provider_id, v_completion_time
    FROM exchange_sessions WHERE session_id = p_session_id;

    SET v_days_since = TIMESTAMPDIFF(SECOND, v_completion_time, NOW()) / 86400.0;

    IF v_session_status IS NULL THEN
        SET p_status = 'error';
        SET p_message = CONCAT('Session #', p_session_id, ' not found.');
    ELSEIF v_session_status != 'completed' THEN
        SET p_status = 'error';
        SET p_message = 'Only completed sessions can be refunded.';
    ELSEIF v_days_since > p_refund_window_days THEN
        SET p_status = 'error';
        SET p_message = CONCAT('Refund window expired (', ROUND(v_days_since, 1), ' days ago).');
    ELSEIF p_refund_amount <= 0 OR p_refund_amount > v_max_amount THEN
        SET p_status = 'error';
        SET p_message = CONCAT('Amount must be between 0.01 and ', v_max_amount, ' TC.');
    ELSE
        -- Check provider balance
        SELECT COALESCE(balance, 0) INTO v_provider_balance FROM wallet WHERE user_id = v_provider_id;
        IF v_provider_balance < p_refund_amount THEN
            SET p_status = 'error';
            SET p_message = CONCAT('Provider balance (', v_provider_balance, ' TC) insufficient for refund.');
        ELSE
            START TRANSACTION;

            -- 1. Return credits to requester
            UPDATE wallet SET balance = balance + p_refund_amount WHERE user_id = v_requester_id;

            -- 2. Deduct from provider
            UPDATE wallet SET balance = balance - p_refund_amount WHERE user_id = v_provider_id;

            -- 3. Update session status and clear rating
            UPDATE exchange_sessions
            SET status = 'refunded', rating = NULL, comment = NULL
            WHERE session_id = p_session_id;

            -- 4. Update provider reputation
            UPDATE reputation
            SET completed_sessions = GREATEST(completed_sessions - 1, 0),
                cancelled_sessions = cancelled_sessions + 1
            WHERE user_id = v_provider_id;

            -- 5. Log refund transaction with type determined by IF()
            SET v_refund_type = IF(p_refund_amount < v_max_amount, 'partial_refund', 'full_refund');
            INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, note)
            VALUES (p_session_id, v_provider_id, v_requester_id, v_refund_type, v_max_amount, p_refund_amount, p_reason);

            COMMIT;
            SET p_status = 'success';
            SET p_message = CONCAT(v_refund_type, ': ', p_refund_amount, ' TC refunded successfully.');
        END IF;
    END IF;
END //
DELIMITER ;


-- =========================
-- PROCEDURE: sp_register_user
-- =========================
DELIMITER //
CREATE PROCEDURE sp_register_user(
    IN p_name VARCHAR(100),
    IN p_email VARCHAR(100),
    IN p_password_hash VARCHAR(255),
    IN p_location VARCHAR(100),
    IN p_bio TEXT,
    OUT p_status VARCHAR(50),
    OUT p_user_id INT
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_status = 'error';
        SET p_user_id = 0;
    END;

    -- Check duplicate using EXISTS subquery
    IF EXISTS (SELECT 1 FROM users WHERE email = p_email) THEN
        SET p_status = 'duplicate';
        SET p_user_id = 0;
    ELSE
        START TRANSACTION;

        INSERT INTO users (name, email, password_hash, location, bio)
        VALUES (p_name, p_email, p_password_hash, p_location, p_bio);

        SET p_user_id = LAST_INSERT_ID();

        -- Wallet with welcome bonus (also triggered by TR-1, but explicit here)
        INSERT INTO wallet (user_id, balance) VALUES (p_user_id, 10.00)
        ON DUPLICATE KEY UPDATE balance = balance;

        -- Reputation init (also triggered by TR-1, but explicit here)
        INSERT INTO reputation (user_id, current_score, completed_sessions, mentor_level)
        VALUES (p_user_id, 5.00, 0, 'Novice')
        ON DUPLICATE KEY UPDATE current_score = current_score;

        COMMIT;
        SET p_status = 'success';
    END IF;
END //
DELIMITER ;


-- ============================================================
-- TIME CREDIT LOANS & GIFTING EXTENSIONS
-- ============================================================

-- =========================
-- ENTITY: loans
-- =========================
-- Fixes applied:
-- [Flaw 2] Added interest_rate and total_due (generated column) for interest tracking
-- [Flaw 4] Added repaid_at timestamp to track when a loan was repaid
-- =========================
CREATE TABLE IF NOT EXISTS loans (
    loan_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL CHECK (amount > 0.00),
    interest_rate DECIMAL(5, 2) NOT NULL DEFAULT 5.00,
    total_due DECIMAL(10, 2) GENERATED ALWAYS AS (amount * (1 + interest_rate / 100)) STORED,
    due_date DATETIME NOT NULL,
    status ENUM('active', 'paid', 'defaulted') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    repaid_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE INDEX idx_loans_user ON loans(user_id);
CREATE INDEX idx_loans_status ON loans(status);
CREATE INDEX idx_loans_due_date ON loans(due_date);


-- =========================
-- PROCEDURE SP-5: Request Credit Loan
-- =========================
-- Fixes applied:
-- [Flaw 3] Race condition fix — SELECT ... FOR UPDATE inside transaction
-- [Flaw 6] Gift → Loan cooldown — 7-day block after receiving a gift
-- =========================
DELIMITER //
CREATE PROCEDURE sp_request_loan(
    IN p_user_id INT,
    IN p_amount DECIMAL(10, 2),
    OUT p_status VARCHAR(50),
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_active_loans INT;
    DECLARE v_completed_sessions INT;
    DECLARE v_reliability DECIMAL(5,2);
    DECLARE v_max_limit DECIMAL(10,2);
    DECLARE v_recent_gift_received BOOLEAN;
    DECLARE v_wallet_balance DECIMAL(15,2);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Loan request failed due to a database error.';
    END;

    -- Complex Check: Verify if user has any outstanding unpaid loans
    SELECT COUNT(*) INTO v_active_loans 
    FROM loans 
    WHERE user_id = p_user_id AND status IN ('active', 'defaulted');
    
    -- Fetch reliability and completed session counts
    SELECT reliability_score INTO v_reliability FROM users WHERE user_id = p_user_id;
    SELECT completed_sessions INTO v_completed_sessions FROM reputation WHERE user_id = p_user_id;
    
    -- Credit limit is dynamically set: reliability_score * 5.00
    SET v_max_limit = COALESCE(v_reliability, 5.00) * 5.00;

    -- [Flaw 6] Check if user received a gift in the last 7 days
    SELECT EXISTS (
        SELECT 1 FROM transactions
        WHERE to_user_id = p_user_id
          AND type = 'gift'
          AND timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ) INTO v_recent_gift_received;

    IF v_active_loans > 0 THEN
        SET p_status = 'error';
        SET p_message = 'You have an outstanding active or defaulted credit loan.';
    ELSEIF COALESCE(v_completed_sessions, 0) < 2 THEN
        SET p_status = 'error';
        SET p_message = 'Minimum requirement: You must complete at least 2 sessions to borrow credits.';
    ELSEIF p_amount <= 0 OR p_amount > v_max_limit THEN
        SET p_status = 'error';
        SET p_message = CONCAT('Invalid loan amount. Your maximum borrow limit is ', v_max_limit, ' TC.');
    ELSEIF v_recent_gift_received THEN
        -- [Flaw 6] Cooldown period after receiving gifts
        SET p_status = 'error';
        SET p_message = 'Loan cooldown: You received a gift in the last 7 days. Please wait before borrowing to prevent credit cycling.';
    ELSE
        -- [Flaw 3] Start transaction and lock wallet row
        START TRANSACTION;

        SELECT balance INTO v_wallet_balance
        FROM wallet
        WHERE user_id = p_user_id
        FOR UPDATE;

        -- 1. Insert loan record
        INSERT INTO loans (user_id, amount, due_date) 
        VALUES (p_user_id, p_amount, DATE_ADD(NOW(), INTERVAL 30 DAY));
        
        -- 2. Update wallet balance
        UPDATE wallet SET balance = balance + p_amount WHERE user_id = p_user_id;
        
        -- 3. Log transaction (from_user_id NULL representing platform)
        INSERT INTO transactions (from_user_id, to_user_id, type, base_amount, final_amount, note)
        VALUES (NULL, p_user_id, 'loan_disbursement', p_amount, p_amount, 'Platform credit loan disbursement');

        COMMIT;
        SET p_status = 'success';
        SET p_message = CONCAT('Loan of ', p_amount, ' TC successfully disbursed to your wallet.');
    END IF;
END //
DELIMITER ;


-- =========================
-- PROCEDURE SP-6: Repay Credit Loan
-- =========================
-- Fixes applied:
-- [Flaw 2] Repayment uses total_due (principal + interest) instead of base amount
-- [Flaw 4] Records repaid_at timestamp on repayment
-- =========================
DELIMITER //
CREATE PROCEDURE sp_repay_loan(
    IN p_user_id INT,
    IN p_loan_id INT,
    OUT p_status VARCHAR(50),
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_base_amount DECIMAL(10,2);
    DECLARE v_total_due DECIMAL(10,2);
    DECLARE v_loan_status VARCHAR(20);
    DECLARE v_balance DECIMAL(15,2);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Loan repayment failed due to a database error.';
    END;

    -- Fetch loan details including total_due (with interest)
    SELECT amount, total_due, status INTO v_base_amount, v_total_due, v_loan_status 
    FROM loans 
    WHERE loan_id = p_loan_id AND user_id = p_user_id;
    
    -- Fetch wallet balance
    SELECT balance INTO v_balance FROM wallet WHERE user_id = p_user_id;

    IF v_base_amount IS NULL THEN
        SET p_status = 'error';
        SET p_message = 'Loan record not found.';
    ELSEIF v_loan_status = 'paid' THEN
        SET p_status = 'error';
        SET p_message = 'This loan is already fully repaid.';
    ELSEIF v_balance < v_total_due THEN
        SET p_status = 'error';
        SET p_message = CONCAT('Insufficient balance. Repayment requires ', v_total_due, ' TC (includes interest), but your balance is ', v_balance, ' TC.');
    ELSE
        START TRANSACTION;

        -- 1. Mark loan as paid with repayment timestamp [Flaw 4]
        UPDATE loans SET status = 'paid', repaid_at = NOW() WHERE loan_id = p_loan_id;
        
        -- 2. Deduct total_due (principal + interest) from user's wallet
        UPDATE wallet SET balance = balance - v_total_due WHERE user_id = p_user_id;
        
        -- 3. Log transaction — base_amount is the principal, final_amount includes interest
        INSERT INTO transactions (from_user_id, to_user_id, type, base_amount, final_amount, note)
        VALUES (p_user_id, NULL, 'loan_repayment', v_base_amount, v_total_due, 
                CONCAT('Loan repayment: ', v_base_amount, ' TC principal + ', (v_total_due - v_base_amount), ' TC interest'));

        COMMIT;
        SET p_status = 'success';
        SET p_message = CONCAT('Loan repaid! ', v_total_due, ' TC deducted (', v_base_amount, ' principal + ', (v_total_due - v_base_amount), ' interest).');
    END IF;
END //
DELIMITER ;


-- =========================
-- PROCEDURE SP-7: Gift Time Credits
-- =========================
-- Fixes applied:
-- [Flaw 1] Loaned credits cannot be gifted — deducts active loan debt from giftable balance
-- [Flaw 3] Race condition fix — SELECT ... FOR UPDATE inside transaction
-- [Flaw 5] Daily (50 TC) and per-transaction (25 TC) gift caps
-- =========================
DELIMITER //
CREATE PROCEDURE sp_gift_credits(
    IN p_from_user_id INT,
    IN p_to_user_email VARCHAR(100),
    IN p_amount DECIMAL(10, 2),
    OUT p_status VARCHAR(50),
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE v_to_user_id INT;
    DECLARE v_from_balance DECIMAL(15, 2);
    DECLARE v_has_mutual_session BOOLEAN;
    DECLARE v_both_established BOOLEAN;
    DECLARE v_loan_debt DECIMAL(15, 2);
    DECLARE v_giftable_balance DECIMAL(15, 2);
    DECLARE v_daily_gifted DECIMAL(15, 2);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_status = 'error';
        SET p_message = 'Gift transaction failed due to a database error.';
    END;

    -- Get recipient ID (only active users)
    SELECT user_id INTO v_to_user_id FROM users WHERE email = p_to_user_email AND status = 'active';

    -- === Pre-validation (no transaction needed) ===
    IF v_to_user_id IS NULL THEN
        SET p_status = 'error';
        SET p_message = 'Recipient email not found or account is suspended.';
    ELSEIF v_to_user_id = p_from_user_id THEN
        SET p_status = 'error';
        SET p_message = 'You cannot gift credits to yourself.';
    ELSEIF p_amount <= 0 THEN
        SET p_status = 'error';
        SET p_message = 'Gift amount must be greater than zero.';
    ELSEIF p_amount > 25 THEN
        -- [Flaw 5] Per-transaction cap
        SET p_status = 'error';
        SET p_message = 'Maximum gift amount per transaction is 25 TC.';
    ELSE
        -- === [Flaw 3] Start transaction and lock wallet row ===
        START TRANSACTION;

        -- Lock the sender's wallet row to prevent race conditions
        SELECT balance INTO v_from_balance 
        FROM wallet 
        WHERE user_id = p_from_user_id 
        FOR UPDATE;

        -- [Flaw 1] Calculate outstanding loan debt
        SELECT COALESCE(SUM(amount), 0) INTO v_loan_debt
        FROM loans
        WHERE user_id = p_from_user_id AND status = 'active';

        -- Giftable balance = actual balance minus reserved loan debt
        SET v_giftable_balance = v_from_balance - v_loan_debt;

        -- [Flaw 5] Check daily gift total
        SELECT COALESCE(SUM(final_amount), 0) INTO v_daily_gifted
        FROM transactions
        WHERE from_user_id = p_from_user_id 
          AND type = 'gift' 
          AND DATE(timestamp) = CURDATE();

        -- Complex Subquery 1: Verify mutual session collaboration
        SELECT EXISTS (
            SELECT 1 FROM exchange_sessions
            WHERE status = 'completed'
              AND ((requester_id = p_from_user_id AND provider_id = v_to_user_id) 
                OR (requester_id = v_to_user_id AND provider_id = p_from_user_id))
        ) INTO v_has_mutual_session;

        -- Complex Subquery 2: Both must be established (3+ completed sessions globally)
        SELECT EXISTS (
            SELECT 1 FROM reputation WHERE user_id = p_from_user_id AND completed_sessions >= 3
        ) AND EXISTS (
            SELECT 1 FROM reputation WHERE user_id = v_to_user_id AND completed_sessions >= 3
        ) INTO v_both_established;

        -- === Validation inside transaction ===
        IF v_giftable_balance < p_amount THEN
            -- [Flaw 1] Insufficient giftable balance (accounts for loan reservation)
            ROLLBACK;
            SET p_status = 'error';
            IF v_loan_debt > 0 THEN
                SET p_message = CONCAT('Insufficient giftable balance. You have ', v_from_balance, ' TC but ', v_loan_debt, ' TC is reserved for loan repayment. Available: ', v_giftable_balance, ' TC.');
            ELSE
                SET p_message = CONCAT('Insufficient balance. You have ', v_from_balance, ' TC.');
            END IF;
        ELSEIF (v_daily_gifted + p_amount) > 50 THEN
            -- [Flaw 5] Daily cap exceeded
            ROLLBACK;
            SET p_status = 'error';
            SET p_message = CONCAT('Daily gift limit exceeded. You have already gifted ', v_daily_gifted, ' TC today. Daily limit is 50 TC.');
        ELSEIF NOT v_has_mutual_session AND NOT v_both_established THEN
            ROLLBACK;
            SET p_status = 'error';
            SET p_message = 'Gifting blocked. Both established or mutual session history required.';
        ELSE
            -- 1. Deduct from donor
            UPDATE wallet SET balance = balance - p_amount WHERE user_id = p_from_user_id;
            
            -- 2. Add to recipient
            UPDATE wallet SET balance = balance + p_amount WHERE user_id = v_to_user_id;
            
            -- 3. Log transaction
            INSERT INTO transactions (from_user_id, to_user_id, type, base_amount, final_amount, note)
            VALUES (p_from_user_id, v_to_user_id, 'gift', p_amount, p_amount, 'Peer-to-peer time credit gift');

            COMMIT;
            SET p_status = 'success';
            SET p_message = CONCAT('Successfully gifted ', p_amount, ' TC to ', p_to_user_email);
        END IF;
    END IF;
END //
DELIMITER ;


-- ============================================================
-- LOAN ENFORCEMENT & AUDIT EXTENSIONS
-- ============================================================

-- =========================
-- [Flaw 8] AUDIT TABLE: loan_audit_log
-- =========================
-- Tracks every loan status change (active → paid, active → defaulted)
-- Populated automatically by trg_after_loan_status_change trigger.
-- =========================
CREATE TABLE IF NOT EXISTS loan_audit_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    user_id INT NOT NULL,
    old_status VARCHAR(20),
    new_status VARCHAR(20),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE INDEX idx_loan_audit_loan ON loan_audit_log(loan_id);
CREATE INDEX idx_loan_audit_user ON loan_audit_log(user_id);


-- =========================
-- [Flaw 8] TRIGGER: trg_after_loan_status_change
-- =========================
-- Automatically logs any loan status transition to loan_audit_log.
-- =========================
DROP TRIGGER IF EXISTS trg_after_loan_status_change;
DELIMITER //
CREATE TRIGGER trg_after_loan_status_change
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
END //
DELIMITER ;


-- =========================
-- [Flaw 2] EVENT: evt_check_overdue_loans
-- =========================
-- Runs daily to automatically:
-- 1. Mark overdue active loans as 'defaulted'
-- 2. Penalize the user's reliability_score by -1.0 (min 0)
-- Requires: SET GLOBAL event_scheduler = ON;
-- =========================
DELIMITER //
CREATE EVENT IF NOT EXISTS evt_check_overdue_loans
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
    -- Step 1: Penalize reliability score for users whose loans are about to be defaulted
    UPDATE users u
    JOIN loans l ON u.user_id = l.user_id
    SET u.reliability_score = GREATEST(u.reliability_score - 1.0, 0)
    WHERE l.status = 'active' AND l.due_date < NOW();

    -- Step 2: Mark overdue active loans as defaulted
    -- (This triggers trg_after_loan_status_change to log the audit entry)
    UPDATE loans 
    SET status = 'defaulted'
    WHERE status = 'active' AND due_date < NOW();
END //
DELIMITER ;

-- (Duplicate audit triggers removed; defined in main triggers section above)


-- ============================================================
-- STAR-SCHEMA OLAP WAREHOUSE VIEWS
-- ============================================================

CREATE OR REPLACE VIEW vw_dim_users AS
SELECT user_id, name, email, location, reliability_score, created_at
FROM users;

CREATE OR REPLACE VIEW vw_dim_skills AS
SELECT skill_id, skill_name, catagory AS category, difficulty_level
FROM skills;

CREATE OR REPLACE VIEW vw_dim_time AS
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
FROM exchange_sessions;

CREATE OR REPLACE VIEW vw_fact_sessions AS
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
FROM exchange_sessions es;

-- ============================================================
-- PEER-TO-PEER MESSAGING TABLES & INDEXES
-- ============================================================

CREATE TABLE IF NOT EXISTS conversations (
    conversation_id INT AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS conversation_members (
    conversation_id INT,
    user_id INT,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    message_text TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE INDEX idx_messages_thread ON messages(conversation_id, sent_at DESC);


-- ============================================================
-- PHASE 6: DISPUTES, VIEWS & RESOLUTION PROCEDURES
-- ============================================================

CREATE TABLE IF NOT EXISTS disputes (
    dispute_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    filed_by_user_id INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('open', 'resolved_refunded', 'resolved_payout') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES exchange_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (filed_by_user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE OR REPLACE VIEW vw_public_users AS
SELECT user_id, name, location, bio, reliability_score, status, created_at, last_active_at
FROM users;

CREATE OR REPLACE VIEW vw_smart_matches AS
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
WHERE u.status = 'active';

DROP PROCEDURE IF EXISTS sp_resolve_dispute;
DELIMITER //
CREATE PROCEDURE sp_resolve_dispute(
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
END //
DELIMITER ;

ALTER TABLE exchange_sessions MODIFY COLUMN status ENUM('scheduled', 'under-review', 'completed', 'cancelled', 'refunded', 'disputed') DEFAULT 'scheduled';



CREATE DATABASE IF NOT EXISTS Skillswap;
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    availability_schedule TEXT
);

-- email already has a UNIQUE index
CREATE INDEX idx_users_location ON users(location);
CREATE INDEX idx_users_last_active ON users(last_active_at);
CREATE INDEX idx_users_reliability ON users(reliability_score);


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
    balance DECIMAL(15, 2) DEFAULT 0.00,
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
    rep_boost DECIMAL(5, 2) DEFAULT 0.25,
    status ENUM('pending', 'in-progress', 'completed', 'cancelled') DEFAULT 'pending',
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
    status ENUM('scheduled', 'completed', 'cancelled') DEFAULT 'scheduled',
    scheduled_time DATETIME NOT NULL,
    completion_time DATETIME NULL,
    session_duration INT,
    feedback_given BOOLEAN DEFAULT FALSE,
    time_credit_transfer DECIMAL(10, 2),
    rating INT CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    bonus_multiplier DECIMAL(3, 2) DEFAULT 1.00,
    FOREIGN KEY (requester_id) REFERENCES users(user_id),
    FOREIGN KEY (provider_id) REFERENCES users(user_id),
    FOREIGN KEY (skill_id) REFERENCES skills(skill_id)
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
    from_user_id INT NOT NULL,
    to_user_id INT NOT NULL,
    type VARCHAR(50),
    base_amount DECIMAL(10, 2),
    final_amount DECIMAL(10, 2),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES exchange_sessions(session_id) ON DELETE SET NULL,
    FOREIGN KEY (from_user_id) REFERENCES users(user_id),
    FOREIGN KEY (to_user_id) REFERENCES users(user_id)
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

INSERT INTO users (name, email, password_hash, location, bio, reliability_score, availability_schedule) VALUES
('Bob Smith', 'bob@example.com', '1234', 'San Francisco', 'Professional designer with 10 years experience.', 4.50, 'Tue 10:00-15:00'),
('Charlie Brown', 'charlie@example.com', '1234', 'Madrid', 'Native Spanish speaker and travel blogger.', 4.90, 'Fri 18:00-21:00'),
('David Miller', 'david@example.com', '1234', 'Los Angeles', 'Certified Yoga instructor with a focus on mindfulness.', 5.00, 'Sat 08:00-10:00'),
('Eva Green', 'eva@example.com', '1234', 'London', 'Digital marketing specialist and SEO consultant.', 4.20, NULL);

INSERT INTO user_skills_offered (user_id, skill_id) VALUES
(1, 8), (2, 7), (3, 6), (4, 5);

INSERT INTO user_skills_requested (user_id, skill_id) VALUES
(1, 9), (2, 1), (3, 7), (4, 2);

INSERT INTO reputation (user_id, current_score, completed_sessions) VALUES
(1, 4.5, 8), (2, 4.9, 15), (3, 5.0, 5), (4, 4.2, 7);

INSERT INTO wallet (user_id, balance) VALUES
(1, 50.00), (2, 75.00), (3, 120.00), (4, 30.00);

INSERT INTO community_task (user_id, task_type, description, location, rep_boost, status) VALUES
(NULL, 'Library', 'Organize and label study materials in the community library.', 'Main Campus Library', 0.30, 'pending'),
(NULL, 'Physical', 'Help set up tables and chairs for the weekend workshop event.', 'Community Center Hall B', 0.25, 'pending'),
(NULL, 'Admin', 'Update the community notice board with latest announcements.', 'Student Union Office', 0.20, 'pending'),
(1, 'Library', 'Sort donated books by category and shelve them.', 'City Public Library', 0.35, 'in-progress'),
(3, 'Physical', 'Clean and organize shared workspace area.', 'Co-Working Hub Floor 2', 0.30, 'completed');

INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status, scheduled_time, session_duration, time_credit_transfer, rating, comment) VALUES
(1, 3, 6, 'completed', '2026-05-10 14:00:00', 60, 20.00, 5, 'David is a great Math tutor!'),
(3, 4, 5, 'scheduled', '2026-05-12 09:00:00', 60, 25.00, NULL, NULL),
(2, 1, 8, 'completed', '2026-05-09 18:00:00', 30, 10.00, 5, 'Bob is a fantastic art teacher, very creative.'),
(4, 2, 7, 'completed', '2026-05-11 10:00:00', 45, 15.00, 4, 'Charlie made music lessons so fun!');

INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount) VALUES
(1, 1, 3, 'credit_transfer', 20.00, 20.00),
(3, 2, 1, 'credit_transfer', 10.00, 10.00),
(4, 4, 2, 'credit_transfer', 15.00, 15.00);


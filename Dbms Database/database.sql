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
    location VARCHAR(100) NOT NULL,
    bio TEXT,
    profile_photo MEDIUMBLOB,
    profile_photo_mime VARCHAR(50),
    reliability_score DECIMAL(5, 2) DEFAULT NULL,
    status ENUM('active', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    availability_schedule TEXT,
    availability_locked TINYINT(1) DEFAULT 0
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
    current_score DECIMAL(5, 2) DEFAULT NULL,
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
INSERT INTO users (name, email, password_hash, location, bio, reliability_score, status, created_at) VALUES
('Rahim Rahman', 'rahim.rahman@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dhaka, Bangladesh', 'Passionate about teaching and learning new things every day.', 4.03, 'active', '2025-10-14 13:34:58'),
('Karim Hossain', 'karim.hossain@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Chittagong, Bangladesh', 'Full-stack developer who loves sharing knowledge with the community.', 4.18, 'active', '2026-03-28 13:34:58'),
('Rafiq Islam', 'rafiq.islam@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sylhet, Bangladesh', 'Creative designer with a knack for minimalist interfaces.', 3.57, 'active', '2026-03-08 13:34:58'),
('Shafiq Ahmed', 'shafiq.ahmed@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rajshahi, Bangladesh', 'Language enthusiast who speaks 3 languages fluently.', 4.39, 'active', '2026-04-06 13:34:58'),
('Jashim Ali', 'jashim.ali@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Khulna, Bangladesh', 'Music teacher with 10 years of experience in classical piano.', 4.37, 'active', '2025-07-25 13:34:58'),
('Kabir Chowdhury', 'kabir.chowdhury@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Barisal, Bangladesh', 'Data scientist exploring the intersection of AI and education.', 4.81, 'active', '2026-03-16 13:34:58'),
('Nazmul Khan', 'nazmul.khan@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rangpur, Bangladesh', 'Fitness trainer specializing in home workouts and nutrition.', 4.09, 'active', '2025-07-14 13:34:58'),
('Sakib Sikder', 'sakib.sikder@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mymensingh, Bangladesh', 'Amateur photographer who loves capturing urban landscapes.', 4.23, 'active', '2026-02-07 13:34:58'),
('Tamim Molla', 'tamim.molla@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Comilla, Bangladesh', 'Marketing specialist with expertise in digital growth strategies.', 4.47, 'active', '2026-04-11 13:34:58'),
('Mushfiq Mia', 'mushfiq.mia@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Narayanganj, Bangladesh', 'College student eager to learn everything from cooking to coding.', 4.49, 'active', '2026-02-28 13:34:58'),
('Mahmudullah Haque', 'mahmudullah.haque@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Gazipur, Bangladesh', 'Passionate about teaching and learning new things every day.', 4.10, 'active', '2025-10-05 13:34:58'),
('Liton Siddique', 'liton.siddique@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Bogra, Bangladesh', 'Full-stack developer who loves sharing knowledge with the community.', 4.88, 'active', '2026-03-13 13:34:58'),
('Soumya Khandaker', 'soumya.khandaker@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dhaka, Bangladesh', 'Creative designer with a knack for minimalist interfaces.', 4.83, 'active', '2025-06-12 13:34:58'),
('Mehidy Bhuiyan', 'mehidy.bhuiyan@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Chittagong, Bangladesh', 'Language enthusiast who speaks 3 languages fluently.', 4.69, 'active', '2025-08-19 13:34:58'),
('Taskin Majumder', 'taskin.majumder@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sylhet, Bangladesh', 'Music teacher with 10 years of experience in classical piano.', 4.02, 'active', '2026-03-20 13:34:58'),
('Mustafizur Talukder', 'mustafizur.talukder@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rajshahi, Bangladesh', 'Data scientist exploring the intersection of AI and education.', 4.71, 'active', '2025-09-30 13:34:58'),
('Rubel Howlader', 'rubel.howlader@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Khulna, Bangladesh', 'Fitness trainer specializing in home workouts and nutrition.', 4.98, 'active', '2025-12-06 13:34:58'),
('Anamul Sardar', 'anamul.sardar@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Barisal, Bangladesh', 'Amateur photographer who loves capturing urban landscapes.', 4.79, 'active', '2025-10-31 13:34:58'),
('Mominul Dewan', 'mominul.dewan@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rangpur, Bangladesh', 'Marketing specialist with expertise in digital growth strategies.', 4.52, 'active', '2025-06-16 13:34:58'),
('Imrul Munshi', 'imrul.munshi@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mymensingh, Bangladesh', 'College student eager to learn everything from cooking to coding.', 4.87, 'active', '2026-03-22 13:34:58'),
('Sadia Akter', 'sadia.akter@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Comilla, Bangladesh', 'Passionate about teaching and learning new things every day.', 4.58, 'active', '2025-08-10 13:34:58'),
('Nusrat Begum', 'nusrat.begum@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Narayanganj, Bangladesh', 'Full-stack developer who loves sharing knowledge with the community.', 4.34, 'active', '2026-03-10 13:34:58'),
('Sumaiya Khatun', 'sumaiya.khatun@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Gazipur, Bangladesh', 'Creative designer with a knack for minimalist interfaces.', 4.29, 'active', '2026-02-13 13:34:58'),
('Fatima Nesa', 'fatima.nesa@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Bogra, Bangladesh', 'Language enthusiast who speaks 3 languages fluently.', 4.13, 'active', '2026-03-16 13:34:58'),
('Ayesha Banu', 'ayesha.banu@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dhaka, Bangladesh', 'Music teacher with 10 years of experience in classical piano.', 4.95, 'active', '2026-02-20 13:34:58'),
('Khadija Mahmud', 'khadija.mahmud@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Chittagong, Bangladesh', 'Data scientist exploring the intersection of AI and education.', 4.78, 'active', '2025-08-23 13:34:58'),
('Tania Hasan', 'tania.hasan@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sylhet, Bangladesh', 'Fitness trainer specializing in home workouts and nutrition.', 3.53, 'active', '2026-03-18 13:34:58'),
('Farzana Uddin', 'farzana.uddin@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rajshahi, Bangladesh', 'Amateur photographer who loves capturing urban landscapes.', 4.64, 'active', '2026-02-12 13:34:58'),
('Jahanara Karim', 'jahanara.karim@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Khulna, Bangladesh', 'Marketing specialist with expertise in digital growth strategies.', 4.40, 'active', '2026-03-25 13:34:58'),
('Rumana Habib', 'rumana.habib@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Barisal, Bangladesh', 'College student eager to learn everything from cooking to coding.', 3.80, 'active', '2026-02-05 13:34:58'),
('Salma Kabir', 'salma.kabir@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rangpur, Bangladesh', 'Passionate about teaching and learning new things every day.', 4.62, 'active', '2025-11-18 13:34:58'),
('Panna Miah', 'panna.miah@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mymensingh, Bangladesh', 'Full-stack developer who loves sharing knowledge with the community.', 3.54, 'active', '2025-10-09 13:34:58'),
('Fahima Sarker', 'fahima.sarker@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Comilla, Bangladesh', 'Creative designer with a knack for minimalist interfaces.', 3.51, 'active', '2025-10-01 13:34:58'),
('Nahida Das', 'nahida.das@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Narayanganj, Bangladesh', 'Language enthusiast who speaks 3 languages fluently.', 3.98, 'active', '2026-03-08 13:34:58'),
('Ritu Roy', 'ritu.roy@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Gazipur, Bangladesh', 'Music teacher with 10 years of experience in classical piano.', 3.81, 'active', '2025-08-02 13:34:58'),
('Sharmin Barua', 'sharmin.barua@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Bogra, Bangladesh', 'Data scientist exploring the intersection of AI and education.', 4.12, 'active', '2026-01-10 13:34:58'),
('Nigar Chakma', 'nigar.chakma@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dhaka, Bangladesh', 'Fitness trainer specializing in home workouts and nutrition.', 3.70, 'active', '2026-04-30 13:34:58'),
('Shamima Tripura', 'shamima.tripura@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Chittagong, Bangladesh', 'Amateur photographer who loves capturing urban landscapes.', 3.94, 'active', '2026-02-16 13:34:58'),
('Fargana Marma', 'fargana.marma@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sylhet, Bangladesh', 'Marketing specialist with expertise in digital growth strategies.', 3.99, 'active', '2025-10-31 13:34:58'),
('Sanjida Debnath', 'sanjida.debnath@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rajshahi, Bangladesh', 'College student eager to learn everything from cooking to coding.', 3.56, 'active', '2025-07-16 13:34:58'),
('Hasan Saha', 'hasan.saha@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Khulna, Bangladesh', 'Passionate about teaching and learning new things every day.', 3.80, 'active', '2025-08-21 13:34:58'),
('Ali Ghosh', 'ali.ghosh@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Barisal, Bangladesh', 'Full-stack developer who loves sharing knowledge with the community.', 4.68, 'active', '2026-04-26 13:34:58'),
('Ahmed Basu', 'ahmed.basu@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rangpur, Bangladesh', 'Creative designer with a knack for minimalist interfaces.', 3.91, 'active', '2025-09-15 13:34:58'),
('Mia Mitra', 'mia.mitra@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mymensingh, Bangladesh', 'Language enthusiast who speaks 3 languages fluently.', 3.59, 'active', '2025-09-18 13:34:58'),
('Khan Datta', 'khan.datta@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Comilla, Bangladesh', 'Music teacher with 10 years of experience in classical piano.', 3.72, 'active', '2025-12-12 13:34:58'),
('Chowdhury Pal', 'chowdhury.pal@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Narayanganj, Bangladesh', 'Data scientist exploring the intersection of AI and education.', 4.91, 'active', '2025-08-26 13:34:58'),
('Hossain Shil', 'hossain.shil@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Gazipur, Bangladesh', 'Fitness trainer specializing in home workouts and nutrition.', 4.99, 'active', '2025-12-04 13:34:58'),
('Rahman Banik', 'rahman.banik@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Bogra, Bangladesh', 'Amateur photographer who loves capturing urban landscapes.', 3.58, 'active', '2025-08-14 13:34:58'),
('Siddique Bhowmik', 'siddique.bhowmik@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dhaka, Bangladesh', 'Marketing specialist with expertise in digital growth strategies.', 3.68, 'active', '2025-10-05 13:34:58'),
('Hoque Sen', 'hoque.sen@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Chittagong, Bangladesh', 'College student eager to learn everything from cooking to coding.', 3.53, 'active', '2026-02-14 13:34:58'),
('Newbie User', 'newbie@skillswap-bd.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dhaka, Bangladesh', 'Just joined and ready to learn.', NULL, 'active', '2026-06-10 10:00:00');

REPLACE INTO wallet (user_id, balance) VALUES
(1, 192.84),
(2, 101.19),
(3, 136.68),
(4, 84.21),
(5, 173.19),
(6, 102.50),
(7, 32.93),
(8, 86.17),
(9, 91.05),
(10, 131.41),
(11, 136.19),
(12, 96.09),
(13, 164.55),
(14, 25.96),
(15, 172.71),
(16, 56.83),
(17, 177.05),
(18, 73.25),
(19, 146.39),
(20, 104.01),
(21, 94.88),
(22, 179.40),
(23, 190.60),
(24, 114.92),
(25, 78.11),
(26, 105.04),
(27, 157.09),
(28, 153.57),
(29, 119.94),
(30, 100.77),
(31, 137.13),
(32, 150.68),
(33, 95.48),
(34, 33.74),
(35, 134.45),
(36, 191.03),
(37, 106.80),
(38, 185.67),
(39, 87.05),
(40, 56.31),
(41, 124.16),
(42, 154.13),
(43, 86.72),
(44, 165.67),
(45, 47.35),
(46, 57.22),
(47, 199.65),
(48, 131.78),
(49, 22.85),
(50, 105.57),
(51, 10.00);

REPLACE INTO reputation (user_id, current_score, completed_sessions, cancelled_sessions, mentor_level) VALUES
(1, 3.65, 1, 3, 'Novice'),
(2, 3.75, 9, 2, 'Professional'),
(3, 3.84, 2, 2, 'Novice'),
(4, 3.15, 22, 3, 'Master'),
(5, 3.13, 23, 3, 'Master'),
(6, 4.20, 21, 0, 'Master'),
(7, 4.04, 21, 2, 'Master'),
(8, 3.37, 3, 2, 'Novice'),
(9, 3.63, 8, 2, 'Professional'),
(10, 3.15, 19, 3, 'Expert'),
(11, 4.27, 13, 1, 'Expert'),
(12, 3.68, 6, 2, 'Professional'),
(13, 3.46, 0, 3, 'Novice'),
(14, 4.19, 6, 1, 'Professional'),
(15, 4.27, 8, 0, 'Professional'),
(16, 3.44, 15, 3, 'Expert'),
(17, 3.96, 2, 2, 'Novice'),
(18, 3.94, 4, 0, 'Novice'),
(19, 3.34, 14, 2, 'Expert'),
(20, 3.80, 13, 3, 'Expert'),
(21, 4.16, 15, 1, 'Expert'),
(22, 3.66, 18, 1, 'Expert'),
(23, 3.90, 6, 2, 'Professional'),
(24, 4.31, 24, 0, 'Master'),
(25, 4.07, 2, 2, 'Novice'),
(26, 3.83, 12, 3, 'Expert'),
(27, 4.12, 9, 1, 'Professional'),
(28, 3.10, 11, 2, 'Expert'),
(29, 3.90, 20, 2, 'Master'),
(30, 3.92, 24, 0, 'Master'),
(31, 3.16, 22, 2, 'Master'),
(32, 3.54, 18, 3, 'Expert'),
(33, 3.68, 13, 0, 'Expert'),
(34, 3.90, 10, 0, 'Expert'),
(35, 4.10, 0, 1, 'Novice'),
(36, 3.47, 16, 3, 'Expert'),
(37, 3.13, 14, 3, 'Expert'),
(38, 3.19, 8, 2, 'Professional'),
(39, 3.41, 24, 3, 'Master'),
(40, 3.33, 17, 1, 'Expert'),
(41, 3.92, 14, 2, 'Expert'),
(42, 3.59, 20, 2, 'Master'),
(43, 3.92, 24, 2, 'Master'),
(44, 4.34, 15, 0, 'Expert'),
(45, 4.08, 3, 2, 'Novice'),
(46, 3.64, 20, 2, 'Master'),
(47, 3.75, 20, 2, 'Master'),
(48, 3.08, 15, 3, 'Expert'),
(49, 4.13, 16, 0, 'Expert'),
(50, 3.46, 10, 1, 'Expert'),
(51, NULL, 0, 0, 'Novice');

INSERT INTO user_skills_offered (user_id, skill_id) VALUES
(1, 27),
(1, 2),
(1, 1),
(1, 11),
(2, 21),
(2, 16),
(2, 35),
(2, 25),
(2, 20),
(3, 16),
(3, 12),
(4, 21),
(4, 34),
(4, 16),
(5, 27),
(5, 13),
(6, 30),
(6, 16),
(6, 1),
(6, 14),
(7, 28),
(7, 3),
(8, 32),
(8, 31),
(8, 9),
(8, 13),
(8, 23),
(9, 5),
(9, 15),
(10, 35),
(10, 18),
(10, 1),
(10, 10),
(10, 13),
(11, 15),
(11, 35),
(11, 4),
(11, 22),
(12, 6),
(12, 35),
(12, 7),
(13, 21),
(13, 31),
(13, 27),
(13, 26),
(14, 5),
(14, 28),
(14, 24),
(15, 20),
(15, 18),
(15, 26),
(16, 6),
(16, 17),
(16, 8),
(16, 22),
(16, 33),
(17, 8),
(17, 20),
(17, 1),
(18, 15),
(18, 27),
(18, 23),
(19, 20),
(19, 12),
(19, 22),
(20, 34),
(20, 7),
(20, 9),
(20, 19),
(20, 29),
(21, 12),
(21, 34),
(21, 7),
(21, 13),
(22, 4),
(22, 14),
(22, 25),
(22, 29),
(22, 22),
(23, 11),
(23, 18),
(23, 25),
(23, 3),
(24, 25),
(24, 14),
(25, 13),
(25, 24),
(25, 26),
(26, 14),
(26, 5),
(26, 18),
(26, 27),
(26, 17),
(27, 31),
(27, 26),
(27, 10),
(27, 13),
(27, 29),
(28, 25),
(28, 14),
(28, 11),
(29, 6),
(29, 2),
(29, 4),
(29, 25),
(29, 29),
(30, 14),
(30, 10),
(31, 28),
(31, 20),
(31, 2),
(32, 28),
(32, 16),
(33, 1),
(33, 12),
(33, 23),
(33, 16),
(34, 10),
(34, 9),
(34, 13),
(34, 15),
(35, 30),
(35, 28),
(35, 35),
(35, 8),
(35, 24),
(36, 22),
(36, 35),
(37, 24),
(37, 27),
(37, 1),
(38, 34),
(38, 21),
(38, 2),
(38, 22),
(39, 13),
(39, 27),
(39, 16),
(39, 26),
(39, 34),
(40, 34),
(40, 24),
(40, 23),
(40, 4),
(41, 1),
(41, 14),
(41, 30),
(41, 3),
(42, 2),
(42, 35),
(42, 15),
(42, 21),
(43, 29),
(43, 24),
(44, 8),
(44, 20),
(44, 10),
(45, 20),
(45, 22),
(45, 32),
(45, 23),
(46, 19),
(46, 6),
(46, 18),
(46, 29),
(47, 18),
(47, 13),
(48, 6),
(48, 7),
(49, 7),
(49, 32),
(50, 21),
(50, 34),
(50, 1),
(50, 15);

INSERT INTO user_skills_requested (user_id, skill_id) VALUES
(1, 25),
(1, 32),
(2, 28),
(2, 13),
(3, 23),
(3, 11),
(3, 15),
(4, 35),
(4, 4),
(4, 28),
(5, 31),
(5, 23),
(5, 8),
(6, 7),
(6, 3),
(7, 14),
(7, 18),
(7, 29),
(8, 20),
(8, 12),
(9, 24),
(9, 28),
(9, 31),
(10, 25),
(10, 34),
(11, 7),
(11, 17),
(11, 1),
(11, 8),
(12, 25),
(12, 2),
(13, 8),
(13, 20),
(13, 6),
(13, 30),
(14, 15),
(14, 6),
(14, 19),
(15, 29),
(15, 8),
(15, 9),
(16, 10),
(16, 30),
(17, 30),
(17, 29),
(17, 13),
(17, 31),
(18, 17),
(18, 20),
(19, 32),
(19, 21),
(19, 15),
(19, 26),
(20, 15),
(20, 4),
(20, 27),
(20, 11),
(21, 28),
(21, 8),
(21, 5),
(22, 23),
(22, 13),
(22, 12),
(23, 12),
(23, 2),
(23, 13),
(24, 2),
(24, 8),
(24, 35),
(24, 24),
(25, 3),
(25, 10),
(25, 25),
(26, 2),
(26, 29),
(26, 9),
(27, 28),
(27, 32),
(28, 4),
(28, 24),
(28, 23),
(29, 13),
(29, 22),
(30, 8),
(30, 16),
(30, 21),
(30, 5),
(31, 5),
(31, 31),
(32, 18),
(32, 6),
(32, 8),
(32, 14),
(33, 32),
(33, 25),
(33, 33),
(33, 11),
(34, 18),
(34, 34),
(35, 4),
(35, 10),
(35, 11),
(35, 14),
(36, 34),
(36, 8),
(36, 7),
(37, 2),
(37, 28),
(37, 17),
(38, 15),
(38, 13),
(38, 19),
(39, 4),
(39, 7),
(39, 30),
(39, 20),
(40, 15),
(40, 21),
(40, 30),
(41, 5),
(41, 10),
(41, 19),
(42, 22),
(42, 13),
(42, 20),
(42, 10),
(43, 16),
(43, 9),
(43, 13),
(44, 7),
(44, 14),
(44, 4),
(44, 23),
(45, 26),
(45, 10),
(45, 35),
(45, 18),
(46, 27),
(46, 5),
(46, 7),
(47, 20),
(47, 16),
(48, 28),
(48, 8),
(48, 2),
(48, 16),
(49, 21),
(49, 28),
(49, 11),
(49, 33),
(50, 26),
(50, 18),
(50, 31);

INSERT INTO user_availability (user_id, day_of_week, start_time, end_time) VALUES
(1, 'Thursday', '08:00:00', '11:00:00'),
(1, 'Wednesday', '08:00:00', '10:00:00'),
(2, 'Sunday', '17:00:00', '19:00:00'),
(2, 'Saturday', '10:00:00', '13:00:00'),
(2, 'Wednesday', '16:00:00', '20:00:00'),
(3, 'Tuesday', '16:00:00', '18:00:00'),
(3, 'Thursday', '11:00:00', '15:00:00'),
(4, 'Tuesday', '13:00:00', '17:00:00'),
(4, 'Monday', '12:00:00', '15:00:00'),
(4, 'Wednesday', '08:00:00', '11:00:00'),
(5, 'Tuesday', '16:00:00', '18:00:00'),
(5, 'Wednesday', '09:00:00', '13:00:00'),
(5, 'Sunday', '08:00:00', '12:00:00'),
(6, 'Wednesday', '09:00:00', '13:00:00'),
(6, 'Tuesday', '16:00:00', '19:00:00'),
(6, 'Friday', '09:00:00', '11:00:00'),
(7, 'Tuesday', '11:00:00', '15:00:00'),
(7, 'Sunday', '10:00:00', '12:00:00'),
(8, 'Monday', '17:00:00', '20:00:00'),
(8, 'Friday', '10:00:00', '13:00:00'),
(8, 'Sunday', '15:00:00', '18:00:00'),
(9, 'Thursday', '16:00:00', '18:00:00'),
(9, 'Friday', '16:00:00', '18:00:00'),
(10, 'Monday', '13:00:00', '15:00:00'),
(10, 'Friday', '08:00:00', '11:00:00'),
(10, 'Saturday', '17:00:00', '19:00:00'),
(11, 'Tuesday', '15:00:00', '19:00:00'),
(12, 'Thursday', '14:00:00', '16:00:00'),
(12, 'Wednesday', '09:00:00', '12:00:00'),
(12, 'Friday', '18:00:00', '21:00:00'),
(13, 'Sunday', '17:00:00', '20:00:00'),
(13, 'Tuesday', '10:00:00', '13:00:00'),
(13, 'Friday', '18:00:00', '20:00:00'),
(14, 'Wednesday', '16:00:00', '19:00:00'),
(14, 'Thursday', '10:00:00', '12:00:00'),
(15, 'Monday', '17:00:00', '19:00:00'),
(15, 'Thursday', '10:00:00', '13:00:00'),
(16, 'Thursday', '14:00:00', '16:00:00'),
(16, 'Saturday', '14:00:00', '16:00:00'),
(17, 'Monday', '10:00:00', '12:00:00'),
(17, 'Tuesday', '15:00:00', '18:00:00'),
(18, 'Tuesday', '16:00:00', '19:00:00'),
(18, 'Thursday', '08:00:00', '10:00:00'),
(18, 'Wednesday', '16:00:00', '18:00:00'),
(19, 'Tuesday', '09:00:00', '12:00:00'),
(19, 'Monday', '10:00:00', '12:00:00'),
(19, 'Wednesday', '12:00:00', '16:00:00'),
(20, 'Saturday', '12:00:00', '16:00:00'),
(20, 'Thursday', '11:00:00', '15:00:00'),
(21, 'Monday', '09:00:00', '13:00:00'),
(21, 'Tuesday', '15:00:00', '19:00:00'),
(21, 'Saturday', '08:00:00', '10:00:00'),
(22, 'Sunday', '11:00:00', '14:00:00'),
(22, 'Friday', '08:00:00', '11:00:00'),
(22, 'Tuesday', '17:00:00', '21:00:00'),
(23, 'Sunday', '09:00:00', '12:00:00'),
(23, 'Wednesday', '11:00:00', '15:00:00'),
(24, 'Saturday', '09:00:00', '11:00:00'),
(24, 'Thursday', '15:00:00', '19:00:00'),
(25, 'Monday', '16:00:00', '19:00:00'),
(25, 'Thursday', '12:00:00', '15:00:00'),
(26, 'Thursday', '13:00:00', '17:00:00'),
(27, 'Monday', '16:00:00', '20:00:00'),
(27, 'Friday', '09:00:00', '12:00:00'),
(28, 'Tuesday', '11:00:00', '13:00:00'),
(28, 'Monday', '17:00:00', '20:00:00'),
(29, 'Saturday', '18:00:00', '22:00:00'),
(29, 'Wednesday', '10:00:00', '13:00:00'),
(30, 'Sunday', '18:00:00', '20:00:00'),
(30, 'Tuesday', '15:00:00', '19:00:00'),
(30, 'Saturday', '16:00:00', '19:00:00'),
(31, 'Sunday', '09:00:00', '11:00:00'),
(31, 'Monday', '18:00:00', '21:00:00'),
(31, 'Friday', '15:00:00', '17:00:00'),
(31, 'Tuesday', '12:00:00', '16:00:00'),
(32, 'Thursday', '15:00:00', '18:00:00'),
(32, 'Wednesday', '17:00:00', '19:00:00'),
(32, 'Friday', '16:00:00', '19:00:00'),
(32, 'Tuesday', '18:00:00', '22:00:00'),
(33, 'Sunday', '08:00:00', '11:00:00'),
(33, 'Thursday', '11:00:00', '13:00:00'),
(34, 'Tuesday', '13:00:00', '16:00:00'),
(34, 'Sunday', '15:00:00', '17:00:00'),
(34, 'Thursday', '08:00:00', '12:00:00'),
(35, 'Tuesday', '10:00:00', '14:00:00'),
(35, 'Sunday', '14:00:00', '16:00:00'),
(36, 'Sunday', '08:00:00', '12:00:00'),
(36, 'Friday', '14:00:00', '16:00:00'),
(36, 'Tuesday', '17:00:00', '20:00:00'),
(36, 'Saturday', '14:00:00', '17:00:00'),
(37, 'Wednesday', '18:00:00', '22:00:00'),
(37, 'Thursday', '09:00:00', '13:00:00'),
(38, 'Monday', '15:00:00', '19:00:00'),
(38, 'Saturday', '13:00:00', '17:00:00'),
(38, 'Wednesday', '09:00:00', '13:00:00'),
(39, 'Tuesday', '15:00:00', '18:00:00'),
(39, 'Wednesday', '15:00:00', '17:00:00'),
(39, 'Saturday', '16:00:00', '20:00:00'),
(40, 'Sunday', '11:00:00', '15:00:00'),
(40, 'Thursday', '11:00:00', '13:00:00'),
(40, 'Monday', '10:00:00', '14:00:00'),
(41, 'Wednesday', '11:00:00', '14:00:00'),
(41, 'Saturday', '15:00:00', '17:00:00'),
(41, 'Friday', '10:00:00', '12:00:00'),
(42, 'Sunday', '16:00:00', '20:00:00'),
(42, 'Saturday', '14:00:00', '16:00:00'),
(42, 'Monday', '14:00:00', '17:00:00'),
(42, 'Thursday', '11:00:00', '15:00:00'),
(43, 'Thursday', '12:00:00', '14:00:00'),
(43, 'Tuesday', '09:00:00', '13:00:00'),
(43, 'Wednesday', '09:00:00', '13:00:00'),
(44, 'Sunday', '16:00:00', '19:00:00'),
(44, 'Tuesday', '18:00:00', '22:00:00'),
(45, 'Sunday', '08:00:00', '12:00:00'),
(45, 'Friday', '13:00:00', '16:00:00'),
(46, 'Tuesday', '09:00:00', '11:00:00'),
(46, 'Wednesday', '15:00:00', '18:00:00'),
(47, 'Tuesday', '14:00:00', '17:00:00'),
(47, 'Friday', '12:00:00', '16:00:00'),
(47, 'Wednesday', '15:00:00', '19:00:00'),
(48, 'Thursday', '16:00:00', '19:00:00'),
(48, 'Tuesday', '16:00:00', '19:00:00'),
(49, 'Saturday', '18:00:00', '22:00:00'),
(49, 'Monday', '11:00:00', '14:00:00'),
(49, 'Friday', '11:00:00', '15:00:00'),
(50, 'Sunday', '11:00:00', '13:00:00');

INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status, scheduled_time, completion_time, session_duration, time_credit_transfer, rating, feedback_given, bonus_multiplier, completion_otp) VALUES
(32, 1, 27, 'completed', '2026-01-12 13:34:58', '2026-01-12 14:04:58', 30, 5.00, 5, TRUE, 1.00, '5165'),
(18, 27, 34, 'completed', '2026-04-19 13:34:58', '2026-04-19 15:04:58', 90, 15.00, 5, TRUE, 1.00, '8481'),
(10, 25, 10, 'completed', '2026-02-09 13:34:58', '2026-02-09 15:04:58', 90, 15.00, 5, TRUE, 1.00, '7848'),
(23, 48, 18, 'completed', '2026-06-01 13:34:58', '2026-06-01 15:34:58', 120, 20.00, 3, TRUE, 1.00, '7904'),
(45, 18, 22, 'completed', '2026-01-22 13:34:58', '2026-01-22 14:34:58', 60, 10.00, 3, TRUE, 1.00, '3894'),
(1, 33, 12, 'completed', '2026-01-26 13:34:58', '2026-01-26 15:04:58', 90, 15.00, 3, TRUE, 1.00, '9335'),
(26, 16, 19, 'completed', '2026-02-22 13:34:58', '2026-02-22 14:34:58', 60, 10.00, 4, TRUE, 1.00, '9861'),
(11, 27, 33, 'completed', '2026-01-04 13:34:58', '2026-01-04 14:34:58', 60, 10.00, 5, TRUE, 1.00, '2615'),
(50, 2, 19, 'completed', '2026-03-03 13:34:58', '2026-03-03 14:04:58', 30, 5.00, 5, TRUE, 1.00, '0734'),
(46, 26, 4, 'completed', '2026-05-02 13:34:58', '2026-05-02 15:34:58', 120, 20.00, 5, TRUE, 1.00, '5481'),
(2, 34, 33, 'completed', '2026-03-04 13:34:58', '2026-03-04 15:34:58', 120, 20.00, 5, TRUE, 1.00, '0675'),
(50, 2, 7, 'completed', '2026-05-28 13:34:58', '2026-05-28 14:04:58', 30, 5.00, 3, TRUE, 1.00, '3045'),
(13, 24, 13, 'completed', '2025-12-30 13:34:58', '2025-12-30 14:34:58', 60, 10.00, 5, TRUE, 1.00, '4998'),
(39, 31, 20, 'completed', '2026-05-28 13:34:58', '2026-05-28 14:34:58', 60, 10.00, 5, TRUE, 1.00, '0422'),
(6, 7, 11, 'completed', '2025-12-21 13:34:58', '2025-12-21 15:34:58', 120, 20.00, 5, TRUE, 1.00, '4050'),
(30, 1, 5, 'completed', '2026-04-18 13:34:58', '2026-04-18 14:34:58', 60, 10.00, 5, TRUE, 1.00, '2867'),
(3, 32, 34, 'completed', '2026-05-14 13:34:58', '2026-05-14 15:04:58', 90, 15.00, 5, TRUE, 1.00, '5597'),
(18, 49, 29, 'completed', '2026-01-30 13:34:58', '2026-01-30 14:34:58', 60, 10.00, 5, TRUE, 1.00, '2217'),
(12, 36, 11, 'completed', '2026-02-08 13:34:58', '2026-02-08 14:34:58', 60, 10.00, 3, TRUE, 1.00, '6237'),
(30, 49, 12, 'completed', '2026-05-14 13:34:58', '2026-05-14 14:34:58', 60, 10.00, 4, TRUE, 1.00, '8633'),
(27, 3, 22, 'completed', '2026-03-12 13:34:58', '2026-03-12 14:34:58', 60, 10.00, 4, TRUE, 1.00, '5759'),
(1, 11, 28, 'completed', '2026-06-08 13:34:58', '2026-06-08 15:34:58', 120, 20.00, 5, TRUE, 1.00, '8484'),
(32, 9, 1, 'completed', '2026-03-23 13:34:58', '2026-03-23 14:34:58', 60, 10.00, 4, TRUE, 1.00, '0150'),
(4, 46, 19, 'completed', '2026-02-21 13:34:58', '2026-02-21 14:34:58', 60, 10.00, 4, TRUE, 1.00, '3077'),
(13, 33, 5, 'completed', '2026-04-27 13:34:58', '2026-04-27 14:34:58', 60, 10.00, 4, TRUE, 1.00, '5144'),
(47, 40, 17, 'completed', '2026-02-15 13:34:58', '2026-02-15 15:04:58', 90, 15.00, 3, TRUE, 1.00, '5850'),
(38, 27, 15, 'completed', '2026-02-17 13:34:58', '2026-02-17 14:34:58', 60, 10.00, 4, TRUE, 1.00, '4010'),
(19, 42, 18, 'completed', '2026-05-08 13:34:58', '2026-05-08 14:34:58', 60, 10.00, 4, TRUE, 1.00, '4518'),
(13, 18, 2, 'completed', '2026-03-19 13:34:58', '2026-03-19 14:34:58', 60, 10.00, 3, TRUE, 1.00, '3122'),
(5, 20, 2, 'completed', '2026-01-24 13:34:58', '2026-01-24 14:04:58', 30, 5.00, 4, TRUE, 1.00, '3397'),
(34, 24, 34, 'completed', '2026-05-09 13:34:58', '2026-05-09 14:34:58', 60, 10.00, 3, TRUE, 1.00, '2684'),
(36, 29, 23, 'completed', '2026-04-13 13:34:58', '2026-04-13 15:34:58', 120, 20.00, 5, TRUE, 1.00, '2566'),
(44, 34, 22, 'completed', '2026-05-21 13:34:58', '2026-05-21 14:34:58', 60, 10.00, 3, TRUE, 1.00, '6839'),
(34, 2, 16, 'completed', '2026-04-11 13:34:58', '2026-04-11 14:34:58', 60, 10.00, 5, TRUE, 1.00, '9757'),
(36, 31, 27, 'completed', '2026-01-04 13:34:58', '2026-01-04 14:34:58', 60, 10.00, 5, TRUE, 1.00, '3176'),
(17, 50, 20, 'completed', '2026-02-16 13:34:58', '2026-02-16 14:34:58', 60, 10.00, 5, TRUE, 1.00, '6470'),
(41, 43, 10, 'completed', '2026-04-16 13:34:58', '2026-04-16 14:34:58', 60, 10.00, 5, TRUE, 1.00, '8748'),
(34, 20, 6, 'completed', '2026-01-16 13:34:58', '2026-01-16 15:34:58', 120, 20.00, 3, TRUE, 1.00, '5049'),
(27, 10, 35, 'completed', '2026-03-07 13:34:58', '2026-03-07 15:34:58', 120, 20.00, 4, TRUE, 1.00, '0819'),
(48, 40, 20, 'completed', '2026-04-08 13:34:58', '2026-04-08 14:04:58', 30, 5.00, 4, TRUE, 1.00, '2228'),
(42, 2, 3, 'completed', '2026-04-04 13:34:58', '2026-04-04 15:34:58', 120, 20.00, 5, TRUE, 1.00, '8182'),
(36, 13, 23, 'completed', '2026-01-14 13:34:58', '2026-01-14 14:34:58', 60, 10.00, 4, TRUE, 1.00, '7023'),
(32, 24, 16, 'completed', '2026-03-30 13:34:58', '2026-03-30 14:34:58', 60, 10.00, 4, TRUE, 1.00, '2497'),
(30, 21, 20, 'completed', '2026-01-06 13:34:58', '2026-01-06 14:04:58', 30, 5.00, 4, TRUE, 1.00, '4902'),
(20, 13, 32, 'completed', '2026-01-09 13:34:58', '2026-01-09 14:34:58', 60, 10.00, 4, TRUE, 1.00, '4057'),
(39, 4, 33, 'completed', '2026-01-06 13:34:58', '2026-01-06 14:34:58', 60, 10.00, 4, TRUE, 1.00, '4089'),
(16, 18, 24, 'completed', '2026-03-28 13:34:58', '2026-03-28 15:34:58', 120, 20.00, 5, TRUE, 1.00, '9488'),
(1, 24, 24, 'completed', '2026-02-14 13:34:58', '2026-02-14 14:04:58', 30, 5.00, 4, TRUE, 1.00, '9227'),
(27, 11, 7, 'completed', '2025-12-23 13:34:58', '2025-12-23 14:34:58', 60, 10.00, 4, TRUE, 1.00, '1758'),
(48, 24, 19, 'completed', '2026-01-16 13:34:58', '2026-01-16 15:04:58', 90, 15.00, 4, TRUE, 1.00, '3389'),
(18, 42, 21, 'completed', '2026-01-07 13:34:58', '2026-01-07 14:34:58', 60, 10.00, 4, TRUE, 1.00, '2748'),
(34, 45, 35, 'completed', '2026-04-13 13:34:58', '2026-04-13 14:34:58', 60, 10.00, 3, TRUE, 1.00, '3840'),
(15, 24, 19, 'completed', '2026-02-07 13:34:58', '2026-02-07 14:34:58', 60, 10.00, 5, TRUE, 1.00, '4821'),
(37, 50, 11, 'completed', '2026-04-12 13:34:58', '2026-04-12 15:34:58', 120, 20.00, 4, TRUE, 1.00, '8107'),
(26, 24, 22, 'completed', '2026-02-26 13:34:58', '2026-02-26 15:04:58', 90, 15.00, 4, TRUE, 1.00, '0342'),
(33, 15, 20, 'completed', '2025-12-25 13:34:58', '2025-12-25 14:34:58', 60, 10.00, 4, TRUE, 1.00, '7623'),
(5, 4, 17, 'completed', '2025-12-13 13:34:58', '2025-12-13 14:04:58', 30, 5.00, 5, TRUE, 1.00, '0853'),
(46, 23, 12, 'completed', '2026-01-29 13:34:58', '2026-01-29 15:04:58', 90, 15.00, 3, TRUE, 1.00, '5818'),
(49, 24, 3, 'completed', '2026-01-08 13:34:58', '2026-01-08 14:34:58', 60, 10.00, 5, TRUE, 1.00, '4181'),
(7, 46, 29, 'completed', '2026-06-06 13:34:58', '2026-06-06 15:34:58', 120, 20.00, 3, TRUE, 1.00, '2238'),
(49, 26, 4, 'completed', '2026-01-06 13:34:58', '2026-01-06 15:34:58', 120, 20.00, 5, TRUE, 1.00, '4979'),
(10, 13, 25, 'completed', '2026-01-30 13:34:58', '2026-01-30 15:04:58', 90, 15.00, 5, TRUE, 1.00, '8849'),
(33, 44, 10, 'completed', '2026-04-24 13:34:58', '2026-04-24 14:34:58', 60, 10.00, 5, TRUE, 1.00, '0163'),
(17, 30, 27, 'completed', '2026-02-11 13:34:58', '2026-02-11 14:34:58', 60, 10.00, 4, TRUE, 1.00, '6821'),
(12, 13, 32, 'completed', '2026-01-26 13:34:58', '2026-01-26 15:34:58', 120, 20.00, 4, TRUE, 1.00, '7884'),
(36, 38, 11, 'completed', '2026-03-13 13:34:58', '2026-03-13 15:04:58', 90, 15.00, 4, TRUE, 1.00, '5879'),
(36, 13, 24, 'completed', '2026-04-11 13:34:58', '2026-04-11 14:34:58', 60, 10.00, 5, TRUE, 1.00, '3951'),
(9, 49, 35, 'completed', '2026-03-17 13:34:58', '2026-03-17 15:04:58', 90, 15.00, 5, TRUE, 1.00, '6493'),
(9, 18, 10, 'completed', '2025-12-15 13:34:58', '2025-12-15 14:34:58', 60, 10.00, 4, TRUE, 1.00, '0235'),
(24, 14, 15, 'completed', '2026-03-20 13:34:58', '2026-03-20 14:34:58', 60, 10.00, 3, TRUE, 1.00, '9694'),
(31, 26, 24, 'completed', '2026-05-28 13:34:58', '2026-05-28 14:34:58', 60, 10.00, 5, TRUE, 1.00, '8810'),
(7, 23, 20, 'completed', '2026-01-12 13:34:58', '2026-01-12 14:34:58', 60, 10.00, 4, TRUE, 1.00, '2616'),
(13, 49, 23, 'completed', '2026-05-22 13:34:58', '2026-05-22 14:34:58', 60, 10.00, 3, TRUE, 1.00, '3338'),
(36, 23, 20, 'completed', '2025-12-28 13:34:58', '2025-12-28 14:34:58', 60, 10.00, 5, TRUE, 1.00, '5662'),
(27, 17, 29, 'completed', '2026-01-08 13:34:58', '2026-01-08 14:34:58', 60, 10.00, 4, TRUE, 1.00, '6252'),
(18, 47, 32, 'completed', '2026-03-18 13:34:58', '2026-03-18 15:04:58', 90, 15.00, 3, TRUE, 1.00, '2062'),
(48, 24, 2, 'completed', '2026-01-25 13:34:58', '2026-01-25 15:04:58', 90, 15.00, 3, TRUE, 1.00, '1736'),
(25, 23, 2, 'completed', '2026-01-16 13:34:58', '2026-01-16 15:04:58', 90, 15.00, 5, TRUE, 1.00, '3294'),
(14, 27, 23, 'completed', '2026-05-22 13:34:58', '2026-05-22 14:34:58', 60, 10.00, 5, TRUE, 1.00, '0230'),
(6, 9, 23, 'completed', '2026-06-05 13:34:58', '2026-06-05 15:04:58', 90, 15.00, 5, TRUE, 1.00, '2741'),
(15, 13, 30, 'completed', '2025-12-30 13:34:58', '2025-12-30 14:34:58', 60, 10.00, 5, TRUE, 1.00, '9989'),
(8, 6, 3, 'completed', '2026-02-04 13:34:58', '2026-02-04 14:34:58', 60, 10.00, 5, TRUE, 1.00, '7743'),
(50, 26, 8, 'completed', '2026-01-14 13:34:58', '2026-01-14 14:34:58', 60, 10.00, 5, TRUE, 1.00, '6587'),
(50, 38, 2, 'completed', '2026-02-21 13:34:58', '2026-02-21 14:34:58', 60, 10.00, 5, TRUE, 1.00, '8519'),
(12, 17, 11, 'completed', '2025-12-16 13:34:58', '2025-12-16 14:34:58', 60, 10.00, 5, TRUE, 1.00, '3625'),
(30, 4, 15, 'completed', '2026-05-05 13:34:58', '2026-05-05 14:34:58', 60, 10.00, 3, TRUE, 1.00, '9406'),
(11, 25, 19, 'completed', '2026-02-14 13:34:58', '2026-02-14 15:04:58', 90, 15.00, 5, TRUE, 1.00, '2583'),
(4, 8, 22, 'completed', '2025-12-27 13:34:58', '2025-12-27 15:04:58', 90, 15.00, 5, TRUE, 1.00, '7662'),
(8, 45, 17, 'completed', '2026-04-23 13:34:58', '2026-04-23 15:34:58', 120, 20.00, 4, TRUE, 1.00, '4530'),
(37, 46, 2, 'completed', '2026-02-13 13:34:58', '2026-02-13 14:34:58', 60, 10.00, 5, TRUE, 1.00, '3326'),
(14, 24, 12, 'completed', '2026-03-25 13:34:58', '2026-03-25 14:34:58', 60, 10.00, 4, TRUE, 1.00, '2973'),
(49, 28, 22, 'completed', '2026-05-08 13:34:58', '2026-05-08 14:34:58', 60, 10.00, 3, TRUE, 1.00, '8265'),
(1, 6, 19, 'completed', '2026-03-13 13:34:58', '2026-03-13 14:34:58', 60, 10.00, 5, TRUE, 1.00, '5024'),
(50, 2, 1, 'completed', '2025-12-19 13:34:58', '2025-12-19 14:34:58', 60, 10.00, 3, TRUE, 1.00, '8538'),
(44, 17, 25, 'completed', '2026-06-05 13:34:58', '2026-06-05 15:34:58', 120, 20.00, 3, TRUE, 1.00, '0712'),
(23, 31, 27, 'completed', '2026-02-26 13:34:58', '2026-02-26 15:34:58', 120, 20.00, 3, TRUE, 1.00, '5120'),
(38, 40, 8, 'completed', '2026-03-10 13:34:58', '2026-03-10 14:04:58', 30, 5.00, 3, TRUE, 1.00, '0360'),
(17, 9, 3, 'completed', '2026-02-25 13:34:58', '2026-02-25 15:04:58', 90, 15.00, 5, TRUE, 1.00, '4326'),
(5, 34, 20, 'completed', '2026-03-17 13:34:58', '2026-03-17 15:34:58', 120, 20.00, 3, TRUE, 1.00, '4763'),
(37, 16, 12, 'completed', '2026-02-15 13:34:58', '2026-02-15 14:34:58', 60, 10.00, 3, TRUE, 1.00, '9833'),
(47, 45, 18, 'completed', '2026-02-05 13:34:58', '2026-02-05 15:04:58', 90, 15.00, 5, TRUE, 1.00, '7778'),
(4, 41, 18, 'completed', '2026-04-15 13:34:58', '2026-04-15 14:04:58', 30, 5.00, 5, TRUE, 1.00, '1204'),
(48, 5, 16, 'completed', '2026-01-26 13:34:58', '2026-01-26 14:34:58', 60, 10.00, 3, TRUE, 1.00, '6533'),
(45, 17, 24, 'completed', '2025-12-11 13:34:58', '2025-12-11 15:04:58', 90, 15.00, 4, TRUE, 1.00, '3151'),
(48, 10, 34, 'completed', '2026-02-22 13:34:58', '2026-02-22 15:34:58', 120, 20.00, 4, TRUE, 1.00, '3336'),
(17, 25, 7, 'completed', '2026-02-12 13:34:58', '2026-02-12 14:34:58', 60, 10.00, 5, TRUE, 1.00, '8580'),
(39, 5, 11, 'completed', '2026-04-13 13:34:58', '2026-04-13 14:34:58', 60, 10.00, 4, TRUE, 1.00, '8329'),
(14, 38, 15, 'completed', '2026-01-08 13:34:58', '2026-01-08 14:34:58', 60, 10.00, 5, TRUE, 1.00, '9802'),
(14, 1, 29, 'completed', '2026-04-29 13:34:58', '2026-04-29 14:34:58', 60, 10.00, 4, TRUE, 1.00, '8670'),
(47, 38, 13, 'completed', '2026-04-10 13:34:58', '2026-04-10 15:34:58', 120, 20.00, 4, TRUE, 1.00, '8374'),
(27, 39, 11, 'completed', '2026-02-14 13:34:58', '2026-02-14 15:34:58', 120, 20.00, 3, TRUE, 1.00, '5516'),
(41, 48, 22, 'completed', '2026-06-03 13:34:58', '2026-06-03 15:34:58', 120, 20.00, 5, TRUE, 1.00, '1075'),
(18, 46, 32, 'completed', '2026-03-04 13:34:58', '2026-03-04 15:04:58', 90, 15.00, 3, TRUE, 1.00, '1224'),
(30, 17, 23, 'completed', '2026-01-07 13:34:58', '2026-01-07 14:34:58', 60, 10.00, 4, TRUE, 1.00, '4231'),
(2, 39, 5, 'completed', '2026-04-14 13:34:58', '2026-04-14 14:04:58', 30, 5.00, 5, TRUE, 1.00, '4705'),
(50, 46, 19, 'completed', '2026-01-18 13:34:58', '2026-01-18 14:04:58', 30, 5.00, 3, TRUE, 1.00, '7767'),
(21, 7, 12, 'completed', '2026-01-25 13:34:58', '2026-01-25 14:04:58', 30, 5.00, 3, TRUE, 1.00, '1131'),
(49, 15, 28, 'completed', '2026-04-20 13:34:58', '2026-04-20 14:04:58', 30, 5.00, 5, TRUE, 1.00, '5887'),
(25, 39, 2, 'completed', '2026-01-03 13:34:58', '2026-01-03 14:04:58', 30, 5.00, 3, TRUE, 1.00, '8680'),
(4, 43, 15, 'completed', '2026-02-16 13:34:58', '2026-02-16 14:04:58', 30, 5.00, 4, TRUE, 1.00, '3745'),
(34, 15, 25, 'completed', '2026-04-14 13:34:58', '2026-04-14 14:34:58', 60, 10.00, 3, TRUE, 1.00, '6079'),
(31, 24, 18, 'completed', '2026-06-01 13:34:58', '2026-06-01 15:34:58', 120, 20.00, 4, TRUE, 1.00, '2600'),
(47, 48, 28, 'completed', '2026-04-20 13:34:58', '2026-04-20 14:34:58', 60, 10.00, 5, TRUE, 1.00, '5873'),
(41, 50, 10, 'completed', '2026-03-07 13:34:58', '2026-03-07 14:34:58', 60, 10.00, 3, TRUE, 1.00, '8338'),
(25, 31, 15, 'completed', '2026-03-29 13:34:58', '2026-03-29 15:34:58', 120, 20.00, 5, TRUE, 1.00, '3033'),
(31, 20, 26, 'completed', '2025-12-20 13:34:58', '2025-12-20 14:34:58', 60, 10.00, 5, TRUE, 1.00, '5483'),
(45, 34, 18, 'completed', '2026-02-24 13:34:58', '2026-02-24 15:34:58', 120, 20.00, 5, TRUE, 1.00, '4336'),
(50, 23, 29, 'completed', '2026-06-08 13:34:58', '2026-06-08 14:34:58', 60, 10.00, 3, TRUE, 1.00, '0782'),
(11, 32, 32, 'completed', '2026-04-11 13:34:58', '2026-04-11 14:34:58', 60, 10.00, 4, TRUE, 1.00, '1590'),
(36, 18, 11, 'completed', '2026-03-12 13:34:58', '2026-03-12 14:34:58', 60, 10.00, 5, TRUE, 1.00, '6659'),
(11, 12, 32, 'completed', '2026-03-29 13:34:58', '2026-03-29 15:34:58', 120, 20.00, 4, TRUE, 1.00, '2534'),
(45, 2, 6, 'completed', '2026-05-03 13:34:58', '2026-05-03 15:04:58', 90, 15.00, 3, TRUE, 1.00, '4767'),
(38, 47, 15, 'completed', '2026-02-14 13:34:58', '2026-02-14 15:04:58', 90, 15.00, 4, TRUE, 1.00, '5663'),
(6, 37, 7, 'completed', '2025-12-18 13:34:58', '2025-12-18 14:34:58', 60, 10.00, 4, TRUE, 1.00, '0757'),
(48, 2, 8, 'completed', '2026-03-28 13:34:58', '2026-03-28 14:34:58', 60, 10.00, 4, TRUE, 1.00, '0562'),
(19, 49, 22, 'completed', '2026-02-05 13:34:58', '2026-02-05 14:34:58', 60, 10.00, 4, TRUE, 1.00, '7089'),
(24, 11, 17, 'completed', '2026-01-12 13:34:58', '2026-01-12 14:34:58', 60, 10.00, 4, TRUE, 1.00, '7222'),
(36, 37, 12, 'completed', '2026-01-05 13:34:58', '2026-01-05 15:34:58', 120, 20.00, 5, TRUE, 1.00, '5039'),
(39, 24, 1, 'completed', '2026-06-02 13:34:58', '2026-06-02 14:04:58', 30, 5.00, 4, TRUE, 1.00, '4960'),
(1, 28, 14, 'completed', '2026-05-10 13:34:58', '2026-05-10 14:34:58', 60, 10.00, 5, TRUE, 1.00, '1364'),
(48, 45, 21, 'completed', '2026-04-14 13:34:58', '2026-04-14 14:34:58', 60, 10.00, 5, TRUE, 1.00, '5329'),
(32, 5, 31, 'completed', '2026-05-13 13:34:58', '2026-05-13 15:34:58', 120, 20.00, 5, TRUE, 1.00, '5371'),
(50, 9, 12, 'completed', '2026-04-06 13:34:58', '2026-04-06 14:34:58', 60, 10.00, 4, TRUE, 1.00, '5038'),
(10, 19, 7, 'completed', '2026-01-04 13:34:58', '2026-01-04 15:04:58', 90, 15.00, 3, TRUE, 1.00, '5457'),
(49, 12, 2, 'completed', '2026-06-05 13:34:58', '2026-06-05 14:34:58', 60, 10.00, 3, TRUE, 1.00, '8292'),
(30, 23, 15, 'completed', '2026-01-04 13:34:58', '2026-01-04 15:04:58', 90, 15.00, 5, TRUE, 1.00, '6368'),
(44, 17, 11, 'completed', '2025-12-16 13:34:58', '2025-12-16 14:34:58', 60, 10.00, 4, TRUE, 1.00, '3149'),
(39, 47, 23, 'completed', '2026-03-11 13:34:58', '2026-03-11 15:34:58', 120, 20.00, 5, TRUE, 1.00, '8464'),
(21, 2, 7, 'completed', '2026-04-15 13:34:58', '2026-04-15 14:34:58', 60, 10.00, 5, TRUE, 1.00, '6033'),
(21, 2, 30, 'completed', '2026-01-30 13:34:58', '2026-01-30 14:34:58', 60, 10.00, 3, TRUE, 1.00, '1188');

INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, note, timestamp) VALUES
(1, 32, 1, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-01-12 14:04:58'),
(2, 18, 27, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-04-19 15:04:58'),
(3, 10, 25, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-02-09 15:04:58'),
(4, 23, 48, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-06-01 15:34:58'),
(5, 45, 18, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-22 14:34:58'),
(6, 1, 33, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-01-26 15:04:58'),
(7, 26, 16, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-22 14:34:58'),
(8, 11, 27, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-04 14:34:58'),
(9, 50, 2, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-03-03 14:04:58'),
(10, 46, 26, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-05-02 15:34:58'),
(11, 2, 34, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-03-04 15:34:58'),
(12, 50, 2, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-05-28 14:04:58'),
(13, 13, 24, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-30 14:34:58'),
(14, 39, 31, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-28 14:34:58'),
(15, 6, 7, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2025-12-21 15:34:58'),
(16, 30, 1, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-18 14:34:58'),
(17, 3, 32, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-05-14 15:04:58'),
(18, 18, 49, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-30 14:34:58'),
(19, 12, 36, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-08 14:34:58'),
(20, 30, 49, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-14 14:34:58'),
(21, 27, 3, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-12 14:34:58'),
(22, 1, 11, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-06-08 15:34:58'),
(23, 32, 9, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-23 14:34:58'),
(24, 4, 46, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-21 14:34:58'),
(25, 13, 33, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-27 14:34:58'),
(26, 47, 40, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-02-15 15:04:58'),
(27, 38, 27, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-17 14:34:58'),
(28, 19, 42, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-08 14:34:58'),
(29, 13, 18, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-19 14:34:58'),
(30, 5, 20, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-01-24 14:04:58'),
(31, 34, 24, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-09 14:34:58'),
(32, 36, 29, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-04-13 15:34:58'),
(33, 44, 34, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-21 14:34:58'),
(34, 34, 2, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-11 14:34:58'),
(35, 36, 31, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-04 14:34:58'),
(36, 17, 50, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-16 14:34:58'),
(37, 41, 43, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-16 14:34:58'),
(38, 34, 20, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-01-16 15:34:58'),
(39, 27, 10, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-03-07 15:34:58'),
(40, 48, 40, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-04-08 14:04:58'),
(41, 42, 2, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-04-04 15:34:58'),
(42, 36, 13, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-14 14:34:58'),
(43, 32, 24, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-30 14:34:58'),
(44, 30, 21, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-01-06 14:04:58'),
(45, 20, 13, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-09 14:34:58'),
(46, 39, 4, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-06 14:34:58'),
(47, 16, 18, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-03-28 15:34:58'),
(48, 1, 24, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-02-14 14:04:58'),
(49, 27, 11, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-23 14:34:58'),
(50, 48, 24, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-01-16 15:04:58'),
(51, 18, 42, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-07 14:34:58'),
(52, 34, 45, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-13 14:34:58'),
(53, 15, 24, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-07 14:34:58'),
(54, 37, 50, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-04-12 15:34:58'),
(55, 26, 24, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-02-26 15:04:58'),
(56, 33, 15, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-25 14:34:58'),
(57, 5, 4, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2025-12-13 14:04:58'),
(58, 46, 23, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-01-29 15:04:58'),
(59, 49, 24, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-08 14:34:58'),
(60, 7, 46, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-06-06 15:34:58'),
(61, 49, 26, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-01-06 15:34:58'),
(62, 10, 13, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-01-30 15:04:58'),
(63, 33, 44, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-24 14:34:58'),
(64, 17, 30, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-11 14:34:58'),
(65, 12, 13, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-01-26 15:34:58'),
(66, 36, 38, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-03-13 15:04:58'),
(67, 36, 13, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-11 14:34:58'),
(68, 9, 49, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-03-17 15:04:58'),
(69, 9, 18, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-15 14:34:58'),
(70, 24, 14, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-20 14:34:58'),
(71, 31, 26, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-28 14:34:58'),
(72, 7, 23, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-12 14:34:58'),
(73, 13, 49, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-22 14:34:58'),
(74, 36, 23, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-28 14:34:58'),
(75, 27, 17, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-08 14:34:58'),
(76, 18, 47, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-03-18 15:04:58'),
(77, 48, 24, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-01-25 15:04:58'),
(78, 25, 23, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-01-16 15:04:58'),
(79, 14, 27, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-22 14:34:58'),
(80, 6, 9, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-06-05 15:04:58'),
(81, 15, 13, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-30 14:34:58'),
(82, 8, 6, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-04 14:34:58'),
(83, 50, 26, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-14 14:34:58'),
(84, 50, 38, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-21 14:34:58'),
(85, 12, 17, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-16 14:34:58'),
(86, 30, 4, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-05 14:34:58'),
(87, 11, 25, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-02-14 15:04:58'),
(88, 4, 8, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2025-12-27 15:04:58'),
(89, 8, 45, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-04-23 15:34:58'),
(90, 37, 46, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-13 14:34:58'),
(91, 14, 24, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-25 14:34:58'),
(92, 49, 28, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-08 14:34:58'),
(93, 1, 6, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-13 14:34:58'),
(94, 50, 2, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-19 14:34:58'),
(95, 44, 17, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-06-05 15:34:58'),
(96, 23, 31, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-02-26 15:34:58'),
(97, 38, 40, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-03-10 14:04:58'),
(98, 17, 9, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-02-25 15:04:58'),
(99, 5, 34, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-03-17 15:34:58'),
(100, 37, 16, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-15 14:34:58'),
(101, 47, 45, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-02-05 15:04:58'),
(102, 4, 41, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-04-15 14:04:58'),
(103, 48, 5, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-26 14:34:58'),
(104, 45, 17, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2025-12-11 15:04:58'),
(105, 48, 10, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-02-22 15:34:58'),
(106, 17, 25, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-12 14:34:58'),
(107, 39, 5, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-13 14:34:58'),
(108, 14, 38, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-08 14:34:58'),
(109, 14, 1, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-29 14:34:58'),
(110, 47, 38, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-04-10 15:34:58'),
(111, 27, 39, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-02-14 15:34:58'),
(112, 41, 48, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-06-03 15:34:58'),
(113, 18, 46, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-03-04 15:04:58'),
(114, 30, 17, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-07 14:34:58'),
(115, 2, 39, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-04-14 14:04:58'),
(116, 50, 46, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-01-18 14:04:58'),
(117, 21, 7, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-01-25 14:04:58'),
(118, 49, 15, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-04-20 14:04:58'),
(119, 25, 39, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-01-03 14:04:58'),
(120, 4, 43, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-02-16 14:04:58'),
(121, 34, 15, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-14 14:34:58'),
(122, 31, 24, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-06-01 15:34:58'),
(123, 47, 48, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-20 14:34:58'),
(124, 41, 50, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-07 14:34:58'),
(125, 25, 31, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-03-29 15:34:58'),
(126, 31, 20, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-20 14:34:58'),
(127, 45, 34, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-02-24 15:34:58'),
(128, 50, 23, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-06-08 14:34:58'),
(129, 11, 32, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-11 14:34:58'),
(130, 36, 18, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-12 14:34:58'),
(131, 11, 12, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-03-29 15:34:58'),
(132, 45, 2, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-05-03 15:04:58'),
(133, 38, 47, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-02-14 15:04:58'),
(134, 6, 37, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-18 14:34:58'),
(135, 48, 2, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-03-28 14:34:58'),
(136, 19, 49, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-02-05 14:34:58'),
(137, 24, 11, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-12 14:34:58'),
(138, 36, 37, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-01-05 15:34:58'),
(139, 39, 24, 'credit_transfer', 5.00, 5.00, 'Time credit transfer for completed session', '2026-06-02 14:04:58'),
(140, 1, 28, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-05-10 14:34:58'),
(141, 48, 45, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-14 14:34:58'),
(142, 32, 5, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-05-13 15:34:58'),
(143, 50, 9, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-06 14:34:58'),
(144, 10, 19, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-01-04 15:04:58'),
(145, 49, 12, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-06-05 14:34:58'),
(146, 30, 23, 'credit_transfer', 15.00, 15.00, 'Time credit transfer for completed session', '2026-01-04 15:04:58'),
(147, 44, 17, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2025-12-16 14:34:58'),
(148, 39, 47, 'credit_transfer', 20.00, 20.00, 'Time credit transfer for completed session', '2026-03-11 15:34:58'),
(149, 21, 2, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-04-15 14:34:58'),
(150, 21, 2, 'credit_transfer', 10.00, 10.00, 'Time credit transfer for completed session', '2026-01-30 14:34:58');

INSERT INTO community_task (user_id, task_type, description, location, credit_reward, status, assigned_at, completed_at) VALUES
(14, 'Bug Report', 'Community task: Bug Report contribution #1', 'Sylhet, Bangladesh', 5.00, 'pending', '2026-03-31 13:34:58', NULL),
(50, 'Translation', 'Community task: Translation contribution #2', 'Bogra, Bangladesh', 5.00, 'pending', '2026-05-08 13:34:58', NULL),
(32, 'Translation', 'Community task: Translation contribution #3', 'Rajshahi, Bangladesh', 10.00, 'in-progress', '2026-04-18 13:34:58', NULL),
(4, 'Skill Review', 'Community task: Skill Review contribution #4', 'Rangpur, Bangladesh', 8.00, 'in-progress', '2026-04-28 13:34:58', NULL),
(26, 'Content Moderation', 'Community task: Content Moderation contribution #5', 'Chittagong, Bangladesh', 8.00, 'completed', '2026-04-09 13:34:58', '2026-04-12 13:34:58'),
(42, 'Bug Report', 'Community task: Bug Report contribution #6', 'Rajshahi, Bangladesh', 10.00, 'completed', '2026-05-17 13:34:58', '2026-05-20 13:34:58'),
(3, 'Tutorial Writing', 'Community task: Tutorial Writing contribution #7', 'Comilla, Bangladesh', 3.00, 'completed', '2026-05-05 13:34:58', '2026-05-06 13:34:58'),
(8, 'Mentoring Session', 'Community task: Mentoring Session contribution #8', 'Rajshahi, Bangladesh', 5.00, 'completed', '2026-04-30 13:34:58', '2026-05-03 13:34:58'),
(2, 'Skill Review', 'Community task: Skill Review contribution #9', 'Narayanganj, Bangladesh', 8.00, 'pending', '2026-05-10 13:34:58', NULL),
(19, 'Skill Review', 'Community task: Skill Review contribution #10', 'Gazipur, Bangladesh', 10.00, 'completed', '2026-05-20 13:34:58', '2026-05-23 13:34:58'),
(28, 'Bug Report', 'Community task: Bug Report contribution #11', 'Dhaka, Bangladesh', 3.00, 'completed', '2026-04-14 13:34:58', '2026-04-18 13:34:58'),
(38, 'Tutorial Writing', 'Community task: Tutorial Writing contribution #12', 'Comilla, Bangladesh', 5.00, 'completed', '2026-05-14 13:34:58', '2026-05-17 13:34:58'),
(34, 'Tutorial Writing', 'Community task: Tutorial Writing contribution #13', 'Dhaka, Bangladesh', 3.00, 'completed', '2026-05-19 13:34:58', '2026-05-22 13:34:58'),
(47, 'Tutorial Writing', 'Community task: Tutorial Writing contribution #14', 'Comilla, Bangladesh', 8.00, 'completed', '2026-05-11 13:34:58', '2026-05-15 13:34:58'),
(40, 'Content Moderation', 'Community task: Content Moderation contribution #15', 'Narayanganj, Bangladesh', 3.00, 'pending', '2026-06-04 13:34:58', NULL),
(44, 'Mentoring Session', 'Community task: Mentoring Session contribution #16', 'Rangpur, Bangladesh', 10.00, 'pending', '2026-04-15 13:34:58', NULL),
(14, 'Mentoring Session', 'Community task: Mentoring Session contribution #17', 'Barisal, Bangladesh', 10.00, 'in-progress', '2026-05-08 13:34:58', NULL),
(39, 'Tutorial Writing', 'Community task: Tutorial Writing contribution #18', 'Rajshahi, Bangladesh', 3.00, 'in-progress', '2026-04-04 13:34:58', NULL),
(25, 'Skill Review', 'Community task: Skill Review contribution #19', 'Chittagong, Bangladesh', 5.00, 'completed', '2026-04-23 13:34:58', '2026-04-24 13:34:58'),
(19, 'Tutorial Writing', 'Community task: Tutorial Writing contribution #20', 'Mymensingh, Bangladesh', 5.00, 'in-progress', '2026-05-29 13:34:58', NULL),
(11, 'Skill Review', 'Community task: Skill Review contribution #21', 'Sylhet, Bangladesh', 3.00, 'pending', '2026-04-02 13:34:58', NULL),
(47, 'Bug Report', 'Community task: Bug Report contribution #22', 'Dhaka, Bangladesh', 8.00, 'completed', '2026-04-17 13:34:58', '2026-04-22 13:34:58'),
(35, 'Tutorial Writing', 'Community task: Tutorial Writing contribution #23', 'Barisal, Bangladesh', 5.00, 'completed', '2026-03-15 13:34:58', '2026-03-18 13:34:58'),
(48, 'Tutorial Writing', 'Community task: Tutorial Writing contribution #24', 'Bogra, Bangladesh', 5.00, 'completed', '2026-03-14 13:34:58', '2026-03-18 13:34:58'),
(30, 'Skill Review', 'Community task: Skill Review contribution #25', 'Dhaka, Bangladesh', 3.00, 'in-progress', '2026-05-18 13:34:58', NULL),
(14, 'Content Moderation', 'Community task: Content Moderation contribution #26', 'Rangpur, Bangladesh', 8.00, 'completed', '2026-05-13 13:34:58', '2026-05-18 13:34:58'),
(39, 'Translation', 'Community task: Translation contribution #27', 'Mymensingh, Bangladesh', 3.00, 'completed', '2026-05-19 13:34:58', '2026-05-24 13:34:58'),
(35, 'Translation', 'Community task: Translation contribution #28', 'Comilla, Bangladesh', 5.00, 'completed', '2026-03-20 13:34:58', '2026-03-21 13:34:58'),
(17, 'Bug Report', 'Community task: Bug Report contribution #29', 'Barisal, Bangladesh', 3.00, 'pending', '2026-04-03 13:34:58', NULL),
(34, 'Content Moderation', 'Community task: Content Moderation contribution #30', 'Rangpur, Bangladesh', 3.00, 'completed', '2026-05-19 13:34:58', '2026-05-22 13:34:58');




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
        VALUES (NEW.user_id, NULL, 0, 'Novice');
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
            WHEN NEW.completed_sessions >= 10 THEN 'Professional'
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
        ELSEIF NEW.status = 'under-review' THEN
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.provider_id, 'One of your sessions is currently under review by an admin.', 'session_update');
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.requester_id, 'One of your sessions is currently under review by an admin.', 'session_update');
        ELSEIF NEW.status = 'refunded' THEN
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.requester_id, 'Admin has resolved a dispute and your credits have been refunded.', 'session_update');
            INSERT INTO notifications (user_id, message, type)
            VALUES (NEW.provider_id, 'Admin has resolved a dispute. The session was refunded to the requester.', 'session_update');
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
    r.current_score AS reputation_score,
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
DROP PROCEDURE IF EXISTS sp_complete_session;
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
    r.current_score AS reputation_score,
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
DROP PROCEDURE IF EXISTS sp_complete_session;
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
        VALUES (p_user_id, NULL, 0, 'Novice')
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
    -- Fix: Count total sessions (requester or provider) matching the UI logic
    SELECT COUNT(*) INTO v_completed_sessions FROM exchange_sessions WHERE (requester_id = p_user_id OR provider_id = p_user_id) AND status = 'completed';
    
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

        IF v_wallet_balance >= 10.00 THEN
            ROLLBACK;
            SET p_status = 'error';
            SET p_message = 'You can only request a loan when your balance is less than 10 TC.';
        ELSE
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
    DECLARE v_completed_activities INT;
    DECLARE v_has_gifted_before BOOLEAN;
    
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
    ELSEIF p_amount > 5 THEN
        -- [Flaw 5] Per-transaction cap
        SET p_status = 'error';
        SET p_message = 'Maximum gift amount per transaction is 5 TC.';
    ELSE
        -- Check if user has completed at least one community task or session
        SELECT (
            (SELECT COUNT(*) FROM community_task WHERE user_id = p_from_user_id AND status = 'completed')
            +
            (SELECT COUNT(*) FROM exchange_sessions WHERE (requester_id = p_from_user_id OR provider_id = p_from_user_id) AND status = 'completed')
        ) INTO v_completed_activities;

        IF v_completed_activities < 3 THEN
            SET p_status = 'error';
            SET p_message = 'You must complete at least 3 community tasks or skill exchange sessions to unlock gifting.';
        ELSE
            -- Check if sender has already gifted this recipient before
            SELECT EXISTS (
                SELECT 1 FROM transactions 
                WHERE from_user_id = p_from_user_id 
                  AND to_user_id = v_to_user_id 
                  AND type = 'gift'
                  AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            ) INTO v_has_gifted_before;

            IF v_has_gifted_before THEN
                SET p_status = 'error';
                SET p_message = 'You have already sent a gift to this user recently. You must wait 2 weeks before gifting them again.';
            ELSE
                -- === [Flaw 3] Start transaction and lock wallet row ===
                START TRANSACTION;

        -- Lock the sender's wallet row to prevent race conditions
        SELECT balance INTO v_from_balance 
        FROM wallet 
        WHERE user_id = p_from_user_id 
        FOR UPDATE;

        -- [Flaw 1] Calculate outstanding loan debt (including defaulted)
        SELECT COALESCE(SUM(amount), 0) INTO v_loan_debt
        FROM loans
        WHERE user_id = p_from_user_id AND status IN ('active', 'defaulted');

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

        -- Complex Subquery 2: Both must be established (3+ completed sessions globally across both roles)
        SELECT (
            (SELECT COUNT(*) FROM exchange_sessions WHERE (requester_id = p_from_user_id OR provider_id = p_from_user_id) AND status = 'completed') >= 3
        ) AND (
            (SELECT COUNT(*) FROM exchange_sessions WHERE (requester_id = v_to_user_id OR provider_id = v_to_user_id) AND status = 'completed') >= 3
        ) INTO v_both_established;

        -- === Validation inside transaction ===
        IF v_loan_debt > 0 THEN
            ROLLBACK;
            SET p_status = 'error';
            SET p_message = 'Gifting blocked. You cannot gift time credits while you have an active loan debt.';
        ELSEIF v_from_balance < p_amount THEN
            -- Insufficient balance
            ROLLBACK;
            SET p_status = 'error';
            SET p_message = CONCAT('Insufficient balance. You have ', v_from_balance, ' TC.');
        ELSEIF (v_daily_gifted + p_amount) > 10 THEN
            -- [Flaw 5] Daily cap exceeded
            ROLLBACK;
            SET p_status = 'error';
            SET p_message = CONCAT('Daily gift limit exceeded. You have already gifted ', v_daily_gifted, ' TC today. Daily limit is 10 TC.');
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
    is_hidden TINYINT(1) DEFAULT 0,
    PRIMARY KEY (conversation_id, user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
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
SELECT user_id, name, location, bio, profile_photo, reliability_score, status, created_at, last_active_at
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
WHERE u.status = 'active' AND my_req.user_id != their_off.user_id;

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
            INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, note)
            VALUES (v_session_id, NULL, v_requester_id, 'full_refund', v_amount, v_amount, 'Refund via dispute resolution.');
            
            -- Update dispute status
            UPDATE disputes SET status = 'resolved_refunded' WHERE dispute_id = p_dispute_id;
            
            -- Penalize provider reliability in reputation
            UPDATE reputation 
            SET current_score = GREATEST(current_score - 0.50, 1.00),
                cancelled_sessions = cancelled_sessions + 1
            WHERE user_id = v_provider_id;

            -- Notify both users
            INSERT INTO notifications (user_id, message, type)
            VALUES (v_requester_id, 'Admin has resolved a dispute in your favor. Your credits have been refunded.', 'session_update');
            INSERT INTO notifications (user_id, message, type)
            VALUES (v_provider_id, 'Admin has resolved a dispute in the requester''s favor. The session was cancelled and credits refunded.', 'session_update');

            COMMIT;
            SET p_status = 'success';
            SET p_message = CONCAT('Dispute refunded. ', v_amount, ' TC returned to requester.');

        ELSEIF p_verdict = 'payout' THEN
            -- Update session status
            UPDATE exchange_sessions SET status = 'completed', completion_time = NOW() WHERE session_id = v_session_id;
            
            -- Transfer escrow credits to provider
            UPDATE wallet SET balance = balance + v_amount WHERE user_id = v_provider_id;
            
            -- Log credit transfer transaction
            INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, note)
            VALUES (v_session_id, v_requester_id, v_provider_id, 'credit_transfer', v_amount, v_amount, 'Payout via dispute resolution.');
            
            -- Update dispute status
            UPDATE disputes SET status = 'resolved_payout' WHERE dispute_id = p_dispute_id;
            
            -- Increment provider completed sessions
            UPDATE reputation 
            SET completed_sessions = completed_sessions + 1 
            WHERE user_id = v_provider_id;

            -- Notify both users
            INSERT INTO notifications (user_id, message, type)
            VALUES (v_requester_id, 'Admin has resolved a dispute in the provider''s favor. The session is marked completed and credits transferred.', 'session_update');
            INSERT INTO notifications (user_id, message, type)
            VALUES (v_provider_id, 'Admin has resolved a dispute in your favor. The session is marked completed and credits transferred.', 'session_update');

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



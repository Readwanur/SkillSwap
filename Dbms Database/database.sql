CREATE DATABASE Skillswap;
USE skillswap;

CREATE TABLE IF NOT EXISTS categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS skills (
    skill_id INT AUTO_INCREMENT PRIMARY KEY,
    skill_name VARCHAR(100) NOT NULL,
    category_id INT,
    description TEXT,
    difficulty_level ENUM('Beginner', 'Intermediate', 'Advanced'),
    base_duration INT DEFAULT 60,
    FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    location VARCHAR(100),
    bio TEXT,
    profile_photo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_skills_offered (
    user_id INT,
    skill_id INT,
    PRIMARY KEY (user_id, skill_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(skill_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_skills_requested (
    user_id INT,
    skill_id INT,
    is_priority BOOLEAN DEFAULT FALSE,
    PRIMARY KEY (user_id, skill_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(skill_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_availability (
    availability_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
    start_time TIME,
    end_time TIME,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

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

CREATE TABLE IF NOT EXISTS wallet (
    wallet_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    balance DECIMAL(15, 2) DEFAULT 0.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS community_task (
    task_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    task_type VARCHAR(50),
    status ENUM('pending', 'in-progress', 'completed', 'cancelled') DEFAULT 'pending',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    rep_boost DECIMAL(5, 2), 
    location VARCHAR(100),
    description TEXT,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

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
    FOREIGN KEY (requester_id) REFERENCES users(user_id),
    FOREIGN KEY (provider_id) REFERENCES users(user_id),
    FOREIGN KEY (skill_id) REFERENCES skills(skill_id)
);

CREATE TABLE IF NOT EXISTS review (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    rating INT CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    bonus_multiplier DECIMAL(3, 2) DEFAULT 1.00,
    FOREIGN KEY (session_id) REFERENCES exchange_sessions(session_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
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


INSERT INTO categories (category_name) VALUES
('Technology'), ('Design'), ('Language'), ('Health'), ('Marketing'), ('Academic'), ('Arts');

INSERT INTO skills (skill_name, category_id, description, difficulty_level) VALUES
('Python Programming', 1, 'Learn core Python concepts and automation.', 'Intermediate'),
('Graphic Design', 2, 'Creating visual content using Adobe Illustrator.', 'Advanced'),
('Spanish Conversation', 3, 'Practice speaking Spanish with a native.', 'Beginner'),
('Yoga Basics', 4, 'Introduction to Hatha Yoga poses and breathing.', 'Beginner'),
('SEO Strategy', 5, 'Optimizing websites for search engine rankings.', 'Advanced'),
('Math', 6, 'General mathematics and problem solving.', 'Intermediate'),
('Music', 7, 'Musical theory or instrument practice.', 'Beginner'),
('Art', 7, 'Visual arts, painting, or sketching.', 'Beginner'),
('Coding', 1, 'Software development and logic.', 'Intermediate');

INSERT INTO users (name, email, password_hash, location, bio) VALUES
('Alice Johnson', 'alice@example.com', '1234', 'New York', 'Software engineer passionate about teaching.'),
('Bob Smith', 'bob@example.com', '1234', 'San Francisco', 'Professional designer with 10 years experience.'),
('Charlie Brown', 'charlie@example.com', '1234', 'Madrid', 'Native Spanish speaker and travel blogger.'),
('David Miller', 'david@example.com', '1234', 'Los Angeles', 'Certified Yoga instructor with a focus on mindfulness.'),
('Eva Green', 'eva@example.com', '1234', 'London', 'Digital marketing specialist and SEO consultant.');

INSERT INTO user_availability (user_id, day_of_week, start_time, end_time) VALUES
(1, 'Monday', '09:00:00', '12:00:00'),
(1, 'Wednesday', '14:00:00', '17:00:00'),
(2, 'Tuesday', '10:00:00', '15:00:00'),
(3, 'Friday', '18:00:00', '21:00:00'),
(4, 'Saturday', '08:00:00', '10:00:00');

INSERT INTO user_skills_offered (user_id, skill_id) VALUES 
(1, 9), (2, 8), (3, 7), (4, 6), (5, 5);

INSERT INTO user_skills_requested (user_id, skill_id) VALUES 
(1, 6), (2, 9), (3, 1), (4, 7), (5, 2);

INSERT INTO reputation (user_id, current_score, completed_sessions, mentor_level) VALUES
(1, 4.8, 12, 'Level 4 Mentor'), (2, 4.5, 8, 'Advanced Mentor'), (3, 4.9, 15, 'Master Mentor'), (4, 5.0, 5, 'Novice'), (5, 4.2, 7, 'Intermediate');

INSERT INTO wallet (user_id, balance) VALUES
(1, 24.50), (2, 50.00), (3, 75.00), (4, 120.00), (5, 30.00);

INSERT INTO community_task (user_id, task_type, status, rep_boost, location, description) VALUES
(1, 'Physical', 'completed', 8.00, 'Campus Garden', 'Help with seasonal planting and soil preparation.'),
(2, 'Library', 'pending', 5.00, 'Central Library', 'Support library staff in re-shelving academic journals.'),
(4, 'Admin', 'completed', 12.00, 'Social Club', 'Input historical membership data for the Student Union archive.');

INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status, scheduled_time, session_duration, time_credit_transfer) VALUES
(2, 1, 9, 'completed', '2026-05-10 14:00:00', 60, 20.00),
(1, 4, 6, 'completed', '2026-05-11 10:00:00', 45, 15.00),
(4, 5, 5, 'scheduled', '2026-05-12 09:00:00', 60, 25.00),
(3, 2, 8, 'completed', '2026-05-09 18:00:00', 30, 10.00);

INSERT INTO review (session_id, rating, comment) VALUES
(1, 5, 'Great coding session! Alice is very helpful.'),
(2, 4, 'Very good Math practice, highly recommended.'),
(4, 5, 'Bob is a fantastic art teacher, very creative.');

INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount) VALUES
(1, 1, 2, 'credit_transfer', 20.00, 20.00),
(2, 2, 3, 'credit_transfer', 15.00, 15.00),
(4, 1, 4, 'credit_transfer', 10.00, 10.00);

-- Manga Hub Database - Simple Version
CREATE DATABASE IF NOT EXISTS manga_hub DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE manga_hub;

-- Disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100),
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'translator', 'admin') DEFAULT 'user',
    coins INT DEFAULT 100,
    realm VARCHAR(50) DEFAULT 'Luyện Khí',
    realm_stage INT DEFAULT 1,
    avatar VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- User realm progress
CREATE TABLE user_realm_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    current_qi INT DEFAULT 0,
    qi_needed INT DEFAULT 10,
    realm VARCHAR(50) DEFAULT 'Luyện Khí',
    realm_stage INT DEFAULT 1,
    total_qi_earned INT DEFAULT 0,
    last_qi_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Comics table
CREATE TABLE comics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    thumbnail VARCHAR(500),
    author VARCHAR(100),
    status ENUM('ongoing', 'completed', 'dropped') DEFAULT 'ongoing',
    genres TEXT,
    views INT DEFAULT 0,
    follows INT DEFAULT 0,
    rating DECIMAL(3,2) DEFAULT 0.00,
    total_rating INT DEFAULT 0,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Chapters table
CREATE TABLE chapters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comic_id INT NOT NULL,
    chapter_title VARCHAR(200) NOT NULL,
    chapter_number DECIMAL(5,1),
    images TEXT,
    is_vip BOOLEAN DEFAULT FALSE,
    coins_unlock INT DEFAULT 0,
    views INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Comments table
CREATE TABLE comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comic_id INT,
    chapter_id INT,
    parent_id INT,
    content TEXT NOT NULL,
    is_pinned BOOLEAN DEFAULT FALSE,
    is_deleted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Comment likes table
CREATE TABLE comment_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comment_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- User favorites table
CREATE TABLE user_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comic_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Comic ratings table
CREATE TABLE comic_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comic_id INT NOT NULL,
    rating INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Read history table
CREATE TABLE read_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chapter_id INT NOT NULL,
    qi_awarded BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Chapter unlocks table
CREATE TABLE chapter_unlocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chapter_id INT NOT NULL,
    coins_spent INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Qi history table
CREATE TABLE qi_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chapter_id INT,
    qi_amount INT NOT NULL,
    action_type ENUM('read_chapter', 'daily_bonus', 'admin_grant') DEFAULT 'read_chapter',
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Realm advancement history table
CREATE TABLE realm_advancement_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    from_realm VARCHAR(50),
    from_stage INT,
    to_realm VARCHAR(50) NOT NULL,
    to_stage INT NOT NULL,
    total_qi_used INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add unique constraints
ALTER TABLE users ADD UNIQUE(username);
ALTER TABLE users ADD UNIQUE(email);
ALTER TABLE user_realm_progress ADD UNIQUE(user_id);
ALTER TABLE comment_likes ADD UNIQUE(user_id, comment_id);
ALTER TABLE user_favorites ADD UNIQUE(user_id, comic_id);
ALTER TABLE comic_ratings ADD UNIQUE(user_id, comic_id);
ALTER TABLE read_history ADD UNIQUE(user_id, chapter_id);
ALTER TABLE chapter_unlocks ADD UNIQUE(user_id, chapter_id);

-- Add indexes
ALTER TABLE users ADD INDEX(username);
ALTER TABLE users ADD INDEX(email);
ALTER TABLE users ADD INDEX(role);
ALTER TABLE comics ADD INDEX(title);
ALTER TABLE comics ADD INDEX(status);
ALTER TABLE comics ADD INDEX(views);
ALTER TABLE chapters ADD INDEX(comic_id);
ALTER TABLE comments ADD INDEX(user_id);
ALTER TABLE comments ADD INDEX(comic_id);
ALTER TABLE comments ADD INDEX(chapter_id);
ALTER TABLE comments ADD INDEX(parent_id);

-- Add foreign keys
ALTER TABLE user_realm_progress ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE comics ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE chapters ADD FOREIGN KEY (comic_id) REFERENCES comics(id) ON DELETE CASCADE;
ALTER TABLE comments ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE comments ADD FOREIGN KEY (comic_id) REFERENCES comics(id) ON DELETE CASCADE;
ALTER TABLE comments ADD FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE;
ALTER TABLE comments ADD FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE;
ALTER TABLE comment_likes ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE comment_likes ADD FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE;
ALTER TABLE user_favorites ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE user_favorites ADD FOREIGN KEY (comic_id) REFERENCES comics(id) ON DELETE CASCADE;
ALTER TABLE comic_ratings ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE comic_ratings ADD FOREIGN KEY (comic_id) REFERENCES comics(id) ON DELETE CASCADE;
ALTER TABLE read_history ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE read_history ADD FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE;
ALTER TABLE chapter_unlocks ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE chapter_unlocks ADD FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE;
ALTER TABLE qi_history ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE qi_history ADD FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE SET NULL;
ALTER TABLE realm_advancement_history ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Insert sample data
INSERT INTO users (username, email, password, role, coins, realm, realm_stage) VALUES
('admin', 'admin@mangahub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 10000, 'Độ Kiếp', 1),
('translator', 'translator@mangahub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'translator', 5000, 'Đại Thừa', 5),
('user1', 'user1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 500, 'Trúc Cơ', 3),
('user2', 'user2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 300, 'Luyện Khí', 8);

INSERT INTO user_realm_progress (user_id, current_qi, qi_needed, realm, realm_stage, total_qi_earned) VALUES
(1, 0, 3200, 'Độ Kiếp', 1, 50000),
(2, 250, 400, 'Đại Thừa', 5, 15000),
(3, 15, 30, 'Trúc Cơ', 3, 200),
(4, 8, 10, 'Luyện Khí', 8, 80);

INSERT INTO comics (title, description, thumbnail, author, status, genres, views, follows, created_by) VALUES
('Tu Tiên Đại Đạo', 'Câu chuyện về hành trình tu tiên đầy gian nan của một thiếu niên bình thường.', 'https://via.placeholder.com/300x400?text=Tu+Tien+Dai+Dao', 'Thiên Tàm Thổ Đậu', 'ongoing', 'Tu tiên, Hành động', 15420, 890, 1),
('Ngự Thiên Thần Đế', 'Một tu sĩ tái sinh về quá khứ với ký ức về tương lai.', 'https://via.placeholder.com/300x400?text=Ngu+Thien+Than+De', 'Mộng Nhập Thần Cơ', 'ongoing', 'Tu tiên, Tái sinh', 25630, 1240, 1);

INSERT INTO chapters (comic_id, chapter_title, chapter_number, images, views) VALUES
(1, 'Chương 1: Khởi đầu hành trình tu tiên', 1, 'https://via.placeholder.com/800x1200?text=Chapter+1+Page+1', 2500),
(1, 'Chương 2: Đột phá Luyện Khí', 2, 'https://via.placeholder.com/800x1200?text=Chapter+2+Page+1', 2100),
(2, 'Chương 1: Tái sinh trở về', 1, 'https://via.placeholder.com/800x1200?text=Comic+2+Ch+1+P+1', 3200);

INSERT INTO comments (user_id, comic_id, content, is_pinned) VALUES
(1, 1, 'Truyện hay quá! Hệ thống tu luyện rất chi tiết và hấp dẫn.', 1),
(2, 1, 'Đồng ý với admin! Đây là một trong những truyện tu tiên hay nhất.', 0),
(3, 1, 'Nhân vật chính phát triển rất tự nhiên, không bị mary sue.', 0);

INSERT INTO comment_likes (user_id, comment_id) VALUES
(2, 1), (3, 1), (4, 1),
(1, 2), (3, 2),
(1, 3), (2, 3);

INSERT INTO read_history (user_id, chapter_id, qi_awarded) VALUES
(3, 1, 1), (3, 2, 1),
(4, 1, 1);

INSERT INTO user_favorites (user_id, comic_id) VALUES
(3, 1), (4, 1), (2, 1);

INSERT INTO comic_ratings (user_id, comic_id, rating) VALUES
(2, 1, 5), (3, 1, 5), (4, 1, 4);

-- Update comic stats
UPDATE comics SET 
    rating = (SELECT AVG(rating) FROM comic_ratings WHERE comic_id = comics.id),
    total_rating = (SELECT COUNT(*) FROM comic_ratings WHERE comic_id = comics.id),
    follows = (SELECT COUNT(*) FROM user_favorites WHERE comic_id = comics.id);

-- Complete!
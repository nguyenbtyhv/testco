-- ===================================================================
-- 🌟 MANGA HUB DATABASE - HỆ THỐNG ĐỌC TRUYỆN VỚI CẢNH GIỚI VÀ BÌNH LUẬN
-- ===================================================================

-- Tạo database
CREATE DATABASE IF NOT EXISTS `manga_hub` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `manga_hub`;

-- Tắt foreign key checks tạm thời
SET FOREIGN_KEY_CHECKS = 0;

-- ===================================================================
-- 1. BẢNG NGƯỜI DÙNG (USERS)
-- ===================================================================
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) UNIQUE NOT NULL,
    `email` VARCHAR(100) UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('user', 'translator', 'admin') DEFAULT 'user',
    `coins` INT DEFAULT 100,
    `realm` VARCHAR(50) DEFAULT 'Luyện Khí',
    `realm_stage` INT DEFAULT 1,
    `avatar` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 2. BẢNG TIẾN TRÌNH CẢNH GIỚI (USER_REALM_PROGRESS)
-- ===================================================================
CREATE TABLE `user_realm_progress` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `current_qi` INT DEFAULT 0,
    `qi_needed` INT DEFAULT 10,
    `realm` VARCHAR(50) DEFAULT 'Luyện Khí',
    `realm_stage` INT DEFAULT 1,
    `total_qi_earned` INT DEFAULT 0,
    `last_qi_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 3. BẢNG TRUYỆN (COMICS)
-- ===================================================================
CREATE TABLE `comics` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `thumbnail` VARCHAR(500),
    `author` VARCHAR(100),
    `status` ENUM('ongoing', 'completed', 'dropped') DEFAULT 'ongoing',
    `genres` TEXT,
    `views` INT DEFAULT 0,
    `follows` INT DEFAULT 0,
    `rating` DECIMAL(3,2) DEFAULT 0.00,
    `total_rating` INT DEFAULT 0,
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 4. BẢNG CHƯƠNG (CHAPTERS)
-- ===================================================================
CREATE TABLE `chapters` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `comic_id` INT NOT NULL,
    `chapter_title` VARCHAR(200) NOT NULL,
    `chapter_number` DECIMAL(5,1) DEFAULT NULL,
    `images` TEXT,
    `is_vip` BOOLEAN DEFAULT FALSE,
    `coins_unlock` INT DEFAULT 0,
    `views` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 5. BẢNG BÌNH LUẬN (COMMENTS)
-- ===================================================================
CREATE TABLE `comments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `comic_id` INT DEFAULT NULL,
    `chapter_id` INT DEFAULT NULL,
    `parent_id` INT DEFAULT NULL,
    `content` TEXT NOT NULL,
    `is_pinned` BOOLEAN DEFAULT FALSE,
    `is_deleted` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 6. BẢNG LIKE BÌNH LUẬN (COMMENT_LIKES)
-- ===================================================================
CREATE TABLE `comment_likes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `comment_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 7. BẢNG THEO DÕI TRUYỆN (USER_FAVORITES)
-- ===================================================================
CREATE TABLE `user_favorites` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `comic_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 8. BẢNG ĐÁNH GIÁ TRUYỆN (COMIC_RATINGS)
-- ===================================================================
CREATE TABLE `comic_ratings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `comic_id` INT NOT NULL,
    `rating` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 9. BẢNG LỊCH SỬ ĐỌC (READ_HISTORY)
-- ===================================================================
CREATE TABLE `read_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `chapter_id` INT NOT NULL,
    `qi_awarded` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 10. BẢNG MỞ KHÓA CHƯƠNG VIP (CHAPTER_UNLOCKS)
-- ===================================================================
CREATE TABLE `chapter_unlocks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `chapter_id` INT NOT NULL,
    `coins_spent` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 11. BẢNG LỊCH SỬ LINH KHÍ (QI_HISTORY)
-- ===================================================================
CREATE TABLE `qi_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `chapter_id` INT DEFAULT NULL,
    `qi_amount` INT NOT NULL,
    `action_type` ENUM('read_chapter', 'daily_bonus', 'admin_grant') DEFAULT 'read_chapter',
    `description` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 12. BẢNG LỊCH SỬ THĂNG CẢNH GIỚI (REALM_ADVANCEMENT_HISTORY)
-- ===================================================================
CREATE TABLE `realm_advancement_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `from_realm` VARCHAR(50),
    `from_stage` INT,
    `to_realm` VARCHAR(50) NOT NULL,
    `to_stage` INT NOT NULL,
    `total_qi_used` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- THÊM INDEXES VÀ FOREIGN KEYS
-- ===================================================================

-- Indexes cho bảng users
ALTER TABLE `users` ADD INDEX `idx_username` (`username`);
ALTER TABLE `users` ADD INDEX `idx_email` (`email`);
ALTER TABLE `users` ADD INDEX `idx_role` (`role`);

-- Indexes và foreign keys cho user_realm_progress
ALTER TABLE `user_realm_progress` ADD UNIQUE KEY `unique_user` (`user_id`);
ALTER TABLE `user_realm_progress` ADD INDEX `idx_realm` (`realm`, `realm_stage`);
ALTER TABLE `user_realm_progress` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

-- Indexes và foreign keys cho comics
ALTER TABLE `comics` ADD INDEX `idx_title` (`title`);
ALTER TABLE `comics` ADD INDEX `idx_status` (`status`);
ALTER TABLE `comics` ADD INDEX `idx_views` (`views`);
ALTER TABLE `comics` ADD INDEX `idx_updated` (`updated_at`);
ALTER TABLE `comics` ADD INDEX `idx_title_search` (`title`(50));
ALTER TABLE `comics` ADD INDEX `idx_rating` (`rating`);
ALTER TABLE `comics` ADD FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Indexes và foreign keys cho chapters
ALTER TABLE `chapters` ADD INDEX `idx_comic_chapter` (`comic_id`, `chapter_number`);
ALTER TABLE `chapters` ADD INDEX `idx_views` (`views`);
ALTER TABLE `chapters` ADD FOREIGN KEY (`comic_id`) REFERENCES `comics`(`id`) ON DELETE CASCADE;

-- Indexes và foreign keys cho comments
ALTER TABLE `comments` ADD INDEX `idx_comic_chapter` (`comic_id`, `chapter_id`);
ALTER TABLE `comments` ADD INDEX `idx_parent` (`parent_id`);
ALTER TABLE `comments` ADD INDEX `idx_pinned` (`is_pinned`);
ALTER TABLE `comments` ADD INDEX `idx_created` (`created_at`);
ALTER TABLE `comments` ADD INDEX `idx_user_created` (`user_id`, `created_at`);
ALTER TABLE `comments` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
ALTER TABLE `comments` ADD FOREIGN KEY (`comic_id`) REFERENCES `comics`(`id`) ON DELETE CASCADE;
ALTER TABLE `comments` ADD FOREIGN KEY (`chapter_id`) REFERENCES `chapters`(`id`) ON DELETE CASCADE;
ALTER TABLE `comments` ADD FOREIGN KEY (`parent_id`) REFERENCES `comments`(`id`) ON DELETE CASCADE;

-- Indexes và foreign keys cho comment_likes
ALTER TABLE `comment_likes` ADD UNIQUE KEY `unique_like` (`user_id`, `comment_id`);
ALTER TABLE `comment_likes` ADD INDEX `idx_comment` (`comment_id`);
ALTER TABLE `comment_likes` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
ALTER TABLE `comment_likes` ADD FOREIGN KEY (`comment_id`) REFERENCES `comments`(`id`) ON DELETE CASCADE;

-- Indexes và foreign keys cho user_favorites
ALTER TABLE `user_favorites` ADD UNIQUE KEY `unique_favorite` (`user_id`, `comic_id`);
ALTER TABLE `user_favorites` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
ALTER TABLE `user_favorites` ADD FOREIGN KEY (`comic_id`) REFERENCES `comics`(`id`) ON DELETE CASCADE;

-- Indexes và foreign keys cho comic_ratings
ALTER TABLE `comic_ratings` ADD UNIQUE KEY `unique_rating` (`user_id`, `comic_id`);
ALTER TABLE `comic_ratings` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
ALTER TABLE `comic_ratings` ADD FOREIGN KEY (`comic_id`) REFERENCES `comics`(`id`) ON DELETE CASCADE;

-- Indexes và foreign keys cho read_history
ALTER TABLE `read_history` ADD UNIQUE KEY `unique_read` (`user_id`, `chapter_id`);
ALTER TABLE `read_history` ADD INDEX `idx_user_date` (`user_id`, `created_at`);
ALTER TABLE `read_history` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
ALTER TABLE `read_history` ADD FOREIGN KEY (`chapter_id`) REFERENCES `chapters`(`id`) ON DELETE CASCADE;

-- Indexes và foreign keys cho chapter_unlocks
ALTER TABLE `chapter_unlocks` ADD UNIQUE KEY `unique_unlock` (`user_id`, `chapter_id`);
ALTER TABLE `chapter_unlocks` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
ALTER TABLE `chapter_unlocks` ADD FOREIGN KEY (`chapter_id`) REFERENCES `chapters`(`id`) ON DELETE CASCADE;

-- Indexes và foreign keys cho qi_history
ALTER TABLE `qi_history` ADD INDEX `idx_user_date` (`user_id`, `created_at`);
ALTER TABLE `qi_history` ADD INDEX `idx_action` (`action_type`);
ALTER TABLE `qi_history` ADD INDEX `idx_user_action` (`user_id`, `action_type`);
ALTER TABLE `qi_history` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
ALTER TABLE `qi_history` ADD FOREIGN KEY (`chapter_id`) REFERENCES `chapters`(`id`) ON DELETE SET NULL;

-- Indexes và foreign keys cho realm_advancement_history
ALTER TABLE `realm_advancement_history` ADD INDEX `idx_user_date` (`user_id`, `created_at`);
ALTER TABLE `realm_advancement_history` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

-- Bật lại foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- ===================================================================
-- DỮ LIỆU MẪU (SAMPLE DATA)
-- ===================================================================

-- Tạo tài khoản Admin mặc định
INSERT INTO `users` (`username`, `email`, `password`, `role`, `coins`, `realm`, `realm_stage`) VALUES
('admin', 'admin@mangahub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 10000, 'Độ Kiếp', 1),
('translator', 'translator@mangahub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'translator', 5000, 'Đại Thừa', 5),
('user1', 'user1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 500, 'Trúc Cơ', 3),
('user2', 'user2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 300, 'Luyện Khí', 8);

-- Khởi tạo tiến trình cảnh giới cho users
INSERT INTO `user_realm_progress` (`user_id`, `current_qi`, `qi_needed`, `realm`, `realm_stage`, `total_qi_earned`) VALUES
(1, 0, 3200, 'Độ Kiếp', 1, 50000),
(2, 250, 400, 'Đại Thừa', 5, 15000),
(3, 15, 30, 'Trúc Cơ', 3, 200),
(4, 8, 10, 'Luyện Khí', 8, 80);

-- Tạo dữ liệu truyện mẫu
INSERT INTO `comics` (`title`, `description`, `thumbnail`, `author`, `status`, `genres`, `views`, `follows`, `created_by`) VALUES
('Tu Tiên Đại Đạo', 'Câu chuyện về hành trình tu tiên đầy gian nan của một thiếu niên bình thường. Từ Luyện Khí đến Độ Kiếp, anh ta sẽ phải đối mặt với vô số thử thách và kẻ thù mạnh mẽ.', 'https://via.placeholder.com/300x400?text=Tu+Tien+Dai+Dao', 'Thiên Tàm Thổ Đậu', 'ongoing', 'Tu tiên, Hành động, Phiêu lưu', 15420, 890, 1),
('Ngự Thiên Thần Đế', 'Một tu sĩ tái sinh về quá khứ với ký ức về tương lai. Lần này, anh ta sẽ không để bi kịch lặp lại và trở thành bậc thần đế thực thụ.', 'https://via.placeholder.com/300x400?text=Ngu+Thien+Than+De', 'Mộng Nhập Thần Cơ', 'ongoing', 'Tu tiên, Tái sinh, Drama', 25630, 1240, 1),
('Thiên Đạo Đồ Thư Quán', 'Trong một thế giới mà sách có thể ban sức mạnh, một cậu bé mồ côi khám phá ra mình có thể đọc được những cuốn sách cấm kỵ nhất.', 'https://via.placeholder.com/300x400?text=Thien+Dao+Do+Thu', 'Hắc Sơn Lão Quỷ', 'completed', 'Huyền ảo, Phiêu lưu, Kiến thức', 8920, 456, 2),
('Ma Đế Trọng Sinh', 'Ma đế một thời bị phản bội và chết, nhưng được tái sinh về thời niên thiếu. Lần này, tất cả kẻ thù sẽ phải trả giá!', 'https://via.placeholder.com/300x400?text=Ma+De+Trong+Sinh', 'Phong Hỏa Hy Chư Hầu', 'ongoing', 'Ma đạo, Báo thù, Tái sinh', 18750, 975, 1);

-- Tạo chương mẫu
INSERT INTO `chapters` (`comic_id`, `chapter_title`, `chapter_number`, `images`, `views`) VALUES
(1, 'Chương 1: Khởi đầu hành trình tu tiên', 1, 'https://via.placeholder.com/800x1200?text=Chapter+1+Page+1\nhttps://via.placeholder.com/800x1200?text=Chapter+1+Page+2\nhttps://via.placeholder.com/800x1200?text=Chapter+1+Page+3', 2500),
(1, 'Chương 2: Đột phá Luyện Khí', 2, 'https://via.placeholder.com/800x1200?text=Chapter+2+Page+1\nhttps://via.placeholder.com/800x1200?text=Chapter+2+Page+2', 2100),
(1, 'Chương 3: Gặp gỡ sư phụ', 3, 'https://via.placeholder.com/800x1200?text=Chapter+3+Page+1\nhttps://via.placeholder.com/800x1200?text=Chapter+3+Page+2\nhttps://via.placeholder.com/800x1200?text=Chapter+3+Page+3\nhttps://via.placeholder.com/800x1200?text=Chapter+3+Page+4', 1890),
(2, 'Chương 1: Tái sinh trở về', 1, 'https://via.placeholder.com/800x1200?text=Comic+2+Ch+1+P+1\nhttps://via.placeholder.com/800x1200?text=Comic+2+Ch+1+P+2', 3200),
(2, 'Chương 2: Báo thù bắt đầu', 2, 'https://via.placeholder.com/800x1200?text=Comic+2+Ch+2+P+1\nhttps://via.placeholder.com/800x1200?text=Comic+2+Ch+2+P+2\nhttps://via.placeholder.com/800x1200?text=Comic+2+Ch+2+P+3', 2890),
(3, 'Chương 1: Khám phá bí mật', 1, 'https://via.placeholder.com/800x1200?text=Comic+3+Ch+1+P+1', 1200),
(4, 'Chương 1: Ma đế tái sinh', 1, 'https://via.placeholder.com/800x1200?text=Comic+4+Ch+1+P+1\nhttps://via.placeholder.com/800x1200?text=Comic+4+Ch+1+P+2', 2750);

-- Tạo bình luận mẫu
INSERT INTO `comments` (`user_id`, `comic_id`, `content`, `is_pinned`) VALUES
(1, 1, 'Truyện hay quá! Hệ thống tu luyện rất chi tiết và hấp dẫn. Mong tác giả cập nhật thêm nhiều chương.', 1),
(2, 1, 'Đồng ý với admin! Đây là một trong những truyện tu tiên hay nhất tôi từng đọc. Art style cũng rất đẹp.', 0),
(3, 1, 'Nhân vật chính phát triển rất tự nhiên, không bị mary sue. Rất thích cách tác giả xây dựng thế giới.', 0),
(4, 2, 'Truyện tái sinh luôn hấp dẫn! Nhất là khi MC có kiến thức về tương lai để báo thù.', 0),
(1, 3, 'Concept về sách ban sức mạnh rất độc đáo. Khác biệt so với những truyện tu tiên thông thường.', 1);

-- Tạo bình luận chương
INSERT INTO `comments` (`user_id`, `chapter_id`, `content`) VALUES
(3, 1, 'Chapter đầu tiên rất ấn tượng! Cách dẫn dắt câu chuyện rất hay.'),
(4, 1, 'Tôi đã nhận được 1 Linh Khí khi đọc xong! Hệ thống cảnh giới thú vị quá.'),
(2, 2, 'Đột phá Luyện Khí mô tả rất chi tiết và hợp lý. Không bị rush.'),
(3, 4, 'Scene tái sinh được vẽ rất dramatic! Cảm xúc của MC truyền tải tốt.');

-- Tạo reply bình luận
INSERT INTO `comments` (`user_id`, `comic_id`, `parent_id`, `content`) VALUES
(4, 1, 1, 'Đúng vậy admin! Tôi cũng rất thích hệ thống cảnh giới từ Luyện Khí đến Độ Kiếp.'),
(1, 1, 2, 'Cảm ơn bạn! Chúng tôi sẽ tiếp tục cập nhật những truyện chất lượng cao.'),
(2, 1, 3, 'Tôi cũng nghĩ vậy. Character development rất tốt, không bị flat.');

-- Tạo likes cho bình luận
INSERT INTO `comment_likes` (`user_id`, `comment_id`) VALUES
(2, 1), (3, 1), (4, 1),  -- 3 likes cho comment admin
(1, 2), (3, 2), (4, 2),  -- 3 likes cho comment translator
(1, 3), (2, 3),          -- 2 likes
(1, 4), (2, 4), (3, 4),  -- 3 likes
(2, 5), (3, 5), (4, 5);  -- 3 likes

-- Tạo lịch sử đọc
INSERT INTO `read_history` (`user_id`, `chapter_id`, `qi_awarded`) VALUES
(3, 1, 1), (3, 2, 1), (3, 4, 1),
(4, 1, 1), (4, 4, 1),
(2, 1, 1), (2, 2, 1), (2, 4, 1), (2, 5, 1);

-- Tạo theo dõi truyện
INSERT INTO `user_favorites` (`user_id`, `comic_id`) VALUES
(3, 1), (3, 2), (3, 4),
(4, 1), (4, 2),
(2, 1), (2, 3), (2, 4);

-- Tạo đánh giá truyện  
INSERT INTO `comic_ratings` (`user_id`, `comic_id`, `rating`) VALUES
(2, 1, 5), (3, 1, 5), (4, 1, 4),
(1, 2, 4), (3, 2, 5), (4, 2, 4),
(1, 3, 4), (2, 3, 4), (4, 3, 3),
(2, 4, 5), (3, 4, 4);

-- Tạo lịch sử linh khí
INSERT INTO `qi_history` (`user_id`, `chapter_id`, `qi_amount`, `action_type`, `description`) VALUES
(3, 1, 1, 'read_chapter', 'Đọc Tu Tiên Đại Đạo - Chương 1'),
(3, 2, 1, 'read_chapter', 'Đọc Tu Tiên Đại Đạo - Chương 2'),
(3, 4, 1, 'read_chapter', 'Đọc Ngự Thiên Thần Đế - Chương 1'),
(4, 1, 1, 'read_chapter', 'Đọc Tu Tiên Đại Đạo - Chương 1'),
(4, 4, 1, 'read_chapter', 'Đọc Ngự Thiên Thần Đế - Chương 1');

-- Tạo lịch sử thăng cảnh giới
INSERT INTO `realm_advancement_history` (`user_id`, `from_realm`, `from_stage`, `to_realm`, `to_stage`, `total_qi_used`) VALUES
(3, 'Luyện Khí', 10, 'Trúc Cơ', 1, 100),
(3, 'Trúc Cơ', 1, 'Trúc Cơ', 2, 25),
(3, 'Trúc Cơ', 2, 'Trúc Cơ', 3, 30),
(4, 'Luyện Khí', 7, 'Luyện Khí', 8, 10);

-- ===================================================================
-- CẬP NHẬT THỐNG KÊ CHO COMICS
-- ===================================================================
UPDATE `comics` SET 
    `rating` = (SELECT IFNULL(AVG(rating), 0) FROM `comic_ratings` WHERE comic_id = comics.id),
    `total_rating` = (SELECT COUNT(*) FROM `comic_ratings` WHERE comic_id = comics.id),
    `follows` = (SELECT COUNT(*) FROM `user_favorites` WHERE comic_id = comics.id);

-- ===================================================================
-- VIEWS HỮU ÍCH (Tùy chọn - có thể bỏ qua nếu không hỗ trợ)
-- ===================================================================

-- View: Bảng xếp hạng tu luyện
CREATE VIEW `cultivation_leaderboard` AS
SELECT 
    u.id, u.username, u.role,
    urp.realm, urp.realm_stage, urp.total_qi_earned,
    urp.current_qi, urp.qi_needed
FROM users u
JOIN user_realm_progress urp ON u.id = urp.user_id
ORDER BY 
    CASE urp.realm
        WHEN 'Độ Kiếp' THEN 9
        WHEN 'Đại Thừa' THEN 8  
        WHEN 'Hợp Thể' THEN 7
        WHEN 'Luyện Hư' THEN 6
        WHEN 'Hóa Thần' THEN 5
        WHEN 'Nguyên Anh' THEN 4
        WHEN 'Kết Đan' THEN 3
        WHEN 'Trúc Cơ' THEN 2
        WHEN 'Luyện Khí' THEN 1
        ELSE 0
    END DESC,
    urp.realm_stage DESC,
    urp.total_qi_earned DESC;

-- ===================================================================
-- KẾT THÚC FILE SQL
-- ===================================================================

-- Password mặc định cho tất cả tài khoản demo: "password123"
-- Admin: username=admin, password=password123
-- Translator: username=translator, password=password123  
-- Users: username=user1/user2, password=password123

-- File SQL đã được sửa lỗi và tối ưu hóa! 🌟
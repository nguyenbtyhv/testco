-- ================================================================
-- HỆ THỐNG TRUYỆN TRANH VÀ XẾP HẠNG - DATABASE HOÀN CHỈNH
-- ================================================================

-- Tạo database (nếu chưa có)
CREATE DATABASE IF NOT EXISTS `truyen_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `truyen_db`;

-- ================================================================
-- 1. BẢNG NGƯỜI DÙNG (USERS)
-- ================================================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `email` varchar(100) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin','translator','vip') DEFAULT 'user',
  `coins` int(11) DEFAULT 0,
  `realm` varchar(20) DEFAULT 'Luyện Khí',
  `realm_stage` int(11) DEFAULT 1,
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_coins` (`coins`),
  KEY `idx_users_realm` (`realm`, `realm_stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 2. BẢNG TIẾN TRÌNH TU LUYỆN (USER_REALM_PROGRESS)
-- ================================================================
DROP TABLE IF EXISTS `user_realm_progress`;
CREATE TABLE `user_realm_progress` (
  `user_id` int(11) NOT NULL,
  `current_qi` int(11) DEFAULT 0,
  `qi_needed` int(11) DEFAULT 100,
  `total_qi_earned` bigint(20) DEFAULT 0,
  `last_qi_gain` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `breakthrough_count` int(11) DEFAULT 0,
  PRIMARY KEY (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 3. BẢNG TRUYỆN (COMICS)
-- ================================================================
DROP TABLE IF EXISTS `comics`;
CREATE TABLE `comics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `author` varchar(100) DEFAULT NULL,
  `description` text,
  `thumbnail` varchar(500) DEFAULT NULL,
  `status` enum('ongoing','completed','hiatus') DEFAULT 'ongoing',
  `genre` varchar(255) DEFAULT NULL,
  `views` bigint(20) DEFAULT 0,
  `rating` decimal(3,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comics_status` (`status`),
  KEY `idx_comics_created_at` (`created_at`),
  KEY `idx_comics_updated_at` (`updated_at`),
  KEY `idx_comics_views` (`views`),
  FULLTEXT KEY `idx_comics_search` (`title`, `author`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 4. BẢNG CHƯƠNG (CHAPTERS)
-- ================================================================
DROP TABLE IF EXISTS `chapters`;
CREATE TABLE `chapters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comic_id` int(11) NOT NULL,
  `chapter_number` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` longtext,
  `is_vip` tinyint(1) DEFAULT 0,
  `coin_cost` int(11) DEFAULT 0,
  `views` bigint(20) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_comic_chapter` (`comic_id`, `chapter_number`),
  KEY `idx_chapters_comic_vip` (`comic_id`, `is_vip`),
  KEY `idx_chapters_created_at` (`created_at`),
  KEY `idx_chapters_views` (`views`),
  FOREIGN KEY (`comic_id`) REFERENCES `comics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 5. BẢNG THEO DÕI (USER_FAVORITES)
-- ================================================================
DROP TABLE IF EXISTS `user_favorites`;
CREATE TABLE `user_favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `comic_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_comic` (`user_id`, `comic_id`),
  KEY `idx_user_favorites_user_comic` (`user_id`, `comic_id`),
  KEY `idx_user_favorites_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`comic_id`) REFERENCES `comics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 6. BẢNG LỊCH SỬ ĐỌC (READ_history)
-- ================================================================
DROP TABLE IF EXISTS `read_history`;
CREATE TABLE `read_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `comic_id` int(11) NOT NULL,
  `chapter_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_read_history_user_date` (`user_id`, `created_at`),
  KEY `idx_read_history_comic` (`comic_id`),
  KEY `idx_read_history_chapter` (`chapter_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`comic_id`) REFERENCES `comics`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`chapter_id`) REFERENCES `chapters`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 7. BẢNG BÌNH LUẬN (COMMENTS)
-- ================================================================
DROP TABLE IF EXISTS `comments`;
CREATE TABLE `comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `comic_id` int(11) DEFAULT NULL,
  `chapter_id` int(11) DEFAULT NULL,
  `content` text NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comments_comic_chapter` (`comic_id`, `chapter_id`),
  KEY `idx_comments_created_at` (`created_at`),
  KEY `idx_comments_user` (`user_id`),
  KEY `idx_comments_parent` (`parent_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`comic_id`) REFERENCES `comics`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`chapter_id`) REFERENCES `chapters`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_id`) REFERENCES `comments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 8. BẢNG XẾP HẠNG TRUYỆN (COMIC_RANKINGS)
-- ================================================================
DROP TABLE IF EXISTS `comic_rankings`;
CREATE TABLE `comic_rankings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comic_id` int(11) NOT NULL,
  `rank_type` enum('daily','weekly','monthly') NOT NULL,
  `rank_position` int(11) NOT NULL,
  `views_count` bigint(20) DEFAULT 0,
  `favorites_count` int(11) DEFAULT 0,
  `comments_count` int(11) DEFAULT 0,
  `score` decimal(15,2) DEFAULT 0.00,
  `ranking_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_comic_rank_date` (`comic_id`, `rank_type`, `ranking_date`),
  KEY `idx_comic_rankings_type_date` (`rank_type`, `ranking_date`),
  KEY `idx_comic_rankings_position` (`rank_position`),
  KEY `idx_comic_rankings_score` (`score`),
  FOREIGN KEY (`comic_id`) REFERENCES `comics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 9. BẢNG XẾP HẠNG NGƯỜI DÙNG (USER_RANKINGS)
-- ================================================================
DROP TABLE IF EXISTS `user_rankings`;
CREATE TABLE `user_rankings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `rank_type` enum('cao_thu','ty_phu') NOT NULL,
  `rank_position` int(11) NOT NULL,
  `score_value` bigint(20) DEFAULT 0,
  `ranking_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_rank_date` (`user_id`, `rank_type`, `ranking_date`),
  KEY `idx_user_rankings_type_date` (`rank_type`, `ranking_date`),
  KEY `idx_user_rankings_position` (`rank_position`),
  KEY `idx_user_rankings_score` (`score_value`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 10. BẢNG GIAO DỊCH XU (COIN_TRANSACTIONS)
-- ================================================================
DROP TABLE IF EXISTS `coin_transactions`;
CREATE TABLE `coin_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `amount` int(11) NOT NULL,
  `type` enum('earn','spend','purchase','gift') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coin_transactions_user` (`user_id`),
  KEY `idx_coin_transactions_type` (`type`),
  KEY `idx_coin_transactions_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- DỮ LIỆU MẪU (SAMPLE DATA)
-- ================================================================

-- Thêm người dùng mẫu
INSERT INTO `users` (`username`, `email`, `password`, `role`, `coins`, `realm`, `realm_stage`) VALUES
('admin', 'admin@truyen.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 10000, 'Độ Kiếp', 1),
('caothu1', 'caothu1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 8500, 'Đại Thừa', 9),
('caothu2', 'caothu2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 7200, 'Đại Thừa', 7),
('caothu3', 'caothu3@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 6800, 'Hợp Thể', 10),
('tyPhu1', 'typhu1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'vip', 15000, 'Luyện Hư', 5),
('tyPhu2', 'typhu2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'vip', 12500, 'Hóa Thần', 8),
('tyPhu3', 'typhu3@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'vip', 11200, 'Nguyên Anh', 9),
('user1', 'user1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 2500, 'Kết Đan', 6),
('user2', 'user2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 1800, 'Trúc Cơ', 8),
('user3', 'user3@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 1200, 'Luyện Khí', 10),
('translator1', 'trans1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'translator', 5000, 'Hóa Thần', 5),
('reader1', 'reader1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 800, 'Luyện Khí', 7),
('reader2', 'reader2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 600, 'Luyện Khí', 5),
('reader3', 'reader3@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 400, 'Luyện Khí', 3),
('reader4', 'reader4@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 300, 'Luyện Khí', 2);

-- Thêm tiến trình tu luyện
INSERT INTO `user_realm_progress` (`user_id`, `current_qi`, `qi_needed`, `total_qi_earned`, `breakthrough_count`) VALUES
(1, 0, 1000000, 5000000, 50),
(2, 850, 10000, 2500000, 45),
(3, 720, 9500, 2200000, 42),
(4, 680, 11000, 2000000, 40),
(5, 500, 8000, 1800000, 35),
(6, 800, 9000, 1600000, 32),
(7, 900, 8500, 1500000, 30),
(8, 300, 3500, 800000, 25),
(9, 450, 2800, 600000, 20),
(10, 100, 1500, 300000, 15),
(11, 650, 7000, 1200000, 28),
(12, 200, 1800, 250000, 12),
(13, 150, 1600, 180000, 10),
(14, 80, 1200, 120000, 8),
(15, 50, 1000, 80000, 5);

-- Thêm truyện mẫu
INSERT INTO `comics` (`title`, `author`, `description`, `thumbnail`, `status`, `genre`, `views`) VALUES
('Tu Tiên Nghịch Thiên', 'Mộng Huyền Cơ', 'Câu chuyện về một thiếu niên bình thường vô tình bước vào con đường tu tiên đầy gian khó. Từ một người phàm phu, anh ta dần dần khám phá ra những bí mật kinh hoàng của thế giới tu tiên và quyết tâm thay đổi vận mệnh của mình.', 'https://example.com/thumbnails/tu-tien-nghich-thien.jpg', 'ongoing', 'Tu Tiên, Hành Động, Phiêu Lưu', 150000),
('Ma Đế Trọng Sinh', 'Thiên Tằm Thổ Đậu', 'Ma Đế một đời từng thống trị ba nghìn thế giới, nhưng cuối cùng bị bạn bè phản bội và chết trong tuyệt vọng. Tưởng rằng mọi thứ đã kết thúc, không ngờ anh ta được trọng sinh về thời niên thiếu. Lần này, anh sẽ không để bi kịch lặp lại!', 'https://example.com/thumbnails/ma-de-trong-sinh.jpg', 'ongoing', 'Ma Pháp, Trọng Sinh, Báo Thù', 180000),
('Thiên Tài Bại Gia Tử', 'Phá Quân Tinh', 'Từng là thiên tài vô song của gia tộc, nhưng vì âm mưu của kẻ thù mà trở thành kẻ bại gia tử bị mọi người khinh thường. Tuy nhiên, may mắn giáng trần khi anh gặp được một vị sư phụ bí ẩn và bắt đầu hành trình luyện tập mạnh mẽ trở lại.', 'https://example.com/thumbnails/thien-tai-bai-gia-tu.jpg', 'ongoing', 'Tu Tiên, Gia Tộc, Phục Hận', 120000),
('Vạn Cổ Tối Cường Tông', 'Thái Nhất Sinh Thủy', 'Trong thế giới võ đạo, sức mạnh là tất cả. Chủ nhân công từ một đệ tử bình thường của một tông môn nhỏ bé, dần dần trở thành tông chủ của tông môn mạnh nhất vạn cổ. Hành trình đầy máu và nước mắt để xây dựng đế chế vô địch.', 'https://example.com/thumbnails/van-co-toi-cuong-tong.jpg', 'ongoing', 'Võ Đạo, Tông Môn, Quyền Mưu', 200000),
('Hoàng Đế Độc Tôn', 'Yến Hòa Phong Nguyệt', 'Một hoàng tử bị phế truất, bị mọi người coi thường và sỉ nhục. Nhưng số phận đã an bài cho anh ta gặp được một cuốn bí kíp cổ đại. Từ đây, anh bắt đầu hành trình khôi phục vinh quang hoàng gia và trở thành bá chủ thiên hạ.', 'https://example.com/thumbnails/hoang-de-doc-ton.jpg', 'completed', 'Hoàng Gia, Quyền Mưu, Tu Tiên', 250000),
('Tinh Thần Biến', 'Đường Gia Tam Thiếu', 'Trong một thế giới nơi con người có thể triệu hồi Tinh Thần để chiến đấu, chàng trai tên Đường Tam bị sinh ra với Tinh Thần phế vật Blue Silver Grass. Nhưng với ý chí kiên cường và sự giúp đỡ của thầy, anh đã trở thành huyền thoại.', 'https://example.com/thumbnails/tinh-than-bien.jpg', 'completed', 'Phiêu Lưu, Tình Bạn, Tu Luyện', 300000),
('Phá Thiên Một Kiếm', 'Mộng Nhập Thần Cơ', 'Kỹ thuật kiếm đạo tuyệt thế, một kiếm có thể phá vỡ cả thiên đường. Chủ nhân công từ một kiếm sư bình thường dần dần luyện thành tuyệt học kiếm đạo và trở thành kiếm thần vô địch thiên hạ.', 'https://example.com/thumbnails/pha-thien-mot-kiem.jpg', 'ongoing', 'Kiếm Đạo, Tu Tiên, Phiêu Lưu', 90000),
('Tuyệt Thế Vô Song', 'Cổ Vũ', 'Trong thế giới đầy nguy hiểm nơi yêu thú hung dữ và tu sĩ cường đại cùng tồn tại, một thiếu niên mang trong mình bí mật kinh thiên động địa. Anh ta sẽ như thế nào vượt qua những thử thách và trở thành cường giả tuyệt đỉnh?', 'https://example.com/thumbnails/tuyet-the-vo-song.jpg', 'ongoing', 'Tu Tiên, Yêu Thú, Mạo Hiểm', 75000),
('Cửu Tinh Bá Thể Quyết', 'Bình Phàm Mộng', 'Bí kíp thể chất cổ đại bị thất truyền từ thời thượng cổ. Chủ nhân công vô tình được truyền thừa và bắt đầu con đường tu luyện thể chất mạnh mẽ nhất, chinh phục tất cả bằng sức mạnh tuyệt đối của thể chất.', 'https://example.com/thumbnails/cuu-tinh-ba-the-quyet.jpg', 'ongoing', 'Thể Chất, Tu Luyện, Cường Giả', 110000),
('Thiên Địa Vô Dụng', 'Ngã Cật Tây Hồng Shi', 'Trong mắt mọi người, anh ta là kẻ vô dụng không thể tu luyện. Nhưng họ không biết rằng anh ta đang che giấu một bí mật lớn. Khi bí mật được hé lộ, cả thiên địa sẽ phải run sợ trước sức mạnh kinh hoàng của anh ta.', 'https://example.com/thumbnails/thien-dia-vo-dung.jpg', 'hiatus', 'Che Giấu, Tu Tiên, Phản Chuyển', 85000);

-- Thêm chương cho các truyện
INSERT INTO `chapters` (`comic_id`, `chapter_number`, `title`, `content`, `is_vip`, `coin_cost`, `views`) VALUES
-- Tu Tiên Nghịch Thiên (Comic ID: 1)
(1, 1, 'Khởi đầu hành trình', 'Chương 1: Lâm Đông, một thiếu niên bình thường 16 tuổi sống tại làng Thanh Phong...', 0, 0, 5000),
(1, 2, 'Gặp gỡ sư phụ', 'Chương 2: Trong rừng sâu, Lâm Đông gặp phải một ông lão lạ lùng...', 0, 0, 4800),
(1, 3, 'Bước đầu tu tiên', 'Chương 3: Ông lão truyền cho Lâm Đông tâm pháp tu tiên cơ bản...', 0, 0, 4500),
(1, 4, 'Thử thách đầu tiên', 'Chương 4: Để kiểm tra khả năng của đệ tử, sư phụ đưa ra thử thách...', 1, 10, 4200),
(1, 5, 'Đột phá Luyện Khí', 'Chương 5: Sau nhiều ngày khổ luyện, Lâm Đông đạt được cảnh giới Luyện Khí...', 1, 10, 4000),

-- Ma Đế Trọng Sinh (Comic ID: 2)
(2, 1, 'Cái chết của Ma Đế', 'Chương 1: Ma Đế Lý Thiên Vũ, một đời thống trị ba nghìn thế giới...', 0, 0, 6000),
(2, 2, 'Trọng sinh về quá khứ', 'Chương 2: Mở mắt ra, Lý Thiên Vũ phát hiện mình đã trở về tuổi 16...', 0, 0, 5800),
(2, 3, 'Ký ức của kiếp trước', 'Chương 3: Với toàn bộ ký ức của kiếp trước, Thiên Vũ bắt đầu kế hoạch...', 0, 0, 5500),
(2, 4, 'Báo thù bắt đầu', 'Chương 4: Những kẻ từng phản bội sẽ phải trả giá...', 1, 15, 5200),
(2, 5, 'Sức mạnh kinh hoàng', 'Chương 5: Thiên Vũ thể hiện sức mạnh khiến mọi người kinh ngạc...', 1, 15, 5000),

-- Thiên Tài Bại Gia Tử (Comic ID: 3)
(3, 1, 'Từ thiên tài thành phế vật', 'Chương 1: Diệp Thần từng là thiên tài số một của gia tộc...', 0, 0, 4000),
(3, 2, 'Bí mật của nhẫn cổ', 'Chương 2: Trong chiếc nhẫn cổ có chứa linh hồn của một cao nhân...', 0, 0, 3800),
(3, 3, 'Lấy lại thiên phú', 'Chương 3: Với sự giúp đỡ của tiền bối, Diệp Thần bắt đầu phục hồi...', 0, 0, 3600),
(3, 4, 'Đối đầu kẻ thù', 'Chương 4: Lần đầu tiên sau nhiều năm, Diệp Thần đối đầu với những kẻ đã hại mình...', 1, 12, 3400),

-- Vạn Cổ Tối Cường Tông (Comic ID: 4)
(4, 1, 'Đệ tử bình thường', 'Chương 1: Tại Thanh Vân Tông, Trương Vô Cực chỉ là một đệ tử bình thường...', 0, 0, 7000),
(4, 2, 'Cơ duyên bất ngờ', 'Chương 2: Trong một lần làm nhiệm vụ, Vô Cực tìm được bí kíp cổ đại...', 0, 0, 6800),
(4, 3, 'Tham vọng xây dựng tông môn', 'Chương 3: Vô Cực quyết tâm xây dựng tông môn mạnh nhất...', 0, 0, 6500),

-- Hoàng Đế Độc Tôn (Comic ID: 5)
(5, 1, 'Hoàng tử bị phế truất', 'Chương 1: Hoàng tử Cửu Hoàng tử bị phế truất vì không thể tu luyện...', 0, 0, 8000),
(5, 2, 'Bí kíp Hoàng Đế Kinh', 'Chương 2: Tình cờ tìm được Hoàng Đế Kinh, bí kíp tuyệt thế...', 0, 0, 7800),
(5, 3, 'Khôi phục sức mạnh', 'Chương 3: Cửu Hoàng tử bắt đầu lấy lại những gì thuộc về mình...', 0, 0, 7500);

-- Thêm theo dõi truyện
INSERT INTO `user_favorites` (`user_id`, `comic_id`, `created_at`) VALUES
(2, 1, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(2, 2, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(2, 4, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(3, 1, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(3, 3, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(3, 5, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(4, 2, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(4, 4, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(5, 1, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(5, 2, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(5, 3, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(6, 3, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(6, 4, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(6, 5, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(7, 1, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(7, 5, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(8, 1, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(8, 2, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(9, 3, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(10, 1, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(11, 2, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(11, 4, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(12, 1, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(13, 2, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(14, 3, NOW() - INTERVAL FLOOR(RAND()*30) DAY),
(15, 1, NOW() - INTERVAL FLOOR(RAND()*30) DAY);

-- Thêm lịch sử đọc
INSERT INTO `read_history` (`user_id`, `comic_id`, `chapter_id`, `created_at`) VALUES
(2, 1, 1, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(2, 1, 2, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(2, 1, 3, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(2, 2, 6, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(2, 2, 7, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(3, 1, 1, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(3, 1, 2, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(3, 3, 11, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(4, 2, 6, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(4, 4, 16, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(5, 1, 1, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(5, 2, 6, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(5, 3, 11, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(6, 3, 11, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(6, 4, 16, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(6, 5, 19, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(7, 1, 1, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(7, 5, 19, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(8, 1, 1, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(8, 2, 6, NOW() - INTERVAL RAND()*10) DAY),
(9, 3, 11, NOW() - INTERVAL FLOOR(RAND()*10) DAY),
(10, 1, 1, NOW() - INTERVAL FLOOR(RAND()*10) DAY);

-- Thêm bình luận
INSERT INTO `comments` (`user_id`, `comic_id`, `chapter_id`, `content`, `created_at`) VALUES
(2, 1, 1, 'Truyện hay quá! Mong tác giả cập nhật sớm', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(3, 1, 1, 'Chủ nhân công rất hay, có tính cách', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(4, 1, 2, 'Chương này hấp dẫn ghê!', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(5, 2, 6, 'Ma Đế quá ngầu, lần này sẽ không bị phản bội nữa', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(6, 2, 7, 'Cảnh báo thù đầu tiên rất thỏa mãn', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(7, 3, 11, 'Đợi xem Diệp Thần trả thù như thế nào', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(8, 4, 16, 'Xây dựng tông môn thật thú vị', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(9, 5, 19, 'Hoàng đế đã trở lại rồi!', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(10, 1, 3, 'Hệ thống tu tiên rất logic', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(11, 2, 8, 'Không thể ngừng đọc được', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(12, 3, 12, 'Mong có thêm chương vip', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(13, 1, 4, 'Chất lượng dịch rất tốt', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(14, 2, 9, 'Ma Đế này quá OP', NOW() - INTERVAL FLOOR(RAND()*5) DAY),
(15, 1, 5, 'Truyện hay nhất tôi từng đọc', NOW() - INTERVAL FLOOR(RAND()*5) DAY);

-- Thêm giao dịch xu
INSERT INTO `coin_transactions` (`user_id`, `amount`, `type`, `description`, `created_at`) VALUES
(2, 500, 'purchase', 'Nạp xu từ thẻ cào', NOW() - INTERVAL 10 DAY),
(2, -10, 'spend', 'Mua chương VIP', NOW() - INTERVAL 8 DAY),
(2, -15, 'spend', 'Mua chương VIP', NOW() - INTERVAL 6 DAY),
(3, 300, 'purchase', 'Nạp xu qua ví điện tử', NOW() - INTERVAL 12 DAY),
(3, -12, 'spend', 'Mua chương VIP', NOW() - INTERVAL 5 DAY),
(4, 200, 'earn', 'Thưởng đọc truyện hàng ngày', NOW() - INTERVAL 7 DAY),
(5, 1000, 'purchase', 'Nạp gói VIP tháng', NOW() - INTERVAL 15 DAY),
(5, -10, 'spend', 'Mua chương VIP', NOW() - INTERVAL 4 DAY),
(6, 800, 'purchase', 'Nạp xu từ banking', NOW() - INTERVAL 9 DAY),
(7, 50, 'earn', 'Thưởng check-in 7 ngày', NOW() - INTERVAL 3 DAY),
(8, 100, 'gift', 'Thưởng từ admin', NOW() - INTERVAL 2 DAY),
(9, 75, 'earn', 'Thưởng bình luận tích cực', NOW() - INTERVAL 1 DAY),
(10, 25, 'earn', 'Thưởng đăng ký mới', NOW());

-- ================================================================
-- INDEXES VÀ OPTIMIZATION
-- ================================================================

-- Thêm indexes để tối ưu hiệu suất
CREATE INDEX idx_comics_views_desc ON comics(views DESC);
CREATE INDEX idx_chapters_views_desc ON chapters(views DESC);
CREATE INDEX idx_user_favorites_created_recent ON user_favorites(created_at DESC);
CREATE INDEX idx_comments_created_recent ON comments(created_at DESC);
CREATE INDEX idx_read_history_recent ON read_history(created_at DESC);
CREATE INDEX idx_users_coins_desc ON users(coins DESC);
CREATE INDEX idx_user_realm_progress_qi ON user_realm_progress(total_qi_earned DESC);

-- ================================================================
-- VIEWS VÀ STORED PROCEDURES
-- ================================================================

-- View để lấy thống kê truyện nhanh
CREATE OR REPLACE VIEW comic_stats AS
SELECT 
    c.id,
    c.title,
    c.author,
    c.thumbnail,
    c.views,
    c.status,
    COUNT(DISTINCT ch.id) as chapter_count,
    COUNT(DISTINCT uf.id) as favorite_count,
    COUNT(DISTINCT cm.id) as comment_count,
    COALESCE(AVG(ch.views), 0) as avg_chapter_views
FROM comics c
LEFT JOIN chapters ch ON c.id = ch.comic_id
LEFT JOIN user_favorites uf ON c.id = uf.comic_id
LEFT JOIN comments cm ON c.id = cm.comic_id
GROUP BY c.id, c.title, c.author, c.thumbnail, c.views, c.status;

-- View để lấy top users
CREATE OR REPLACE VIEW top_users_stats AS
SELECT 
    u.id,
    u.username,
    u.realm,
    u.realm_stage,
    u.coins,
    urp.total_qi_earned,
    COUNT(DISTINCT uf.id) as favorite_count,
    COUNT(DISTINCT rh.id) as read_count,
    COUNT(DISTINCT cm.id) as comment_count,
    (CASE u.realm
        WHEN 'Luyện Khí' THEN 1
        WHEN 'Trúc Cơ' THEN 2
        WHEN 'Kết Đan' THEN 3
        WHEN 'Nguyên Anh' THEN 4
        WHEN 'Hóa Thần' THEN 5
        WHEN 'Luyện Hư' THEN 6
        WHEN 'Hợp Thể' THEN 7
        WHEN 'Đại Thừa' THEN 8
        WHEN 'Độ Kiếp' THEN 9
        ELSE 0
    END * 1000000 + u.realm_stage * 10000 + COALESCE(urp.total_qi_earned, 0)) as power_score
FROM users u
LEFT JOIN user_realm_progress urp ON u.id = urp.user_id
LEFT JOIN user_favorites uf ON u.id = uf.user_id
LEFT JOIN read_history rh ON u.id = rh.user_id
LEFT JOIN comments cm ON u.id = cm.user_id
WHERE u.role != 'admin'
GROUP BY u.id, u.username, u.realm, u.realm_stage, u.coins, urp.total_qi_earned;

-- ================================================================
-- KẾT THÚC SCRIPT
-- ================================================================

-- Hiển thị thông tin tổng quan
SELECT 'DATABASE SETUP COMPLETED SUCCESSFULLY!' as status;
SELECT COUNT(*) as total_users FROM users;
SELECT COUNT(*) as total_comics FROM comics;
SELECT COUNT(*) as total_chapters FROM chapters;
SELECT COUNT(*) as total_favorites FROM user_favorites;
SELECT COUNT(*) as total_comments FROM comments;

-- Hiển thị một số thống kê mẫu
SELECT 'TOP 5 POPULAR COMICS:' as info;
SELECT title, views, (SELECT COUNT(*) FROM user_favorites WHERE comic_id = comics.id) as favorites
FROM comics 
ORDER BY views DESC 
LIMIT 5;

SELECT 'TOP 5 POWER USERS:' as info;
SELECT username, realm, realm_stage, coins, total_qi_earned
FROM users u
LEFT JOIN user_realm_progress urp ON u.id = urp.user_id
WHERE u.role != 'admin'
ORDER BY (CASE u.realm
    WHEN 'Luyện Khí' THEN 1
    WHEN 'Trúc Cơ' THEN 2
    WHEN 'Kết Đan' THEN 3
    WHEN 'Nguyên Anh' THEN 4
    WHEN 'Hóa Thần' THEN 5
    WHEN 'Luyện Hư' THEN 6
    WHEN 'Hợp Thể' THEN 7
    WHEN 'Đại Thừa' THEN 8
    WHEN 'Độ Kiếp' THEN 9
    ELSE 0
END * 1000000 + u.realm_stage * 10000 + COALESCE(urp.total_qi_earned, 0)) DESC
LIMIT 5;
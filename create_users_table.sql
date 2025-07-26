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

-- Thêm indexes cho bảng users
ALTER TABLE `users` ADD INDEX `idx_username` (`username`);
ALTER TABLE `users` ADD INDEX `idx_email` (`email`);
ALTER TABLE `users` ADD INDEX `idx_role` (`role`);

-- Tạo tài khoản admin mẫu
INSERT INTO `users` (`username`, `email`, `password`, `role`, `coins`, `realm`, `realm_stage`) VALUES
('admin', 'admin@mangahub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 10000, 'Độ Kiếp', 1);
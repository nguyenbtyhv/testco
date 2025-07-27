<?php
session_start();

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'test';

try {
    $mysqli = new mysqli($host, $username, $password, $database);
    $mysqli->set_charset('utf8mb4');
    
    if ($mysqli->connect_error) {
        die("Kết nối database thất bại: " . $mysqli->connect_error);
    }
} catch (Exception $e) {
    die("Lỗi database: " . $e->getMessage());
}

// CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper functions
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function getUser($mysqli) {
    if (isset($_SESSION['user_id'])) {
        $user_id = (int)$_SESSION['user_id'];
        $result = $mysqli->query("SELECT * FROM users WHERE id = $user_id");
        return $result ? $result->fetch_assoc() : null;
    }
    return null;
}

function getTimeAgo($datetime) {
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'vừa xong';
    if ($time < 3600) return floor($time/60) . ' phút trước';
    if ($time < 86400) return floor($time/3600) . ' giờ trước';
    return floor($time/86400) . ' ngày trước';
}

function getUserRealmProgress($mysqli, $user_id) {
    $result = $mysqli->query("SELECT * FROM user_realm_progress WHERE user_id = $user_id");
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    // Create initial progress if not exists
    $mysqli->query("INSERT INTO user_realm_progress (user_id, qi_needed) VALUES ($user_id, 10)");
    return [
        'user_id' => $user_id,
        'current_qi' => 0,
        'qi_needed' => 10,
        'realm' => 'Luyện Khí',
        'realm_stage' => 1,
        'total_qi_earned' => 0
    ];
}

function addQi($mysqli, $user_id, $amount = 1) {
    $progress = getUserRealmProgress($mysqli, $user_id);
    $new_qi = $progress['current_qi'] + $amount;
    $new_total = $progress['total_qi_earned'] + $amount;
    
    // Check for realm advancement
    if ($new_qi >= $progress['qi_needed']) {
        $new_stage = $progress['realm_stage'] + 1;
        $new_realm = $progress['realm'];
        $new_qi_needed = $progress['qi_needed'];
        
        // Realm progression system - theo yêu cầu của bạn
        $realms = [
            'Luyện Khí' => 10,
            'Trúc Cơ' => 10, 
            'Kết Đan' => 10,
            'Nguyên Anh' => 10,
            'Hóa Thần' => 10,
            'Luyện Hư' => 10,
            'Hợp Thể' => 10,
            'Đại Thừa' => 10,
            'Độ Kiếp' => 1
        ];
        
        // Base qi requirements for each realm (increases exponentially)
        $base_qi_requirements = [
            'Luyện Khí' => 10,
            'Trúc Cơ' => 25, 
            'Kết Đan' => 50,
            'Nguyên Anh' => 100,
            'Hóa Thần' => 200,
            'Luyện Hư' => 400,
            'Hợp Thể' => 800,
            'Đại Thừa' => 1600,
            'Độ Kiếp' => 3200
        ];
        
        if ($new_stage > $realms[$new_realm] && $new_realm != 'Độ Kiếp') {
            $realm_keys = array_keys($realms);
            $current_index = array_search($new_realm, $realm_keys);
            if ($current_index !== false && $current_index < count($realm_keys) - 1) {
                $new_realm = $realm_keys[$current_index + 1];
                $new_stage = 1;
                $new_qi_needed = $base_qi_requirements[$new_realm]; // Set base requirement for new realm
            }
        } else {
            // Increase qi needed for next stage within same realm
            $base_qi = $base_qi_requirements[$new_realm];
            $new_qi_needed = floor($base_qi * (1 + ($new_stage - 1) * 0.2)); // 20% increase per stage
        }
        
        $new_qi = 0; // Reset qi after advancement
        
        $mysqli->query("UPDATE user_realm_progress SET 
                       current_qi = $new_qi, 
                       qi_needed = $new_qi_needed,
                       realm = '$new_realm', 
                       realm_stage = $new_stage,
                       total_qi_earned = $new_total 
                       WHERE user_id = $user_id");
        
        // Update user table as well
        $mysqli->query("UPDATE users SET realm = '$new_realm', realm_stage = $new_stage WHERE id = $user_id");
        
        return ['advanced' => true, 'new_realm' => $new_realm, 'new_stage' => $new_stage];
    } else {
        $mysqli->query("UPDATE user_realm_progress SET 
                       current_qi = $new_qi,
                       total_qi_earned = $new_total 
                       WHERE user_id = $user_id");
        
        return ['advanced' => false];
    }
}

function getUserRoleTag($role) {
    switch($role) {
        case 'admin': return '<span style="background: linear-gradient(45deg, #ff6b6b, #ee5a24); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: bold;">👑 ADMIN</span>';
        case 'translator': return '<span style="background: linear-gradient(45deg, #4834d4, #686de0); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: bold;">📚 NHÀ DỊCH</span>';
        default: return '';
    }
}

function getRealmBadge($realm, $stage) {
    $colors = [
        'Luyện Khí' => '#8e44ad',    // Tím nhạt - cảnh giới thấp nhất
        'Trúc Cơ' => '#3498db',     // Xanh dương - cảnh giới xây dựng nền tảng
        'Kết Đan' => '#f39c12',     // Vàng - cảnh giới kết tụ kim đan  
        'Nguyên Anh' => '#e74c3c',  // Đỏ - cảnh giới linh hồn
        'Hóa Thần' => '#9b59b6',    // Tím đậm - cảnh giới biến hóa
        'Luyện Hư' => '#34495e',    // Xám đen - cảnh giới hư vô
        'Hợp Thể' => '#16a085',     // Xanh lục - cảnh giới hợp nhất
        'Đại Thừa' => '#e67e22',    // Cam đậm - cảnh giới đại thành
        'Độ Kiếp' => '#ecf0f1'      // Trắng vàng - cảnh giới tối thượng
    ];
    
    $icons = [
        'Luyện Khí' => '🌱',
        'Trúc Cơ' => '🏗️', 
        'Kết Đan' => '💊',
        'Nguyên Anh' => '👻',
        'Hóa Thần' => '🔮',
        'Luyện Hư' => '🌌',
        'Hợp Thể' => '⚡',
        'Đại Thừa' => '🌟',
        'Độ Kiếp' => '⚡'
    ];
    
    $color = $colors[$realm] ?? '#95a5a6';
    $icon = $icons[$realm] ?? '⚡';
    
    // Special styling for highest realm
    if ($realm == 'Độ Kiếp') {
        return '<span style="background: linear-gradient(45deg, #f1c40f, #e67e22); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: bold; box-shadow: 0 0 10px rgba(241, 196, 15, 0.5);">' . $icon . ' ' . $realm . ' ' . $stage . '</span>';
    }
    
    return '<span style="background: ' . $color . '; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: bold;">' . $icon . ' ' . $realm . ' ' . $stage . '</span>';
}

function getEnhancedRealmBadge($realm, $stage) {
    $colors = [
        'Luyện Khí' => ['#8e44ad', '#9b59b6'],
        'Trúc Cơ' => ['#3498db', '#5dade2'],
        'Kết Đan' => ['#f39c12', '#f4d03f'],
        'Nguyên Anh' => ['#e74c3c', '#ec7063'],
        'Hóa Thần' => ['#9b59b6', '#bb8fce'],
        'Luyện Hư' => ['#34495e', '#5d6d7e'],
        'Hợp Thể' => ['#16a085', '#48c9b0'],
        'Đại Thừa' => ['#e67e22', '#f0b27a'],
        'Độ Kiếp' => ['#f1c40f', '#e67e22']
    ];
    
    $icons = [
        'Luyện Khí' => '🌱',
        'Trúc Cơ' => '🏗️', 
        'Kết Đan' => '💊',
        'Nguyên Anh' => '👻',
        'Hóa Thần' => '🔮',
        'Luyện Hư' => '🌌',
        'Hợp Thể' => '⚡',
        'Đại Thừa' => '🌟',
        'Độ Kiếp' => '⚡'
    ];
    
    $color = $colors[$realm] ?? ['#95a5a6', '#bdc3c7'];
    $icon = $icons[$realm] ?? '⚡';
    
    // Special styling for highest realm with animation
    if ($realm == 'Độ Kiếp') {
        return '<span style="background: linear-gradient(45deg, #f1c40f, #e67e22); color: white; padding: 3px 10px; border-radius: 15px; font-size: 0.8em; font-weight: bold; box-shadow: 0 0 15px rgba(241, 196, 15, 0.6); animation: glow 2s ease-in-out infinite alternate; display: inline-flex; align-items: center; gap: 0.3rem;">' . $icon . ' ' . $realm . ' ' . $stage . '</span>';
    }
    
    return '<span style="background: linear-gradient(45deg, ' . $color[0] . ', ' . $color[1] . '); color: white; padding: 3px 10px; border-radius: 15px; font-size: 0.8em; font-weight: bold; box-shadow: 0 2px 8px rgba(0,0,0,0.2); display: inline-flex; align-items: center; gap: 0.3rem; transition: all 0.3s ease;" onmouseover="this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 4px 12px rgba(0,0,0,0.3)\';" onmouseout="this.style.transform=\'scale(1)\'; this.style.boxShadow=\'0 2px 8px rgba(0,0,0,0.2)\';">' . $icon . ' ' . $realm . ' ' . $stage . '</span>';
}

function generateUserAvatar($username, $realm, $realm_stage) {
    // Generate color based on username
    $colors = [
        '#e74c3c', '#3498db', '#9b59b6', '#f39c12', '#2ecc71',
        '#e67e22', '#1abc9c', '#34495e', '#f1c40f', '#e91e63'
    ];
    
    $color_index = abs(crc32($username)) % count($colors);
    $bg_color = $colors[$color_index];
    
    // Get realm-based border color
    $realm_colors = [
        'Luyện Khí' => '#8e44ad',
        'Trúc Cơ' => '#3498db',
        'Kết Đan' => '#f39c12',
        'Nguyên Anh' => '#e74c3c',
        'Hóa Thần' => '#9b59b6',
        'Luyện Hư' => '#34495e',
        'Hợp Thể' => '#16a085',
        'Đại Thừa' => '#e67e22',
        'Độ Kiếp' => '#f1c40f'
    ];
    
    $border_color = $realm_colors[$realm] ?? '#95a5a6';
    $initial = strtoupper(mb_substr($username, 0, 1));
    
    // Special effects for high-level realms
    $special_effects = '';
    if (in_array($realm, ['Đại Thừa', 'Độ Kiếp'])) {
        $special_effects = 'box-shadow: 0 0 20px rgba(241, 196, 15, 0.5); animation: pulse 2s ease-in-out infinite alternate;';
    }
    
    return '<div style="width: 45px; height: 45px; border-radius: 50%; background: ' . $bg_color . '; border: 3px solid ' . $border_color . '; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.2em; ' . $special_effects . ' transition: all 0.3s ease;" onmouseover="this.style.transform=\'scale(1.1)\';" onmouseout="this.style.transform=\'scale(1)\';">' . $initial . '</div>';
}

function getUsernameStyle($role, $realm) {
    $base_style = 'font-weight: bold; font-size: 1.1em; transition: all 0.3s ease;';
    
    // Role-based colors
    $role_colors = [
        'admin' => 'background: linear-gradient(45deg, #e74c3c, #c0392b); -webkit-background-clip: text; -webkit-text-fill-color: transparent; text-shadow: 0 0 10px rgba(231, 76, 60, 0.5);',
        'translator' => 'background: linear-gradient(45deg, #3498db, #2980b9); -webkit-background-clip: text; -webkit-text-fill-color: transparent; text-shadow: 0 0 10px rgba(52, 152, 219, 0.5);',
        'vip' => 'background: linear-gradient(45deg, #f39c12, #e67e22); -webkit-background-clip: text; -webkit-text-fill-color: transparent; text-shadow: 0 0 10px rgba(243, 156, 18, 0.5);',
        'user' => 'color: rgba(255, 255, 255, 0.9);'
    ];
    
    // Realm-based enhancements
    $realm_enhancements = '';
    if (in_array($realm, ['Đại Thừa', 'Độ Kiếp'])) {
        $realm_enhancements = ' text-shadow: 0 0 15px rgba(241, 196, 15, 0.7);';
    }
    
    return $base_style . ' ' . ($role_colors[$role] ?? $role_colors['user']) . $realm_enhancements;
}

function displayComments($mysqli, $user, $comic_id = null, $chapter_id = null, $parent_id = null, $level = 0) {
    $where_clause = "WHERE parent_id " . ($parent_id ? "= $parent_id" : "IS NULL");
    if ($comic_id) $where_clause .= " AND comic_id = $comic_id";
    if ($chapter_id) $where_clause .= " AND chapter_id = $chapter_id";
    
    // Enhanced query to get chapter info when available
    $chapter_join = $chapter_id ? "LEFT JOIN chapters ch ON ch.id = c.chapter_id" : "";
    $chapter_select = $chapter_id ? ", ch.chapter_title" : ", NULL as chapter_title";
    
    $comments = $mysqli->query("
        SELECT c.*, u.username, u.role, u.realm, u.realm_stage,
               (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) as like_count,
               " . ($user ? "(SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id AND cl.user_id = {$user['id']}) as user_liked" : "0 as user_liked") . "
               $chapter_select
        FROM comments c 
        JOIN users u ON u.id = c.user_id 
        $chapter_join
        $where_clause
        ORDER BY c.is_pinned DESC, c.created_at DESC
    ");
    
    if (!$comments || $comments->num_rows == 0) {
        return;
    }
    
    $indent = $level * 20;
    
    while ($comment = $comments->fetch_assoc()) {
        $can_pin = $user && in_array($user['role'], ['admin']);
        $pin_text = $comment['is_pinned'] ? 'Bỏ ghim' : 'Ghim';
        $pin_icon = $comment['is_pinned'] ? '📌' : '';
        
        // Generate user avatar based on username
        $avatar_color = generateAvatarColor($comment['username']);
        $avatar_initial = strtoupper(mb_substr($comment['username'], 0, 1));
        
        // Enhanced username styling with animation effects
        $username_style = getUsernameStyle($comment['role'], $comment['realm']);
        
        // Chapter info display
        $chapter_info = '';
        if ($comment['chapter_title'] && $comment['chapter_id']) {
            $chapter_info = '<div class="chapter-info" style="padding: 0.8rem; margin-bottom: 0.8rem; border-radius: 8px; font-size: 0.85em;">
                                <span style="color: #3498db; font-weight: bold; display: inline-flex; align-items: center; gap: 0.3rem;">
                                    <span style="font-size: 1.2em;">📖</span> Chapter ' . $comment['chapter_id'] . '
                                </span>
                                <span style="color: rgba(255,255,255,0.9); margin-left: 0.5rem; font-style: italic;">' . sanitize($comment['chapter_title']) . '</span>
                             </div>';
        }
        
        echo '<div class="comment-container" style="margin-left: ' . $indent . 'px; border-left: ' . ($level > 0 ? '2px solid rgba(255,255,255,0.2)' : 'none') . '; padding-left: ' . ($level > 0 ? '15px' : '0') . '; margin-bottom: 1.5rem;">
                <div style="background: rgba(255,255,255,' . ($comment['is_pinned'] ? '0.15' : '0.08') . '); padding: 1.2rem; border-radius: 15px; border: ' . ($comment['is_pinned'] ? '2px solid #f1c40f' : '1px solid rgba(255,255,255,0.15)') . '; box-shadow: 0 4px 15px rgba(0,0,0,0.1); transition: all 0.3s ease;" class="interactive-element">
                    ' . $chapter_info . '
                    <div style="display: flex; align-items: flex-start; gap: 1rem;">
                        <div style="flex-shrink: 0;">
                            ' . generateUserAvatar($comment['username'], $comment['realm'], $comment['realm_stage']) . '
                        </div>
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.8rem; flex-wrap: wrap;">
                                <span style="' . $username_style . '">' . sanitize($comment['username']) . '</span>
                                ' . getUserRoleTag($comment['role']) . '
                                ' . getEnhancedRealmBadge($comment['realm'], $comment['realm_stage']) . '
                                ' . $pin_icon . '
                                <span style="color: rgba(255,255,255,0.6); font-size: 0.8em; margin-left: auto;">' . getTimeAgo($comment['created_at']) . '</span>
                            </div>
                            <div style="margin-bottom: 1rem; line-height: 1.6; color: rgba(255,255,255,0.9);">
                                ' . nl2br(sanitize($comment['content'])) . '
                            </div>
                            <div style="display: flex; gap: 1.5rem; align-items: center;">
                                ' . ($user ? '<a href="?like_comment=' . $comment['id'] . '" style="color: ' . ($comment['user_liked'] ? '#e74c3c' : 'rgba(255,255,255,0.7)') . '; text-decoration: none; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.3rem;" onmouseover="this.style.color=\'#e74c3c\'; this.style.transform=\'scale(1.1)\';" onmouseout="this.style.color=\'' . ($comment['user_liked'] ? '#e74c3c' : 'rgba(255,255,255,0.7)') . '\'; this.style.transform=\'scale(1)\';"><span style="font-size: 1.1em;">❤️</span> ' . $comment['like_count'] . '</a>' : '<span style="color: rgba(255,255,255,0.7); display: flex; align-items: center; gap: 0.3rem;"><span style="font-size: 1.1em;">❤️</span> ' . $comment['like_count'] . '</span>') . '
                                ' . ($user ? '<a href="#" onclick="toggleReplyForm(' . $comment['id'] . ')" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.3rem;" onmouseover="this.style.color=\'#3498db\'; this.style.transform=\'scale(1.1)\';" onmouseout="this.style.color=\'rgba(255,255,255,0.7)\'; this.style.transform=\'scale(1)\';"><span style="font-size: 1.1em;">💬</span> Trả lời</a>' : '') . '
                                ' . ($can_pin ? '<a href="?toggle_pin=' . $comment['id'] . '" style="color: rgba(255,255,255,0.7); text-decoration: none; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.3rem;" onmouseover="this.style.color=\'#f1c40f\'; this.style.transform=\'scale(1.1)\';" onmouseout="this.style.color=\'rgba(255,255,255,0.7)\'; this.style.transform=\'scale(1)\';"><span style="font-size: 1.1em;">📌</span> ' . $pin_text . '</a>' : '') . '
                            </div>
                        </div>
                    </div>
                </div>';
        
        // Reply form
        if ($user) {
            echo '<div id="reply-form-' . $comment['id'] . '" style="display: none; margin-top: 1rem; margin-left: 20px;">
                    <form method="POST" style="background: rgba(255,255,255,0.05); padding: 1rem; border-radius: 10px;">
                        <input type="hidden" name="parent_id" value="' . $comment['id'] . '">
                        ' . ($comic_id ? '<input type="hidden" name="comic_id" value="' . $comic_id . '">' : '') . '
                        ' . ($chapter_id ? '<input type="hidden" name="chapter_id" value="' . $chapter_id . '">' : '') . '
                        <textarea name="content" class="form-input" placeholder="Viết trả lời..." rows="3" required style="margin-bottom: 0.5rem;"></textarea>
                        <div style="text-align: right;">
                            <button type="button" onclick="toggleReplyForm(' . $comment['id'] . ')" class="btn btn-outline" style="margin-right: 0.5rem;">Hủy</button>
                            <button type="submit" name="add_comment" class="btn btn-primary">Trả lời</button>
                        </div>
                    </form>
                  </div>';
        }
        
        // Display nested replies
        displayComments($mysqli, $user, $comic_id, $chapter_id, $comment['id'], $level + 1);
        
        echo '</div>';
    }
}

// Get current user
$user = getUser($mysqli);
$page = $_GET['page'] ?? 'home';

// Handle like comment
if ($user && isset($_GET['like_comment'])) {
    $comment_id = (int)$_GET['like_comment'];
    $user_id = $user['id'];
    
    $check = $mysqli->query("SELECT id FROM comment_likes WHERE comment_id = $comment_id AND user_id = $user_id");
    if ($check && $check->num_rows > 0) {
        $mysqli->query("DELETE FROM comment_likes WHERE comment_id = $comment_id AND user_id = $user_id");
    } else {
        $mysqli->query("INSERT INTO comment_likes (comment_id, user_id) VALUES ($comment_id, $user_id)");
    }
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// Handle add comment
if ($user && $_POST && isset($_POST['add_comment'])) {
    $content = sanitize($_POST['content']);
    $comic_id = isset($_POST['comic_id']) ? (int)$_POST['comic_id'] : null;
    $chapter_id = isset($_POST['chapter_id']) ? (int)$_POST['chapter_id'] : null;
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    
    if (!empty($content) && ($comic_id || $chapter_id)) {
        $stmt = $mysqli->prepare("INSERT INTO comments (user_id, comic_id, chapter_id, parent_id, content) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiis", $user['id'], $comic_id, $chapter_id, $parent_id, $content);
        
        if ($stmt->execute()) {
            $_SESSION['notification'] = ['type' => 'success', 'message' => 'Đã thêm bình luận thành công!'];
        } else {
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Có lỗi khi thêm bình luận!'];
        }
    }
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// Handle pin/unpin comment (admin only)
if ($user && in_array($user['role'], ['admin']) && isset($_GET['toggle_pin'])) {
    $comment_id = (int)$_GET['toggle_pin'];
    $current_pin = $mysqli->query("SELECT is_pinned FROM comments WHERE id = $comment_id")->fetch_assoc();
    $new_pin = $current_pin['is_pinned'] ? 0 : 1;
    $mysqli->query("UPDATE comments SET is_pinned = $new_pin WHERE id = $comment_id");
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// Create tables if not exist
$mysqli->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'translator', 'admin') DEFAULT 'user',
    coins INT DEFAULT 100,
    realm VARCHAR(50) DEFAULT 'Luyện Khí',
    realm_stage INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS comics (
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
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS chapters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comic_id INT NOT NULL,
    chapter_title VARCHAR(200) NOT NULL,
    images TEXT,
    is_vip BOOLEAN DEFAULT FALSE,
    coins_unlock INT DEFAULT 0,
    views INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS user_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comic_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favorite (user_id, comic_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS comic_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comic_id INT NOT NULL,
    rating INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rating (user_id, comic_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS read_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chapter_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_read (user_id, chapter_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS chapter_unlocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chapter_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_unlock (user_id, chapter_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comic_id INT,
    chapter_id INT,
    parent_id INT DEFAULT NULL,
    content TEXT NOT NULL,
    is_pinned BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_comic_chapter (comic_id, chapter_id),
    INDEX idx_parent (parent_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS comment_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    comment_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_like (user_id, comment_id)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS user_realm_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    current_qi INT DEFAULT 0,
    qi_needed INT DEFAULT 10,
    realm VARCHAR(50) DEFAULT 'Luyện Khí',
    realm_stage INT DEFAULT 1,
    total_qi_earned INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id)
)");

// Create default admin user if not exists
$admin_check = $mysqli->query("SELECT id FROM users WHERE username = 'admin'");
if (!$admin_check || $admin_check->num_rows == 0) {
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    $mysqli->query("INSERT INTO users (username, email, password, role, coins, realm, realm_stage) 
                   VALUES ('admin', 'admin@manga.com', '$admin_password', 'admin', 10000, 'Độ Kiếp', 1)");
}

// Initialize realm progress for existing users
$users_without_progress = $mysqli->query("
    SELECT u.id FROM users u 
    LEFT JOIN user_realm_progress urp ON u.id = urp.user_id 
    WHERE urp.user_id IS NULL
");
if ($users_without_progress && $users_without_progress->num_rows > 0) {
    while ($user_row = $users_without_progress->fetch_assoc()) {
        $mysqli->query("INSERT INTO user_realm_progress (user_id, realm, realm_stage, qi_needed) 
                       VALUES ({$user_row['id']}, 'Luyện Khí', 1, 10)");
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MangaHub - Website Đọc Truyện Tranh</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            color: #1a202c;
            min-height: 100vh;
            line-height: 1.6;
        }
        
        .header {
            background: #ffffff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: 800;
            color: #2d3748;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .logo:hover {
            color: #3182ce;
        }
        
        .nav-menu {
            display: flex;
            list-style: none;
            gap: 1.5rem;
            margin: 0;
            padding: 0;
        }
        
        .nav-link {
            color: #4a5568;
            text-decoration: none;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .nav-link:hover {
            color: #3182ce;
            background: #ebf8ff;
        }
        
        .nav-link.active {
            color: #3182ce;
            background: #ebf8ff;
            font-weight: 600;
        }
        
        .user-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-area span {
            color: #4a5568;
            font-weight: 500;
        }
        
        .btn {
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
            font-size: 0.875rem;
        }
        
        .btn-primary {
            background: #3182ce;
            color: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .btn-primary:hover {
            background: #2c5282;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .btn-outline {
            background: transparent;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }
        
        .btn-outline:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        
        .btn-outline {
            background: transparent;
            color: #fff;
            border: 2px solid #fff;
        }
        
        .btn-outline:hover {
            background: #fff;
            color: #1e3c72;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 2rem;
            color: #2d3748;
            position: relative;
            padding-bottom: 0.5rem;
        }
        
        .page-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 3px;
            background: #3182ce;
            border-radius: 2px;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #2d3748;
            position: relative;
            padding-left: 1rem;
        }
        
        .section-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background: #3182ce;
            border-radius: 2px;
        }
        
        .comic-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        
        .comic-card {
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .comic-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .comic-thumbnail {
            width: 100%;
            height: 240px;
            position: relative;
            overflow: hidden;
        }
        
        .comic-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .comic-card:hover .comic-thumbnail img {
            transform: scale(1.05);
        }
        
        .comic-info {
            padding: 1rem;
        }
        
        .comic-title {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #2d3748;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .comic-meta {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            font-size: 0.8rem;
            color: #718096;
        }
        
        .comic-meta span {
            display: block;
        }
        
        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            color: #718096;
        }
        
        .breadcrumb a {
            color: #3182ce;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        .breadcrumb-separator {
            color: #cbd5e0;
        }
        
        /* Search Bar */
        .search-container {
            max-width: 400px;
            margin: 0 auto 2rem;
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 25px;
            background: #ffffff;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #3182ce;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            width: 18px;
            height: 18px;
        }
        
        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
        }
        
        .filter-tab {
            padding: 0.5rem 1rem;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #4a5568;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .filter-tab:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }
        
        .filter-tab.active {
            background: #3182ce;
            color: white;
            border-color: #3182ce;
        }
        
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-tabs {
                justify-content: center;
                flex-wrap: wrap;
            }
        }
        
        .form-container {
            max-width: 400px;
            margin: 2rem auto;
            background: #ffffff;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #2d3748;
            font-size: 1rem;
            transition: border-color 0.2s ease;
            box-sizing: border-box;
        }
        
        .form-input::placeholder {
            color: #a0aec0;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3182ce;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        
        .notification {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-weight: 500;
        }
        
        .notification.success {
            background: #f0fff4;
            border-color: #38a169;
            color: #2f855a;
        }
        
        .notification.error {
            background: #fed7d7;
            border-color: #e53e3e;
            color: #c53030;
        }
        
        .notification.warning {
            background: #fefcbf;
            border-color: #d69e2e;
            color: #b7791f;
        }
        
        .chapter-list {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }
        
        .chapter-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: background 0.3s ease;
        }
        
        .chapter-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .chapter-link {
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            flex: 1;
        }
        
        .chapter-link:hover {
            color: #4ecdc4;
        }
        
        .admin-section {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .admin-nav {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            border-radius: 10px;
            color: #fff;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab-btn.active {
            background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
        }
        
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }
            
            .nav-menu {
                gap: 1rem;
            }
            
            .container {
                padding: 1rem;
            }
            
            .comic-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .page-title {
                font-size: 2rem;
            }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .navbar {
                padding: 0 1rem;
                flex-wrap: wrap;
            }
            
            .nav-menu {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: #ffffff;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                flex-direction: column;
                padding: 1rem;
                gap: 0.5rem;
            }
            
            .nav-menu.active {
                display: flex;
            }
            
            .container {
                padding: 1rem;
            }
            
            .comic-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 1rem;
            }
            
            .comic-thumbnail {
                height: 200px;
            }
            
            .page-title {
                font-size: 1.5rem;
                text-align: center;
            }
        }
        
        @media (max-width: 480px) {
            .comic-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 0.75rem;
            }
            
            .comic-thumbnail {
                height: 180px;
            }
            
            .comic-info {
                padding: 0.75rem;
            }
            
            .comic-title {
                font-size: 0.85rem;
            }
        }

        /* Enhanced Comment Animations */
        @keyframes glow {
            0% { box-shadow: 0 0 15px rgba(241, 196, 15, 0.6); }
            100% { box-shadow: 0 0 25px rgba(241, 196, 15, 0.9), 0 0 35px rgba(241, 196, 15, 0.4); }
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 20px rgba(241, 196, 15, 0.5); }
            100% { box-shadow: 0 0 30px rgba(241, 196, 15, 0.8), 0 0 40px rgba(241, 196, 15, 0.3); }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Comment container animations */
        .comment-container {
            animation: fadeInUp 0.5s ease-out;
        }
        
        /* Enhanced gradient text for special users */
        .gradient-text {
            background: linear-gradient(45deg, #f1c40f, #e67e22, #e74c3c);
            background-size: 200% 200%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: gradientShift 3s ease-in-out infinite;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        /* Hover effects for interactive elements */
        .interactive-element {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .interactive-element:hover {
            transform: translateY(-2px);
        }
        
        /* Chapter info styling */
        .chapter-info {
            background: rgba(52, 152, 219, 0.15);
            border-left: 3px solid #3498db;
            backdrop-filter: blur(5px);
            transition: all 0.3s ease;
        }
        
        .chapter-info:hover {
            background: rgba(52, 152, 219, 0.25);
            border-left-width: 5px;
        }
    </style>
</head>
<body>
    <header class="header">
        <nav class="navbar">
            <a href="?page=home" class="logo">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
                MangaHub
            </a>
            <ul class="nav-menu">
                <li><a href="?page=home" class="nav-link <?php echo $page == 'home' ? 'active' : ''; ?>">Trang chủ</a></li>
                <li><a href="?page=favorites" class="nav-link <?php echo $page == 'favorites' ? 'active' : ''; ?>">Theo dõi</a></li>
                <li><a href="?page=history" class="nav-link <?php echo $page == 'history' ? 'active' : ''; ?>">Lịch sử</a></li>
                <?php if ($user && in_array($user['role'], ['admin', 'translator'])): ?>
                <li><a href="?page=admin" class="nav-link <?php echo $page == 'admin' ? 'active' : ''; ?>">Quản trị</a></li>
                <?php endif; ?>
            </ul>
            <div class="user-area">
                <?php if ($user): ?>
                    <span>Xin chào, <?php echo sanitize($user['username']); ?>!</span>
                    <a href="?page=profile" class="btn btn-outline">Tài khoản</a>
                    <a href="?page=logout" class="btn btn-primary">Đăng xuất</a>
                <?php else: ?>
                    <a href="?page=login" class="btn btn-outline">Đăng nhập</a>
                    <a href="?page=register" class="btn btn-primary">Đăng ký</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main class="container">
        <?php
        // Handle different pages
        switch ($page) {
            case 'home':
                echo '<div class="search-container">
                        <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" class="search-input" placeholder="Tìm kiếm truyện tranh...">
                      </div>';
                echo '<div class="page-header">
                        <h1 class="page-title">Truyện Mới Nhất</h1>
                        <div class="filter-tabs">
                            <button class="filter-tab active">Tất cả</button>
                            <button class="filter-tab">Mới cập nhật</button>
                            <button class="filter-tab">Xem nhiều</button>
                            <button class="filter-tab">Hoàn thành</button>
                        </div>
                      </div>';
                echo '<div class="comic-grid">';
                
                $comics = $mysqli->query("SELECT * FROM comics ORDER BY updated_at DESC LIMIT 20");
                if ($comics && $comics->num_rows > 0) {
                    while ($comic = $comics->fetch_assoc()) {
                        $thumbnail = $comic['thumbnail'] ?: 'https://via.placeholder.com/200x250?text=No+Image';
                        echo '<div class="comic-card">
                                <a href="?page=comic&id=' . $comic['id'] . '">
                                    <div class="comic-thumbnail">
                                        <img src="' . sanitize($thumbnail) . '" alt="' . sanitize($comic['title']) . '">
                                    </div>
                                    <div class="comic-info">
                                        <h3 class="comic-title">' . sanitize($comic['title']) . '</h3>
                                        <div class="comic-meta">
                                            <span>' . sanitize($comic['author'] ?: 'Chưa rõ') . '</span>
                                            <span>' . number_format($comic['views']) . ' lượt xem</span>
                                        </div>
                                    </div>
                                </a>
                              </div>';
                    }
                } else {
                    echo '<div class="notification warning">Chưa có truyện nào được đăng.</div>';
                }
                echo '</div>';
                break;

            case 'login':
                if ($_POST) {
                    $username = sanitize($_POST['username']);
                    $password = $_POST['password'];
                    
                    $stmt = $mysqli->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
                    $stmt->bind_param("ss", $username, $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result && $result->num_rows > 0) {
                        $user_data = $result->fetch_assoc();
                        if (password_verify($password, $user_data['password'])) {
                            $_SESSION['user_id'] = $user_data['id'];
                            echo '<script>window.location.href = "?page=home";</script>';
                            exit;
                        }
                    }
                    echo '<div class="notification error">Tên đăng nhập hoặc mật khẩu không đúng!</div>';
                }
                
                echo '<div class="form-container">
                        <h2 class="section-title">Đăng Nhập</h2>
                        <form method="POST">
                            <div class="form-group">
                                <input name="username" type="text" class="form-input" placeholder="Tên đăng nhập hoặc email" required>
                            </div>
                            <div class="form-group">
                                <input name="password" type="password" class="form-input" placeholder="Mật khẩu" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Đăng Nhập</button>
                        </form>
                      </div>';
                break;

            case 'register':
                if ($_POST) {
                    $username = sanitize($_POST['username']);
                    $email = sanitize($_POST['email']);
                    $password = $_POST['password'];
                    
                    if (strlen($username) < 3) {
                        echo '<div class="notification error">Tên đăng nhập phải có ít nhất 3 ký tự!</div>';
                    } elseif (strlen($password) < 6) {
                        echo '<div class="notification error">Mật khẩu phải có ít nhất 6 ký tự!</div>';
                    } else {
                        $check = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                        $check->bind_param("ss", $username, $email);
                        $check->execute();
                        
                        if ($check->get_result()->num_rows > 0) {
                            echo '<div class="notification error">Tên đăng nhập hoặc email đã tồn tại!</div>';
                        } else {
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $mysqli->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                            $stmt->bind_param("sss", $username, $email, $hashed_password);
                            
                            if ($stmt->execute()) {
                                echo '<div class="notification success">Đăng ký thành công! Hãy đăng nhập.</div>';
                                echo '<script>setTimeout(() => window.location.href = "?page=login", 2000);</script>';
                            } else {
                                echo '<div class="notification error">Có lỗi xảy ra khi đăng ký!</div>';
                            }
                        }
                    }
                }
                
                echo '<div class="form-container">
                        <h2 class="section-title">Đăng Ký</h2>
                        <form method="POST">
                            <div class="form-group">
                                <input name="username" type="text" class="form-input" placeholder="Tên đăng nhập" required>
                            </div>
                            <div class="form-group">
                                <input name="email" type="email" class="form-input" placeholder="Email" required>
                            </div>
                            <div class="form-group">
                                <input name="password" type="password" class="form-input" placeholder="Mật khẩu" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Đăng Ký</button>
                        </form>
                      </div>';
                break;

            case 'logout':
                session_destroy();
                echo '<script>window.location.href = "?page=home";</script>';
                exit;

            case 'comic':
                $comic_id = (int)($_GET['id'] ?? 0);
                if ($comic_id <= 0) {
                    echo '<div class="notification error">Truyện không tồn tại!</div>';
                    break;
                }
                
                $comic = $mysqli->query("SELECT * FROM comics WHERE id = $comic_id")->fetch_assoc();
                if (!$comic) {
                    echo '<div class="notification error">Không tìm thấy truyện!</div>';
                    break;
                }
                
                // Update view count
                $mysqli->query("UPDATE comics SET views = views + 1 WHERE id = $comic_id");
                
                echo '<h1 class="page-title">' . sanitize($comic['title']) . '</h1>';
                echo '<div style="display: flex; gap: 2rem; margin-bottom: 2rem;">
                        <img src="' . sanitize($comic['thumbnail'] ?: 'https://via.placeholder.com/300x400') . '" 
                             style="width: 300px; height: 400px; object-fit: cover; border-radius: 15px;">
                        <div>
                            <p><strong>Tác giả:</strong> ' . sanitize($comic['author'] ?: 'Chưa rõ') . '</p>
                            <p><strong>Trạng thái:</strong> ' . sanitize($comic['status']) . '</p>
                            <p><strong>Lượt xem:</strong> ' . number_format($comic['views']) . '</p>
                            <p><strong>Theo dõi:</strong> ' . number_format($comic['follows']) . '</p>
                            <p><strong>Mô tả:</strong></p>
                            <p>' . nl2br(sanitize($comic['description'] ?: 'Chưa có mô tả')) . '</p>
                        </div>
                      </div>';
                
                echo '<h2 class="section-title">Danh Sách Chương</h2>';
                $chapters = $mysqli->query("SELECT * FROM chapters WHERE comic_id = $comic_id ORDER BY id ASC");
                if ($chapters && $chapters->num_rows > 0) {
                    echo '<div class="chapter-list">';
                    while ($chapter = $chapters->fetch_assoc()) {
                        echo '<div class="chapter-item">
                                <a href="?page=chapter&id=' . $chapter['id'] . '" class="chapter-link">
                                    ' . sanitize($chapter['chapter_title']) . '
                                </a>
                                <span>' . getTimeAgo($chapter['created_at']) . '</span>
                              </div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="notification warning">Chưa có chương nào.</div>';
                }
                
                // Comments section for comic
                echo '<div style="margin-top: 3rem;">
                        <h2 class="section-title">Bình Luận</h2>';
                
                // Display session notifications
                if (isset($_SESSION['notification'])) {
                    $notif = $_SESSION['notification'];
                    echo '<div class="notification ' . $notif['type'] . '">' . $notif['message'] . '</div>';
                    unset($_SESSION['notification']);
                }
                
                // Comment form
                if ($user) {
                    echo '<form method="POST" style="background: rgba(255,255,255,0.1); padding: 1.5rem; border-radius: 15px; margin-bottom: 2rem;">
                            <input type="hidden" name="comic_id" value="' . $comic_id . '">
                            <div class="form-group">
                                <textarea name="content" class="form-input" placeholder="Viết bình luận..." rows="4" required></textarea>
                            </div>
                            <div style="text-align: right;">
                                <button type="submit" name="add_comment" class="btn btn-primary">Đăng bình luận</button>
                            </div>
                          </form>';
                } else {
                    echo '<div class="notification warning">
                            <a href="?page=login">Đăng nhập</a> để bình luận.
                          </div>';
                }
                
                // Display comments
                displayComments($mysqli, $user, $comic_id);
                
                echo '</div>';
                break;

            case 'chapter':
                if (!$user) {
                    echo '<div class="notification warning">Bạn cần <a href="?page=login">đăng nhập</a> để đọc truyện.</div>';
                    break;
                }
                
                $chapter_id = (int)($_GET['id'] ?? 0);
                if ($chapter_id <= 0) {
                    echo '<div class="notification error">Chương không tồn tại!</div>';
                    break;
                }
                
                $chapter = $mysqli->query("
                    SELECT c.*, co.title as comic_title, co.id as comic_id 
                    FROM chapters c 
                    JOIN comics co ON co.id = c.comic_id 
                    WHERE c.id = $chapter_id
                ")->fetch_assoc();
                
                if (!$chapter) {
                    echo '<div class="notification error">Không tìm thấy chương!</div>';
                    break;
                }
                
                // Update view count and reading history
                $mysqli->query("UPDATE chapters SET views = views + 1 WHERE id = $chapter_id");
                
                // Check if user has already read this chapter today for qi reward
                $today = date('Y-m-d');
                $qi_check = $mysqli->query("
                    SELECT id FROM read_history 
                    WHERE user_id = {$user['id']} AND chapter_id = $chapter_id 
                    AND DATE(created_at) = '$today'
                ");
                
                $qi_gained = false;
                $realm_advancement = null;
                
                // Award qi if first read today
                if (!$qi_check || $qi_check->num_rows == 0) {
                    $realm_advancement = addQi($mysqli, $user['id'], 1);
                    $qi_gained = true;
                    
                    // Store session notification for realm advancement
                    if ($realm_advancement['advanced']) {
                        $_SESSION['realm_advancement'] = [
                            'realm' => $realm_advancement['new_realm'],
                            'stage' => $realm_advancement['new_stage']
                        ];
                    }
                }
                
                $mysqli->query("INSERT INTO read_history (user_id, chapter_id) VALUES ({$user['id']}, $chapter_id) 
                               ON DUPLICATE KEY UPDATE updated_at = NOW()");
                
                // Display realm advancement notification
                if (isset($_SESSION['realm_advancement'])) {
                    $advancement = $_SESSION['realm_advancement'];
                    echo '<div class="notification success" style="background: linear-gradient(45deg, #f1c40f, #e67e22); border: none; text-align: center; font-weight: bold; font-size: 1.1em;">
                            🎉 Chúc mừng! Bạn đã thăng cảnh giới lên ' . $advancement['realm'] . ' tầng ' . $advancement['stage'] . '! 🎉
                          </div>';
                    unset($_SESSION['realm_advancement']);
                }
                
                // Display qi gained notification
                if ($qi_gained) {
                    $user_progress = getUserRealmProgress($mysqli, $user['id']);
                    echo '<div class="notification success" style="text-align: center;">
                            ⚡ Bạn đã nhận được 1 Linh Khí! (' . $user_progress['current_qi'] . '/' . $user_progress['qi_needed'] . ')
                          </div>';
                }
                
                echo '<h1 class="page-title">' . sanitize($chapter['comic_title']) . ' - ' . sanitize($chapter['chapter_title']) . '</h1>';
                
                $images = $chapter['images'] ? explode("\n", trim($chapter['images'])) : [];
                if (!empty($images)) {
                    echo '<div style="text-align: center;">';
                    foreach ($images as $image) {
                        $image = trim($image);
                        if (!empty($image)) {
                            echo '<img src="' . sanitize($image) . '" style="max-width: 100%; margin-bottom: 1rem; border-radius: 10px;">';
                        }
                    }
                    echo '</div>';
                } else {
                    echo '<div class="notification warning">Chương này chưa có nội dung.</div>';
                }
                
                echo '<div style="text-align: center; margin-top: 2rem;">
                        <a href="?page=comic&id=' . $chapter['comic_id'] . '" class="btn btn-primary">Quay lại danh sách chương</a>
                      </div>';
                
                // Comments section for chapter
                echo '<div style="margin-top: 3rem;">
                        <h2 class="section-title">Bình Luận Chương</h2>';
                
                // Display session notifications
                if (isset($_SESSION['notification'])) {
                    $notif = $_SESSION['notification'];
                    echo '<div class="notification ' . $notif['type'] . '">' . $notif['message'] . '</div>';
                    unset($_SESSION['notification']);
                }
                
                // Comment form
                if ($user) {
                    echo '<form method="POST" style="background: rgba(255,255,255,0.1); padding: 1.5rem; border-radius: 15px; margin-bottom: 2rem;">
                            <input type="hidden" name="chapter_id" value="' . $chapter_id . '">
                            <div class="form-group">
                                <textarea name="content" class="form-input" placeholder="Viết bình luận về chương này..." rows="4" required></textarea>
                            </div>
                            <div style="text-align: right;">
                                <button type="submit" name="add_comment" class="btn btn-primary">Đăng bình luận</button>
                            </div>
                          </form>';
                } else {
                    echo '<div class="notification warning">
                            <a href="?page=login">Đăng nhập</a> để bình luận.
                          </div>';
                }
                
                // Display comments
                displayComments($mysqli, $user, null, $chapter_id);
                
                echo '</div>';
                break;

            case 'admin':
                if (!$user || !in_array($user['role'], ['admin', 'translator'])) {
                    echo '<div class="notification error">Bạn không có quyền truy cập trang này!</div>';
                    break;
                }
                
                $action = $_GET['action'] ?? 'comic';
                
                echo '<h1 class="page-title">Quản Trị Hệ Thống</h1>';
                echo '<div class="admin-nav">
                        <button class="tab-btn ' . ($action == 'comic' ? 'active' : '') . '" onclick="location.href=\'?page=admin&action=comic\'">Quản lý truyện</button>
                        <button class="tab-btn ' . ($action == 'chapter' ? 'active' : '') . '" onclick="location.href=\'?page=admin&action=chapter\'">Quản lý chương</button>
                      </div>';
                
                if ($action == 'comic') {
                    if ($_POST && isset($_POST['add_comic'])) {
                        $title = sanitize($_POST['title']);
                        $author = sanitize($_POST['author']);
                        $description = sanitize($_POST['description']);
                        $thumbnail = sanitize($_POST['thumbnail']);
                        
                        if (!empty($title)) {
                            $stmt = $mysqli->prepare("INSERT INTO comics (title, author, description, thumbnail, created_by) VALUES (?, ?, ?, ?, ?)");
                            $stmt->bind_param("ssssi", $title, $author, $description, $thumbnail, $user['id']);
                            
                            if ($stmt->execute()) {
                                echo '<div class="notification success">Đã thêm truyện thành công!</div>';
                            } else {
                                echo '<div class="notification error">Có lỗi khi thêm truyện!</div>';
                            }
                        }
                    }
                    
                    echo '<div class="admin-section">
                            <h2 class="section-title">Thêm Truyện Mới</h2>
                            <form method="POST">
                                <div class="form-group">
                                    <input name="title" type="text" class="form-input" placeholder="Tên truyện" required>
                                </div>
                                <div class="form-group">
                                    <input name="author" type="text" class="form-input" placeholder="Tác giả">
                                </div>
                                <div class="form-group">
                                    <input name="thumbnail" type="url" class="form-input" placeholder="Link ảnh bìa">
                                </div>
                                <div class="form-group">
                                    <textarea name="description" class="form-input" placeholder="Mô tả truyện" rows="4"></textarea>
                                </div>
                                <button type="submit" name="add_comic" class="btn btn-primary">Thêm Truyện</button>
                            </form>
                          </div>';
                    
                    echo '<div class="admin-section">
                            <h2 class="section-title">Danh Sách Truyện</h2>';
                    
                    $comics = $mysqli->query("SELECT * FROM comics ORDER BY id DESC");
                    if ($comics && $comics->num_rows > 0) {
                        while ($comic = $comics->fetch_assoc()) {
                            echo '<div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 10px;">
                                    <img src="' . sanitize($comic['thumbnail'] ?: 'https://via.placeholder.com/60x80') . '" style="width: 60px; height: 80px; object-fit: cover; border-radius: 5px;">
                                    <div style="flex: 1;">
                                        <h4>' . sanitize($comic['title']) . '</h4>
                                        <p style="color: rgba(255,255,255,0.7);">' . sanitize($comic['author'] ?: 'Chưa rõ tác giả') . '</p>
                                    </div>
                                    <div>
                                        <a href="?page=admin&action=chapter&comic_id=' . $comic['id'] . '" class="btn btn-outline" style="margin-right: 0.5rem;">Quản lý chương</a>
                                        <a href="?page=comic&id=' . $comic['id'] . '" class="btn btn-primary">Xem</a>
                                    </div>
                                  </div>';
                        }
                    } else {
                        echo '<div class="notification warning">Chưa có truyện nào.</div>';
                    }
                    echo '</div>';
                    
                } elseif ($action == 'chapter') {
                    $comic_id = (int)($_GET['comic_id'] ?? 0);
                    
                    if ($_POST && isset($_POST['add_chapter'])) {
                        $chapter_comic_id = (int)$_POST['comic_id'];
                        $chapter_title = sanitize($_POST['chapter_title']);
                        $images = sanitize($_POST['images']);
                        
                        if ($chapter_comic_id > 0 && !empty($chapter_title)) {
                            $stmt = $mysqli->prepare("INSERT INTO chapters (comic_id, chapter_title, images) VALUES (?, ?, ?)");
                            $stmt->bind_param("iss", $chapter_comic_id, $chapter_title, $images);
                            
                            if ($stmt->execute()) {
                                echo '<div class="notification success">Đã thêm chương thành công!</div>';
                            } else {
                                echo '<div class="notification error">Có lỗi khi thêm chương!</div>';
                            }
                        }
                    }
                    
                    echo '<div class="admin-section">
                            <h2 class="section-title">Thêm Chương Mới</h2>
                            <form method="POST">
                                <div class="form-group">
                                    <select name="comic_id" class="form-input" required>';
                    
                    $comics = $mysqli->query("SELECT id, title FROM comics ORDER BY title");
                    echo '<option value="">Chọn truyện</option>';
                    while ($comic = $comics->fetch_assoc()) {
                        $selected = ($comic_id == $comic['id']) ? 'selected' : '';
                        echo '<option value="' . $comic['id'] . '" ' . $selected . '>' . sanitize($comic['title']) . '</option>';
                    }
                    
                    echo '</select>
                                </div>
                                <div class="form-group">
                                    <input name="chapter_title" type="text" class="form-input" placeholder="Tên chương" required>
                                </div>
                                <div class="form-group">
                                    <textarea name="images" class="form-input" placeholder="Link ảnh (mỗi dòng một link)" rows="6"></textarea>
                                </div>
                                <button type="submit" name="add_chapter" class="btn btn-primary">Thêm Chương</button>
                            </form>
                          </div>';
                    
                    if ($comic_id > 0) {
                        $comic = $mysqli->query("SELECT title FROM comics WHERE id = $comic_id")->fetch_assoc();
                        echo '<div class="admin-section">
                                <h2 class="section-title">Chương của: ' . sanitize($comic['title']) . '</h2>';
                        
                        $chapters = $mysqli->query("SELECT * FROM chapters WHERE comic_id = $comic_id ORDER BY id DESC");
                        if ($chapters && $chapters->num_rows > 0) {
                            while ($chapter = $chapters->fetch_assoc()) {
                                echo '<div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 10px;">
                                        <div style="flex: 1;">
                                            <h4>' . sanitize($chapter['chapter_title']) . '</h4>
                                            <p style="color: rgba(255,255,255,0.7);">' . getTimeAgo($chapter['created_at']) . '</p>
                                        </div>
                                        <div>
                                            <a href="?page=chapter&id=' . $chapter['id'] . '" class="btn btn-primary">Xem</a>
                                        </div>
                                      </div>';
                            }
                        } else {
                            echo '<div class="notification warning">Chưa có chương nào.</div>';
                        }
                        echo '</div>';
                    }
                }
                break;

            case 'profile':
                if (!$user) {
                    echo '<div class="notification warning">Bạn cần <a href="?page=login">đăng nhập</a> để xem thông tin cá nhân.</div>';
                    break;
                }
                
                $user_progress = getUserRealmProgress($mysqli, $user['id']);
                
                echo '<h1 class="page-title">Thông Tin Cá Nhân</h1>';
                echo '<div class="admin-section">
                        <div style="text-align: center;">
                            <h2>' . sanitize($user['username']) . ' ' . getUserRoleTag($user['role']) . '</h2>
                            <p><strong>Email:</strong> ' . sanitize($user['email'] ?: 'Chưa cập nhật') . '</p>
                            <p><strong>Vai trò:</strong> ' . sanitize($user['role']) . '</p>
                            <p><strong>Số xu:</strong> ' . number_format($user['coins']) . ' xu</p>
                            
                            <div style="margin: 2rem 0; padding: 1.5rem; background: rgba(255,255,255,0.1); border-radius: 15px;">
                                <h3>Tu Luyện Tiến Trình</h3>
                                <p>' . getRealmBadge($user_progress['realm'], $user_progress['realm_stage']) . '</p>
                                <div style="margin: 1rem 0;">
                                    <strong>Linh Khí hiện tại:</strong> ' . $user_progress['current_qi'] . '/' . $user_progress['qi_needed'] . '
                                </div>
                                <div style="background: rgba(0,0,0,0.3); border-radius: 10px; height: 20px; margin: 1rem 0;">
                                    <div style="background: linear-gradient(45deg, #4ecdc4, #44a08d); height: 100%; border-radius: 10px; width: ' . min(100, ($user_progress['current_qi'] / $user_progress['qi_needed']) * 100) . '%"></div>
                                </div>
                                <p><strong>Tổng Linh Khí kiếm được:</strong> ' . number_format($user_progress['total_qi_earned']) . '</p>
                                <p style="color: rgba(255,255,255,0.7); font-size: 0.9em;">💡 Đọc chapter mỗi ngày để nhận Linh Khí và thăng cảnh giới!</p>
                            </div>
                            
                            <p><strong>Ngày tham gia:</strong> ' . date('d/m/Y', strtotime($user['created_at'])) . '</p>
                        </div>
                      </div>';
                break;

            default:
                echo '<div class="notification error">Trang không tồn tại!</div>';
                break;
        }
        ?>
    </main>

    <script>
        // Simple JavaScript for interactivity
        document.addEventListener('DOMContentLoaded', function() {
            // Smooth scrolling
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth' });
                    }
                });
            });
            
            // Auto-hide notifications
            setTimeout(() => {
                const notifications = document.querySelectorAll('.notification');
                notifications.forEach(notification => {
                    notification.style.opacity = '0.7';
                });
            }, 3000);
        });
        
        // Toggle reply form
        function toggleReplyForm(commentId) {
            const form = document.getElementById('reply-form-' + commentId);
            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
                form.querySelector('textarea').focus();
            } else {
                form.style.display = 'none';
            }
        }
    </script>
</body>
</html>
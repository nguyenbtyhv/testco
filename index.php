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

// Rate limiting for actions
function checkRateLimit($action, $user_id = null, $limit = 10, $window = 60) {
    $key = $user_id ? "{$action}_{$user_id}" : "{$action}_" . $_SERVER['REMOTE_ADDR'];
    
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }
    
    $now = time();
    $rate_data = $_SESSION['rate_limits'][$key] ?? ['count' => 0, 'reset_time' => $now + $window];
    
    // Reset if window expired
    if ($now > $rate_data['reset_time']) {
        $rate_data = ['count' => 0, 'reset_time' => $now + $window];
    }
    
    // Check limit
    if ($rate_data['count'] >= $limit) {
        return false;
    }
    
    // Increment counter
    $rate_data['count']++;
    $_SESSION['rate_limits'][$key] = $rate_data;
    
    return true;
}

// Input validation function
function validateInput($data, $type, $max_length = null) {
    $data = trim($data);
    
    switch ($type) {
        case 'username':
            return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $data) ? $data : false;
        case 'email':
            return filter_var($data, FILTER_VALIDATE_EMAIL) ? $data : false;
        case 'text':
            $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
            return ($max_length && strlen($data) > $max_length) ? false : $data;
        case 'int':
            return filter_var($data, FILTER_VALIDATE_INT) !== false ? (int)$data : false;
        case 'url':
            return filter_var($data, FILTER_VALIDATE_URL) ? $data : false;
        default:
            return false;
    }
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

// Coin management functions
function addCoins($mysqli, $user_id, $amount, $type, $description = '', $chapter_id = null) {
    $mysqli->begin_transaction();
    try {
        // Update user coins
        $stmt = $mysqli->prepare("UPDATE users SET coins = coins + ? WHERE id = ?");
        $stmt->bind_param("ii", $amount, $user_id);
        $stmt->execute();
        
        // Log transaction
        $stmt = $mysqli->prepare("INSERT INTO coin_transactions (user_id, amount, type, description, chapter_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iissi", $user_id, $amount, $type, $description, $chapter_id);
        $stmt->execute();
        
        $mysqli->commit();
        return true;
    } catch (Exception $e) {
        $mysqli->rollback();
        return false;
    }
}

function deductCoins($mysqli, $user_id, $amount, $type, $description = '', $chapter_id = null) {
    $mysqli->begin_transaction();
    try {
        // Check if user has enough coins
        $user = $mysqli->query("SELECT coins FROM users WHERE id = $user_id")->fetch_assoc();
        if ($user['coins'] < $amount) {
            $mysqli->rollback();
            return false;
        }
        
        // Deduct coins
        $stmt = $mysqli->prepare("UPDATE users SET coins = coins - ? WHERE id = ?");
        $stmt->bind_param("ii", $amount, $user_id);
        $stmt->execute();
        
        // Log transaction (negative amount)
        $negative_amount = -$amount;
        $stmt = $mysqli->prepare("INSERT INTO coin_transactions (user_id, amount, type, description, chapter_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iissi", $user_id, $negative_amount, $type, $description, $chapter_id);
        $stmt->execute();
        
        $mysqli->commit();
        return true;
    } catch (Exception $e) {
        $mysqli->rollback();
        return false;
    }
}

function hasUnlockedChapter($mysqli, $user_id, $chapter_id) {
    $result = $mysqli->query("SELECT id FROM chapter_unlocks WHERE user_id = $user_id AND chapter_id = $chapter_id");
    return $result && $result->num_rows > 0;
}

function unlockVipChapter($mysqli, $user_id, $chapter_id) {
    $mysqli->begin_transaction();
    try {
        // Check if already unlocked
        if (hasUnlockedChapter($mysqli, $user_id, $chapter_id)) {
            $mysqli->rollback();
            return ['success' => false, 'message' => 'Chapter đã được mở khóa trước đó'];
        }
        
        // Get chapter info
        $chapter = $mysqli->query("SELECT coins_unlock, chapter_title FROM chapters WHERE id = $chapter_id AND is_vip = 1")->fetch_assoc();
        if (!$chapter) {
            $mysqli->rollback();
            return ['success' => false, 'message' => 'Chapter không tồn tại hoặc không phải VIP'];
        }
        
        // Deduct coins
        if (!deductCoins($mysqli, $user_id, $chapter['coins_unlock'], 'unlock_chapter', "Mở khóa chapter: " . $chapter['chapter_title'], $chapter_id)) {
            $mysqli->rollback();
            return ['success' => false, 'message' => 'Không đủ xu để mở khóa chapter'];
        }
        
        // Unlock chapter
        $stmt = $mysqli->prepare("INSERT INTO chapter_unlocks (user_id, chapter_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $chapter_id);
        $stmt->execute();
        
        $mysqli->commit();
        return ['success' => true, 'message' => 'Mở khóa chapter thành công!'];
    } catch (Exception $e) {
        $mysqli->rollback();
        return ['success' => false, 'message' => 'Có lỗi xảy ra: ' . $e->getMessage()];
    }
}

function getChapterNavigation($mysqli, $chapter_id) {
    $current_chapter = $mysqli->query("SELECT comic_id, id FROM chapters WHERE id = $chapter_id")->fetch_assoc();
    if (!$current_chapter) return null;
    
    $comic_id = $current_chapter['comic_id'];
    
    // Get previous chapter
    $prev_chapter = $mysqli->query("
        SELECT id, chapter_title 
        FROM chapters 
        WHERE comic_id = $comic_id AND id < $chapter_id 
        ORDER BY id DESC 
        LIMIT 1
    ")->fetch_assoc();
    
    // Get next chapter
    $next_chapter = $mysqli->query("
        SELECT id, chapter_title 
        FROM chapters 
        WHERE comic_id = $comic_id AND id > $chapter_id 
        ORDER BY id ASC 
        LIMIT 1
    ")->fetch_assoc();
    
    return [
        'prev' => $prev_chapter,
        'next' => $next_chapter,
        'comic_id' => $comic_id
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

// Ranking calculation functions
function calculateComicRankings($mysqli, $rank_type = 'daily') {
    $date_condition = '';
    $today = date('Y-m-d');
    
    switch ($rank_type) {
        case 'daily':
            $date_condition = "AND DATE(created_at) = '$today'";
            break;
        case 'weekly':
            $date_condition = "AND created_at >= DATE_SUB('$today', INTERVAL 7 DAY)";
            break;
        case 'monthly':
            $date_condition = "AND created_at >= DATE_SUB('$today', INTERVAL 30 DAY)";
            break;
    }
    
    // Calculate comic scores based on views, favorites, and comments
    $query = "
        SELECT 
            c.id as comic_id,
            c.title,
            c.thumbnail,
            COALESCE(SUM(ch.views), 0) as total_views,
            COALESCE(fav_count.favorites, 0) as favorites_count,
            COALESCE(comment_count.comments, 0) as comments_count,
            (
                COALESCE(SUM(ch.views), 0) * 1.0 + 
                COALESCE(fav_count.favorites, 0) * 5.0 + 
                COALESCE(comment_count.comments, 0) * 3.0
            ) as score
        FROM comics c
        LEFT JOIN chapters ch ON c.id = ch.comic_id
        LEFT JOIN (
            SELECT comic_id, COUNT(*) as favorites 
            FROM user_favorites 
            WHERE 1=1 $date_condition 
            GROUP BY comic_id
        ) fav_count ON c.id = fav_count.comic_id
        LEFT JOIN (
            SELECT 
                COALESCE(ch.comic_id, c2.id) as comic_id, 
                COUNT(cm.id) as comments 
            FROM comments cm
            LEFT JOIN chapters ch ON cm.chapter_id = ch.id
            LEFT JOIN comics c2 ON cm.comic_id = c2.id
            WHERE 1=1 $date_condition
            GROUP BY COALESCE(ch.comic_id, c2.id)
        ) comment_count ON c.id = comment_count.comic_id
        GROUP BY c.id, c.title, c.thumbnail, fav_count.favorites, comment_count.comments
        HAVING score > 0
        ORDER BY score DESC
        LIMIT 100
    ";
    
    $result = $mysqli->query($query);
    $rankings = [];
    $position = 1;
    
    while ($result && $row = $result->fetch_assoc()) {
        $rankings[] = [
            'comic_id' => $row['comic_id'],
            'position' => $position,
            'views' => $row['total_views'],
            'favorites' => $row['favorites_count'],
            'comments' => $row['comments_count'],
            'score' => $row['score']
        ];
        $position++;
    }
    
    // Save rankings to database
    foreach ($rankings as $ranking) {
        $mysqli->query("
            INSERT INTO comic_rankings 
            (comic_id, rank_type, rank_position, views_count, favorites_count, comments_count, score, ranking_date)
            VALUES 
            ({$ranking['comic_id']}, '$rank_type', {$ranking['position']}, {$ranking['views']}, {$ranking['favorites']}, {$ranking['comments']}, {$ranking['score']}, '$today')
            ON DUPLICATE KEY UPDATE
            rank_position = {$ranking['position']},
            views_count = {$ranking['views']},
            favorites_count = {$ranking['favorites']},
            comments_count = {$ranking['comments']},
            score = {$ranking['score']}
        ");
    }
    
    return $rankings;
}

function calculateUserRankings($mysqli) {
    $today = date('Y-m-d');
    
    // Calculate Cao Thủ ranking (based on realm and total qi)
    $cao_thu_query = "
        SELECT 
            u.id as user_id,
            u.username,
            u.realm,
            u.realm_stage,
            urp.total_qi_earned,
            (
                CASE u.realm
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
                END * 1000000 + u.realm_stage * 10000 + urp.total_qi_earned
            ) as score
        FROM users u
        LEFT JOIN user_realm_progress urp ON u.id = urp.user_id
        WHERE u.role != 'admin'
        ORDER BY score DESC
        LIMIT 50
    ";
    
    $result = $mysqli->query($cao_thu_query);
    $position = 1;
    while ($result && $row = $result->fetch_assoc()) {
        $mysqli->query("
            INSERT INTO user_rankings 
            (user_id, rank_type, rank_position, score_value, ranking_date)
            VALUES 
            ({$row['user_id']}, 'cao_thu', $position, {$row['score']}, '$today')
            ON DUPLICATE KEY UPDATE
            rank_position = $position,
            score_value = {$row['score']}
        ");
        $position++;
    }
    
    // Calculate Tỷ Phú ranking (based on coins)
    $ty_phu_query = "
        SELECT 
            u.id as user_id,
            u.username,
            u.coins as score
        FROM users u
        WHERE u.role != 'admin' AND u.coins > 0
        ORDER BY u.coins DESC
        LIMIT 50
    ";
    
    $result = $mysqli->query($ty_phu_query);
    $position = 1;
    while ($result && $row = $result->fetch_assoc()) {
        $mysqli->query("
            INSERT INTO user_rankings 
            (user_id, rank_type, rank_position, score_value, ranking_date)
            VALUES 
            ({$row['user_id']}, 'ty_phu', $position, {$row['score']}, '$today')
            ON DUPLICATE KEY UPDATE
            rank_position = $position,
            score_value = {$row['score']}
        ");
        $position++;
    }
}

function getRankingData($mysqli, $rank_type = 'daily', $limit = 10) {
    $today = date('Y-m-d');
    
    $query = "
        SELECT 
            cr.rank_position,
            cr.score,
            cr.views_count,
            cr.favorites_count,
            cr.comments_count,
            c.id,
            c.title,
            c.thumbnail,
            c.author
        FROM comic_rankings cr
        JOIN comics c ON cr.comic_id = c.id
        WHERE cr.rank_type = '$rank_type' AND cr.ranking_date = '$today'
        ORDER BY cr.rank_position ASC
        LIMIT $limit
    ";
    
    return $mysqli->query($query);
}

function getUserRankingData($mysqli, $rank_type = 'cao_thu', $limit = 10) {
    $today = date('Y-m-d');
    
    $query = "
        SELECT 
            ur.rank_position,
            ur.score_value,
            u.id,
            u.username,
            u.realm,
            u.realm_stage,
            u.coins,
            urp.total_qi_earned
        FROM user_rankings ur
        JOIN users u ON ur.user_id = u.id
        LEFT JOIN user_realm_progress urp ON u.id = urp.user_id
        WHERE ur.rank_type = '$rank_type' AND ur.ranking_date = '$today'
        ORDER BY ur.rank_position ASC
        LIMIT $limit
    ";
    
    return $mysqli->query($query);
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

function displayComments($mysqli, $user, $comic_id = null, $chapter_id = null, $parent_id = null, $level = 0, $context = 'general') {
    $where_clause = "WHERE parent_id " . ($parent_id ? "= $parent_id" : "IS NULL");
    if ($comic_id) $where_clause .= " AND comic_id = $comic_id";
    if ($chapter_id) $where_clause .= " AND chapter_id = $chapter_id";
    
    // For comic context, only show comments without chapter_id (general comic comments)
    if ($context === 'comic' && $comic_id) {
        $where_clause .= " AND chapter_id IS NULL";
    }
    
    $comments = $mysqli->query("
        SELECT c.*, u.username, u.role, u.realm, u.realm_stage,
               (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) as like_count,
               " . ($user ? "(SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id AND cl.user_id = {$user['id']}) as user_liked" : "0 as user_liked") . "
        FROM comments c 
        JOIN users u ON u.id = c.user_id 
        $where_clause
        ORDER BY c.is_pinned DESC, c.created_at DESC
    ");
    
    if (!$comments || $comments->num_rows == 0) {
        return;
    }
    
    $indent = $level * 40;
    
    while ($comment = $comments->fetch_assoc()) {
        $can_pin = $user && in_array($user['role'], ['admin']);
        $pin_text = $comment['is_pinned'] ? 'Bỏ ghim' : 'Ghim';
        $pin_icon = $comment['is_pinned'] ? '📌' : '';
        
        echo '<div style="margin-left: ' . $indent . 'px; border-left: ' . ($level > 0 ? '2px solid rgba(255,255,255,0.2)' : 'none') . '; padding-left: ' . ($level > 0 ? '15px' : '0') . '; margin-bottom: 1.5rem;">
                <div style="background: rgba(255,255,255,' . ($comment['is_pinned'] ? '0.15' : '0.1') . '); padding: 1rem; border-radius: 10px; border: ' . ($comment['is_pinned'] ? '2px solid #f1c40f' : '1px solid rgba(255,255,255,0.2)') . ';">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <strong>' . sanitize($comment['username']) . '</strong>
                        ' . getUserRoleTag($comment['role']) . '
                        ' . getRealmBadge($comment['realm'], $comment['realm_stage']) . '';
                        
        // Display current chapter info if this comment is on a specific chapter
        if ($comment['chapter_id']) {
            $current_chapter_query = $mysqli->query("
                SELECT ch.chapter_title 
                FROM chapters ch 
                WHERE ch.id = {$comment['chapter_id']}
            ");
            if ($current_chapter_query && $current_chapter_query->num_rows > 0) {
                $current_chapter = $current_chapter_query->fetch_assoc();
                echo '<span style="background: rgba(46, 204, 113, 0.2); color: #2ecc71; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.75rem; font-weight: bold;" 
                            title="Bình luận ở chapter này">
                        📖 ' . sanitize($current_chapter['chapter_title']) . '
                      </span>';
            }
        }
             
        echo '        ' . $pin_icon . '
                        <span style="color: rgba(255,255,255,0.6); font-size: 0.8em;">' . getTimeAgo($comment['created_at']) . '</span>
                    </div>
                    <div style="margin-bottom: 1rem; line-height: 1.5;">
                        ' . nl2br(sanitize($comment['content'])) . '
                    </div>
                    <div style="display: flex; gap: 1rem; align-items: center;">
                        ' . ($user ? '<a href="?like_comment=' . $comment['id'] . '" style="color: ' . ($comment['user_liked'] ? '#e74c3c' : 'rgba(255,255,255,0.7)') . '; text-decoration: none;">❤️ ' . $comment['like_count'] . '</a>' : '<span style="color: rgba(255,255,255,0.7);">❤️ ' . $comment['like_count'] . '</span>') . '
                        ' . ($user ? '<a href="#" onclick="toggleReplyForm(' . $comment['id'] . ')" style="color: rgba(255,255,255,0.7); text-decoration: none;">💬 Trả lời</a>' : '') . '
                        ' . ($can_pin ? '<a href="?toggle_pin=' . $comment['id'] . '" style="color: rgba(255,255,255,0.7); text-decoration: none;">📌 ' . $pin_text . '</a>' : '') . '
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
        displayComments($mysqli, $user, $comic_id, $chapter_id, $comment['id'], $level + 1, $context);
        
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
    // Rate limiting for comments
    if (!checkRateLimit('comment', $user['id'], 5, 60)) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Bạn đang bình luận quá nhanh! Vui lòng chờ một chút.'];
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    $content = validateInput($_POST['content'], 'text', 1000);
    $comic_id = isset($_POST['comic_id']) ? validateInput($_POST['comic_id'], 'int') : null;
    $chapter_id = isset($_POST['chapter_id']) ? validateInput($_POST['chapter_id'], 'int') : null;
    $parent_id = isset($_POST['parent_id']) ? validateInput($_POST['parent_id'], 'int') : null;
    
    if ($content !== false && !empty($content) && ($comic_id || $chapter_id)) {
        $stmt = $mysqli->prepare("INSERT INTO comments (user_id, comic_id, chapter_id, parent_id, content) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiis", $user['id'], $comic_id, $chapter_id, $parent_id, $content);
        
        if ($stmt->execute()) {
            $_SESSION['notification'] = ['type' => 'success', 'message' => 'Đã thêm bình luận thành công!'];
        } else {
            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Có lỗi khi thêm bình luận!'];
        }
    } else {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Nội dung bình luận không hợp lệ!'];
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

// Handle delete comment
if ($user && isset($_GET['delete_comment'])) {
    $comment_id = (int)$_GET['delete_comment'];
    
    // Check if user owns the comment or is admin
    $comment_check = $mysqli->query("SELECT user_id FROM comments WHERE id = $comment_id");
    if ($comment_check && $comment_check->num_rows > 0) {
        $comment_data = $comment_check->fetch_assoc();
        if ($comment_data['user_id'] == $user['id'] || $user['role'] === 'admin') {
            // Delete comment and its likes
            $mysqli->query("DELETE FROM comment_likes WHERE comment_id = $comment_id");
            $mysqli->query("DELETE FROM comments WHERE id = $comment_id");
            
            // Also delete replies to this comment
            $mysqli->query("DELETE FROM comment_likes WHERE comment_id IN (SELECT id FROM comments WHERE parent_id = $comment_id)");
            $mysqli->query("DELETE FROM comments WHERE parent_id = $comment_id");
        }
    }
    
    header('Location: ?page=profile');
    exit;
}

// Handle unlock VIP chapter
if ($user && isset($_GET['unlock_chapter'])) {
    // Rate limiting for unlock attempts
    if (!checkRateLimit('unlock', $user['id'], 3, 60)) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Bạn đang thực hiện quá nhiều giao dịch! Vui lòng chờ một chút.'];
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    $chapter_id = validateInput($_GET['unlock_chapter'], 'int');
    if ($chapter_id !== false) {
        $result = unlockVipChapter($mysqli, $user['id'], $chapter_id);
        
        $_SESSION['notification'] = [
            'type' => $result['success'] ? 'success' : 'error',
            'message' => $result['message']
        ];
        
        header("Location: ?page=chapter&id=$chapter_id");
    } else {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'ID chapter không hợp lệ!'];
        header("Location: " . $_SERVER['HTTP_REFERER']);
    }
    exit;
}

// Handle daily coin reward
if ($user && isset($_GET['daily_reward'])) {
    $today = date('Y-m-d');
    $check = $mysqli->query("
        SELECT id FROM coin_transactions 
        WHERE user_id = {$user['id']} AND type = 'daily_reward' 
        AND DATE(created_at) = '$today'
    ");
    
    if (!$check || $check->num_rows == 0) {
        if (addCoins($mysqli, $user['id'], 10, 'daily_reward', 'Phần thưởng hàng ngày')) {
            $_SESSION['notification'] = ['type' => 'success', 'message' => 'Đã nhận 10 xu phần thưởng hàng ngày!'];
        }
    } else {
        $_SESSION['notification'] = ['type' => 'warning', 'message' => 'Bạn đã nhận phần thưởng hôm nay rồi!'];
    }
    
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?: '?'));
    exit;
}

// Handle remove all comments from a comic
if ($user && isset($_GET['remove_comic_comments'])) {
    $comic_id = (int)$_GET['remove_comic_comments'];
    
    // Get all chapter IDs from this comic
    $chapters_query = $mysqli->query("SELECT id FROM chapters WHERE comic_id = $comic_id");
    $chapter_ids = [];
    while ($chapter = $chapters_query->fetch_assoc()) {
        $chapter_ids[] = $chapter['id'];
    }
    
    if (!empty($chapter_ids)) {
        $chapter_ids_str = implode(',', $chapter_ids);
        
        // Delete user's comments and their likes from this comic
        $mysqli->query("DELETE FROM comment_likes WHERE comment_id IN (
            SELECT id FROM comments WHERE user_id = {$user['id']} AND chapter_id IN ($chapter_ids_str)
        )");
        $mysqli->query("DELETE FROM comments WHERE user_id = {$user['id']} AND chapter_id IN ($chapter_ids_str)");
    }
    
    header('Location: ?page=profile');
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

$mysqli->query("CREATE TABLE IF NOT EXISTS coin_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount INT NOT NULL,
    type ENUM('purchase', 'unlock_chapter', 'daily_reward', 'refund') NOT NULL,
    description TEXT,
    chapter_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_type (user_id, type),
    INDEX idx_chapter (chapter_id)
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

// Create ranking tables
$mysqli->query("CREATE TABLE IF NOT EXISTS comic_rankings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comic_id INT NOT NULL,
    rank_type ENUM('daily', 'weekly', 'monthly') NOT NULL,
    rank_position INT NOT NULL,
    views_count INT DEFAULT 0,
    favorites_count INT DEFAULT 0,
    comments_count INT DEFAULT 0,
    score DECIMAL(10,2) DEFAULT 0.00,
    ranking_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ranking (comic_id, rank_type, ranking_date),
    INDEX idx_rank_type_date (rank_type, ranking_date),
    INDEX idx_rank_position (rank_type, ranking_date, rank_position)
)");

$mysqli->query("CREATE TABLE IF NOT EXISTS user_rankings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    rank_type ENUM('cao_thu', 'ty_phu') NOT NULL,
    rank_position INT NOT NULL,
    score_value BIGINT DEFAULT 0,
    ranking_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_ranking (user_id, rank_type, ranking_date),
    INDEX idx_user_rank_type_date (rank_type, ranking_date),
    INDEX idx_user_rank_position (rank_type, ranking_date, rank_position)
)");

// Add database indexes for performance optimization
$mysqli->query("CREATE INDEX IF NOT EXISTS idx_comics_status ON comics(status)");
$mysqli->query("CREATE INDEX IF NOT EXISTS idx_comics_created_at ON comics(created_at)");
$mysqli->query("CREATE INDEX IF NOT EXISTS idx_chapters_comic_vip ON chapters(comic_id, is_vip)");
$mysqli->query("CREATE INDEX IF NOT EXISTS idx_chapters_created_at ON chapters(created_at)");
$mysqli->query("CREATE INDEX IF NOT EXISTS idx_comments_comic_chapter ON comments(comic_id, chapter_id)");
$mysqli->query("CREATE INDEX IF NOT EXISTS idx_comments_created_at ON comments(created_at)");
$mysqli->query("CREATE INDEX IF NOT EXISTS idx_user_favorites_user_comic ON user_favorites(user_id, comic_id)");
$mysqli->query("CREATE INDEX IF NOT EXISTS idx_read_history_user_date ON read_history(user_id, created_at)");

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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #6c63ff 0%, #9c88ff 50%, #c3acd0 100%);
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(108, 99, 255, 0.1);
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 20px rgba(108, 99, 255, 0.1);
        }
        
        .navbar {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1rem;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: #6c63ff;
            text-decoration: none;
            background: linear-gradient(45deg, #6c63ff, #9c88ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .nav-menu {
            display: flex;
            list-style: none;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .nav-link {
            color: #6c63ff;
            text-decoration: none;
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        
        .nav-link:hover, .nav-link.active {
            background: linear-gradient(45deg, #6c63ff, #9c88ff);
            color: white;
            transform: translateY(-1px);
        }
        
        .user-area {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-size: 0.8rem;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #6c63ff, #9c88ff);
            color: white;
            box-shadow: 0 4px 15px rgba(108, 99, 255, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 99, 255, 0.4);
        }
        
        .btn-outline {
            background: transparent;
            color: #6c63ff;
            border: 2px solid #6c63ff;
        }
        
        .btn-outline:hover {
            background: #6c63ff;
            color: white;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 1rem;
        }
        
        .page-title {
            font-size: 1.8rem;
            text-align: center;
            margin-bottom: 1.5rem;
            color: white;
            font-weight: bold;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .section-title {
            font-size: 1.3rem;
            margin-bottom: 1rem;
            color: white;
            font-weight: bold;
            padding: 0.75rem 1rem;
            background: linear-gradient(45deg, rgba(255,255,255,0.2), rgba(255,255,255,0.1));
            border-radius: 15px;
            border-left: 4px solid #6c63ff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        
        /* Mobile-first responsive grid */
        .comic-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .comic-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.15);
            position: relative;
        }
        
        .comic-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(108, 99, 255, 0.25);
        }
        
        .comic-thumbnail {
            width: 100%;
            height: 180px;
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
            padding: 0.75rem;
        }
        
        .comic-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .comic-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: #666;
        }
        
        /* Ranking specific styles */
        .ranking-tabs {
            display: flex;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 25px;
            padding: 0.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(108, 99, 255, 0.1);
        }
        
        .tab-btn {
            flex: 1;
            padding: 0.75rem 1rem;
            background: transparent;
            border: none;
            border-radius: 20px;
            color: #6c63ff;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .tab-btn.active {
            background: linear-gradient(45deg, #6c63ff, #9c88ff);
            color: white;
            box-shadow: 0 4px 15px rgba(108, 99, 255, 0.3);
        }
        
        .ranking-list {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.1);
        }
        
        .ranking-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.3s ease;
        }
        
        .ranking-item:hover {
            background: linear-gradient(90deg, rgba(108, 99, 255, 0.05), rgba(156, 136, 255, 0.05));
        }
        
        .ranking-item:last-child {
            border-bottom: none;
        }
        
        .rank-number {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .rank-number.top-1 {
            background: linear-gradient(45deg, #FFD700, #FFA500);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.4);
        }
        
        .rank-number.top-2 {
            background: linear-gradient(45deg, #C0C0C0, #A8A8A8);
            color: white;
            box-shadow: 0 4px 15px rgba(192, 192, 192, 0.4);
        }
        
        .rank-number.top-3 {
            background: linear-gradient(45deg, #CD7F32, #B8860B);
            color: white;
            box-shadow: 0 4px 15px rgba(205, 127, 50, 0.4);
        }
        
        .rank-number.other {
            background: linear-gradient(45deg, #6c63ff, #9c88ff);
            color: white;
        }
        
        .ranking-content {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .ranking-thumbnail {
            width: 3rem;
            height: 3.5rem;
            border-radius: 8px;
            object-fit: cover;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .ranking-info {
            flex: 1;
        }
        
        .ranking-title {
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
            line-height: 1.3;
        }
        
        .ranking-stats {
            font-size: 0.75rem;
            color: #666;
            display: flex;
            gap: 0.75rem;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .form-container {
            max-width: 400px;
            margin: 2rem auto;
            background: white;
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.15);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 15px;
            color: #333;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-input::placeholder {
            color: #adb5bd;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #6c63ff;
            box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.1);
        }
        
        .notification {
            padding: 1rem;
            border-radius: 15px;
            margin-bottom: 1rem;
            border: none;
        }
        
        .notification.success {
            background: linear-gradient(45deg, #51cf66, #40c057);
            color: white;
        }
        
        .notification.error {
            background: linear-gradient(45deg, #ff6b6b, #fa5252);
            color: white;
        }
        
        .notification.warning {
            background: linear-gradient(45deg, #ffd43b, #fab005);
            color: white;
        }
        
        .chapter-list {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.1);
        }
        
        .chapter-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.3s ease;
        }
        
        .chapter-item:hover {
            background: linear-gradient(90deg, rgba(108, 99, 255, 0.05), rgba(156, 136, 255, 0.05));
        }
        
        .chapter-link {
            color: #333;
            text-decoration: none;
            font-weight: 500;
            flex: 1;
        }
        
        .chapter-link:hover {
            color: #6c63ff;
        }
        
        .admin-section {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.1);
        }
        
        .admin-nav {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .user-ranking-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: white;
            border-radius: 15px;
            margin-bottom: 0.75rem;
            box-shadow: 0 4px 15px rgba(108, 99, 255, 0.1);
            transition: all 0.3s ease;
        }
        
        .user-ranking-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.15);
        }
        
        .user-avatar {
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            background: linear-gradient(45deg, #6c63ff, #9c88ff);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .user-info {
            flex: 1;
        }
        
        .username {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .user-stats {
            font-size: 0.8rem;
            color: #666;
            display: flex;
            gap: 1rem;
        }
        
        /* Responsive design */
        @media (min-width: 768px) {
            .comic-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1.5rem;
            }
            
            .container {
                padding: 2rem;
            }
            
            .comic-thumbnail {
                height: 220px;
            }
            
            .nav-menu {
                gap: 1rem;
            }
            
            .nav-link {
                font-size: 0.9rem;
                padding: 0.5rem 1rem;
            }
        }
        
        @media (min-width: 1024px) {
            .comic-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 2rem;
            }
            
            .comic-thumbnail {
                height: 250px;
            }
            
            .navbar {
                padding: 0 2rem;
            }
            
            .nav-link {
                font-size: 1rem;
                padding: 0.75rem 1.25rem;
            }
        }
        
        /* Animation keyframes */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .comic-card {
            animation: fadeInUp 0.5s ease-out;
        }
        
        .ranking-item {
            animation: fadeInUp 0.3s ease-out;
        }
        
        /* Loading states */
        .loading {
            position: relative;
            overflow: hidden;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            animation: shimmer 1.5s infinite;
        }
        
        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
    </style>
</head>
<body>
    <header class="header">
        <nav class="navbar">
            <a href="?page=home" class="logo">🌟 MangaHub</a>
            <ul class="nav-menu">
                <li><a href="?page=home" class="nav-link <?php echo $page == 'home' ? 'active' : ''; ?>">Trang chủ</a></li>
                <li><a href="?page=rankings" class="nav-link <?php echo $page == 'rankings' ? 'active' : ''; ?>">🏆 Bảng xếp hạng</a></li>
                <li><a href="?page=top_users" class="nav-link <?php echo $page == 'top_users' ? 'active' : ''; ?>">👑 Top cao thủ</a></li>
                <li><a href="?page=favorites" class="nav-link <?php echo $page == 'favorites' ? 'active' : ''; ?>">Theo dõi</a></li>
                <li><a href="?page=history" class="nav-link <?php echo $page == 'history' ? 'active' : ''; ?>">Lịch sử</a></li>
                <?php if ($user): ?>
                <li><a href="?page=shop" class="nav-link <?php echo $page == 'shop' ? 'active' : ''; ?>">💰 Cửa hàng</a></li>
                <?php endif; ?>
                <?php if ($user && in_array($user['role'], ['admin', 'translator'])): ?>
                <li><a href="?page=admin" class="nav-link <?php echo $page == 'admin' ? 'active' : ''; ?>">Quản trị</a></li>
                <?php endif; ?>
            </ul>
            <div class="user-area">
                <?php if ($user): ?>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span style="background: linear-gradient(45deg, #f39c12, #e67e22); color: white; padding: 0.3rem 0.8rem; border-radius: 15px; font-weight: bold; font-size: 0.9rem;">
                            💰 <?php echo number_format($user['coins']); ?> xu
                        </span>
                        <span>Xin chào, <?php echo sanitize($user['username']); ?>!</span>
                    </div>
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
                echo '<h1 class="page-title">Truyện Mới Nhất</h1>';
                echo '<div class="comic-grid">';
                
                $comics = $mysqli->query("SELECT * FROM comics ORDER BY updated_at DESC LIMIT 20");
                if ($comics && $comics->num_rows > 0) {
                    while ($comic = $comics->fetch_assoc()) {
                        $thumbnail = $comic['thumbnail'] ?: '/assets/images/no-image.svg';
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
                                                    <img src="' . sanitize($comic['thumbnail'] ?: '/assets/images/no-image.svg') . '" 
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
                        $is_unlocked = $user ? hasUnlockedChapter($mysqli, $user['id'], $chapter['id']) : false;
                        $vip_badge = $chapter['is_vip'] ? '<span style="background: linear-gradient(45deg, #f39c12, #e67e22); color: white; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.7rem; font-weight: bold; margin-left: 0.5rem;">🔒 VIP</span>' : '';
                        $unlock_badge = ($chapter['is_vip'] && $is_unlocked) ? '<span style="background: linear-gradient(45deg, #27ae60, #2ecc71); color: white; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.7rem; font-weight: bold; margin-left: 0.5rem;">🔓 Đã mở</span>' : '';
                        
                        echo '<div class="chapter-item" style="display: flex; justify-content: space-between; align-items: center; padding: 0.8rem; background: rgba(255,255,255,0.1); border-radius: 8px; margin-bottom: 0.5rem;">
                                <div style="display: flex; align-items: center;">
                                    <a href="?page=chapter&id=' . $chapter['id'] . '" class="chapter-link" style="color: white; text-decoration: none; font-weight: 500;">
                                        ' . sanitize($chapter['chapter_title']) . '
                                    </a>
                                    ' . $vip_badge . $unlock_badge . '
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    ' . ($chapter['is_vip'] && !$is_unlocked && $user ? '<span style="color: #f39c12; font-size: 0.8rem;">' . $chapter['coins_unlock'] . ' xu</span>' : '') . '
                                    <span style="color: rgba(255,255,255,0.6); font-size: 0.8rem;">' . getTimeAgo($chapter['created_at']) . '</span>
                                </div>
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
                
                displayComments($mysqli, $user, $comic_id, null, null, 0, 'comic');
                                            $chapters_with_comments = $mysqli->query("
                    SELECT DISTINCT ch.id, ch.chapter_title, COUNT(c.id) as comment_count
                    FROM chapters ch
                    LEFT JOIN comments c ON c.chapter_id = ch.id
                    WHERE ch.comic_id = $comic_id AND c.id IS NOT NULL
                    GROUP BY ch.id, ch.chapter_title
                    ORDER BY ch.id ASC
                ");
                
                if ($chapters_with_comments && $chapters_with_comments->num_rows > 0) {
                    while ($chapter_info = $chapters_with_comments->fetch_assoc()) {
                        displayComments($mysqli, $user, null, $chapter_info['id'], null, 0, 'chapter');
                    }
                } else {
                    echo '<div class="notification info">Chưa có bình luận nào cho các chương.</div>';
                }
                
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
                
                // Check VIP access
                $vip_access = true;
                $unlock_message = '';
                
                if ($chapter['is_vip']) {
                    $is_unlocked = hasUnlockedChapter($mysqli, $user['id'], $chapter_id);
                    if (!$is_unlocked) {
                        $vip_access = false;
                        $unlock_message = $chapter['coins_unlock'];
                    }
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
                
                // Get chapter navigation
                $navigation = getChapterNavigation($mysqli, $chapter_id);
                
                // Display session notifications
                if (isset($_SESSION['notification'])) {
                    $notif = $_SESSION['notification'];
                    echo '<div class="notification ' . $notif['type'] . '">' . $notif['message'] . '</div>';
                    unset($_SESSION['notification']);
                }
                
                echo '<h1 class="page-title">' . sanitize($chapter['comic_title']) . ' - ' . sanitize($chapter['chapter_title']) . '</h1>';
                
                // Chapter navigation bar
                echo '<div class="chapter-navigation" style="margin: 2rem 0; display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.1); padding: 1rem; border-radius: 15px;">
                        <div>';
                if ($navigation['prev']) {
                    echo '<a href="?page=chapter&id=' . $navigation['prev']['id'] . '" class="btn btn-outline" style="margin-right: 0.5rem;" title="' . sanitize($navigation['prev']['chapter_title']) . '">
                            ← Chương trước
                          </a>';
                } else {
                    echo '<span class="btn btn-outline" style="opacity: 0.5; cursor: not-allowed; margin-right: 0.5rem;">← Chương trước</span>';
                }
                echo '</div>
                      <div style="text-align: center;">
                        <a href="?page=comic&id=' . $chapter['comic_id'] . '" class="btn btn-primary">Danh sách chương</a>
                      </div>
                      <div>';
                if ($navigation['next']) {
                    echo '<a href="?page=chapter&id=' . $navigation['next']['id'] . '" class="btn btn-outline" style="margin-left: 0.5rem;" title="' . sanitize($navigation['next']['chapter_title']) . '">
                            Chương sau →
                          </a>';
                } else {
                    echo '<span class="btn btn-outline" style="opacity: 0.5; cursor: not-allowed; margin-left: 0.5rem;">Chương sau →</span>';
                }
                echo '</div>
                      </div>';
                
                // VIP Chapter Content Check
                if (!$vip_access) {
                    echo '<div class="vip-unlock-panel" style="text-align: center; background: linear-gradient(45deg, #f39c12, #e67e22); padding: 3rem; border-radius: 20px; margin: 2rem 0; border: 3px solid #d35400;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">🔒</div>
                            <h2 style="color: white; margin-bottom: 1rem;">Chapter VIP</h2>
                            <p style="color: white; margin-bottom: 1.5rem; font-size: 1.1rem;">Chapter này yêu cầu ' . $unlock_message . ' xu để mở khóa</p>
                            <div style="margin-bottom: 1.5rem;">
                                <span style="background: rgba(255,255,255,0.9); color: #e67e22; padding: 0.5rem 1rem; border-radius: 25px; font-weight: bold;">
                                    💰 Số xu hiện tại: ' . number_format($user['coins']) . '
                                </span>
                            </div>';
                    
                    if ($user['coins'] >= $unlock_message) {
                        echo '<a href="?unlock_chapter=' . $chapter_id . '" class="btn" style="background: linear-gradient(45deg, #27ae60, #2ecc71); color: white; font-size: 1.1rem; padding: 1rem 2rem; margin: 0.5rem;">
                                🔓 Mở khóa với ' . $unlock_message . ' xu
                              </a>';
                    } else {
                        echo '<div style="margin-bottom: 1rem;">
                                <p style="color: white; margin-bottom: 1rem;">Bạn cần thêm ' . ($unlock_message - $user['coins']) . ' xu</p>
                                <a href="?page=shop" class="btn" style="background: linear-gradient(45deg, #3498db, #2980b9); color: white; font-size: 1.1rem; padding: 1rem 2rem;">
                                    💳 Mua xu
                                </a>
                              </div>';
                    }
                    
                    echo '</div>';
                } else {
                    // Show chapter content
                    $images = $chapter['images'] ? explode("\n", trim($chapter['images'])) : [];
                    if (!empty($images)) {
                        echo '<div class="chapter-content" style="text-align: center;">';
                        foreach ($images as $image) {
                            $image = trim($image);
                            if (!empty($image)) {
                                echo '<img src="' . sanitize($image) . '" style="max-width: 100%; margin-bottom: 1rem; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);" loading="lazy">';
                            }
                        }
                        echo '</div>';
                    } else {
                        echo '<div class="notification warning">Chương này chưa có nội dung.</div>';
                    }
                }
                
                // Bottom navigation
                echo '<div class="chapter-navigation" style="margin: 2rem 0; display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.1); padding: 1rem; border-radius: 15px;">
                        <div>';
                if ($navigation['prev']) {
                    echo '<a href="?page=chapter&id=' . $navigation['prev']['id'] . '" class="btn btn-outline" style="margin-right: 0.5rem;" title="' . sanitize($navigation['prev']['chapter_title']) . '">
                            ← Chương trước
                          </a>';
                } else {
                    echo '<span class="btn btn-outline" style="opacity: 0.5; cursor: not-allowed; margin-right: 0.5rem;">← Chương trước</span>';
                }
                echo '</div>
                      <div style="text-align: center;">
                        <a href="?page=comic&id=' . $chapter['comic_id'] . '" class="btn btn-primary">Danh sách chương</a>
                      </div>
                      <div>';
                if ($navigation['next']) {
                    echo '<a href="?page=chapter&id=' . $navigation['next']['id'] . '" class="btn btn-outline" style="margin-left: 0.5rem;" title="' . sanitize($navigation['next']['chapter_title']) . '">
                            Chương sau →
                          </a>';
                } else {
                    echo '<span class="btn btn-outline" style="opacity: 0.5; cursor: not-allowed; margin-left: 0.5rem;">Chương sau →</span>';
                }
                echo '</div>
                      </div>';
                
                // Keyboard shortcuts help
                echo '<div class="shortcuts-help" id="shortcuts-help">
                        <div style="font-weight: bold; margin-bottom: 0.5rem;">⌨️ Phím tắt:</div>
                        <div>← hoặc A: Chương trước</div>
                        <div>→ hoặc D: Chương sau</div>
                        <div>Esc: Về danh sách chương</div>
                        <div style="margin-top: 0.5rem; font-size: 0.7rem; opacity: 0.7;">Nhấn ? để ẩn/hiện</div>
                      </div>';
                
                // Comments section for chapter (only if chapter is accessible)
                if ($vip_access) {
                    echo '<div style="margin-top: 3rem;">
                            <h2 class="section-title">Bình Luận Chương</h2>';
                
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
                    displayComments($mysqli, $user, null, $chapter_id, null, 0, 'chapter');
                    
                    echo '</div>';
                }
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
                                    <img src="' . sanitize($comic['thumbnail'] ?: '/assets/images/no-image.svg') . '" style="width: 60px; height: 80px; object-fit: cover; border-radius: 5px;">
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
                        $is_vip = isset($_POST['is_vip']) ? 1 : 0;
                        $coins_unlock = $is_vip ? (int)$_POST['coins_unlock'] : 0;
                        
                        if ($chapter_comic_id > 0 && !empty($chapter_title)) {
                            $stmt = $mysqli->prepare("INSERT INTO chapters (comic_id, chapter_title, images, is_vip, coins_unlock) VALUES (?, ?, ?, ?, ?)");
                            $stmt->bind_param("issii", $chapter_comic_id, $chapter_title, $images, $is_vip, $coins_unlock);
                            
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
                                <div class="form-group">
                                    <label style="display: flex; align-items: center; color: #fff; margin-bottom: 0.5rem;">
                                        <input type="checkbox" name="is_vip" id="is_vip" style="margin-right: 0.5rem;" onchange="toggleVipOptions()">
                                        Chapter VIP (yêu cầu xu để mở khóa)
                                    </label>
                                </div>
                                <div id="vip_options" style="display: none;" class="form-group">
                                    <input name="coins_unlock" type="number" class="form-input" placeholder="Số xu cần để mở khóa" min="1" value="50">
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
                
                // Display commented comics management section
                echo '<div class="admin-section" style="margin-top: 2rem;">
                        <h3 style="margin-bottom: 1rem; color: #fff; font-size: 1.2rem;">📚 Quản Lý Truyện Đã Bình Luận</h3>';
                
                // Get comics where user has commented
                $commented_comics_query = "
                    SELECT 
                        co.id as comic_id,
                        co.title as comic_title,
                        co.thumbnail,
                        COUNT(DISTINCT ch.id) as chapter_count,
                        COUNT(c.id) as total_comments,
                        MAX(c.created_at) as last_comment_date,
                        GROUP_CONCAT(DISTINCT ch.chapter_title ORDER BY ch.id ASC SEPARATOR ', ') as chapter_titles
                    FROM comments c
                    LEFT JOIN chapters ch ON c.chapter_id = ch.id
                    LEFT JOIN comics co ON ch.comic_id = co.id
                    WHERE c.user_id = {$user['id']} 
                    AND c.chapter_id IS NOT NULL
                    GROUP BY co.id, co.title, co.thumbnail
                    ORDER BY last_comment_date DESC
                    LIMIT 10
                ";
                
                $commented_comics = $mysqli->query($commented_comics_query);
                $total_comics = $commented_comics ? $commented_comics->num_rows : 0;
                
                if ($commented_comics && $commented_comics->num_rows > 0) {
                    echo '<div style="margin-bottom: 1rem; padding: 0.5rem 1rem; background: rgba(52, 152, 219, 0.2); border-radius: 20px; display: inline-block; color: #3498db; font-size: 0.85rem;">
                            📊 Đã bình luận tại ' . $total_comics . ' truyện
                          </div>';
                    while ($row = $commented_comics->fetch_assoc()) {
                        echo '<div style="background: rgba(255,255,255,0.1); padding: 1rem; border-radius: 10px; margin-bottom: 1rem; border: 1px solid rgba(255,255,255,0.2); position: relative;">
                                <div style="display: flex; align-items: flex-start; gap: 1rem;">
                                    <div style="flex-shrink: 0;">
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(45deg, #4ecdc4, #44a08d); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 0.8rem;">
                                            ' . strtoupper(substr($user['username'], 0, 2)) . '
                                        </div>
                                    </div>
                                    <div style="flex: 1;">
                                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                            <span style="background: #3498db; color: white; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.8rem; font-weight: bold;">
                                                ' . sanitize($user_progress['realm']) . '
                                            </span>
                                            <span style="background: #2ecc71; color: white; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.8rem;">
                                                ' . $user_progress['realm_stage'] . '
                                            </span>
                                            <span style="color: #3498db; font-size: 0.9rem; font-weight: bold;">
                                                <a href="?page=comic&id=' . $row['comic_id'] . '" style="color: #3498db; text-decoration: none;">
                                                    ' . sanitize($row['comic_title']) . '
                                                </a>
                                            </span>
                                        </div>
                                        <div style="display: flex; gap: 0.8rem; margin-bottom: 0.5rem;">
                                            <div style="flex: 1;">
                                                <div style="color: rgba(255,255,255,0.9); font-size: 0.9rem; line-height: 1.4; margin-bottom: 0.5rem;">
                                                    <strong>📊 Thống kê:</strong> ' . $row['chapter_count'] . ' chương • ' . $row['total_comments'] . ' bình luận
                                                </div>
                                                <div style="color: rgba(255,255,255,0.7); font-size: 0.85rem; line-height: 1.3;">
                                                    <strong>📖 Các chương:</strong> ' . (strlen($row['chapter_titles']) > 100 ? substr($row['chapter_titles'], 0, 100) . '...' : $row['chapter_titles']) . '
                                                </div>
                                            </div>
                                            <div style="flex-shrink: 0;">
                                                <img src="' . ($row['thumbnail'] ? sanitize($row['thumbnail']) : '/assets/images/no-image.svg') . '" 
                                                     alt="' . sanitize($row['comic_title']) . '" 
                                                     style="width: 50px; height: 60px; object-fit: cover; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2);">
                                            </div>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <span style="color: rgba(255,255,255,0.6); font-size: 0.8rem;">
                                                Bình luận cuối: ' . getTimeAgo($row['last_comment_date']) . '
                                            </span>
                                            <div style="display: flex; gap: 1.5rem; align-items: center;">
                                                <a href="?page=comic&id=' . $row['comic_id'] . '" style="color: rgba(255,255,255,0.7); text-decoration: none; font-size: 0.85rem; display: flex; align-items: center; gap: 0.3rem;">
                                                    📚 Xem truyện
                                                </a>
                                                <a href="?page=comic&id=' . $row['comic_id'] . '#comments" style="color: rgba(255,255,255,0.7); text-decoration: none; font-size: 0.85rem; display: flex; align-items: center; gap: 0.3rem;">
                                                    💬 Bình luận
                                                </a>
                                                <span style="background: rgba(231, 76, 60, 0.2); color: #e74c3c; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.75rem; cursor: pointer;" 
                                                      onclick="removeComicFromList(' . $row['comic_id'] . ')" 
                                                      title="Xóa khỏi danh sách">
                                                    🗑️ Xóa
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                              </div>';
                    }
                } else {
                    echo '<div style="text-align: center; padding: 2rem; color: rgba(255,255,255,0.6); background: rgba(255,255,255,0.05); border-radius: 10px; border: 1px dashed rgba(255,255,255,0.2);">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">💭</div>
                            <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">Chưa có bình luận nào</p>
                            <p style="font-size: 0.9rem; line-height: 1.4;">Hãy đọc truyện và để lại bình luận để tương tác với cộng đồng!</p>
                            <div style="margin-top: 1rem;">
                                <a href="?" style="color: #3498db; text-decoration: none; font-size: 0.9rem;">
                                    🏠 Về trang chủ để đọc truyện
                                </a>
                            </div>
                          </div>';
                }
                
                echo '</div>';
                break;

            case 'shop':
                if (!$user) {
                    echo '<div class="notification warning">Bạn cần <a href="?page=login">đăng nhập</a> để mua xu.</div>';
                    break;
                }
                
                // Handle coin purchase
                if ($_POST && isset($_POST['buy_coins'])) {
                    $package = $_POST['package'] ?? '';
                    $packages = [
                        'basic' => ['amount' => 100, 'price' => 10000, 'bonus' => 0],
                        'standard' => ['amount' => 500, 'price' => 45000, 'bonus' => 50],
                        'premium' => ['amount' => 1000, 'price' => 85000, 'bonus' => 150],
                        'vip' => ['amount' => 2000, 'price' => 160000, 'bonus' => 400]
                    ];
                    
                    if (isset($packages[$package])) {
                        $p = $packages[$package];
                        $total_coins = $p['amount'] + $p['bonus'];
                        
                        // Simulate payment process (in real app, integrate with payment gateway)
                        if (addCoins($mysqli, $user['id'], $total_coins, 'purchase', "Mua gói {$package}: {$p['amount']} xu + {$p['bonus']} xu thưởng")) {
                            $_SESSION['notification'] = ['type' => 'success', 'message' => "Đã mua thành công {$total_coins} xu! Cảm ơn bạn đã ủng hộ!"];
                        } else {
                            $_SESSION['notification'] = ['type' => 'error', 'message' => 'Có lỗi xảy ra trong quá trình thanh toán!'];
                        }
                        
                        header('Location: ?page=shop');
                        exit;
                    }
                }
                
                // Display session notifications
                if (isset($_SESSION['notification'])) {
                    $notif = $_SESSION['notification'];
                    echo '<div class="notification ' . $notif['type'] . '">' . $notif['message'] . '</div>';
                    unset($_SESSION['notification']);
                }
                
                // Get current user coins
                $current_user = getUser($mysqli);
                
                echo '<h1 class="page-title">💰 Cửa Hàng Xu</h1>';
                
                // Daily reward section
                $today = date('Y-m-d');
                $daily_check = $mysqli->query("
                    SELECT id FROM coin_transactions 
                    WHERE user_id = {$user['id']} AND type = 'daily_reward' 
                    AND DATE(created_at) = '$today'
                ");
                $has_claimed_today = $daily_check && $daily_check->num_rows > 0;
                
                echo '<div class="admin-section" style="background: linear-gradient(45deg, #2ecc71, #27ae60); border: none; margin-bottom: 2rem;">
                        <h2 style="color: white; margin-bottom: 1rem;">🎁 Phần Thưởng Hàng Ngày</h2>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <p style="color: white; margin-bottom: 0.5rem;">Nhận 10 xu miễn phí mỗi ngày!</p>
                                <p style="color: rgba(255,255,255,0.8); font-size: 0.9rem;">Đăng nhập hàng ngày để nhận phần thưởng</p>
                            </div>
                            <div>';
                            
                if (!$has_claimed_today) {
                    echo '<a href="?daily_reward=1" class="btn" style="background: rgba(255,255,255,0.9); color: #27ae60; font-weight: bold;">
                            🎁 Nhận 10 xu
                          </a>';
                } else {
                    echo '<span class="btn" style="background: rgba(255,255,255,0.3); color: white; cursor: not-allowed;">
                            ✅ Đã nhận hôm nay
                          </span>';
                }
                
                echo '    </div>
                        </div>
                      </div>';
                
                // Current balance
                echo '<div class="admin-section" style="text-align: center; margin-bottom: 2rem;">
                        <h3 style="color: #f39c12; margin-bottom: 1rem;">💳 Số Dư Hiện Tại</h3>
                        <div style="font-size: 2rem; font-weight: bold; color: #f39c12;">
                            ' . number_format($current_user['coins']) . ' xu
                        </div>
                      </div>';
                
                // Coin packages
                echo '<div class="admin-section">
                        <h2 class="section-title">📦 Gói Xu</h2>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-top: 1.5rem;">';
                
                $packages = [
                    'basic' => [
                        'name' => 'Gói Cơ Bản',
                        'amount' => 100,
                        'bonus' => 0,
                        'price' => 10000,
                        'color' => '#3498db',
                        'icon' => '💎'
                    ],
                    'standard' => [
                        'name' => 'Gói Tiêu Chuẩn',
                        'amount' => 500,
                        'bonus' => 50,
                        'price' => 45000,
                        'color' => '#9b59b6',
                        'icon' => '💜',
                        'popular' => true
                    ],
                    'premium' => [
                        'name' => 'Gói Cao Cấp',
                        'amount' => 1000,
                        'bonus' => 150,
                        'price' => 85000,
                        'color' => '#f39c12',
                        'icon' => '👑'
                    ],
                    'vip' => [
                        'name' => 'Gói VIP',
                        'amount' => 2000,
                        'bonus' => 400,
                        'price' => 160000,
                        'color' => '#e74c3c',
                        'icon' => '🔥',
                        'best_value' => true
                    ]
                ];
                
                foreach ($packages as $key => $package) {
                    $total_coins = $package['amount'] + $package['bonus'];
                    $savings = $package['bonus'] > 0 ? round(($package['bonus'] / $package['amount']) * 100) : 0;
                    
                    echo '<div style="background: linear-gradient(145deg, ' . $package['color'] . ', ' . $package['color'] . '88); 
                                     padding: 1.5rem; border-radius: 15px; text-align: center; color: white; position: relative;
                                     box-shadow: 0 8px 25px rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.2);">';
                    
                    if (isset($package['popular'])) {
                        echo '<div style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); 
                                     background: #27ae60; color: white; padding: 0.3rem 1rem; border-radius: 15px; 
                                     font-size: 0.8rem; font-weight: bold;">PHỔ BIẾN</div>';
                    }
                    
                    if (isset($package['best_value'])) {
                        echo '<div style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); 
                                     background: #e67e22; color: white; padding: 0.3rem 1rem; border-radius: 15px; 
                                     font-size: 0.8rem; font-weight: bold;">GIÁ TRỊ NHẤT</div>';
                    }
                    
                    echo '<div style="font-size: 2.5rem; margin-bottom: 0.5rem;">' . $package['icon'] . '</div>
                          <h3 style="margin-bottom: 1rem;">' . $package['name'] . '</h3>
                          <div style="font-size: 1.5rem; font-weight: bold; margin-bottom: 0.5rem;">
                            ' . number_format($package['amount']) . ' xu';
                    
                    if ($package['bonus'] > 0) {
                        echo ' <span style="color: #2ecc71;">+ ' . $package['bonus'] . '</span>';
                    }
                    
                    echo '</div>';
                    
                    if ($savings > 0) {
                        echo '<div style="background: rgba(46, 204, 113, 0.3); color: #2ecc71; padding: 0.3rem 0.8rem; 
                                     border-radius: 15px; font-size: 0.8rem; margin-bottom: 1rem; display: inline-block;">
                                Tiết kiệm ' . $savings . '%
                              </div>';
                    }
                    
                    echo '<div style="font-size: 1.2rem; margin-bottom: 1.5rem; color: rgba(255,255,255,0.9);">
                            ' . number_format($package['price']) . ' VNĐ
                          </div>
                          <form method="POST" style="margin: 0;">
                            <input type="hidden" name="package" value="' . $key . '">
                            <button type="submit" name="buy_coins" class="btn" style="background: rgba(255,255,255,0.9); 
                                                                                      color: ' . $package['color'] . '; 
                                                                                      font-weight: bold; width: 100%;">
                              💳 Mua Ngay
                            </button>
                          </form>
                        </div>';
                }
                
                echo '</div>
                      </div>';
                
                // Transaction history
                echo '<div class="admin-section" style="margin-top: 2rem;">
                        <h2 class="section-title">📜 Lịch Sử Giao Dịch</h2>';
                
                $transactions = $mysqli->query("
                    SELECT ct.*, ch.chapter_title 
                    FROM coin_transactions ct 
                    LEFT JOIN chapters ch ON ct.chapter_id = ch.id 
                    WHERE ct.user_id = {$user['id']} 
                    ORDER BY ct.created_at DESC 
                    LIMIT 10
                ");
                
                if ($transactions && $transactions->num_rows > 0) {
                    echo '<div style="margin-top: 1rem;">';
                    while ($transaction = $transactions->fetch_assoc()) {
                        $amount_color = $transaction['amount'] > 0 ? '#2ecc71' : '#e74c3c';
                        $amount_sign = $transaction['amount'] > 0 ? '+' : '';
                        $type_icon = [
                            'purchase' => '💳',
                            'unlock_chapter' => '🔓',
                            'daily_reward' => '🎁',
                            'refund' => '↩️'
                        ][$transaction['type']] ?? '💰';
                        
                        echo '<div style="display: flex; justify-content: space-between; align-items: center; 
                                     padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 10px; 
                                     margin-bottom: 0.5rem; border-left: 4px solid ' . $amount_color . ';">
                                <div>
                                  <div style="font-weight: bold; margin-bottom: 0.3rem;">
                                    ' . $type_icon . ' ' . sanitize($transaction['description']) . '
                                  </div>
                                  <div style="color: rgba(255,255,255,0.7); font-size: 0.8rem;">
                                    ' . date('d/m/Y H:i', strtotime($transaction['created_at'])) . '
                                  </div>
                                </div>
                                <div style="font-weight: bold; font-size: 1.1rem; color: ' . $amount_color . ';">
                                  ' . $amount_sign . number_format($transaction['amount']) . ' xu
                                </div>
                              </div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="notification warning">Chưa có giao dịch nào.</div>';
                }
                
                echo '</div>';
                break;
                
            case 'rankings':
                // Update rankings daily
                calculateComicRankings($mysqli, 'daily');
                calculateComicRankings($mysqli, 'weekly');
                calculateComicRankings($mysqli, 'monthly');
                
                $rank_type = $_GET['type'] ?? 'daily';
                if (!in_array($rank_type, ['daily', 'weekly', 'monthly'])) {
                    $rank_type = 'daily';
                }
                
                echo '<h1 class="page-title">🏆 Bảng Xếp Hạng Truyện</h1>';
                
                echo '<div class="ranking-tabs">
                        <button class="tab-btn ' . ($rank_type == 'daily' ? 'active' : '') . '" onclick="location.href=\'?page=rankings&type=daily\'">📅 Ngày</button>
                        <button class="tab-btn ' . ($rank_type == 'weekly' ? 'active' : '') . '" onclick="location.href=\'?page=rankings&type=weekly\'">📅 Tuần</button>
                        <button class="tab-btn ' . ($rank_type == 'monthly' ? 'active' : '') . '" onclick="location.href=\'?page=rankings&type=monthly\'">📅 Tháng</button>
                      </div>';
                
                $rankings = getRankingData($mysqli, $rank_type, 20);
                
                if ($rankings && $rankings->num_rows > 0) {
                    echo '<div class="ranking-list">';
                    while ($ranking = $rankings->fetch_assoc()) {
                        $rank_class = '';
                        if ($ranking['rank_position'] == 1) $rank_class = 'top-1';
                        elseif ($ranking['rank_position'] == 2) $rank_class = 'top-2';
                        elseif ($ranking['rank_position'] == 3) $rank_class = 'top-3';
                        else $rank_class = 'other';
                        
                        $thumbnail = $ranking['thumbnail'] ?: '/assets/images/no-image.svg';
                        
                        echo '<div class="ranking-item">
                                <div class="rank-number ' . $rank_class . '">' . $ranking['rank_position'] . '</div>
                                <div class="ranking-content">
                                    <img src="' . sanitize($thumbnail) . '" class="ranking-thumbnail" alt="' . sanitize($ranking['title']) . '">
                                    <div class="ranking-info">
                                        <div class="ranking-title">
                                            <a href="?page=comic&id=' . $ranking['id'] . '" style="color: inherit; text-decoration: none;">
                                                ' . sanitize($ranking['title']) . '
                                            </a>
                                        </div>
                                        <div class="ranking-stats">
                                            <div class="stat-item">
                                                <span>👁️</span>
                                                <span>' . number_format($ranking['views_count']) . '</span>
                                            </div>
                                            <div class="stat-item">
                                                <span>❤️</span>
                                                <span>' . number_format($ranking['favorites_count']) . '</span>
                                            </div>
                                            <div class="stat-item">
                                                <span>💬</span>
                                                <span>' . number_format($ranking['comments_count']) . '</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                              </div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="notification warning">Chưa có dữ liệu xếp hạng cho thời gian này.</div>';
                }
                break;
                
            case 'top_users':
                // Update user rankings daily
                calculateUserRankings($mysqli);
                
                $user_type = $_GET['type'] ?? 'cao_thu';
                if (!in_array($user_type, ['cao_thu', 'ty_phu'])) {
                    $user_type = 'cao_thu';
                }
                
                echo '<h1 class="page-title">👑 Top Cao Thủ</h1>';
                
                echo '<div class="ranking-tabs">
                        <button class="tab-btn ' . ($user_type == 'cao_thu' ? 'active' : '') . '" onclick="location.href=\'?page=top_users&type=cao_thu\'">⚔️ Cao Thủ</button>
                        <button class="tab-btn ' . ($user_type == 'ty_phu' ? 'active' : '') . '" onclick="location.href=\'?page=top_users&type=ty_phu\'">💰 Tỷ Phú</button>
                      </div>';
                
                $user_rankings = getUserRankingData($mysqli, $user_type, 20);
                
                if ($user_rankings && $user_rankings->num_rows > 0) {
                    echo '<div style="display: flex; flex-direction: column; gap: 0.5rem;">';
                    while ($user_rank = $user_rankings->fetch_assoc()) {
                        $rank_class = '';
                        if ($user_rank['rank_position'] == 1) $rank_class = 'top-1';
                        elseif ($user_rank['rank_position'] == 2) $rank_class = 'top-2';
                        elseif ($user_rank['rank_position'] == 3) $rank_class = 'top-3';
                        else $rank_class = 'other';
                        
                        $avatar_text = strtoupper(substr($user_rank['username'], 0, 2));
                        
                        echo '<div class="user-ranking-item">
                                <div class="rank-number ' . $rank_class . '">' . $user_rank['rank_position'] . '</div>
                                <div class="user-avatar">' . $avatar_text . '</div>
                                <div class="user-info">
                                    <div class="username">' . sanitize($user_rank['username']) . '</div>
                                    <div class="user-stats">';
                        
                        if ($user_type == 'cao_thu') {
                            echo '<div class="stat-item">
                                    <span>' . getRealmBadge($user_rank['realm'], $user_rank['realm_stage']) . '</span>
                                  </div>
                                  <div class="stat-item">
                                    <span>⚡</span>
                                    <span>' . number_format($user_rank['total_qi_earned']) . ' Linh Khí</span>
                                  </div>';
                        } else {
                            echo '<div class="stat-item">
                                    <span>💰</span>
                                    <span>' . number_format($user_rank['coins']) . ' xu</span>
                                  </div>';
                        }
                        
                        echo '        </div>
                                </div>
                              </div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="notification warning">Chưa có dữ liệu xếp hạng người dùng.</div>';
                }
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
            
            // Setup chapter navigation shortcuts
            setupChapterNavigation();
            
            // Setup lazy loading
            setupLazyLoading();
            
            // Preload next chapter
            preloadNextChapter();
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
        
        // Toggle options dropdown
        function toggleOptions(commentId) {
            const dropdown = document.getElementById('options-' + commentId);
            const isVisible = dropdown.style.display === 'block';
            
            // Hide all other dropdowns
            document.querySelectorAll('[id^="options-"]').forEach(el => {
                el.style.display = 'none';
            });
            
            // Toggle current dropdown
            dropdown.style.display = isVisible ? 'none' : 'block';
        }
        
        // Edit comment function
        function editComment(commentId) {
            alert('Tính năng chỉnh sửa bình luận sẽ được phát triển trong tương lai!');
            toggleOptions(commentId);
        }
        
        // Delete comment function
        function deleteComment(commentId) {
            if (confirm('Bạn có chắc chắn muốn xóa bình luận này không?')) {
                window.location.href = '?delete_comment=' + commentId;
            }
            toggleOptions(commentId);
        }
        
        // Remove comic from commented list
        function removeComicFromList(comicId) {
            if (confirm('Bạn có chắc chắn muốn xóa tất cả bình luận của truyện này khỏi danh sách không?')) {
                window.location.href = '?remove_comic_comments=' + comicId;
            }
        }
        
        // Toggle VIP options in admin
        function toggleVipOptions() {
            const checkbox = document.getElementById('is_vip');
            const options = document.getElementById('vip_options');
            if (options) {
                options.style.display = checkbox.checked ? 'block' : 'none';
            }
        }
        
        // Chapter navigation with keyboard shortcuts
        function setupChapterNavigation() {
            document.addEventListener('keydown', function(e) {
                // Only work on chapter pages
                if (!window.location.search.includes('page=chapter')) return;
                
                // Prevent shortcuts when user is typing
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                
                switch(e.key) {
                    case 'ArrowLeft':
                    case 'a':
                    case 'A':
                        e.preventDefault();
                        const prevChapter = document.querySelector('.chapter-navigation a[title*="trước"]');
                        if (prevChapter && !prevChapter.closest('span')) {
                            window.location.href = prevChapter.href;
                        }
                        break;
                        
                    case 'ArrowRight':
                    case 'd':
                    case 'D':
                        e.preventDefault();
                        const nextChapter = document.querySelector('.chapter-navigation a[title*="sau"]');
                        if (nextChapter && !nextChapter.closest('span')) {
                            window.location.href = nextChapter.href;
                        }
                        break;
                        
                    case 'Escape':
                        e.preventDefault();
                        const comicLink = document.querySelector('a[href*="page=comic"]:not([href*="page=comic&"])');
                        if (comicLink) {
                            window.location.href = comicLink.href;
                        }
                        break;
                        
                    case '?':
                        e.preventDefault();
                        const helpDiv = document.getElementById('shortcuts-help');
                        if (helpDiv) {
                            helpDiv.classList.toggle('show');
                        }
                        break;
                }
            });
            
            // Show shortcuts help for 3 seconds on load
            setTimeout(() => {
                const helpDiv = document.getElementById('shortcuts-help');
                if (helpDiv) {
                    helpDiv.classList.add('show');
                    setTimeout(() => {
                        helpDiv.classList.remove('show');
                    }, 3000);
                }
            }, 1000);
        }
        
        // Lazy loading for images
        function setupLazyLoading() {
            if ('IntersectionObserver' in window) {
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            if (img.dataset.src) {
                                img.src = img.dataset.src;
                                img.removeAttribute('data-src');
                                img.classList.add('loaded');
                                observer.unobserve(img);
                            }
                        }
                    });
                }, {
                    rootMargin: '50px 0px',
                    threshold: 0.1
                });
                
                document.querySelectorAll('img[data-src]').forEach(img => {
                    imageObserver.observe(img);
                });
            }
        }
        
        // Preload next chapter images
        function preloadNextChapter() {
            const nextChapterLink = document.querySelector('.chapter-navigation a[title*="sau"]');
            if (nextChapterLink && !nextChapterLink.closest('span')) {
                // Preload next chapter in background
                const link = document.createElement('link');
                link.rel = 'prefetch';
                link.href = nextChapterLink.href;
                document.head.appendChild(link);
            }
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('[onclick*="toggleOptions"]') && !event.target.closest('[id^="options-"]')) {
                document.querySelectorAll('[id^="options-"]').forEach(el => {
                    el.style.display = 'none';
                });
            }
        });
    </script>
</body>
</html>
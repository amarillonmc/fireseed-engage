<?php
// 包含初始化文件
require_once 'includes/init.php';

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 获取用户信息
$user = new User($_SESSION['user_id']);
if (!$user->isValid()) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// 获取排名类型
$rankingType = isset($_GET['type']) ? $_GET['type'] : 'level';
$validTypes = ['level', 'cities', 'generals', 'combat_power', 'resources'];
if (!in_array($rankingType, $validTypes)) {
    $rankingType = 'level';
}

// 获取分页参数
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// 获取排名数据
function getRankingData($type, $limit, $offset) {
    $db = Database::getInstance()->getConnection();
    
    switch ($type) {
        case 'level':
            $query = "SELECT u.user_id, u.username, u.level, u.created_at,
                             (SELECT COUNT(*) FROM cities c WHERE c.owner_id = u.user_id) as city_count
                      FROM users u 
                      ORDER BY u.level DESC, u.created_at ASC 
                      LIMIT ? OFFSET ?";
            break;
            
        case 'cities':
            $query = "SELECT u.user_id, u.username, u.level, u.created_at,
                             COUNT(c.city_id) as city_count
                      FROM users u 
                      LEFT JOIN cities c ON u.user_id = c.owner_id
                      GROUP BY u.user_id 
                      ORDER BY city_count DESC, u.level DESC 
                      LIMIT ? OFFSET ?";
            break;
            
        case 'generals':
            $query = "SELECT u.user_id, u.username, u.level, u.created_at,
                             COUNT(g.general_id) as general_count,
                             (SELECT COUNT(*) FROM cities c WHERE c.owner_id = u.user_id) as city_count
                      FROM users u 
                      LEFT JOIN generals g ON u.user_id = g.owner_id
                      GROUP BY u.user_id 
                      ORDER BY general_count DESC, u.level DESC 
                      LIMIT ? OFFSET ?";
            break;
            
        case 'combat_power':
            $query = "SELECT u.user_id, u.username, u.level, u.created_at,
                             COALESCE(SUM(au.quantity * 
                                 CASE au.type 
                                     WHEN 'pawn' THEN 1
                                     WHEN 'knight' THEN 2
                                     WHEN 'rook' THEN 2
                                     WHEN 'bishop' THEN 4
                                     WHEN 'golem' THEN 1
                                     WHEN 'scout' THEN 0
                                     ELSE 1
                                 END * au.level
                             ), 0) as combat_power,
                             (SELECT COUNT(*) FROM cities c WHERE c.owner_id = u.user_id) as city_count
                      FROM users u 
                      LEFT JOIN armies a ON u.user_id = a.owner_id
                      LEFT JOIN army_units au ON a.army_id = au.army_id
                      GROUP BY u.user_id 
                      ORDER BY combat_power DESC, u.level DESC 
                      LIMIT ? OFFSET ?";
            break;
            
        case 'resources':
            $query = "SELECT u.user_id, u.username, u.level, u.created_at,
                             (r.bright_crystal + r.warm_crystal + r.cold_crystal + 
                              r.green_crystal + r.day_crystal + r.night_crystal) as total_resources,
                             (SELECT COUNT(*) FROM cities c WHERE c.owner_id = u.user_id) as city_count
                      FROM users u 
                      LEFT JOIN resources r ON u.user_id = r.user_id
                      ORDER BY total_resources DESC, u.level DESC 
                      LIMIT ? OFFSET ?";
            break;
            
        default:
            return [];
    }
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rankings = [];
    $rank = $offset + 1;
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['rank'] = $rank++;
            $rankings[] = $row;
        }
    }
    
    $stmt->close();
    return $rankings;
}

// 获取总用户数
function getTotalUsers() {
    $db = Database::getInstance()->getConnection();
    $query = "SELECT COUNT(*) as total FROM users";
    $result = $db->query($query);
    $row = $result->fetch_assoc();
    return $row['total'];
}

$rankings = getRankingData($rankingType, $limit, $offset);
$totalUsers = getTotalUsers();
$totalPages = ceil($totalUsers / $limit);

// 页面标题
$pageTitle = '排行榜';

// 排名类型名称映射
$typeNames = [
    'level' => '等级排行',
    'cities' => '城池排行',
    'generals' => '武将排行',
    'combat_power' => '战力排行',
    'resources' => '资源排行'
];
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .ranking-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .ranking-header {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .ranking-title {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .ranking-subtitle {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .ranking-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .ranking-tab {
            padding: 10px 20px;
            background: #ecf0f1;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            color: #2c3e50;
            transition: background-color 0.3s;
            font-size: 14px;
        }
        
        .ranking-tab.active {
            background: #3498db;
            color: white;
        }
        
        .ranking-tab:hover {
            background: #bdc3c7;
        }
        
        .ranking-tab.active:hover {
            background: #2980b9;
        }
        
        .ranking-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .ranking-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .ranking-table th {
            background: #34495e;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: bold;
        }
        
        .ranking-table td {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .ranking-table tr:last-child td {
            border-bottom: none;
        }
        
        .ranking-table tr:hover {
            background: #f8f9fa;
        }
        
        .rank-number {
            font-weight: bold;
            font-size: 18px;
            width: 60px;
            text-align: center;
        }
        
        .rank-1 { color: #f39c12; }
        .rank-2 { color: #95a5a6; }
        .rank-3 { color: #cd7f32; }
        
        .rank-medal {
            font-size: 20px;
            margin-right: 5px;
        }
        
        .username {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .username.current-user {
            color: #e74c3c;
            background: #ffebee;
            padding: 2px 8px;
            border-radius: 3px;
        }
        
        .user-level {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .stat-value {
            font-weight: bold;
            color: #27ae60;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #2c3e50;
        }
        
        .pagination a:hover {
            background: #f8f9fa;
        }
        
        .pagination .current {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }
        
        .pagination .disabled {
            color: #bdc3c7;
            cursor: not-allowed;
        }
        
        .no-data {
            text-align: center;
            color: #7f8c8d;
            padding: 40px;
            background: white;
            border-radius: 8px;
        }
        
        @media (max-width: 768px) {
            .ranking-tabs {
                justify-content: center;
            }
            
            .ranking-tab {
                font-size: 12px;
                padding: 8px 12px;
            }
            
            .ranking-table {
                overflow-x: auto;
            }
            
            .ranking-table th,
            .ranking-table td {
                padding: 10px 8px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- 页首 -->
        <header>
            <h1 class="site-title"><?php echo SITE_NAME; ?></h1>
            <h2 class="page-title"><?php echo $pageTitle; ?></h2>
            <nav class="main-nav">
                <ul>
                    <li><a href="index.php">主基地</a></li>
                    <li><a href="profile.php">档案</a></li>
                    <li><a href="generals.php">武将</a></li>
                    <li><a href="armies.php">军队</a></li>
                    <li><a href="map.php">地图</a></li>
                    <li><a href="territory.php">领地</a></li>
                    <li><a href="internal.php">内政</a></li>
                    <li><a href="ranking.php">排名</a></li>
                    <li class="circuit-points">思考回路: <?php echo $user->getCircuitPoints(); ?> / <?php echo $user->getMaxCircuitPoints(); ?></li>
                </ul>
            </nav>
        </header>

        <!-- 主要内容 -->
        <main>
            <div class="ranking-container">
                <!-- 排行榜头部 -->
                <div class="ranking-header">
                    <div class="ranking-title">🏆 <?php echo $typeNames[$rankingType]; ?></div>
                    <div class="ranking-subtitle">数据之海钻探者排行榜</div>
                </div>

                <!-- 排名类别标签 -->
                <div class="ranking-tabs">
                    <?php foreach ($validTypes as $type): ?>
                    <a href="ranking.php?type=<?php echo $type; ?>" 
                       class="ranking-tab <?php echo $rankingType == $type ? 'active' : ''; ?>">
                        <?php echo $typeNames[$type]; ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- 排名表格 -->
                <?php if (!empty($rankings)): ?>
                <div class="ranking-table">
                    <table>
                        <thead>
                            <tr>
                                <th>排名</th>
                                <th>用户</th>
                                <th>等级</th>
                                <th>城池</th>
                                <?php if ($rankingType == 'generals'): ?>
                                <th>武将数量</th>
                                <?php elseif ($rankingType == 'combat_power'): ?>
                                <th>战斗力</th>
                                <?php elseif ($rankingType == 'resources'): ?>
                                <th>总资源</th>
                                <?php endif; ?>
                                <th>注册时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rankings as $ranking): ?>
                            <tr>
                                <td class="rank-number">
                                    <?php if ($ranking['rank'] <= 3): ?>
                                    <span class="rank-medal">
                                        <?php echo $ranking['rank'] == 1 ? '🥇' : ($ranking['rank'] == 2 ? '🥈' : '🥉'); ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="rank-<?php echo min($ranking['rank'], 3); ?>">
                                        <?php echo $ranking['rank']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="username <?php echo $ranking['user_id'] == $user->getUserId() ? 'current-user' : ''; ?>">
                                        <?php echo htmlspecialchars($ranking['username']); ?>
                                        <?php if ($ranking['user_id'] == $user->getUserId()): ?>
                                        (你)
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="user-level">Lv.<?php echo $ranking['level']; ?></span>
                                </td>
                                <td>
                                    <span class="stat-value"><?php echo $ranking['city_count']; ?></span>
                                </td>
                                <?php if ($rankingType == 'generals'): ?>
                                <td>
                                    <span class="stat-value"><?php echo $ranking['general_count']; ?></span>
                                </td>
                                <?php elseif ($rankingType == 'combat_power'): ?>
                                <td>
                                    <span class="stat-value"><?php echo number_format($ranking['combat_power']); ?></span>
                                </td>
                                <?php elseif ($rankingType == 'resources'): ?>
                                <td>
                                    <span class="stat-value"><?php echo number_format($ranking['total_resources']); ?></span>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php echo date('Y-m-d', strtotime($ranking['created_at'])); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 分页 -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="ranking.php?type=<?php echo $rankingType; ?>&page=<?php echo $page - 1; ?>">上一页</a>
                    <?php else: ?>
                    <span class="disabled">上一页</span>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                    <?php if ($i == $page): ?>
                    <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="ranking.php?type=<?php echo $rankingType; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="ranking.php?type=<?php echo $rankingType; ?>&page=<?php echo $page + 1; ?>">下一页</a>
                    <?php else: ?>
                    <span class="disabled">下一页</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="no-data">
                    <h3>暂无排名数据</h3>
                    <p>还没有用户数据可以显示。</p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- 页脚 -->
        <footer>
            <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?> - 版本 <?php echo GAME_VERSION; ?></p>
        </footer>
    </div>
</body>
</html>

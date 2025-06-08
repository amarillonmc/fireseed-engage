<?php
// 包含初始化文件
require_once '../includes/init.php';

// 检查用户是否已登录且为管理员
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid() || !$user->isAdmin()) {
    header('Location: ../index.php');
    exit;
}

// 创建管理员管理器
$adminManager = new AdminManager($user);

// 获取系统统计信息
$totalUsers = User::getTotalUserCount();
$totalCities = 0;
$totalGenerals = 0;
$totalArmies = 0;

// 获取城池总数
$db = Database::getInstance()->getConnection();
$result = $db->query("SELECT COUNT(*) as total FROM cities");
if ($result) {
    $row = $result->fetch_assoc();
    $totalCities = $row['total'];
}

// 获取武将总数
$result = $db->query("SELECT COUNT(*) as total FROM generals");
if ($result) {
    $row = $result->fetch_assoc();
    $totalGenerals = $row['total'];
}

// 获取军队总数
$result = $db->query("SELECT COUNT(*) as total FROM armies");
if ($result) {
    $row = $result->fetch_assoc();
    $totalArmies = $row['total'];
}

// 获取最近的管理员日志
$recentLogs = $adminManager->getAdminLogs(10);

// 获取游戏配置
$gameConfig = new GameConfig();
$maintenanceMode = GameConfig::get('maintenance_mode', 0);
$newPlayerRegistration = GameConfig::get('new_player_registration', 1);

$pageTitle = '管理后台';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .admin-header {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .admin-title {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .admin-subtitle {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .admin-nav {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .nav-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            text-decoration: none;
            color: #2c3e50;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .nav-link:hover {
            background: #e9ecef;
            border-color: #3498db;
            transform: translateY(-2px);
        }
        
        .nav-icon {
            font-size: 24px;
            margin-right: 10px;
        }
        
        .nav-text {
            font-weight: bold;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stats-card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stats-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .stats-number {
            font-size: 36px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .stats-label {
            font-size: 16px;
            color: #7f8c8d;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }
        
        .content-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .section-title {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .section-icon {
            font-size: 24px;
            margin-right: 10px;
        }
        
        .log-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .log-item:last-child {
            border-bottom: none;
        }
        
        .log-action {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .log-admin {
            color: #3498db;
            font-size: 14px;
        }
        
        .log-time {
            color: #7f8c8d;
            font-size: 12px;
        }
        
        .quick-actions {
            display: grid;
            gap: 15px;
        }
        
        .action-button {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        
        .action-button:hover {
            background: #2980b9;
        }
        
        .action-button.warning {
            background: #f39c12;
        }
        
        .action-button.warning:hover {
            background: #e67e22;
        }
        
        .action-button.danger {
            background: #e74c3c;
        }
        
        .action-button.danger:hover {
            background: #c0392b;
        }
        
        .action-button.success {
            background: #27ae60;
        }
        
        .action-button.success:hover {
            background: #229954;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-online {
            background: #27ae60;
        }
        
        .status-maintenance {
            background: #f39c12;
        }
        
        .status-offline {
            background: #e74c3c;
        }
        
        .admin-level {
            background: #9b59b6;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-links {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- 管理后台头部 -->
        <div class="admin-container">
            <div class="admin-header">
                <div class="admin-title">🛡️ 管理后台</div>
                <div class="admin-subtitle">
                    欢迎，<?php echo htmlspecialchars($user->getUsername()); ?>
                    <span class="admin-level"><?php echo AdminManager::getAdminLevelName($user->getAdminLevel()); ?></span>
                </div>
            </div>

            <!-- 导航菜单 -->
            <div class="admin-nav">
                <div class="nav-links">
                    <a href="users.php" class="nav-link">
                        <span class="nav-icon">👥</span>
                        <span class="nav-text">用户管理</span>
                    </a>
                    <a href="config.php" class="nav-link">
                        <span class="nav-icon">⚙️</span>
                        <span class="nav-text">系统配置</span>
                    </a>
                    <a href="map.php" class="nav-link">
                        <span class="nav-icon">🗺️</span>
                        <span class="nav-text">地图管理</span>
                    </a>
                    <a href="logs.php" class="nav-link">
                        <span class="nav-icon">📋</span>
                        <span class="nav-text">操作日志</span>
                    </a>
                    <a href="game_data.php" class="nav-link">
                        <span class="nav-icon">🎮</span>
                        <span class="nav-text">游戏数据</span>
                    </a>
                    <a href="maintenance.php" class="nav-link">
                        <span class="nav-icon">🔧</span>
                        <span class="nav-text">系统维护</span>
                    </a>
                    <a href="../index.php" class="nav-link">
                        <span class="nav-icon">🏠</span>
                        <span class="nav-text">返回游戏</span>
                    </a>
                    <a href="../logout.php" class="nav-link">
                        <span class="nav-icon">🚪</span>
                        <span class="nav-text">退出登录</span>
                    </a>
                </div>
            </div>

            <!-- 统计数据 -->
            <div class="stats-grid">
                <div class="stats-card">
                    <div class="stats-icon">👥</div>
                    <div class="stats-number"><?php echo number_format($totalUsers); ?></div>
                    <div class="stats-label">注册用户</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">🏰</div>
                    <div class="stats-number"><?php echo number_format($totalCities); ?></div>
                    <div class="stats-label">城池总数</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">⚔️</div>
                    <div class="stats-number"><?php echo number_format($totalGenerals); ?></div>
                    <div class="stats-label">武将总数</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">🚀</div>
                    <div class="stats-number"><?php echo number_format($totalArmies); ?></div>
                    <div class="stats-label">军队总数</div>
                </div>
            </div>

            <!-- 主要内容 -->
            <div class="content-grid">
                <!-- 最近操作日志 -->
                <div class="content-section">
                    <div class="section-title">
                        <span class="section-icon">📋</span>
                        最近操作日志
                    </div>
                    
                    <?php if (!empty($recentLogs)): ?>
                    <?php foreach ($recentLogs as $log): ?>
                    <div class="log-item">
                        <div>
                            <div class="log-action"><?php echo htmlspecialchars($log['action']); ?></div>
                            <div class="log-admin">管理员: <?php echo htmlspecialchars($log['admin_username']); ?></div>
                            <?php if ($log['details']): ?>
                            <div style="font-size: 12px; color: #7f8c8d; margin-top: 2px;">
                                <?php echo htmlspecialchars($log['details']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="log-time"><?php echo date('m-d H:i', strtotime($log['created_at'])); ?></div>
                    </div>
                    <?php endforeach; ?>
                    
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="logs.php" class="action-button">查看全部日志</a>
                    </div>
                    <?php else: ?>
                    <div style="text-align: center; color: #7f8c8d; padding: 20px;">
                        暂无操作日志
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 快捷操作 -->
                <div class="content-section">
                    <div class="section-title">
                        <span class="section-icon">⚡</span>
                        快捷操作
                    </div>
                    
                    <div class="quick-actions">
                        <!-- 系统状态 -->
                        <div style="padding: 15px; background: #f8f9fa; border-radius: 8px; margin-bottom: 15px;">
                            <div style="font-weight: bold; margin-bottom: 10px;">系统状态</div>
                            <div style="display: flex; align-items: center; margin-bottom: 5px;">
                                <span class="status-indicator <?php echo $maintenanceMode ? 'status-maintenance' : 'status-online'; ?>"></span>
                                <span><?php echo $maintenanceMode ? '维护模式' : '正常运行'; ?></span>
                            </div>
                            <div style="display: flex; align-items: center;">
                                <span class="status-indicator <?php echo $newPlayerRegistration ? 'status-online' : 'status-offline'; ?>"></span>
                                <span><?php echo $newPlayerRegistration ? '开放注册' : '关闭注册'; ?></span>
                            </div>
                        </div>
                        
                        <?php if ($adminManager->hasPermission('edit_game_config')): ?>
                        <a href="config.php" class="action-button">
                            <span style="margin-right: 10px;">⚙️</span>
                            系统配置
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($adminManager->hasPermission('view_users')): ?>
                        <a href="users.php" class="action-button">
                            <span style="margin-right: 10px;">👥</span>
                            用户管理
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($adminManager->hasPermission('manage_map')): ?>
                        <a href="map.php" class="action-button warning">
                            <span style="margin-right: 10px;">🗺️</span>
                            重新生成地图
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($adminManager->hasPermission('reset_game')): ?>
                        <a href="maintenance.php" class="action-button danger">
                            <span style="margin-right: 10px;">🔄</span>
                            游戏重置
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($adminManager->hasPermission('system_maintenance')): ?>
                        <a href="maintenance.php" class="action-button success">
                            <span style="margin-right: 10px;">🔧</span>
                            系统维护
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

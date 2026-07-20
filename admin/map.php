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

// 检查权限
if (!$adminManager->hasPermission('manage_map')) {
    die('您没有权限访问此页面');
}

$error = '';
$success = '';

// 所有地图管理变更都要求POST与CSRF令牌 / Require POST and a CSRF token for every map-admin mutation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken()) {
    $error = '请求已过期，请刷新页面后重试 / Request expired; refresh and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'generate_map':
            $forceRegenerate = isset($_POST['force_regenerate']);
            
            try {
                $mapGenerator = new MapGenerator();
                $result = $mapGenerator->generateMap($forceRegenerate);
                
                if ($result === true) {
                    $success = '地图生成成功！';
                    $user->logAdminAction('generate_map', 'map', null, 
                        $forceRegenerate ? 'Force regenerate' : 'Generate new');
                } else {
                    $error = '地图生成失败: ' . $result;
                }
            } catch (Exception $e) {
                $error = '地图生成失败: ' . $e->getMessage();
            }
            break;
            
        case 'reset_npc_cities':
            try {
                $respawnedCount = Map::respawnAllNpcForts();
                $success = "已重生 $respawnedCount 个NPC城池！";
                $user->logAdminAction('reset_npc_cities', 'map', null, "Respawned $respawnedCount cities");
            } catch (Exception $e) {
                $error = '重生NPC城池失败: ' . $e->getMessage();
            }
            break;
    }
}

// 获取地图统计信息
$db = Database::getInstance()->getConnection();

$mapStats = [];

// 总格子数
$result = $db->query("SELECT COUNT(*) as total FROM map_tiles");
$mapStats['total_tiles'] = $result ? $result->fetch_assoc()['total'] : 0;

// 空地数量
$result = $db->query("SELECT COUNT(*) as count FROM map_tiles WHERE type = 'empty'");
$mapStats['empty_tiles'] = $result ? $result->fetch_assoc()['count'] : 0;

// 资源点数量
$result = $db->query("SELECT COUNT(*) as count FROM map_tiles WHERE type = 'resource'");
$mapStats['resource_tiles'] = $result ? $result->fetch_assoc()['count'] : 0;

// NPC城池数量
$result = $db->query("SELECT COUNT(*) as count FROM map_tiles WHERE type = 'npc_fort'");
$mapStats['npc_forts'] = $result ? $result->fetch_assoc()['count'] : 0;

// 玩家城池数量
$result = $db->query("SELECT COUNT(*) as count FROM cities WHERE owner_id > 0");
$mapStats['player_cities'] = $result ? $result->fetch_assoc()['count'] : 0;

// 银白之孔
$result = $db->query(
    "SELECT COUNT(*) as count
     FROM map_tiles
     WHERE type = 'special' AND subtype = 'silver_hole'"
);
$mapStats['silver_holes'] = $result ? $result->fetch_assoc()['count'] : 0;

// 被占领的格子数量
$result = $db->query("SELECT COUNT(*) as count FROM map_tiles WHERE owner_id > 0");
$mapStats['occupied_tiles'] = $result ? $result->fetch_assoc()['count'] : 0;

$pageTitle = '地图管理';
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .admin-header {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title {
            font-size: 24px;
            font-weight: bold;
        }
        
        .back-link {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stats-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stats-icon {
            font-size: 36px;
            margin-bottom: 10px;
        }
        
        .stats-number {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .stats-label {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .actions-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
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
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .action-card {
            border: 1px solid #ecf0f1;
            border-radius: 8px;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .action-title {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .action-desc {
            color: #7f8c8d;
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.4;
        }
        
        .action-button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-warning:hover {
            background: #e67e22;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .warning-box {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            border-left: 4px solid #ffc107;
        }
        
        .danger-box {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            border-left: 4px solid #e74c3c;
        }
        
        .checkbox-group {
            margin: 15px 0;
        }
        
        .checkbox-group label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .checkbox-group input[type="checkbox"] {
            margin-right: 8px;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #c62828;
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #2e7d32;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .action-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="admin-container">
            <!-- 页面头部 -->
            <div class="admin-header">
                <div class="header-title">🗺️ 地图管理</div>
                <a href="index.php" class="back-link">← 返回管理后台</a>
            </div>

            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- 地图统计 -->
            <div class="stats-grid">
                <div class="stats-card">
                    <div class="stats-icon">🗺️</div>
                    <div class="stats-number"><?php echo number_format($mapStats['total_tiles']); ?></div>
                    <div class="stats-label">总格子数</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">⬜</div>
                    <div class="stats-number"><?php echo number_format($mapStats['empty_tiles']); ?></div>
                    <div class="stats-label">空地</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">💎</div>
                    <div class="stats-number"><?php echo number_format($mapStats['resource_tiles']); ?></div>
                    <div class="stats-label">资源点</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">🏰</div>
                    <div class="stats-number"><?php echo number_format($mapStats['npc_forts']); ?></div>
                    <div class="stats-label">NPC城池</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">🏛️</div>
                    <div class="stats-number"><?php echo number_format($mapStats['player_cities']); ?></div>
                    <div class="stats-label">玩家城池</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">⭐</div>
                    <div class="stats-number"><?php echo number_format($mapStats['silver_holes']); ?></div>
                    <div class="stats-label">银白之孔</div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon">🚩</div>
                    <div class="stats-number"><?php echo number_format($mapStats['occupied_tiles']); ?></div>
                    <div class="stats-label">被占领格子</div>
                </div>
            </div>

            <!-- 地图操作 -->
            <div class="actions-section">
                <div class="section-title">
                    <span class="section-icon">⚡</span>
                    地图操作
                </div>
                
                <div class="action-grid">
                    <!-- 生成地图 -->
                    <div class="action-card">
                        <div class="action-title">生成新地图</div>
                        <div class="action-desc">
                            生成全新的游戏地图，包括资源点、NPC城池和银白之孔。
                        </div>
                        
                        <form method="post">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="generate_map">
                            
                            <div class="checkbox-group">
                                <label>
                                    <input type="checkbox" name="force_regenerate" value="1">
                                    重新生成空服地图
                                </label>
                            </div>
                            
                            <div class="warning-box">
                                <strong>注意：</strong>存在任何城池时该操作会被拒绝；进行中赛季必须使用赛季重建流程。
                            </div>
                            
                            <button type="submit" class="action-button btn-primary" 
                                    onclick="return confirm('确定要生成新地图吗？')">
                                生成地图
                            </button>
                        </form>
                    </div>
                    
                    <!-- 重生NPC城池 -->
                    <div class="action-card">
                        <div class="action-title">重生NPC城池</div>
                        <div class="action-desc">
                            重新生成所有被摧毁的NPC城池，恢复到初始状态。
                        </div>
                        
                        <form method="post">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="reset_npc_cities">
                            
                            <div class="warning-box">
                                <strong>说明：</strong>这将重生所有被摧毁的NPC城池，不会影响玩家城池。
                            </div>
                            
                            <button type="submit" class="action-button btn-warning" 
                                    onclick="return confirm('确定要重生所有NPC城池吗？')">
                                重生NPC城池
                            </button>
                        </form>
                    </div>
                    
                    <!-- 查看地图 -->
                    <div class="action-card">
                        <div class="action-title">查看游戏地图</div>
                        <div class="action-desc">
                            在新窗口中打开游戏地图，查看当前地图状态。
                        </div>
                        
                        <div class="warning-box">
                            <strong>提示：</strong>您可以在地图页面查看详细的地图信息和玩家分布。
                        </div>
                        
                        <a href="../map.php" target="_blank" class="action-button btn-primary">
                            打开地图
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- 地图配置信息 -->
            <div class="actions-section">
                <div class="section-title">
                    <span class="section-icon">⚙️</span>
                    地图配置信息
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div>
                            <strong>地图大小：</strong><?php echo GameConfig::get('map_size', 512); ?>x<?php echo GameConfig::get('map_size', 512); ?>
                        </div>
                        <div>
                            <strong>银白之孔位置：</strong>(<?php echo GameConfig::get('silver_hole_x', 256); ?>, <?php echo GameConfig::get('silver_hole_y', 256); ?>)
                        </div>
                        <div>
                            <strong>NPC重生时间：</strong><?php echo GameConfig::get('npc_respawn_time', 86400); ?>秒
                        </div>
                        <div>
                            <strong>资源点重生时间：</strong><?php echo GameConfig::get('resource_point_respawn_time', 3600); ?>秒
                        </div>
                    </div>
                    
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dee2e6;">
                        <a href="config.php?category=map" class="action-button btn-primary" style="width: auto; padding: 8px 16px;">
                            修改地图配置
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

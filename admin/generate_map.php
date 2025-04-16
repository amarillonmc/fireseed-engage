<?php
// 包含初始化文件
require_once '../includes/init.php';

// 检查用户是否已登录且是管理员
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid() || !$user->isAdmin()) {
    header('Location: ../index.php');
    exit;
}

$message = '';
$statistics = null;

// 处理地图生成请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $mapGenerator = new MapGenerator();
        
        if ($_POST['action'] === 'generate') {
            $clearExisting = isset($_POST['clear_existing']) && $_POST['clear_existing'] === 'yes';
            $result = $mapGenerator->generateMap($clearExisting);
            
            if ($result === true) {
                $message = '地图生成成功';
                $statistics = $mapGenerator->getMapStatistics();
            } else {
                $message = '地图生成失败: ' . $result;
            }
        } elseif ($_POST['action'] === 'reset') {
            if ($mapGenerator->resetMap()) {
                $message = '地图重置成功';
            } else {
                $message = '地图重置失败';
            }
        }
    }
}

// 获取地图统计信息
if ($statistics === null) {
    $mapGenerator = new MapGenerator();
    $statistics = $mapGenerator->getMapStatistics();
}

// 页面标题
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
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .admin-title {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .admin-section {
            margin-bottom: 30px;
        }
        
        .admin-section h3 {
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .admin-form {
            margin-bottom: 20px;
        }
        
        .admin-form .form-group {
            margin-bottom: 15px;
        }
        
        .admin-form .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        
        .admin-form .form-group input[type="checkbox"] {
            margin-right: 5px;
        }
        
        .admin-form button {
            padding: 8px 15px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            margin-right: 10px;
        }
        
        .admin-form button:hover {
            background-color: #555;
        }
        
        .admin-form button.danger {
            background-color: #cc0000;
        }
        
        .admin-form button.danger:hover {
            background-color: #ff0000;
        }
        
        .statistics-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .statistics-table th,
        .statistics-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #eee;
        }
        
        .statistics-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        
        .statistics-table tr:nth-child(even) {
            background-color: #f9f9f9;
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
                    <li><a href="../index.php">返回游戏</a></li>
                    <li><a href="index.php">管理首页</a></li>
                    <li><a href="users.php">用户管理</a></li>
                    <li><a href="generate_map.php">地图管理</a></li>
                    <li><a href="game_settings.php">游戏设置</a></li>
                </ul>
            </nav>
        </header>
        
        <!-- 主内容 -->
        <main>
            <div class="admin-container">
                <h2 class="admin-title">地图管理</h2>
                
                <?php if (!empty($message)): ?>
                <div class="message <?php echo strpos($message, '成功') !== false ? 'success' : 'error'; ?>">
                    <p><?php echo $message; ?></p>
                </div>
                <?php endif; ?>
                
                <div class="admin-section">
                    <h3>地图操作</h3>
                    
                    <form class="admin-form" method="post" action="">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="clear_existing" value="yes">
                                清除现有地图数据
                            </label>
                            <p class="form-hint">警告：这将删除所有现有的地图数据，包括玩家城池和占领的资源点。</p>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="action" value="generate">生成新地图</button>
                            <button type="submit" name="action" value="reset" class="danger" onclick="return confirm('确定要重置地图吗？这将删除所有地图数据！')">重置地图</button>
                        </div>
                    </form>
                </div>
                
                <div class="admin-section">
                    <h3>地图统计信息</h3>
                    
                    <table class="statistics-table">
                        <tr>
                            <th>总格子数</th>
                            <td><?php echo number_format($statistics['total_tiles']); ?></td>
                        </tr>
                        <tr>
                            <th>空地数量</th>
                            <td><?php echo number_format($statistics['empty_tiles']); ?> (<?php echo round($statistics['empty_tiles'] / $statistics['total_tiles'] * 100, 2); ?>%)</td>
                        </tr>
                        <tr>
                            <th>资源点总数</th>
                            <td><?php echo number_format($statistics['resource_points']['total']); ?> (<?php echo round($statistics['resource_points']['total'] / $statistics['total_tiles'] * 100, 2); ?>%)</td>
                        </tr>
                        <tr>
                            <th>亮晶晶资源点</th>
                            <td><?php echo number_format($statistics['resource_points']['bright']); ?></td>
                        </tr>
                        <tr>
                            <th>暖洋洋资源点</th>
                            <td><?php echo number_format($statistics['resource_points']['warm']); ?></td>
                        </tr>
                        <tr>
                            <th>冷冰冰资源点</th>
                            <td><?php echo number_format($statistics['resource_points']['cold']); ?></td>
                        </tr>
                        <tr>
                            <th>郁萌萌资源点</th>
                            <td><?php echo number_format($statistics['resource_points']['green']); ?></td>
                        </tr>
                        <tr>
                            <th>昼闪闪资源点</th>
                            <td><?php echo number_format($statistics['resource_points']['day']); ?></td>
                        </tr>
                        <tr>
                            <th>夜静静资源点</th>
                            <td><?php echo number_format($statistics['resource_points']['night']); ?></td>
                        </tr>
                        <tr>
                            <th>NPC城池总数</th>
                            <td><?php echo number_format($statistics['npc_forts']['total']); ?> (<?php echo round($statistics['npc_forts']['total'] / $statistics['total_tiles'] * 100, 2); ?>%)</td>
                        </tr>
                        <tr>
                            <th>1级NPC城池</th>
                            <td><?php echo number_format($statistics['npc_forts']['level_1']); ?></td>
                        </tr>
                        <tr>
                            <th>2级NPC城池</th>
                            <td><?php echo number_format($statistics['npc_forts']['level_2']); ?></td>
                        </tr>
                        <tr>
                            <th>3级NPC城池</th>
                            <td><?php echo number_format($statistics['npc_forts']['level_3']); ?></td>
                        </tr>
                        <tr>
                            <th>4级NPC城池</th>
                            <td><?php echo number_format($statistics['npc_forts']['level_4']); ?></td>
                        </tr>
                        <tr>
                            <th>5级NPC城池</th>
                            <td><?php echo number_format($statistics['npc_forts']['level_5']); ?></td>
                        </tr>
                        <tr>
                            <th>玩家城池数量</th>
                            <td><?php echo number_format($statistics['player_cities']); ?></td>
                        </tr>
                        <tr>
                            <th>特殊地点总数</th>
                            <td><?php echo number_format($statistics['special_points']['total']); ?></td>
                        </tr>
                        <tr>
                            <th>银白之孔</th>
                            <td><?php echo number_format($statistics['special_points']['silver_hole']); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </main>
        
        <!-- 页脚 -->
        <footer>
            <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?> - 版本 <?php echo GAME_VERSION; ?></p>
        </footer>
    </div>
</body>
</html>

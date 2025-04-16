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

// 获取用户资源
$resource = new Resource($user->getUserId());

// 获取用户拥有的所有资源点
$query = "SELECT * FROM map_tiles WHERE owner_id = ? AND type = 'resource'";
$stmt = $db->prepare($query);
$stmt->bind_param('i', $user->getUserId());
$stmt->execute();
$result = $stmt->get_result();

$resourceTiles = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $tile = new Map($row['tile_id']);
        if ($tile->isValid()) {
            $resourceTiles[] = [
                'tile_id' => $tile->getTileId(),
                'x' => $tile->getX(),
                'y' => $tile->getY(),
                'type' => $tile->getType(),
                'subtype' => $tile->getSubtype(),
                'resource_amount' => $tile->getResourceAmount(),
                'last_collection_time' => $tile->getLastCollectionTime(),
                'collection_efficiency' => $tile->getCollectionEfficiency(),
                'name' => $tile->getName()
            ];
        }
    }
}

$stmt->close();

// 页面标题
$pageTitle = '领地管理';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .territory-container {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .territory-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .territory-title {
            margin: 0;
        }
        
        .territory-controls {
            display: flex;
            gap: 10px;
        }
        
        .territory-controls button {
            padding: 5px 10px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .territory-controls button:hover {
            background-color: #555;
        }
        
        .territory-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .territory-table th,
        .territory-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #eee;
        }
        
        .territory-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        
        .territory-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .territory-table .resource-bright {
            background-color: #f0f0f0;
        }
        
        .territory-table .resource-warm {
            background-color: #ffeeee;
        }
        
        .territory-table .resource-cold {
            background-color: #eeeeff;
        }
        
        .territory-table .resource-green {
            background-color: #eeffee;
        }
        
        .territory-table .resource-day {
            background-color: #ffffee;
        }
        
        .territory-table .resource-night {
            background-color: #eeeeff;
        }
        
        .territory-table .actions {
            display: flex;
            gap: 5px;
        }
        
        .territory-table .actions button {
            padding: 3px 8px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .territory-table .actions button:hover {
            background-color: #555;
        }
        
        .territory-table .actions button.abandon {
            background-color: #cc0000;
        }
        
        .territory-table .actions button.abandon:hover {
            background-color: #ff0000;
        }
        
        .territory-info {
            margin-top: 20px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        
        .territory-info h3 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .territory-info p {
            margin: 5px 0;
        }
        
        .territory-summary {
            margin-top: 20px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        
        .territory-summary h3 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .territory-summary p {
            margin: 5px 0;
        }
        
        .territory-summary .resource-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .territory-summary .resource-item {
            padding: 5px 10px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .territory-summary .resource-bright {
            background-color: #f0f0f0;
            color: #333;
        }
        
        .territory-summary .resource-warm {
            background-color: #ffeeee;
            color: #cc0000;
        }
        
        .territory-summary .resource-cold {
            background-color: #eeeeff;
            color: #0000cc;
        }
        
        .territory-summary .resource-green {
            background-color: #eeffee;
            color: #00cc00;
        }
        
        .territory-summary .resource-day {
            background-color: #ffffee;
            color: #cccc00;
        }
        
        .territory-summary .resource-night {
            background-color: #eeeeff;
            color: #6600cc;
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
                    <li><a href="map.php">地图</a></li>
                    <li><a href="territory.php">领地</a></li>
                    <li><a href="internal.php">内政</a></li>
                    <li><a href="ranking.php">排名</a></li>
                    <li class="circuit-points">思考回路: <?php echo $user->getCircuitPoints(); ?> / <?php echo $user->getMaxCircuitPoints(); ?></li>
                </ul>
            </nav>
        </header>
        
        <!-- 主内容 -->
        <main>
            <!-- 资源显示 -->
            <div class="resource-bar">
                <div class="resource bright-crystal">
                    <span class="resource-name">亮晶晶</span>
                    <span class="resource-value"><?php echo number_format($resource->getBrightCrystal()); ?></span>
                </div>
                <div class="resource warm-crystal">
                    <span class="resource-name">暖洋洋</span>
                    <span class="resource-value"><?php echo number_format($resource->getWarmCrystal()); ?></span>
                </div>
                <div class="resource cold-crystal">
                    <span class="resource-name">冷冰冰</span>
                    <span class="resource-value"><?php echo number_format($resource->getColdCrystal()); ?></span>
                </div>
                <div class="resource green-crystal">
                    <span class="resource-name">郁萌萌</span>
                    <span class="resource-value"><?php echo number_format($resource->getGreenCrystal()); ?></span>
                </div>
                <div class="resource day-crystal">
                    <span class="resource-name">昼闪闪</span>
                    <span class="resource-value"><?php echo number_format($resource->getDayCrystal()); ?></span>
                </div>
                <div class="resource night-crystal">
                    <span class="resource-name">夜静静</span>
                    <span class="resource-value"><?php echo number_format($resource->getNightCrystal()); ?></span>
                </div>
            </div>
            
            <!-- 领地容器 -->
            <div class="territory-container">
                <div class="territory-header">
                    <h3 class="territory-title">资源点管理</h3>
                    <div class="territory-controls">
                        <button id="collect-all-btn">收集所有资源</button>
                        <button id="refresh-btn">刷新</button>
                    </div>
                </div>
                
                <?php if (empty($resourceTiles)): ?>
                <div class="message info">
                    <p>您还没有占领任何资源点。请前往<a href="map.php">地图</a>占领资源点。</p>
                </div>
                <?php else: ?>
                <table class="territory-table">
                    <thead>
                        <tr>
                            <th>名称</th>
                            <th>坐标</th>
                            <th>资源类型</th>
                            <th>剩余资源</th>
                            <th>收集效率</th>
                            <th>上次收集</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resourceTiles as $tile): ?>
                        <tr class="resource-<?php echo $tile['subtype']; ?>">
                            <td><?php echo $tile['name']; ?></td>
                            <td>(<?php echo $tile['x']; ?>, <?php echo $tile['y']; ?>)</td>
                            <td><?php echo getResourceName($tile['subtype']); ?></td>
                            <td><?php echo number_format($tile['resource_amount']); ?></td>
                            <td><?php echo $tile['collection_efficiency']; ?>/小时</td>
                            <td>
                                <?php if ($tile['last_collection_time']): ?>
                                <?php echo date('Y-m-d H:i:s', strtotime($tile['last_collection_time'])); ?>
                                <?php else: ?>
                                从未收集
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <button class="collect" data-tile-id="<?php echo $tile['tile_id']; ?>">收集</button>
                                <button class="view-on-map" data-x="<?php echo $tile['x']; ?>" data-y="<?php echo $tile['y']; ?>">查看</button>
                                <button class="abandon" data-x="<?php echo $tile['x']; ?>" data-y="<?php echo $tile['y']; ?>">放弃</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="territory-summary">
                    <h3>资源点统计</h3>
                    <p>总数: <?php echo count($resourceTiles); ?> 个资源点</p>
                    <p>资源类型分布:</p>
                    <div class="resource-list">
                        <?php
                        $resourceCounts = [
                            'bright' => 0,
                            'warm' => 0,
                            'cold' => 0,
                            'green' => 0,
                            'day' => 0,
                            'night' => 0
                        ];
                        
                        foreach ($resourceTiles as $tile) {
                            $resourceCounts[$tile['subtype']]++;
                        }
                        
                        foreach ($resourceCounts as $type => $count) {
                            if ($count > 0) {
                                echo '<div class="resource-item resource-' . $type . '">' . getResourceName($type) . ': ' . $count . '</div>';
                            }
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
        
        <!-- 页脚 -->
        <footer>
            <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?> - 版本 <?php echo GAME_VERSION; ?></p>
        </footer>
    </div>
    
    <script src="assets/js/script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 收集所有资源按钮点击事件
            document.getElementById('collect-all-btn').addEventListener('click', function() {
                collectAllResources();
            });
            
            // 刷新按钮点击事件
            document.getElementById('refresh-btn').addEventListener('click', function() {
                window.location.reload();
            });
            
            // 收集按钮点击事件
            const collectButtons = document.querySelectorAll('.collect');
            collectButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tileId = this.getAttribute('data-tile-id');
                    collectResource(tileId);
                });
            });
            
            // 查看按钮点击事件
            const viewButtons = document.querySelectorAll('.view-on-map');
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const x = this.getAttribute('data-x');
                    const y = this.getAttribute('data-y');
                    window.location.href = `map.php?x=${x}&y=${y}`;
                });
            });
            
            // 放弃按钮点击事件
            const abandonButtons = document.querySelectorAll('.abandon');
            abandonButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const x = this.getAttribute('data-x');
                    const y = this.getAttribute('data-y');
                    abandonTile(x, y);
                });
            });
            
            // 收集所有资源
            function collectAllResources() {
                fetch('api/collect_resources.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            let message = `收集成功，共收集了${data.total_collected}单位资源`;
                            
                            // 显示各类资源收集数量
                            const resources = data.collected_resources;
                            for (const type in resources) {
                                if (resources[type] > 0) {
                                    message += `\n${getResourceName(type)}: ${resources[type]}`;
                                }
                            }
                            
                            showNotification(message);
                            
                            // 刷新页面
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        } else {
                            showNotification(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error collecting resources:', error);
                        showNotification('收集资源时发生错误');
                    });
            }
            
            // 收集单个资源点的资源
            function collectResource(tileId) {
                fetch(`api/collect_resources.php?tile_id=${tileId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(`收集成功，获得了${data.collected}单位${getResourceName(data.resource_type)}`);
                            
                            // 刷新页面
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        } else {
                            showNotification(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error collecting resource:', error);
                        showNotification('收集资源时发生错误');
                    });
            }
            
            // 放弃资源点
            function abandonTile(x, y) {
                if (confirm('确定要放弃这个资源点吗？')) {
                    fetch(`api/abandon_tile.php?x=${x}&y=${y}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showNotification('放弃成功');
                                
                                // 刷新页面
                                setTimeout(() => {
                                    window.location.reload();
                                }, 2000);
                            } else {
                                showNotification(data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error abandoning tile:', error);
                            showNotification('放弃资源点时发生错误');
                        });
                }
            }
            
            // 获取资源名称
            function getResourceName(type) {
                switch (type) {
                    case 'bright':
                        return '亮晶晶';
                    case 'warm':
                        return '暖洋洋';
                    case 'cold':
                        return '冷冰冰';
                    case 'green':
                        return '郁萌萌';
                    case 'day':
                        return '昼闪闪';
                    case 'night':
                        return '夜静静';
                    default:
                        return '未知资源';
                }
            }
        });
        
        // 获取资源名称函数（PHP版本）
        <?php
        function getResourceName($type) {
            switch ($type) {
                case 'bright':
                    return '亮晶晶';
                case 'warm':
                    return '暖洋洋';
                case 'cold':
                    return '冷冰冰';
                case 'green':
                    return '郁萌萌';
                case 'day':
                    return '昼闪闪';
                case 'night':
                    return '夜静静';
                default:
                    return '未知资源';
            }
        }
        ?>
    </script>
</body>
</html>

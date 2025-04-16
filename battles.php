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

// 获取分页参数
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// 获取用户的战斗记录
$battles = Battle::getUserBattles($user->getUserId(), $limit, $offset);

// 获取用户的战斗记录总数
$totalBattles = Battle::getUserBattlesCount($user->getUserId());
$totalPages = ceil($totalBattles / $limit);

// 页面标题
$pageTitle = '战斗记录';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .battles-container {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .battles-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .battles-title {
            margin: 0;
        }
        
        .battles-controls {
            display: flex;
            gap: 10px;
        }
        
        .battles-controls button {
            padding: 5px 10px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .battles-controls button:hover {
            background-color: #555;
        }
        
        .battles-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .battles-table th,
        .battles-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #eee;
        }
        
        .battles-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        
        .battles-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .battles-table .battle-result {
            font-weight: bold;
        }
        
        .battles-table .battle-result.attacker-win {
            color: #28a745;
        }
        
        .battles-table .battle-result.defender-win {
            color: #dc3545;
        }
        
        .battles-table .battle-result.draw {
            color: #ffc107;
        }
        
        .battles-table .battle-actions {
            display: flex;
            gap: 5px;
        }
        
        .battles-table .battle-actions button {
            padding: 3px 8px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .battles-table .battle-actions button:hover {
            background-color: #555;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        
        .pagination a {
            padding: 5px 10px;
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 3px;
            text-decoration: none;
            color: #333;
        }
        
        .pagination a:hover {
            background-color: #e5e5e5;
        }
        
        .pagination a.active {
            background-color: #333;
            color: #fff;
            border-color: #333;
        }
        
        .no-battles {
            padding: 20px;
            text-align: center;
            background-color: #f5f5f5;
            border-radius: 5px;
            margin-bottom: 20px;
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
                    <li><a href="armies.php">军队</a></li>
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
            
            <!-- 战斗记录容器 -->
            <div class="battles-container">
                <div class="battles-header">
                    <h3 class="battles-title">战斗记录</h3>
                    <div class="battles-controls">
                        <button id="refresh-btn">刷新</button>
                    </div>
                </div>
                
                <?php if (empty($battles)): ?>
                <div class="no-battles">
                    <p>您还没有任何战斗记录。</p>
                </div>
                <?php else: ?>
                <table class="battles-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>时间</th>
                            <th>攻击方</th>
                            <th>防守方</th>
                            <th>结果</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($battles as $battle): ?>
                        <tr>
                            <td><?php echo $battle->getBattleId(); ?></td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($battle->getBattleTime())); ?></td>
                            <td>
                                <?php
                                $attackerArmy = new Army($battle->getAttackerArmyId());
                                if ($attackerArmy->isValid()) {
                                    echo $attackerArmy->getName();
                                    
                                    if ($attackerArmy->getOwnerId() == $user->getUserId()) {
                                        echo ' (我方)';
                                    }
                                } else {
                                    echo '未知军队';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $defenderInfo = '';
                                $defenderArmyId = $battle->getDefenderArmyId();
                                $defenderCityId = $battle->getDefenderCityId();
                                $defenderTileId = $battle->getDefenderTileId();
                                
                                if ($defenderArmyId) {
                                    $defenderArmy = new Army($defenderArmyId);
                                    if ($defenderArmy->isValid()) {
                                        $defenderInfo = $defenderArmy->getName();
                                        
                                        if ($defenderArmy->getOwnerId() == $user->getUserId()) {
                                            $defenderInfo .= ' (我方)';
                                        }
                                    } else {
                                        $defenderInfo = '未知军队';
                                    }
                                } elseif ($defenderCityId) {
                                    $defenderCity = new City($defenderCityId);
                                    if ($defenderCity->isValid()) {
                                        $defenderInfo = $defenderCity->getName();
                                        
                                        if ($defenderCity->getOwnerId() == $user->getUserId()) {
                                            $defenderInfo .= ' (我方)';
                                        }
                                    } else {
                                        $defenderInfo = '未知城池';
                                    }
                                } elseif ($defenderTileId) {
                                    $defenderTile = new Map($defenderTileId);
                                    if ($defenderTile->isValid()) {
                                        if ($defenderTile->getType() == 'resource') {
                                            $defenderInfo = '资源点 - ' . getResourceName($defenderTile->getSubtype());
                                        } elseif ($defenderTile->getType() == 'npc_fort') {
                                            $defenderInfo = 'NPC城池';
                                        } else {
                                            $defenderInfo = $defenderTile->getType();
                                        }
                                        
                                        $defenderInfo .= ' (' . $defenderTile->getX() . ', ' . $defenderTile->getY() . ')';
                                    } else {
                                        $defenderInfo = '未知地点';
                                    }
                                } else {
                                    $defenderInfo = '未知防守方';
                                }
                                
                                echo $defenderInfo;
                                ?>
                            </td>
                            <td>
                                <?php
                                $resultClass = '';
                                $resultText = '';
                                
                                switch ($battle->getResult()) {
                                    case 'attacker_win_big':
                                        $resultClass = 'attacker-win';
                                        $resultText = '攻击方大胜';
                                        break;
                                    case 'attacker_win':
                                        $resultClass = 'attacker-win';
                                        $resultText = '攻击方小胜';
                                        break;
                                    case 'defender_win_big':
                                        $resultClass = 'defender-win';
                                        $resultText = '防守方大胜';
                                        break;
                                    case 'defender_win':
                                        $resultClass = 'defender-win';
                                        $resultText = '防守方小胜';
                                        break;
                                    case 'draw':
                                        $resultClass = 'draw';
                                        $resultText = '战斗平局';
                                        break;
                                    default:
                                        $resultClass = '';
                                        $resultText = '战斗结果未知';
                                }
                                ?>
                                <span class="battle-result <?php echo $resultClass; ?>"><?php echo $resultText; ?></span>
                            </td>
                            <td class="battle-actions">
                                <button class="view-report" data-battle-id="<?php echo $battle->getBattleId(); ?>">查看报告</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- 分页 -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?page=1">首页</a>
                    <a href="?page=<?php echo $page - 1; ?>">上一页</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++) {
                        $activeClass = $i == $page ? 'active' : '';
                        echo "<a href=\"?page=$i\" class=\"$activeClass\">$i</a>";
                    }
                    ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>">下一页</a>
                    <a href="?page=<?php echo $totalPages; ?>">末页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
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
            // 刷新按钮点击事件
            document.getElementById('refresh-btn').addEventListener('click', function() {
                window.location.reload();
            });
            
            // 查看报告按钮点击事件
            const viewReportButtons = document.querySelectorAll('.view-report');
            viewReportButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const battleId = this.getAttribute('data-battle-id');
                    window.location.href = `battle_report.php?id=${battleId}`;
                });
            });
        });
        
        // 获取资源名称函数
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
    </script>
    
    <?php
    // 获取资源名称函数（PHP版本）
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
</body>
</html>

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

// 获取战斗ID
$battleId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 获取战斗信息
$battle = new Battle($battleId);
if (!$battle->isValid()) {
    header('Location: battles.php');
    exit;
}

// 获取攻击方军队
$attackerArmy = new Army($battle->getAttackerArmyId());
if (!$attackerArmy->isValid()) {
    header('Location: battles.php');
    exit;
}

// 检查用户是否有权限查看战斗报告
$hasPermission = false;

if ($attackerArmy->getOwnerId() == $user->getUserId()) {
    $hasPermission = true;
} else {
    // 检查用户是否是防守方
    $defenderArmyId = $battle->getDefenderArmyId();
    $defenderCityId = $battle->getDefenderCityId();
    
    if ($defenderArmyId) {
        $defenderArmy = new Army($defenderArmyId);
        if ($defenderArmy->isValid() && $defenderArmy->getOwnerId() == $user->getUserId()) {
            $hasPermission = true;
        }
    }
    
    if ($defenderCityId) {
        $defenderCity = new City($defenderCityId);
        if ($defenderCity->isValid() && $defenderCity->getOwnerId() == $user->getUserId()) {
            $hasPermission = true;
        }
    }
}

if (!$hasPermission) {
    header('Location: battles.php');
    exit;
}

// 获取防守方信息
$defenderInfo = null;
$defenderArmyId = $battle->getDefenderArmyId();
$defenderCityId = $battle->getDefenderCityId();
$defenderTileId = $battle->getDefenderTileId();

if ($defenderArmyId) {
    $defenderArmy = new Army($defenderArmyId);
    if ($defenderArmy->isValid()) {
        $defenderInfo = [
            'type' => 'army',
            'army_id' => $defenderArmy->getArmyId(),
            'name' => $defenderArmy->getName(),
            'owner_id' => $defenderArmy->getOwnerId()
        ];
    }
} elseif ($defenderCityId) {
    $defenderCity = new City($defenderCityId);
    if ($defenderCity->isValid()) {
        $defenderInfo = [
            'type' => 'city',
            'city_id' => $defenderCity->getCityId(),
            'name' => $defenderCity->getName(),
            'owner_id' => $defenderCity->getOwnerId()
        ];
    }
} elseif ($defenderTileId) {
    $defenderTile = new Map($defenderTileId);
    if ($defenderTile->isValid()) {
        $defenderInfo = [
            'type' => 'tile',
            'tile_id' => $defenderTile->getTileId(),
            'x' => $defenderTile->getX(),
            'y' => $defenderTile->getY(),
            'type' => $defenderTile->getType(),
            'subtype' => $defenderTile->getSubtype()
        ];
    }
}

// 获取战斗结果
$battleResult = $battle->getResult();
$attackerLosses = $battle->getAttackerLosses();
$defenderLosses = $battle->getDefenderLosses();
$rewards = $battle->getRewards();

// 页面标题
$pageTitle = '战斗报告';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .battle-report-container {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .battle-report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .battle-report-title {
            margin: 0;
        }
        
        .battle-report-time {
            color: #666;
            font-size: 14px;
        }
        
        .battle-report-result {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            font-weight: bold;
            font-size: 18px;
        }
        
        .battle-report-result.attacker-win {
            background-color: #d4edda;
            color: #155724;
        }
        
        .battle-report-result.defender-win {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .battle-report-result.draw {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .battle-report-sides {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .battle-report-side {
            flex: 1;
            padding: 15px;
            border-radius: 5px;
            background-color: #f5f5f5;
        }
        
        .battle-report-side h4 {
            margin-top: 0;
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .battle-report-side p {
            margin: 5px 0;
        }
        
        .battle-report-losses {
            margin-bottom: 20px;
        }
        
        .battle-report-losses h4 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .battle-report-losses-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .battle-report-losses-table th,
        .battle-report-losses-table td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        
        .battle-report-losses-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        
        .battle-report-rewards {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 5px;
            background-color: #f5f5f5;
        }
        
        .battle-report-rewards h4 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .battle-report-rewards p {
            margin: 5px 0;
        }
        
        .battle-report-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .battle-report-actions button {
            padding: 8px 15px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .battle-report-actions button:hover {
            background-color: #555;
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
            
            <!-- 战斗报告容器 -->
            <div class="battle-report-container">
                <div class="battle-report-header">
                    <h3 class="battle-report-title">战斗报告 #<?php echo $battleId; ?></h3>
                    <div class="battle-report-time">
                        <?php echo date('Y-m-d H:i:s', strtotime($battle->getBattleTime())); ?>
                    </div>
                </div>
                
                <?php
                $resultClass = '';
                $resultText = '';
                
                switch ($battleResult) {
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
                
                <div class="battle-report-result <?php echo $resultClass; ?>">
                    <?php echo $resultText; ?>
                </div>
                
                <div class="battle-report-sides">
                    <div class="battle-report-side">
                        <h4>攻击方</h4>
                        <p><strong>军队名称:</strong> <?php echo $attackerArmy->getName(); ?></p>
                        <p><strong>拥有者:</strong> <?php 
                            $attackerOwner = new User($attackerArmy->getOwnerId());
                            echo $attackerOwner->isValid() ? $attackerOwner->getUsername() : '未知用户';
                        ?></p>
                        <p><strong>战斗力:</strong> <?php echo number_format($attackerArmy->getCombatPower()); ?></p>
                    </div>
                    
                    <div class="battle-report-side">
                        <h4>防守方</h4>
                        <?php if ($defenderInfo): ?>
                            <?php if ($defenderInfo['type'] == 'army'): ?>
                                <p><strong>军队名称:</strong> <?php echo $defenderInfo['name']; ?></p>
                                <p><strong>拥有者:</strong> <?php 
                                    $defenderOwner = new User($defenderInfo['owner_id']);
                                    echo $defenderOwner->isValid() ? $defenderOwner->getUsername() : '未知用户';
                                ?></p>
                            <?php elseif ($defenderInfo['type'] == 'city'): ?>
                                <p><strong>城池名称:</strong> <?php echo $defenderInfo['name']; ?></p>
                                <p><strong>拥有者:</strong> <?php 
                                    $defenderOwner = new User($defenderInfo['owner_id']);
                                    echo $defenderOwner->isValid() ? $defenderOwner->getUsername() : '未知用户';
                                ?></p>
                            <?php elseif ($defenderInfo['type'] == 'tile'): ?>
                                <p><strong>地图格子:</strong> (<?php echo $defenderInfo['x']; ?>, <?php echo $defenderInfo['y']; ?>)</p>
                                <p><strong>类型:</strong> <?php 
                                    if ($defenderInfo['type'] == 'resource') {
                                        echo '资源点 - ' . getResourceName($defenderInfo['subtype']);
                                    } elseif ($defenderInfo['type'] == 'npc_fort') {
                                        echo 'NPC城池';
                                    } else {
                                        echo $defenderInfo['type'];
                                    }
                                ?></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>防守方信息不可用</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="battle-report-losses">
                    <h4>战斗损失</h4>
                    
                    <?php if (!empty($attackerLosses)): ?>
                    <h5>攻击方损失</h5>
                    <table class="battle-report-losses-table">
                        <thead>
                            <tr>
                                <th>士兵类型</th>
                                <th>等级</th>
                                <th>损失数量</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attackerLosses as $loss): ?>
                            <tr>
                                <td><?php echo getSoldierName($loss['soldier_type']); ?></td>
                                <td><?php echo $loss['level']; ?></td>
                                <td><?php echo number_format($loss['quantity']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p>攻击方没有损失</p>
                    <?php endif; ?>
                    
                    <?php if (!empty($defenderLosses)): ?>
                    <h5>防守方损失</h5>
                    <?php if (isset($defenderLosses['durability_loss'])): ?>
                        <p>城池耐久度损失: <?php echo number_format($defenderLosses['durability_loss']); ?></p>
                    <?php elseif (isset($defenderLosses['resource_loss'])): ?>
                        <p>资源损失: <?php echo number_format($defenderLosses['resource_loss']); ?></p>
                    <?php else: ?>
                        <table class="battle-report-losses-table">
                            <thead>
                                <tr>
                                    <th>士兵类型</th>
                                    <th>等级</th>
                                    <th>损失数量</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($defenderLosses as $loss): ?>
                                <tr>
                                    <td><?php echo getSoldierName($loss['soldier_type']); ?></td>
                                    <td><?php echo $loss['level']; ?></td>
                                    <td><?php echo number_format($loss['quantity']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php else: ?>
                    <p>防守方没有损失</p>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($rewards)): ?>
                <div class="battle-report-rewards">
                    <h4>战斗奖励</h4>
                    
                    <?php if (isset($rewards['circuit_points'])): ?>
                    <p>思考回路点数: <?php echo $rewards['circuit_points']; ?></p>
                    <?php endif; ?>
                    
                    <?php if (isset($rewards['resources'])): ?>
                    <p>资源奖励:</p>
                    <ul>
                        <?php foreach ($rewards['resources'] as $type => $amount): ?>
                        <?php if ($amount > 0): ?>
                        <li><?php echo getResourceName($type); ?>: <?php echo number_format($amount); ?></li>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    
                    <?php if (isset($rewards['tile_control'])): ?>
                    <p>获得地图格子控制权: 
                        <?php 
                        if ($rewards['tile_control']['type'] == 'resource') {
                            echo '资源点 - ' . getResourceName($rewards['tile_control']['subtype']);
                        } else {
                            echo $rewards['tile_control']['type'];
                        }
                        ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="battle-report-actions">
                    <button id="back-btn">返回</button>
                    <?php if ($defenderInfo && isset($defenderInfo['x']) && isset($defenderInfo['y'])): ?>
                    <button id="view-on-map-btn" data-x="<?php echo $defenderInfo['x']; ?>" data-y="<?php echo $defenderInfo['y']; ?>">在地图上查看</button>
                    <?php endif; ?>
                </div>
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
            // 返回按钮点击事件
            document.getElementById('back-btn').addEventListener('click', function() {
                window.location.href = 'battles.php';
            });
            
            // 在地图上查看按钮点击事件
            const viewOnMapBtn = document.getElementById('view-on-map-btn');
            if (viewOnMapBtn) {
                viewOnMapBtn.addEventListener('click', function() {
                    const x = this.getAttribute('data-x');
                    const y = this.getAttribute('data-y');
                    window.location.href = `map.php?x=${x}&y=${y}`;
                });
            }
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
        
        // 获取士兵名称函数
        function getSoldierName(type) {
            switch (type) {
                case 'pawn':
                    return '兵卒';
                case 'knight':
                    return '骑士';
                case 'rook':
                    return '城壁';
                case 'bishop':
                    return '主教';
                case 'golem':
                    return '锤子兵';
                case 'scout':
                    return '侦察兵';
                default:
                    return '未知士兵';
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
    
    // 获取士兵名称函数（PHP版本）
    function getSoldierName($type) {
        switch ($type) {
            case 'pawn':
                return '兵卒';
            case 'knight':
                return '骑士';
            case 'rook':
                return '城壁';
            case 'bishop':
                return '主教';
            case 'golem':
                return '锤子兵';
            case 'scout':
                return '侦察兵';
            default:
                return '未知士兵';
        }
    }
    ?>
</body>
</html>

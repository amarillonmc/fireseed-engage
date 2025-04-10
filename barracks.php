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

// 获取城池ID
$cityId = isset($_GET['city_id']) ? intval($_GET['city_id']) : 0;

// 如果没有指定城池ID，获取用户的主城
if ($cityId <= 0) {
    $mainCity = City::getUserMainCity($user->getUserId());
    if ($mainCity) {
        $cityId = $mainCity->getCityId();
    }
}

// 获取城池信息
$city = null;
if ($cityId > 0) {
    $city = new City($cityId);
    
    // 检查城池是否存在且属于当前用户
    if (!$city->isValid() || $city->getOwnerId() != $user->getUserId()) {
        $city = null;
    }
}

// 如果没有有效的城池，重定向到主页
if (!$city) {
    header('Location: index.php');
    exit;
}

// 获取城池中的兵营
$barracks = Facility::getCityFacilitiesByType($city->getCityId(), 'barracks');

// 检查是否有可用的兵营
$hasBarracks = !empty($barracks);

// 获取城池中的士兵
$soldiers = $city->getSoldiers();

// 页面标题
$pageTitle = $city->getName() . ' - 兵营';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
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
            
            <!-- 兵营视图 -->
            <div class="barracks-view" data-city-id="<?php echo $city->getCityId(); ?>">
                <h3>兵营 - <?php echo $city->getName(); ?></h3>
                
                <?php if ($hasBarracks): ?>
                    <div class="barracks-info">
                        <p>兵营等级: <?php echo $barracks[0]->getLevel(); ?></p>
                        <p>可训练士兵等级: <?php echo $barracks[0]->getMaxSoldierLevel(); ?></p>
                    </div>
                    
                    <table class="barracks-table">
                        <thead>
                            <tr>
                                <th>士兵类型</th>
                                <th>等级</th>
                                <th>数量</th>
                                <th>训练中</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // 定义可训练的士兵类型
                            $trainableSoldierTypes = ['pawn', 'knight', 'rook', 'bishop'];
                            
                            foreach ($trainableSoldierTypes as $type) {
                                $soldierFound = false;
                                
                                // 查找该类型的士兵
                                foreach ($soldiers as $soldier) {
                                    if ($soldier->getType() == $type) {
                                        $soldierFound = true;
                                        ?>
                                        <tr>
                                            <td><?php echo $soldier->getName(); ?></td>
                                            <td><?php echo $soldier->getLevel(); ?></td>
                                            <td><?php echo $soldier->getQuantity(); ?></td>
                                            <td>
                                                <?php if ($soldier->getInTraining() > 0): ?>
                                                    <?php echo $soldier->getInTraining(); ?>
                                                    <?php if ($soldier->getTrainingCompleteTime()): ?>
                                                        <?php
                                                        $trainingCompleteTime = strtotime($soldier->getTrainingCompleteTime());
                                                        $now = time();
                                                        $timeRemaining = max(0, $trainingCompleteTime - $now);
                                                        $hours = floor($timeRemaining / 3600);
                                                        $minutes = floor(($timeRemaining % 3600) / 60);
                                                        $seconds = $timeRemaining % 60;
                                                        ?>
                                                        (<?php echo sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds); ?>)
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    0
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="train-button" onclick="showTrainingDialog('<?php echo $type; ?>')">训练</button>
                                            </td>
                                        </tr>
                                        <?php
                                        break;
                                    }
                                }
                                
                                // 如果没有找到该类型的士兵，显示空行
                                if (!$soldierFound) {
                                    ?>
                                    <tr>
                                        <td><?php echo getSoldierName($type); ?></td>
                                        <td><?php echo $barracks[0]->getMaxSoldierLevel(); ?></td>
                                        <td>0</td>
                                        <td>0</td>
                                        <td>
                                            <button class="train-button" onclick="showTrainingDialog('<?php echo $type; ?>')">训练</button>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="message info">
                        <p>该城池没有兵营，请先建造兵营。</p>
                        <p><a href="index.php?city_id=<?php echo $city->getCityId(); ?>">返回城池</a></p>
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
</body>
</html>

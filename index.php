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

// 获取用户主城
$mainCity = City::getUserMainCity($user->getUserId());
if (!$mainCity) {
    // 如果用户没有主城，创建一个
    $cityId = City::createInitialPlayerCity($user->getUserId());
    if ($cityId) {
        $mainCity = new City($cityId);
    }
}

// 获取主城坐标
$coordinates = $mainCity ? $mainCity->getCoordinates() : [0, 0];

// 页面标题
$pageTitle = '主页';
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
            <h2 class="page-title"><?php echo $mainCity ? "(" . $coordinates[0] . ", " . $coordinates[1] . ") - " . $mainCity->getName() : $pageTitle; ?></h2>
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
            
            <?php if ($mainCity): ?>
            <!-- 城池视图 -->
            <div class="city-view" data-city-id="<?php echo $mainCity->getCityId(); ?>">
                <h3>城池视图 - <?php echo $mainCity->getName(); ?></h3>
                
                <div class="city-grid">
                    <?php
                    // 获取城池中的所有设施
                    $facilities = $mainCity->getFacilities();
                    
                    // 创建24x24的网格
                    for ($y = 0; $y < 24; $y++) {
                        echo '<div class="city-row">';
                        for ($x = 0; $x < 24; $x++) {
                            $facilityFound = false;
                            
                            // 检查该位置是否有设施
                            foreach ($facilities as $facility) {
                                if ($facility->getXPos() == $x && $facility->getYPos() == $y) {
                                    echo '<div class="city-cell facility ' . $facility->getType() . '" data-facility-id="' . $facility->getFacilityId() . '">';
                                    echo '<span class="facility-name">' . $facility->getName() . '</span>';
                                    echo '<span class="facility-level">Lv.' . $facility->getLevel() . '</span>';
                                    echo '</div>';
                                    $facilityFound = true;
                                    break;
                                }
                            }
                            
                            // 如果没有设施，显示空格子
                            if (!$facilityFound) {
                                echo '<div class="city-cell empty" data-x="' . $x . '" data-y="' . $y . '"></div>';
                            }
                        }
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
            <?php else: ?>
            <div class="message error">
                <p>无法创建初始城池，请联系管理员。</p>
            </div>
            <?php endif; ?>
        </main>
        
        <!-- 页脚 -->
        <footer>
            <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?> - 版本 <?php echo GAME_VERSION; ?></p>
        </footer>
    </div>
    
    <script src="assets/js/script.js"></script>
</body>
</html>

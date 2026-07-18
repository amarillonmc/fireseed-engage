<?php
// 包含初始化文件
require_once 'includes/init.php';
require_once 'includes/gameplay_ui.php';

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
$vassalService = new VassalService();
$activeVassalRelation = $vassalService->getActiveRelation(
    $user->getUserId()
);

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
                    <li><a href="engage.php">集结中枢</a></li>
                    <li><a href="profile.php">档案</a></li>
                    <li><a href="generals.php">武将</a></li>
                    <li><a href="map.php">地图</a></li>
                    <li><a href="internal.php">内政</a></li>
                    <li><a href="ranking.php">排名</a></li>
                    <li><a href="vassal.php">附属</a></li>
                    <li class="circuit-points">
                        <?php echo renderImageResource(
                            'resource_circuit_points',
                            24,
                            ['alt' => '思考回路 / Circuit Points']
                        ); ?>
                        <span class="circuit-label">思考回路:</span>
                        <span class="circuit-value">
                            <?php echo number_format($user->getCircuitPoints()); ?>
                            /
                            <?php echo number_format($user->getMaxCircuitPoints()); ?>
                        </span>
                    </li>
                </ul>
            </nav>
        </header>
        
        <!-- 主内容 -->
        <main>
            <?php if ($activeVassalRelation): ?>
            <div class="message warning">
                当前附属于
                <?php echo escapeHtml($activeVassalRelation['lord_name']); ?>；
                领地与排行榜贡献计入
                <?php echo escapeHtml($activeVassalRelation['overlord_name']); ?>
                的势力。<a href="vassal.php">查看救出或主动脱离</a>
            </div>
            <?php endif; ?>

            <?php renderGameplayResourceBar($resource); ?>
            
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
                                    echo renderImageResource(
                                        'facility_' . $facility->getType(),
                                        32,
                                        [
                                            'alt' => $facility->getName(),
                                            'class' => 'city-facility-icon'
                                        ]
                                    );
                                    echo '<span class="facility-name">'
                                        . escapeHtml($facility->getName())
                                        . '</span>';
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
                <p><?php echo htmlspecialchars(
                    isSeasonGameplayFrozen()
                        ? getSeasonGameplayFreezeMessage()
                        : '无法创建初始城池，请联系管理员。',
                    ENT_QUOTES,
                    'UTF-8'
                ); ?></p>
            </div>
            <?php endif; ?>
        </main>
        
        <!-- 页脚 -->
        <footer>
            <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?> - 版本 <?php echo GAME_VERSION; ?></p>
        </footer>
    </div>
    
    <script>
        // 城池异步刷新复用服务器资源白名单 / Async city refresh reuses the server asset allowlist
        window.FIRESEED_IMAGE_RESOURCES = <?php echo json_encode(
            getImageResourceClientConfig([
                'facility_resource_production',
                'facility_governor_office',
                'facility_barracks',
                'facility_research_lab',
                'facility_dormitory',
                'facility_storage',
                'facility_watchtower',
                'facility_workshop'
            ]),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ); ?>;
    </script>
    <script src="assets/js/script.js"></script>
</body>
</html>

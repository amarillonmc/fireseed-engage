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

// 获取用户城池
$cities = City::getUserCities($user->getUserId());
$mainCity = City::getUserMainCity($user->getUserId());

// 获取当前选择的城池
$selectedCityId = isset($_GET['city_id']) ? intval($_GET['city_id']) : ($mainCity ? $mainCity->getCityId() : 0);
$selectedCity = null;

foreach ($cities as $city) {
    if ($city->getCityId() == $selectedCityId) {
        $selectedCity = $city;
        break;
    }
}

if (!$selectedCity && !empty($cities)) {
    $selectedCity = $cities[0];
    $selectedCityId = $selectedCity->getCityId();
}

// 获取城池设施
$facilities = [];
if ($selectedCity) {
    $facilities = Facility::getCityFacilities($selectedCityId);
}

// 获取城池士兵
$soldiers = [];
if ($selectedCity) {
    $soldiers = $selectedCity->getSoldiers();
}

// 页面标题
$pageTitle = '内政管理';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .internal-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .internal-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .city-selector {
            margin-bottom: 20px;
        }
        
        .city-selector select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            min-width: 200px;
        }
        
        .internal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .internal-section {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .section-title {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .section-icon {
            font-size: 24px;
            margin-right: 10px;
        }
        
        .facility-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .facility-slot {
            aspect-ratio: 1;
            border: 2px dashed #ddd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            min-height: 80px;
        }
        
        .facility-slot.empty {
            background: #f8f9fa;
        }
        
        .facility-slot.empty:hover {
            border-color: #3498db;
            background: #e3f2fd;
        }
        
        .facility-slot.occupied {
            border: 2px solid #27ae60;
            background: #e8f5e8;
        }
        
        .facility-slot.constructing {
            border: 2px solid #f39c12;
            background: #fff3cd;
        }
        
        .facility-slot.upgrading {
            border: 2px solid #9b59b6;
            background: #f3e5f5;
        }
        
        .facility-icon {
            font-size: 32px;
            margin-bottom: 5px;
        }
        
        .facility-info {
            text-align: center;
            font-size: 12px;
        }
        
        .facility-name {
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .facility-level {
            color: #7f8c8d;
        }
        
        .facility-progress {
            position: absolute;
            bottom: 5px;
            left: 5px;
            right: 5px;
            height: 4px;
            background: #ecf0f1;
            border-radius: 2px;
            overflow: hidden;
        }
        
        .facility-progress-bar {
            height: 100%;
            background: #3498db;
            transition: width 0.3s;
        }
        
        .resource-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .resource-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .resource-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .resource-name {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        
        .resource-amount {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .resource-production {
            font-size: 12px;
            color: #27ae60;
            margin-top: 5px;
        }
        
        .soldiers-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .soldier-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .soldier-item:last-child {
            border-bottom: none;
        }
        
        .soldier-info {
            display: flex;
            align-items: center;
        }
        
        .soldier-icon {
            font-size: 20px;
            margin-right: 10px;
        }
        
        .soldier-name {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .soldier-level {
            color: #7f8c8d;
            font-size: 14px;
            margin-left: 10px;
        }
        
        .soldier-quantity {
            font-weight: bold;
            color: #27ae60;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .action-button {
            padding: 15px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
            text-decoration: none;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .action-button:hover {
            background: #2980b9;
        }
        
        .action-button.secondary {
            background: #95a5a6;
        }
        
        .action-button.secondary:hover {
            background: #7f8c8d;
        }
        
        .action-button.success {
            background: #27ae60;
        }
        
        .action-button.success:hover {
            background: #229954;
        }
        
        .action-button.warning {
            background: #f39c12;
        }
        
        .action-button.warning:hover {
            background: #e67e22;
        }
        
        .city-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .no-city {
            text-align: center;
            color: #7f8c8d;
            padding: 40px;
        }
        
        @media (max-width: 768px) {
            .internal-grid {
                grid-template-columns: 1fr;
            }
            
            .facility-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .resource-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .city-stats {
                grid-template-columns: repeat(2, 1fr);
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
            <div class="internal-container">
                <!-- 内政管理头部 -->
                <div class="internal-header">
                    <h3>🏛️ 内政管理中心</h3>
                    <p>管理您的城池、设施、资源和军队</p>
                </div>

                <?php if (!empty($cities)): ?>
                <!-- 城池选择器 -->
                <div class="city-selector">
                    <label for="city-select">选择城池:</label>
                    <select id="city-select" onchange="changeCitySelection()">
                        <?php foreach ($cities as $city): ?>
                        <?php $coordinates = $city->getCoordinates(); ?>
                        <option value="<?php echo $city->getCityId(); ?>" 
                                <?php echo $city->getCityId() == $selectedCityId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($city->getName()); ?> 
                            (<?php echo $coordinates[0]; ?>, <?php echo $coordinates[1]; ?>)
                            <?php if ($mainCity && $city->getCityId() == $mainCity->getCityId()): ?>
                            - 主城
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($selectedCity): ?>
                <!-- 城池统计 -->
                <div class="city-stats">
                    <div class="stat-item">
                        <div class="stat-label">城池等级</div>
                        <div class="stat-value"><?php echo $selectedCity->getLevel(); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">耐久度</div>
                        <div class="stat-value"><?php echo $selectedCity->getDurability(); ?> / <?php echo $selectedCity->getMaxDurability(); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">防御力</div>
                        <div class="stat-value"><?php echo number_format($selectedCity->getDefensePower()); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">设施数量</div>
                        <div class="stat-value"><?php echo count($facilities); ?></div>
                    </div>
                </div>

                <!-- 主要内容网格 -->
                <div class="internal-grid">
                    <!-- 设施管理 -->
                    <div class="internal-section">
                        <div class="section-title">
                            <span class="section-icon">🏗️</span>
                            设施管理
                        </div>
                        
                        <div class="facility-grid">
                            <?php
                            // 创建6x4的设施网格
                            $facilityMap = [];
                            foreach ($facilities as $facility) {
                                $key = $facility->getXPos() . '_' . $facility->getYPos();
                                $facilityMap[$key] = $facility;
                            }
                            
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 6; $x++) {
                                    $key = $x . '_' . $y;
                                    if (isset($facilityMap[$key])) {
                                        $facility = $facilityMap[$key];
                                        $isConstructing = $facility->isUnderConstruction();
                                        $isUpgrading = $facility->isUpgrading();
                                        
                                        $slotClass = 'occupied';
                                        if ($isConstructing) $slotClass = 'constructing';
                                        if ($isUpgrading) $slotClass = 'upgrading';
                                        
                                        // 设施图标映射
                                        $facilityIcons = [
                                            'resource_production' => '⚡',
                                            'governor_office' => '🏛️',
                                            'barracks' => '⚔️',
                                            'research_lab' => '🔬',
                                            'dormitory' => '🏠',
                                            'storage' => '📦',
                                            'watchtower' => '🗼',
                                            'workshop' => '🔧'
                                        ];
                                        
                                        $icon = isset($facilityIcons[$facility->getType()]) ? 
                                               $facilityIcons[$facility->getType()] : '🏢';
                                        
                                        echo '<div class="facility-slot ' . $slotClass . '" onclick="manageFacility(' . $facility->getFacilityId() . ')">';
                                        echo '<div>';
                                        echo '<div class="facility-icon">' . $icon . '</div>';
                                        echo '<div class="facility-info">';
                                        echo '<div class="facility-name">' . htmlspecialchars($facility->getName()) . '</div>';
                                        echo '<div class="facility-level">Lv.' . $facility->getLevel() . '</div>';
                                        echo '</div>';
                                        echo '</div>';
                                        
                                        if ($isConstructing || $isUpgrading) {
                                            echo '<div class="facility-progress">';
                                            echo '<div class="facility-progress-bar" style="width: 60%;"></div>';
                                            echo '</div>';
                                        }
                                        
                                        echo '</div>';
                                    } else {
                                        echo '<div class="facility-slot empty" onclick="buildFacility(' . $x . ', ' . $y . ')">';
                                        echo '<div style="color: #bdc3c7;">+</div>';
                                        echo '</div>';
                                    }
                                }
                            }
                            ?>
                        </div>
                    </div>

                    <!-- 资源概览 -->
                    <div class="internal-section">
                        <div class="section-title">
                            <span class="section-icon">💎</span>
                            资源概览
                        </div>
                        
                        <div class="resource-summary">
                            <div class="resource-item">
                                <div class="resource-icon">⚪</div>
                                <div class="resource-name">亮晶晶</div>
                                <div class="resource-amount"><?php echo number_format($resource->getBrightCrystal()); ?></div>
                                <div class="resource-production">+100/小时</div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">🔴</div>
                                <div class="resource-name">暖洋洋</div>
                                <div class="resource-amount"><?php echo number_format($resource->getWarmCrystal()); ?></div>
                                <div class="resource-production">+80/小时</div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">🔵</div>
                                <div class="resource-name">冷冰冰</div>
                                <div class="resource-amount"><?php echo number_format($resource->getColdCrystal()); ?></div>
                                <div class="resource-production">+90/小时</div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">🟢</div>
                                <div class="resource-name">郁萌萌</div>
                                <div class="resource-amount"><?php echo number_format($resource->getGreenCrystal()); ?></div>
                                <div class="resource-production">+85/小时</div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">🟡</div>
                                <div class="resource-name">昼闪闪</div>
                                <div class="resource-amount"><?php echo number_format($resource->getDayCrystal()); ?></div>
                                <div class="resource-production">+75/小时</div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">⚫</div>
                                <div class="resource-name">夜静静</div>
                                <div class="resource-amount"><?php echo number_format($resource->getNightCrystal()); ?></div>
                                <div class="resource-production">+5/小时</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 士兵和快捷操作 -->
                <div class="internal-grid">
                    <!-- 驻军概览 -->
                    <div class="internal-section">
                        <div class="section-title">
                            <span class="section-icon">⚔️</span>
                            驻军概览
                        </div>
                        
                        <?php if (!empty($soldiers)): ?>
                        <div class="soldiers-list">
                            <?php foreach ($soldiers as $soldier): ?>
                            <div class="soldier-item">
                                <div class="soldier-info">
                                    <span class="soldier-icon">
                                        <?php
                                        $soldierIcons = [
                                            'pawn' => '♟️',
                                            'knight' => '♞',
                                            'rook' => '♜',
                                            'bishop' => '♝',
                                            'golem' => '🗿',
                                            'scout' => '👁️'
                                        ];
                                        echo isset($soldierIcons[$soldier->getType()]) ? 
                                             $soldierIcons[$soldier->getType()] : '⚔️';
                                        ?>
                                    </span>
                                    <div>
                                        <div class="soldier-name"><?php echo $soldier->getName(); ?></div>
                                        <span class="soldier-level">Lv.<?php echo $soldier->getLevel(); ?></span>
                                    </div>
                                </div>
                                <div class="soldier-quantity"><?php echo number_format($soldier->getQuantity()); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="no-data">暂无驻军</div>
                        <?php endif; ?>
                    </div>

                    <!-- 快捷操作 -->
                    <div class="internal-section">
                        <div class="section-title">
                            <span class="section-icon">⚡</span>
                            快捷操作
                        </div>
                        
                        <div class="quick-actions">
                            <a href="barracks.php?city_id=<?php echo $selectedCityId; ?>" class="action-button">
                                ⚔️ 训练士兵
                            </a>
                            <a href="research.php" class="action-button secondary">
                                🔬 科技研究
                            </a>
                            <a href="generals.php" class="action-button success">
                                👥 武将管理
                            </a>
                            <a href="armies.php" class="action-button warning">
                                🚀 军队管理
                            </a>
                            <a href="territory.php" class="action-button">
                                🗺️ 领地管理
                            </a>
                            <a href="recruit.php" class="action-button secondary">
                                🎲 招募武将
                            </a>
                        </div>
                    </div>
                </div>

                <?php endif; ?>

                <?php else: ?>
                <div class="no-city">
                    <h3>暂无城池</h3>
                    <p>您还没有任何城池。请先在地图上建立城池。</p>
                    <button onclick="window.location.href='map.php'">前往地图</button>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- 页脚 -->
        <footer>
            <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?> - 版本 <?php echo GAME_VERSION; ?></p>
        </footer>
    </div>

    <script>
        function changeCitySelection() {
            const select = document.getElementById('city-select');
            const cityId = select.value;
            window.location.href = 'internal.php?city_id=' + cityId;
        }
        
        function manageFacility(facilityId) {
            // 这里可以打开设施管理弹窗或跳转到设施详情页
            alert('设施管理功能开发中... 设施ID: ' + facilityId);
        }
        
        function buildFacility(x, y) {
            // 这里可以打开建造设施弹窗
            alert('建造设施功能开发中... 位置: (' + x + ', ' + y + ')');
        }
    </script>
</body>
</html>

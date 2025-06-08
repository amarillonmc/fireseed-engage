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

// 获取用户武将
$generals = General::getUserGenerals($user->getUserId());

// 获取用户军队
$armies = Army::getUserArmies($user->getUserId());

// 获取用户科技效果
$technologyEffects = UserTechnology::getUserTechnologyEffects($user->getUserId());

// 计算统计数据
$totalCities = count($cities);
$totalGenerals = count($generals);
$totalArmies = count($armies);

// 计算总战斗力
$totalCombatPower = 0;
foreach ($armies as $army) {
    $totalCombatPower += $army->getCombatPower();
}

// 页面标题
$pageTitle = '用户档案';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
        }
        
        .profile-name {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .profile-level {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stats-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .stats-title {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .stats-icon {
            font-size: 24px;
            margin-right: 10px;
        }
        
        .stats-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .stats-item:last-child {
            border-bottom: none;
        }
        
        .stats-label {
            color: #7f8c8d;
        }
        
        .stats-value {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .resources-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
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
        
        .technologies-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .tech-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .tech-item:last-child {
            border-bottom: none;
        }
        
        .tech-name {
            color: #2c3e50;
            font-weight: 500;
        }
        
        .tech-effect {
            color: #27ae60;
            font-weight: bold;
        }
        
        .cities-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .city-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .city-item:last-child {
            border-bottom: none;
        }
        
        .city-name {
            color: #2c3e50;
            font-weight: 500;
        }
        
        .city-coords {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .city-main {
            background: #f39c12;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
        }
        
        .generals-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
        }
        
        .rarity-count {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            text-align: center;
        }
        
        .rarity-label {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        
        .rarity-number {
            font-size: 18px;
            font-weight: bold;
        }
        
        .rarity-B { color: #95a5a6; }
        .rarity-A { color: #3498db; }
        .rarity-S { color: #9b59b6; }
        .rarity-SS { color: #e74c3c; }
        .rarity-P { color: #f39c12; }
        
        .no-data {
            text-align: center;
            color: #7f8c8d;
            padding: 20px;
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
            <div class="profile-container">
                <!-- 用户信息头部 -->
                <div class="profile-header">
                    <div class="profile-avatar">👤</div>
                    <div class="profile-name"><?php echo htmlspecialchars($user->getUsername()); ?></div>
                    <div class="profile-level">等级 <?php echo $user->getLevel(); ?></div>
                </div>

                <!-- 统计数据网格 -->
                <div class="stats-grid">
                    <!-- 基础统计 -->
                    <div class="stats-card">
                        <div class="stats-title">
                            <span class="stats-icon">📊</span>
                            基础统计
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">用户等级</span>
                            <span class="stats-value"><?php echo $user->getLevel(); ?></span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">城池数量</span>
                            <span class="stats-value"><?php echo $totalCities; ?></span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">武将数量</span>
                            <span class="stats-value"><?php echo $totalGenerals; ?></span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">军队数量</span>
                            <span class="stats-value"><?php echo $totalArmies; ?></span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">总战斗力</span>
                            <span class="stats-value"><?php echo number_format($totalCombatPower); ?></span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">武将费用上限</span>
                            <span class="stats-value"><?php echo $user->getMaxGeneralCost(); ?></span>
                        </div>
                    </div>

                    <!-- 资源统计 -->
                    <div class="stats-card">
                        <div class="stats-title">
                            <span class="stats-icon">💎</span>
                            资源统计
                        </div>
                        <div class="resources-grid">
                            <div class="resource-item">
                                <div class="resource-icon">⚪</div>
                                <div class="resource-name">亮晶晶</div>
                                <div class="resource-amount"><?php echo number_format($resource->getBrightCrystal()); ?></div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">🔴</div>
                                <div class="resource-name">暖洋洋</div>
                                <div class="resource-amount"><?php echo number_format($resource->getWarmCrystal()); ?></div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">🔵</div>
                                <div class="resource-name">冷冰冰</div>
                                <div class="resource-amount"><?php echo number_format($resource->getColdCrystal()); ?></div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">🟢</div>
                                <div class="resource-name">郁萌萌</div>
                                <div class="resource-amount"><?php echo number_format($resource->getGreenCrystal()); ?></div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">🟡</div>
                                <div class="resource-name">昼闪闪</div>
                                <div class="resource-amount"><?php echo number_format($resource->getDayCrystal()); ?></div>
                            </div>
                            <div class="resource-item">
                                <div class="resource-icon">⚫</div>
                                <div class="resource-name">夜静静</div>
                                <div class="resource-amount"><?php echo number_format($resource->getNightCrystal()); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- 武将统计 -->
                    <div class="stats-card">
                        <div class="stats-title">
                            <span class="stats-icon">⚔️</span>
                            武将统计
                        </div>
                        <?php if (!empty($generals)): ?>
                        <?php
                            $rarityCounts = ['B' => 0, 'A' => 0, 'S' => 0, 'SS' => 0, 'P' => 0];
                            foreach ($generals as $general) {
                                $rarity = $general->getRarity();
                                if (isset($rarityCounts[$rarity])) {
                                    $rarityCounts[$rarity]++;
                                }
                            }
                        ?>
                        <div class="generals-summary">
                            <?php foreach ($rarityCounts as $rarity => $count): ?>
                            <div class="rarity-count">
                                <div class="rarity-label"><?php echo $rarity; ?>级</div>
                                <div class="rarity-number rarity-<?php echo $rarity; ?>"><?php echo $count; ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="no-data">暂无武将</div>
                        <?php endif; ?>
                    </div>

                    <!-- 科技效果 -->
                    <div class="stats-card">
                        <div class="stats-title">
                            <span class="stats-icon">🔬</span>
                            科技效果
                        </div>
                        <?php if (!empty($technologyEffects)): ?>
                        <div class="technologies-list">
                            <?php foreach ($technologyEffects as $effect): ?>
                            <div class="tech-item">
                                <span class="tech-name"><?php echo $effect['name']; ?> (Lv.<?php echo $effect['level']; ?>)</span>
                                <span class="tech-effect">+<?php echo number_format($effect['effect_value'] * 100, 1); ?>%</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="no-data">暂无科技效果</div>
                        <?php endif; ?>
                    </div>

                    <!-- 城池列表 -->
                    <div class="stats-card">
                        <div class="stats-title">
                            <span class="stats-icon">🏰</span>
                            城池列表
                        </div>
                        <?php if (!empty($cities)): ?>
                        <div class="cities-list">
                            <?php foreach ($cities as $city): ?>
                            <?php $coordinates = $city->getCoordinates(); ?>
                            <div class="city-item">
                                <div>
                                    <div class="city-name">
                                        <?php echo htmlspecialchars($city->getName()); ?>
                                        <?php if ($mainCity && $city->getCityId() == $mainCity->getCityId()): ?>
                                        <span class="city-main">主城</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="city-coords">(<?php echo $coordinates[0]; ?>, <?php echo $coordinates[1]; ?>)</div>
                                </div>
                                <div>
                                    <button onclick="window.location.href='index.php?city_id=<?php echo $city->getCityId(); ?>'">
                                        查看
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="no-data">暂无城池</div>
                        <?php endif; ?>
                    </div>

                    <!-- 账户信息 -->
                    <div class="stats-card">
                        <div class="stats-title">
                            <span class="stats-icon">👤</span>
                            账户信息
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">用户名</span>
                            <span class="stats-value"><?php echo htmlspecialchars($user->getUsername()); ?></span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">邮箱</span>
                            <span class="stats-value"><?php echo htmlspecialchars($user->getEmail()); ?></span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">注册时间</span>
                            <span class="stats-value"><?php echo date('Y-m-d', strtotime($user->getCreatedAt())); ?></span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">最后登录</span>
                            <span class="stats-value"><?php echo date('Y-m-d H:i', strtotime($user->getLastLogin())); ?></span>
                        </div>
                    </div>
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

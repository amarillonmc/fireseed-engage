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
    header('Location: index.php');
    exit;
}

// 获取科技类别
$category = isset($_GET['category']) ? $_GET['category'] : 'resource';
$validCategories = ['resource', 'soldier', 'city', 'governor'];
if (!in_array($category, $validCategories)) {
    $category = 'resource';
}

// 获取用户的科技
$userTechnologies = UserTechnology::getUserTechnologiesByCategory($user->getUserId(), $category);

// 获取研究所信息
$researchLabs = Facility::getCityFacilitiesByType($mainCity->getCityId(), 'research_lab');
$researchLabLevel = !empty($researchLabs) ? $researchLabs[0]->getLevel() : 0;

// 页面标题
$pageTitle = '科技研究';

// 科技类别名称映射
$categoryNames = [
    'resource' => '资源科技',
    'soldier' => '士兵科技',
    'city' => '城池科技',
    'governor' => '总督府科技'
];
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .research-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .research-header {
            background: #2c3e50;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .research-lab-info {
            background: #34495e;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .category-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .category-tab {
            padding: 10px 20px;
            background: #ecf0f1;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            color: #2c3e50;
            transition: background-color 0.3s;
        }
        
        .category-tab.active {
            background: #3498db;
            color: white;
        }
        
        .category-tab:hover {
            background: #bdc3c7;
        }
        
        .category-tab.active:hover {
            background: #2980b9;
        }
        
        .technologies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
        }
        
        .technology-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .technology-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .technology-name {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .technology-level {
            background: #3498db;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 14px;
        }
        
        .technology-description {
            color: #7f8c8d;
            margin-bottom: 15px;
            line-height: 1.4;
        }
        
        .technology-effect {
            background: #ecf0f1;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        
        .effect-current {
            color: #27ae60;
            font-weight: bold;
        }
        
        .effect-next {
            color: #f39c12;
            font-size: 14px;
        }
        
        .research-cost {
            margin-bottom: 15px;
        }
        
        .cost-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .cost-resources {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .cost-item {
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 14px;
        }
        
        .cost-item.insufficient {
            background: #ffebee;
            color: #c62828;
        }
        
        .research-actions {
            display: flex;
            gap: 10px;
        }
        
        .research-btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        
        .research-btn.start {
            background: #27ae60;
            color: white;
        }
        
        .research-btn.start:hover {
            background: #229954;
        }
        
        .research-btn.researching {
            background: #f39c12;
            color: white;
            cursor: not-allowed;
        }
        
        .research-btn.max-level {
            background: #95a5a6;
            color: white;
            cursor: not-allowed;
        }
        
        .research-btn.insufficient {
            background: #e74c3c;
            color: white;
            cursor: not-allowed;
        }
        
        .research-time {
            font-size: 14px;
            color: #f39c12;
            margin-top: 5px;
        }
        
        .emoji-icon {
            font-size: 24px;
            margin-right: 10px;
        }
        
        .no-research-lab {
            background: #ffebee;
            color: #c62828;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
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
                    <li><a href="generals.php">武将</a></li>
                    <li><a href="armies.php">军队</a></li>
                    <li><a href="map.php">地图</a></li>
                    <li><a href="territory.php">领地</a></li>
                    <li><a href="internal.php">内政</a></li>
                    <li><a href="ranking.php">排名</a></li>
                    <li class="circuit-points">
                        <?php echo renderImageResource(
                            'resource_circuit_points',
                            24,
                            ['alt' => '思考回路 / Circuit Points']
                        ); ?>
                        <span class="circuit-label">思考回路:</span>
                        <span class="circuit-value">
                            <?php echo number_format($user->getCircuitPoints()); ?> /
                            <?php echo number_format($user->getMaxCircuitPoints()); ?>
                        </span>
                    </li>
                </ul>
            </nav>
        </header>

        <!-- 主要内容 -->
        <main>
            <div class="research-container">
                <!-- 研究所信息 -->
                <div class="research-header">
                    <h3>
                        <span class="emoji-icon">
                            <?php echo renderImageResource(
                                'facility_research_lab',
                                32,
                                ['decorative' => true]
                            ); ?>
                        </span>
                        科技研究中心
                    </h3>
                    <div class="research-lab-info">
                        <?php if ($researchLabLevel > 0): ?>
                            <p><strong>研究所等级:</strong> <?php echo $researchLabLevel; ?></p>
                            <p><strong>可研究科技等级:</strong> 最高 <?php echo $researchLabLevel; ?> 级</p>
                        <?php else: ?>
                            <p><strong>状态:</strong> 未建造研究所</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($researchLabLevel == 0): ?>
                <div class="no-research-lab">
                    <h3>⚠️ 需要建造研究所</h3>
                    <p>您需要在城池中建造研究所才能进行科技研究。</p>
                    <button onclick="window.location.href='index.php'">返回主基地</button>
                </div>
                <?php else: ?>

                <!-- 科技类别标签 -->
                <div class="category-tabs">
                    <?php foreach ($validCategories as $cat): ?>
                    <a href="research.php?category=<?php echo $cat; ?>" 
                       class="category-tab <?php echo $category == $cat ? 'active' : ''; ?>">
                        <?php echo $categoryNames[$cat]; ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- 科技列表 -->
                <div class="technologies-grid">
                    <?php foreach ($userTechnologies as $techData): ?>
                    <?php 
                        $technology = $techData['technology'];
                        $userTech = $techData['user_tech'];
                        $currentLevel = $userTech->getLevel();
                        $maxLevel = $technology->getMaxLevel();
                        $isResearching = $userTech->isResearching();
                        $canResearch = $currentLevel < $maxLevel && !$isResearching && $researchLabLevel > $currentLevel;
                        
                        // 计算升级费用
                        $upgradeCost = [];
                        $hasEnoughResources = true;
                        if ($canResearch) {
                            $upgradeCost = $technology->getUpgradeCostAtLevel($currentLevel);
                            foreach ($upgradeCost as $resourceType => $cost) {
                                if ($resource->getResourceByType($resourceType) < $cost) {
                                    $hasEnoughResources = false;
                                    break;
                                }
                            }
                        }
                        
                        // 计算当前和下一级效果
                        $currentEffect = $currentLevel > 0 ? $technology->getEffectAtLevel($currentLevel) : 0;
                        $nextEffect = $currentLevel < $maxLevel ? $technology->getEffectAtLevel($currentLevel + 1) : 0;
                    ?>
                    <div class="technology-card">
                        <div class="technology-header">
                            <div class="technology-name">
                                <?php echo $technology->getName(); ?>
                                <small>
                                    <?php echo $technology->getScope() === 'permanent'
                                        ? '［永久］'
                                        : '［本赛季］'; ?>
                                </small>
                            </div>
                            <div class="technology-level">
                                <?php echo $currentLevel; ?> / <?php echo $maxLevel; ?>
                            </div>
                        </div>
                        
                        <div class="technology-description">
                            <?php echo $technology->getDescription(); ?>
                        </div>
                        
                        <div class="technology-effect">
                            <?php if ($currentLevel > 0): ?>
                            <div class="effect-current">
                                当前效果: <?php echo $technology->formatEffectAtLevel($currentLevel); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($nextEffect > 0): ?>
                            <div class="effect-next">
                                下一级效果: <?php echo $technology->formatEffectAtLevel($currentLevel + 1); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($canResearch && !empty($upgradeCost)): ?>
                        <div class="research-cost">
                            <div class="cost-title">升级费用:</div>
                            <div class="cost-resources">
                                <?php foreach ($upgradeCost as $resourceType => $cost): ?>
                                <?php 
                                    $userAmount = $resource->getResourceByType($resourceType);
                                    $sufficient = $userAmount >= $cost;
                                    $resourceNames = [
                                        'bright' => '亮晶晶',
                                        'warm' => '暖洋洋', 
                                        'cold' => '冷冰冰',
                                        'green' => '郁萌萌',
                                        'day' => '昼闪闪',
                                        'night' => '夜静静'
                                    ];
                                ?>
                                <div class="cost-item <?php echo $sufficient ? '' : 'insufficient'; ?>">
                                    <?php echo $resourceNames[$resourceType]; ?>: <?php echo number_format($cost); ?>
                                    (<?php echo number_format($userAmount); ?>)
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="research-actions">
                            <?php if ($isResearching): ?>
                            <button class="research-btn researching" disabled>
                                研究中...
                            </button>
                            <div class="research-time">
                                完成时间: <?php echo date('Y-m-d H:i:s', strtotime($userTech->getResearchTime())); ?>
                            </div>
                            <?php elseif ($currentLevel >= $maxLevel): ?>
                            <button class="research-btn max-level" disabled>
                                已达最高等级
                            </button>
                            <?php elseif (!$canResearch): ?>
                            <button class="research-btn insufficient" disabled>
                                研究所等级不足
                            </button>
                            <?php elseif (!$hasEnoughResources): ?>
                            <button class="research-btn insufficient" disabled>
                                资源不足
                            </button>
                            <?php else: ?>
                            <button class="research-btn start" onclick="startResearch(<?php echo $technology->getTechId(); ?>)">
                                开始研究
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
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
        function startResearch(techId) {
            if (confirm('确定要开始研究这项科技吗？')) {
                fetch('api/start_research.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        tech_id: techId,
                        csrf_token: <?php echo json_encode(getCsrfToken()); ?>
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('研究开始！');
                        location.reload();
                    } else {
                        alert('研究失败: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('发生错误，请重试');
                });
            }
        }
        
        // 定期检查研究完成情况
        function checkResearchCompletion() {
            fetch('api/check_research_completion.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.completed_research && data.completed_research.length > 0) {
                        data.completed_research.forEach(research => {
                            showNotification(`科技 ${research.tech_name} 研究完成！当前等级: ${research.level}`);
                        });
                        location.reload();
                    }
                })
                .catch(error => console.error('Error checking research completion:', error));
        }
        
        // 每30秒检查一次研究完成情况
        setInterval(checkResearchCompletion, 30000);
        
        function showNotification(message) {
            // 简单的通知实现
            alert(message);
        }
    </script>
</body>
</html>

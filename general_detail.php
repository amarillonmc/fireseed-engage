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

// 获取武将ID
$generalId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($generalId <= 0) {
    header('Location: generals.php');
    exit;
}

// 获取武将信息
$general = new General($generalId);
if (!$general->isValid() || $general->getOwnerId() != $user->getUserId()) {
    header('Location: generals.php');
    exit;
}

// 处理升级请求
$upgradeResult = null;
if (isset($_POST['action']) && $_POST['action'] == 'upgrade') {
    $upgradeCost = $general->getUpgradeCost();
    
    // 检查资源是否足够
    if ($resource->getBrightCrystal() >= $upgradeCost) {
        // 扣除资源
        $resource->consumeBrightCrystal($upgradeCost);
        
        // 升级武将
        if ($general->levelUp()) {
            $upgradeResult = [
                'success' => true,
                'message' => '武将升级成功！',
                'new_level' => $general->getLevel(),
                'new_attack' => $general->getAttack(),
                'new_defense' => $general->getDefense(),
                'new_speed' => $general->getSpeed(),
                'new_intelligence' => $general->getIntelligence()
            ];
        } else {
            $upgradeResult = [
                'success' => false,
                'message' => '武将升级失败，请稍后再试。'
            ];
        }
    } else {
        $upgradeResult = [
            'success' => false,
            'message' => '亮晶晶不足，无法升级武将。'
        ];
    }
}

// 处理技能卡牌添加请求
$skillResult = null;
if (isset($_POST['action']) && $_POST['action'] == 'add_skill') {
    $skillName = isset($_POST['skill_name']) ? $_POST['skill_name'] : '';
    $skillSlot = isset($_POST['skill_slot']) ? intval($_POST['skill_slot']) : 0;
    $skillEffect = isset($_POST['skill_effect']) ? json_decode($_POST['skill_effect'], true) : [];
    
    if (!empty($skillName) && $skillSlot > 0 && !empty($skillEffect)) {
        if ($general->addSkillCard($skillName, $skillSlot, $skillEffect)) {
            $skillResult = [
                'success' => true,
                'message' => '技能卡牌添加成功！'
            ];
        } else {
            $skillResult = [
                'success' => false,
                'message' => '技能卡牌添加失败，请稍后再试。'
            ];
        }
    } else {
        $skillResult = [
            'success' => false,
            'message' => '参数错误，无法添加技能卡牌。'
        ];
    }
}

// 处理技能卡牌移除请求
if (isset($_POST['action']) && $_POST['action'] == 'remove_skill') {
    $skillSlot = isset($_POST['skill_slot']) ? intval($_POST['skill_slot']) : 0;
    
    if ($skillSlot > 0) {
        if ($general->removeSkillCard($skillSlot)) {
            $skillResult = [
                'success' => true,
                'message' => '技能卡牌移除成功！'
            ];
        } else {
            $skillResult = [
                'success' => false,
                'message' => '技能卡牌移除失败，请稍后再试。'
            ];
        }
    } else {
        $skillResult = [
            'success' => false,
            'message' => '参数错误，无法移除技能卡牌。'
        ];
    }
}

// 页面标题
$pageTitle = '武将详情 - ' . $general->getName();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .general-detail {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .general-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .general-title {
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .general-title .rarity {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .general-title .rarity.B {
            background-color: #e0e0e0;
            color: #333;
        }
        
        .general-title .rarity.A {
            background-color: #a5d6a7;
            color: #1b5e20;
        }
        
        .general-title .rarity.S {
            background-color: #90caf9;
            color: #0d47a1;
        }
        
        .general-title .rarity.SS {
            background-color: #ce93d8;
            color: #4a148c;
        }
        
        .general-title .rarity.P {
            background-color: #ffcc80;
            color: #e65100;
        }
        
        .general-info {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .general-info-item {
            width: 33.33%;
            padding: 5px 0;
        }
        
        .general-info-item .label {
            font-weight: bold;
            margin-right: 5px;
        }
        
        .general-attributes {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .attribute-card {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .attribute-card .name {
            font-weight: bold;
            margin-bottom: 5px;
            display: block;
        }
        
        .attribute-card .value {
            font-size: 24px;
            color: #333;
        }
        
        .general-skills {
            margin-bottom: 20px;
        }
        
        .skills-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .skills-title {
            margin: 0;
        }
        
        .skill-card {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 10px;
        }
        
        .skill-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .skill-name {
            font-weight: bold;
            font-size: 16px;
        }
        
        .skill-type {
            color: #666;
            font-style: italic;
        }
        
        .skill-level {
            background-color: #333;
            color: #fff;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .skill-effects {
            margin-top: 10px;
        }
        
        .skill-effect {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .skill-effect .name {
            font-weight: bold;
        }
        
        .upgrade-section {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .upgrade-title {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .upgrade-cost {
            margin-bottom: 15px;
        }
        
        .upgrade-button {
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .upgrade-button:hover {
            background-color: #45a049;
        }
        
        .upgrade-button:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        
        .result-message {
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
        }
        
        .result-message.success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .result-message.error {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .actions button {
            padding: 10px 20px;
            background-color: #333;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .actions button:hover {
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
            
            <!-- 武将详情 -->
            <div class="general-detail">
                <div class="general-header">
                    <h3 class="general-title">
                        <?php echo $general->getName(); ?>
                        <span class="rarity <?php echo $general->getRarity(); ?>"><?php echo $general->getRarity(); ?></span>
                    </h3>
                    <div class="general-controls">
                        <button onclick="window.location.href='generals.php'">返回武将列表</button>
                    </div>
                </div>
                
                <div class="general-info">
                    <div class="general-info-item">
                        <span class="label">等级:</span>
                        <span class="value"><?php echo $general->getLevel(); ?></span>
                    </div>
                    <div class="general-info-item">
                        <span class="label">COST:</span>
                        <span class="value"><?php echo $general->getCost(); ?></span>
                    </div>
                    <div class="general-info-item">
                        <span class="label">元素:</span>
                        <span class="value"><?php echo $general->getElement(); ?></span>
                    </div>
                    <div class="general-info-item">
                        <span class="label">来源:</span>
                        <span class="value"><?php echo $general->getSource(); ?></span>
                    </div>
                    <div class="general-info-item">
                        <span class="label">HP:</span>
                        <span class="value"><?php echo $general->getHp(); ?> / <?php echo $general->getMaxHp(); ?></span>
                    </div>
                </div>
                
                <div class="general-attributes">
                    <div class="attribute-card">
                        <span class="name">攻击力</span>
                        <span class="value"><?php echo $general->getAttack(); ?></span>
                    </div>
                    <div class="attribute-card">
                        <span class="name">守备力</span>
                        <span class="value"><?php echo $general->getDefense(); ?></span>
                    </div>
                    <div class="attribute-card">
                        <span class="name">速度</span>
                        <span class="value"><?php echo $general->getSpeed(); ?></span>
                    </div>
                    <div class="attribute-card">
                        <span class="name">智力</span>
                        <span class="value"><?php echo $general->getIntelligence(); ?></span>
                    </div>
                </div>
                
                <div class="general-skills">
                    <div class="skills-header">
                        <h4 class="skills-title">技能卡牌</h4>
                    </div>
                    
                    <?php if (empty($general->getSkills())): ?>
                    <p>该武将没有技能卡牌。</p>
                    <?php else: ?>
                    <?php foreach ($general->getSkills() as $skill): ?>
                    <div class="skill-card">
                        <div class="skill-header">
                            <span class="skill-name"><?php echo $skill->getSkillName(); ?></span>
                            <span class="skill-type"><?php echo $skill->getSkillType(); ?> (槽位: <?php echo $skill->getSlot(); ?>)</span>
                            <span class="skill-level">Lv.<?php echo $skill->getSkillLevel(); ?></span>
                        </div>
                        <div class="skill-effects">
                            <?php foreach ($skill->getEffect() as $effectName => $effectValue): ?>
                            <div class="skill-effect">
                                <span class="name"><?php echo $effectName; ?></span>
                                <span class="value">+<?php echo $effectValue; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($skill->getSlot() > 0): ?>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="action" value="remove_skill">
                            <input type="hidden" name="skill_slot" value="<?php echo $skill->getSlot(); ?>">
                            <button type="submit" class="remove-skill-button">移除技能卡牌</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="upgrade-section">
                    <h4 class="upgrade-title">升级武将</h4>
                    <div class="upgrade-cost">
                        <p>升级所需亮晶晶: <strong><?php echo number_format($general->getUpgradeCost()); ?></strong></p>
                        <p>当前亮晶晶: <strong><?php echo number_format($resource->getBrightCrystal()); ?></strong></p>
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="upgrade">
                        <button type="submit" class="upgrade-button" <?php echo $resource->getBrightCrystal() < $general->getUpgradeCost() ? 'disabled' : ''; ?>>
                            升级武将
                        </button>
                    </form>
                    
                    <?php if ($upgradeResult): ?>
                    <div class="result-message <?php echo $upgradeResult['success'] ? 'success' : 'error'; ?>">
                        <?php echo $upgradeResult['message']; ?>
                        <?php if ($upgradeResult['success']): ?>
                        <p>新等级: <?php echo $upgradeResult['new_level']; ?></p>
                        <p>新属性: 攻击力 <?php echo $upgradeResult['new_attack']; ?>, 守备力 <?php echo $upgradeResult['new_defense']; ?>, 速度 <?php echo $upgradeResult['new_speed']; ?>, 智力 <?php echo $upgradeResult['new_intelligence']; ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="actions">
                    <button onclick="window.location.href='assign_general.php?id=<?php echo $general->getGeneralId(); ?>'">分配武将</button>
                    <button onclick="window.location.href='generals.php'">返回武将列表</button>
                </div>
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

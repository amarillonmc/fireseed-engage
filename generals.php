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

// 获取用户的所有武将
$generals = General::getUserGenerals($user->getUserId());

// 获取排序参数
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'level';
$sortOrder = isset($_GET['order']) ? $_GET['order'] : 'desc';

// 排序武将
usort($generals, function($a, $b) use ($sortBy, $sortOrder) {
    $aValue = 0;
    $bValue = 0;

    switch ($sortBy) {
        case 'name':
            $aValue = $a->getName();
            $bValue = $b->getName();
            break;
        case 'level':
            $aValue = $a->getLevel();
            $bValue = $b->getLevel();
            break;
        case 'rarity':
            $rarityOrder = [
                'B' => 1,
                'A' => 2,
                'S' => 3,
                'SS' => 4,
                'P' => 5
            ];
            $aValue = $rarityOrder[$a->getRarity()];
            $bValue = $rarityOrder[$b->getRarity()];
            break;
        case 'cost':
            $aValue = $a->getCost();
            $bValue = $b->getCost();
            break;
        case 'element':
            $aValue = $a->getElement();
            $bValue = $b->getElement();
            break;
        case 'attack':
            $aValue = $a->getAttack();
            $bValue = $b->getAttack();
            break;
        case 'defense':
            $aValue = $a->getDefense();
            $bValue = $b->getDefense();
            break;
        case 'speed':
            $aValue = $a->getSpeed();
            $bValue = $b->getSpeed();
            break;
        case 'intelligence':
            $aValue = $a->getIntelligence();
            $bValue = $b->getIntelligence();
            break;
    }

    if ($sortOrder == 'asc') {
        return $aValue <=> $bValue;
    } else {
        return $bValue <=> $aValue;
    }
});

// 页面标题
$pageTitle = '武将管理';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .generals-container {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .generals-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .generals-title {
            margin: 0;
        }

        .generals-controls {
            display: flex;
            gap: 10px;
        }

        .generals-controls button {
            padding: 5px 10px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }

        .generals-controls button:hover {
            background-color: #555;
        }

        .generals-sort {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .generals-sort label {
            margin-right: 10px;
            font-weight: bold;
        }

        .generals-sort select {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            margin-right: 10px;
        }

        .generals-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .general-card {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            background-color: #f9f9f9;
            transition: transform 0.3s ease;
        }

        .general-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .general-card h4 {
            margin-top: 0;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .general-card .rarity {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }

        .general-card .rarity.B {
            background-color: #e0e0e0;
            color: #333;
        }

        .general-card .rarity.A {
            background-color: #a5d6a7;
            color: #1b5e20;
        }

        .general-card .rarity.S {
            background-color: #90caf9;
            color: #0d47a1;
        }

        .general-card .rarity.SS {
            background-color: #ce93d8;
            color: #4a148c;
        }

        .general-card .rarity.P {
            background-color: #ffcc80;
            color: #e65100;
        }

        .general-card .element {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .general-card .source {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .general-card .skills {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #ddd;
        }

        .general-card .skills h5 {
            margin-top: 0;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .general-card .skill {
            font-size: 13px;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
        }

        .general-card .skill-name {
            font-weight: bold;
        }

        .general-card .skill-level {
            color: #666;
        }

        .general-card .skill-type {
            color: #999;
            font-style: italic;
        }

        .general-card .level {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .general-card .attributes {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .general-card .attribute {
            display: flex;
            justify-content: space-between;
        }

        .general-card .attribute .name {
            font-weight: bold;
        }

        .general-card .assignment {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #ddd;
            font-size: 14px;
            color: #666;
        }

        .general-card .actions {
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
        }

        .general-card .actions button {
            padding: 5px 10px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
        }

        .general-card .actions button:hover {
            background-color: #555;
        }

        .no-generals {
            padding: 20px;
            text-align: center;
            background-color: #f5f5f5;
            border-radius: 5px;
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

            <!-- 武将容器 -->
            <div class="generals-container">
                <div class="generals-header">
                    <h3 class="generals-title">我的武将</h3>
                    <div class="generals-controls">
                        <button onclick="window.location.href='recruit.php'">招募武将</button>
                    </div>
                </div>

                <div class="generals-sort">
                    <label for="sort-by">排序方式:</label>
                    <select id="sort-by">
                        <option value="level" <?php echo $sortBy == 'level' ? 'selected' : ''; ?>>等级</option>
                        <option value="name" <?php echo $sortBy == 'name' ? 'selected' : ''; ?>>名称</option>
                        <option value="rarity" <?php echo $sortBy == 'rarity' ? 'selected' : ''; ?>>稀有度</option>
                        <option value="cost" <?php echo $sortBy == 'cost' ? 'selected' : ''; ?>>COST</option>
                        <option value="element" <?php echo $sortBy == 'element' ? 'selected' : ''; ?>>元素</option>
                        <option value="attack" <?php echo $sortBy == 'attack' ? 'selected' : ''; ?>>攻击力</option>
                        <option value="defense" <?php echo $sortBy == 'defense' ? 'selected' : ''; ?>>守备力</option>
                        <option value="speed" <?php echo $sortBy == 'speed' ? 'selected' : ''; ?>>速度</option>
                        <option value="intelligence" <?php echo $sortBy == 'intelligence' ? 'selected' : ''; ?>>智力</option>
                    </select>

                    <label for="sort-order">排序顺序:</label>
                    <select id="sort-order">
                        <option value="desc" <?php echo $sortOrder == 'desc' ? 'selected' : ''; ?>>降序</option>
                        <option value="asc" <?php echo $sortOrder == 'asc' ? 'selected' : ''; ?>>升序</option>
                    </select>
                </div>

                <?php if (empty($generals)): ?>
                <div class="no-generals">
                    <p>您还没有任何武将。请前往招募页面招募武将。</p>
                    <button onclick="window.location.href='recruit.php'">招募武将</button>
                </div>
                <?php else: ?>
                <div class="generals-list">
                    <?php foreach ($generals as $general): ?>
                    <div class="general-card">
                        <h4>
                            <?php echo $general->getName(); ?>
                            <span class="rarity <?php echo $general->getRarity(); ?>">
                                <?php echo $general->getRarity(); ?>
                            </span>
                        </h4>

                        <div class="level">等级: <?php echo $general->getLevel(); ?> | COST: <?php echo $general->getCost(); ?></div>
                        <div class="element">元素: <?php echo $general->getElement(); ?></div>
                        <div class="source">来源: <?php echo $general->getSource(); ?></div>

                        <div class="attributes">
                            <div class="attribute">
                                <span class="name">攻击力</span>
                                <span class="value"><?php echo $general->getAttack(); ?></span>
                            </div>
                            <div class="attribute">
                                <span class="name">守备力</span>
                                <span class="value"><?php echo $general->getDefense(); ?></span>
                            </div>
                            <div class="attribute">
                                <span class="name">速度</span>
                                <span class="value"><?php echo $general->getSpeed(); ?></span>
                            </div>
                            <div class="attribute">
                                <span class="name">智力</span>
                                <span class="value"><?php echo $general->getIntelligence(); ?></span>
                            </div>
                            <div class="attribute">
                                <span class="name">HP</span>
                                <span class="value"><?php echo $general->getHp(); ?> / <?php echo $general->getMaxHp(); ?></span>
                            </div>
                        </div>

                        <div class="skills">
                            <h5>技能卡牌</h5>
                            <?php foreach ($general->getSkills() as $skill): ?>
                            <div class="skill">
                                <span class="skill-name"><?php echo $skill->getSkillName(); ?></span>
                                <span class="skill-level">Lv.<?php echo $skill->getSkillLevel(); ?></span>
                                <span class="skill-type">(<?php echo $skill->getSkillType(); ?>)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php
                        $assignment = $general->getAssignment();
                        if ($assignment) {
                            $assignmentType = $assignment->getAssignmentType();
                            $targetId = $assignment->getTargetId();
                            $assignmentText = '';

                            if ($assignmentType == 'city') {
                                $city = new City($targetId);
                                if ($city->isValid()) {
                                    $assignmentText = '分配到城池: ' . $city->getName();
                                }
                            } elseif ($assignmentType == 'army') {
                                $army = new Army($targetId);
                                if ($army->isValid()) {
                                    $assignmentText = '分配到军队: ' . $army->getName();
                                }
                            }

                            if ($assignmentText) {
                                echo '<div class="assignment">' . $assignmentText . '</div>';
                            }
                        }
                        ?>

                        <div class="actions">
                            <button onclick="window.location.href='general_detail.php?id=<?php echo $general->getGeneralId(); ?>'">查看详情</button>
                            <button onclick="window.location.href='assign_general.php?id=<?php echo $general->getGeneralId(); ?>'">分配武将</button>
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

    <script src="assets/js/script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 排序选择变更事件
            document.getElementById('sort-by').addEventListener('change', function() {
                updateSortUrl();
            });

            document.getElementById('sort-order').addEventListener('change', function() {
                updateSortUrl();
            });

            function updateSortUrl() {
                const sortBy = document.getElementById('sort-by').value;
                const sortOrder = document.getElementById('sort-order').value;
                window.location.href = `generals.php?sort=${sortBy}&order=${sortOrder}`;
            }
        });
    </script>
</body>
</html>

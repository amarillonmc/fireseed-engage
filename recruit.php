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

// 处理招募请求
$recruitResult = null;
$recruitedGeneral = null;

if (isset($_POST['recruit_type'])) {
    $recruitType = $_POST['recruit_type'];
    $recruitCount = isset($_POST['recruit_count']) ? intval($_POST['recruit_count']) : 1;

    // 检查招募次数是否有效
    if ($recruitCount <= 0 || $recruitCount > 10) {
        $recruitCount = 1;
    }

    // 计算招募消耗
    $cost = [];
    $canRecruit = true;

    switch ($recruitType) {
        case 'normal':
            // 普通招募：消耗少量资源
            $cost = [
                'bright' => 100 * $recruitCount,
                'warm' => 100 * $recruitCount,
                'cold' => 100 * $recruitCount,
                'green' => 100 * $recruitCount
            ];

            // 检查资源是否足够
            if ($resource->getBrightCrystal() < $cost['bright'] ||
                $resource->getWarmCrystal() < $cost['warm'] ||
                $resource->getColdCrystal() < $cost['cold'] ||
                $resource->getGreenCrystal() < $cost['green']) {
                $canRecruit = false;
                $recruitResult = [
                    'success' => false,
                    'message' => '资源不足，无法招募'
                ];
            }
            break;

        case 'advanced':
            // 高级招募：消耗大量资源
            $cost = [
                'bright' => 500 * $recruitCount,
                'warm' => 500 * $recruitCount,
                'cold' => 500 * $recruitCount,
                'green' => 500 * $recruitCount,
                'day' => 100 * $recruitCount,
                'night' => 100 * $recruitCount
            ];

            // 检查资源是否足够
            if ($resource->getBrightCrystal() < $cost['bright'] ||
                $resource->getWarmCrystal() < $cost['warm'] ||
                $resource->getColdCrystal() < $cost['cold'] ||
                $resource->getGreenCrystal() < $cost['green'] ||
                $resource->getDayCrystal() < $cost['day'] ||
                $resource->getNightCrystal() < $cost['night']) {
                $canRecruit = false;
                $recruitResult = [
                    'success' => false,
                    'message' => '资源不足，无法招募'
                ];
            }
            break;

        case 'legendary':
            // 传奇招募：消耗思考回路点数
            $cost = [
                'circuit_points' => 50 * $recruitCount
            ];

            // 检查思考回路点数是否足够
            if ($user->getCircuitPoints() < $cost['circuit_points']) {
                $canRecruit = false;
                $recruitResult = [
                    'success' => false,
                    'message' => '思考回路点数不足，无法招募'
                ];
            }
            break;

        default:
            $canRecruit = false;
            $recruitResult = [
                'success' => false,
                'message' => '招募类型无效'
            ];
    }

    // 执行招募
    if ($canRecruit) {
        // 扣除资源
        if (isset($cost['bright'])) {
            $resource->consumeBrightCrystal($cost['bright']);
        }
        if (isset($cost['warm'])) {
            $resource->consumeWarmCrystal($cost['warm']);
        }
        if (isset($cost['cold'])) {
            $resource->consumeColdCrystal($cost['cold']);
        }
        if (isset($cost['green'])) {
            $resource->consumeGreenCrystal($cost['green']);
        }
        if (isset($cost['day'])) {
            $resource->consumeDayCrystal($cost['day']);
        }
        if (isset($cost['night'])) {
            $resource->consumeNightCrystal($cost['night']);
        }
        if (isset($cost['circuit_points'])) {
            $user->reduceCircuitPoints($cost['circuit_points']);
        }

        // 招募武将
        $recruitedGenerals = [];

        for ($i = 0; $i < $recruitCount; $i++) {
            // 根据招募类型确定稀有度概率
            $rarityRoll = mt_rand(1, 100);
            $rarity = 'B';

            switch ($recruitType) {
                case 'normal':
                    // 普通招募：B(70%)，A(25%)，S(5%)，SS(0%)，P(0%)
                    if ($rarityRoll <= 70) {
                        $rarity = 'B';
                    } elseif ($rarityRoll <= 95) {
                        $rarity = 'A';
                    } else {
                        $rarity = 'S';
                    }
                    break;

                case 'advanced':
                    // 高级招募：B(0%)，A(70%)，S(25%)，SS(5%)，P(0%)
                    if ($rarityRoll <= 70) {
                        $rarity = 'A';
                    } elseif ($rarityRoll <= 95) {
                        $rarity = 'S';
                    } else {
                        $rarity = 'SS';
                    }
                    break;

                case 'legendary':
                    // 传奇招募：B(0%)，A(0%)，S(0%)，SS(70%)，P(30%)
                    if ($rarityRoll <= 70) {
                        $rarity = 'SS';
                    } else {
                        $rarity = 'P';
                    }
                    break;
            }

            // 生成随机武将
            $generalId = General::generateRandomGeneral($user->getUserId(), $rarity);

            if ($generalId) {
                $general = new General($generalId);
                $recruitedGenerals[] = $general;

                // 如果是第一个武将，保存用于显示
                if ($i == 0) {
                    $recruitedGeneral = $general;
                }

                // 随机添加一个技能卡牌
                $skillName = General::getRandomSkillName($general->getElement());
                $skillEffect = General::getRandomSkillEffect($general->getElement());
                $general->addSkillCard($skillName, 0, $skillEffect);
            }
        }

        $recruitResult = [
            'success' => true,
            'message' => '招募成功',
            'count' => count($recruitedGenerals)
        ];
    }
}

// 页面标题
$pageTitle = '武将招募';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .recruit-container {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .recruit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .recruit-title {
            margin: 0;
        }

        .recruit-options {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }

        .recruit-option {
            flex: 1;
            min-width: 250px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .recruit-option:hover {
            border-color: #aaa;
            background-color: #f0f0f0;
        }

        .recruit-option.selected {
            border-color: #4CAF50;
            background-color: #e8f5e9;
        }

        .recruit-option h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #333;
        }

        .recruit-option p {
            margin: 5px 0;
            color: #666;
        }

        .recruit-option .cost {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #ddd;
        }

        .recruit-option .cost p {
            margin: 3px 0;
            font-size: 14px;
        }

        .recruit-option .probability {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #ddd;
        }

        .recruit-option .probability p {
            margin: 3px 0;
            font-size: 14px;
        }

        .recruit-actions {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }

        .recruit-actions button {
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s ease;
        }

        .recruit-actions button:hover {
            background-color: #45a049;
        }

        .recruit-actions button:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }

        .recruit-count {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
        }

        .recruit-count label {
            margin-right: 10px;
            font-weight: bold;
        }

        .recruit-count select {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .recruit-result {
            margin-top: 30px;
            padding: 20px;
            border-radius: 5px;
            text-align: center;
        }

        .recruit-result.success {
            background-color: #e8f5e9;
            border: 1px solid #4CAF50;
        }

        .recruit-result.error {
            background-color: #ffebee;
            border: 1px solid #f44336;
        }

        .recruit-result h4 {
            margin-top: 0;
            margin-bottom: 15px;
        }

        .general-card {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .general-card h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #333;
        }

        .general-card .rarity {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
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

        .general-card .attributes {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }

        .general-card .attribute {
            flex: 1;
            min-width: 100px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            text-align: center;
            background-color: #fff;
        }

        .general-card .attribute .name {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .general-card .attribute .value {
            font-size: 18px;
            color: #333;
        }

        .general-card .skills {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed #ddd;
        }

        .general-card .skills h5 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .general-card .skill {
            padding: 8px;
            margin-bottom: 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            background-color: #fff;
        }

        .general-card .skill .name {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .general-card .skill .effect {
            font-size: 14px;
            color: #666;
        }

        .general-card .actions {
            margin-top: 15px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .general-card .actions button {
            padding: 5px 10px;
            background-color: #333;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
        }

        .general-card .actions button:hover {
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

            <!-- 招募容器 -->
            <div class="recruit-container">
                <div class="recruit-header">
                    <h3 class="recruit-title">武将招募</h3>
                </div>

                <form method="post" action="recruit.php">
                    <div class="recruit-options">
                        <div class="recruit-option" data-type="normal">
                            <h4>普通招募</h4>
                            <p>消耗少量资源，获得B级或A级武将</p>

                            <div class="cost">
                                <p><strong>消耗:</strong></p>
                                <p>亮晶晶: 100</p>
                                <p>暖洋洋: 100</p>
                                <p>冷冰冰: 100</p>
                                <p>郁萌萌: 100</p>
                            </div>

                            <div class="probability">
                                <p><strong>概率:</strong></p>
                                <p>B级: 70%</p>
                                <p>A级: 25%</p>
                                <p>S级: 5%</p>
                                <p>SS级: 0%</p>
                                <p>P级: 0%</p>
                            </div>
                        </div>

                        <div class="recruit-option" data-type="advanced">
                            <h4>高级招募</h4>
                            <p>消耗大量资源，获得A级或S级武将</p>

                            <div class="cost">
                                <p><strong>消耗:</strong></p>
                                <p>亮晶晶: 500</p>
                                <p>暖洋洋: 500</p>
                                <p>冷冰冰: 500</p>
                                <p>郁萌萌: 500</p>
                                <p>昼闪闪: 100</p>
                                <p>夜静静: 100</p>
                            </div>

                            <div class="probability">
                                <p><strong>概率:</strong></p>
                                <p>B级: 0%</p>
                                <p>A级: 70%</p>
                                <p>S级: 25%</p>
                                <p>SS级: 5%</p>
                                <p>P级: 0%</p>
                            </div>
                        </div>

                        <div class="recruit-option" data-type="legendary">
                            <h4>传奇招募</h4>
                            <p>消耗思考回路点数，获得SS级或P级武将</p>

                            <div class="cost">
                                <p><strong>消耗:</strong></p>
                                <p>思考回路: 50</p>
                            </div>

                            <div class="probability">
                                <p><strong>概率:</strong></p>
                                <p>B级: 0%</p>
                                <p>A级: 0%</p>
                                <p>S级: 0%</p>
                                <p>SS级: 70%</p>
                                <p>P级: 30%</p>
                            </div>
                        </div>
                    </div>

                    <div class="recruit-count">
                        <label for="recruit-count">招募次数:</label>
                        <select id="recruit-count" name="recruit_count">
                            <option value="1">1次</option>
                            <option value="5">5次</option>
                            <option value="10">10次</option>
                        </select>
                    </div>

                    <input type="hidden" id="recruit-type" name="recruit_type" value="">

                    <div class="recruit-actions">
                        <button type="submit" id="recruit-button" disabled>招募武将</button>
                    </div>
                </form>

                <?php if ($recruitResult): ?>
                <div class="recruit-result <?php echo $recruitResult['success'] ? 'success' : 'error'; ?>">
                    <h4><?php echo $recruitResult['message']; ?></h4>
                    <?php if ($recruitResult['success']): ?>
                    <p>成功招募了 <?php echo $recruitResult['count']; ?> 名武将</p>
                    <?php endif; ?>
                </div>

                <?php if ($recruitedGeneral): ?>
                <div class="general-card">
                    <h4>
                        <?php echo $recruitedGeneral->getName(); ?>
                        <span class="rarity <?php echo $recruitedGeneral->getRarity(); ?>">
                            <?php
                            echo $recruitedGeneral->getRarity();
                            ?>
                        </span>
                    </h4>

                    <div class="attributes">
                        <div class="attribute">
                            <div class="name">攻击力</div>
                            <div class="value"><?php echo $recruitedGeneral->getAttack(); ?></div>
                        </div>
                        <div class="attribute">
                            <div class="name">守备力</div>
                            <div class="value"><?php echo $recruitedGeneral->getDefense(); ?></div>
                        </div>
                        <div class="attribute">
                            <div class="name">速度</div>
                            <div class="value"><?php echo $recruitedGeneral->getSpeed(); ?></div>
                        </div>
                        <div class="attribute">
                            <div class="name">智力</div>
                            <div class="value"><?php echo $recruitedGeneral->getIntelligence(); ?></div>
                        </div>
                        <div class="attribute">
                            <div class="name">元素</div>
                            <div class="value"><?php echo $recruitedGeneral->getElement(); ?></div>
                        </div>
                    </div>

                    <div class="skills">
                        <h5>技能</h5>
                        <?php foreach ($recruitedGeneral->getSkills() as $skill): ?>
                        <div class="skill">
                            <div class="name"><?php echo $skill->getSkillName(); ?> (Lv.<?php echo $skill->getSkillLevel(); ?>)</div>
                            <div class="effect">
                                <?php
                                $effect = $skill->getEffect();
                                $effectText = [];

                                foreach ($effect as $key => $value) {
                                    switch ($key) {
                                        case 'attack':
                                            $effectText[] = "攻击力 +$value";
                                            break;
                                        case 'defense':
                                            $effectText[] = "防御力 +$value";
                                            break;
                                        case 'production':
                                            $effectText[] = "资源产出 +$value%";
                                            break;
                                        case 'build_speed':
                                            $effectText[] = "建造速度 +$value%";
                                            break;
                                        case 'speed':
                                            $effectText[] = "移动速度 +$value%";
                                            break;
                                        case 'morale':
                                            $effectText[] = "士气 +$value";
                                            break;
                                        default:
                                            $effectText[] = "$key +$value";
                                    }
                                }

                                echo implode(', ', $effectText);
                                ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="actions">
                        <button onclick="window.location.href='generals.php'">查看所有武将</button>
                        <button onclick="window.location.href='general_detail.php?id=<?php echo $recruitedGeneral->getGeneralId(); ?>'">查看详情</button>
                    </div>
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
            const recruitOptions = document.querySelectorAll('.recruit-option');
            const recruitTypeInput = document.getElementById('recruit-type');
            const recruitButton = document.getElementById('recruit-button');

            // 招募选项点击事件
            recruitOptions.forEach(option => {
                option.addEventListener('click', function() {
                    // 移除所有选项的选中状态
                    recruitOptions.forEach(opt => {
                        opt.classList.remove('selected');
                    });

                    // 添加当前选项的选中状态
                    this.classList.add('selected');

                    // 设置招募类型
                    const recruitType = this.getAttribute('data-type');
                    recruitTypeInput.value = recruitType;

                    // 启用招募按钮
                    recruitButton.disabled = false;
                });
            });

            // 招募次数变更事件
            document.getElementById('recruit-count').addEventListener('change', function() {
                const count = parseInt(this.value);

                // 更新招募选项中的消耗
                recruitOptions.forEach(option => {
                    const recruitType = option.getAttribute('data-type');
                    const costElements = option.querySelectorAll('.cost p:not(:first-child)');

                    switch (recruitType) {
                        case 'normal':
                            costElements[0].textContent = `亮晶晶: ${100 * count}`;
                            costElements[1].textContent = `暖洋洋: ${100 * count}`;
                            costElements[2].textContent = `冷冰冰: ${100 * count}`;
                            costElements[3].textContent = `郁萌萌: ${100 * count}`;
                            break;

                        case 'advanced':
                            costElements[0].textContent = `亮晶晶: ${500 * count}`;
                            costElements[1].textContent = `暖洋洋: ${500 * count}`;
                            costElements[2].textContent = `冷冰冰: ${500 * count}`;
                            costElements[3].textContent = `郁萌萌: ${500 * count}`;
                            costElements[4].textContent = `昼闪闪: ${100 * count}`;
                            costElements[5].textContent = `夜静静: ${100 * count}`;
                            break;

                        case 'legendary':
                            costElements[0].textContent = `思考回路: ${50 * count}`;
                            break;
                    }
                });
            });
        });
    </script>
</body>
</html>

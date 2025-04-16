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

// 获取城池信息
$city = new City($cityId);
if (!$city->isValid() || $city->getOwnerId() != $user->getUserId()) {
    header('Location: cities.php');
    exit;
}

// 处理防御策略设置
if (isset($_POST['defense_strategy'])) {
    $strategy = $_POST['defense_strategy'];
    $city->setDefenseStrategy($strategy);
    
    // 重定向以避免表单重复提交
    header('Location: defense.php?city_id=' . $cityId);
    exit;
}

// 获取当前防御策略
$currentStrategy = $city->getDefenseStrategy();

// 获取防御策略加成
$defenseBonus = $city->getDefenseStrategyBonus();

// 页面标题
$pageTitle = '城池防御设置';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .defense-container {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .defense-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .defense-title {
            margin: 0;
        }
        
        .city-info {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        
        .city-info h4 {
            margin-top: 0;
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .city-info p {
            margin: 5px 0;
        }
        
        .defense-form {
            margin-bottom: 20px;
        }
        
        .defense-form .form-group {
            margin-bottom: 15px;
        }
        
        .defense-form .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .defense-form .form-group .strategy-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .defense-form .form-group .strategy-option {
            display: flex;
            align-items: center;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .defense-form .form-group .strategy-option:hover {
            background-color: #f9f9f9;
        }
        
        .defense-form .form-group .strategy-option.selected {
            background-color: #e6f7ff;
            border-color: #1890ff;
        }
        
        .defense-form .form-group .strategy-option input {
            margin-right: 10px;
        }
        
        .defense-form .form-group .strategy-option .strategy-info {
            flex: 1;
        }
        
        .defense-form .form-group .strategy-option .strategy-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .defense-form .form-group .strategy-option .strategy-description {
            font-size: 14px;
            color: #666;
        }
        
        .defense-form .form-actions {
            margin-top: 20px;
        }
        
        .defense-form .form-actions button {
            padding: 8px 15px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .defense-form .form-actions button:hover {
            background-color: #555;
        }
        
        .current-strategy {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        
        .current-strategy h4 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .current-strategy p {
            margin: 5px 0;
        }
        
        .current-strategy .strategy-bonus {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #ddd;
        }
        
        .current-strategy .strategy-bonus p {
            margin: 5px 0;
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
            
            <!-- 防御设置容器 -->
            <div class="defense-container">
                <div class="defense-header">
                    <h3 class="defense-title">城池防御设置</h3>
                </div>
                
                <div class="city-info">
                    <h4><?php echo $city->getName(); ?></h4>
                    <p>等级: <?php echo $city->getLevel(); ?></p>
                    <p>耐久度: <?php echo number_format($city->getDurability()); ?> / <?php echo number_format($city->getMaxDurability()); ?></p>
                    <p>防御力: <?php echo number_format($city->getDefensePower()); ?></p>
                    <p>坐标: (<?php echo $city->getCoordinates()[0]; ?>, <?php echo $city->getCoordinates()[1]; ?>)</p>
                    <?php if ($city->isMainCity()): ?>
                    <p><strong>这是您的主城</strong></p>
                    <?php endif; ?>
                </div>
                
                <div class="current-strategy">
                    <h4>当前防御策略</h4>
                    <?php
                    $strategyName = '';
                    $strategyDescription = '';
                    
                    switch ($currentStrategy) {
                        case 'defense':
                            $strategyName = '优先防御';
                            $strategyDescription = '增加城池防御力，但减少资源产出';
                            break;
                        case 'production':
                            $strategyName = '优先产出';
                            $strategyDescription = '增加资源产出，但减少城池防御力';
                            break;
                        case 'balanced':
                        default:
                            $strategyName = '平衡';
                            $strategyDescription = '城池防御力和资源产出保持平衡';
                            break;
                    }
                    ?>
                    <p><strong>策略:</strong> <?php echo $strategyName; ?></p>
                    <p><strong>描述:</strong> <?php echo $strategyDescription; ?></p>
                    
                    <div class="strategy-bonus">
                        <p><strong>防御力加成:</strong> <?php echo ($defenseBonus[0] > 1 ? '+' : '') . (($defenseBonus[0] - 1) * 100) . '%'; ?></p>
                        <p><strong>资源产出加成:</strong> <?php echo ($defenseBonus[1] > 1 ? '+' : '') . (($defenseBonus[1] - 1) * 100) . '%'; ?></p>
                    </div>
                </div>
                
                <div class="defense-form">
                    <form method="post" action="defense.php?city_id=<?php echo $cityId; ?>">
                        <div class="form-group">
                            <label>选择防御策略</label>
                            <div class="strategy-options">
                                <div class="strategy-option <?php echo $currentStrategy == 'defense' ? 'selected' : ''; ?>">
                                    <input type="radio" name="defense_strategy" value="defense" id="defense-strategy" <?php echo $currentStrategy == 'defense' ? 'checked' : ''; ?>>
                                    <div class="strategy-info">
                                        <div class="strategy-name">优先防御</div>
                                        <div class="strategy-description">增加城池防御力50%，但减少资源产出20%</div>
                                    </div>
                                </div>
                                
                                <div class="strategy-option <?php echo $currentStrategy == 'balanced' ? 'selected' : ''; ?>">
                                    <input type="radio" name="defense_strategy" value="balanced" id="balanced-strategy" <?php echo $currentStrategy == 'balanced' ? 'checked' : ''; ?>>
                                    <div class="strategy-info">
                                        <div class="strategy-name">平衡</div>
                                        <div class="strategy-description">城池防御力和资源产出保持平衡</div>
                                    </div>
                                </div>
                                
                                <div class="strategy-option <?php echo $currentStrategy == 'production' ? 'selected' : ''; ?>">
                                    <input type="radio" name="defense_strategy" value="production" id="production-strategy" <?php echo $currentStrategy == 'production' ? 'checked' : ''; ?>>
                                    <div class="strategy-info">
                                        <div class="strategy-name">优先产出</div>
                                        <div class="strategy-description">增加资源产出50%，但减少城池防御力20%</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit">保存设置</button>
                            <button type="button" id="cancel-btn">取消</button>
                        </div>
                    </form>
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
            // 策略选项点击事件
            const strategyOptions = document.querySelectorAll('.strategy-option');
            strategyOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    radio.checked = true;
                    
                    // 更新选中状态
                    strategyOptions.forEach(opt => {
                        opt.classList.remove('selected');
                    });
                    this.classList.add('selected');
                });
            });
            
            // 取消按钮点击事件
            document.getElementById('cancel-btn').addEventListener('click', function() {
                window.location.href = 'cities.php';
            });
        });
    </script>
</body>
</html>

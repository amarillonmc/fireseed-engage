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

// 从GET展示页面、从POST执行移动 / Use GET for display and POST for the movement mutation
$armyId = isset($_POST['army_id'])
    ? (int) $_POST['army_id']
    : (isset($_GET['army_id']) ? (int) $_GET['army_id'] : 0);

// 获取军队信息
$army = new Army($armyId);
if (!$army->isValid() || $army->getOwnerId() != $user->getUserId() || $army->getStatus() != 'idle') {
    header('Location: armies.php');
    exit;
}

$moveError = '';
$prefillTargetX = isset($_GET['target_x']) ? (int) $_GET['target_x'] : '';
$prefillTargetY = isset($_GET['target_y']) ? (int) $_GET['target_y'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $moveError = '安全令牌无效，请刷新页面后重试。';
    } elseif (isSeasonGameplayFrozen()) {
        $moveError = getSeasonGameplayFreezeMessage();
    } else {
        $targetX = isset($_POST['target_x']) ? (int) $_POST['target_x'] : -1;
        $targetY = isset($_POST['target_y']) ? (int) $_POST['target_y'] : -1;
        if ($targetX < 0 || $targetX >= MAP_WIDTH || $targetY < 0 || $targetY >= MAP_HEIGHT) {
            $moveError = '目标坐标超出地图范围。';
        } elseif ($army->moveArmy($targetX, $targetY)) {
            header('Location: armies.php');
            exit;
        } else {
            $moveError = '移动失败，军队状态可能已经变化。';
        }
    }
}

// 获取军队当前位置
$currentPosition = $army->getCurrentPosition();

// 页面标题
$pageTitle = '移动军队';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .move-army-container {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .move-army-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .move-army-title {
            margin: 0;
        }
        
        .move-army-info {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        
        .move-army-info h4 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .move-army-info p {
            margin: 5px 0;
        }
        
        .move-army-form {
            margin-bottom: 20px;
        }
        
        .move-army-form .form-group {
            margin-bottom: 15px;
        }
        
        .move-army-form .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .move-army-form .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }
        
        .move-army-form .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        
        .move-army-form .form-actions button {
            padding: 8px 15px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .move-army-form .form-actions button:hover {
            background-color: #555;
        }
        
        .move-army-map {
            margin-top: 20px;
        }
        
        .move-army-map h4 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .move-army-map .map-container {
            height: 400px;
            border: 1px solid #ccc;
            border-radius: 5px;
            overflow: hidden;
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
        
        <!-- 主内容 -->
        <main>
            <!-- 资源栏 / Resource bar -->
            <?php renderGameplayResourceBar($resource); ?>
            
            <!-- 移动军队容器 -->
            <div class="move-army-container">
                <div class="move-army-header">
                    <h3 class="move-army-title">移动军队</h3>
                </div>
                
                <div class="move-army-info">
                    <h4><?php echo escapeHtml($army->getName()); ?></h4>
                    <p>当前位置: (<?php echo $currentPosition[0]; ?>, <?php echo $currentPosition[1]; ?>)</p>
                    <p>移动速度: <?php echo number_format($army->getMovementSpeed(), 2); ?> 格/小时</p>
                    <p>战斗力: <?php echo number_format($army->getCombatPower()); ?></p>
                </div>
                
                <div class="move-army-form">
                    <?php if ($moveError !== ''): ?>
                    <div class="message error"><?php echo escapeHtml($moveError); ?></div>
                    <?php endif; ?>
                    <form method="post" action="move_army.php">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="army_id" value="<?php echo $armyId; ?>">
                        
                        <div class="form-group">
                            <label for="target-x">目标X坐标</label>
                            <input type="number" id="target-x" name="target_x" min="0" max="<?php echo MAP_WIDTH - 1; ?>" value="<?php echo escapeHtml($prefillTargetX); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="target-y">目标Y坐标</label>
                            <input type="number" id="target-y" name="target_y" min="0" max="<?php echo MAP_HEIGHT - 1; ?>" value="<?php echo escapeHtml($prefillTargetY); ?>" required>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit">移动</button>
                            <button type="button" id="cancel-btn">取消</button>
                        </div>
                    </form>
                </div>
                
                <div class="move-army-map">
                    <h4>地图</h4>
                    <p>点击下方按钮在地图上选择目标位置</p>
                    <div class="form-actions">
                        <button type="button" id="select-on-map-btn">在地图上选择</button>
                    </div>
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
            // 取消按钮点击事件
            document.getElementById('cancel-btn').addEventListener('click', function() {
                window.location.href = 'armies.php';
            });
            
            // 在地图上选择按钮点击事件
            document.getElementById('select-on-map-btn').addEventListener('click', function() {
                const currentX = <?php echo $currentPosition[0]; ?>;
                const currentY = <?php echo $currentPosition[1]; ?>;
                
                // 打开地图页面，并传递当前军队ID
                window.location.href = `map.php?x=${currentX}&y=${currentY}&select_target=1&army_id=<?php echo $armyId; ?>`;
            });
            
            // 如果URL中有target_x和target_y参数，自动填充表单
            const urlParams = new URLSearchParams(window.location.search);
            const targetX = urlParams.get('target_x');
            const targetY = urlParams.get('target_y');
            
            if (targetX && targetY) {
                document.getElementById('target-x').value = targetX;
                document.getElementById('target-y').value = targetY;
            }
        });
    </script>
</body>
</html>

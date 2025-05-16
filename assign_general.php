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

// 处理分配请求
$assignResult = null;
if (isset($_POST['action']) && $_POST['action'] == 'assign') {
    $assignmentType = isset($_POST['assignment_type']) ? $_POST['assignment_type'] : '';
    $targetId = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;
    
    if (!empty($assignmentType) && $targetId > 0) {
        if ($general->assignGeneral($assignmentType, $targetId)) {
            $assignResult = [
                'success' => true,
                'message' => '武将分配成功！'
            ];
        } else {
            $assignResult = [
                'success' => false,
                'message' => '武将分配失败，请稍后再试。'
            ];
        }
    } else {
        $assignResult = [
            'success' => false,
            'message' => '参数错误，无法分配武将。'
        ];
    }
}

// 处理取消分配请求
if (isset($_POST['action']) && $_POST['action'] == 'unassign') {
    if ($general->unassignGeneral()) {
        $assignResult = [
            'success' => true,
            'message' => '取消分配成功！'
        ];
    } else {
        $assignResult = [
            'success' => false,
            'message' => '取消分配失败，请稍后再试。'
        ];
    }
}

// 获取用户的城池
$cities = City::getUserCities($user->getUserId());

// 获取用户的军队
$armies = Army::getUserArmies($user->getUserId());

// 页面标题
$pageTitle = '分配武将 - ' . $general->getName();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .assign-general {
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
        
        .current-assignment {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .current-assignment h4 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .assignment-options {
            margin-bottom: 20px;
        }
        
        .assignment-options h4 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .assignment-form {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .target-list {
            display: none;
        }
        
        .assign-button {
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .assign-button:hover {
            background-color: #45a049;
        }
        
        .unassign-button {
            padding: 10px 20px;
            background-color: #f44336;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .unassign-button:hover {
            background-color: #d32f2f;
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
            <!-- 武将分配 -->
            <div class="assign-general">
                <div class="general-header">
                    <h3 class="general-title">
                        <?php echo $general->getName(); ?>
                        <span class="rarity <?php echo $general->getRarity(); ?>"><?php echo $general->getRarity(); ?></span>
                    </h3>
                    <div class="general-controls">
                        <button onclick="window.location.href='general_detail.php?id=<?php echo $general->getGeneralId(); ?>'">返回武将详情</button>
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
                </div>
                
                <?php
                $assignment = $general->getAssignment();
                if ($assignment) {
                    $assignmentType = $assignment->getAssignmentType();
                    $targetId = $assignment->getTargetId();
                    $targetName = '';
                    
                    if ($assignmentType == 'city') {
                        $city = new City($targetId);
                        if ($city->isValid()) {
                            $targetName = $city->getName();
                        }
                    } else if ($assignmentType == 'army') {
                        $army = new Army($targetId);
                        if ($army->isValid()) {
                            $targetName = $army->getName();
                        }
                    }
                ?>
                <div class="current-assignment">
                    <h4>当前分配</h4>
                    <p>该武将当前已分配到 <?php echo $assignmentType == 'city' ? '城池' : '军队'; ?>: <strong><?php echo $targetName; ?></strong></p>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="unassign">
                        <button type="submit" class="unassign-button">取消分配</button>
                    </form>
                </div>
                <?php } else { ?>
                <div class="assignment-options">
                    <h4>分配武将</h4>
                    
                    <div class="assignment-form">
                        <form method="post" id="assign-form">
                            <input type="hidden" name="action" value="assign">
                            
                            <div class="form-group">
                                <label for="assignment-type">分配类型:</label>
                                <select id="assignment-type" name="assignment_type" onchange="showTargetList()">
                                    <option value="">-- 选择分配类型 --</option>
                                    <option value="city">城池</option>
                                    <option value="army">军队</option>
                                </select>
                            </div>
                            
                            <div class="form-group target-list" id="city-list">
                                <label for="city-target">选择城池:</label>
                                <select id="city-target" name="target_id">
                                    <option value="">-- 选择城池 --</option>
                                    <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo $city->getCityId(); ?>"><?php echo $city->getName(); ?> (等级: <?php echo $city->getLevel(); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group target-list" id="army-list">
                                <label for="army-target">选择军队:</label>
                                <select id="army-target" name="target_id">
                                    <option value="">-- 选择军队 --</option>
                                    <?php foreach ($armies as $army): ?>
                                    <option value="<?php echo $army->getArmyId(); ?>"><?php echo $army->getName(); ?> (等级: <?php echo $army->getLevel(); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <button type="submit" class="assign-button">分配武将</button>
                        </form>
                    </div>
                </div>
                <?php } ?>
                
                <?php if ($assignResult): ?>
                <div class="result-message <?php echo $assignResult['success'] ? 'success' : 'error'; ?>">
                    <?php echo $assignResult['message']; ?>
                </div>
                <?php endif; ?>
                
                <div class="actions">
                    <button onclick="window.location.href='general_detail.php?id=<?php echo $general->getGeneralId(); ?>'">返回武将详情</button>
                    <button onclick="window.location.href='generals.php'">返回武将列表</button>
                </div>
            </div>
        </main>
        
        <!-- 页脚 -->
        <footer>
            <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?> - 版本 <?php echo GAME_VERSION; ?></p>
        </footer>
    </div>
    
    <script>
        function showTargetList() {
            var assignmentType = document.getElementById('assignment-type').value;
            var cityList = document.getElementById('city-list');
            var armyList = document.getElementById('army-list');
            
            if (assignmentType === 'city') {
                cityList.style.display = 'block';
                armyList.style.display = 'none';
                document.getElementById('army-target').value = '';
            } else if (assignmentType === 'army') {
                cityList.style.display = 'none';
                armyList.style.display = 'block';
                document.getElementById('city-target').value = '';
            } else {
                cityList.style.display = 'none';
                armyList.style.display = 'none';
            }
        }
    </script>
</body>
</html>

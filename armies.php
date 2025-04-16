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

// 获取用户的所有军队
$armies = Army::getUserArmies($user->getUserId());

// 获取用户的所有城池
$cities = City::getUserCities($user->getUserId());

// 页面标题
$pageTitle = '军队管理';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .armies-container {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .armies-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .armies-title {
            margin: 0;
        }
        
        .armies-controls {
            display: flex;
            gap: 10px;
        }
        
        .armies-controls button {
            padding: 5px 10px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .armies-controls button:hover {
            background-color: #555;
        }
        
        .armies-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .army-card {
            border: 1px solid #eee;
            border-radius: 5px;
            padding: 15px;
            background-color: #f9f9f9;
        }
        
        .army-card h4 {
            margin-top: 0;
            margin-bottom: 10px;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        
        .army-card p {
            margin: 5px 0;
            font-size: 14px;
        }
        
        .army-card .army-status {
            font-weight: bold;
        }
        
        .army-card .army-status.idle {
            color: #009900;
        }
        
        .army-card .army-status.marching {
            color: #cc9900;
        }
        
        .army-card .army-status.fighting {
            color: #cc0000;
        }
        
        .army-card .army-status.returning {
            color: #0099cc;
        }
        
        .army-card .army-units {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #eee;
        }
        
        .army-card .army-unit {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .army-card .army-actions {
            margin-top: 15px;
            display: flex;
            gap: 5px;
        }
        
        .army-card .army-actions button {
            padding: 3px 8px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .army-card .army-actions button:hover {
            background-color: #555;
        }
        
        .army-card .army-actions button.disband {
            background-color: #cc0000;
        }
        
        .army-card .army-actions button.disband:hover {
            background-color: #ff0000;
        }
        
        .create-army-form {
            margin-top: 20px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        
        .create-army-form h3 {
            margin-top: 0;
            margin-bottom: 15px;
        }
        
        .create-army-form .form-group {
            margin-bottom: 15px;
        }
        
        .create-army-form .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .create-army-form .form-group input,
        .create-army-form .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }
        
        .create-army-form .form-group .unit-selection {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .create-army-form .form-group .unit-selection .unit-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .create-army-form .form-group .unit-selection .unit-item input {
            width: 60px;
        }
        
        .create-army-form .form-actions {
            margin-top: 20px;
        }
        
        .create-army-form .form-actions button {
            padding: 8px 15px;
            background-color: #333;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .create-army-form .form-actions button:hover {
            background-color: #555;
        }
        
        .no-armies {
            padding: 20px;
            text-align: center;
            background-color: #f5f5f5;
            border-radius: 5px;
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
            
            <!-- 军队容器 -->
            <div class="armies-container">
                <div class="armies-header">
                    <h3 class="armies-title">我的军队</h3>
                    <div class="armies-controls">
                        <button id="create-army-btn">创建新军队</button>
                        <button id="refresh-btn">刷新</button>
                    </div>
                </div>
                
                <?php if (empty($armies)): ?>
                <div class="no-armies">
                    <p>您还没有任何军队。请创建新军队。</p>
                </div>
                <?php else: ?>
                <div class="armies-list">
                    <?php foreach ($armies as $army): ?>
                    <div class="army-card" data-army-id="<?php echo $army->getArmyId(); ?>">
                        <h4><?php echo $army->getName(); ?></h4>
                        
                        <?php
                        $statusText = '';
                        $statusClass = '';
                        
                        switch ($army->getStatus()) {
                            case 'idle':
                                $statusText = '待命中';
                                $statusClass = 'idle';
                                break;
                            case 'marching':
                                $statusText = '行军中';
                                $statusClass = 'marching';
                                break;
                            case 'fighting':
                                $statusText = '战斗中';
                                $statusClass = 'fighting';
                                break;
                            case 'returning':
                                $statusText = '返回中';
                                $statusClass = 'returning';
                                break;
                        }
                        ?>
                        
                        <p class="army-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></p>
                        
                        <?php if ($army->getStatus() == 'marching'): ?>
                        <p>目标位置: (<?php echo $army->getTargetPosition()[0]; ?>, <?php echo $army->getTargetPosition()[1]; ?>)</p>
                        <p>预计到达时间: <?php echo date('Y-m-d H:i:s', strtotime($army->getArrivalTime())); ?></p>
                        <?php elseif ($army->getStatus() == 'returning'): ?>
                        <p>返回城池: <?php 
                            $city = new City($army->getCityId());
                            echo $city->isValid() ? $city->getName() : '未知城池';
                        ?></p>
                        <p>预计返回时间: <?php echo date('Y-m-d H:i:s', strtotime($army->getReturnTime())); ?></p>
                        <?php else: ?>
                        <p>当前位置: (<?php echo $army->getCurrentPosition()[0]; ?>, <?php echo $army->getCurrentPosition()[1]; ?>)</p>
                        <p>所属城池: <?php 
                            $city = new City($army->getCityId());
                            echo $city->isValid() ? $city->getName() : '未知城池';
                        ?></p>
                        <?php endif; ?>
                        
                        <p>战斗力: <?php echo number_format($army->getCombatPower()); ?></p>
                        
                        <div class="army-units">
                            <p><strong>军队组成:</strong></p>
                            <?php foreach ($army->getUnits() as $unit): ?>
                            <div class="army-unit">
                                <span><?php echo getSoldierName($unit['soldier_type']); ?> (Lv.<?php echo $unit['level']; ?>)</span>
                                <span><?php echo number_format($unit['quantity']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="army-actions">
                            <?php if ($army->getStatus() == 'idle'): ?>
                            <button class="view-on-map" data-x="<?php echo $army->getCurrentPosition()[0]; ?>" data-y="<?php echo $army->getCurrentPosition()[1]; ?>">查看位置</button>
                            <button class="move" data-army-id="<?php echo $army->getArmyId(); ?>">移动</button>
                            <button class="disband" data-army-id="<?php echo $army->getArmyId(); ?>">解散</button>
                            <?php elseif ($army->getStatus() == 'marching'): ?>
                            <button class="view-on-map" data-x="<?php echo $army->getTargetPosition()[0]; ?>" data-y="<?php echo $army->getTargetPosition()[1]; ?>">查看目标</button>
                            <button class="return" data-army-id="<?php echo $army->getArmyId(); ?>">返回</button>
                            <?php elseif ($army->getStatus() == 'returning'): ?>
                            <button class="view-on-map" data-x="<?php echo $army->getTargetPosition()[0]; ?>" data-y="<?php echo $army->getTargetPosition()[1]; ?>">查看目标</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- 创建军队表单 -->
                <div class="create-army-form" id="create-army-form" style="display: none;">
                    <h3>创建新军队</h3>
                    
                    <div class="form-group">
                        <label for="army-name">军队名称</label>
                        <input type="text" id="army-name" name="army-name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="city-id">所属城池</label>
                        <select id="city-id" name="city-id" required>
                            <option value="">-- 选择城池 --</option>
                            <?php foreach ($cities as $city): ?>
                            <option value="<?php echo $city->getCityId(); ?>"><?php echo $city->getName(); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>选择士兵</label>
                        <div class="unit-selection" id="unit-selection">
                            <!-- 士兵选择将通过JavaScript动态加载 -->
                            <p>请先选择城池</p>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" id="create-army-submit">创建军队</button>
                        <button type="button" id="create-army-cancel">取消</button>
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
            // 创建新军队按钮点击事件
            document.getElementById('create-army-btn').addEventListener('click', function() {
                document.getElementById('create-army-form').style.display = 'block';
            });
            
            // 取消创建军队按钮点击事件
            document.getElementById('create-army-cancel').addEventListener('click', function() {
                document.getElementById('create-army-form').style.display = 'none';
            });
            
            // 刷新按钮点击事件
            document.getElementById('refresh-btn').addEventListener('click', function() {
                window.location.reload();
            });
            
            // 城池选择变更事件
            document.getElementById('city-id').addEventListener('change', function() {
                const cityId = this.value;
                
                if (cityId) {
                    // 获取城池中的士兵
                    fetch(`api/get_city_soldiers.php?city_id=${cityId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                renderUnitSelection(data.soldiers);
                            } else {
                                showNotification(data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error getting city soldiers:', error);
                            showNotification('获取城池士兵时发生错误');
                        });
                } else {
                    document.getElementById('unit-selection').innerHTML = '<p>请先选择城池</p>';
                }
            });
            
            // 创建军队提交按钮点击事件
            document.getElementById('create-army-submit').addEventListener('click', function() {
                const armyName = document.getElementById('army-name').value;
                const cityId = document.getElementById('city-id').value;
                
                if (!armyName || !cityId) {
                    showNotification('请填写军队名称并选择城池');
                    return;
                }
                
                // 获取选择的士兵
                const units = [];
                const unitInputs = document.querySelectorAll('.unit-item input[type="number"]');
                
                unitInputs.forEach(input => {
                    const quantity = parseInt(input.value);
                    
                    if (quantity > 0) {
                        const soldierType = input.getAttribute('data-soldier-type');
                        const level = parseInt(input.getAttribute('data-level'));
                        
                        units.push({
                            soldier_type: soldierType,
                            level: level,
                            quantity: quantity
                        });
                    }
                });
                
                if (units.length === 0) {
                    showNotification('请至少选择一种士兵');
                    return;
                }
                
                // 创建军队
                fetch('api/create_army.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        name: armyName,
                        city_id: cityId,
                        units: units
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('创建军队成功');
                            
                            // 刷新页面
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        } else {
                            showNotification(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error creating army:', error);
                        showNotification('创建军队时发生错误');
                    });
            });
            
            // 查看位置按钮点击事件
            const viewButtons = document.querySelectorAll('.view-on-map');
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const x = this.getAttribute('data-x');
                    const y = this.getAttribute('data-y');
                    window.location.href = `map.php?x=${x}&y=${y}`;
                });
            });
            
            // 移动按钮点击事件
            const moveButtons = document.querySelectorAll('.move');
            moveButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const armyId = this.getAttribute('data-army-id');
                    window.location.href = `move_army.php?army_id=${armyId}`;
                });
            });
            
            // 返回按钮点击事件
            const returnButtons = document.querySelectorAll('.return');
            returnButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const armyId = this.getAttribute('data-army-id');
                    
                    if (confirm('确定要让军队返回城池吗？')) {
                        fetch(`api/return_army.php?army_id=${armyId}`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    showNotification('军队开始返回城池');
                                    
                                    // 刷新页面
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 2000);
                                } else {
                                    showNotification(data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error returning army:', error);
                                showNotification('返回城池时发生错误');
                            });
                    }
                });
            });
            
            // 解散按钮点击事件
            const disbandButtons = document.querySelectorAll('.disband');
            disbandButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const armyId = this.getAttribute('data-army-id');
                    
                    if (confirm('确定要解散这支军队吗？士兵将返回城池。')) {
                        fetch(`api/disband_army.php?army_id=${armyId}`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    showNotification('解散军队成功');
                                    
                                    // 刷新页面
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 2000);
                                } else {
                                    showNotification(data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error disbanding army:', error);
                                showNotification('解散军队时发生错误');
                            });
                    }
                });
            });
            
            // 渲染士兵选择
            function renderUnitSelection(soldiers) {
                const unitSelection = document.getElementById('unit-selection');
                
                if (soldiers.length === 0) {
                    unitSelection.innerHTML = '<p>该城池中没有可用的士兵</p>';
                    return;
                }
                
                let html = '';
                
                soldiers.forEach(soldier => {
                    html += `
                        <div class="unit-item">
                            <span>${getSoldierName(soldier.type)} (Lv.${soldier.level})</span>
                            <span>可用: ${soldier.quantity}</span>
                            <input type="number" min="0" max="${soldier.quantity}" value="0" data-soldier-type="${soldier.type}" data-level="${soldier.level}">
                        </div>
                    `;
                });
                
                unitSelection.innerHTML = html;
            }
            
            // 获取士兵名称
            function getSoldierName(type) {
                switch (type) {
                    case 'pawn':
                        return '兵卒';
                    case 'knight':
                        return '骑士';
                    case 'rook':
                        return '城壁';
                    case 'bishop':
                        return '主教';
                    case 'golem':
                        return '锤子兵';
                    case 'scout':
                        return '侦察兵';
                    default:
                        return '未知士兵';
                }
            }
        });
        
        // 获取士兵名称函数（PHP版本）
        <?php
        function getSoldierName($type) {
            switch ($type) {
                case 'pawn':
                    return '兵卒';
                case 'knight':
                    return '骑士';
                case 'rook':
                    return '城壁';
                case 'bishop':
                    return '主教';
                case 'golem':
                    return '锤子兵';
                case 'scout':
                    return '侦察兵';
                default:
                    return '未知士兵';
            }
        }
        ?>
    </script>
</body>
</html>

<?php
// 种火集结号 - 武将管理页面
// 包含初始化文件
require_once '../includes/init.php';

// 检查用户是否已登录且为管理员
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid() || !$user->isAdmin()) {
    header('Location: ../index.php');
    exit;
}

// 创建管理员管理器
$adminManager = new AdminManager($user);

// 检查权限
if (!$adminManager->hasPermission('manage_generals')) {
    die('您没有权限访问此页面');
}

$error = '';
$success = '';

// 处理武将操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_general':
            if ($adminManager->hasPermission('create_generals')) {
                $general = new General();
                $attributes = [
                    'attack' => intval($_POST['attack'] ?? 0),
                    'defense' => intval($_POST['defense'] ?? 0),
                    'speed' => intval($_POST['speed'] ?? 0),
                    'intelligence' => intval($_POST['intelligence'] ?? 0)
                ];
                
                $generalId = $general->createGeneral(
                    0, // owner_id = 0 表示通用武将池
                    $_POST['name'] ?? '',
                    $_POST['source'] ?? '',
                    $_POST['rarity'] ?? 'B',
                    floatval($_POST['cost'] ?? 1.0),
                    $_POST['element'] ?? '亮晶晶',
                    $attributes
                );
                
                if ($generalId) {
                    $success = '武将创建成功';
                    $user->logAdminAction('create_general', 'general', $generalId, "Created: {$_POST['name']}");
                } else {
                    $error = '武将创建失败';
                }
            } else {
                $error = '您没有权限创建武将';
            }
            break;
            
        case 'update_general':
            if ($adminManager->hasPermission('edit_generals')) {
                $generalId = intval($_POST['general_id'] ?? 0);
                $general = new General($generalId);
                
                if ($general->isValid()) {
                    $general->setName($_POST['name'] ?? '');
                    $general->setSource($_POST['source'] ?? '');
                    $general->setRarity($_POST['rarity'] ?? 'B');
                    $general->setCost(floatval($_POST['cost'] ?? 1.0));
                    $general->setElement($_POST['element'] ?? '亮晶晶');
                    $general->setAttack(intval($_POST['attack'] ?? 0));
                    $general->setDefense(intval($_POST['defense'] ?? 0));
                    $general->setSpeed(intval($_POST['speed'] ?? 0));
                    $general->setIntelligence(intval($_POST['intelligence'] ?? 0));
                    
                    if ($general->save()) {
                        $success = '武将更新成功';
                        $user->logAdminAction('update_general', 'general', $generalId, "Updated: {$_POST['name']}");
                    } else {
                        $error = '武将更新失败';
                    }
                } else {
                    $error = '武将不存在';
                }
            } else {
                $error = '您没有权限编辑武将';
            }
            break;
            
        case 'delete_general':
            if ($adminManager->hasPermission('delete_generals')) {
                $generalId = intval($_POST['general_id'] ?? 0);
                $general = new General($generalId);
                
                if ($general->isValid()) {
                    if ($general->delete()) {
                        $success = '武将删除成功';
                        $user->logAdminAction('delete_general', 'general', $generalId, "Deleted general");
                    } else {
                        $error = '武将删除失败';
                    }
                } else {
                    $error = '武将不存在';
                }
            } else {
                $error = '您没有权限删除武将';
            }
            break;
    }
}

// 获取搜索参数
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// 获取武将列表
$db = Database::getInstance()->getConnection();
$whereClause = '';
$params = [];

if ($search) {
    $whereClause = "WHERE name LIKE ? OR source LIKE ?";
    $searchParam = "%$search%";
    $params = [$searchParam, $searchParam];
}

$query = "SELECT * FROM generals $whereClause ORDER BY general_id DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($query);
if ($search) {
    $stmt->bind_param('ssii', ...$params, $limit, $offset);
} else {
    $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

$generals = [];
while ($row = $result->fetch_assoc()) {
    $generals[] = $row;
}
$stmt->close();

// 获取总数
$countQuery = "SELECT COUNT(*) as total FROM generals $whereClause";
$countStmt = $db->prepare($countQuery);
if ($search) {
    $countStmt->bind_param('ss', ...$params);
}
$countStmt->execute();
$totalCount = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = ceil($totalCount / $limit);

$pageTitle = '武将管理';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .admin-header {
            background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title {
            font-size: 24px;
            font-weight: bold;
        }
        
        .back-link {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .search-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        
        .search-button {
            padding: 10px 20px;
            background: #9b59b6;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .search-button:hover {
            background: #8e44ad;
        }
        
        .generals-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .generals-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .generals-table th,
        .generals-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .generals-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .generals-table tr:hover {
            background: #f8f9fa;
        }
        
        .rarity-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }
        
        .rarity-B { background: #95a5a6; color: white; }
        .rarity-A { background: #3498db; color: white; }
        .rarity-S { background: #9b59b6; color: white; }
        .rarity-SS { background: #f39c12; color: white; }
        .rarity-P { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; }
        
        .element-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            display: inline-block;
        }
        
        .element-亮晶晶 { background: #ecf0f1; color: #2c3e50; }
        .element-暖洋洋 { background: #ffebee; color: #c62828; }
        .element-冷冰冰 { background: #e3f2fd; color: #1565c0; }
        .element-郁萌萌 { background: #e8f5e8; color: #2e7d32; }
        .element-昼闪闪 { background: #fffde7; color: #f57f17; }
        .element-夜静静 { background: #4a148c; color: white; }
        
        .action-button {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            margin: 2px;
            transition: background-color 0.3s;
        }
        
        .btn-primary {
            background: #9b59b6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #8e44ad;
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #2c3e50;
        }
        
        .pagination a:hover {
            background: #f8f9fa;
        }
        
        .pagination .current {
            background: #9b59b6;
            color: white;
            border-color: #9b59b6;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: #000;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .form-input,
        .form-select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #c62828;
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #2e7d32;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .generals-table {
                font-size: 14px;
            }
            
            .generals-table th,
            .generals-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="admin-container">
            <!-- 页面头部 -->
            <div class="admin-header">
                <div class="header-title">⚔️ 武将管理</div>
                <a href="index.php" class="back-link">← 返回管理后台</a>
            </div>
            
            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <!-- 搜索区域 -->
            <div class="search-section">
                <form class="search-form" method="get">
                    <input type="text" name="search" class="search-input" 
                           placeholder="搜索武将名称或来源..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-button">搜索</button>
                    <?php if ($search): ?>
                    <a href="generals.php" class="search-button" style="background: #95a5a6;">清除</a>
                    <?php endif; ?>
                    <?php if ($adminManager->hasPermission('create_generals')): ?>
                    <button type="button" class="search-button" style="background: #27ae60;" onclick="showCreateModal()">
                        + 创建武将
                    </button>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- 武将列表 -->
            <div class="generals-section">
                <h3>武将列表 (共 <?php echo number_format($totalCount); ?> 个武将)</h3>
                
                <?php if (!empty($generals)): ?>
                <table class="generals-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>武将名称</th>
                            <th>来源</th>
                            <th>稀有度</th>
                            <th>元素</th>
                            <th>COST</th>
                            <th>攻击</th>
                            <th>防御</th>
                            <th>速度</th>
                            <th>智力</th>
                            <th>等级</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($generals as $general): ?>
                        <tr>
                            <td><?php echo $general['general_id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($general['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($general['source']); ?></td>
                            <td>
                                <span class="rarity-badge rarity-<?php echo $general['rarity']; ?>">
                                    <?php echo $general['rarity']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="element-badge element-<?php echo $general['element']; ?>">
                                    <?php echo $general['element']; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($general['cost'], 1); ?></td>
                            <td><?php echo $general['attack']; ?></td>
                            <td><?php echo $general['defense']; ?></td>
                            <td><?php echo $general['speed']; ?></td>
                            <td><?php echo $general['intelligence']; ?></td>
                            <td>Lv.<?php echo $general['level']; ?></td>
                            <td>
                                <?php if ($adminManager->hasPermission('edit_generals')): ?>
                                <button class="action-button btn-primary" 
                                        onclick="editGeneral(<?php echo $general['general_id']; ?>)">
                                    编辑
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($adminManager->hasPermission('delete_generals')): ?>
                                <button class="action-button btn-danger" 
                                        onclick="deleteGeneral(<?php echo $general['general_id']; ?>, '<?php echo htmlspecialchars($general['name']); ?>')">
                                    删除
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- 分页 -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="generals.php?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">上一页</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                    <?php if ($i == $page): ?>
                    <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="generals.php?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="generals.php?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div style="text-align: center; color: #7f8c8d; padding: 40px;">
                    <?php echo $search ? '未找到匹配的武将' : '暂无武将'; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 创建/编辑武将模态框 -->
    <div id="generalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title" id="modalTitle">创建武将</div>
                <span class="close" onclick="closeModal('generalModal')">&times;</span>
            </div>
            <form id="generalForm" method="post">
                <input type="hidden" name="action" id="formAction" value="create_general">
                <input type="hidden" name="general_id" id="edit_general_id">
                
                <div class="form-group">
                    <label class="form-label">武将名称</label>
                    <input type="text" name="name" id="general_name" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">来源</label>
                    <input type="text" name="source" id="general_source" class="form-input" placeholder="例如：来自某游戏">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">稀有度</label>
                        <select name="rarity" id="general_rarity" class="form-select" required>
                            <option value="B">B - 普通</option>
                            <option value="A">A - 稀有</option>
                            <option value="S">S - 史诗</option>
                            <option value="SS">SS - 传说</option>
                            <option value="P">P - 白金</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">COST</label>
                        <input type="number" name="cost" id="general_cost" class="form-input" 
                               step="0.5" min="1.0" max="4.0" value="1.0" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">元素</label>
                    <select name="element" id="general_element" class="form-select" required>
                        <option value="亮晶晶">亮晶晶 - 内政型</option>
                        <option value="暖洋洋">暖洋洋 - 速攻型</option>
                        <option value="冷冰冰">冷冰冰 - 防御型</option>
                        <option value="郁萌萌">郁萌萌 - 强攻型</option>
                        <option value="昼闪闪">昼闪闪 - 辅助型</option>
                        <option value="夜静静">夜静静 - 特殊型</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">攻击力</label>
                        <input type="number" name="attack" id="general_attack" class="form-input" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">防御力</label>
                        <input type="number" name="defense" id="general_defense" class="form-input" min="0" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">速度</label>
                        <input type="number" name="speed" id="general_speed" class="form-input" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">智力</label>
                        <input type="number" name="intelligence" id="general_intelligence" class="form-input" min="0" required>
                    </div>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeModal('generalModal')" 
                            style="margin-right: 10px; padding: 8px 16px; background: #95a5a6; color: white; border: none; border-radius: 4px;">
                        取消
                    </button>
                    <button type="submit" class="action-button btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function showCreateModal() {
            document.getElementById('modalTitle').textContent = '创建武将';
            document.getElementById('formAction').value = 'create_general';
            document.getElementById('edit_general_id').value = '';
            
            // 清空表单
            document.getElementById('general_name').value = '';
            document.getElementById('general_source').value = '';
            document.getElementById('general_rarity').value = 'B';
            document.getElementById('general_cost').value = '1.0';
            document.getElementById('general_element').value = '亮晶晶';
            document.getElementById('general_attack').value = '';
            document.getElementById('general_defense').value = '';
            document.getElementById('general_speed').value = '';
            document.getElementById('general_intelligence').value = '';
            
            document.getElementById('generalModal').style.display = 'block';
        }
        
        function editGeneral(generalId) {
            fetch('../api/get_general.php?general_id=' + generalId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').textContent = '编辑武将';
                        document.getElementById('formAction').value = 'update_general';
                        document.getElementById('edit_general_id').value = generalId;
                        
                        const general = data.general;
                        document.getElementById('general_name').value = general.name;
                        document.getElementById('general_source').value = general.source || '';
                        document.getElementById('general_rarity').value = general.rarity;
                        document.getElementById('general_cost').value = general.cost;
                        document.getElementById('general_element').value = general.element;
                        document.getElementById('general_attack').value = general.attack;
                        document.getElementById('general_defense').value = general.defense;
                        document.getElementById('general_speed').value = general.speed;
                        document.getElementById('general_intelligence').value = general.intelligence;
                        
                        document.getElementById('generalModal').style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('获取武将信息失败');
                });
        }
        
        function deleteGeneral(generalId, generalName) {
            if (confirm('确定要删除武将「' + generalName + '」吗？此操作不可撤销！')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_general">
                    <input type="hidden" name="general_id" value="${generalId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // 点击模态框外部关闭
        window.onclick = function(event) {
            const modal = document.getElementById('generalModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>

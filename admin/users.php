<?php
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
if (!$adminManager->hasPermission('view_users')) {
    die('您没有权限访问此页面');
}

$error = '';
$success = '';

// 处理用户操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetUserId = intval($_POST['user_id'] ?? 0);
    
    switch ($action) {
        case 'update_resources':
            if ($adminManager->hasPermission('edit_user_resources')) {
                $resources = [
                    'bright_crystal' => intval($_POST['bright_crystal'] ?? 0),
                    'warm_crystal' => intval($_POST['warm_crystal'] ?? 0),
                    'cold_crystal' => intval($_POST['cold_crystal'] ?? 0),
                    'green_crystal' => intval($_POST['green_crystal'] ?? 0),
                    'day_crystal' => intval($_POST['day_crystal'] ?? 0),
                    'night_crystal' => intval($_POST['night_crystal'] ?? 0)
                ];
                
                if ($adminManager->updateUserResources($targetUserId, $resources)) {
                    $success = '用户资源更新成功';
                } else {
                    $error = '用户资源更新失败';
                }
            } else {
                $error = '您没有权限修改用户资源';
            }
            break;
            
        case 'update_level':
            if ($adminManager->hasPermission('edit_user_basic')) {
                $level = intval($_POST['level'] ?? 1);
                if ($adminManager->updateUserLevel($targetUserId, $level)) {
                    $success = '用户等级更新成功';
                } else {
                    $error = '用户等级更新失败';
                }
            } else {
                $error = '您没有权限修改用户等级';
            }
            break;
            
        case 'update_circuit_points':
            if ($adminManager->hasPermission('edit_user_basic')) {
                $circuitPoints = intval($_POST['circuit_points'] ?? 0);
                $maxCircuitPoints = intval($_POST['max_circuit_points'] ?? 1);
                if ($adminManager->updateUserCircuitPoints($targetUserId, $circuitPoints, $maxCircuitPoints)) {
                    $success = '用户思考回路更新成功';
                } else {
                    $error = '用户思考回路更新失败';
                }
            } else {
                $error = '您没有权限修改用户思考回路';
            }
            break;
            
        case 'set_admin_level':
            if ($adminManager->hasPermission('manage_admins')) {
                $adminLevel = intval($_POST['admin_level'] ?? 0);
                if ($adminManager->setUserAdminLevel($targetUserId, $adminLevel)) {
                    $success = '用户管理员等级设置成功';
                } else {
                    $error = '用户管理员等级设置失败';
                }
            } else {
                $error = '您没有权限设置管理员等级';
            }
            break;
    }
}

// 获取搜索参数
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// 获取用户列表
if ($search) {
    $users = User::searchUsers($search, $limit);
    $totalUsers = count($users);
} else {
    $users = User::getAllUsers($limit, $offset);
    $totalUsers = User::getTotalUserCount();
}

$totalPages = ceil($totalUsers / $limit);

$pageTitle = '用户管理';
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
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
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
            background: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .search-button:hover {
            background: #2980b9;
        }
        
        .users-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .users-table th,
        .users-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .users-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .users-table tr:hover {
            background: #f8f9fa;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3498db;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-name {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .user-email {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .admin-badge {
            background: #9b59b6;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: bold;
        }
        
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
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-warning:hover {
            background: #e67e22;
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
            background: #3498db;
            color: white;
            border-color: #3498db;
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
            max-width: 500px;
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
        
        .form-input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3498db;
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
            .users-table {
                font-size: 14px;
            }
            
            .users-table th,
            .users-table td {
                padding: 8px;
            }
            
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .modal-content {
                margin: 10% auto;
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="admin-container">
            <!-- 页面头部 -->
            <div class="admin-header">
                <div class="header-title">👥 用户管理</div>
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
                           placeholder="搜索用户名或邮箱..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-button">搜索</button>
                    <?php if ($search): ?>
                    <a href="users.php" class="search-button" style="background: #95a5a6;">清除</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- 用户列表 -->
            <div class="users-section">
                <h3>用户列表 (共 <?php echo number_format($totalUsers); ?> 个用户)</h3>
                
                <?php if (!empty($users)): ?>
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>用户</th>
                            <th>等级</th>
                            <th>管理员</th>
                            <th>注册时间</th>
                            <th>最后登录</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $userData): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar">
                                        <?php echo strtoupper(substr($userData['username'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="user-name"><?php echo htmlspecialchars($userData['username']); ?></div>
                                        <div class="user-email"><?php echo htmlspecialchars($userData['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <strong>Lv.<?php echo $userData['level']; ?></strong>
                            </td>
                            <td>
                                <?php if ($userData['admin_level'] > 0): ?>
                                <span class="admin-badge">
                                    <?php echo AdminManager::getAdminLevelName($userData['admin_level']); ?>
                                </span>
                                <?php else: ?>
                                <span style="color: #7f8c8d;">普通用户</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo date('Y-m-d', strtotime($userData['registration_date'])); ?>
                            </td>
                            <td>
                                <?php echo $userData['last_login'] ? date('Y-m-d H:i', strtotime($userData['last_login'])) : '从未登录'; ?>
                            </td>
                            <td>
                                <?php if ($adminManager->hasPermission('edit_user_resources')): ?>
                                <button class="action-button btn-primary" 
                                        onclick="editUserResources(<?php echo $userData['user_id']; ?>, '<?php echo htmlspecialchars($userData['username']); ?>')">
                                    资源
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($adminManager->hasPermission('edit_user_basic')): ?>
                                <button class="action-button btn-warning" 
                                        onclick="editUserBasic(<?php echo $userData['user_id']; ?>, '<?php echo htmlspecialchars($userData['username']); ?>', <?php echo $userData['level']; ?>)">
                                    基础
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($adminManager->hasPermission('manage_admins')): ?>
                                <button class="action-button btn-danger" 
                                        onclick="editUserAdmin(<?php echo $userData['user_id']; ?>, '<?php echo htmlspecialchars($userData['username']); ?>', <?php echo $userData['admin_level']; ?>)">
                                    权限
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- 分页 -->
                <?php if (!$search && $totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="users.php?page=<?php echo $page - 1; ?>">上一页</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                    <?php if ($i == $page): ?>
                    <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="users.php?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="users.php?page=<?php echo $page + 1; ?>">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div style="text-align: center; color: #7f8c8d; padding: 40px;">
                    <?php echo $search ? '未找到匹配的用户' : '暂无用户'; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 编辑用户资源模态框 -->
    <div id="resourceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">编辑用户资源</div>
                <span class="close" onclick="closeModal('resourceModal')">&times;</span>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="update_resources">
                <input type="hidden" name="user_id" id="resource_user_id">
                
                <div class="form-group">
                    <label class="form-label">用户名</label>
                    <input type="text" id="resource_username" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">亮晶晶</label>
                    <input type="number" name="bright_crystal" class="form-input" min="0" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">暖洋洋</label>
                    <input type="number" name="warm_crystal" class="form-input" min="0" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">冷冰冰</label>
                    <input type="number" name="cold_crystal" class="form-input" min="0" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">郁萌萌</label>
                    <input type="number" name="green_crystal" class="form-input" min="0" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">昼闪闪</label>
                    <input type="number" name="day_crystal" class="form-input" min="0" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">夜静静</label>
                    <input type="number" name="night_crystal" class="form-input" min="0" required>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeModal('resourceModal')" 
                            style="margin-right: 10px; padding: 8px 16px; background: #95a5a6; color: white; border: none; border-radius: 4px;">
                        取消
                    </button>
                    <button type="submit" class="action-button btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 编辑用户基础信息模态框 -->
    <div id="basicModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">编辑用户基础信息</div>
                <span class="close" onclick="closeModal('basicModal')">&times;</span>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="update_level">
                <input type="hidden" name="user_id" id="basic_user_id">
                
                <div class="form-group">
                    <label class="form-label">用户名</label>
                    <input type="text" id="basic_username" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">用户等级</label>
                    <input type="number" name="level" id="basic_level" class="form-input" min="1" max="100" required>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeModal('basicModal')" 
                            style="margin-right: 10px; padding: 8px 16px; background: #95a5a6; color: white; border: none; border-radius: 4px;">
                        取消
                    </button>
                    <button type="submit" class="action-button btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 编辑管理员权限模态框 -->
    <div id="adminModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">编辑管理员权限</div>
                <span class="close" onclick="closeModal('adminModal')">&times;</span>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="set_admin_level">
                <input type="hidden" name="user_id" id="admin_user_id">
                
                <div class="form-group">
                    <label class="form-label">用户名</label>
                    <input type="text" id="admin_username" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">管理员等级</label>
                    <select name="admin_level" id="admin_level" class="form-input" required>
                        <?php for ($i = 0; $i <= min(9, $user->getAdminLevel()); $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?> - <?php echo AdminManager::getAdminLevelName($i); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div style="background: #fff3cd; padding: 10px; border-radius: 4px; margin: 15px 0; font-size: 14px;">
                    <strong>注意：</strong>您只能设置不超过自己等级的管理员权限。
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeModal('adminModal')" 
                            style="margin-right: 10px; padding: 8px 16px; background: #95a5a6; color: white; border: none; border-radius: 4px;">
                        取消
                    </button>
                    <button type="submit" class="action-button btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function editUserResources(userId, username) {
            document.getElementById('resource_user_id').value = userId;
            document.getElementById('resource_username').value = username;
            
            // 获取用户当前资源
            fetch('../api/get_user_resources.php?user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelector('input[name="bright_crystal"]').value = data.resources.bright_crystal || 0;
                        document.querySelector('input[name="warm_crystal"]').value = data.resources.warm_crystal || 0;
                        document.querySelector('input[name="cold_crystal"]').value = data.resources.cold_crystal || 0;
                        document.querySelector('input[name="green_crystal"]').value = data.resources.green_crystal || 0;
                        document.querySelector('input[name="day_crystal"]').value = data.resources.day_crystal || 0;
                        document.querySelector('input[name="night_crystal"]').value = data.resources.night_crystal || 0;
                    }
                })
                .catch(error => console.error('Error:', error));
            
            document.getElementById('resourceModal').style.display = 'block';
        }
        
        function editUserBasic(userId, username, level) {
            document.getElementById('basic_user_id').value = userId;
            document.getElementById('basic_username').value = username;
            document.getElementById('basic_level').value = level;
            document.getElementById('basicModal').style.display = 'block';
        }
        
        function editUserAdmin(userId, username, adminLevel) {
            document.getElementById('admin_user_id').value = userId;
            document.getElementById('admin_username').value = username;
            document.getElementById('admin_level').value = adminLevel;
            document.getElementById('adminModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // 点击模态框外部关闭
        window.onclick = function(event) {
            const modals = ['resourceModal', 'basicModal', 'adminModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>

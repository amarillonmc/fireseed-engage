<?php
// 种火集结号 - 技能管理页面
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
if (!$adminManager->hasPermission('manage_skills')) {
    die('您没有权限访问此页面');
}

$error = '';
$success = '';

// 处理技能操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_skill':
            if ($adminManager->hasPermission('create_skills')) {
                $skill = new GeneralSkill();
                
                // 解析技能效果
                $skillEffect = [
                    'type' => $_POST['effect_type'] ?? '',
                    'value' => floatval($_POST['effect_value'] ?? 0),
                    'description' => $_POST['effect_description'] ?? ''
                ];
                
                $skillId = $skill->createSkill(
                    0, // general_id = 0 表示通用技能池
                    $_POST['skill_name'] ?? '',
                    $_POST['skill_type'] ?? '被动技能',
                    0, // slot
                    $skillEffect
                );
                
                if ($skillId) {
                    $success = '技能创建成功';
                    $user->logAdminAction('create_skill', 'skill', $skillId, "Created: {$_POST['skill_name']}");
                } else {
                    $error = '技能创建失败';
                }
            } else {
                $error = '您没有权限创建技能';
            }
            break;
            
        case 'update_skill':
            if ($adminManager->hasPermission('edit_skills')) {
                $skillId = intval($_POST['skill_id'] ?? 0);
                $skill = new GeneralSkill($skillId);
                
                if ($skill->isValid()) {
                    // 解析技能效果
                    $skillEffect = [
                        'type' => $_POST['effect_type'] ?? '',
                        'value' => floatval($_POST['effect_value'] ?? 0),
                        'description' => $_POST['effect_description'] ?? ''
                    ];
                    
                    $skill->setSkillName($_POST['skill_name'] ?? '');
                    $skill->setSkillType($_POST['skill_type'] ?? '被动技能');
                    $skill->setSkillEffect($skillEffect);
                    
                    if ($skill->save()) {
                        $success = '技能更新成功';
                        $user->logAdminAction('update_skill', 'skill', $skillId, "Updated: {$_POST['skill_name']}");
                    } else {
                        $error = '技能更新失败';
                    }
                } else {
                    $error = '技能不存在';
                }
            } else {
                $error = '您没有权限编辑技能';
            }
            break;
            
        case 'delete_skill':
            if ($adminManager->hasPermission('delete_skills')) {
                $skillId = intval($_POST['skill_id'] ?? 0);
                $skill = new GeneralSkill($skillId);
                
                if ($skill->isValid()) {
                    if ($skill->delete()) {
                        $success = '技能删除成功';
                        $user->logAdminAction('delete_skill', 'skill', $skillId, "Deleted skill");
                    } else {
                        $error = '技能删除失败';
                    }
                } else {
                    $error = '技能不存在';
                }
            } else {
                $error = '您没有权限删除技能';
            }
            break;
    }
}

// 获取搜索参数
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// 获取技能列表
$db = Database::getInstance()->getConnection();
$whereClause = '';
$params = [];

if ($search) {
    $whereClause = "WHERE skill_name LIKE ?";
    $searchParam = "%$search%";
    $params = [$searchParam];
}

$query = "SELECT * FROM general_skills $whereClause ORDER BY skill_id DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($query);
if ($search) {
    $stmt->bind_param('sii', $searchParam, $limit, $offset);
} else {
    $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

$skills = [];
while ($row = $result->fetch_assoc()) {
    $skills[] = $row;
}
$stmt->close();

// 获取总数
$countQuery = "SELECT COUNT(*) as total FROM general_skills $whereClause";
$countStmt = $db->prepare($countQuery);
if ($search) {
    $countStmt->bind_param('s', $searchParam);
}
$countStmt->execute();
$totalCount = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = ceil($totalCount / $limit);

$pageTitle = '技能管理';
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
            background: linear-gradient(135deg, #1abc9c 0%, #16a085 100%);
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
            background: #1abc9c;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .search-button:hover {
            background: #16a085;
        }
        
        .skills-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .skills-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .skills-table th,
        .skills-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .skills-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .skills-table tr:hover {
            background: #f8f9fa;
        }
        
        .skill-type-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }
        
        .skill-type-主动技能 { background: #e74c3c; color: white; }
        .skill-type-被动技能 { background: #3498db; color: white; }
        .skill-type-自带 { background: #27ae60; color: white; }
        .skill-type-装备 { background: #9b59b6; color: white; }
        
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
            background: #1abc9c;
            color: white;
        }
        
        .btn-primary:hover {
            background: #16a085;
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
            background: #1abc9c;
            color: white;
            border-color: #1abc9c;
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
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
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
            .skills-table {
                font-size: 14px;
            }
            
            .skills-table th,
            .skills-table td {
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
                <div class="header-title">✨ 技能管理</div>
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
                           placeholder="搜索技能名称..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-button">搜索</button>
                    <?php if ($search): ?>
                    <a href="skills.php" class="search-button" style="background: #95a5a6;">清除</a>
                    <?php endif; ?>
                    <?php if ($adminManager->hasPermission('create_skills')): ?>
                    <button type="button" class="search-button" style="background: #27ae60;" onclick="showCreateModal()">
                        + 创建技能
                    </button>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- 技能列表 -->
            <div class="skills-section">
                <h3>技能列表 (共 <?php echo number_format($totalCount); ?> 个技能)</h3>
                
                <?php if (!empty($skills)): ?>
                <table class="skills-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>技能名称</th>
                            <th>技能类型</th>
                            <th>效果类型</th>
                            <th>效果值</th>
                            <th>技能等级</th>
                            <th>槽位</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($skills as $skill): ?>
                        <?php 
                        $effectData = json_decode($skill['skill_effect'], true) ?: [];
                        ?>
                        <tr>
                            <td><?php echo $skill['skill_id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($skill['skill_name']); ?></strong></td>
                            <td>
                                <span class="skill-type-badge skill-type-<?php echo $skill['skill_type']; ?>">
                                    <?php echo $skill['skill_type']; ?>
                                </span>
                            </td>
                            <td><?php echo $effectData['type'] ?? '-'; ?></td>
                            <td><?php echo $effectData['value'] ?? '-'; ?></td>
                            <td>Lv.<?php echo $skill['skill_level']; ?></td>
                            <td><?php echo $skill['slot']; ?></td>
                            <td>
                                <?php if ($adminManager->hasPermission('edit_skills')): ?>
                                <button class="action-button btn-primary" 
                                        onclick="editSkill(<?php echo $skill['skill_id']; ?>)">
                                    编辑
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($adminManager->hasPermission('delete_skills')): ?>
                                <button class="action-button btn-danger" 
                                        onclick="deleteSkill(<?php echo $skill['skill_id']; ?>, '<?php echo htmlspecialchars($skill['skill_name']); ?>')">
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
                    <a href="skills.php?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">上一页</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                    <?php if ($i == $page): ?>
                    <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="skills.php?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="skills.php?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div style="text-align: center; color: #7f8c8d; padding: 40px;">
                    <?php echo $search ? '未找到匹配的技能' : '暂无技能'; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 创建/编辑技能模态框 -->
    <div id="skillModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title" id="modalTitle">创建技能</div>
                <span class="close" onclick="closeModal('skillModal')">&times;</span>
            </div>
            <form id="skillForm" method="post">
                <input type="hidden" name="action" id="formAction" value="create_skill">
                <input type="hidden" name="skill_id" id="edit_skill_id">
                
                <div class="form-group">
                    <label class="form-label">技能名称</label>
                    <input type="text" name="skill_name" id="skill_name" class="form-input" required>
                </div>
                
                <div class="form-row" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                    <div class="form-group">
                        <label class="form-label">技能类型</label>
                        <select name="skill_type" id="skill_type" class="form-select" required>
                            <option value="被动技能">被动技能</option>
                            <option value="主动技能">主动技能</option>
                            <option value="自带">自带</option>
                            <option value="装备">装备</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">效果类型</label>
                        <select name="effect_type" id="effect_type" class="form-select">
                            <option value="attack_boost">攻击力提升</option>
                            <option value="defense_boost">防御力提升</option>
                            <option value="speed_boost">速度提升</option>
                            <option value="resource_boost">资源产出提升</option>
                            <option value="training_speed">训练速度提升</option>
                            <option value="custom">自定义效果</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                    <div class="form-group">
                        <label class="form-label">效果值</label>
                        <input type="number" name="effect_value" id="effect_value" class="form-input" 
                               step="0.01" min="0" value="10" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">效果描述</label>
                    <textarea name="effect_description" id="effect_description" class="form-textarea" 
                              placeholder="描述这个技能的具体效果..."></textarea>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeModal('skillModal')" 
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
            document.getElementById('modalTitle').textContent = '创建技能';
            document.getElementById('formAction').value = 'create_skill';
            document.getElementById('edit_skill_id').value = '';
            
            // 清空表单
            document.getElementById('skill_name').value = '';
            document.getElementById('skill_type').value = '被动技能';
            document.getElementById('effect_type').value = 'attack_boost';
            document.getElementById('effect_value').value = '10';
            document.getElementById('effect_description').value = '';
            
            document.getElementById('skillModal').style.display = 'block';
        }
        
        function editSkill(skillId) {
            fetch('../api/get_skill.php?skill_id=' + skillId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').textContent = '编辑技能';
                        document.getElementById('formAction').value = 'update_skill';
                        document.getElementById('edit_skill_id').value = skillId;
                        
                        const skill = data.skill;
                        document.getElementById('skill_name').value = skill.skill_name;
                        document.getElementById('skill_type').value = skill.skill_type;
                        
                        const effectData = skill.skill_effect || {};
                        document.getElementById('effect_type').value = effectData.type || 'attack_boost';
                        document.getElementById('effect_value').value = effectData.value || 10;
                        document.getElementById('effect_description').value = effectData.description || '';
                        
                        document.getElementById('skillModal').style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('获取技能信息失败');
                });
        }
        
        function deleteSkill(skillId, skillName) {
            if (confirm('确定要删除技能「' + skillName + '」吗？此操作不可撤销！')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_skill">
                    <input type="hidden" name="skill_id" value="${skillId}">
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
            const modal = document.getElementById('skillModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>

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
if (!$adminManager->hasPermission('edit_game_config')) {
    die('您没有权限访问此页面');
}

$gameConfig = new GameConfig();
$error = '';
$success = '';

// 处理配置更新 / Process configuration updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $error = '请求验证失败，请刷新页面后重试 / Request validation failed; refresh and try again';
    } elseif (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_config':
                $configKey = $_POST['config_key'] ?? '';
                $configValue = $_POST['config_value'] ?? '';
                
                if (empty($configKey)) {
                    $error = '配置键不能为空';
                } else {
                    // 验证配置值
                    if ($gameConfig->validateConfig($configKey, $configValue)) {
                        // 缺少升级SQL时也将新图像项归入显示分类 / Keep the new image setting in the display category even before the upgrade SQL runs
                        $configCategory = $configKey === 'image_display_mode'
                            ? 'display'
                            : null;
                        if ($gameConfig->set(
                            $configKey,
                            $configValue,
                            null,
                            $configCategory
                        )) {
                            $success = '配置更新成功';
                            $user->logAdminAction('update_config', 'config', null, "$configKey = $configValue");
                        } else {
                            $error = '配置更新失败';
                        }
                    } else {
                        $error = '配置值无效';
                    }
                }
                break;
                
            case 'batch_update':
                $configs = [];
                foreach ($_POST as $key => $value) {
                    if (strpos($key, 'config_') === 0) {
                        $configKey = substr($key, 7); // 移除 'config_' 前缀
                        if ($gameConfig->validateConfig($configKey, $value)) {
                            $configs[$configKey] = $value;
                        } else {
                            $error = "配置 $configKey 的值无效";
                            break;
                        }
                    }
                }
                
                if (empty($error) && !empty($configs)) {
                    if ($gameConfig->batchUpdate($configs)) {
                        $success = '批量更新成功，共更新 ' . count($configs) . ' 项配置';
                        $user->logAdminAction('batch_update_config', 'config', null, 
                            'Updated ' . count($configs) . ' configs');
                    } else {
                        $error = '批量更新失败';
                    }
                }
                break;
                
            case 'reset_category':
                $category = $_POST['category'] ?? '';
                if ($gameConfig->resetToDefaults($category)) {
                    $success = "已重置 $category 分类的配置到默认值";
                    $user->logAdminAction('reset_config_category', 'config', null, "Category: $category");
                } else {
                    $error = '重置配置失败';
                }
                break;
        }
    }
}

// 获取配置分类
$categories = $gameConfig->getCategories();
$selectedCategory = $_GET['category'] ?? 'basic';

// 获取当前分类的配置
$configs = $gameConfig->getAll($selectedCategory);
$imageDisplayMode = getImageDisplayMode();

$pageTitle = '系统配置';
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
            max-width: 1200px;
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
        
        .category-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .category-tab {
            padding: 10px 20px;
            background: #ecf0f1;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            color: #2c3e50;
            transition: background-color 0.3s;
        }
        
        .category-tab.active {
            background: #3498db;
            color: white;
        }
        
        .category-tab:hover {
            background: #bdc3c7;
        }
        
        .category-tab.active:hover {
            background: #2980b9;
        }
        
        .config-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .config-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .config-table th,
        .config-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .config-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .config-key {
            font-family: monospace;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
        }
        
        .config-input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .config-input:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .config-description {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 4px;
        }
        
        .config-constant {
            background: #ffebee;
            color: #c62828;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
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
        
        .quick-config {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .quick-config-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
        }
        
        .quick-config-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        
        .quick-config-desc {
            color: #7f8c8d;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .image-mode-form {
            display: grid;
            gap: 12px;
        }

        .image-mode-select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
        }

        .image-mode-preview {
            display: flex;
            align-items: center;
            gap: 14px;
            min-height: 72px;
            padding: 10px;
            border: 1px dashed #b0bec5;
            border-radius: 6px;
            background: #fff;
        }

        .image-mode-preview .image-resource,
        .image-mode-preview picture,
        .image-mode-preview img {
            display: inline-flex;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #3498db;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        @media (max-width: 768px) {
            .category-tabs {
                justify-content: center;
            }
            
            .category-tab {
                font-size: 14px;
                padding: 8px 16px;
            }
            
            .config-table {
                font-size: 14px;
            }
            
            .action-buttons {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="admin-container">
            <!-- 页面头部 -->
            <div class="admin-header">
                <div class="header-title">⚙️ 系统配置</div>
                <a href="index.php" class="back-link">← 返回管理后台</a>
            </div>

            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- 快捷配置 -->
            <div class="config-section">
                <h3>快捷配置</h3>
                <div class="quick-config">
                    <div class="quick-config-item">
                        <div class="quick-config-title">维护模式</div>
                        <div class="quick-config-desc">开启后将阻止普通用户访问游戏</div>
                        <form method="post" style="display: inline;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="update_config">
                            <input type="hidden" name="config_key" value="maintenance_mode">
                            <input type="hidden" name="config_value" value="0">
                            <label class="toggle-switch">
                                <input type="checkbox" name="config_value" value="1" 
                                       <?php echo GameConfig::get('maintenance_mode', 0) ? 'checked' : ''; ?>
                                       onchange="this.form.submit()">
                                <span class="slider"></span>
                            </label>
                        </form>
                    </div>
                    
                    <div class="quick-config-item">
                        <div class="quick-config-title">新用户注册</div>
                        <div class="quick-config-desc">控制是否允许新用户注册</div>
                        <form method="post" style="display: inline;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="update_config">
                            <input type="hidden" name="config_key" value="new_player_registration">
                            <input type="hidden" name="config_value" value="0">
                            <label class="toggle-switch">
                                <input type="checkbox" name="config_value" value="1" 
                                       <?php echo GameConfig::get('new_player_registration', 1) ? 'checked' : ''; ?>
                                       onchange="this.form.submit()">
                                <span class="slider"></span>
                            </label>
                        </form>
                    </div>

                    <div class="quick-config-item">
                        <div class="quick-config-title">图像资源显示 / Image resources</div>
                        <div class="quick-config-desc">
                            正式图像优先使用 WebP 并以 PNG 兼容；文件缺失或加载失败时逐项回退到 Emoji。
                            / Formal images prefer WebP with PNG compatibility and fall back per item.
                        </div>
                        <form method="post" class="image-mode-form">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="update_config">
                            <input type="hidden" name="config_key" value="image_display_mode">
                            <label for="image-display-mode">全局显示模式 / Global display mode</label>
                            <select
                                id="image-display-mode"
                                class="image-mode-select"
                                name="config_value"
                            >
                                <option value="image" <?php echo $imageDisplayMode === 'image' ? 'selected' : ''; ?>>
                                    正式图像 / Image resources
                                </option>
                                <option value="emoji_fallback" <?php echo $imageDisplayMode === 'emoji_fallback' ? 'selected' : ''; ?>>
                                    Emoji 回退 / Emoji fallback
                                </option>
                            </select>
                            <div class="image-mode-preview" aria-label="当前图像模式预览">
                                <?php
                                echo renderImageResource(
                                    'resource_bright_crystal',
                                    48,
                                    ['loading' => 'eager']
                                );
                                echo renderImageResource(
                                    'facility_barracks',
                                    56,
                                    ['loading' => 'eager']
                                );
                                echo renderImageResource(
                                    'soldier_knight',
                                    48,
                                    ['loading' => 'eager']
                                );
                                ?>
                                <span>
                                    当前：<?php echo $imageDisplayMode === 'image'
                                        ? '正式图像 / Image resources'
                                        : 'Emoji 回退 / Emoji fallback'; ?>
                                </span>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                保存显示模式 / Save display mode
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- 配置分类标签 -->
            <div class="category-tabs">
                <?php foreach ($categories as $category): ?>
                <a href="config.php?category=<?php echo urlencode($category); ?>" 
                   class="category-tab <?php echo $selectedCategory == $category ? 'active' : ''; ?>">
                    <?php echo escapeHtml(ucfirst($category)); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- 配置列表 -->
            <div class="config-section">
                <h3><?php echo escapeHtml(ucfirst($selectedCategory)); ?> 配置</h3>
                
                <?php if (!empty($configs)): ?>
                <form method="post">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="batch_update">
                    
                    <table class="config-table">
                        <thead>
                            <tr>
                                <th>配置键</th>
                                <th>当前值</th>
                                <th>描述</th>
                                <th>状态</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($configs as $config): ?>
                            <tr>
                                <td>
                                    <div class="config-key"><?php echo htmlspecialchars($config['key']); ?></div>
                                </td>
                                <td>
                                    <?php if ($config['is_constant']): ?>
                                    <input type="text" class="config-input" 
                                           value="<?php echo htmlspecialchars($config['value']); ?>" 
                                           disabled>
                                    <?php elseif ($config['key'] === 'image_display_mode'): ?>
                                    <select
                                        class="config-input"
                                        name="config_image_display_mode"
                                    >
                                        <option value="image" <?php echo $config['value'] === 'image' ? 'selected' : ''; ?>>
                                            正式图像 / Image resources
                                        </option>
                                        <option value="emoji_fallback" <?php echo $config['value'] === 'emoji_fallback' ? 'selected' : ''; ?>>
                                            Emoji 回退 / Emoji fallback
                                        </option>
                                    </select>
                                    <?php else: ?>
                                    <input type="text" class="config-input" 
                                           name="config_<?php echo htmlspecialchars($config['key']); ?>"
                                           value="<?php echo htmlspecialchars($config['value']); ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="config-description">
                                        <?php echo htmlspecialchars($config['description'] ?? '无描述'); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($config['is_constant']): ?>
                                    <span class="config-constant">常量</span>
                                    <?php else: ?>
                                    <span style="color: #27ae60;">可修改</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="action-buttons">
                        <button type="submit" class="btn btn-primary">保存所有更改</button>
                        <button type="button" class="btn btn-warning" 
                                onclick="resetCategory(<?php echo escapeHtml(
                                    json_encode(
                                        (string) $selectedCategory,
                                        JSON_UNESCAPED_UNICODE
                                    )
                                ); ?>)">
                            重置为默认值
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div style="text-align: center; color: #7f8c8d; padding: 40px;">
                    该分类下暂无配置项
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function resetCategory(category) {
            if (confirm('确定要重置 ' + category + ' 分类的所有配置到默认值吗？此操作不可撤销。')) {
                const form = document.createElement('form');
                form.method = 'post';

                // 使用 DOM 属性写入受控字段并携带 CSRF / Write controlled fields through DOM properties and include CSRF
                const fields = {
                    action: 'reset_category',
                    category: category,
                    csrf_token: <?php echo json_encode(getCsrfToken()); ?>
                };
                Object.keys(fields).forEach(name => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = fields[name];
                    form.appendChild(input);
                });
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // 自动保存提示 / Warn about unsaved changes
        let hasChanges = false;
        document.querySelectorAll('.config-input').forEach(input => {
            input.addEventListener('input', function() {
                hasChanges = true;
            });
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (hasChanges) {
                e.preventDefault();
                e.returnValue = '您有未保存的更改，确定要离开吗？';
            }
        });
        
        // 任意表单提交后重置更改标记 / Reset the dirty flag after any form submission
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                hasChanges = false;
            });
        });
    </script>
</body>
</html>

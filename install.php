<?php
// 种火集结号 - 游戏安装脚本

// 检查是否已经安装
if (file_exists('config/installed.lock')) {
    die('游戏已经安装完成。如需重新安装，请删除 config/installed.lock 文件。');
}

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$error = '';
$success = '';

// 处理安装步骤
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 1:
            // 环境检查
            $step = 2;
            break;
            
        case 2:
            // 数据库配置
            $dbHost = $_POST['db_host'] ?? '';
            $dbUser = $_POST['db_user'] ?? '';
            $dbPass = $_POST['db_pass'] ?? '';
            $dbName = $_POST['db_name'] ?? '';
            $siteUrl = $_POST['site_url'] ?? '';
            $adminEmail = $_POST['admin_email'] ?? '';
            
            if (empty($dbHost) || empty($dbUser) || empty($dbName) || empty($siteUrl)) {
                $error = '请填写所有必填字段';
            } else {
                // 测试数据库连接
                try {
                    $testConn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
                    if ($testConn->connect_error) {
                        $error = '数据库连接失败: ' . $testConn->connect_error;
                    } else {
                        // 保存配置到会话
                        session_start();
                        $_SESSION['install_config'] = [
                            'db_host' => $dbHost,
                            'db_user' => $dbUser,
                            'db_pass' => $dbPass,
                            'db_name' => $dbName,
                            'site_url' => $siteUrl,
                            'admin_email' => $adminEmail
                        ];
                        $testConn->close();
                        $step = 3;
                    }
                } catch (Exception $e) {
                    $error = '数据库连接失败: ' . $e->getMessage();
                }
            }
            break;
            
        case 3:
            // 管理员账户创建
            session_start();
            $adminUsername = $_POST['admin_username'] ?? '';
            $adminPassword = $_POST['admin_password'] ?? '';
            $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';
            
            if (empty($adminUsername) || empty($adminPassword)) {
                $error = '请填写管理员用户名和密码';
            } elseif ($adminPassword !== $adminPasswordConfirm) {
                $error = '两次输入的密码不一致';
            } elseif (strlen($adminPassword) < 6) {
                $error = '密码长度至少6位';
            } else {
                $_SESSION['install_config']['admin_username'] = $adminUsername;
                $_SESSION['install_config']['admin_password'] = $adminPassword;
                $step = 4;
            }
            break;
            
        case 4:
            // 执行安装
            session_start();
            if (!isset($_SESSION['install_config'])) {
                $error = '安装配置丢失，请重新开始';
                $step = 1;
            } else {
                $result = performInstallation($_SESSION['install_config']);
                if ($result === true) {
                    $success = '安装完成！';
                    $step = 5;
                } else {
                    $error = $result;
                }
            }
            break;
    }
}

/**
 * 执行安装
 */
function performInstallation($config) {
    try {
        // 1. 创建配置文件
        $configContent = "<?php\n";
        $configContent .= "// 种火集结号 - 主配置文件\n";
        $configContent .= "// 由安装程序自动生成\n\n";
        $configContent .= "// 数据库配置\n";
        $configContent .= "define('DB_HOST', '" . addslashes($config['db_host']) . "');\n";
        $configContent .= "define('DB_USER', '" . addslashes($config['db_user']) . "');\n";
        $configContent .= "define('DB_PASS', '" . addslashes($config['db_pass']) . "');\n";
        $configContent .= "define('DB_NAME', '" . addslashes($config['db_name']) . "');\n";
        $configContent .= "define('DB_CHARSET', 'utf8mb4');\n\n";
        $configContent .= "// 网站基本设置\n";
        $configContent .= "define('SITE_NAME', '种火集结号');\n";
        $configContent .= "define('SITE_URL', '" . addslashes($config['site_url']) . "');\n";
        $configContent .= "define('ADMIN_EMAIL', '" . addslashes($config['admin_email']) . "');\n\n";
        $configContent .= "// 游戏基本设置\n";
        $configContent .= "define('GAME_VERSION', '1.0.0');\n";
        $configContent .= "define('DEBUG_MODE', false);\n\n";
        $configContent .= "// 时区设置\n";
        $configContent .= "date_default_timezone_set('Asia/Shanghai');\n\n";
        $configContent .= "// 会话设置\n";
        $configContent .= "ini_set('session.cookie_lifetime', 86400); // 24小时\n";
        $configContent .= "ini_set('session.gc_maxlifetime', 86400); // 24小时\n";
        $configContent .= "session_start();\n";
        
        if (!file_put_contents('config/config.php', $configContent)) {
            return '无法创建配置文件';
        }
        
        // 2. 连接数据库
        $db = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
        if ($db->connect_error) {
            return '数据库连接失败: ' . $db->connect_error;
        }
        $db->set_charset('utf8mb4');
        
        // 3. 创建数据库表
        $sqlFiles = [
            'sql/users.sql',
            'sql/game_config.sql',
            'sql/admin_logs.sql',
            'sql/map_tiles.sql',
            'sql/facilities.sql',
            'sql/technologies.sql',
            'sql/user_technologies.sql',
            'sql/generals.sql',
            'sql/general_skills.sql',
            'sql/general_assignments.sql',
            'sql/armies.sql',
            'sql/army_units.sql',
            'sql/battles.sql'
        ];
        
        foreach ($sqlFiles as $sqlFile) {
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                if ($sql) {
                    // 分割SQL语句
                    $statements = explode(';', $sql);
                    foreach ($statements as $statement) {
                        $statement = trim($statement);
                        if (!empty($statement)) {
                            if (!$db->query($statement)) {
                                return "执行SQL失败 ($sqlFile): " . $db->error;
                            }
                        }
                    }
                }
            }
        }
        
        // 4. 创建resources和cities表（如果不存在）
        $resourcesTable = "CREATE TABLE IF NOT EXISTS `resources` (
            `resource_id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `bright_crystal` int(11) DEFAULT 1000,
            `warm_crystal` int(11) DEFAULT 1000,
            `cold_crystal` int(11) DEFAULT 1000,
            `green_crystal` int(11) DEFAULT 1000,
            `day_crystal` int(11) DEFAULT 1000,
            `night_crystal` int(11) DEFAULT 1000,
            `last_update` datetime NOT NULL,
            PRIMARY KEY (`resource_id`),
            KEY `user_id` (`user_id`),
            CONSTRAINT `resources_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $citiesTable = "CREATE TABLE IF NOT EXISTS `cities` (
            `city_id` int(11) NOT NULL AUTO_INCREMENT,
            `owner_id` int(11) NOT NULL,
            `name` varchar(100) NOT NULL,
            `x` int(11) NOT NULL,
            `y` int(11) NOT NULL,
            `level` int(11) DEFAULT 1,
            `durability` int(11) DEFAULT 3000,
            `max_durability` int(11) DEFAULT 3000,
            `is_main_city` tinyint(1) DEFAULT 0,
            `last_circuit_production` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`city_id`),
            KEY `owner_id` (`owner_id`),
            UNIQUE KEY `coordinates` (`x`, `y`),
            CONSTRAINT `cities_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $soldiersTable = "CREATE TABLE IF NOT EXISTS `soldiers` (
            `soldier_id` int(11) NOT NULL AUTO_INCREMENT,
            `city_id` int(11) NOT NULL,
            `type` enum('pawn','knight','rook','bishop','golem','scout') NOT NULL,
            `level` int(11) DEFAULT 1,
            `quantity` int(11) DEFAULT 0,
            `in_training` int(11) DEFAULT 0,
            `training_complete_time` datetime DEFAULT NULL,
            PRIMARY KEY (`soldier_id`),
            UNIQUE KEY `city_soldier_type` (`city_id`,`type`),
            CONSTRAINT `soldiers_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `cities` (`city_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if (!$db->query($resourcesTable)) {
            return '创建resources表失败: ' . $db->error;
        }
        
        if (!$db->query($citiesTable)) {
            return '创建cities表失败: ' . $db->error;
        }
        
        if (!$db->query($soldiersTable)) {
            return '创建soldiers表失败: ' . $db->error;
        }
        
        // 5. 包含必要的类文件
        require_once 'includes/database.php';
        require_once 'includes/classes/User.php';
        require_once 'includes/classes/Technology.php';
        require_once 'includes/classes/MapGenerator.php';
        
        // 6. 创建管理员账户
        $adminUserId = User::createAdminUser(
            $config['admin_username'],
            $config['admin_password'],
            $config['admin_email'],
            9 // 超级管理员
        );
        
        if (!$adminUserId) {
            return '创建管理员账户失败';
        }
        
        // 7. 初始化默认科技
        Technology::initializeDefaultTechnologies();
        
        // 8. 生成初始地图
        $mapGenerator = new MapGenerator();
        $mapResult = $mapGenerator->generateMap(true);
        if ($mapResult !== true) {
            return '生成地图失败: ' . $mapResult;
        }
        
        // 9. 创建安装锁定文件
        $lockContent = "安装完成时间: " . date('Y-m-d H:i:s') . "\n";
        $lockContent .= "管理员用户: " . $config['admin_username'] . "\n";
        $lockContent .= "安装版本: 1.0.0\n";
        
        if (!file_put_contents('config/installed.lock', $lockContent)) {
            return '无法创建安装锁定文件';
        }
        
        $db->close();
        return true;
        
    } catch (Exception $e) {
        return '安装过程中发生错误: ' . $e->getMessage();
    }
}

/**
 * 检查环境要求
 */
function checkEnvironment() {
    $checks = [];
    
    // PHP版本检查
    $checks['php_version'] = [
        'name' => 'PHP版本 (>= 7.4)',
        'status' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'current' => PHP_VERSION
    ];
    
    // MySQL扩展检查
    $checks['mysqli'] = [
        'name' => 'MySQLi扩展',
        'status' => extension_loaded('mysqli'),
        'current' => extension_loaded('mysqli') ? '已安装' : '未安装'
    ];
    
    // JSON扩展检查
    $checks['json'] = [
        'name' => 'JSON扩展',
        'status' => extension_loaded('json'),
        'current' => extension_loaded('json') ? '已安装' : '未安装'
    ];
    
    // 会话支持检查
    $checks['session'] = [
        'name' => '会话支持',
        'status' => function_exists('session_start'),
        'current' => function_exists('session_start') ? '支持' : '不支持'
    ];
    
    // 文件写入权限检查
    $checks['config_writable'] = [
        'name' => 'config目录写入权限',
        'status' => is_writable('config'),
        'current' => is_writable('config') ? '可写' : '不可写'
    ];
    
    return $checks;
}

$envChecks = checkEnvironment();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>种火集结号 - 游戏安装</title>
    <style>
        body {
            font-family: 'Microsoft YaHei', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        
        .install-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .install-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .install-title {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .install-subtitle {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .install-content {
            padding: 30px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #ecf0f1;
            color: #7f8c8d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin: 0 10px;
            position: relative;
        }
        
        .step.active {
            background: #3498db;
            color: white;
        }
        
        .step.completed {
            background: #27ae60;
            color: white;
        }
        
        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            right: -25px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 2px;
            background: #ecf0f1;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            box-sizing: border-box;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .form-hint {
            font-size: 14px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background: #2980b9;
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-success:hover {
            background: #229954;
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
        
        .check-list {
            list-style: none;
            padding: 0;
        }
        
        .check-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .check-item:last-child {
            border-bottom: none;
        }
        
        .check-status {
            font-weight: bold;
        }
        
        .check-status.pass {
            color: #27ae60;
        }
        
        .check-status.fail {
            color: #e74c3c;
        }
        
        .text-center {
            text-align: center;
        }
        
        .mt-20 {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="install-header">
            <div class="install-title">🎮 种火集结号</div>
            <div class="install-subtitle">游戏安装向导</div>
        </div>
        
        <div class="install-content">
            <!-- 步骤指示器 -->
            <div class="step-indicator">
                <div class="step <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">1</div>
                <div class="step <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>">2</div>
                <div class="step <?php echo $step >= 3 ? ($step > 3 ? 'completed' : 'active') : ''; ?>">3</div>
                <div class="step <?php echo $step >= 4 ? ($step > 4 ? 'completed' : 'active') : ''; ?>">4</div>
                <div class="step <?php echo $step >= 5 ? 'active' : ''; ?>">5</div>
            </div>
            
            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if ($step == 1): ?>
            <!-- 步骤1: 环境检查 -->
            <h3>步骤 1: 环境检查</h3>
            <p>正在检查服务器环境是否满足游戏运行要求...</p>
            
            <ul class="check-list">
                <?php foreach ($envChecks as $check): ?>
                <li class="check-item">
                    <span><?php echo $check['name']; ?></span>
                    <span class="check-status <?php echo $check['status'] ? 'pass' : 'fail'; ?>">
                        <?php echo $check['status'] ? '✓ 通过' : '✗ 失败'; ?>
                        (<?php echo $check['current']; ?>)
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <?php
            $allPassed = true;
            foreach ($envChecks as $check) {
                if (!$check['status']) {
                    $allPassed = false;
                    break;
                }
            }
            ?>
            
            <div class="text-center mt-20">
                <?php if ($allPassed): ?>
                <form method="post">
                    <button type="submit" class="btn">下一步</button>
                </form>
                <?php else: ?>
                <p style="color: #e74c3c;">请解决上述环境问题后重新检查。</p>
                <button onclick="location.reload()" class="btn">重新检查</button>
                <?php endif; ?>
            </div>
            
            <?php elseif ($step == 2): ?>
            <!-- 步骤2: 数据库配置 -->
            <h3>步骤 2: 数据库配置</h3>
            <p>请填写数据库连接信息和基本站点设置。</p>
            
            <form method="post">
                <div class="form-group">
                    <label class="form-label">数据库主机 *</label>
                    <input type="text" name="db_host" class="form-input" value="localhost" required>
                    <div class="form-hint">通常为 localhost</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">数据库用户名 *</label>
                    <input type="text" name="db_user" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">数据库密码</label>
                    <input type="password" name="db_pass" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">数据库名称 *</label>
                    <input type="text" name="db_name" class="form-input" value="fireseed_engage" required>
                    <div class="form-hint">请确保数据库已创建</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">站点URL *</label>
                    <input type="url" name="site_url" class="form-input" value="<?php echo 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']); ?>" required>
                    <div class="form-hint">游戏的完整访问地址</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">管理员邮箱</label>
                    <input type="email" name="admin_email" class="form-input" value="admin@example.com">
                </div>
                
                <div class="text-center mt-20">
                    <button type="submit" class="btn">下一步</button>
                </div>
            </form>
            
            <?php elseif ($step == 3): ?>
            <!-- 步骤3: 管理员账户 -->
            <h3>步骤 3: 创建管理员账户</h3>
            <p>请设置超级管理员账户信息。</p>
            
            <form method="post">
                <div class="form-group">
                    <label class="form-label">管理员用户名 *</label>
                    <input type="text" name="admin_username" class="form-input" required>
                    <div class="form-hint">用于登录管理后台</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">管理员密码 *</label>
                    <input type="password" name="admin_password" class="form-input" required>
                    <div class="form-hint">密码长度至少6位</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">确认密码 *</label>
                    <input type="password" name="admin_password_confirm" class="form-input" required>
                </div>
                
                <div class="text-center mt-20">
                    <button type="submit" class="btn">下一步</button>
                </div>
            </form>
            
            <?php elseif ($step == 4): ?>
            <!-- 步骤4: 执行安装 -->
            <h3>步骤 4: 正在安装...</h3>
            <p>正在创建数据库表、初始化数据和配置文件，请稍候...</p>
            
            <form method="post">
                <div class="text-center mt-20">
                    <button type="submit" class="btn">开始安装</button>
                </div>
            </form>
            
            <?php elseif ($step == 5): ?>
            <!-- 步骤5: 安装完成 -->
            <h3>🎉 安装完成！</h3>
            <p>恭喜！种火集结号已成功安装。</p>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 6px; margin: 20px 0;">
                <h4>安装信息：</h4>
                <ul>
                    <li><strong>游戏版本：</strong>1.0.0</li>
                    <li><strong>安装时间：</strong><?php echo date('Y-m-d H:i:s'); ?></li>
                    <li><strong>管理员账户：</strong><?php echo htmlspecialchars($_SESSION['install_config']['admin_username'] ?? ''); ?></li>
                </ul>
            </div>
            
            <div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #ffc107;">
                <h4>重要提示：</h4>
                <ul>
                    <li>请删除或重命名 <code>install.php</code> 文件以确保安全</li>
                    <li>建议设置定时任务执行 <code>cron_tasks.php</code>（每分钟一次）</li>
                    <li>请妥善保管管理员账户信息</li>
                </ul>
            </div>
            
            <div class="text-center mt-20">
                <a href="index.php" class="btn btn-success">进入游戏</a>
                <a href="admin/" class="btn" style="margin-left: 10px;">管理后台</a>
            </div>
            
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

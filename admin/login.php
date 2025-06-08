<?php
// 包含初始化文件
require_once '../includes/init.php';

// 如果已经登录且是管理员，直接跳转到管理后台
if (isset($_SESSION['user_id'])) {
    $user = new User($_SESSION['user_id']);
    if ($user->isValid() && $user->isAdmin()) {
        header('Location: index.php');
        exit;
    }
}

$error = '';
$success = '';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        $user = new User();
        $loginResult = $user->login($username, $password);
        
        if ($loginResult === true) {
            // 检查是否为管理员
            if ($user->isAdmin()) {
                // 记录管理员登录日志
                $user->logAdminAction('admin_login');
                header('Location: index.php');
                exit;
            } else {
                $error = '您没有管理员权限';
                // 登出普通用户
                session_unset();
                session_destroy();
                session_start();
            }
        } else {
            $error = $loginResult;
        }
    }
}

$pageTitle = '管理员登录';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <style>
        body {
            font-family: 'Microsoft YaHei', Arial, sans-serif;
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
            margin: 20px;
        }
        
        .login-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .login-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .login-subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .login-content {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
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
            transition: border-color 0.3s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #e74c3c;
            box-shadow: 0 0 0 2px rgba(231, 76, 60, 0.2);
        }
        
        .login-button {
            width: 100%;
            background: #e74c3c;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .login-button:hover {
            background: #c0392b;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #c62828;
            font-size: 14px;
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #2e7d32;
            font-size: 14px;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #ecf0f1;
        }
        
        .footer-link {
            color: #7f8c8d;
            text-decoration: none;
            font-size: 14px;
        }
        
        .footer-link:hover {
            color: #2c3e50;
        }
        
        .admin-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .security-notice {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
            font-size: 14px;
        }
        
        .security-notice strong {
            display: block;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="admin-icon">🛡️</div>
            <div class="login-title">管理员登录</div>
            <div class="login-subtitle"><?php echo SITE_NAME; ?> 管理后台</div>
        </div>
        
        <div class="login-content">
            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <div class="security-notice">
                <strong>安全提示</strong>
                此页面仅供管理员使用。请确保您有合法的管理员权限。
            </div>
            
            <form method="post">
                <div class="form-group">
                    <label class="form-label">管理员用户名</label>
                    <input type="text" name="username" class="form-input" 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                           required autofocus>
                </div>
                
                <div class="form-group">
                    <label class="form-label">密码</label>
                    <input type="password" name="password" class="form-input" required>
                </div>
                
                <button type="submit" class="login-button">登录管理后台</button>
            </form>
            
            <div class="login-footer">
                <a href="../index.php" class="footer-link">← 返回游戏首页</a>
            </div>
        </div>
    </div>
    
    <script>
        // 自动聚焦到用户名输入框
        document.addEventListener('DOMContentLoaded', function() {
            const usernameInput = document.querySelector('input[name="username"]');
            if (usernameInput && !usernameInput.value) {
                usernameInput.focus();
            }
        });
        
        // 防止表单重复提交
        document.querySelector('form').addEventListener('submit', function() {
            const button = document.querySelector('.login-button');
            button.disabled = true;
            button.textContent = '登录中...';
            
            // 5秒后重新启用按钮
            setTimeout(function() {
                button.disabled = false;
                button.textContent = '登录管理后台';
            }, 5000);
        });
    </script>
</body>
</html>

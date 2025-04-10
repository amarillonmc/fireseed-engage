<?php
// 包含初始化文件
require_once 'includes/init.php';

// 检查用户是否已登录
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

// 处理登录表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        $user = new User();
        $userId = $user->login($username, $password);
        
        if ($userId) {
            $_SESSION['user_id'] = $userId;
            header('Location: index.php');
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    }
}

// 页面标题
$pageTitle = '登录';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="login-container">
            <h1 class="login-title"><?php echo SITE_NAME; ?> - 登录</h1>
            
            <?php if (!empty($error)): ?>
            <div class="message error">
                <p><?php echo $error; ?></p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div class="form-group">
                    <button type="submit">登录</button>
                </div>
            </form>
            
            <div class="register-link">
                <p>还没有账号？<a href="register.php">立即注册</a></p>
            </div>
        </div>
    </div>
</body>
</html>

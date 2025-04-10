<?php
// 包含初始化文件
require_once 'includes/init.php';

// 检查用户是否已登录
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// 处理注册表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    
    if (empty($username) || empty($password) || empty($confirmPassword) || empty($email)) {
        $error = '请填写所有字段';
    } elseif ($password !== $confirmPassword) {
        $error = '两次输入的密码不一致';
    } elseif (strlen($username) < 3 || strlen($username) > 20) {
        $error = '用户名长度必须在3-20个字符之间';
    } elseif (strlen($password) < 6) {
        $error = '密码长度必须至少为6个字符';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入有效的电子邮箱地址';
    } else {
        $user = new User();
        $userId = $user->createUser($username, $password, $email);
        
        if ($userId) {
            $success = '注册成功，请登录';
        } else {
            $error = '注册失败，用户名或电子邮箱可能已被使用';
        }
    }
}

// 页面标题
$pageTitle = '注册';
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
        <div class="register-container">
            <h1 class="register-title"><?php echo SITE_NAME; ?> - 注册</h1>
            
            <?php if (!empty($error)): ?>
            <div class="message error">
                <p><?php echo $error; ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
            <div class="message success">
                <p><?php echo $success; ?></p>
                <p><a href="login.php">点击此处登录</a></p>
            </div>
            <?php else: ?>
            <form method="post" action="">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="email">电子邮箱</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">确认密码</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                
                <div class="form-group">
                    <button type="submit">注册</button>
                </div>
            </form>
            <?php endif; ?>
            
            <div class="login-link">
                <p>已有账号？<a href="login.php">立即登录</a></p>
            </div>
        </div>
    </div>
</body>
</html>

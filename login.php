<?php
// 包含初始化文件
require_once 'includes/init.php';

// 检查用户是否已登录
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = isset($_GET['logged_out'])
    ? '您已安全退出登录 / You have been signed out securely.'
    : '';
$username = '';

// 处理登录表单提交 / Process the login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) && is_scalar($_POST['username'])
        ? trim((string) $_POST['username'])
        : '';
    $password = isset($_POST['password']) && is_scalar($_POST['password'])
        ? (string) $_POST['password']
        : '';
    
    if (!validateCsrfToken()) {
        $error = '请求已过期，请刷新页面后重试 / Request expired; refresh and try again.';
    } elseif (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        $throttle = AuthSecurity::getLoginThrottleStatus(
            'player',
            $username
        );
        if (empty($throttle['allowed'])) {
            $retryMinutes = max(
                1,
                (int) ceil((int) $throttle['retry_after'] / 60)
            );
            $error = "登录尝试过多，请在 {$retryMinutes} 分钟后重试"
                . " / Too many attempts; retry in {$retryMinutes} minute(s).";
        } else {
            $user = new User();
            $userId = $user->login($username, $password);

            if (is_numeric($userId) && (int) $userId > 0) {
                AuthSecurity::clearLoginFailures('player', $username);
                if (AuthSecurity::establishAuthenticatedSession($userId)) {
                    header('Location: index.php');
                    exit;
                }
                $error = '无法建立安全会话，请稍后重试'
                    . ' / Unable to establish a secure session; try again later.';
            } else {
                AuthSecurity::recordLoginFailure('player', $username);
                $error = '用户名或密码错误';
            }
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

            <?php if (!empty($success)): ?>
            <div class="message success">
                <p><?php echo escapeHtml($success); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
            <div class="message error">
                <p><?php echo escapeHtml($error); ?></p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username"
                           value="<?php echo escapeHtml($username); ?>"
                           autocomplete="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" required>
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

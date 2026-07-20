<?php
// 种火集结号 - 安全退出登录 / Fireseed Engage - Secure sign-out

require_once 'includes/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        http_response_code(400);
        $error = '请求已过期，请返回后重试 / Request expired; go back and try again.';
    } else {
        AuthSecurity::destroyAuthenticatedSession();
        header('Location: login.php?logged_out=1');
        exit;
    }
}

$pageTitle = '退出登录 / Sign out';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <main class="login-container">
            <h1 class="login-title"><?php echo escapeHtml($pageTitle); ?></h1>

            <?php if ($error): ?>
            <div class="message error">
                <p><?php echo escapeHtml($error); ?></p>
            </div>
            <?php endif; ?>

            <p>确认退出当前账号吗？ / Sign out of the current account?</p>
            <form method="post">
                <?php echo csrfField(); ?>
                <button type="submit">确认退出 / Sign out</button>
                <a href="index.php">取消 / Cancel</a>
            </form>
        </main>
    </div>
</body>
</html>

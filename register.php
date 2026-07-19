<?php
// 包含初始化文件
require_once 'includes/init.php';

// 检查用户是否已登录
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$registrationAvailability = AuthSecurity::getRegistrationAvailability();
$error = !empty($registrationAvailability['open'])
    ? ''
    : $registrationAvailability['message'];
$success = '';
$username = '';
$email = '';

// 处理注册表单提交 / Process the registration form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) && is_scalar($_POST['username'])
        ? trim((string) $_POST['username'])
        : '';
    $password = isset($_POST['password']) && is_scalar($_POST['password'])
        ? (string) $_POST['password']
        : '';
    $confirmPassword = isset($_POST['confirm_password'])
        && is_scalar($_POST['confirm_password'])
        ? (string) $_POST['confirm_password']
        : '';
    $email = isset($_POST['email']) && is_scalar($_POST['email'])
        ? trim((string) $_POST['email'])
        : '';
    
    if (!validateCsrfToken()) {
        $error = '请求已过期，请刷新页面后重试 / Request expired; refresh and try again.';
    } elseif (empty($registrationAvailability['open'])) {
        $error = $registrationAvailability['message'];
    } elseif (empty($username) || empty($password) || empty($confirmPassword) || empty($email)) {
        $error = '请填写所有字段';
    } elseif ($password !== $confirmPassword) {
        $error = '两次输入的密码不一致';
    } elseif (mb_strlen($username, 'UTF-8') < 3
        || mb_strlen($username, 'UTF-8') > 20
    ) {
        $error = '用户名长度必须在3-20个字符之间';
    } elseif (!preg_match('/^[\p{L}\p{N}_-]+$/u', $username)) {
        $error = '用户名只能包含文字、数字、下划线和短横线';
    } elseif (mb_strlen($password, 'UTF-8') < 10
        || strlen($password) > 256
    ) {
        $error = '密码长度必须为10至256个字符';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入有效的电子邮箱地址';
    } elseif (strlen($email) > 254) {
        $error = '电子邮箱地址过长';
    } else {
        // 命名锁让容量复查与创建成为单服原子入口 / A named lock serializes the capacity recheck and account creation
        $db = Database::getInstance()->getConnection();
        $lockName = 'fireseed_player_registration';
        $hasRegistrationLock = false;
        try {
            $lockQuery = "SELECT GET_LOCK(?, 5) AS acquired";
            $lockStmt = $db->prepare($lockQuery);
            if (!$lockStmt) {
                throw new RuntimeException(
                    'Unable to prepare registration lock'
                );
            }
            $lockStmt->bind_param('s', $lockName);
            if (!$lockStmt->execute()) {
                $lockStmt->close();
                throw new RuntimeException(
                    'Unable to acquire registration lock'
                );
            }
            $lockResult = $lockStmt->get_result();
            $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
            $lockStmt->close();
            $hasRegistrationLock = $lockRow
                && (int) $lockRow['acquired'] === 1;

            if (!$hasRegistrationLock) {
                $error = '注册服务正忙，请稍后重试'
                    . ' / Registration is busy; please try again shortly.';
            } else {
                // 锁内刷新配置与玩家总数，防止并发超过上限 / Refresh configuration and capacity inside the lock
                GameConfig::clearCache();
                $registrationAvailability =
                    AuthSecurity::getRegistrationAvailability();
                if (empty($registrationAvailability['open'])) {
                    $error = $registrationAvailability['message'];
                } else {
                    $user = new User();
                    $userId = $user->createUser(
                        $username,
                        $password,
                        $email
                    );

                    if ($userId) {
                        $success = '注册成功，请登录';
                    } else {
                        $error = '注册失败，用户名或电子邮箱可能已被使用';
                    }
                }
            }
        } catch (Throwable $exception) {
            error_log(
                'Registration endpoint failed: '
                . $exception->getMessage()
            );
            $error = '注册服务暂时不可用，请稍后重试';
        } finally {
            if ($hasRegistrationLock) {
                $releaseQuery = "SELECT RELEASE_LOCK(?)";
                $releaseStmt = $db->prepare($releaseQuery);
                if ($releaseStmt) {
                    $releaseStmt->bind_param('s', $lockName);
                    if (!$releaseStmt->execute()) {
                        error_log(
                            'Registration lock release failed for '
                            . $lockName
                        );
                    }
                    $releaseStmt->close();
                } else {
                    error_log(
                        'Unable to prepare registration lock release'
                    );
                }
            }
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
                <p><?php echo escapeHtml($error); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
            <div class="message success">
                <p><?php echo $success; ?></p>
                <p><a href="login.php">点击此处登录</a></p>
            </div>
            <?php elseif (!empty($registrationAvailability['open'])): ?>
            <form method="post" action="">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username"
                           value="<?php echo escapeHtml($username); ?>"
                           minlength="3" maxlength="20"
                           pattern="[\p{L}\p{N}_-]+"
                           autocomplete="username" required>
                </div>
                
                <div class="form-group">
                    <label for="email">电子邮箱</label>
                    <input type="email" id="email" name="email"
                           value="<?php echo escapeHtml($email); ?>"
                           maxlength="254" autocomplete="email" required>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password"
                           minlength="10" maxlength="256"
                           autocomplete="new-password" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">确认密码</label>
                    <input type="password" id="confirm_password"
                           name="confirm_password" minlength="10" maxlength="256"
                           autocomplete="new-password"
                           required>
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

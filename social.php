<?php
// 种火集结号 - 邮件、聊天与好友页面 / Fireseed Engage - Mail, chat, and friendship page

require_once 'includes/init.php';
require_once 'includes/gameplay_ui.php';
require_once 'includes/classes/SocialService.php';
require_once 'includes/classes/AllianceService.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User((int) $_SESSION['user_id']);
if (!$user->isValid()) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

/**
 * 转义社交页面输出 / Escape social page output
 */
function socialEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$service = new SocialService();
$allianceService = new AllianceService();
$membership = $allianceService->getMembership($user->getUserId());
$actionResult = null;
$openedMail = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $sessionToken = isset($_SESSION['social_csrf_token'])
        ? (string) $_SESSION['social_csrf_token']
        : '';

    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $actionResult = [
            'success' => false,
            'message' => '页面令牌已过期，请刷新页面后重试。',
            'data' => []
        ];
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        switch ($action) {
            case 'send_mail':
                $actionResult = $service->sendMail(
                    $user->getUserId(),
                    $_POST['receiver_username'] ?? '',
                    $_POST['subject'] ?? '',
                    $_POST['body'] ?? ''
                );
                break;
            case 'read_mail':
                $openedMail = $service->readMail(
                    $user->getUserId(),
                    $_POST['message_id'] ?? 0
                );
                $actionResult = $openedMail
                    ? ['success' => true, 'message' => '邮件已打开。', 'data' => []]
                    : ['success' => false, 'message' => '邮件不存在或不属于你。', 'data' => []];
                break;
            case 'send_chat':
                $actionResult = $service->sendChatMessage(
                    $user->getUserId(),
                    $_POST['channel_type'] ?? '',
                    $_POST['body'] ?? ''
                );
                break;
            case 'friend_request':
                $actionResult = $service->sendFriendRequest(
                    $user->getUserId(),
                    $_POST['username'] ?? ''
                );
                break;
            case 'accept_friend':
                $actionResult = $service->respondToFriendRequest(
                    $user->getUserId(),
                    $_POST['friendship_id'] ?? 0,
                    'accepted'
                );
                break;
            case 'reject_friend':
                $actionResult = $service->respondToFriendRequest(
                    $user->getUserId(),
                    $_POST['friendship_id'] ?? 0,
                    'rejected'
                );
                break;
            default:
                $actionResult = [
                    'success' => false,
                    'message' => '无法识别该操作。',
                    'data' => []
                ];
        }
    }

    // 每次提交后轮换令牌，防止重复提交 / Rotate the token after each submission to prevent replay
    unset($_SESSION['social_csrf_token']);
}

if (empty($_SESSION['social_csrf_token'])) {
    $_SESSION['social_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['social_csrf_token'];

$resource = new Resource($user->getUserId());
$inbox = $service->getInbox($user->getUserId());
$sentMail = $service->getSentMail($user->getUserId());
$worldChat = $service->getChatMessages($user->getUserId(), 'world');
$allianceChat = $membership ? $service->getChatMessages($user->getUserId(), 'alliance') : [];
$friendships = $service->getFriendshipState($user->getUserId());
$pageTitle = '邮件、聊天与好友';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo socialEscape(SITE_NAME); ?> - <?php echo socialEscape($pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .social-panel { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
        .social-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
        .social-panel h3, .social-panel h4 { margin-bottom: 10px; }
        .social-form label { display: block; margin-top: 8px; font-weight: bold; }
        .social-form input, .social-form textarea, .social-form select {
            width: 100%; padding: 8px; margin-top: 4px; border: 1px solid #ccc; border-radius: 3px;
        }
        .social-form button, .inline-form button {
            margin-top: 10px; padding: 8px 12px; border: 0; border-radius: 3px;
            background: #333; color: #fff; cursor: pointer;
        }
        .inline-form { display: inline-block; margin-right: 6px; }
        .social-table { width: 100%; border-collapse: collapse; }
        .social-table th, .social-table td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        .social-table th { background: #f0f0f0; }
        .unread-mail { font-weight: bold; }
        .mail-view { border-left: 4px solid #555; padding: 12px; margin-bottom: 20px; background: #fafafa; }
        .chat-log { height: 320px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fafafa; }
        .chat-line { margin-bottom: 8px; overflow-wrap: anywhere; }
        .chat-time { color: #777; font-size: 12px; }
        .friend-card { border: 1px solid #ddd; border-radius: 4px; padding: 10px; margin-bottom: 8px; }
        .danger-button { background: #9d2323 !important; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1 class="site-title"><?php echo socialEscape(SITE_NAME); ?></h1>
        <h2 class="page-title"><?php echo socialEscape($pageTitle); ?></h2>
        <nav class="main-nav">
            <ul>
                <li><a href="index.php">主基地</a></li>
                <li><a href="generals.php">武将</a></li>
                <li><a href="map.php">地图</a></li>
                <li><a href="alliance.php">联盟</a></li>
                <li><a href="ranking.php">排名</a></li>
                <li class="circuit-points">
                    <?php echo renderImageResource(
                        'resource_circuit_points',
                        24,
                        ['alt' => '思考回路 / Circuit Points']
                    ); ?>
                    <span class="circuit-label">思考回路:</span>
                    <span class="circuit-value">
                        <?php echo number_format($user->getCircuitPoints()); ?> /
                        <?php echo number_format($user->getMaxCircuitPoints()); ?>
                    </span>
                </li>
            </ul>
        </nav>
    </header>

    <main>
        <!-- 资源栏 / Resource bar -->
        <?php renderGameplayResourceBar($resource); ?>

        <?php if ($actionResult !== null): ?>
            <div class="message <?php echo $actionResult['success'] ? 'success' : 'error'; ?>">
                <?php echo socialEscape($actionResult['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($openedMail): ?>
            <section class="social-panel mail-view">
                <h3><?php echo socialEscape($openedMail['subject']); ?></h3>
                <p>发件人：<?php echo socialEscape($openedMail['sender_name']); ?>　<?php echo socialEscape($openedMail['sent_at']); ?></p>
                <p><?php echo nl2br(socialEscape($openedMail['body'])); ?></p>
            </section>
        <?php endif; ?>

        <div class="social-grid">
            <section class="social-panel">
                <h3>写邮件</h3>
                <form class="social-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo socialEscape($csrfToken); ?>">
                    <input type="hidden" name="action" value="send_mail">
                    <label for="mail-receiver">收件人用户名</label>
                    <input id="mail-receiver" name="receiver_username" maxlength="50" required>
                    <label for="mail-subject">主题</label>
                    <input id="mail-subject" name="subject" maxlength="120" required>
                    <label for="mail-body">正文</label>
                    <textarea id="mail-body" name="body" maxlength="5000" rows="7" required></textarea>
                    <button type="submit">发送邮件</button>
                </form>
            </section>

            <section class="social-panel">
                <h3>收件箱</h3>
                <table class="social-table">
                    <thead><tr><th>发件人</th><th>主题</th><th>时间</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($inbox as $mail): ?>
                        <tr class="<?php echo (int) $mail['is_read'] === 0 ? 'unread-mail' : ''; ?>">
                            <td><?php echo socialEscape($mail['sender_name']); ?></td>
                            <td><?php echo socialEscape($mail['subject']); ?></td>
                            <td><?php echo socialEscape($mail['sent_at']); ?></td>
                            <td>
                                <form class="inline-form" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo socialEscape($csrfToken); ?>">
                                    <input type="hidden" name="action" value="read_mail">
                                    <input type="hidden" name="message_id" value="<?php echo (int) $mail['message_id']; ?>">
                                    <button type="submit">阅读</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($inbox)): ?>
                        <tr><td colspan="4">收件箱为空。</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>

        <section class="social-panel">
            <h3>已发送邮件</h3>
            <table class="social-table">
                <thead><tr><th>收件人</th><th>主题</th><th>发送时间</th><th>阅读状态</th></tr></thead>
                <tbody>
                <?php foreach ($sentMail as $mail): ?>
                    <tr>
                        <td><?php echo socialEscape($mail['receiver_name']); ?></td>
                        <td><?php echo socialEscape($mail['subject']); ?></td>
                        <td><?php echo socialEscape($mail['sent_at']); ?></td>
                        <td><?php echo (int) $mail['is_read'] === 1 ? '已读' : '未读'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($sentMail)): ?>
                    <tr><td colspan="4">还没有发送过邮件。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <div class="social-grid">
            <section class="social-panel">
                <h3>世界频道</h3>
                <div class="chat-log">
                    <?php foreach ($worldChat as $chat): ?>
                        <p class="chat-line">
                            <span class="chat-time">[<?php echo socialEscape($chat['sent_at']); ?>]</span>
                            <strong><?php echo socialEscape($chat['sender_name']); ?>：</strong>
                            <?php echo socialEscape($chat['body']); ?>
                        </p>
                    <?php endforeach; ?>
                    <?php if (empty($worldChat)): ?><p>暂无消息。</p><?php endif; ?>
                </div>
                <form class="social-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo socialEscape($csrfToken); ?>">
                    <input type="hidden" name="action" value="send_chat">
                    <input type="hidden" name="channel_type" value="world">
                    <label for="world-message">消息</label>
                    <input id="world-message" name="body" maxlength="500" required>
                    <button type="submit">发送到世界频道</button>
                </form>
            </section>

            <section class="social-panel">
                <h3>联盟频道</h3>
                <?php if ($membership): ?>
                    <div class="chat-log">
                        <?php foreach ($allianceChat as $chat): ?>
                            <p class="chat-line">
                                <span class="chat-time">[<?php echo socialEscape($chat['sent_at']); ?>]</span>
                                <strong><?php echo socialEscape($chat['sender_name']); ?>：</strong>
                                <?php echo socialEscape($chat['body']); ?>
                            </p>
                        <?php endforeach; ?>
                        <?php if (empty($allianceChat)): ?><p>暂无消息。</p><?php endif; ?>
                    </div>
                    <form class="social-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo socialEscape($csrfToken); ?>">
                        <input type="hidden" name="action" value="send_chat">
                        <input type="hidden" name="channel_type" value="alliance">
                        <label for="alliance-message">消息</label>
                        <input id="alliance-message" name="body" maxlength="500" required>
                        <button type="submit">发送到联盟频道</button>
                    </form>
                <?php else: ?>
                    <p>加入联盟后即可使用联盟频道。</p>
                    <p><a href="alliance.php">前往联盟页面</a></p>
                <?php endif; ?>
            </section>
        </div>

        <div class="social-grid">
            <section class="social-panel">
                <h3>添加好友</h3>
                <form class="social-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo socialEscape($csrfToken); ?>">
                    <input type="hidden" name="action" value="friend_request">
                    <label for="friend-username">用户名</label>
                    <input id="friend-username" name="username" maxlength="50" required>
                    <button type="submit">发送好友申请</button>
                </form>
                <h4>我的好友</h4>
                <?php foreach ($friendships['friends'] as $friend): ?>
                    <div class="friend-card"><?php echo socialEscape($friend['username']); ?></div>
                <?php endforeach; ?>
                <?php if (empty($friendships['friends'])): ?><p>好友列表为空。</p><?php endif; ?>
            </section>

            <section class="social-panel">
                <h3>收到的好友申请</h3>
                <?php foreach ($friendships['incoming'] as $request): ?>
                    <div class="friend-card">
                        <strong><?php echo socialEscape($request['username']); ?></strong>
                        <form class="inline-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo socialEscape($csrfToken); ?>">
                            <input type="hidden" name="action" value="accept_friend">
                            <input type="hidden" name="friendship_id" value="<?php echo (int) $request['friendship_id']; ?>">
                            <button type="submit">接受</button>
                        </form>
                        <form class="inline-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo socialEscape($csrfToken); ?>">
                            <input type="hidden" name="action" value="reject_friend">
                            <input type="hidden" name="friendship_id" value="<?php echo (int) $request['friendship_id']; ?>">
                            <button class="danger-button" type="submit">拒绝</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($friendships['incoming'])): ?><p>没有待处理申请。</p><?php endif; ?>

                <h4>已发送申请</h4>
                <?php foreach ($friendships['outgoing'] as $request): ?>
                    <div class="friend-card">
                        <?php echo socialEscape($request['username']); ?>　
                        <span class="chat-time"><?php echo socialEscape($request['created_at']); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($friendships['outgoing'])): ?><p>没有等待对方处理的申请。</p><?php endif; ?>
            </section>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo socialEscape(SITE_NAME); ?> - 版本 <?php echo socialEscape(GAME_VERSION); ?></p>
    </footer>
</div>
</body>
</html>

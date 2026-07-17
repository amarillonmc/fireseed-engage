<?php
// 种火集结号 - 任务与成就页面 / Fireseed Engage - Quest and achievement page

require_once 'includes/init.php';
require_once 'includes/gameplay_ui.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid()) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

$progressService = new ProgressService();
$result = null;
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $result = ['success' => false, 'message' => '请求校验失败，请刷新页面后重试'];
    } elseif (isset($_POST['action']) && $_POST['action'] === 'claim_quest') {
        $result = $progressService->claimQuest(
            $user->getUserId(),
            isset($_POST['user_quest_id']) ? (int) $_POST['user_quest_id'] : 0
        );
    } elseif (isset($_POST['action']) && $_POST['action'] === 'claim_achievement') {
        $result = $progressService->claimAchievement(
            $user->getUserId(),
            isset($_POST['achievement_id']) ? (int) $_POST['achievement_id'] : 0
        );
    }
}

$resource = new Resource($user->getUserId());
$dashboard = $progressService->getDashboard($user->getUserId());
$pageTitle = '任务与成就';
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
    <?php renderGameplayHeader($pageTitle, $user, 'quests'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>
        <?php renderGameplayNotice($result); ?>

        <section class="gameplay-section">
            <h3>任务</h3>
            <div class="gameplay-grid">
                <?php foreach ($dashboard['quests'] as $quest): ?>
                    <?php
                    $target = max(1, (int) $quest['target_value']);
                    $percent = min(100, (int) floor((int) $quest['progress'] / $target * 100));
                    ?>
                    <article class="gameplay-card">
                        <h4><?php echo escapeHtml($quest['name']); ?></h4>
                        <p><?php echo escapeHtml($quest['description']); ?></p>
                        <span class="gameplay-badge"><?php echo escapeHtml($quest['reset_cycle']); ?></span>
                        <div class="progress-track"><span style="width: <?php echo $percent; ?>%"></span></div>
                        <p><?php echo number_format((int) $quest['progress']); ?> / <?php echo number_format($target); ?></p>
                        <p><strong>奖励：</strong><?php echo escapeHtml(formatGameplayBundle($quest['reward'])); ?></p>
                        <?php if ($quest['status'] === 'completed' && $quest['user_quest_id']): ?>
                            <form method="post">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="claim_quest">
                                <input type="hidden" name="user_quest_id" value="<?php echo (int) $quest['user_quest_id']; ?>">
                                <button class="gameplay-button" type="submit">领取</button>
                            </form>
                        <?php elseif ($quest['status'] === 'claimed'): ?>
                            <p class="muted">已领取</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="gameplay-section">
            <h3>成就</h3>
            <div class="gameplay-grid">
                <?php foreach ($dashboard['achievements'] as $achievement): ?>
                    <?php
                    $target = max(1, (int) $achievement['target_value']);
                    $percent = min(100, (int) floor((int) $achievement['progress'] / $target * 100));
                    ?>
                    <article class="gameplay-card">
                        <h4><?php echo escapeHtml($achievement['name']); ?></h4>
                        <p><?php echo escapeHtml($achievement['description']); ?></p>
                        <div class="progress-track"><span style="width: <?php echo $percent; ?>%"></span></div>
                        <p><?php echo number_format((int) $achievement['progress']); ?> / <?php echo number_format($target); ?></p>
                        <p><strong>奖励：</strong><?php echo escapeHtml(formatGameplayBundle($achievement['reward'])); ?></p>
                        <?php if ($achievement['unlocked_at'] && !$achievement['claimed_at']): ?>
                            <form method="post">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="claim_achievement">
                                <input type="hidden" name="achievement_id" value="<?php echo (int) $achievement['achievement_id']; ?>">
                                <button class="gameplay-button" type="submit">领取</button>
                            </form>
                        <?php elseif ($achievement['claimed_at']): ?>
                            <p class="muted">已领取</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
</body>
</html>

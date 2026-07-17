<?php
// 种火集结号 - 集结中枢 / Fireseed Engage - Gameplay hub

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

$resource = new Resource($user->getUserId());
$economyService = new EconomyService();
$progressService = new ProgressService();
$seasonService = new SeasonService();
$wallet = $economyService->getWallet($user->getUserId());
$items = $economyService->getItems($user->getUserId());
$progress = $progressService->getDashboard($user->getUserId());
$seasonOverview = $seasonService->getOverview($user->getUserId());
$generals = General::getUserGenerals($user->getUserId());
$armies = Army::getUserArmies($user->getUserId());
$completedQuests = 0;
foreach ($progress['quests'] as $quest) {
    if ($quest['status'] === 'completed') {
        $completedQuests++;
    }
}
$pageTitle = '集结中枢';
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
    <?php renderGameplayHeader($pageTitle, $user, 'engage'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>

        <div class="gameplay-grid">
            <section class="gameplay-card">
                <h3>武将阵列</h3>
                <div class="metric"><?php echo number_format(count($generals)); ?></div>
                <p>通过原创角色模板完成契约、BREAK 与技能配置。</p>
                <div class="gameplay-actions">
                    <a href="recruit.php">武将契约</a>
                    <a href="generals.php">管理武将</a>
                </div>
            </section>
            <section class="gameplay-card">
                <h3>军队与挑战</h3>
                <div class="metric"><?php echo number_format(count($armies)); ?></div>
                <p>使用现有军队参与竞技场、讨伐战与战斗之塔。</p>
                <div class="gameplay-actions">
                    <a href="armies.php">军队管理</a>
                    <a href="challenges.php">挑战玩法</a>
                    <a href="battles.php">战斗记录</a>
                    <a href="scouting.php">侦察任务</a>
                    <a href="subbases.php">分基地</a>
                </div>
            </section>
            <section class="gameplay-card">
                <h3>当前赛季</h3>
                <?php if ($seasonOverview['season']): ?>
                    <div class="metric">第 <?php echo number_format((int) $seasonOverview['season']['season_number']); ?> 季</div>
                    <p>状态：<?php echo escapeHtml($seasonOverview['season']['status']); ?></p>
                    <p>十二门通行权：<?php echo $seasonOverview['has_gateway_access'] ? '已取得' : '未取得'; ?></p>
                <?php else: ?>
                    <p>尚未初始化赛季。</p>
                <?php endif; ?>
                <div class="gameplay-actions"><a href="season.php">查看十二门</a></div>
            </section>
            <section class="gameplay-card">
                <h3>可领取任务</h3>
                <div class="metric"><?php echo number_format($completedQuests); ?></div>
                <p>日常、周常、主线任务与长期成就共用事件进度。</p>
                <div class="gameplay-actions"><a href="quests.php">任务与成就</a></div>
            </section>
        </div>

        <section class="gameplay-section">
            <h3>游戏内钱包</h3>
            <div class="gameplay-grid">
                <div><strong>技能点</strong><div class="metric"><?php echo number_format((int) $wallet['skill_points']); ?></div></div>
                <div><strong>功勋</strong><div class="metric"><?php echo number_format((int) $wallet['merit_points']); ?></div></div>
                <div><strong>竞技代币</strong><div class="metric"><?php echo number_format((int) $wallet['arena_tokens']); ?></div></div>
                <div><strong>蜕变核心</strong><div class="metric"><?php echo number_format(isset($items['break_core']) ? $items['break_core'] : 0); ?></div></div>
            </div>
            <p class="muted">这里的全部货币与素材均由玩法产出，不包含充值或付费购买。</p>
            <div class="gameplay-actions">
                <a href="skills.php">技能卡</a>
                <a href="shop.php">资源兑换</a>
                <a href="alliance.php">联盟协作</a>
                <a href="social.php">通讯</a>
            </div>
        </section>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
</body>
</html>

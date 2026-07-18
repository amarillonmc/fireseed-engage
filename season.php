<?php
// 种火集结号 - 十二门与赛季页面 / Fireseed Engage - Twelve Gateways and season page

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

$seasonService = new SeasonService();
$result = null;
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $result = ['success' => false, 'message' => '请求校验失败，请刷新页面后重试'];
    } elseif (isset($_POST['action']) && $_POST['action'] === 'assault') {
        $result = $seasonService->assaultSite(
            $user->getUserId(),
            isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0,
            isset($_POST['army_id']) ? (int) $_POST['army_id'] : 0
        );
    }
}

$resource = new Resource($user->getUserId());
$overview = $seasonService->getOverview($user->getUserId());
$idleArmies = [];
foreach ($overview['armies'] as $army) {
    if ($army->getStatus() === 'idle' && $army->getCombatPower() > 0) {
        $idleArmies[] = $army;
    }
}
$statusLabels = [
    'active' => '进行中',
    'victory_countdown' => '银白之孔占领计时中',
    'won' => '胜者已确定',
    'reset_pending' => '冻结并等待重置'
];
$pageTitle = '十二门与赛季';
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
    <?php renderGameplayHeader($pageTitle, $user, 'season'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>
        <?php renderGameplayNotice($result); ?>

        <section class="gameplay-section">
            <h3>赛季状态</h3>
            <?php if ($overview['season']): ?>
                <p>第 <?php echo number_format((int) $overview['season']['season_number']); ?> 季 /
                    <?php echo escapeHtml(isset($statusLabels[$overview['season']['status']]) ? $statusLabels[$overview['season']['status']] : $overview['season']['status']); ?></p>
                <p>银白之孔需要连续占领 <?php echo number_format(VICTORY_OCCUPATION_DAYS); ?> 天；
                    胜利后冻结 <?php echo number_format(SEASON_RESET_DELAY_HOURS); ?> 小时再重置领地。</p>
                <p>玩家武将、城池、资源与联盟关系在赛季重置后保留。</p>
            <?php else: ?>
                <p>赛季尚未初始化。</p>
            <?php endif; ?>
        </section>

        <section class="gameplay-section">
            <h3>银白之孔与十二门</h3>
            <p>银白之孔通行权：<?php echo $overview['has_gateway_access'] ? '已取得' : '尚未取得（自己或联盟需先占领一座十二门）'; ?></p>
            <div class="gameplay-grid">
                <?php foreach ($overview['sites'] as $site): ?>
                    <?php
                    $maxDurability = max(1, (int) $site['max_durability']);
                    $durabilityPercent = min(100, max(0, (int) floor((int) $site['durability'] / $maxDurability * 100)));
                    ?>
                    <article class="gameplay-card">
                        <h4><?php echo escapeHtml($site['display_name']); ?></h4>
                        <span class="gameplay-badge"><?php echo $site['site_type'] === 'silver_hole' ? '银白之孔' : '十二门'; ?></span>
                        <p>坐标（<?php echo (int) $site['x']; ?>, <?php echo (int) $site['y']; ?>）</p>
                        <p>占领者：<?php echo escapeHtml($site['owner_name'] ?: '数据海驻军'); ?></p>
                        <div class="progress-track"><span style="width: <?php echo $durabilityPercent; ?>%"></span></div>
                        <p>耐久 <?php echo number_format((int) $site['durability']); ?> /
                            <?php echo number_format($maxDurability); ?>；
                            驻军 <?php echo number_format((int) $site['npc_garrison']); ?></p>
                        <?php if ($site['site_type'] === 'silver_hole' && isset($site['occupation_seconds'])): ?>
                            <p>连续占领：
                                <?php echo escapeHtml(formatTime((int) $site['occupation_seconds'])); ?> /
                                <?php echo number_format(VICTORY_OCCUPATION_DAYS); ?> 天</p>
                        <?php endif; ?>
                        <?php if ($site['owner_id'] === null || (int) $site['owner_id'] !== $user->getUserId()): ?>
                            <form method="post" class="gameplay-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="assault">
                                <input type="hidden" name="site_id" value="<?php echo (int) $site['site_id']; ?>">
                                <label>已抵达目标的待命军队
                                    <select name="army_id" required>
                                        <?php foreach ($idleArmies as $army): ?>
                                            <?php $position = $army->getCurrentPosition(); ?>
                                            <option value="<?php echo (int) $army->getArmyId(); ?>">
                                                <?php echo escapeHtml($army->getName()); ?>
                                                （<?php echo (int) $position[0]; ?>, <?php echo (int) $position[1]; ?>；
                                                <?php echo number_format($army->getCombatPower()); ?>）
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="gameplay-button danger" type="submit" <?php echo empty($idleArmies) ? 'disabled' : ''; ?>>发动进攻</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="gameplay-section">
            <h3>赛季势力排行</h3>
            <p>联盟成员与附属玩家的本季贡献会实时汇总到当前有效势力。</p>
            <table class="gameplay-table">
                <thead><tr><th>名次</th><th>势力代表</th><th>贡献者</th><th>领地</th><th>战斗</th><th>十二门</th><th>总分</th></tr></thead>
                <tbody>
                <?php foreach ($overview['ranking'] as $index => $rank): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo escapeHtml($rank['username']); ?></td>
                        <td><?php echo number_format((int) $rank['contributor_count']); ?></td>
                        <td><?php echo number_format((int) $rank['territory_score']); ?></td>
                        <td><?php echo number_format((int) $rank['battle_score']); ?></td>
                        <td><?php echo number_format((int) $rank['gateway_score']); ?></td>
                        <td><?php echo number_format((int) $rank['total_score']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
</body>
</html>

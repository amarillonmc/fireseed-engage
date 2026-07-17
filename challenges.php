<?php
// 种火集结号 - 挑战玩法页面 / Fireseed Engage - Challenge-mode page

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

$challengeService = new ChallengeService();
$result = null;
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $result = ['success' => false, 'message' => '请求校验失败，请刷新页面后重试'];
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $armyId = isset($_POST['army_id']) ? (int) $_POST['army_id'] : 0;
        if ($action === 'set_arena_defense') {
            $result = $challengeService->setArenaDefense($user->getUserId(), $armyId);
        } elseif ($action === 'arena_challenge') {
            $result = $challengeService->challengeArena(
                $user->getUserId(),
                isset($_POST['defender_id']) ? (int) $_POST['defender_id'] : 0,
                $armyId
            );
        } elseif ($action === 'tower_challenge') {
            $result = $challengeService->challengeTower($user->getUserId(), $armyId);
        } elseif ($action === 'raid_attack') {
            $result = $challengeService->attackRaid(
                $user->getUserId(),
                isset($_POST['raid_id']) ? (int) $_POST['raid_id'] : 0,
                $armyId
            );
        } elseif ($action === 'raid_claim') {
            $result = $challengeService->claimRaidReward(
                $user->getUserId(),
                isset($_POST['raid_id']) ? (int) $_POST['raid_id'] : 0
            );
        }
    }
}

$resource = new Resource($user->getUserId());
$dashboard = $challengeService->getDashboard($user->getUserId());
$idleArmies = [];
foreach ($dashboard['armies'] as $army) {
    if ($army->getStatus() === 'idle' && $army->getCombatPower() > 0) {
        $idleArmies[] = $army;
    }
}
$pageTitle = '挑战玩法';
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
    <?php renderGameplayHeader($pageTitle, $user, 'challenges'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>
        <?php renderGameplayNotice($result); ?>

        <section class="gameplay-section">
            <h3>战斗之塔</h3>
            <?php if ($dashboard['tower']): ?>
                <p>当前层：<?php echo number_format((int) $dashboard['tower']['current_floor']); ?> /
                    最高通过：<?php echo number_format((int) $dashboard['tower']['highest_floor']); ?></p>
                <p>敌军战力：<?php echo number_format((int) $dashboard['tower']['enemy_power']); ?> /
                    今日挑战：<?php echo number_format((int) $dashboard['tower']['attempts_today']); ?> / 5</p>
                <p>通关奖励：<?php echo escapeHtml(formatGameplayBundle($dashboard['tower']['reward'])); ?></p>
                <form method="post" class="gameplay-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="tower_challenge">
                    <label>出战军队
                        <select name="army_id" required>
                            <?php foreach ($idleArmies as $army): ?>
                                <option value="<?php echo (int) $army->getArmyId(); ?>"><?php echo escapeHtml($army->getName()); ?>（<?php echo number_format($army->getCombatPower()); ?>）</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="gameplay-button" type="submit" <?php echo empty($idleArmies) ? 'disabled' : ''; ?>>挑战当前层</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="gameplay-section">
            <h3>讨伐战</h3>
            <div class="gameplay-grid">
                <?php foreach ($dashboard['raids'] as $raid): ?>
                    <?php
                    $maxHp = max(1, (int) $raid['max_hp']);
                    $currentHp = max(0, (int) $raid['current_hp']);
                    $userDamage = max(0, (int) $raid['user_damage']);
                    $hpPercent = min(100, max(0, (int) floor($currentHp / $maxHp * 100)));
                    $startsAt = strtotime((string) $raid['starts_at']);
                    $endsAt = strtotime((string) $raid['ends_at']);
                    $now = time();
                    $hasStarted = $startsAt !== false && $startsAt <= $now;
                    $hasExpired = $endsAt !== false && $endsAt <= $now;
                    $isDefeated = (string) $raid['status'] === 'defeated';
                    $minimumContribution = GameRules::getRaidMinimumContribution(
                        $maxHp
                    );
                    $contributionRemaining = max(0, $minimumContribution - $userDamage);
                    $rewardClaimed = !empty($raid['reward_claimed_at']);
                    $canAttack = (string) $raid['status'] === 'active'
                        && $hasStarted
                        && !$hasExpired
                        && $currentHp > 0;
                    $canClaim = ($isDefeated || $hasExpired)
                        && $userDamage >= $minimumContribution
                        && !$rewardClaimed;
                    ?>
                    <article class="gameplay-card">
                        <h4><?php echo escapeHtml($raid['name']); ?></h4>
                        <p><?php echo escapeHtml($raid['description']); ?></p>
                        <div class="progress-track"><span style="width: <?php echo $hpPercent; ?>%"></span></div>
                        <p>HP <?php echo number_format($currentHp); ?> / <?php echo number_format($maxHp); ?></p>
                        <p>
                            个人贡献：<?php echo number_format($userDamage); ?> /
                            领取门槛：<?php echo number_format($minimumContribution); ?>
                        </p>
                        <?php if ($rewardClaimed): ?>
                            <p class="muted">贡献奖励已领取</p>
                        <?php elseif ($canClaim): ?>
                            <form method="post">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="raid_claim">
                                <input type="hidden" name="raid_id" value="<?php echo (int) $raid['raid_id']; ?>">
                                <button class="gameplay-button" type="submit">领取贡献奖励</button>
                            </form>
                        <?php elseif ($canAttack): ?>
                            <form method="post" class="gameplay-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="raid_attack">
                                <input type="hidden" name="raid_id" value="<?php echo (int) $raid['raid_id']; ?>">
                                <label>出战军队
                                    <select name="army_id" required>
                                        <?php foreach ($idleArmies as $army): ?>
                                            <option value="<?php echo (int) $army->getArmyId(); ?>"><?php echo escapeHtml($army->getName()); ?>（<?php echo number_format($army->getCombatPower()); ?>）</option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="gameplay-button" type="submit" <?php echo empty($idleArmies) ? 'disabled' : ''; ?>>发动讨伐</button>
                            </form>
                            <?php if (empty($idleArmies)): ?>
                                <p class="muted">当前没有可出战的空闲军队。</p>
                            <?php elseif ($contributionRemaining > 0): ?>
                                <p class="muted">距离奖励门槛还差 <?php echo number_format($contributionRemaining); ?> 点伤害。</p>
                            <?php else: ?>
                                <p class="muted">已达到奖励门槛，可继续协助击破目标。</p>
                            <?php endif; ?>
                        <?php elseif (!$hasStarted): ?>
                            <p class="muted">讨伐尚未开始。</p>
                        <?php elseif ($isDefeated || $hasExpired): ?>
                            <p class="muted">
                                讨伐已结束，贡献不足，无法领取奖励；还差
                                <?php echo number_format($contributionRemaining); ?> 点伤害。
                            </p>
                        <?php elseif ($currentHp <= 0): ?>
                            <p class="muted">目标已击破，奖励正在结算，请稍后刷新。</p>
                        <?php elseif ((string) $raid['status'] === 'scheduled'): ?>
                            <p class="muted">讨伐目标正在激活，请稍后刷新。</p>
                        <?php else: ?>
                            <p class="muted">讨伐当前不可参与。</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="gameplay-section">
            <h3>竞技场</h3>
            <?php if ($dashboard['arena_profile']): ?>
                <p>评分 <?php echo number_format((int) $dashboard['arena_profile']['rating']); ?> /
                    胜 <?php echo number_format((int) $dashboard['arena_profile']['wins']); ?> /
                    负 <?php echo number_format((int) $dashboard['arena_profile']['losses']); ?></p>
                <p>本季积分 <?php echo number_format((int) $dashboard['arena_profile']['season_points']); ?></p>
                <p>防守军队：<?php echo escapeHtml($dashboard['arena_profile']['defense_army_name'] ?: '未设置'); ?></p>
                <form method="post" class="gameplay-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="set_arena_defense">
                    <label>防守军队
                        <select name="army_id" required>
                            <?php foreach ($idleArmies as $army): ?>
                                <option value="<?php echo (int) $army->getArmyId(); ?>"><?php echo escapeHtml($army->getName()); ?>（<?php echo number_format($army->getCombatPower()); ?>）</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="gameplay-button" type="submit" <?php echo empty($idleArmies) ? 'disabled' : ''; ?>>设置防守</button>
                </form>
            <?php endif; ?>

            <table class="gameplay-table">
                <thead><tr><th>对手</th><th>评分</th><th>防守军队</th><th>挑战</th></tr></thead>
                <tbody>
                <?php foreach ($dashboard['arena_opponents'] as $opponent): ?>
                    <tr>
                        <td><?php echo escapeHtml($opponent['username']); ?></td>
                        <td><?php echo number_format((int) $opponent['rating']); ?></td>
                        <td><?php echo escapeHtml($opponent['defense_army_name']); ?></td>
                        <td>
                            <form method="post" class="gameplay-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="arena_challenge">
                                <input type="hidden" name="defender_id" value="<?php echo (int) $opponent['user_id']; ?>">
                                <select name="army_id" required>
                                    <?php foreach ($idleArmies as $army): ?>
                                        <option value="<?php echo (int) $army->getArmyId(); ?>"><?php echo escapeHtml($army->getName()); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="gameplay-button" type="submit" <?php echo empty($idleArmies) ? 'disabled' : ''; ?>>挑战</button>
                            </form>
                        </td>
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

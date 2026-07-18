<?php
// 种火集结号 - 武将成长详情页面 / Fireseed Engage - General progression detail page

require_once 'includes/init.php';
require_once 'includes/classes/GameRules.php';
require_once 'includes/classes/GeneralProgression.php';
require_once 'includes/gameplay_ui.php';

/**
 * 在事务内升级玩家拥有的武将 / Levels an owned general inside a transaction
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param int $userId 玩家ID / User ID
 * @param int $generalId 武将ID / General ID
 * @return array 操作结果 / Operation result
 */
function upgradeOwnedGeneralForPage($db, $userId, $generalId) {
    $transactionStarted = false;

    try {
        // 先通过标准资源路径结算产出；后续扣费不改写产出时间戳 / Settle production through the canonical resource path first; the later charge leaves its timestamp untouched
        Resource::updateResourceProduction($userId);

        if (!$db->begin_transaction()) {
            throw new RuntimeException('无法开始升级事务 / Unable to start level-up transaction');
        }
        $transactionStarted = true;

        $query = "SELECT g.level, g.rarity, gp.break_level
                  FROM generals g
                  JOIN general_progression gp
                    ON gp.general_id = g.general_id
                  WHERE g.general_id = ?
                    AND g.owner_id = ?
                    AND g.is_active = 1
                  FOR UPDATE";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException('无法锁定武将 / Unable to lock general');
        }
        $stmt->bind_param('ii', $generalId, $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('无法锁定武将 / Unable to lock general: ' . $error);
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new DomainException('武将不存在或不属于玩家 / General does not exist or is not owned');
        }

        $finalLevelCap = GameRules::getBreakLevelCap((string) $row['rarity']);
        $currentLevelCap = min(
            $finalLevelCap,
            20 + max(0, (int) $row['break_level']) * 20
        );
        if ((int) $row['level'] >= $currentLevelCap) {
            throw new DomainException(
                '武将已达到当前等级上限，请先完成BREAK / General reached the current level cap; complete BREAK first'
            );
        }

        $lockedGeneral = new General($generalId);
        if (!$lockedGeneral->isValid()
            || (int) $lockedGeneral->getOwnerId() !== $userId) {
            throw new DomainException('武将所有权验证失败 / General ownership validation failed');
        }

        $upgradeCost = (int) $lockedGeneral->getUpgradeCost();
        $resourceUpdate = "UPDATE resources
                           SET bright_crystal = bright_crystal - ?
                           WHERE user_id = ?
                             AND bright_crystal >= ?";
        $stmt = $db->prepare($resourceUpdate);
        if (!$stmt) {
            throw new RuntimeException('无法扣除亮晶晶 / Unable to consume bright crystals');
        }
        $stmt->bind_param('iii', $upgradeCost, $userId, $upgradeCost);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('无法扣除亮晶晶 / Unable to consume bright crystals: ' . $error);
        }
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if ($affectedRows !== 1) {
            throw new DomainException('亮晶晶不足 / Insufficient bright crystals');
        }

        if (!$lockedGeneral->levelUp()) {
            throw new RuntimeException('武将升级失败 / General level-up failed');
        }

        $eventType = 'general_leveled';
        $referenceType = 'general';
        $eventInsert = "INSERT INTO gameplay_events
                          (user_id, event_type, event_value, reference_type, reference_id)
                        VALUES (?, ?, 1, ?, ?)";
        $stmt = $db->prepare($eventInsert);
        if (!$stmt) {
            throw new RuntimeException('无法记录升级事件 / Unable to record level-up event');
        }
        $stmt->bind_param(
            'issi',
            $userId,
            $eventType,
            $referenceType,
            $generalId
        );
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('无法记录升级事件 / Unable to record level-up event: ' . $error);
        }
        $stmt->close();

        if (!$db->commit()) {
            throw new RuntimeException('无法提交升级事务 / Unable to commit level-up transaction');
        }
        $transactionStarted = false;

        return [
            'success' => true,
            'message' => '武将升级成功 / General level-up successful',
            'cost' => ['bright' => $upgradeCost],
            'level' => (int) $lockedGeneral->getLevel()
        ];
    } catch (DomainException $e) {
        if ($transactionStarted) {
            $db->rollback();
        }

        return ['success' => false, 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $db->rollback();
        }
        error_log('general_detail level-up failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => '武将升级失败，未消耗资源 / General level-up failed and no resources were consumed'
        ];
    }
}

/**
 * 格式化武将技能效果 / Formats general skill effects
 *
 * @param array $effects 技能效果 / Skill effects
 * @return string 可读摘要 / Readable summary
 */
function formatGeneralSkillEffectsForPage(array $effects) {
    if (empty($effects)) {
        return '无';
    }

    $parts = [];
    foreach ($effects as $name => $value) {
        if (is_array($value)) {
            $parts[] = (string) $name . '（'
                . formatGeneralSkillEffectsForPage($value) . '）';
        } elseif (is_bool($value)) {
            $parts[] = (string) $name . '：' . ($value ? '是' : '否');
        } else {
            $parts[] = (string) $name . '：' . (string) $value;
        }
    }

    return implode('、', $parts);
}

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

$userId = (int) $user->getUserId();
$generalId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$general = new General($generalId);

if ($generalId <= 0
    || !$general->isValid()
    || (int) $general->getOwnerId() !== $userId) {
    header('Location: generals.php');
    exit;
}

$progressionService = new GeneralProgression();
$progressionService->ensure($generalId);
$operationResult = null;

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $operationResult = [
            'success' => false,
            'message' => '请求验证失败，请刷新页面后重试 / Request verification failed; refresh and try again'
        ];
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

        if ($action === 'upgrade') {
            $operationResult = upgradeOwnedGeneralForPage(
                Database::getInstance()->getConnection(),
                $userId,
                $generalId
            );
        } elseif ($action === 'break') {
            $operationResult = $progressionService->breakGeneral(
                $userId,
                $generalId
            );
        } elseif ($action === 'recover_hp') {
            $operationResult = $progressionService->recoverAllHp($userId);
        } else {
            $operationResult = [
                'success' => false,
                'message' => '未知操作 / Unknown operation'
            ];
        }
    }

    $general = new General($generalId);
    if (!$general->isValid()
        || (int) $general->getOwnerId() !== $userId) {
        header('Location: generals.php');
        exit;
    }
}

$progressionResult = $progressionService->get($generalId);
$progression = !empty($progressionResult['success'])
    ? $progressionResult['progression']
    : [];
$resource = new Resource($userId);
$breakCoreCount = 0;
$db = Database::getInstance()->getConnection();
$itemCode = 'break_core';
$itemQuery = "SELECT quantity
              FROM user_items
              WHERE user_id = ? AND item_code = ?";
$itemStmt = $db->prepare($itemQuery);
if ($itemStmt) {
    $itemStmt->bind_param('is', $userId, $itemCode);
    if ($itemStmt->execute()) {
        $itemResult = $itemStmt->get_result();
        $itemRow = $itemResult ? $itemResult->fetch_assoc() : null;
        $breakCoreCount = $itemRow ? (int) $itemRow['quantity'] : 0;
    }
    $itemStmt->close();
}

$recoveryModifier = isset($GLOBALS['GENERAL_HP_RECOVERY_MODIFIER'])
    && is_numeric($GLOBALS['GENERAL_HP_RECOVERY_MODIFIER'])
    ? max(0.0, (float) $GLOBALS['GENERAL_HP_RECOVERY_MODIFIER'])
    : 1.0;
$recoveryPerHour = 5.0 * $recoveryModifier;
$pageTitle = '武将详情 - ' . $general->getName();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .detail-panel {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 18px;
            margin-bottom: 16px;
        }
        .detail-heading,
        .button-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }
        .detail-hero {
            display: grid;
            grid-template-columns: minmax(240px, 340px) minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }
        .detail-card-visual {
            width: 100%;
        }
        .rarity {
            display: inline-block;
            border-radius: 3px;
            padding: 2px 7px;
            background: #333;
            color: #fff;
            font-weight: bold;
        }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
            gap: 12px;
        }
        .stat-card,
        .skill-card {
            background: #fafafa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 12px;
        }
        .stat-value {
            display: block;
            margin-top: 4px;
            font-size: 1.35rem;
            font-weight: bold;
        }
        .skill-card {
            margin-bottom: 10px;
        }
        .button-row {
            justify-content: flex-start;
        }
        .button-row form {
            margin: 0;
        }
        button,
        .button-link {
            display: inline-block;
            border: 1px solid #555;
            border-radius: 4px;
            padding: 8px 13px;
            background: #333;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
        }
        button:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }
        .muted {
            color: #666;
        }
        @media (max-width: 760px) {
            .detail-hero {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <?php renderGameplayHeader($pageTitle, $user, 'generals'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>
        <?php renderGameplayNotice($operationResult); ?>
        <?php if (empty($progressionResult['success'])): ?>
            <?php renderGameplayNotice($progressionResult); ?>
        <?php endif; ?>

        <section class="detail-panel">
            <div class="detail-hero">
                <div class="detail-card-visual">
                    <?php echo renderGeneralCardVisual($general); ?>
                </div>
                <div>
                    <div class="detail-heading">
                        <h3>
                            <?php echo escapeHtml($general->getName()); ?>
                            <span class="rarity"><?php echo escapeHtml($general->getRarity()); ?></span>
                        </h3>
                        <a href="generals.php">返回武将列表</a>
                    </div>
                    <p>
                        来源：<?php echo escapeHtml($general->getSource()); ?> /
                        元素：<?php echo escapeHtml($general->getElement()); ?> /
                        COST：<?php echo escapeHtml($general->getCost()); ?>
                    </p>
                    <div class="stat-grid">
                        <div class="stat-card">
                            等级
                            <span class="stat-value">
                                <?php echo number_format((int) $general->getLevel()); ?>
                                /
                                <?php echo isset($progression['current_level_cap'])
                                    ? number_format((int) $progression['current_level_cap'])
                                    : '—'; ?>
                            </span>
                        </div>
                        <div class="stat-card">
                            BREAK
                            <span class="stat-value">
                                <?php echo isset($progression['break_level'])
                                    ? number_format((int) $progression['break_level'])
                                    : '—'; ?>
                            </span>
                        </div>
                        <div class="stat-card">
                            HP
                            <span class="stat-value">
                                <?php echo number_format((int) $general->getHp()); ?>
                                / <?php echo number_format((int) $general->getMaxHp()); ?>
                            </span>
                        </div>
                        <div class="stat-card">
                            攻击
                            <span class="stat-value"><?php echo number_format((int) $general->getAttack()); ?></span>
                        </div>
                        <div class="stat-card">
                            守备
                            <span class="stat-value"><?php echo number_format((int) $general->getDefense()); ?></span>
                        </div>
                        <div class="stat-card">
                            速度
                            <span class="stat-value"><?php echo number_format((int) $general->getSpeed()); ?></span>
                        </div>
                        <div class="stat-card">
                            智力
                            <span class="stat-value"><?php echo number_format((int) $general->getIntelligence()); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="detail-panel">
            <div class="detail-heading">
                <h3>技能</h3>
                <a href="skills.php">管理技能卡库存与槽位</a>
            </div>
            <?php if (empty($general->getSkills())): ?>
                <p>该武将尚未拥有技能。</p>
            <?php else: ?>
                <?php foreach ($general->getSkills() as $skill): ?>
                    <article class="skill-card">
                        <strong><?php echo escapeHtml($skill->getSkillName()); ?></strong>
                        <span>
                            （<?php echo escapeHtml($skill->getSkillType()); ?> /
                            槽位 <?php echo number_format((int) $skill->getSlot()); ?> /
                            Lv.<?php echo number_format((int) $skill->getSkillLevel()); ?>）
                        </span>
                        <?php if ($skill->isCatalogCardDisabled()): ?>
                            <span class="muted">[已停用，不会生效 / Disabled and inactive]</span>
                        <?php endif; ?>
                        <p><?php echo escapeHtml(formatGeneralSkillEffectsForPage($skill->getEffect())); ?></p>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
            <p class="muted">固有技能位于零号槽；一号与二号槽只能通过库存技能卡页面装备，不能手工构造。</p>
        </section>

        <section class="detail-panel">
            <h3>等级成长</h3>
            <?php if (!empty($progression)): ?>
                <p>
                    当前等级上限：
                    <strong><?php echo number_format((int) $progression['current_level_cap']); ?></strong>；
                    最终等级上限：
                    <strong><?php echo number_format((int) $progression['final_level_cap']); ?></strong>
                </p>
                <?php if ((int) $general->getLevel() < (int) $progression['current_level_cap']): ?>
                    <p>
                        升级消耗：亮晶晶 ×
                        <strong><?php echo number_format((int) $general->getUpgradeCost()); ?></strong>
                    </p>
                    <form method="post" action="general_detail.php?id=<?php echo $generalId; ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="upgrade">
                        <button type="submit" <?php echo $resource->getBrightCrystal() < $general->getUpgradeCost() ? 'disabled' : ''; ?>>
                            提升一级
                        </button>
                    </form>
                <?php elseif ((int) $progression['current_level_cap'] < (int) $progression['final_level_cap']): ?>
                    <p>已达到当前等级上限，完成 BREAK 后可继续成长。</p>
                <?php else: ?>
                    <p>该武将已达到最终等级上限。</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="detail-panel">
            <h3>BREAK</h3>
            <p>
                持有蜕变核心：<strong><?php echo number_format($breakCoreCount); ?></strong>
            </p>
            <?php if (!empty($progression['next_break_cost'])): ?>
                <p>
                    下一次 BREAK 消耗：
                    <?php echo escapeHtml(formatGameplayBundle($progression['next_break_cost'])); ?>
                </p>
                <p>BREAK 会将基础战斗属性与最大 HP 提高约 10%，并解锁下一段等级上限。</p>
                <form method="post" action="general_detail.php?id=<?php echo $generalId; ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="break">
                    <button type="submit" <?php echo empty($progression['can_break']) ? 'disabled' : ''; ?>>
                        执行 BREAK
                    </button>
                </form>
            <?php else: ?>
                <p>该武将无需继续 BREAK。</p>
            <?php endif; ?>
        </section>

        <section class="detail-panel">
            <h3>HP 离线回复</h3>
            <p>
                当前回复速度：每小时
                <strong><?php echo escapeHtml(rtrim(rtrim(number_format($recoveryPerHour, 2, '.', ''), '0'), '.')); ?></strong>
                HP。上次结算：
                <strong>
                    <?php echo isset($progression['last_hp_recovery'])
                        ? escapeHtml($progression['last_hp_recovery'])
                        : '—'; ?>
                </strong>
            </p>
            <p class="muted">结算会一次处理你全部武将自上次记录以来的回复，且不会超过各自最大 HP。</p>
            <form method="post" action="general_detail.php?id=<?php echo $generalId; ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="recover_hp">
                <button type="submit">结算全部武将 HP</button>
            </form>
        </section>

        <section class="detail-panel">
            <div class="button-row">
                <a class="button-link" href="assign_general.php?id=<?php echo $generalId; ?>">分配武将</a>
                <a class="button-link" href="skills.php">技能卡管理</a>
                <a class="button-link" href="generals.php">返回武将列表</a>
            </div>
        </section>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
<script src="assets/js/script.js"></script>
</body>
</html>

<?php
// 种火集结号 - 技能卡管理页面 / Fireseed Engage - Skill-card management page

require_once 'includes/init.php';
require_once 'includes/classes/SkillCardService.php';
require_once 'includes/gameplay_ui.php';

/**
 * 格式化技能效果供页面展示 / Formats skill effects for page display
 *
 * @param array $effects 技能效果 / Skill effects
 * @return string 可读摘要 / Readable summary
 */
function formatSkillEffectsForPage(array $effects) {
    if (empty($effects)) {
        return '无';
    }

    $labels = [
        'attack' => '攻击',
        'defense' => '守备',
        'speed' => '速度',
        'intelligence' => '智力',
        'healing' => '治疗',
        'all_resources' => '六系资源',
        'march_speed' => '行军速度',
        'production' => '生产',
        'capture_rate' => '俘虏率'
    ];
    $parts = [];

    foreach ($effects as $key => $value) {
        $label = isset($labels[$key]) ? $labels[$key] : (string) $key;
        if (is_array($value)) {
            $parts[] = $label . '（' . formatSkillEffectsForPage($value) . '）';
        } elseif (is_bool($value)) {
            $parts[] = $label . '：' . ($value ? '是' : '否');
        } else {
            $parts[] = $label . '：' . (string) $value;
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
$skillService = new SkillCardService();
$drawPoolsResult = $skillService->getDrawPools();
$drawPools = !empty($drawPoolsResult['success'])
    && isset($drawPoolsResult['pools'])
    && is_array($drawPoolsResult['pools'])
    ? $drawPoolsResult['pools']
    : [];
$drawPoolWarnings = isset($drawPoolsResult['warnings'])
    && is_array($drawPoolsResult['warnings'])
    ? $drawPoolsResult['warnings']
    : [];
$allowedDrawCountsByPool = [];

// 仅接受当前页面实际列出的池与次数 / Accept only pools and counts listed by this page
foreach ($drawPools as $drawPool) {
    $drawPoolId = isset($drawPool['pool_id']) ? (int) $drawPool['pool_id'] : 0;
    $allowedCounts = isset($drawPool['allowed_counts'])
        && is_array($drawPool['allowed_counts'])
        ? array_values(array_map('intval', $drawPool['allowed_counts']))
        : [];
    if ($drawPoolId > 0 && !empty($allowedCounts)) {
        $allowedDrawCountsByPool[$drawPoolId] = $allowedCounts;
    }
}

$operationResult = null;

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $operationResult = [
            'success' => false,
            'message' => '请求验证失败，请刷新页面后重试 / Request verification failed; refresh and try again'
        ];
    } else {
        $action = isset($_POST['action']) && is_scalar($_POST['action'])
            ? (string) $_POST['action']
            : '';

        if ($action === 'draw') {
            $poolIdInput = isset($_POST['pool_id']) && is_scalar($_POST['pool_id'])
                ? (string) $_POST['pool_id']
                : '';
            $countInput = isset($_POST['draw_count'])
                && is_scalar($_POST['draw_count'])
                ? (string) $_POST['draw_count']
                : '';
            $drawPoolId = filter_var(
                $poolIdInput,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $count = filter_var(
                $countInput,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 100]]
            );

            if ($drawPoolId === false
                || $count === false
                || !isset($allowedDrawCountsByPool[(int) $drawPoolId])
                || !in_array(
                    (int) $count,
                    $allowedDrawCountsByPool[(int) $drawPoolId],
                    true
                )) {
                $operationResult = [
                    'success' => false,
                    'message' => '技能卡抽取参数无效 / Invalid skill-card draw parameters'
                ];
            } else {
                $operationResult = $skillService->draw(
                    $userId,
                    (int) $count,
                    (int) $drawPoolId
                );
            }
        } elseif ($action === 'equip') {
            $operationResult = $skillService->equip(
                $userId,
                isset($_POST['general_id']) ? (int) $_POST['general_id'] : 0,
                isset($_POST['card_id']) ? (int) $_POST['card_id'] : 0,
                isset($_POST['slot']) ? (int) $_POST['slot'] : 0
            );
        } elseif ($action === 'unequip') {
            $operationResult = $skillService->unequip(
                $userId,
                isset($_POST['general_id']) ? (int) $_POST['general_id'] : 0,
                isset($_POST['slot']) ? (int) $_POST['slot'] : 0
            );
        } elseif ($action === 'upgrade') {
            $operationResult = $skillService->upgrade(
                $userId,
                isset($_POST['skill_id']) ? (int) $_POST['skill_id'] : 0
            );
        } elseif ($action === 'activate') {
            $operationResult = $skillService->activate(
                $userId,
                isset($_POST['skill_id']) ? (int) $_POST['skill_id'] : 0
            );
        } else {
            $operationResult = [
                'success' => false,
                'message' => '未知操作 / Unknown operation'
            ];
        }
    }
}

$ownedGenerals = General::getUserGenerals($userId);
$inventoryResult = $skillService->getInventory($userId);
$catalogResult = $skillService->getCatalog();
$inventory = !empty($inventoryResult['success'])
    ? $inventoryResult['cards']
    : [];
$catalog = !empty($catalogResult['success'])
    ? $catalogResult['cards']
    : [];
$db = Database::getInstance()->getConnection();
$skillPoints = 0;
$walletQuery = "SELECT skill_points FROM gameplay_wallets WHERE user_id = ?";
$walletStmt = $db->prepare($walletQuery);
if ($walletStmt) {
    $walletStmt->bind_param('i', $userId);
    if ($walletStmt->execute()) {
        $walletResult = $walletStmt->get_result();
        $walletRow = $walletResult ? $walletResult->fetch_assoc() : null;
        $skillPoints = $walletRow ? (int) $walletRow['skill_points'] : 0;
    }
    $walletStmt->close();
}

$equippedByGeneral = [];
$equippedQuery = "SELECT gs.skill_id, gs.general_id, gs.skill_name, gs.slot,
                         gs.skill_level, gs.skill_effect, esc.card_id,
                         c.name AS catalog_name,
                         c.effect_json AS catalog_effect_json,
                         c.rarity, c.activation_type, c.max_level,
                         c.base_cooldown, c.is_active, cd.ready_at
                  FROM general_skills gs
                  JOIN generals g ON g.general_id = gs.general_id
                  JOIN equipped_skill_cards esc ON esc.skill_id = gs.skill_id
                  JOIN skill_card_catalog c ON c.card_id = esc.card_id
                  LEFT JOIN skill_cooldowns cd
                    ON cd.skill_id = gs.skill_id AND cd.user_id = ?
                  WHERE g.owner_id = ? AND g.is_active = 1
                  ORDER BY gs.general_id, gs.slot, gs.skill_id";
$equippedStmt = $db->prepare($equippedQuery);
if ($equippedStmt) {
    $equippedStmt->bind_param('ii', $userId, $userId);
    if ($equippedStmt->execute()) {
        $equippedResult = $equippedStmt->get_result();
        while ($equippedResult && ($row = $equippedResult->fetch_assoc())) {
            // 已映射技能始终展示目录权威定义；旧快照仅保留兼容用途 / Mapped skills always display the authoritative catalog definition; the legacy snapshot remains for compatibility
            $effects = json_decode(
                (string) $row['catalog_effect_json'],
                true
            );
            $row['effects'] = is_array($effects) ? $effects : [];
            $row['skill_name'] = (string) $row['catalog_name'];
            $generalKey = (int) $row['general_id'];
            if (!isset($equippedByGeneral[$generalKey])) {
                $equippedByGeneral[$generalKey] = [];
            }
            $equippedByGeneral[$generalKey][] = $row;
        }
    }
    $equippedStmt->close();
}

$user = new User($userId);
$resource = new Resource($userId);
$pageTitle = '技能卡管理';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .skill-section {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .skill-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
        }
        .skill-card {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 14px;
            background: #fafafa;
        }
        .rarity {
            display: inline-block;
            border-radius: 3px;
            padding: 2px 7px;
            background: #333;
            color: #fff;
            font-weight: bold;
        }
        .compact-list {
            margin: 8px 0;
            padding-left: 20px;
        }
        .pool-schedule,
        .pool-revision {
            color: #666;
            font-size: 0.92rem;
        }
        .pool-entry-list {
            list-style: none;
            margin: 8px 0 14px;
            padding: 0;
            max-height: 320px;
            overflow-y: auto;
            border-top: 1px solid #e3e3e3;
        }
        .pool-entry-list li {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 7px 0;
            border-bottom: 1px solid #e3e3e3;
        }
        .pool-entry-probability {
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        .featured {
            display: inline-block;
            border-radius: 3px;
            padding: 1px 6px;
            background: #c0392b;
            color: #fff;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .button-row,
        .skill-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .button-row form,
        .skill-actions form {
            margin: 0;
        }
        select,
        button {
            padding: 7px 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th,
        td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
            text-align: left;
            vertical-align: top;
        }
        .muted {
            color: #666;
        }
    </style>
</head>
<body>
<div class="container">
    <?php renderGameplayHeader($pageTitle, $user, 'skills'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>
        <?php renderGameplayNotice($operationResult); ?>
        <?php if (empty($drawPoolsResult['success'])): ?>
            <?php renderGameplayNotice($drawPoolsResult); ?>
        <?php endif; ?>
        <?php foreach ($drawPoolWarnings as $drawPoolWarning): ?>
            <?php if (is_scalar($drawPoolWarning)): ?>
                <div class="message warning">
                    <?php echo escapeHtml((string) $drawPoolWarning); ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($operationResult && !empty($operationResult['cards'])): ?>
            <section class="skill-section">
                <h3>本次抽取</h3>
                <div class="skill-grid">
                    <?php foreach ($operationResult['cards'] as $drawnCard): ?>
                        <article class="skill-card">
                            <h4>
                                <?php echo escapeHtml($drawnCard['name']); ?>
                                <span class="rarity"><?php echo escapeHtml($drawnCard['rarity']); ?></span>
                            </h4>
                            <p><?php echo escapeHtml($drawnCard['description']); ?></p>
                            <p><?php echo escapeHtml(formatSkillEffectsForPage($drawnCard['effect'])); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="skill-section">
            <h3>技能卡抽取</h3>
            <?php if (!empty($drawPoolsResult['success']) && empty($drawPools)): ?>
                <p>当前没有开放的技能卡池，请稍后再来。</p>
            <?php elseif (!empty($drawPools)): ?>
                <div class="skill-grid">
                <?php foreach ($drawPools as $drawPool): ?>
                    <?php
                    $poolId = (int) ($drawPool['pool_id'] ?? 0);
                    $unitCost = isset($drawPool['cost']) && is_array($drawPool['cost'])
                        ? $drawPool['cost']
                        : [];
                    $allowedCounts = isset($drawPool['allowed_counts'])
                        && is_array($drawPool['allowed_counts'])
                        ? $drawPool['allowed_counts']
                        : [];
                    $rarityProbabilities = isset($drawPool['rarity_probabilities'])
                        && is_array($drawPool['rarity_probabilities'])
                        ? $drawPool['rarity_probabilities']
                        : [];
                    $entries = isset($drawPool['entries']) && is_array($drawPool['entries'])
                        ? $drawPool['entries']
                        : [];
                    ?>
                    <article class="skill-card">
                        <h4><?php echo escapeHtml((string) ($drawPool['name'] ?? '技能卡池')); ?></h4>
                        <p><?php echo escapeHtml((string) ($drawPool['description'] ?? '')); ?></p>
                        <p class="pool-schedule">
                            <?php echo escapeHtml(formatGameplayPoolSchedule($drawPool)); ?>
                        </p>
                        <p class="pool-revision">
                            配置版本：r<?php echo number_format((int) ($drawPool['revision'] ?? 1)); ?>
                        </p>
                        <p>
                            <strong>每次消耗：</strong>
                            <?php echo escapeHtml(empty($unitCost) ? '免费' : formatGameplayBundle($unitCost)); ?>
                        </p>
                        <p><strong>稀有度概率：</strong></p>
                        <ul class="compact-list">
                            <?php foreach ($rarityProbabilities as $rarity => $probability): ?>
                                <?php if (is_numeric($probability) && (float) $probability > 0): ?>
                                    <li>
                                        <?php echo escapeHtml((string) $rarity); ?>：
                                        <?php echo escapeHtml(formatGameplayPoolProbability($probability)); ?>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                        <details>
                            <summary>卡池内容与单卡实际概率（<?php echo number_format(count($entries)); ?>）</summary>
                            <ul class="pool-entry-list">
                                <?php foreach ($entries as $entry): ?>
                                    <li>
                                        <span>
                                            <?php echo escapeHtml((string) ($entry['name'] ?? '未知技能卡')); ?>
                                            <span class="rarity">
                                                <?php echo escapeHtml((string) ($entry['rarity'] ?? '')); ?>
                                            </span>
                                            <?php if (!empty($entry['is_featured'])): ?>
                                                <span class="featured">UP</span>
                                            <?php endif; ?>
                                        </span>
                                        <strong class="pool-entry-probability">
                                            <?php echo escapeHtml(formatGameplayPoolProbability($entry['probability'] ?? 0)); ?>
                                        </strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                        <div class="button-row">
                            <?php foreach ($allowedCounts as $count): ?>
                                <?php
                                $normalizedCount = (int) $count;
                                $totalCost = multiplyGameplayPoolCost(
                                    $unitCost,
                                    $normalizedCount
                                );
                                ?>
                                <form method="post" action="skills.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="draw">
                                    <input type="hidden" name="pool_id" value="<?php echo $poolId; ?>">
                                    <input type="hidden" name="draw_count" value="<?php echo $normalizedCount; ?>">
                                    <button type="submit">
                                        抽取 <?php echo number_format($normalizedCount); ?> 次
                                        （<?php echo escapeHtml(empty($totalCost) ? '免费' : formatGameplayBundle($totalCost)); ?>）
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="skill-section">
            <h3>库存技能卡</h3>
            <?php if (empty($inventoryResult['success'])): ?>
                <?php renderGameplayNotice($inventoryResult); ?>
            <?php elseif (empty($inventory)): ?>
                <p>库存为空，可先从当前开放卡池抽取技能卡。</p>
            <?php else: ?>
                <div class="skill-grid">
                    <?php foreach ($inventory as $card): ?>
                        <?php $inventoryCardDisabled = (int) $card['is_active'] !== 1; ?>
                        <article class="skill-card">
                            <h4>
                                <?php echo escapeHtml($card['name']); ?>
                                <span class="rarity"><?php echo escapeHtml($card['rarity']); ?></span>
                                × <?php echo number_format((int) $card['quantity']); ?>
                                <?php if ($inventoryCardDisabled): ?>
                                    <span class="muted">[已停用 / Disabled]</span>
                                <?php endif; ?>
                            </h4>
                            <p><?php echo escapeHtml($card['description']); ?></p>
                            <p>
                                <?php echo escapeHtml($card['activation_type']); ?> /
                                <?php echo escapeHtml($card['element']); ?> /
                                <?php echo escapeHtml(formatSkillEffectsForPage($card['effect'])); ?>
                            </p>
                            <?php if ($inventoryCardDisabled): ?>
                                <p class="muted">该目录卡已停用，库存记录保留但不能新装备。</p>
                            <?php elseif (empty($ownedGenerals)): ?>
                                <p class="muted">你尚未拥有可装备的武将。</p>
                            <?php else: ?>
                                <form method="post" action="skills.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="equip">
                                    <input type="hidden" name="card_id" value="<?php echo (int) $card['card_id']; ?>">
                                    <label>
                                        武将
                                        <select name="general_id" required>
                                            <?php foreach ($ownedGenerals as $ownedGeneral): ?>
                                                <option value="<?php echo (int) $ownedGeneral->getGeneralId(); ?>">
                                                    <?php echo escapeHtml($ownedGeneral->getName()); ?>
                                                    （<?php echo escapeHtml($ownedGeneral->getRarity()); ?>）
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>
                                        槽位
                                        <select name="slot" required>
                                            <option value="1">一号槽</option>
                                            <option value="2">二号槽</option>
                                        </select>
                                    </label>
                                    <button type="submit">装备</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="skill-section">
            <h3>已装备技能</h3>
            <p>可用技能点：<strong><?php echo number_format($skillPoints); ?></strong></p>
            <?php if (empty($ownedGenerals)): ?>
                <p>尚未拥有武将。</p>
            <?php else: ?>
                <?php foreach ($ownedGenerals as $ownedGeneral): ?>
                    <?php
                    $generalId = (int) $ownedGeneral->getGeneralId();
                    $equippedSkills = isset($equippedByGeneral[$generalId])
                        ? $equippedByGeneral[$generalId]
                        : [];
                    ?>
                    <h4>
                        <a href="general_detail.php?id=<?php echo $generalId; ?>">
                            <?php echo escapeHtml($ownedGeneral->getName()); ?>
                        </a>
                    </h4>
                    <?php if (empty($equippedSkills)): ?>
                        <p class="muted">一号槽与二号槽均为空。</p>
                    <?php else: ?>
                        <div class="skill-grid">
                            <?php foreach ($equippedSkills as $skill): ?>
                                <?php
                                $readyAt = isset($skill['ready_at'])
                                    ? (string) $skill['ready_at']
                                    : '';
                                $onCooldown = $readyAt !== ''
                                    && strtotime($readyAt) > time();
                                $skillDisabled = (int) $skill['is_active'] !== 1;
                                ?>
                                <article class="skill-card">
                                    <h5>
                                        槽位 <?php echo (int) $skill['slot']; ?>：
                                        <?php echo escapeHtml($skill['skill_name']); ?>
                                        <span class="rarity"><?php echo escapeHtml($skill['rarity']); ?></span>
                                        <?php if ($skillDisabled): ?>
                                            <span class="muted">[已停用 / Disabled]</span>
                                        <?php endif; ?>
                                    </h5>
                                    <p>
                                        Lv.<?php echo number_format((int) $skill['skill_level']); ?>
                                        / <?php echo number_format((int) $skill['max_level']); ?>；
                                        <?php echo escapeHtml($skill['activation_type']); ?>
                                    </p>
                                    <p><?php echo escapeHtml(formatSkillEffectsForPage($skill['effects'])); ?></p>
                                    <?php if ($readyAt !== ''): ?>
                                        <p>
                                            冷却：
                                            <?php echo $onCooldown ? '至 ' . escapeHtml($readyAt) : '已就绪'; ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="skill-actions">
                                        <form method="post" action="skills.php">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="upgrade">
                                            <input type="hidden" name="skill_id" value="<?php echo (int) $skill['skill_id']; ?>">
                                            <button type="submit" <?php echo $skillDisabled || (int) $skill['skill_level'] >= (int) $skill['max_level'] ? 'disabled' : ''; ?>>
                                                升级（技能点 × <?php echo number_format((int) $skill['skill_level'] * 10); ?>）
                                            </button>
                                        </form>
                                        <?php if ($skill['activation_type'] === 'active'): ?>
                                            <form method="post" action="skills.php">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="skill_id" value="<?php echo (int) $skill['skill_id']; ?>">
                                                <button type="submit" <?php echo $skillDisabled || $onCooldown ? 'disabled' : ''; ?>>发动</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ((int) $skill['slot'] > 0): ?>
                                            <form method="post" action="skills.php">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="unequip">
                                                <input type="hidden" name="general_id" value="<?php echo $generalId; ?>">
                                                <input type="hidden" name="slot" value="<?php echo (int) $skill['slot']; ?>">
                                                <button type="submit">卸下</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="skill-section">
            <h3>技能卡目录</h3>
            <?php if (empty($catalogResult['success'])): ?>
                <?php renderGameplayNotice($catalogResult); ?>
            <?php elseif (empty($catalog)): ?>
                <p>目录暂时为空。</p>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>技能卡</th>
                        <th>类型</th>
                        <th>效果</th>
                        <th>最高等级</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($catalog as $card): ?>
                        <tr>
                            <td>
                                <?php echo escapeHtml($card['name']); ?>
                                <span class="rarity"><?php echo escapeHtml($card['rarity']); ?></span>
                                <br>
                                <span class="muted"><?php echo escapeHtml($card['description']); ?></span>
                            </td>
                            <td>
                                <?php echo escapeHtml($card['activation_type']); ?> /
                                <?php echo escapeHtml($card['category']); ?> /
                                <?php echo escapeHtml($card['element']); ?>
                            </td>
                            <td><?php echo escapeHtml(formatSkillEffectsForPage($card['effect'])); ?></td>
                            <td><?php echo number_format((int) $card['max_level']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
<script src="assets/js/script.js"></script>
</body>
</html>

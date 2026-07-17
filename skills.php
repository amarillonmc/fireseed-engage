<?php
// 种火集结号 - 技能卡管理页面 / Fireseed Engage - Skill-card management page

require_once 'includes/init.php';
require_once 'includes/classes/GameRules.php';
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
$operationResult = null;

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $operationResult = [
            'success' => false,
            'message' => '请求验证失败，请刷新页面后重试 / Request verification failed; refresh and try again'
        ];
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

        if ($action === 'draw') {
            $count = isset($_POST['draw_count']) ? (int) $_POST['draw_count'] : 0;
            if (!in_array($count, [1, 10], true)) {
                $operationResult = [
                    'success' => false,
                    'message' => '技能卡抽取次数无效 / Invalid skill-card draw count'
                ];
            } else {
                $operationResult = $skillService->draw($userId, $count);
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
                         c.rarity, c.activation_type, c.max_level,
                         c.base_cooldown, cd.ready_at
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
            $effects = json_decode((string) $row['skill_effect'], true);
            $row['effects'] = is_array($effects) ? $effects : [];
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
$skillProbabilities = GameRules::getSkillCardProbabilities();
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
            <p>每次消耗夜静静 × 250；十连消耗夜静静 × 2,500。</p>
            <p>
                <?php foreach ($skillProbabilities as $rarity => $chance): ?>
                    <span class="rarity"><?php echo escapeHtml($rarity); ?></span>
                    <?php echo escapeHtml($chance); ?>%
                <?php endforeach; ?>
            </p>
            <div class="button-row">
                <?php foreach ([1, 10] as $count): ?>
                    <form method="post" action="skills.php">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="draw">
                        <input type="hidden" name="draw_count" value="<?php echo $count; ?>">
                        <button type="submit">抽取 <?php echo $count; ?> 次</button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="skill-section">
            <h3>库存技能卡</h3>
            <?php if (empty($inventoryResult['success'])): ?>
                <?php renderGameplayNotice($inventoryResult); ?>
            <?php elseif (empty($inventory)): ?>
                <p>库存为空，可先使用夜静静抽取技能卡。</p>
            <?php else: ?>
                <div class="skill-grid">
                    <?php foreach ($inventory as $card): ?>
                        <article class="skill-card">
                            <h4>
                                <?php echo escapeHtml($card['name']); ?>
                                <span class="rarity"><?php echo escapeHtml($card['rarity']); ?></span>
                                × <?php echo number_format((int) $card['quantity']); ?>
                            </h4>
                            <p><?php echo escapeHtml($card['description']); ?></p>
                            <p>
                                <?php echo escapeHtml($card['activation_type']); ?> /
                                <?php echo escapeHtml($card['element']); ?> /
                                <?php echo escapeHtml(formatSkillEffectsForPage($card['effect'])); ?>
                            </p>
                            <?php if (empty($ownedGenerals)): ?>
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
                                ?>
                                <article class="skill-card">
                                    <h5>
                                        槽位 <?php echo (int) $skill['slot']; ?>：
                                        <?php echo escapeHtml($skill['skill_name']); ?>
                                        <span class="rarity"><?php echo escapeHtml($skill['rarity']); ?></span>
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
                                            <button type="submit" <?php echo (int) $skill['skill_level'] >= (int) $skill['max_level'] ? 'disabled' : ''; ?>>
                                                升级（技能点 × <?php echo number_format((int) $skill['skill_level'] * 10); ?>）
                                            </button>
                                        </form>
                                        <?php if ($skill['activation_type'] === 'active'): ?>
                                            <form method="post" action="skills.php">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="skill_id" value="<?php echo (int) $skill['skill_id']; ?>">
                                                <button type="submit" <?php echo $onCooldown ? 'disabled' : ''; ?>>发动</button>
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

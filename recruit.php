<?php
// 种火集结号 - 武将招募页面 / Fireseed Engage - General recruitment page

require_once 'includes/init.php';
require_once 'includes/classes/RecruitmentService.php';
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

$userId = (int) $user->getUserId();
$recruitmentService = new RecruitmentService();
$templatePoolResult = $recruitmentService->getPool();
$publicTemplates = !empty($templatePoolResult['success'])
    && isset($templatePoolResult['generals'])
    && is_array($templatePoolResult['generals'])
    ? $templatePoolResult['generals']
    : [];
$starterTemplates = [];

foreach ($publicTemplates as $template) {
    if (in_array($template['rarity'], ['S', 'SS', 'P'], true)) {
        $starterTemplates[] = $template;
    }
}

$starterTemplateIds = array_map(function ($template) {
    return (int) $template['general_id'];
}, $starterTemplates);
$drawPoolsResult = $recruitmentService->getDrawPools();
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

        if ($action === 'starter') {
            $templateId = isset($_POST['template_id'])
                ? (int) $_POST['template_id']
                : 0;

            if (!in_array($templateId, $starterTemplateIds, true)) {
                $operationResult = [
                    'success' => false,
                    'message' => '请选择页面列出的高稀有度初始武将 / Select a listed high-rarity starter general'
                ];
            } else {
                $operationResult = $recruitmentService->selectStarter(
                    $userId,
                    $templateId
                );
            }
        } elseif ($action === 'recruit') {
            $poolIdInput = isset($_POST['pool_id']) && is_scalar($_POST['pool_id'])
                ? (string) $_POST['pool_id']
                : '';
            $countInput = isset($_POST['recruit_count'])
                && is_scalar($_POST['recruit_count'])
                ? (string) $_POST['recruit_count']
                : '';
            $drawPoolId = filter_var(
                $poolIdInput,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $recruitCount = filter_var(
                $countInput,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 100]]
            );

            if ($drawPoolId === false
                || $recruitCount === false
                || !isset($allowedDrawCountsByPool[(int) $drawPoolId])
                || !in_array(
                    (int) $recruitCount,
                    $allowedDrawCountsByPool[(int) $drawPoolId],
                    true
                )) {
                $operationResult = [
                    'success' => false,
                    'message' => '招募参数无效 / Invalid recruitment parameters'
                ];
            } else {
                $operationResult = $recruitmentService->recruit(
                    $userId,
                    (int) $drawPoolId,
                    (int) $recruitCount
                );
            }
        } else {
            $operationResult = [
                'success' => false,
                'message' => '未知操作 / Unknown operation'
            ];
        }
    }
}

$starterRemaining = $recruitmentService->starterRemaining($userId);
$user = new User($userId);
$resource = new Resource($userId);
$pageTitle = '武将招募';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .recruit-grid,
        .starter-grid,
        .result-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }
        .recruit-panel,
        .starter-card,
        .result-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 16px;
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
        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .button-row form {
            margin: 0;
        }
        button {
            padding: 8px 14px;
            cursor: pointer;
        }
        button:disabled {
            cursor: not-allowed;
            opacity: 0.55;
        }
        .muted {
            color: #666;
        }
    </style>
</head>
<body>
<div class="container">
    <?php renderGameplayHeader($pageTitle, $user, 'generals'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>
        <?php renderGameplayNotice($operationResult); ?>
        <?php if (empty($templatePoolResult['success'])): ?>
            <?php renderGameplayNotice($templatePoolResult); ?>
        <?php endif; ?>
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

        <?php if ($operationResult && !empty($operationResult['generals'])): ?>
            <section class="recruit-panel">
                <h3>本次获得</h3>
                <div class="result-grid">
                    <?php foreach ($operationResult['generals'] as $recruited): ?>
                        <article class="result-card">
                            <h4>
                                <?php echo escapeHtml($recruited['name']); ?>
                                <span class="rarity"><?php echo escapeHtml($recruited['rarity']); ?></span>
                            </h4>
                            <p>
                                元素：<?php echo escapeHtml($recruited['element']); ?> /
                                COST：<?php echo escapeHtml($recruited['cost']); ?>
                            </p>
                            <p>
                                攻 <?php echo number_format((int) $recruited['attack']); ?> /
                                守 <?php echo number_format((int) $recruited['defense']); ?> /
                                速 <?php echo number_format((int) $recruited['speed']); ?> /
                                智 <?php echo number_format((int) $recruited['intelligence']); ?>
                            </p>
                            <?php if (!empty($recruited['duplicate'])): ?>
                                <p class="muted">
                                    重复契约已转化为技能点 ×
                                    <?php echo number_format((int) $recruited['duplicate_skill_points']); ?>
                                </p>
                            <?php endif; ?>
                            <a href="general_detail.php?id=<?php echo (int) $recruited['general_id']; ?>">查看武将</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="recruit-panel">
            <h3>初始武将选择</h3>
            <p>
                剩余免费选择次数：
                <strong><?php echo number_format($starterRemaining); ?> / 5</strong>
            </p>
            <p class="muted">每个高稀有度模板只能选择一次；选择记录由服务器验证。</p>

            <?php if ($starterRemaining <= 0): ?>
                <p>你已完成五名初始武将的选择。</p>
            <?php elseif (empty($starterTemplates)): ?>
                <p>公共武将池中暂时没有可选的 S、SS 或 P 级模板。</p>
            <?php else: ?>
                <div class="starter-grid">
                    <?php foreach ($starterTemplates as $template): ?>
                        <article class="starter-card">
                            <h4>
                                <?php echo escapeHtml($template['name']); ?>
                                <span class="rarity"><?php echo escapeHtml($template['rarity']); ?></span>
                            </h4>
                            <p>
                                <?php echo escapeHtml($template['source']); ?> /
                                <?php echo escapeHtml($template['element']); ?> /
                                COST <?php echo escapeHtml($template['cost']); ?>
                            </p>
                            <p>
                                攻 <?php echo number_format((int) $template['attack']); ?> /
                                守 <?php echo number_format((int) $template['defense']); ?> /
                                速 <?php echo number_format((int) $template['speed']); ?> /
                                智 <?php echo number_format((int) $template['intelligence']); ?>
                            </p>
                            <form method="post" action="recruit.php">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="starter">
                                <input type="hidden" name="template_id" value="<?php echo (int) $template['general_id']; ?>">
                                <button type="submit">选择此武将</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="recruit-panel">
            <h3>非付费招募</h3>
            <?php if (!empty($drawPoolsResult['success']) && empty($drawPools)): ?>
                <p>当前没有开放的武将卡池，请稍后再来。</p>
            <?php elseif (!empty($drawPools)): ?>
                <div class="recruit-grid">
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
                    <article class="starter-card">
                        <h4><?php echo escapeHtml((string) ($drawPool['name'] ?? '武将卡池')); ?></h4>
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
                                            <?php echo escapeHtml((string) ($entry['name'] ?? '未知武将')); ?>
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
                                <form method="post" action="recruit.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="recruit">
                                    <input type="hidden" name="pool_id" value="<?php echo $poolId; ?>">
                                    <input type="hidden" name="recruit_count" value="<?php echo $normalizedCount; ?>">
                                    <button type="submit">
                                        招募 <?php echo number_format($normalizedCount); ?> 次
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
    </main>
    <?php renderGameplayFooter(); ?>
</div>
<script src="assets/js/script.js"></script>
</body>
</html>

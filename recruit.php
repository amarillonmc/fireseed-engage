<?php
// 种火集结号 - 武将招募页面 / Fireseed Engage - General recruitment page

require_once 'includes/init.php';
require_once 'includes/classes/GameRules.php';
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
$poolResult = $recruitmentService->getPool();
$pool = !empty($poolResult['success']) ? $poolResult['generals'] : [];
$starterTemplates = [];

foreach ($pool as $template) {
    if (in_array($template['rarity'], ['S', 'SS', 'P'], true)) {
        $starterTemplates[] = $template;
    }
}

$starterTemplateIds = array_map(function ($template) {
    return (int) $template['general_id'];
}, $starterTemplates);
$operationResult = null;

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $operationResult = [
            'success' => false,
            'message' => '请求验证失败，请刷新页面后重试 / Request verification failed; refresh and try again'
        ];
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

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
            $recruitType = isset($_POST['recruit_type'])
                ? (string) $_POST['recruit_type']
                : '';
            $recruitCount = isset($_POST['recruit_count'])
                ? (int) $_POST['recruit_count']
                : 0;

            if (!in_array($recruitType, ['normal', 'advanced', 'resonance'], true)
                || !in_array($recruitCount, [1, 10], true)) {
                $operationResult = [
                    'success' => false,
                    'message' => '招募参数无效 / Invalid recruitment parameters'
                ];
            } else {
                $operationResult = $recruitmentService->recruit(
                    $userId,
                    $recruitType,
                    $recruitCount
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
$recruitOptions = [
    'normal' => [
        'name' => '普通招募',
        'description' => '以四系基础晶体寻找常规战力。',
        'unit_cost' => [
            'bright' => 100,
            'warm' => 100,
            'cold' => 100,
            'green' => 100
        ]
    ],
    'advanced' => [
        'name' => '高级招募',
        'description' => '追加昼夜晶体，稳定获得更高稀有度。',
        'unit_cost' => [
            'bright' => 500,
            'warm' => 500,
            'cold' => 500,
            'green' => 500,
            'day' => 100,
            'night' => 100
        ]
    ],
    'resonance' => [
        'name' => '回路共鸣',
        'description' => '消耗游戏内积累的思考回路，不含付费货币。',
        'unit_cost' => ['circuit_points' => 5]
    ]
];
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
        <?php if (empty($poolResult['success'])): ?>
            <?php renderGameplayNotice($poolResult); ?>
        <?php endif; ?>

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
            <div class="recruit-grid">
                <?php foreach ($recruitOptions as $type => $option): ?>
                    <?php
                    $probabilities = GameRules::getGeneralRecruitmentProbabilities($type);
                    ?>
                    <article class="starter-card">
                        <h4><?php echo escapeHtml($option['name']); ?></h4>
                        <p><?php echo escapeHtml($option['description']); ?></p>
                        <p><strong>每次消耗：</strong><?php echo escapeHtml(formatGameplayBundle($option['unit_cost'])); ?></p>
                        <p><strong>公开概率：</strong></p>
                        <ul class="compact-list">
                            <?php foreach ($probabilities as $rarity => $chance): ?>
                                <?php if ((float) $chance > 0): ?>
                                    <li><?php echo escapeHtml($rarity); ?>：<?php echo escapeHtml($chance); ?>%</li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                        <div class="button-row">
                            <?php foreach ([1, 10] as $count): ?>
                                <form method="post" action="recruit.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="recruit">
                                    <input type="hidden" name="recruit_type" value="<?php echo escapeHtml($type); ?>">
                                    <input type="hidden" name="recruit_count" value="<?php echo $count; ?>">
                                    <button type="submit">
                                        招募 <?php echo $count; ?> 次
                                        （<?php echo escapeHtml(formatGameplayBundle(array_map(function ($amount) use ($count) {
                                            return (int) $amount * $count;
                                        }, $option['unit_cost']))); ?>）
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
<script src="assets/js/script.js"></script>
</body>
</html>

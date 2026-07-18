<?php
// 种火集结号 - 附属、救出与主动脱离页面 / Fireseed Engage - Vassalage, rescue, and voluntary release page

require_once 'includes/init.php';
require_once 'includes/gameplay_ui.php';

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

$vassalService = new VassalService();
$result = null;
if (isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $result = [
            'success' => false,
            'message' => '请求校验失败，请刷新页面后重试 / Request validation failed'
        ];
    } elseif (!isset($_POST['action'])
        || $_POST['action'] !== 'redeem') {
        $result = [
            'success' => false,
            'message' => '操作无效 / Invalid action'
        ];
    } elseif (!isset($_POST['confirm_release'])
        || (string) $_POST['confirm_release'] !== '1') {
        $result = [
            'success' => false,
            'message' => '请先确认贡金与迁城后果 / Confirm the tribute and relocation consequences first'
        ];
    } else {
        $result = $vassalService->redeem($user->getUserId());
    }
}

$overview = $vassalService->getOverview($user->getUserId());
$resource = new Resource($user->getUserId());
$relation = isset($overview['relation']) ? $overview['relation'] : null;
$settings = isset($overview['settings'])
    ? $overview['settings']
    : $vassalService->getReleaseSettings();
$modeLabels = [
    'outer' => '地图外围随机空地 / Random outer-map tile',
    'middle' => '地图中围随机空地 / Random middle-ring tile',
    'subbase' => '随机既有分基地（没有分基地时退回外围） / Random existing sub-base, falling back to the outer map'
];
$pageTitle = '附属与救出';
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
    <?php renderGameplayHeader($pageTitle, $user, 'vassal'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>
        <?php renderGameplayNotice($result); ?>

        <?php if (!$relation): ?>
            <section class="gameplay-section">
                <h3>当前状态：独立</h3>
                <p>你的主城目前没有处于附属关系，领地与赛季积分按自己的有效势力计算。</p>
                <p>
                    主城被敌对势力彻底攻陷后，城市仍由你经营，但会立刻进入附属状态；
                    原势力成员攻陷你的主城可以完成“救出”。
                </p>
            </section>
        <?php else: ?>
            <section class="gameplay-section">
                <h3>当前状态：附属</h3>
                <div class="gameplay-grid">
                    <article class="gameplay-card">
                        <h4>宗主关系</h4>
                        <p>直接宗主：<?php echo escapeHtml($relation['lord_name']); ?></p>
                        <p>积分归属势力：<?php echo escapeHtml($relation['overlord_name']); ?></p>
                        <p>主城失守时间：<?php echo escapeHtml($relation['captured_at']); ?></p>
                    </article>
                    <article class="gameplay-card">
                        <h4>原势力与救出</h4>
                        <?php if ($relation['previous_alliance_id'] !== null): ?>
                            <p>
                                原联盟：
                                <?php echo escapeHtml(
                                    ($relation['previous_alliance_tag']
                                        ? '[' . $relation['previous_alliance_tag'] . '] '
                                        : '')
                                    . ($relation['previous_alliance_name'] ?: '已解散联盟')
                                ); ?>
                            </p>
                            <p>首次失守时已在该原势力中的成员再次攻陷你的主城后，你会立即获救并恢复原联盟身份；之后的入盟或离盟不会改写这份救出名单。</p>
                        <?php else: ?>
                            <p>失守前没有联盟；当前没有可执行救出的原联盟成员。</p>
                        <?php endif; ?>
                    </article>
                </div>
                <p>
                    附属期间，你仍拥有并经营自己的城市与领地；但这些领地以及全部赛季排行榜贡献，
                    会实时汇总到宗主的有效势力。
                </p>
            </section>

            <section class="gameplay-section">
                <h3>缴纳贡金并主动脱离</h3>
                <p>
                    当前后台比例：
                    <?php echo escapeHtml(
                        rtrim(
                            rtrim(
                                number_format(
                                    (float) $settings['resource_rate'] * 100,
                                    2,
                                    '.',
                                    ''
                                ),
                                '0'
                            ),
                            '.'
                        )
                    ); ?>%。
                    提交时会以事务内锁定的最新余额重新计算，并对每一系资源向下取整。
                </p>

                <table class="gameplay-table">
                    <thead>
                        <tr><th>资源</th><th>当前预估贡金</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $resourceLabels = [
                            'bright' => '亮晶晶',
                            'warm' => '暖洋洋',
                            'cold' => '冷冰冰',
                            'green' => '郁萌萌',
                            'day' => '昼闪闪',
                            'night' => '夜静静'
                        ];
                        foreach ($resourceLabels as $type => $label):
                        ?>
                            <tr>
                                <td><?php echo escapeHtml($label); ?></td>
                                <td><?php echo number_format((int) $overview['tribute'][$type]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="message warning">
                    <p>
                        迁城目的地：
                        <?php echo escapeHtml($modeLabels[$settings['relocation_mode']]); ?>
                    </p>
                    <p>
                        全部领地清算：
                        <?php echo $settings['lose_all_territory']
                            ? '开启；普通领地与未保留分基地会失去'
                            : '关闭；现有领地与其他分基地保留'; ?>
                    </p>
                    <p>
                        思考回路返还：
                        <?php echo $settings['refund_circuit']
                            ? '开启；已投入普通领地的点数全额返还，可暂时超过持有上限'
                            : '关闭；清算时不返还'; ?>
                    </p>
                    <p>
                        当前普通领地 <?php echo number_format((int) $overview['ordinary_territory_count']); ?> 块，
                        分基地 <?php echo number_format((int) $overview['sub_base_count']); ?> 座。
                    </p>
                </div>

                <form method="post" class="gameplay-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="redeem">
                    <label>
                        <input type="checkbox" name="confirm_release" value="1" required>
                        我确认缴纳六系贡金，并接受当前后台设定的迁城与领地清算结果。
                    </label>
                    <button
                        class="gameplay-button danger"
                        type="submit"
                        <?php echo isSeasonGameplayFrozen() ? 'disabled' : ''; ?>
                    >缴纳贡金并脱离</button>
                </form>
            </section>
        <?php endif; ?>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
</body>
</html>

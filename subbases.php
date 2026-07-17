<?php
// 种火集结号 - 分基地管理 / Fireseed Engage - Sub-base management

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

$resource = new Resource($user->getUserId());
$subBaseService = new SubBaseService();
$overview = $subBaseService->getOverview($user->getUserId());
$pageTitle = '分基地管理';
$resourceNames = [
    'bright' => '亮晶晶',
    'warm' => '暖洋洋',
    'cold' => '冷冰冰',
    'green' => '郁萌萌',
    'day' => '昼闪闪',
    'night' => '夜静静'
];
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
    <?php renderGameplayHeader($pageTitle, $user, 'subbases'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>
        <div id="subbase-notice" aria-live="polite"></div>

        <?php if (empty($overview['success'])): ?>
            <div class="message error"><?php echo escapeHtml($overview['message']); ?></div>
        <?php else: ?>
            <section class="gameplay-section">
                <h3>改建规则</h3>
                <div class="gameplay-grid">
                    <div class="gameplay-card">
                        <h4>当前数量</h4>
                        <div class="metric">
                            <?php echo number_format((int) $overview['current_count']); ?>
                            /
                            <?php echo number_format((int) $overview['limit']); ?>
                        </div>
                        <p>
                            玩家等级 <?php echo number_format((int) $overview['level']); ?>；
                            尚余 <?php echo number_format((int) $overview['available_slots']); ?> 个名额。
                        </p>
                    </div>
                    <div class="gameplay-card">
                        <h4>兼容上限</h4>
                        <p>本项目按玩家等级线性开放分基地，每级一个且最低一个。</p>
                    </div>
                    <div class="gameplay-card">
                        <h4>改建条件</h4>
                        <p>资源点必须归你所有、没有领地驻军，且坐标上尚无城池。</p>
                    </div>
                    <div class="gameplay-card">
                        <h4>改建结果</h4>
                        <p>
                            建成非主城、总督府及原资源系产出设施，并返还至多
                            <?php echo number_format((int) TERRITORY_OCCUPATION_COST); ?>
                            点思考回路（不超过上限）。
                        </p>
                    </div>
                </div>
            </section>

            <section class="gameplay-section">
                <h3>已有分基地</h3>
                <?php if (empty($overview['sub_bases'])): ?>
                    <p class="muted">尚未建立分基地。</p>
                <?php else: ?>
                    <table class="gameplay-table">
                        <thead>
                        <tr>
                            <th>名称</th>
                            <th>坐标</th>
                            <th>等级</th>
                            <th>耐久</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($overview['sub_bases'] as $subBase): ?>
                            <tr>
                                <td><?php echo escapeHtml($subBase['name']); ?></td>
                                <td>
                                    (<?php echo number_format((int) $subBase['x']); ?>,
                                    <?php echo number_format((int) $subBase['y']); ?>)
                                </td>
                                <td><?php echo number_format((int) $subBase['level']); ?></td>
                                <td>
                                    <?php echo number_format((int) $subBase['durability']); ?>
                                    /
                                    <?php echo number_format((int) $subBase['max_durability']); ?>
                                </td>
                                <td>
                                    <div class="gameplay-actions">
                                        <a href="internal.php?city_id=<?php echo (int) $subBase['city_id']; ?>">内政</a>
                                        <a href="map.php?x=<?php echo (int) $subBase['x']; ?>&amp;y=<?php echo (int) $subBase['y']; ?>">地图</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="gameplay-section">
                <h3>可改建资源点</h3>
                <?php if (empty($overview['candidates'])): ?>
                    <p class="muted">当前没有归你所有的资源点，请先在地图占领资源领地。</p>
                    <div class="gameplay-actions"><a href="map.php">前往地图</a></div>
                <?php else: ?>
                    <table class="gameplay-table">
                        <thead>
                        <tr>
                            <th>坐标</th>
                            <th>资源系</th>
                            <th>剩余资源</th>
                            <th>资格与原因</th>
                            <th>名称与操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($overview['candidates'] as $candidate): ?>
                            <?php
                            $resourceType = $candidate['resource_type'];
                            $resourceLabel = isset($resourceNames[$resourceType])
                                ? $resourceNames[$resourceType]
                                : $resourceType;
                            $defaultName = $resourceLabel . '分基地（'
                                . (int) $candidate['x'] . ','
                                . (int) $candidate['y'] . '）';
                            $disabled = !$candidate['can_create']
                                || isSeasonGameplayFrozen();
                            ?>
                            <tr>
                                <td>
                                    <a href="map.php?x=<?php echo (int) $candidate['x']; ?>&amp;y=<?php echo (int) $candidate['y']; ?>">
                                        (<?php echo number_format((int) $candidate['x']); ?>,
                                        <?php echo number_format((int) $candidate['y']); ?>)
                                    </a>
                                </td>
                                <td><?php echo escapeHtml($resourceLabel); ?></td>
                                <td><?php echo number_format((int) $candidate['resource_amount']); ?></td>
                                <td><?php echo escapeHtml($candidate['reason']); ?></td>
                                <td>
                                    <form class="subbase-create-form" action="api/create_subbase.php" method="post">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="tile_id" value="<?php echo (int) $candidate['tile_id']; ?>">
                                        <label>
                                            <span class="muted">分基地名称</span>
                                            <input
                                                type="text"
                                                name="name"
                                                maxlength="50"
                                                value="<?php echo escapeHtml($defaultName); ?>"
                                                required
                                                <?php echo $disabled ? 'disabled' : ''; ?>
                                            >
                                        </label>
                                        <div class="gameplay-actions">
                                            <button type="submit" <?php echo $disabled ? 'disabled' : ''; ?>>
                                                改建分基地
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const notice = document.getElementById('subbase-notice');
    document.querySelectorAll('.subbase-create-form').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form)
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(result) {
                // 使用textContent显示服务端消息，避免将名称或错误解释为HTML / Use textContent so names and errors are never interpreted as HTML
                notice.className = 'message ' + (result.success ? 'success' : 'error');
                notice.textContent = result.message || '操作失败 / Operation failed';
                if (result.success) {
                    window.location.reload();
                    return;
                }
                button.disabled = false;
            })
            .catch(function() {
                notice.className = 'message error';
                notice.textContent = '网络请求失败，请重试 / Network request failed; please retry';
                button.disabled = false;
            });
        });
    });
});
</script>
</body>
</html>

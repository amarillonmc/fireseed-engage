<?php
// 种火集结号 - 侦察任务中心 / Fireseed Engage - Scouting mission center

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
$scoutingService = new ScoutingService();
$eligibleArmies = $scoutingService->getEligibleArmies($user->getUserId());
$discoveredTargets = $scoutingService->getDiscoveredTargets(
    $user->getUserId()
);
$missions = $scoutingService->getUserMissions($user->getUserId());
$pageTitle = '侦察任务';

/**
 * 获取侦察任务状态文本 / Get scouting mission status text
 *
 * @param string $status 状态 / Status
 * @return string 状态文本 / Status text
 */
function getScoutingStatusLabel($status) {
    $labels = [
        'launched' => '行军中',
        'succeeded' => '侦察成功',
        'failed' => '侦察失败'
    ];
    return isset($labels[$status]) ? $labels[$status] : '未知状态';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .scouting-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            align-items: end;
        }
        .scouting-form-grid label {
            display: block;
            margin-bottom: 4px;
            font-weight: bold;
        }
        .scouting-form-grid select,
        .scouting-form-grid input {
            box-sizing: border-box;
            width: 100%;
            padding: 8px;
        }
        .scouting-form-grid button {
            padding: 9px 16px;
        }
        .scouting-mission {
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-bottom: 12px;
            padding: 14px;
        }
        .scouting-report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .scouting-report-grid > div {
            background: #f7f7f7;
            border-radius: 4px;
            padding: 10px;
        }
        .scouting-report-grid h5 {
            margin: 0 0 8px;
        }
        .scouting-report-grid ul {
            margin: 0;
            padding-left: 20px;
        }
        .scouting-status {
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container">
    <?php renderGameplayHeader($pageTitle, $user, 'scouting'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>

        <section class="gameplay-section">
            <h3>发起侦察</h3>
            <p>
                只能派遣全部由侦察兵组成且兵力大于零的待命军队。
                目标必须是地图上已经发现的敌方城池或NPC据点。
            </p>
            <div id="scouting-notice" class="message" hidden></div>
            <?php if (empty($eligibleArmies)): ?>
                <p class="muted">当前没有可执行任务的纯侦察待命军队。</p>
            <?php else: ?>
                <form id="scouting-form" class="scouting-form-grid">
                    <?php echo csrfField(); ?>
                    <div>
                        <label for="scouting-army">侦察军队</label>
                        <select id="scouting-army" name="army_id" required>
                            <?php foreach ($eligibleArmies as $army): ?>
                                <option value="<?php echo (int) $army['army_id']; ?>">
                                    <?php echo escapeHtml($army['name']); ?>
                                    · <?php echo number_format((int) $army['scout_count']); ?> 名
                                    · (<?php echo (int) $army['current_x']; ?>,
                                    <?php echo (int) $army['current_y']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="known-target">已发现目标（快捷填入）</label>
                        <select id="known-target">
                            <option value="">手动输入坐标</option>
                            <?php foreach ($discoveredTargets as $target): ?>
                                <option
                                    value="<?php echo (int) $target['tile_id']; ?>"
                                    data-x="<?php echo (int) $target['x']; ?>"
                                    data-y="<?php echo (int) $target['y']; ?>"
                                >
                                    <?php echo escapeHtml($target['name']); ?>
                                    (<?php echo (int) $target['x']; ?>,
                                    <?php echo (int) $target['y']; ?>)
                                    <?php if ($target['level'] !== null): ?>
                                        · Lv.<?php echo (int) $target['level']; ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="target-x">目标X坐标</label>
                        <input
                            id="target-x"
                            name="target_x"
                            type="number"
                            min="0"
                            max="<?php echo MAP_WIDTH - 1; ?>"
                            required
                        >
                    </div>
                    <div>
                        <label for="target-y">目标Y坐标</label>
                        <input
                            id="target-y"
                            name="target_y"
                            type="number"
                            min="0"
                            max="<?php echo MAP_HEIGHT - 1; ?>"
                            required
                        >
                    </div>
                    <div>
                        <button type="submit">派出侦察兵</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="gameplay-section">
            <h3>任务与报告</h3>
            <p class="muted">
                只有你能查看自己的侦察报告。失败任务不会显示目标情报，
                防守方也不会在普通来袭列表中看到侦察任务。
            </p>
            <?php if (empty($missions)): ?>
                <p>尚无侦察任务。</p>
            <?php endif; ?>

            <?php foreach ($missions as $mission): ?>
                <article class="scouting-mission">
                    <h4>
                        #<?php echo number_format((int) $mission['mission_id']); ?>
                        <?php echo escapeHtml($mission['army_name']); ?>
                        → <?php echo escapeHtml($mission['target_name']); ?>
                    </h4>
                    <p>
                        目标：
                        <?php if ($mission['target_x'] !== null): ?>
                            (<?php echo (int) $mission['target_x']; ?>,
                            <?php echo (int) $mission['target_y']; ?>)
                        <?php else: ?>
                            已失效
                        <?php endif; ?>
                        · 状态：
                        <span class="scouting-status">
                            <?php echo escapeHtml(
                                getScoutingStatusLabel($mission['status'])
                            ); ?>
                        </span>
                        <?php if ($mission['status'] === 'launched'): ?>
                            · 抵达倒计时：
                            <span
                                class="scouting-countdown"
                                data-arrival="<?php echo (int) strtotime(
                                    $mission['arrival_at']
                                ); ?>"
                            >计算中</span>
                        <?php endif; ?>
                    </p>

                    <?php if ($mission['status'] === 'failed'): ?>
                        <div class="message error">
                            侦察失败：派出兵力未能严格超过目标反侦察兵力，
                            或目标/军队在抵达前已经失效。
                        </div>
                    <?php elseif ($mission['status'] === 'succeeded'
                        && is_array($mission['report'])): ?>
                        <?php $report = $mission['report']; ?>
                        <div class="message success">
                            侦察成功：
                            <?php echo escapeHtml(
                                isset($report['city_name'])
                                    ? $report['city_name']
                                    : $mission['target_name']
                            ); ?>
                            <?php if (isset($report['scouts_sent'], $report['counter_scouts'])): ?>
                                （我方 <?php echo number_format(
                                    (int) $report['scouts_sent']
                                ); ?>
                                / 反侦察 <?php echo number_format(
                                    (int) $report['counter_scouts']
                                ); ?>）
                            <?php endif; ?>
                        </div>
                        <div class="scouting-report-grid">
                            <div>
                                <h5>城池状态</h5>
                                <p>
                                    等级：
                                    <?php echo number_format(
                                        (int) ($report['city_level'] ?? 1)
                                    ); ?>
                                </p>
                                <p>
                                    耐久：
                                    <?php if (($report['durability'] ?? null) === null): ?>
                                        未设独立耐久
                                    <?php else: ?>
                                        <?php echo number_format(
                                            (int) $report['durability']
                                        ); ?>
                                        /
                                        <?php echo number_format(
                                            (int) ($report['max_durability'] ?? 0)
                                        ); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <h5>设施等级</h5>
                                <?php if (empty($report['facilities'])): ?>
                                    <p>无可见设施。</p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach ($report['facilities'] as $facility): ?>
                                            <li>
                                                <?php echo escapeHtml(
                                                    $facility['name'] ?? '未知设施'
                                                ); ?>
                                                Lv.<?php echo number_format(
                                                    (int) ($facility['level'] ?? 1)
                                                ); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h5>士兵汇总</h5>
                                <?php if (empty($report['soldiers'])): ?>
                                    <p>无驻守士兵。</p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach ($report['soldiers'] as $soldier): ?>
                                            <li>
                                                <?php echo escapeHtml(
                                                    $soldier['name'] ?? '未知士兵'
                                                ); ?>
                                                Lv.<?php echo number_format(
                                                    (int) ($soldier['level'] ?? 1)
                                                ); ?>
                                                × <?php echo number_format(
                                                    (int) ($soldier['quantity'] ?? 0)
                                                ); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h5>驻城武将</h5>
                                <?php if (empty($report['generals'])): ?>
                                    <p>无驻城武将。</p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach ($report['generals'] as $general): ?>
                                            <li>
                                                <?php echo escapeHtml(
                                                    $general['name'] ?? '未知武将'
                                                ); ?>
                                                [<?php echo escapeHtml(
                                                    $general['rarity'] ?? '?'
                                                ); ?>]
                                                Lv.<?php echo number_format(
                                                    (int) ($general['level'] ?? 1)
                                                ); ?>
                                                · HP <?php echo number_format(
                                                    (int) ($general['hp'] ?? 0)
                                                ); ?>
                                                / <?php echo number_format(
                                                    (int) ($general['max_hp'] ?? 0)
                                                ); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
    <?php renderGameplayFooter(); ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const knownTarget = document.getElementById('known-target');
    const targetX = document.getElementById('target-x');
    const targetY = document.getElementById('target-y');
    const form = document.getElementById('scouting-form');
    const notice = document.getElementById('scouting-notice');

    if (knownTarget) {
        knownTarget.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (!option || option.value === '') {
                return;
            }
            targetX.value = option.dataset.x;
            targetY.value = option.dataset.y;
        });
    }

    if (form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;

            fetch('api/launch_scouting.php', {
                method: 'POST',
                body: new FormData(form)
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                notice.hidden = false;
                notice.className = data.success
                    ? 'message success'
                    : 'message error';
                notice.textContent = data.message || '侦察请求处理完成';
                if (data.success) {
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 600);
                }
            })
            .catch(function() {
                notice.hidden = false;
                notice.className = 'message error';
                notice.textContent = '网络请求失败，请稍后重试';
            })
            .then(function() {
                button.disabled = false;
            });
        });
    }

    // 倒计时只读取服务器生成的时间戳并写入纯文本 / Countdowns only read server timestamps and write plain text
    function updateCountdowns() {
        const now = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.scouting-countdown').forEach(function(node) {
            const arrival = Number(node.dataset.arrival);
            const remaining = Math.max(0, arrival - now);
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;
            node.textContent = remaining <= 0
                ? '等待定时结算'
                : hours + '时 ' + minutes + '分 ' + seconds + '秒';
        });
    }

    updateCountdowns();
    window.setInterval(updateCountdowns, 1000);
});
</script>
</body>
</html>

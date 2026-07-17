<?php
// 种火集结号 - 领地与驻军管理 / Fireseed Engage - territory and garrison management

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

$garrisonService = new TerritoryGarrisonService();
$territories = $garrisonService->getUserTerritories($user->getUserId());
$cities = City::getUserCities($user->getUserId());
$resource = new Resource($user->getUserId());
$pageTitle = '领地管理';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo escapeHtml(getCsrfToken()); ?>">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .territory-toolbar,
        .territory-actions,
        .garrison-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .territory-card {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 16px;
            background: #fff;
        }
        .territory-card h4 {
            margin: 0 0 10px;
        }
        .territory-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
            margin-bottom: 12px;
        }
        .garrison-panel {
            margin-top: 12px;
            padding: 12px;
            border-radius: 5px;
            background: #f5f5f5;
        }
        .garrison-list,
        .army-at-tile-list {
            margin: 8px 0;
            padding-left: 22px;
        }
        .withdraw-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin: 10px 0;
        }
        .withdraw-grid label {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .withdraw-grid input,
        .withdraw-grid select {
            box-sizing: border-box;
            width: 100%;
            padding: 7px;
        }
        .blocked-reason {
            color: #8a4b00;
        }
        button.danger {
            background: #a40000;
            color: #fff;
        }
    </style>
</head>
<body>
<div class="container">
    <?php renderGameplayHeader($pageTitle, $user); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>

        <section class="gameplay-section">
            <div class="territory-toolbar">
                <button type="button" id="collect-all-btn">收集所有资源点</button>
                <button type="button" id="refresh-btn">刷新</button>
                <a href="armies.php">军队运输管理</a>
                <a href="map.php">返回地图</a>
            </div>
            <p>
                部署驻军前，请先通过军队移动把整支待命军队运到领地坐标。
                驻军撤回后会组成一支以所选城池为归属的军队，并自动返城。
            </p>
        </section>

        <section class="gameplay-section">
            <h3>普通领地与驻军</h3>
            <?php if (empty($territories)): ?>
                <div class="message info">尚未占领空地或资源点。</div>
            <?php else: ?>
                <?php foreach ($territories as $territory): ?>
                    <?php
                    $withdrawableByType = [];
                    foreach ($territory['garrison_units'] as $unit) {
                        $soldierType = $unit['soldier_type'];
                        $withdrawableByType[$soldierType] =
                            (isset($withdrawableByType[$soldierType])
                                ? $withdrawableByType[$soldierType]
                                : 0)
                            + (int) $unit['quantity'];
                    }
                    ?>
                    <article
                        class="territory-card"
                        id="territory-<?php echo (int) $territory['tile_id']; ?>"
                        data-tile-id="<?php echo (int) $territory['tile_id']; ?>"
                    >
                        <h4><?php echo escapeHtml($territory['name']); ?></h4>
                        <div class="territory-meta">
                            <span>
                                坐标：
                                (<?php echo number_format((int) $territory['x']); ?>,
                                <?php echo number_format((int) $territory['y']); ?>)
                            </span>
                            <span>
                                类型：
                                <?php echo $territory['type'] === 'resource'
                                    ? escapeHtml(getResourceName($territory['subtype']) . '资源点')
                                    : '空地'; ?>
                            </span>
                            <span>
                                驻军总量：
                                <?php echo number_format((int) $territory['garrison_total']); ?>
                            </span>
                            <?php if ($territory['type'] === 'resource'): ?>
                                <span>
                                    剩余资源：
                                    <?php echo number_format((int) $territory['resource_amount']); ?>
                                </span>
                                <span>
                                    收集效率：
                                    <?php echo number_format((int) $territory['collection_efficiency']); ?>/小时
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="territory-actions">
                            <button
                                type="button"
                                class="view-on-map"
                                data-x="<?php echo (int) $territory['x']; ?>"
                                data-y="<?php echo (int) $territory['y']; ?>"
                            >查看地图</button>
                            <?php if ($territory['type'] === 'resource'): ?>
                                <button
                                    type="button"
                                    class="collect-resource"
                                    data-tile-id="<?php echo (int) $territory['tile_id']; ?>"
                                >收集资源</button>
                            <?php endif; ?>
                            <?php if ((int) $territory['garrison_total'] === 0): ?>
                                <button
                                    type="button"
                                    class="abandon-territory danger"
                                    data-x="<?php echo (int) $territory['x']; ?>"
                                    data-y="<?php echo (int) $territory['y']; ?>"
                                >放弃领地</button>
                            <?php else: ?>
                                <span class="blocked-reason">有驻军时不能放弃领地</span>
                            <?php endif; ?>
                        </div>

                        <div class="garrison-panel">
                            <h5>当前驻军编成</h5>
                            <?php if (empty($territory['garrison_units'])): ?>
                                <p>无驻军。</p>
                            <?php else: ?>
                                <ul class="garrison-list">
                                    <?php foreach ($territory['garrison_units'] as $unit): ?>
                                        <li>
                                            <?php echo escapeHtml(getSoldierName($unit['soldier_type'])); ?>
                                            Lv.<?php echo number_format((int) $unit['level']); ?>：
                                            <?php echo number_format((int) $unit['quantity']); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <h5>同坐标待命军队</h5>
                            <?php if (empty($territory['idle_armies'])): ?>
                                <p>
                                    没有待命军队。可在
                                    <a href="armies.php">军队页面</a>
                                    把军队移动到该坐标。
                                </p>
                            <?php else: ?>
                                <ul class="army-at-tile-list">
                                    <?php foreach ($territory['idle_armies'] as $army): ?>
                                        <li>
                                            <?php echo escapeHtml($army['name']); ?>
                                            （<?php echo number_format((int) $army['unit_count']); ?>人）
                                            <?php if ($army['deployable']): ?>
                                                <button
                                                    type="button"
                                                    class="deploy-garrison"
                                                    data-tile-id="<?php echo (int) $territory['tile_id']; ?>"
                                                    data-army-id="<?php echo (int) $army['army_id']; ?>"
                                                >整军部署</button>
                                            <?php else: ?>
                                                <span class="blocked-reason">
                                                    不可部署：
                                                    <?php echo escapeHtml($army['blocked_reason']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($withdrawableByType)): ?>
                            <div class="garrison-panel withdraw-form">
                                <h5>撤回部分驻军</h5>
                                <?php if (empty($cities)): ?>
                                    <div class="message error">没有可作为返程目标的己方城池。</div>
                                <?php else: ?>
                                    <div class="withdraw-grid">
                                        <label>
                                            新军队名称
                                            <input
                                                type="text"
                                                class="withdraw-name"
                                                maxlength="50"
                                                value="<?php
                                                    echo escapeHtml(
                                                        '驻军撤回 '
                                                        . $territory['x']
                                                        . ','
                                                        . $territory['y']
                                                    );
                                                ?>"
                                            >
                                        </label>
                                        <label>
                                            返程城池
                                            <select class="withdraw-city">
                                                <?php foreach ($cities as $city): ?>
                                                    <option value="<?php echo (int) $city->getCityId(); ?>">
                                                        <?php echo escapeHtml($city->getName()); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <?php foreach ($withdrawableByType as $soldierType => $quantity): ?>
                                            <label>
                                                <?php echo escapeHtml(getSoldierName($soldierType)); ?>
                                                （最多<?php echo number_format((int) $quantity); ?>）
                                                <input
                                                    type="number"
                                                    class="withdraw-quantity"
                                                    min="0"
                                                    max="<?php echo (int) $quantity; ?>"
                                                    value="0"
                                                    data-soldier-type="<?php echo escapeHtml($soldierType); ?>"
                                                >
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="garrison-actions">
                                        <button
                                            type="button"
                                            class="withdraw-garrison"
                                            data-tile-id="<?php echo (int) $territory['tile_id']; ?>"
                                        >组成军队并返城</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
    <?php renderGameplayFooter(); ?>
</div>

<script src="assets/js/script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const collectAllButton = document.getElementById('collect-all-btn');
    const refreshButton = document.getElementById('refresh-btn');

    collectAllButton.addEventListener('click', function() {
        submitForm('api/collect_resources.php', {})
            .then(handleJsonResponse)
            .then(data => {
                showNotification(data.message || (
                    data.success
                        ? `收集完成，共获得${Number(data.total_collected) || 0}单位资源`
                        : '收集失败'
                ));
                if (data.success) {
                    window.setTimeout(() => window.location.reload(), 800);
                }
            })
            .catch(handleRequestError);
    });

    refreshButton.addEventListener('click', function() {
        window.location.reload();
    });

    document.querySelectorAll('.view-on-map').forEach(function(button) {
        button.addEventListener('click', function() {
            const x = Number(this.getAttribute('data-x'));
            const y = Number(this.getAttribute('data-y'));
            if (Number.isInteger(x) && Number.isInteger(y)) {
                window.location.href = `map.php?x=${x}&y=${y}`;
            }
        });
    });

    document.querySelectorAll('.collect-resource').forEach(function(button) {
        button.addEventListener('click', function() {
            const tileId = Number(this.getAttribute('data-tile-id'));
            submitForm('api/collect_resources.php', {tile_id: tileId})
                .then(handleJsonResponse)
                .then(data => {
                    showNotification(data.message || (
                        data.success ? '资源收集成功' : '资源收集失败'
                    ));
                    if (data.success) {
                        window.setTimeout(() => window.location.reload(), 800);
                    }
                })
                .catch(handleRequestError);
        });
    });

    document.querySelectorAll('.abandon-territory').forEach(function(button) {
        button.addEventListener('click', function() {
            if (!window.confirm('确定放弃该领地并返还占领成本吗？')) {
                return;
            }
            const x = Number(this.getAttribute('data-x'));
            const y = Number(this.getAttribute('data-y'));
            submitForm('api/abandon_tile.php', {x: x, y: y})
                .then(handleJsonResponse)
                .then(data => {
                    showNotification(data.message);
                    if (data.success) {
                        window.setTimeout(() => window.location.reload(), 800);
                    }
                })
                .catch(handleRequestError);
        });
    });

    document.querySelectorAll('.deploy-garrison').forEach(function(button) {
        button.addEventListener('click', function() {
            if (!window.confirm('部署会解散运输军队，并把全部士兵转为该领地驻军。确定继续吗？')) {
                return;
            }
            const activeButton = this;
            activeButton.disabled = true;
            submitForm('api/deploy_garrison.php', {
                tile_id: Number(activeButton.getAttribute('data-tile-id')),
                army_id: Number(activeButton.getAttribute('data-army-id'))
            })
                .then(handleJsonResponse)
                .then(data => {
                    showNotification(data.message);
                    if (data.success) {
                        window.setTimeout(() => window.location.reload(), 800);
                    } else {
                        activeButton.disabled = false;
                    }
                })
                .catch(error => {
                    activeButton.disabled = false;
                    handleRequestError(error);
                });
        });
    });

    document.querySelectorAll('.withdraw-garrison').forEach(function(button) {
        button.addEventListener('click', function() {
            const form = this.closest('.withdraw-form');
            const tileId = Number(this.getAttribute('data-tile-id'));
            const cityId = Number(form.querySelector('.withdraw-city').value);
            const name = form.querySelector('.withdraw-name').value.trim();
            const units = [];
            let invalid = false;

            form.querySelectorAll('.withdraw-quantity').forEach(function(input) {
                const quantity = Number(input.value);
                const maximum = Number(input.max);
                if (!Number.isInteger(quantity) || quantity < 0 || quantity > maximum) {
                    invalid = true;
                    return;
                }
                if (quantity > 0) {
                    units.push({
                        soldier_type: input.getAttribute('data-soldier-type'),
                        quantity: quantity
                    });
                }
            });

            if (invalid || units.length === 0 || name === '' || !Number.isInteger(cityId)) {
                showNotification('请选择合法的正整数撤回数量、军队名称与返程城池');
                return;
            }

            const activeButton = this;
            activeButton.disabled = true;
            submitForm('api/withdraw_garrison.php', {
                tile_id: tileId,
                city_id: cityId,
                name: name,
                units: JSON.stringify(units)
            })
                .then(handleJsonResponse)
                .then(data => {
                    showNotification(data.message);
                    if (data.success) {
                        window.setTimeout(() => {
                            window.location.href = 'armies.php';
                        }, 800);
                    } else {
                        activeButton.disabled = false;
                    }
                })
                .catch(error => {
                    activeButton.disabled = false;
                    handleRequestError(error);
                });
        });
    });

    // 统一提交带CSRF令牌的状态变更 / Submit every state mutation with a CSRF token
    function submitForm(url, values) {
        const body = new FormData();
        body.append('csrf_token', csrfToken);
        Object.keys(values).forEach(key => body.append(key, values[key]));
        return fetch(url, {
            method: 'POST',
            body: body
        });
    }

    // 在解析JSON前保留HTTP错误语义 / Preserve HTTP error semantics before parsing JSON
    function handleJsonResponse(response) {
        return response.json();
    }

    // 统一处理网络错误 / Handle network failures consistently
    function handleRequestError(error) {
        console.error('Territory request failed:', error);
        showNotification('领地操作请求失败');
    }
});
</script>
</body>
</html>

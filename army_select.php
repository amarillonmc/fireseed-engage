<?php
// 种火集结号 - 地图攻击军队选择 / Fireseed Engage - map attack army selection

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

$targetX = isset($_GET['target_x']) ? (int) $_GET['target_x'] : -1;
$targetY = isset($_GET['target_y']) ? (int) $_GET['target_y'] : -1;
$targetTile = new Map();
$targetValid = $targetX >= 0 && $targetX < MAP_WIDTH
    && $targetY >= 0 && $targetY < MAP_HEIGHT
    && $targetTile->loadByCoordinates($targetX, $targetY)
    && $targetTile->isVisible();
$isWorldSite = false;
$canAttack = false;
$targetMessage = '';
$targetTags = [];

if ($targetValid) {
    $query = "SELECT site_id FROM world_sites WHERE tile_id = ? LIMIT 1";
    $stmt = $db->prepare($query);
    $targetTileId = (int) $targetTile->getTileId();
    $stmt->bind_param('i', $targetTileId);
    $stmt->execute();
    $result = $stmt->get_result();
    $isWorldSite = $result && $result->num_rows > 0;
    $stmt->close();

    if ($isWorldSite || $targetTile->getType() === 'special') {
        $targetMessage = '该地点属于赛季目标，请在赛季页面完成行军与进攻。';
    } elseif ($targetTile->getType() === 'npc_fort') {
        $canAttack = true;
        $targetTags = ['tile', 'npc', 'structure'];
    } elseif ($targetTile->getType() === 'player_city'
        || (in_array($targetTile->getType(), ['empty', 'resource'], true)
            && $targetTile->getOwnerId() !== null)) {
        $targetTags = $targetTile->getType() === 'player_city'
            ? ['city', 'structure', 'player']
            : ['tile', 'player'];
        $ownerId = (int) $targetTile->getOwnerId();
        $allianceService = new AllianceService();
        $canAttack = $allianceService->canUsersFight($user->getUserId(), $ownerId);
        if (!$canAttack) {
            $targetMessage = '不能攻击自己或同势力成员控制的地点。';
        }
    } else {
        $targetMessage = '该地点没有可攻击目标；无主普通领地可直接占领。';
    }
} else {
    $targetMessage = '目标坐标无效或不存在。';
}

if ($canAttack
    && !Map::isAdjacentToUserControl(
        $user->getUserId(),
        $targetX,
        $targetY
    )) {
    $canAttack = false;
    $targetMessage = '只能攻击与己方普通领地或城池曼哈顿相邻的目标。';
}

$eligibleArmies = [];
if ($canAttack) {
    foreach (Army::getUserArmies($user->getUserId()) as $army) {
        if ($army->getStatus() !== 'idle') {
            continue;
        }

        $armyPosition = $army->getCurrentPosition();
        $distance = abs($targetX - (int) $armyPosition[0])
            + abs($targetY - (int) $armyPosition[1]);
        // 使用与出发快照相同的上下文计算预览 / Use the departure-snapshot context for previews
        $battleContext = [
            'phase' => 'battle',
            'side' => 'attack',
            'target_tags' => $targetTags,
            'distance' => $distance
        ];
        $marchContext = [
            'phase' => 'march',
            'distance' => $distance
        ];
        $combatPower = $army->getCombatPower($battleContext);
        $movementSpeed = $army->getMovementSpeed($marchContext);
        if ($combatPower > 0 && $movementSpeed > 0) {
            $eligibleArmies[] = [
                'army' => $army,
                'position' => $armyPosition,
                'distance' => $distance,
                'combat_power' => $combatPower,
                'movement_speed' => $movementSpeed,
                'travel_seconds' => (int) ceil(
                    $distance / $movementSpeed * 3600
                )
            ];
        }
    }
}

$resource = new Resource($user->getUserId());
$pageTitle = '选择出征军队';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo escapeHtml(getCsrfToken()); ?>">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container">
    <?php renderGameplayHeader($pageTitle, $user); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>

        <section class="gameplay-section">
            <h3>目标地点</h3>
            <?php if ($targetValid): ?>
                <p>
                    <?php echo escapeHtml($targetTile->getName()); ?>
                    （<?php echo number_format($targetX); ?>, <?php echo number_format($targetY); ?>）
                </p>
            <?php endif; ?>
            <?php if ($targetMessage !== ''): ?>
                <div class="message <?php echo $isWorldSite ? 'info' : 'error'; ?>">
                    <?php echo escapeHtml($targetMessage); ?>
                </div>
            <?php endif; ?>
            <div class="gameplay-actions">
                <a href="map.php?x=<?php echo max(0, $targetX); ?>&y=<?php echo max(0, $targetY); ?>">返回地图</a>
                <?php if ($isWorldSite): ?><a href="season.php">前往赛季战</a><?php endif; ?>
            </div>
        </section>

        <?php if ($canAttack): ?>
        <section class="gameplay-section">
            <h3>待命军队</h3>
            <?php if (empty($eligibleArmies)): ?>
                <div class="message info">没有可出征的待命军队。</div>
            <?php else: ?>
                <div class="gameplay-grid">
                    <?php foreach ($eligibleArmies as $armyPreview): ?>
                    <?php $army = $armyPreview['army']; ?>
                    <article class="gameplay-card">
                        <h4><?php echo escapeHtml($army->getName()); ?></h4>
                        <p>对该目标战斗力：<?php echo number_format($armyPreview['combat_power']); ?></p>
                        <p>
                            当前位置：（<?php echo number_format($armyPreview['position'][0]); ?>,
                            <?php echo number_format($armyPreview['position'][1]); ?>）
                        </p>
                        <p>距离：<?php echo number_format($armyPreview['distance']); ?> 格</p>
                        <p>行军速度：<?php echo number_format($armyPreview['movement_speed'], 2); ?> 格/小时</p>
                        <p>预计抵达：<?php echo formatTime($armyPreview['travel_seconds']); ?></p>
                        <button type="button" class="attack-button" data-army-id="<?php echo $army->getArmyId(); ?>">
                            出征
                        </button>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
<script src="assets/js/script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    document.querySelectorAll('.attack-button').forEach(function(button) {
        button.addEventListener('click', function() {
            const body = new FormData();
            body.append('csrf_token', csrfToken);
            body.append('army_id', this.getAttribute('data-army-id'));
            body.append('target_x', <?php echo (int) $targetX; ?>);
            body.append('target_y', <?php echo (int) $targetY; ?>);
            this.disabled = true;

            fetch('api/attack_target.php', {method: 'POST', body: body})
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message);
                        setTimeout(() => {
                            window.location.href = 'armies.php';
                        }, 1000);
                    } else if (data.redirect) {
                        showNotification(data.message);
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1000);
                    } else {
                        button.disabled = false;
                        showNotification(data.message);
                    }
                })
                .catch(error => {
                    button.disabled = false;
                    console.error('Error dispatching attack:', error);
                    showNotification('出征请求失败');
                });
        });
    });
});
</script>
</body>
</html>

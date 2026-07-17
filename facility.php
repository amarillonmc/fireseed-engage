<?php
// 种火集结号 - 设施详情与升级页面 / Fireseed Engage - facility details and upgrade page

require_once 'includes/init.php';

// 登录校验 / Authentication check
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

/**
 * 在当前事务中扣除设施升级资源 / Deduct facility upgrade resources in the current transaction
 * @param mysqli $db 数据库连接 / Database connection
 * @param int $userId 用户ID / User ID
 * @param array $cost 升级费用 / Upgrade costs
 * @return string|null 失败消息，成功为null / Failure message, or null on success
 */
function deductFacilityUpgradeResources($db, $userId, $cost) {
    $query = "SELECT bright_crystal, warm_crystal, cold_crystal,
                     green_crystal, day_crystal, night_crystal
              FROM resources
              WHERE user_id = ?
              FOR UPDATE";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return '玩家资源记录不存在';
    }

    $columns = [
        'bright' => 'bright_crystal',
        'warm' => 'warm_crystal',
        'cold' => 'cold_crystal',
        'green' => 'green_crystal',
        'day' => 'day_crystal',
        'night' => 'night_crystal'
    ];
    foreach ($cost as $type => $amount) {
        if (!isset($columns[$type]) || (int) $row[$columns[$type]] < (int) $amount) {
            return getResourceName($type) . '不足';
        }
    }

    $bright = isset($cost['bright']) ? (int) $cost['bright'] : 0;
    $warm = isset($cost['warm']) ? (int) $cost['warm'] : 0;
    $cold = isset($cost['cold']) ? (int) $cost['cold'] : 0;
    $green = isset($cost['green']) ? (int) $cost['green'] : 0;
    $day = isset($cost['day']) ? (int) $cost['day'] : 0;
    $night = isset($cost['night']) ? (int) $cost['night'] : 0;
    $now = date('Y-m-d H:i:s');

    $query = "UPDATE resources
              SET bright_crystal = bright_crystal - ?,
                  warm_crystal = warm_crystal - ?,
                  cold_crystal = cold_crystal - ?,
                  green_crystal = green_crystal - ?,
                  day_crystal = day_crystal - ?,
                  night_crystal = night_crystal - ?,
                  last_update = ?
              WHERE user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param('iiiiiisi', $bright, $warm, $cold, $green, $day, $night, $now, $userId);
    $updated = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();

    return $updated ? null : '扣除升级资源失败';
}

$facilityId = isset($_POST['facility_id'])
    ? (int) $_POST['facility_id']
    : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
$facility = $facilityId > 0 ? new Facility($facilityId) : null;
$city = $facility && $facility->isValid() ? new City($facility->getCityId()) : null;

if (!$facility || !$facility->isValid() || !$city || !$city->isValid()
    || (int) $city->getOwnerId() !== (int) $user->getUserId()) {
    http_response_code(404);
    $facility = null;
}

$message = '';
$messageType = 'info';

if ($facility && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $message = '请求校验失败，请刷新页面后重试。';
        $messageType = 'error';
    } elseif (isSeasonGameplayFrozen()) {
        $message = getSeasonGameplayFreezeMessage();
        $messageType = 'error';
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

        if ($action === 'complete_construction') {
            if ($facility->completeConstruction()) {
                $message = '设施建造已完成。';
                $messageType = 'success';
            } else {
                $message = '设施尚未到达完成时间，或已经完成。';
                $messageType = 'error';
            }
        } elseif ($action === 'complete_upgrade') {
            if ($facility->completeUpgrade()) {
                $message = '设施升级已完成。';
                $messageType = 'success';
            } else {
                $message = '升级尚未到达完成时间，或已经完成。';
                $messageType = 'error';
            }
        } elseif ($action === 'upgrade') {
            $db = Database::getInstance()->getConnection();
            $db->begin_transaction();

            try {
                lockSeasonForWorldAction($db);

                // 锁定设施及其城池所有权 / Lock the facility and its city ownership
                $query = "SELECT f.facility_id, c.owner_id
                          FROM facilities f
                          INNER JOIN cities c ON c.city_id = f.city_id
                          WHERE f.facility_id = ?
                          FOR UPDATE";
                $stmt = $db->prepare($query);
                $stmt->bind_param('i', $facilityId);
                $stmt->execute();
                $result = $stmt->get_result();
                $lockedRow = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if (!$lockedRow || (int) $lockedRow['owner_id'] !== (int) $user->getUserId()) {
                    throw new DomainException('设施不存在或不属于当前用户');
                }

                $lockedFacility = new Facility($facilityId);
                if (!$lockedFacility->isValid()
                    || $lockedFacility->getConstructionTime() !== null
                    || $lockedFacility->getUpgradeTime() !== null) {
                    throw new DomainException('设施正在建造或升级中');
                }
                if ($lockedFacility->getLevel() >= 10) {
                    throw new DomainException('设施已经达到最高等级');
                }

                $upgradeCost = $lockedFacility->getUpgradeCost();
                $deductionError = deductFacilityUpgradeResources(
                    $db,
                    $user->getUserId(),
                    $upgradeCost
                );
                if ($deductionError !== null) {
                    throw new DomainException($deductionError);
                }

                if (!$lockedFacility->upgrade()) {
                    throw new RuntimeException('设置设施升级时间失败 / Failed to schedule facility upgrade');
                }

                $db->commit();
                $message = '设施已开始升级。';
                $messageType = 'success';
            } catch (Throwable $e) {
                $db->rollback();
                $message = $e instanceof DomainException
                    ? $e->getMessage()
                    : '升级请求处理失败，请稍后重试。';
                $messageType = 'error';
                error_log('Facility upgrade failed: ' . $e->getMessage());
            }
        } else {
            $message = '操作无效。';
            $messageType = 'error';
        }

        // 操作后重新载入实体状态 / Reload entity state after a mutation
        $facility = new Facility($facilityId);
        $city = $facility->isValid() ? new City($facility->getCityId()) : null;
    }
}

if (!$facility) {
    $pageTitle = '设施不存在';
} else {
    $pageTitle = $city->getName() . ' - ' . $facility->getName();
}

$resourceTypes = ['bright', 'warm', 'cold', 'green', 'day', 'night'];
$resource = new Resource($user->getUserId());
$trainingTypes = [];
if ($facility) {
    if ($facility->getType() === 'barracks') {
        $trainingTypes = ['pawn', 'knight', 'rook', 'bishop'];
    } elseif ($facility->getType() === 'workshop') {
        $trainingTypes = ['golem'];
    } elseif ($facility->getType() === 'watchtower') {
        $trainingTypes = ['scout'];
    }
}
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
        <!-- 页首与导航 / Header and navigation -->
        <header>
            <h1 class="site-title"><?php echo escapeHtml(SITE_NAME); ?></h1>
            <h2 class="page-title"><?php echo escapeHtml($pageTitle); ?></h2>
            <nav class="main-nav">
                <ul>
                    <li><a href="index.php">主基地</a></li>
                    <li><a href="profile.php">档案</a></li>
                    <li><a href="generals.php">武将</a></li>
                    <li><a href="map.php">地图</a></li>
                    <li><a href="internal.php<?php echo $city ? '?city_id=' . $city->getCityId() : ''; ?>">内政</a></li>
                    <li><a href="ranking.php">排名</a></li>
                    <li class="circuit-points">思考回路: <?php echo $user->getCircuitPoints(); ?> / <?php echo $user->getMaxCircuitPoints(); ?></li>
                </ul>
            </nav>
        </header>

        <main>
            <!-- 资源栏 / Resource bar -->
            <div class="resource-bar">
                <?php foreach ($resourceTypes as $resourceType): ?>
                    <div class="resource <?php echo escapeHtml($resourceType); ?>-crystal">
                        <span class="resource-name"><?php echo escapeHtml(getResourceName($resourceType)); ?></span>
                        <span class="resource-value"><?php echo number_format($resource->getResourceByType($resourceType)); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$facility): ?>
                <div class="message error"><p>设施不存在，或您无权查看该设施。</p></div>
            <?php else: ?>
                <?php if ($message !== ''): ?>
                    <div class="message <?php echo escapeHtml($messageType); ?>">
                        <p><?php echo escapeHtml($message); ?></p>
                    </div>
                <?php endif; ?>

                <div class="city-view">
                    <h3><?php echo escapeHtml($facility->getName()); ?> Lv.<?php echo $facility->getLevel(); ?></h3>
                    <p><?php echo escapeHtml($facility->getDescription()); ?></p>
                    <p>位置: (<?php echo $facility->getXPos(); ?>, <?php echo $facility->getYPos(); ?>)</p>
                    <p>当前效果值: <?php echo number_format($facility->getEffectValue(), 2); ?></p>

                    <?php if ($facility->getConstructionTime() !== null): ?>
                        <div class="message info">
                            <p>建造完成时间: <?php echo escapeHtml($facility->getConstructionTime()); ?></p>
                        </div>
                        <?php if (strtotime($facility->getConstructionTime()) <= time()): ?>
                            <form method="post" action="facility.php?id=<?php echo $facilityId; ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="facility_id" value="<?php echo $facilityId; ?>">
                                <input type="hidden" name="action" value="complete_construction">
                                <button type="submit" class="train-button">确认建造完成</button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($facility->getUpgradeTime() !== null): ?>
                        <div class="message info">
                            <p>升级完成时间: <?php echo escapeHtml($facility->getUpgradeTime()); ?></p>
                        </div>
                        <?php if (strtotime($facility->getUpgradeTime()) <= time()): ?>
                            <form method="post" action="facility.php?id=<?php echo $facilityId; ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="facility_id" value="<?php echo $facilityId; ?>">
                                <input type="hidden" name="action" value="complete_upgrade">
                                <button type="submit" class="train-button">确认升级完成</button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($facility->getLevel() < 10): ?>
                        <h3>升级到 Lv.<?php echo $facility->getLevel() + 1; ?></h3>
                        <p>
                            <?php foreach ($facility->getUpgradeCost() as $type => $amount): ?>
                                <?php if ($amount > 0): ?>
                                    <?php echo escapeHtml(getResourceName($type)); ?> <?php echo number_format($amount); ?>&nbsp;
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </p>
                        <form method="post" action="facility.php?id=<?php echo $facilityId; ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="facility_id" value="<?php echo $facilityId; ?>">
                            <input type="hidden" name="action" value="upgrade">
                            <button type="submit" class="train-button">开始升级</button>
                        </form>
                    <?php else: ?>
                        <div class="message success"><p>该设施已经达到最高等级。</p></div>
                    <?php endif; ?>

                    <?php if ($facility->getType() === 'dormitory'): ?>
                        <p>本宿舍容量: <?php echo number_format($facility->getSoldierStorageCapacity()); ?></p>
                        <p>全城士兵容量: <?php echo number_format(Facility::getCityTotalSoldierCapacity($city->getCityId())); ?></p>
                    <?php elseif ($facility->getType() === 'storage'): ?>
                        <p>本贮存所容量: <?php echo number_format($facility->getResourceStorageCapacity()); ?></p>
                    <?php elseif ($facility->getType() === 'governor_office'): ?>
                        <p>全城士兵容量: <?php echo number_format(Facility::getCityTotalSoldierCapacity($city->getCityId())); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($trainingTypes)
                        && $facility->getConstructionTime() === null
                        && $facility->getUpgradeTime() === null): ?>
                        <h3>训练士兵</h3>
                        <div id="training-message"></div>
                        <table class="barracks-table">
                            <thead>
                                <tr>
                                    <th>兵种</th>
                                    <th>单个费用</th>
                                    <th>数量</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trainingTypes as $soldierType): ?>
                                    <tr>
                                        <td><?php echo escapeHtml(getSoldierName($soldierType)); ?></td>
                                        <td>
                                            <?php foreach (Soldier::getTrainingCost($soldierType) as $type => $amount): ?>
                                                <?php echo escapeHtml(getResourceName($type)); ?> <?php echo number_format($amount); ?>&nbsp;
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <input
                                                form="training-<?php echo escapeHtml($soldierType); ?>"
                                                type="number"
                                                name="quantity"
                                                value="1"
                                                min="1"
                                                max="10000"
                                                required
                                            >
                                        </td>
                                        <td>
                                            <form
                                                id="training-<?php echo escapeHtml($soldierType); ?>"
                                                class="facility-training-form"
                                                method="post"
                                                action="api/train_soldiers.php"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml(getCsrfToken()); ?>">
                                                <input type="hidden" name="city_id" value="<?php echo $city->getCityId(); ?>">
                                                <input type="hidden" name="soldier_type" value="<?php echo escapeHtml($soldierType); ?>">
                                                <button type="submit" class="train-button">训练</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <p>
                        <a href="index.php">返回主基地</a>
                        <?php if ($facility->getType() === 'barracks'): ?>
                            · <a href="barracks.php?city_id=<?php echo $city->getCityId(); ?>">查看驻军</a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </main>

        <footer>
            <p>&copy; <?php echo date('Y'); ?> <?php echo escapeHtml(SITE_NAME); ?> - 版本 <?php echo escapeHtml(GAME_VERSION); ?></p>
        </footer>
    </div>

    <?php if (!empty($trainingTypes)): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 使用同页CSRF令牌提交训练并显示结果 / Submit training with the page CSRF token and display the result
            document.querySelectorAll('.facility-training-form').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    const messageBox = document.getElementById('training-message');
                    fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin'
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        messageBox.className = data.success ? 'message success' : 'message error';
                        messageBox.textContent = data.message;
                    })
                    .catch(function() {
                        messageBox.className = 'message error';
                        messageBox.textContent = '训练请求失败，请稍后重试。';
                    });
                });
            });
        });
        </script>
    <?php endif; ?>
</body>
</html>

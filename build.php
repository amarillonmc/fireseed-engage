<?php
// 种火集结号 - 设施建造页面 / Fireseed Engage - facility construction page

require_once 'includes/init.php';
require_once 'includes/gameplay_ui.php';

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
 * 获取设施建造选项 / Get facility construction options
 * @return array 建造选项 / Construction options
 */
function getFacilityConstructionOptions() {
    return [
        'resource_production' => [
            'name' => '资源产出点',
            'seconds' => 1800,
            'cost' => ['warm' => 500, 'cold' => 500, 'green' => 500, 'day' => 500],
            'requires_subtype' => true
        ],
        'barracks' => [
            'name' => '兵营',
            'seconds' => 3600,
            'cost' => ['warm' => 500, 'cold' => 500, 'green' => 500, 'day' => 500],
            'requires_subtype' => false
        ],
        'research_lab' => [
            'name' => '研究所',
            'seconds' => 7200,
            'cost' => ['warm' => 1000, 'cold' => 1000, 'green' => 1000, 'day' => 1000],
            'requires_subtype' => false
        ],
        'dormitory' => [
            'name' => '宿舍',
            'seconds' => 1800,
            'cost' => ['warm' => 500, 'cold' => 500, 'green' => 500, 'day' => 500],
            'requires_subtype' => false
        ],
        'storage' => [
            'name' => '贮存所',
            'seconds' => 1800,
            'cost' => ['warm' => 500, 'cold' => 500, 'green' => 500, 'day' => 500],
            'requires_subtype' => false
        ],
        'watchtower' => [
            'name' => '瞭望台',
            'seconds' => 3600,
            'cost' => ['warm' => 500, 'cold' => 500, 'green' => 500, 'day' => 500],
            'requires_subtype' => false
        ],
        'workshop' => [
            'name' => '工程所',
            'seconds' => 3600,
            'cost' => ['warm' => 1000, 'cold' => 1000, 'green' => 1000, 'day' => 500],
            'requires_subtype' => false
        ]
    ];
}

/**
 * 在当前事务中校验并扣除资源 / Validate and deduct resources inside the current transaction
 * @param mysqli $db 数据库连接 / Database connection
 * @param int $userId 用户ID / User ID
 * @param array $cost 资源费用 / Resource costs
 * @return string|null 失败消息，成功为null / Failure message, or null on success
 */
function deductConstructionResources($db, $userId, $cost) {
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
    $query = "UPDATE resources
              SET bright_crystal = bright_crystal - ?,
                  warm_crystal = warm_crystal - ?,
                  cold_crystal = cold_crystal - ?,
                  green_crystal = green_crystal - ?,
                  day_crystal = day_crystal - ?,
                  night_crystal = night_crystal - ?
              WHERE user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param(
        'iiiiiii',
        $bright,
        $warm,
        $cold,
        $green,
        $day,
        $night,
        $userId
    );
    $updated = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();

    return $updated ? null : '扣除建造资源失败';
}

$cityId = isset($_POST['city_id'])
    ? (int) $_POST['city_id']
    : (isset($_GET['city_id']) ? (int) $_GET['city_id'] : 0);
if ($cityId <= 0) {
    $mainCity = City::getUserMainCity($user->getUserId());
    $cityId = $mainCity ? $mainCity->getCityId() : 0;
}

$city = $cityId > 0 ? new City($cityId) : null;
if (!$city || !$city->isValid() || (int) $city->getOwnerId() !== (int) $user->getUserId()) {
    header('Location: index.php');
    exit;
}

$x = isset($_POST['x']) ? (int) $_POST['x'] : (isset($_GET['x']) ? (int) $_GET['x'] : 0);
$y = isset($_POST['y']) ? (int) $_POST['y'] : (isset($_GET['y']) ? (int) $_GET['y'] : 0);
$options = getFacilityConstructionOptions();
$adjustedConstructionSeconds = [];
foreach ($options as $optionType => $option) {
    // 预先计算实际建造时间供预览与执行共用 / Precompute actual construction time for both preview and execution
    $adjustedConstructionSeconds[$optionType] =
        $city->getAdjustedCityActionDuration(
            (int) $option['seconds'],
            'build_speed'
        );
}
$resourceTypes = ['bright', 'warm', 'cold', 'green', 'day', 'night'];
$message = '';
$messageType = 'info';
$createdFacilityId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $message = '请求校验失败，请刷新页面后重试。';
        $messageType = 'error';
    } elseif (isSeasonGameplayFrozen()) {
        $message = getSeasonGameplayFreezeMessage();
        $messageType = 'error';
    } elseif ($x < 0 || $x > 23 || $y < 0 || $y > 23) {
        $message = '建造坐标必须位于 24×24 城池范围内。';
        $messageType = 'error';
    } else {
        $type = isset($_POST['type']) ? (string) $_POST['type'] : '';
        $subtype = isset($_POST['subtype']) ? (string) $_POST['subtype'] : '';

        if (!isset($options[$type])) {
            $message = '设施类型无效。';
            $messageType = 'error';
        } elseif ($options[$type]['requires_subtype'] && !in_array($subtype, $resourceTypes, true)) {
            $message = '资源产出点类型无效。';
            $messageType = 'error';
        } else {
            if (!$options[$type]['requires_subtype']) {
                $subtype = null;
            } else {
                // 资源点不消耗其自身产出的资源 / A production point does not consume its own output resource
                unset($options[$type]['cost'][$subtype]);
            }

            $db = Database::getInstance()->getConnection();
            $db->begin_transaction();

            try {
                lockSeasonForWorldAction($db);

                // 锁定城池，确保建造期间所有权不变 / Lock the city so ownership remains stable during construction
                $query = "SELECT owner_id FROM cities WHERE city_id = ? FOR UPDATE";
                $stmt = $db->prepare($query);
                $stmt->bind_param('i', $cityId);
                $stmt->execute();
                $result = $stmt->get_result();
                $cityRow = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if (!$cityRow || (int) $cityRow['owner_id'] !== (int) $user->getUserId()) {
                    throw new DomainException('城池不存在或已不属于当前用户');
                }

                $deductionError = deductConstructionResources(
                    $db,
                    $user->getUserId(),
                    $options[$type]['cost']
                );
                if ($deductionError !== null) {
                    throw new DomainException($deductionError);
                }

                // 在城池锁内重算权威时长，避免并发技能变更沿用旧预览 / Recompute the authoritative duration under the city lock so concurrent skill changes cannot reuse a stale preview
                $constructionSeconds =
                    $city->getAdjustedCityActionDuration(
                        (int) $options[$type]['seconds'],
                        'build_speed'
                    );
                $constructionTime = date('Y-m-d H:i:s', time() + $constructionSeconds);
                $facility = new Facility();
                $createdFacilityId = $facility->createFacility(
                    $cityId,
                    $type,
                    $subtype,
                    1,
                    $x,
                    $y,
                    $constructionTime
                );
                if (!$createdFacilityId) {
                    throw new DomainException('该位置已被占用，或同类唯一设施已经存在');
                }

                $db->commit();
                $message = $facility->getName() . ' 已开始建造。';
                $messageType = 'success';
            } catch (Throwable $e) {
                $db->rollback();
                $message = $e instanceof DomainException
                    ? $e->getMessage()
                    : '建造请求处理失败，请稍后重试。';
                $messageType = 'error';
                error_log('Facility construction failed: ' . $e->getMessage());
            }
        }
    }
}

$resource = new Resource($user->getUserId());
$pageTitle = $city->getName() . ' - 建造设施';
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
                    <li><a href="internal.php?city_id=<?php echo $cityId; ?>">内政</a></li>
                    <li><a href="ranking.php">排名</a></li>
                    <li class="circuit-points">
                        <?php echo renderImageResource(
                            'resource_circuit_points',
                            24,
                            ['alt' => '思考回路 / Circuit Points']
                        ); ?>
                        <span class="circuit-label">思考回路:</span>
                        <span class="circuit-value">
                            <?php echo number_format($user->getCircuitPoints()); ?> /
                            <?php echo number_format($user->getMaxCircuitPoints()); ?>
                        </span>
                    </li>
                </ul>
            </nav>
        </header>

        <main>
            <!-- 资源栏 / Resource bar -->
            <?php renderGameplayResourceBar($resource); ?>

            <?php if ($message !== ''): ?>
                <div class="message <?php echo escapeHtml($messageType); ?>">
                    <p><?php echo escapeHtml($message); ?></p>
                    <?php if ($createdFacilityId > 0): ?>
                        <p><a href="facility.php?id=<?php echo $createdFacilityId; ?>">查看设施</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="city-view">
                <h3>建造位置 (<?php echo $x; ?>, <?php echo $y; ?>)</h3>
                <form method="post" action="build.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="city_id" value="<?php echo $cityId; ?>">
                    <input type="hidden" name="x" value="<?php echo $x; ?>">
                    <input type="hidden" name="y" value="<?php echo $y; ?>">

                    <div class="form-group">
                        <label for="type">设施类型</label>
                        <select id="type" name="type" required>
                            <?php foreach ($options as $optionType => $option): ?>
                                <option value="<?php echo escapeHtml($optionType); ?>">
                                    <?php echo escapeHtml($option['name']); ?>
                                    <?php if ($adjustedConstructionSeconds[$optionType] !== (int) $option['seconds']): ?>
                                        （实际 <?php echo formatTime($adjustedConstructionSeconds[$optionType]); ?>；
                                        基础 <?php echo formatTime($option['seconds']); ?>）
                                    <?php else: ?>
                                        （<?php echo formatTime($adjustedConstructionSeconds[$optionType]); ?>）
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="subtype">资源类型（仅资源产出点使用）</label>
                        <select id="subtype" name="subtype">
                            <?php foreach ($resourceTypes as $resourceType): ?>
                                <option value="<?php echo escapeHtml($resourceType); ?>">
                                    <?php echo escapeHtml(getResourceName($resourceType)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <button type="submit">确认建造</button>
                    </div>
                </form>

                <h3>建造费用</h3>
                <table class="barracks-table">
                    <thead>
                        <tr>
                            <th>设施</th>
                            <th>所需资源</th>
                            <th>时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($options as $optionType => $option): ?>
                            <tr>
                                <td><?php echo escapeHtml($option['name']); ?></td>
                                <td>
                                    <?php foreach ($option['cost'] as $type => $amount): ?>
                                        <?php echo escapeHtml(getResourceName($type)); ?> <?php echo number_format($amount); ?>&nbsp;
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php echo formatTime($adjustedConstructionSeconds[$optionType]); ?>
                                    <?php if ($adjustedConstructionSeconds[$optionType] !== (int) $option['seconds']): ?>
                                        <small>（基础 <?php echo formatTime($option['seconds']); ?>）</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a href="index.php">返回主基地</a></p>
            </div>
        </main>

        <footer>
            <p>&copy; <?php echo date('Y'); ?> <?php echo escapeHtml(SITE_NAME); ?> - 版本 <?php echo escapeHtml(GAME_VERSION); ?></p>
        </footer>
    </div>
</body>
</html>

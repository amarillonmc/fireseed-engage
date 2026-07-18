<?php
// 种火集结号 - 安全攻击目标接口 / Fireseed Engage - secure target attack endpoint

require_once '../includes/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}
if (!isValidPostRequest()) {
    echo json_encode(['success' => false, 'message' => '请求方式或安全令牌无效']);
    exit;
}
if (isSeasonGameplayFrozen()) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'frozen' => true,
        'message' => getSeasonGameplayFreezeMessage()
    ]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$armyId = isset($_POST['army_id']) && is_scalar($_POST['army_id'])
    ? filter_var($_POST['army_id'], FILTER_VALIDATE_INT)
    : false;
$targetType = isset($_POST['target_type']) && is_string($_POST['target_type'])
    ? $_POST['target_type']
    : '';
$targetId = isset($_POST['target_id']) && is_scalar($_POST['target_id'])
    ? filter_var($_POST['target_id'], FILTER_VALIDATE_INT)
    : 0;
$hasTargetX = array_key_exists('target_x', $_POST);
$hasTargetY = array_key_exists('target_y', $_POST);
$targetX = null;
$targetY = null;
if ($hasTargetX !== $hasTargetY) {
    echo json_encode(['success' => false, 'message' => '目标坐标必须成对提供']);
    exit;
}
if ($hasTargetX) {
    $targetX = is_scalar($_POST['target_x'])
        ? filter_var($_POST['target_x'], FILTER_VALIDATE_INT)
        : false;
    $targetY = is_scalar($_POST['target_y'])
        ? filter_var($_POST['target_y'], FILTER_VALIDATE_INT)
        : false;
}
if ($armyId === false
    || $armyId <= 0
    || $targetId === false
    || strlen($targetType) > 16
    || $targetX === false
    || $targetY === false) {
    echo json_encode(['success' => false, 'message' => '攻击参数无效']);
    exit;
}

$army = new Army($armyId);
if (!$army->isValid()
    || (int) $army->getOwnerId() !== $userId
    || $army->getStatus() !== 'idle'
    || $army->getCombatPower() <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '只能派遣自己拥有且有战斗力的待命军队'
    ]);
    exit;
}

// 坐标请求在服务端解析为权威目标，不能信任浏览器提供的类型 / Resolve coordinate requests authoritatively instead of trusting the browser's target type
if ($targetX !== null && $targetY !== null) {
    if ($targetX < 0 || $targetX >= MAP_WIDTH || $targetY < 0 || $targetY >= MAP_HEIGHT) {
        echo json_encode(['success' => false, 'message' => '目标坐标无效']);
        exit;
    }

    $targetTile = new Map();
    if (!$targetTile->loadByCoordinates($targetX, $targetY) || !$targetTile->isVisible()) {
        echo json_encode(['success' => false, 'message' => '目标不存在或尚未探索']);
        exit;
    }

    $query = "SELECT site_id FROM world_sites WHERE tile_id = ? LIMIT 1";
    $stmt = $db->prepare($query);
    $tileId = (int) $targetTile->getTileId();
    $stmt->bind_param('i', $tileId);
    $stmt->execute();
    $siteResult = $stmt->get_result();
    $worldSite = $siteResult ? $siteResult->fetch_assoc() : null;
    $stmt->close();
    if ($worldSite || $targetTile->getType() === 'special') {
        echo json_encode([
            'success' => false,
            'message' => '十二门与银白之孔必须通过赛季战进攻',
            'redirect' => 'season.php'
        ]);
        exit;
    }

    if ($targetTile->getType() === 'player_city') {
        $query = "SELECT city_id FROM cities WHERE x = ? AND y = ? LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bind_param('ii', $targetX, $targetY);
        $stmt->execute();
        $cityResult = $stmt->get_result();
        $cityRow = $cityResult ? $cityResult->fetch_assoc() : null;
        $stmt->close();
        if (!$cityRow) {
            echo json_encode(['success' => false, 'message' => '目标城池不存在']);
            exit;
        }
        $targetType = 'city';
        $targetId = (int) $cityRow['city_id'];
    } else {
        $targetType = 'tile';
        $targetId = (int) $targetTile->getTileId();
    }
}

if (!in_array($targetType, ['city', 'tile', 'army'], true) || $targetId <= 0) {
    echo json_encode(['success' => false, 'message' => '目标参数无效']);
    exit;
}

$defenderOwnerId = null;
$resolvedTargetX = null;
$resolvedTargetY = null;
if ($targetType === 'city') {
    $target = new City($targetId);
    if (!$target->isValid()) {
        echo json_encode(['success' => false, 'message' => '目标城池不存在']);
        exit;
    }
    $defenderOwnerId = (int) $target->getOwnerId();
    $targetCoordinates = $target->getCoordinates();
    $resolvedTargetX = (int) $targetCoordinates[0];
    $resolvedTargetY = (int) $targetCoordinates[1];
} elseif ($targetType === 'army') {
    $target = new Army($targetId);
    if (!$target->isValid() || $target->getStatus() !== 'idle') {
        echo json_encode(['success' => false, 'message' => '目标军队不存在或已离开']);
        exit;
    }
    $defenderOwnerId = (int) $target->getOwnerId();
    $targetCoordinates = $target->getCurrentPosition();
    $resolvedTargetX = (int) $targetCoordinates[0];
    $resolvedTargetY = (int) $targetCoordinates[1];
} else {
    $target = new Map($targetId);
    if (!$target->isValid() || !$target->isVisible()) {
        echo json_encode(['success' => false, 'message' => '目标地点不存在或尚未探索']);
        exit;
    }
    $query = "SELECT site_id FROM world_sites WHERE tile_id = ? LIMIT 1";
    $stmt = $db->prepare($query);
    $resolvedTileId = (int) $target->getTileId();
    $stmt->bind_param('i', $resolvedTileId);
    $stmt->execute();
    $siteResult = $stmt->get_result();
    $isWorldSite = $siteResult && $siteResult->num_rows > 0;
    $stmt->close();
    if ($isWorldSite || $target->getType() === 'special') {
        echo json_encode([
            'success' => false,
            'message' => '特殊地点必须通过赛季战进攻',
            'redirect' => 'season.php'
        ]);
        exit;
    }
    if (!in_array($target->getType(), ['npc_fort', 'empty', 'resource'], true)) {
        echo json_encode(['success' => false, 'message' => '该地点没有可攻击的守军']);
        exit;
    }
    if ($target->getOwnerId() === null
        && in_array($target->getType(), ['empty', 'resource'], true)) {
        echo json_encode([
            'success' => false,
            'message' => '无主普通领地应直接占领'
        ]);
        exit;
    }
    $defenderOwnerId = $target->getOwnerId() === null
        ? null
        : (int) $target->getOwnerId();
    $resolvedTargetX = (int) $target->getX();
    $resolvedTargetY = (int) $target->getY();
}

// 所有世界攻击必须从己方普通领地或城池向曼哈顿相邻格发起 / Every world attack must target a Manhattan-adjacent tile from owned ordinary territory or a city
if (!Map::isAdjacentToUserControl(
    $userId,
    $resolvedTargetX,
    $resolvedTargetY
)) {
    echo json_encode([
        'success' => false,
        'message' => '只能攻击与己方普通领地或城池曼哈顿相邻的目标'
    ]);
    exit;
}

if ($defenderOwnerId !== null) {
    $allianceService = new AllianceService();
    if (!$allianceService->canUsersFight($userId, $defenderOwnerId)) {
        echo json_encode([
            'success' => false,
            'message' => '不能攻击自己或同势力成员 / Cannot attack yourself or a member of the same force'
        ]);
        exit;
    }
}

$battleId = $army->attackTarget($targetType, $targetId);
if (!$battleId) {
    echo json_encode([
        'success' => false,
        'message' => '出征失败，军队或目标状态可能已经变化'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => '军队已出发，抵达后将自动结算战斗',
    'battle_id' => $battleId,
    'arrival_time' => $army->getArrivalTime()
]);

<?php
// 种火集结号 - 安全探索地图接口 / Fireseed Engage - secure map exploration endpoint

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

$x = isset($_POST['x']) && is_scalar($_POST['x'])
    ? filter_var($_POST['x'], FILTER_VALIDATE_INT)
    : false;
$y = isset($_POST['y']) && is_scalar($_POST['y'])
    ? filter_var($_POST['y'], FILTER_VALIDATE_INT)
    : false;
$armyId = isset($_POST['army_id']) && is_scalar($_POST['army_id'])
    ? filter_var($_POST['army_id'], FILTER_VALIDATE_INT)
    : 0;
if ($x === false || $y === false || $armyId === false || $armyId < 0) {
    echo json_encode(['success' => false, 'message' => '探索参数无效']);
    exit;
}
$discoveredTiles = Map::exploreTiles(
    (int) $_SESSION['user_id'],
    $x,
    $y,
    $armyId
);
if (is_string($discoveredTiles)) {
    echo json_encode(['success' => false, 'message' => $discoveredTiles]);
    exit;
}

$tileData = [];
foreach ($discoveredTiles as $tile) {
    $tileData[] = [
        'tile_id' => $tile->getTileId(),
        'x' => $tile->getX(),
        'y' => $tile->getY(),
        'type' => $tile->getType(),
        'subtype' => $tile->getSubtype(),
        'name' => $tile->getName()
    ];
}

$user = new User((int) $_SESSION['user_id']);
echo json_encode([
    'success' => true,
    'message' => empty($tileData) ? '附近没有新的地点' : '探索成功',
    'discovered_tiles' => $tileData,
    'circuit_points' => $user->getCircuitPoints(),
    'max_circuit_points' => $user->getMaxCircuitPoints()
]);

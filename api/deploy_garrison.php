<?php
// 种火集结号 - 部署领地驻军接口 / Fireseed Engage - territory garrison deployment endpoint

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

$tileId = isset($_POST['tile_id']) && is_scalar($_POST['tile_id'])
    ? filter_var($_POST['tile_id'], FILTER_VALIDATE_INT)
    : false;
$armyId = isset($_POST['army_id']) && is_scalar($_POST['army_id'])
    ? filter_var($_POST['army_id'], FILTER_VALIDATE_INT)
    : false;
if ($tileId === false || $tileId <= 0 || $armyId === false || $armyId <= 0) {
    echo json_encode(['success' => false, 'message' => '驻军部署参数无效']);
    exit;
}

$service = new TerritoryGarrisonService();
$result = $service->deployArmy(
    (int) $_SESSION['user_id'],
    $tileId,
    $armyId
);
echo json_encode($result, JSON_UNESCAPED_UNICODE);

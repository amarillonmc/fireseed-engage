<?php
// 种火集结号 - 撤回领地驻军接口 / Fireseed Engage - territory garrison withdrawal endpoint

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
$cityId = isset($_POST['city_id']) && is_scalar($_POST['city_id'])
    ? filter_var($_POST['city_id'], FILTER_VALIDATE_INT)
    : false;
$name = isset($_POST['name']) && is_string($_POST['name'])
    ? trim($_POST['name'])
    : '';
$unitsJson = isset($_POST['units']) && is_string($_POST['units'])
    ? $_POST['units']
    : '';
$units = json_decode($unitsJson, true);
if ($tileId === false
    || $tileId <= 0
    || $cityId === false
    || $cityId <= 0
    || $name === ''
    || !is_array($units)) {
    echo json_encode(['success' => false, 'message' => '驻军撤回参数无效']);
    exit;
}

$service = new TerritoryGarrisonService();
$result = $service->withdrawGarrison(
    (int) $_SESSION['user_id'],
    $tileId,
    $cityId,
    $name,
    $units
);
echo json_encode($result, JSON_UNESCAPED_UNICODE);

<?php
// 种火集结号 - 放弃地图格子接口 / Fireseed Engage - tile abandonment endpoint

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
if ($x === false || $y === false) {
    echo json_encode(['success' => false, 'message' => '坐标参数无效']);
    exit;
}
$result = Map::abandonTile((int) $_SESSION['user_id'], $x, $y);

echo json_encode($result === true
    ? ['success' => true, 'message' => '放弃成功']
    : ['success' => false, 'message' => $result]);

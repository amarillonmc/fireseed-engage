<?php
// 种火集结号 - 原子占领地图格子接口 / Fireseed Engage - atomic tile occupation endpoint

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
$result = Map::occupyTile((int) $_SESSION['user_id'], $x, $y);

if ($result !== true) {
    echo json_encode(['success' => false, 'message' => $result]);
    exit;
}

// 重新读取余额，确保返回事务提交后的数值 / Reload the balance after the transaction commits
$user = new User((int) $_SESSION['user_id']);
echo json_encode([
    'success' => true,
    'message' => '占领成功',
    'circuit_points' => $user->getCircuitPoints(),
    'max_circuit_points' => $user->getMaxCircuitPoints()
]);

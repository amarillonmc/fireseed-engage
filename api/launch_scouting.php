<?php
// 种火集结号 - 安全发起侦察任务接口 / Fireseed Engage - Secure scouting mission launch endpoint

require_once '../includes/init.php';
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '未登录'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 发起任务只接受带CSRF令牌的POST请求 / Mission launches only accept POST requests with a CSRF token
if (!isValidPostRequest()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => '请求方式或安全令牌无效'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isSeasonGameplayFrozen()) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'frozen' => true,
        'message' => getSeasonGameplayFreezeMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$armyId = isset($_POST['army_id']) && is_scalar($_POST['army_id'])
    ? filter_var($_POST['army_id'], FILTER_VALIDATE_INT)
    : false;
$targetX = isset($_POST['target_x']) && is_scalar($_POST['target_x'])
    ? filter_var($_POST['target_x'], FILTER_VALIDATE_INT)
    : false;
$targetY = isset($_POST['target_y']) && is_scalar($_POST['target_y'])
    ? filter_var($_POST['target_y'], FILTER_VALIDATE_INT)
    : false;
if ($armyId === false
    || $armyId <= 0
    || $targetX === false
    || $targetY === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '侦察参数无效'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$scoutingService = new ScoutingService();
$result = $scoutingService->launchMission(
    (int) $_SESSION['user_id'],
    $armyId,
    $targetX,
    $targetY
);
if (empty($result['success'])) {
    http_response_code(
        isset($result['code']) && $result['code'] === 'frozen'
            ? 409
            : 400
    );
    if (isset($result['code']) && $result['code'] === 'frozen') {
        $result['frozen'] = true;
    }
}

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

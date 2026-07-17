<?php
// 种火集结号 - 士兵训练接口 / Fireseed Engage - soldier training endpoint

require_once '../includes/init.php';
header('Content-Type: application/json; charset=UTF-8');

/**
 * 输出JSON并结束请求 / Emit JSON and end the request
 * @param array $payload 响应内容 / Response payload
 * @param int $status HTTP状态码 / HTTP status
 * @return void
 */
function sendTrainingResponse($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    sendTrainingResponse(['success' => false, 'message' => '未登录'], 401);
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendTrainingResponse(['success' => false, 'message' => '仅支持POST请求'], 405);
}

// 同时支持表单与JSON请求体 / Accept both form and JSON request bodies
$input = $_POST;
if (empty($input)) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$csrfToken = isset($input['csrf_token']) ? $input['csrf_token'] : null;
if (!validateCsrfToken($csrfToken)) {
    sendTrainingResponse(['success' => false, 'message' => '请求校验失败，请刷新页面后重试'], 403);
}

if (isSeasonGameplayFrozen()) {
    sendTrainingResponse([
        'success' => false,
        'message' => getSeasonGameplayFreezeMessage()
    ], 409);
}

$cityId = isset($input['city_id']) ? (int) $input['city_id'] : 0;
$soldierType = isset($input['soldier_type']) ? (string) $input['soldier_type'] : '';
$quantity = isset($input['quantity']) ? (int) $input['quantity'] : 0;

$result = Soldier::startTraining(
    (int) $_SESSION['user_id'],
    $cityId,
    $soldierType,
    $quantity
);

if (!$result['success']) {
    sendTrainingResponse($result, 422);
}

sendTrainingResponse($result);

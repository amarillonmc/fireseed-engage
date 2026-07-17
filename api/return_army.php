<?php
// 种火集结号 - 军队返城接口 / Fireseed Engage - army return endpoint
require_once '../includes/init.php';

// 设置响应头
header('Content-Type: application/json');

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '未登录'
    ]);
    exit;
}

if (!isValidPostRequest()) {
    echo json_encode([
        'success' => false,
        'message' => '请求方式或安全令牌无效'
    ]);
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

$armyId = isset($_POST['army_id']) && is_scalar($_POST['army_id'])
    ? filter_var($_POST['army_id'], FILTER_VALIDATE_INT)
    : false;

// 验证参数
if ($armyId === false || $armyId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '参数无效'
    ]);
    exit;
}

// 获取军队信息
$army = new Army($armyId);
if (!$army->isValid() || $army->getOwnerId() != $_SESSION['user_id']) {
    echo json_encode([
        'success' => false,
        'message' => '军队不存在或不属于当前用户'
    ]);
    exit;
}

// 返回城池
if ($army->returnToCity()) {
    echo json_encode([
        'success' => true,
        'message' => '军队开始返回城池'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => '返回城池失败，请确保军队处于待命或行军状态'
    ]);
}

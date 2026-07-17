<?php
// 种火集结号 - 解散军队接口 / Fireseed Engage - army disbandment endpoint
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

// 解散军队
if ($army->disbandArmy()) {
    echo json_encode([
        'success' => true,
        'message' => '解散军队成功'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => '解散失败，请确保军队在所属城池待命且未被其他玩法占用'
    ]);
}

<?php
// 种火集结号 - 领地资源收集接口 / Fireseed Engage - territory resource collection endpoint
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

// 资源收集会改变状态，必须使用POST和CSRF令牌 / Resource collection mutates state and requires POST with CSRF
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

$tileId = 0;
if (isset($_POST['tile_id'])) {
    $tileId = is_scalar($_POST['tile_id'])
        ? filter_var($_POST['tile_id'], FILTER_VALIDATE_INT)
        : false;
    if ($tileId === false || $tileId <= 0) {
        echo json_encode(['success' => false, 'message' => '资源点参数无效']);
        exit;
    }
}

// 创建资源收集器
$resourceCollector = new ResourceCollector();

// 如果指定了资源点ID，只收集该资源点的资源
if ($tileId > 0) {
    $result = $resourceCollector->collectResourceFromTile($tileId, $_SESSION['user_id']);
} else {
    // 否则收集用户的所有资源点资源
    $result = $resourceCollector->collectResourcesForUser($_SESSION['user_id']);
}

// 返回收集结果
echo json_encode($result);

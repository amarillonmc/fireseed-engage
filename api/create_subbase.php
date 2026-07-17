<?php
// 种火集结号 - 资源点改建分基地接口 / Fireseed Engage - Resource-tile-to-sub-base endpoint

require_once '../includes/init.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(
        ['success' => false, 'message' => '未登录 / Not signed in'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// 分基地改建会改变城池与地图状态，必须使用POST和CSRF令牌 / Sub-base conversion mutates city and map state and requires POST with CSRF
if (!isValidPostRequest()) {
    echo json_encode(
        [
            'success' => false,
            'message' => '请求方式或安全令牌无效 / Invalid request method or security token'
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}
if (isSeasonGameplayFrozen()) {
    http_response_code(409);
    echo json_encode(
        [
            'success' => false,
            'frozen' => true,
            'message' => getSeasonGameplayFreezeMessage()
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$tileId = isset($_POST['tile_id']) && is_scalar($_POST['tile_id'])
    ? filter_var($_POST['tile_id'], FILTER_VALIDATE_INT)
    : false;
$name = isset($_POST['name']) && is_scalar($_POST['name'])
    ? (string) $_POST['name']
    : '';
if ($tileId === false || $tileId <= 0) {
    echo json_encode(
        ['success' => false, 'message' => '资源点参数无效 / Invalid resource tile'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$service = new SubBaseService();
$result = $service->createSubBase(
    (int) $_SESSION['user_id'],
    (int) $tileId,
    $name
);
echo json_encode($result, JSON_UNESCAPED_UNICODE);

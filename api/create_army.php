<?php
// 种火集结号 - 创建军队接口 / Fireseed Engage - army creation endpoint
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

// 获取请求数据
$data = json_decode(file_get_contents('php://input'), true);
$csrfToken = is_array($data) && isset($data['csrf_token'])
    ? $data['csrf_token']
    : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');

// JSON接口同样要求POST和CSRF校验 / JSON mutations also require POST and CSRF validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($csrfToken)) {
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

// 验证请求数据 / Validate the request payload
if (!is_array($data)
    || !isset($data['name'], $data['city_id'], $data['units'])
    || !is_string($data['name'])
    || !is_array($data['units'])
    || empty($data['units'])) {
    echo json_encode([
        'success' => false,
        'message' => '参数无效'
    ]);
    exit;
}

$name = normalizeTextInput($data['name'], 50);
$cityId = is_scalar($data['city_id'])
    ? filter_var($data['city_id'], FILTER_VALIDATE_INT)
    : false;

if ($name === '' || $cityId === false || $cityId <= 0 || count($data['units']) > 6) {
    echo json_encode([
        'success' => false,
        'message' => '军队名称或编制无效'
    ]);
    exit;
}

$units = [];
$seenTypes = [];
$validSoldierTypes = ['pawn', 'knight', 'rook', 'bishop', 'golem', 'scout'];
foreach ($data['units'] as $unit) {
    if (!is_array($unit)
        || !isset($unit['soldier_type'], $unit['level'], $unit['quantity'])
        || !is_string($unit['soldier_type'])) {
        echo json_encode(['success' => false, 'message' => '军队编制格式无效']);
        exit;
    }
    $soldierType = $unit['soldier_type'];
    $level = is_scalar($unit['level'])
        ? filter_var($unit['level'], FILTER_VALIDATE_INT)
        : false;
    $quantity = is_scalar($unit['quantity'])
        ? filter_var($unit['quantity'], FILTER_VALIDATE_INT)
        : false;
    if (!in_array($soldierType, $validSoldierTypes, true)
        || isset($seenTypes[$soldierType])
        || $level === false
        || $level < 1
        || $level > 1000000
        || $quantity === false
        || $quantity < 1
        || $quantity > 2147483647) {
        echo json_encode(['success' => false, 'message' => '军队编制数值无效']);
        exit;
    }
    $seenTypes[$soldierType] = true;
    $units[] = [
        'soldier_type' => $soldierType,
        'level' => $level,
        'quantity' => $quantity
    ];
}

// 检查城池是否属于当前用户
$city = new City($cityId);
if (!$city->isValid() || $city->getOwnerId() != $_SESSION['user_id']) {
    echo json_encode([
        'success' => false,
        'message' => '城池不存在或不属于当前用户'
    ]);
    exit;
}

// 创建军队
$army = new Army();
$result = $army->createArmy($_SESSION['user_id'], $name, $cityId, $units);

if ($result) {
    echo json_encode([
        'success' => true,
        'message' => '创建军队成功',
        'army_id' => $result
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => '创建军队失败，请确保城池中有足够的士兵'
    ]);
}

<?php
// 包含初始化文件
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

// 只允许带安全令牌的POST请求 / Allow only POST requests carrying a CSRF token
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '仅支持POST请求'
    ]);
    exit;
}

// 获取请求数据 / Read request data
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)
    || !validateCsrfToken(isset($data['csrf_token']) ? $data['csrf_token'] : null)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => '请求校验失败，请刷新页面后重试'
    ]);
    exit;
}

if (isSeasonGameplayFrozen()) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => getSeasonGameplayFreezeMessage()
    ]);
    exit;
}

// 验证请求数据
if (!isset($data['city_id']) || !isset($data['strategy'])) {
    echo json_encode([
        'success' => false,
        'message' => '参数无效'
    ]);
    exit;
}

$cityId = intval($data['city_id']);
$strategy = $data['strategy'];

// 检查城池是否属于当前用户
$city = new City($cityId);
if (!$city->isValid() || $city->getOwnerId() != $_SESSION['user_id']) {
    echo json_encode([
        'success' => false,
        'message' => '城池不存在或不属于当前用户'
    ]);
    exit;
}

// 检查策略是否有效
$validStrategies = ['defense', 'balanced', 'production'];
if (!in_array($strategy, $validStrategies)) {
    echo json_encode([
        'success' => false,
        'message' => '防御策略无效'
    ]);
    exit;
}

// 设置城池防御策略
if ($city->setDefenseStrategy($strategy)) {
    echo json_encode([
        'success' => true,
        'message' => '设置防御策略成功'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => '设置防御策略失败'
    ]);
}

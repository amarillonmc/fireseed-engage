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

// 获取请求数据
$data = json_decode(file_get_contents('php://input'), true);

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

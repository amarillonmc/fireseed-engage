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
if (!isset($data['name']) || !isset($data['city_id']) || !isset($data['units']) || empty($data['units'])) {
    echo json_encode([
        'success' => false,
        'message' => '参数无效'
    ]);
    exit;
}

$name = $data['name'];
$cityId = intval($data['city_id']);
$units = $data['units'];

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

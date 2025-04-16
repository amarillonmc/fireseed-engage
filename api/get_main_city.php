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

// 获取用户主城
$mainCity = City::getUserMainCity($_SESSION['user_id']);

if (!$mainCity) {
    echo json_encode([
        'success' => false,
        'message' => '未找到主城'
    ]);
    exit;
}

// 获取主城坐标
$coordinates = $mainCity->getCoordinates();

// 返回主城坐标
echo json_encode([
    'success' => true,
    'x' => $coordinates[0],
    'y' => $coordinates[1],
    'city_id' => $mainCity->getCityId(),
    'city_name' => $mainCity->getName()
]);

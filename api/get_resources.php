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

// 获取用户资源
$resource = new Resource($_SESSION['user_id']);
if (!$resource->isValid()) {
    echo json_encode([
        'success' => false,
        'message' => '获取资源失败'
    ]);
    exit;
}

// 返回资源数据
echo json_encode([
    'success' => true,
    'resources' => [
        'bright_crystal' => $resource->getBrightCrystal(),
        'warm_crystal' => $resource->getWarmCrystal(),
        'cold_crystal' => $resource->getColdCrystal(),
        'green_crystal' => $resource->getGreenCrystal(),
        'day_crystal' => $resource->getDayCrystal(),
        'night_crystal' => $resource->getNightCrystal()
    ]
]);

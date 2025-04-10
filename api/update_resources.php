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

// 更新资源产出
$resourceUpdated = Resource::updateResourceProduction($_SESSION['user_id']);

// 更新思考回路产出
$circuitProducedCities = Resource::updateCircuitProduction($_SESSION['user_id']);

// 获取更新后的资源
$resource = new Resource($_SESSION['user_id']);
if (!$resource->isValid()) {
    echo json_encode([
        'success' => false,
        'message' => '获取资源失败'
    ]);
    exit;
}

// 获取用户信息
$user = new User($_SESSION['user_id']);
if (!$user->isValid()) {
    echo json_encode([
        'success' => false,
        'message' => '获取用户信息失败'
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
    ],
    'circuit_points' => $user->getCircuitPoints(),
    'max_circuit_points' => $user->getMaxCircuitPoints(),
    'circuit_produced_cities' => $circuitProducedCities
]);

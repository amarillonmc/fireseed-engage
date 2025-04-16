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

// 获取请求参数
$tileId = isset($_GET['tile_id']) ? intval($_GET['tile_id']) : 0;

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

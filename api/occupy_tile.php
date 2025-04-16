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
$x = isset($_GET['x']) ? intval($_GET['x']) : 0;
$y = isset($_GET['y']) ? intval($_GET['y']) : 0;

// 验证参数
if ($x < 0 || $x >= MAP_WIDTH || $y < 0 || $y >= MAP_HEIGHT) {
    echo json_encode([
        'success' => false,
        'message' => '坐标无效'
    ]);
    exit;
}

// 获取用户信息
$user = new User($_SESSION['user_id']);
if (!$user->isValid()) {
    echo json_encode([
        'success' => false,
        'message' => '用户信息无效'
    ]);
    exit;
}

// 检查用户是否有足够的思考回路
$requiredCircuitPoints = 2; // 占领需要消耗的思考回路点数
if ($user->getCircuitPoints() < $requiredCircuitPoints) {
    echo json_encode([
        'success' => false,
        'message' => '思考回路不足'
    ]);
    exit;
}

// 占领地图格子
$result = Map::occupyTile($_SESSION['user_id'], $x, $y);

// 如果占领成功，扣除思考回路
if ($result === true) {
    $user->reduceCircuitPoints($requiredCircuitPoints);
    
    echo json_encode([
        'success' => true,
        'message' => '占领成功',
        'circuit_points' => $user->getCircuitPoints(),
        'max_circuit_points' => $user->getMaxCircuitPoints()
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result
    ]);
}

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
$radius = isset($_GET['radius']) ? intval($_GET['radius']) : 3;

// 验证参数
if ($x < 0 || $x >= MAP_WIDTH || $y < 0 || $y >= MAP_HEIGHT) {
    echo json_encode([
        'success' => false,
        'message' => '坐标无效'
    ]);
    exit;
}

// 限制探索半径
$maxRadius = 5;
if ($radius <= 0 || $radius > $maxRadius) {
    $radius = $maxRadius;
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
$requiredCircuitPoints = 1; // 探索需要消耗的思考回路点数
if ($user->getCircuitPoints() < $requiredCircuitPoints) {
    echo json_encode([
        'success' => false,
        'message' => '思考回路不足'
    ]);
    exit;
}

// 探索地图
$discoveredTiles = Map::exploreTiles($_SESSION['user_id'], $x, $y, $radius);

// 如果有新发现的地图格子，扣除思考回路
if (!empty($discoveredTiles)) {
    $user->reduceCircuitPoints($requiredCircuitPoints);
}

// 准备返回数据
$tileData = [];

foreach ($discoveredTiles as $tile) {
    $tileData[] = [
        'tile_id' => $tile->getTileId(),
        'x' => $tile->getX(),
        'y' => $tile->getY(),
        'type' => $tile->getType(),
        'subtype' => $tile->getSubtype(),
        'name' => $tile->getName()
    ];
}

// 返回探索结果
echo json_encode([
    'success' => true,
    'message' => '探索成功',
    'discovered_tiles' => $tileData,
    'circuit_points' => $user->getCircuitPoints(),
    'max_circuit_points' => $user->getMaxCircuitPoints()
]);

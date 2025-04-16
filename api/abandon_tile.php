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

// 放弃地图格子
$result = Map::abandonTile($_SESSION['user_id'], $x, $y);

// 返回结果
if ($result === true) {
    echo json_encode([
        'success' => true,
        'message' => '放弃成功'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result
    ]);
}

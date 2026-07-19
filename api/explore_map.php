<?php
// 种火集结号 - 已停用的地图探索接口 / Fireseed Engage - retired map-exploration endpoint

require_once '../includes/init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '未登录 / Not signed in'
    ]);
    exit;
}

// 保留端点只为旧页面和缓存客户端提供明确迁移结果；不会改变世界或扣除回路。
// Keep the endpoint solely to give cached clients an explicit migration result;
// it never mutates the world or charges Circuit Points.
http_response_code(410);
echo json_encode([
    'success' => false,
    'retired' => true,
    'message' => '地图已改为全图可见，不再需要探索 / The full map is visible; exploration is no longer required'
]);

<?php
// 包含初始化文件
require_once '../includes/init.php';

// 设置响应头
header('Content-Type: application/json');

// 检查用户是否已登录且为管理员
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '用户未登录'
    ]);
    exit;
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid() || !$user->isAdmin()) {
    echo json_encode([
        'success' => false,
        'message' => '权限不足'
    ]);
    exit;
}

// 创建管理员管理器
$adminManager = new AdminManager($user);

// 检查权限
if (!$adminManager->hasPermission('view_users')) {
    echo json_encode([
        'success' => false,
        'message' => '您没有权限查看用户信息'
    ]);
    exit;
}

// 获取目标用户ID
$targetUserId = intval($_GET['user_id'] ?? 0);

if ($targetUserId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '无效的用户ID'
    ]);
    exit;
}

try {
    // 获取用户资源
    $resource = new Resource($targetUserId);
    
    if (!$resource->isValid()) {
        echo json_encode([
            'success' => false,
            'message' => '用户资源不存在'
        ]);
        exit;
    }
    
    $resources = [
        'bright_crystal' => $resource->getBrightCrystal(),
        'warm_crystal' => $resource->getWarmCrystal(),
        'cold_crystal' => $resource->getColdCrystal(),
        'green_crystal' => $resource->getGreenCrystal(),
        'day_crystal' => $resource->getDayCrystal(),
        'night_crystal' => $resource->getNightCrystal()
    ];
    
    echo json_encode([
        'success' => true,
        'resources' => $resources
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ]);
}

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
$armyId = isset($_GET['army_id']) ? intval($_GET['army_id']) : 0;

// 验证参数
if ($armyId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '参数无效'
    ]);
    exit;
}

// 获取军队信息
$army = new Army($armyId);
if (!$army->isValid() || $army->getOwnerId() != $_SESSION['user_id']) {
    echo json_encode([
        'success' => false,
        'message' => '军队不存在或不属于当前用户'
    ]);
    exit;
}

// 解散军队
if ($army->disbandArmy()) {
    echo json_encode([
        'success' => true,
        'message' => '解散军队成功'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => '解散军队失败，请确保军队处于待命状态'
    ]);
}

<?php
// 包含初始化文件
require_once '../includes/init.php';

// 设置响应头
header('Content-Type: application/json');

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '用户未登录'
    ]);
    exit;
}

try {
    // 检查并完成所有已完成的研究
    $completedResearch = UserTechnology::checkAndCompleteResearch();

    // 过滤出当前用户的研究
    $userCompletedResearch = array_filter($completedResearch, function($research) {
        return $research['user_id'] == $_SESSION['user_id'];
    });

    echo json_encode([
        'success' => true,
        'completed_research' => array_values($userCompletedResearch)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ]);
}

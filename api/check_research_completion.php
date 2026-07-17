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
// 赛季冻结期间不推进任何城池研究 / Do not advance city research during the season freeze
if (isSeasonGameplayFrozen()) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'frozen' => true,
        'message' => getSeasonGameplayFreezeMessage()
    ]);
    exit;
}

try {
    // 仅结算当前玩家，不能由一次轮询推进其他账号 / Settle only the current user, never every account from one poll
    $userCompletedResearch = UserTechnology::checkAndCompleteResearch(
        (int) $_SESSION['user_id']
    );

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

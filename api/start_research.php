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

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => '无效的请求方法'
    ]);
    exit;
}

// 获取POST数据
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['tech_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '缺少科技ID'
    ]);
    exit;
}

$techId = intval($input['tech_id']);
$userId = $_SESSION['user_id'];

try {
    // 获取用户信息
    $user = new User($userId);
    if (!$user->isValid()) {
        echo json_encode([
            'success' => false,
            'message' => '用户信息无效'
        ]);
        exit;
    }

    // 获取用户主城
    $mainCity = City::getUserMainCity($userId);
    if (!$mainCity) {
        echo json_encode([
            'success' => false,
            'message' => '未找到主城'
        ]);
        exit;
    }

    // 检查科技是否存在
    $technology = new Technology($techId);
    if (!$technology->isValid()) {
        echo json_encode([
            'success' => false,
            'message' => '科技不存在'
        ]);
        exit;
    }

    // 获取用户科技
    $userTech = new UserTechnology($userId, $techId);

    // 开始研究
    $result = $userTech->startResearch($mainCity->getCityId());

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => '研究开始成功',
            'tech_name' => $technology->getName(),
            'research_time' => $userTech->getResearchTime()
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '研究开始失败，请检查条件'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage()
    ]);
}

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
$cityId = isset($_GET['city_id']) ? intval($_GET['city_id']) : 0;

// 验证参数
if ($cityId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '参数无效'
    ]);
    exit;
}

// 检查城池是否属于当前用户
$city = new City($cityId);
if (!$city->isValid() || $city->getOwnerId() != $_SESSION['user_id']) {
    echo json_encode([
        'success' => false,
        'message' => '城池不存在或不属于当前用户'
    ]);
    exit;
}

// 获取城池中的士兵
$soldiers = $city->getSoldiers();

// 准备返回数据
$soldierData = [];

foreach ($soldiers as $soldier) {
    if ($soldier->getQuantity() > 0) {
        $soldierData[] = [
            'type' => $soldier->getType(),
            'level' => $soldier->getLevel(),
            'quantity' => $soldier->getQuantity()
        ];
    }
}

// 返回士兵数据
echo json_encode([
    'success' => true,
    'soldiers' => $soldierData
]);

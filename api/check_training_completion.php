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

// 获取用户的所有城池
$cities = City::getUserCities($_SESSION['user_id']);
$cityIds = [];

foreach ($cities as $city) {
    $cityIds[] = $city->getCityId();
}

// 如果用户没有城池，直接返回
if (empty($cityIds)) {
    echo json_encode([
        'success' => true,
        'completed_trainings' => []
    ]);
    exit;
}

// 检查用户城池中的士兵训练完成情况
$completedTrainings = [];

$db = Database::getInstance()->getConnection();
$now = date('Y-m-d H:i:s');

// 检查训练完成的士兵
$trainingQuery = "SELECT s.soldier_id FROM soldiers s 
                  JOIN cities c ON s.city_id = c.city_id 
                  WHERE c.owner_id = ? AND s.in_training > 0 AND s.training_complete_time IS NOT NULL AND s.training_complete_time <= ?";
$trainingStmt = $db->prepare($trainingQuery);
$trainingStmt->bind_param('is', $_SESSION['user_id'], $now);
$trainingStmt->execute();
$trainingResult = $trainingStmt->get_result();

if ($trainingResult) {
    while ($row = $trainingResult->fetch_assoc()) {
        $soldier = new Soldier($row['soldier_id']);
        if ($soldier->isValid() && $soldier->completeTraining()) {
            // 获取士兵所在的城池
            $city = new City($soldier->getCityId());
            
            $completedTrainings[] = [
                'soldier_id' => $soldier->getSoldierId(),
                'city_id' => $soldier->getCityId(),
                'city_name' => $city->isValid() ? $city->getName() : '',
                'type' => $soldier->getType(),
                'name' => $soldier->getName(),
                'quantity' => $soldier->getQuantity()
            ];
        }
    }
}

$trainingStmt->close();

// 返回完成的训练
echo json_encode([
    'success' => true,
    'completed_trainings' => $completedTrainings
]);

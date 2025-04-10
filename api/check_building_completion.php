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
        'completed_constructions' => [],
        'completed_upgrades' => []
    ]);
    exit;
}

// 检查用户城池中的设施建造完成情况
$completedConstructions = [];
$completedUpgrades = [];

$db = Database::getInstance()->getConnection();
$now = date('Y-m-d H:i:s');

// 检查建造完成的设施
$constructionQuery = "SELECT f.facility_id FROM facilities f 
                      JOIN cities c ON f.city_id = c.city_id 
                      WHERE c.owner_id = ? AND f.construction_time IS NOT NULL AND f.construction_time <= ?";
$constructionStmt = $db->prepare($constructionQuery);
$constructionStmt->bind_param('is', $_SESSION['user_id'], $now);
$constructionStmt->execute();
$constructionResult = $constructionStmt->get_result();

if ($constructionResult) {
    while ($row = $constructionResult->fetch_assoc()) {
        $facility = new Facility($row['facility_id']);
        if ($facility->isValid() && $facility->completeConstruction()) {
            // 获取设施所在的城池
            $city = new City($facility->getCityId());
            
            $completedConstructions[] = [
                'facility_id' => $facility->getFacilityId(),
                'city_id' => $facility->getCityId(),
                'city_name' => $city->isValid() ? $city->getName() : '',
                'type' => $facility->getType(),
                'subtype' => $facility->getSubtype(),
                'name' => $facility->getName(),
                'level' => $facility->getLevel()
            ];
        }
    }
}

$constructionStmt->close();

// 检查升级完成的设施
$upgradeQuery = "SELECT f.facility_id FROM facilities f 
                 JOIN cities c ON f.city_id = c.city_id 
                 WHERE c.owner_id = ? AND f.upgrade_time IS NOT NULL AND f.upgrade_time <= ?";
$upgradeStmt = $db->prepare($upgradeQuery);
$upgradeStmt->bind_param('is', $_SESSION['user_id'], $now);
$upgradeStmt->execute();
$upgradeResult = $upgradeStmt->get_result();

if ($upgradeResult) {
    while ($row = $upgradeResult->fetch_assoc()) {
        $facility = new Facility($row['facility_id']);
        if ($facility->isValid() && $facility->completeUpgrade()) {
            // 获取设施所在的城池
            $city = new City($facility->getCityId());
            
            $completedUpgrades[] = [
                'facility_id' => $facility->getFacilityId(),
                'city_id' => $facility->getCityId(),
                'city_name' => $city->isValid() ? $city->getName() : '',
                'type' => $facility->getType(),
                'subtype' => $facility->getSubtype(),
                'name' => $facility->getName(),
                'level' => $facility->getLevel()
            ];
        }
    }
}

$upgradeStmt->close();

// 返回完成的建造和升级
echo json_encode([
    'success' => true,
    'completed_constructions' => $completedConstructions,
    'completed_upgrades' => $completedUpgrades
]);

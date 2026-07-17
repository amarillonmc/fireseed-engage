<?php
// 种火集结号 - 城池详情查询接口 / Fireseed Engage - city details query endpoint

require_once '../includes/init.php';
header('Content-Type: application/json; charset=UTF-8');

/**
 * 输出城池查询JSON并结束请求 / Emit city query JSON and end the request
 * @param array $payload 响应内容 / Response payload
 * @param int $status HTTP状态码 / HTTP status
 * @return void
 */
function sendCityInfoResponse($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    sendCityInfoResponse(['success' => false, 'message' => '未登录'], 401);
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendCityInfoResponse(['success' => false, 'message' => '仅支持GET请求'], 405);
}

$cityId = isset($_GET['city_id']) ? (int) $_GET['city_id'] : 0;
$city = $cityId > 0 ? new City($cityId) : null;
if (!$city || !$city->isValid() || (int) $city->getOwnerId() !== (int) $_SESSION['user_id']) {
    sendCityInfoResponse(['success' => false, 'message' => '城池不存在或不属于当前用户'], 404);
}

$facilities = [];
foreach ($city->getFacilities() as $facility) {
    $facilities[] = [
        'facility_id' => $facility->getFacilityId(),
        'type' => $facility->getType(),
        'subtype' => $facility->getSubtype(),
        'name' => $facility->getName(),
        'description' => $facility->getDescription(),
        'level' => $facility->getLevel(),
        'x_pos' => $facility->getXPos(),
        'y_pos' => $facility->getYPos(),
        'construction_time' => $facility->getConstructionTime(),
        'upgrade_time' => $facility->getUpgradeTime(),
        'is_under_construction' => $facility->getConstructionTime() !== null,
        'is_upgrading' => $facility->getUpgradeTime() !== null
    ];
}

$soldiers = [];
$usedCapacity = 0;
foreach ($city->getSoldiers() as $soldier) {
    $usedCapacity += $soldier->getQuantity() + $soldier->getInTraining();
    $soldiers[] = [
        'soldier_id' => $soldier->getSoldierId(),
        'type' => $soldier->getType(),
        'name' => $soldier->getName(),
        'level' => $soldier->getLevel(),
        'quantity' => $soldier->getQuantity(),
        'in_training' => $soldier->getInTraining(),
        'training_complete_time' => $soldier->getTrainingCompleteTime()
    ];
}

$coordinates = $city->getCoordinates();
$resource = new Resource((int) $_SESSION['user_id']);
$resources = [];
foreach (['bright', 'warm', 'cold', 'green', 'day', 'night'] as $resourceType) {
    $resources[$resourceType] = $resource->getResourceByType($resourceType);
}

$cityData = [
    'city_id' => $city->getCityId(),
    'name' => $city->getName(),
    'owner_id' => $city->getOwnerId(),
    'x' => $coordinates[0],
    'y' => $coordinates[1],
    'level' => $city->getLevel(),
    'durability' => $city->getDurability(),
    'max_durability' => $city->getMaxDurability(),
    'is_main_city' => $city->isMainCity(),
    'defense_power' => $city->getDefensePower(),
    'defense_strategy' => $city->getDefenseStrategy(),
    'soldier_capacity' => Facility::getCityTotalSoldierCapacity($cityId),
    'used_soldier_capacity' => $usedCapacity,
    'facilities' => $facilities,
    'soldiers' => $soldiers,
    'resources' => $resources
];

sendCityInfoResponse([
    'success' => true,
    'message' => '城池信息读取成功',
    'city' => $cityData
]);

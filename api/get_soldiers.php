<?php
// 种火集结号 - 城池士兵查询接口 / Fireseed Engage - city soldier query endpoint

require_once '../includes/init.php';
header('Content-Type: application/json; charset=UTF-8');

/**
 * 输出士兵查询JSON并结束请求 / Emit soldier query JSON and end the request
 * @param array $payload 响应内容 / Response payload
 * @param int $status HTTP状态码 / HTTP status
 * @return void
 */
function sendSoldierListResponse($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    sendSoldierListResponse(['success' => false, 'message' => '未登录'], 401);
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendSoldierListResponse(['success' => false, 'message' => '仅支持GET请求'], 405);
}

$cityId = isset($_GET['city_id']) ? (int) $_GET['city_id'] : 0;
$city = $cityId > 0 ? new City($cityId) : null;
if (!$city || !$city->isValid() || (int) $city->getOwnerId() !== (int) $_SESSION['user_id']) {
    sendSoldierListResponse(['success' => false, 'message' => '城池不存在或不属于当前用户'], 404);
}

$types = ['pawn', 'knight', 'rook', 'bishop', 'golem', 'scout'];
$facilityLevels = [
    'barracks' => 0,
    'workshop' => 0,
    'watchtower' => 0
];

foreach ($facilityLevels as $facilityType => $unusedLevel) {
    $facilities = Facility::getCityFacilitiesByType($cityId, $facilityType);
    foreach ($facilities as $facility) {
        if ($facility->getConstructionTime() === null && $facility->getUpgradeTime() === null) {
            $trainingLevel = $facilityType === 'barracks'
                ? $facility->getMaxSoldierLevel()
                : $facility->getLevel();
            $facilityLevels[$facilityType] = max($facilityLevels[$facilityType], $trainingLevel);
        }
    }
}

$soldiersByType = [];
$usedCapacity = 0;
foreach ($city->getSoldiers() as $soldier) {
    $usedCapacity += $soldier->getQuantity() + $soldier->getInTraining();
    $completionTimestamp = $soldier->getTrainingCompleteTime()
        ? strtotime($soldier->getTrainingCompleteTime())
        : 0;
    $soldiersByType[$soldier->getType()] = [
        'soldier_id' => $soldier->getSoldierId(),
        'type' => $soldier->getType(),
        'name' => $soldier->getName(),
        'level' => $soldier->getLevel(),
        'quantity' => $soldier->getQuantity(),
        'in_training' => $soldier->getInTraining(),
        'training_complete_time' => $soldier->getTrainingCompleteTime(),
        'remaining_seconds' => $completionTimestamp > 0
            ? max(0, $completionTimestamp - time())
            : 0
    ];
}

// 返回全部兵种，方便客户端稳定渲染空记录 / Return every type so clients can render empty records consistently
$orderedSoldiers = [];
$trainingCostBonuses = $city->getAssignedGeneralCityBonuses([
    'phase' => 'training'
]);
foreach ($types as $type) {
    $facilityType = Soldier::getTrainingFacilityType($type);
    $availableLevel = isset($facilityLevels[$facilityType]) ? $facilityLevels[$facilityType] : 0;
    if (!isset($soldiersByType[$type])) {
        $soldiersByType[$type] = [
            'soldier_id' => null,
            'type' => $type,
            'name' => getSoldierName($type),
            'level' => $availableLevel,
            'quantity' => 0,
            'in_training' => 0,
            'training_complete_time' => null,
            'remaining_seconds' => 0
        ];
    }

    $soldiersByType[$type]['training_available'] = $availableLevel > 0;
    $soldiersByType[$type]['training_facility'] = $facilityType;
    $soldiersByType[$type]['training_cost'] =
        Soldier::getAdjustedTrainingCost(
            $cityId,
            $type,
            1,
            $trainingCostBonuses
        );
    $orderedSoldiers[$type] = $soldiersByType[$type];
}

sendSoldierListResponse([
    'success' => true,
    'message' => '士兵信息读取成功',
    'city_id' => $cityId,
    'capacity' => Facility::getCityTotalSoldierCapacity($cityId),
    'used_capacity' => $usedCapacity,
    'soldiers' => $orderedSoldiers
]);

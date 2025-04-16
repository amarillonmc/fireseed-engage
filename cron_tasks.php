<?php
// 包含初始化文件
require_once 'includes/init.php';

// 设置脚本执行时间限制
set_time_limit(300); // 5分钟

// 记录开始时间
$startTime = microtime(true);
$logMessages = [];

$logMessages[] = "开始执行定时任务: " . date('Y-m-d H:i:s');

// 1. 检查并完成所有已完成建造的设施
$completedConstructions = Facility::checkAndCompleteConstruction();
$logMessages[] = "完成建造的设施数量: " . count($completedConstructions);

// 2. 检查并完成所有已完成升级的设施
$completedUpgrades = Facility::checkAndCompleteUpgrade();
$logMessages[] = "完成升级的设施数量: " . count($completedUpgrades);

// 3. 检查并完成所有已完成训练的士兵
$completedTrainings = Soldier::checkAndCompleteTraining();
$logMessages[] = "完成训练的士兵记录数量: " . count($completedTrainings);

// 4. 更新所有用户的资源产出
$query = "SELECT user_id FROM users";
$result = $db->query($query);

$updatedUsers = 0;
$circuitProducedCount = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $userId = $row['user_id'];

        // 更新资源产出
        if (Resource::updateResourceProduction($userId)) {
            $updatedUsers++;
        }

        // 更新思考回路产出
        $circuitProduced = Resource::updateCircuitProduction($userId);
        $circuitProducedCount += count($circuitProduced);
    }
}

$logMessages[] = "更新资源产出的用户数量: " . $updatedUsers;
$logMessages[] = "产出思考回路的城池数量: " . $circuitProducedCount;

// 5. 收集所有用户的资源点资源
$resourceCollector = new ResourceCollector();
$collectionResult = $resourceCollector->collectResourcesForAll();

$logMessages[] = "收集资源的用户数量: " . $collectionResult['successful_users'];
$totalCollected = 0;
foreach ($collectionResult['collection_results'] as $userResult) {
    $totalCollected += $userResult['total_collected'];
}
$logMessages[] = "收集的资源总量: " . $totalCollected;

// 6. 检查行军中的军队
$arrivedArmies = Army::checkMarchingArmies();
$logMessages[] = "到达目标的军队数量: " . count($arrivedArmies);

// 7. 检查返回中的军队
$returnedArmies = Army::checkReturningArmies();
$logMessages[] = "返回城池的军队数量: " . count($returnedArmies);

// 8. 检查待处理的战斗
$processedBattles = Battle::checkPendingBattles();
$logMessages[] = "处理的战斗数量: " . count($processedBattles);

// 9. 检查并重生NPC城池
$respawnedForts = Map::respawnAllNpcForts();
$logMessages[] = "重生的NPC城池数量: " . $respawnedForts;

// 记录结束时间和执行时间
$endTime = microtime(true);
$executionTime = $endTime - $startTime;
$logMessages[] = "定时任务执行完成，耗时: " . round($executionTime, 4) . " 秒";

// 将日志写入文件
$logContent = implode("\n", $logMessages);
file_put_contents('logs/cron_' . date('Y-m-d') . '.log', $logContent . "\n\n", FILE_APPEND);

// 输出日志
echo $logContent;

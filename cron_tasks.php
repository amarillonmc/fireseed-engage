<?php
// 包含初始化文件 / Include the application bootstrap
require_once __DIR__ . '/includes/init.php';

// 定时任务只允许由命令行执行 / Cron tasks may only run from the command line
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('仅允许命令行执行 / CLI only');
}

// 使用数据库锁防止并发结算 / Use a database lock to prevent concurrent settlement
$lockResult = executePreparedSql(
    $db,
    "SELECT GET_LOCK('fireseed_engage_cron', 0) AS acquired"
);
$lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
if (!$lockRow || (int) $lockRow['acquired'] !== 1) {
    exit("已有定时任务正在运行 / Another cron task is already running\n");
}
register_shutdown_function(function () use ($db) {
    executePreparedSql($db, "SELECT RELEASE_LOCK('fireseed_engage_cron')");
});

// 赛季原子重建可能需要处理完整512×512世界 / Atomic season reconstruction may process the full 512×512 world
set_time_limit(1800); // 30分钟 / 30 minutes

// 记录开始时间
$startTime = microtime(true);
$logMessages = [];

$logMessages[] = "开始执行定时任务: " . date('Y-m-d H:i:s');

// 先推进赛季状态，使本轮任务立即遵守冻结或新赛季状态 / Advance season state first so this run immediately honors a freeze or new season
$seasonService = new SeasonService();
$seasonResult = $seasonService->checkLifecycle();
$worldFrozen = isSeasonGameplayFrozen();
$logMessages[] = "赛季状态: " . $seasonResult['message'];

// 1-3.5. 冻结期暂停城池建造、升级、训练与研究结算 / Pause city construction, upgrades, training, and research settlement during a freeze
$completedConstructions = [];
$completedUpgrades = [];
$completedTrainings = [];
$completedResearch = [];
if (!$worldFrozen) {
    $completedConstructions = Facility::checkAndCompleteConstruction();
    $completedUpgrades = Facility::checkAndCompleteUpgrade();
    $completedTrainings = Soldier::checkAndCompleteTraining();
    $completedResearch = UserTechnology::checkAndCompleteResearch();
}
$logMessages[] = "完成建造的设施数量: " . count($completedConstructions);
$logMessages[] = "完成升级的设施数量: " . count($completedUpgrades);
$logMessages[] = "完成训练的士兵记录数量: " . count($completedTrainings);
$logMessages[] = "完成研究的科技数量: " . count($completedResearch);

// 4. 更新所有用户的资源产出
$query = "SELECT user_id FROM users";
$result = executePreparedSql($db, $query);

$updatedUsers = 0;
$circuitProducedCount = 0;
$recoveredGeneralCount = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $userId = $row['user_id'];

        // 冻结期暂停城市资源与思考回路产出结算 / Pause city resources and circuit production settlement during a freeze
        if (!$worldFrozen) {
            if (Resource::updateResourceProduction($userId)) {
                $updatedUsers++;
            }

            $circuitProduced = Resource::updateCircuitProduction($userId);
            $circuitProducedCount += count($circuitProduced);
        }

        // 结算离线武将HP回复 / Settle offline general HP recovery
        $progressionService = new GeneralProgression();
        $recoveryResult = $progressionService->recoverAllHp($userId);
        if (!empty($recoveryResult['success'])
            && isset($recoveryResult['progression']['recovered_count'])) {
            $recoveredGeneralCount += (int) $recoveryResult['progression']['recovered_count'];
        }
    }
}

$logMessages[] = "更新资源产出的用户数量: " . $updatedUsers;
$logMessages[] = "产出思考回路的城池数量: " . $circuitProducedCount;
$logMessages[] = "回复HP的武将数量: " . $recoveredGeneralCount;

// 5. 非冻结期收集所有用户的资源点资源 / Collect map resources only outside the season freeze
$collectionResult = [
    'successful_users' => 0,
    'collection_results' => []
];
if (!$worldFrozen) {
    $resourceCollector = new ResourceCollector();
    $collectionResult = $resourceCollector->collectResourcesForAll();
}

$logMessages[] = "收集资源的用户数量: " . $collectionResult['successful_users'];
$totalCollected = 0;
foreach ($collectionResult['collection_results'] as $userResult) {
    $totalCollected += $userResult['total_collected'];
}
$logMessages[] = "收集的资源总量: " . $totalCollected;

// 6. 仅在非冻结期维护讨伐战周期 / Maintain the recurring raid cycle only outside a freeze
$raidCycleResult = [
    'success' => true,
    'message' => '赛季冻结期间暂停 / Paused during the season freeze'
];
if (!$worldFrozen) {
    $challengeService = new ChallengeService();
    $raidCycleResult = $challengeService->maintainRaidCycle();
}
$logMessages[] = "讨伐战周期: " . $raidCycleResult['message'];

// 7. 仅在非冻结期派遣联盟协同作战 / Dispatch alliance operations only outside a freeze
$operationResult = [
    'success' => true,
    'message' => '赛季冻结期间暂停 / Paused during the season freeze',
    'data' => ['processed' => 0, 'dispatched' => 0]
];
if (!$worldFrozen) {
    $allianceService = new AllianceService();
    $operationResult = $allianceService->processDueOperations();
}
$operationData = isset($operationResult['data']) && is_array($operationResult['data'])
    ? $operationResult['data']
    : [];
$logMessages[] = "协同作战: "
    . $operationResult['message']
    . "（处理 "
    . (int) ($operationData['processed'] ?? 0)
    . "，派遣 "
    . (int) ($operationData['dispatched'] ?? 0)
    . "）";

// 8-12. 冻结期不推进任何行军、侦察、战斗或地图重生 / Do not advance marching, scouting, battles, or map respawns during a freeze
$arrivedArmies = [];
$resolvedScoutingMissions = [];
$returnedArmies = [];
$processedBattles = [];
$respawnedForts = 0;
if (!$worldFrozen) {
    $arrivedArmies = Army::checkMarchingArmies();
    // 侦察必须在到达后、返城与普通战斗之前结算 / Resolve scouting after arrivals and before returns or ordinary battles
    $scoutingService = new ScoutingService();
    $resolvedScoutingMissions = $scoutingService->processDueMissions();
    $returnedArmies = Army::checkReturningArmies();
    $processedBattles = Battle::checkPendingBattles();
    $respawnedForts = Map::respawnAllNpcForts();
}
$logMessages[] = "到达目标的军队数量: " . count($arrivedArmies);
$logMessages[] = "结算侦察任务数量: " . count($resolvedScoutingMissions);
$logMessages[] = "返回城池的军队数量: " . count($returnedArmies);
$logMessages[] = "处理的战斗数量: " . count($processedBattles);
$logMessages[] = "重生NPC城池数量: " . $respawnedForts;

// 13. 清理已失效的主动技能效果 / Remove expired active skill effects
executePreparedSql(
    $db,
    "DELETE FROM active_skill_effects WHERE expires_at <= NOW()"
);

// 记录结束时间和执行时间
$endTime = microtime(true);
$executionTime = $endTime - $startTime;
$logMessages[] = "定时任务执行完成，耗时: " . round($executionTime, 4) . " 秒";

// 将日志写入文件 / Write the log file
$logContent = implode("\n", $logMessages);
$logDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDirectory)) {
    mkdir($logDirectory, 0750, true);
}
$logPath = $logDirectory . DIRECTORY_SEPARATOR . 'cron_' . date('Y-m-d') . '.log';
file_put_contents($logPath, $logContent . "\n\n", FILE_APPEND | LOCK_EX);

// 输出日志
echo $logContent;

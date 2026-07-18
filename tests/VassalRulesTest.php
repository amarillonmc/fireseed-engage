<?php
// 种火集结号 - 附属流程规则与集成静态测试 / Fireseed Engage - Vassalage rules and integration static tests

require_once __DIR__ . '/../includes/classes/VassalService.php';

$assertions = 0;

/**
 * 断言严格相等 / Assert strict equality
 * @param mixed $expected 期望值 / Expected value
 * @param mixed $actual 实际值 / Actual value
 * @param string $message 失败消息 / Failure message
 * @return void
 */
function assertVassalSame($expected, $actual, $message) {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: "
            . var_export($expected, true)
            . "\nActual: "
            . var_export($actual, true)
        );
    }
}

/**
 * 断言条件为真 / Assert a true condition
 * @param bool $condition 条件 / Condition
 * @param string $message 失败消息 / Failure message
 * @return void
 */
function assertVassalTrue($condition, $message) {
    assertVassalSame(true, (bool) $condition, $message);
}

$balances = [
    'bright' => 1000,
    'warm_crystal' => 999,
    'cold' => 1,
    'green' => 0,
    'day' => -5,
    'night_crystal' => 2147483647
];
$tribute = VassalService::calculateTribute($balances, 0.70);
assertVassalSame(700, $tribute['bright'], '亮晶晶应缴70% / Bright tribute should be 70%');
assertVassalSame(699, $tribute['warm'], '贡金应向下取整 / Tribute should round down');
assertVassalSame(0, $tribute['cold'], '单单位资源向下取整为零 / One resource should floor to zero');
assertVassalSame(0, $tribute['green'], '零余额不产生贡金 / Zero balance yields no tribute');
assertVassalSame(0, $tribute['day'], '负余额按零处理 / Negative balances normalize to zero');
assertVassalSame(
    1503238552,
    $tribute['night'],
    '大额贡金计算不得溢出 / Large tribute calculation must not overflow'
);
assertVassalSame(
    1.0,
    VassalService::normalizeReleaseRate(4),
    '比例上界应为1 / Rate must clamp to one'
);
assertVassalSame(
    0.0,
    VassalService::normalizeReleaseRate(-1),
    '比例下界应为0 / Rate must clamp to zero'
);
assertVassalSame(
    0.70,
    VassalService::normalizeReleaseRate('invalid'),
    '无效比例回退70% / Invalid rate should fall back to 70%'
);
assertVassalSame(
    63,
    VassalService::calculateTribute(['bright' => 90], 0.70)['bright'],
    '贡金定点计算不得因浮点误差少扣 / Fixed-point tribute must not undercharge'
);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/includes/classes/VassalService.php');
$battle = file_get_contents($root . '/includes/classes/Battle.php');
$alliance = file_get_contents($root . '/includes/classes/AllianceService.php');
$season = file_get_contents($root . '/includes/classes/SeasonService.php');
$army = file_get_contents($root . '/includes/classes/Army.php');
$gameConfig = file_get_contents($root . '/includes/classes/GameConfig.php');
$economy = file_get_contents($root . '/includes/classes/EconomyService.php');
$mapClass = file_get_contents($root . '/includes/classes/Map.php');
$subBase = file_get_contents($root . '/includes/classes/SubBaseService.php');
$userClass = file_get_contents($root . '/includes/classes/User.php');
$freshSql = file_get_contents($root . '/sql/gameplay_expansion.sql');
$upgradeSql = file_get_contents(
    $root . '/sql/upgrade_20260717_gameplay_expansion.sql'
);
$configSql = file_get_contents($root . '/sql/game_config.sql');
$page = file_get_contents($root . '/vassal.php');
$rankingPage = file_get_contents($root . '/ranking.php');

foreach ([$freshSql, $upgradeSql] as $schema) {
    assertVassalTrue(
        strpos($schema, 'CREATE TABLE IF NOT EXISTS `vassal_relations`') !== false
            && strpos($schema, 'CASE WHEN `status` = \'active\'') !== false
            && strpos($schema, 'uq_vassal_relations_active_vassal') !== false
            && strpos(
                $schema,
                'FOREIGN KEY (`previous_force_owner_id`)'
                    . ' REFERENCES `users` (`user_id`) ON DELETE RESTRICT'
            ) !== false
            && strpos(
                $schema,
                'CREATE TABLE IF NOT EXISTS `vassal_rescue_eligibility`'
            ) !== false
            && strpos(
                $schema,
                'PRIMARY KEY (`relation_id`,`eligible_user_id`)'
            ) !== false,
        '新装与升级都必须约束唯一有效附属关系 / Fresh and upgrade schemas must constrain one active relation'
    );
}
foreach ([
    'vassal_release_resource_rate',
    'vassal_release_relocation_mode',
    'vassal_release_lose_all_territory',
    'vassal_release_refund_circuit'
] as $configKey) {
    assertVassalTrue(
        strpos($configSql, $configKey) !== false
            && strpos($upgradeSql, $configKey) !== false,
        "新装与升级都必须提供后台项 {$configKey} / Both schemas need {$configKey}"
    );
}

assertVassalTrue(
    strpos($battle, 'lockRelationsForUsers') !== false
        && strpos($battle, 'resolveMainCityCaptureInTransaction') !== false
        && strpos($battle, 'SET durability = max_durability') !== false,
    '战斗必须锁定附属关系并独立结算主城 / Battle must lock and resolve main-city vassalage'
);
assertVassalTrue(
    strpos($service, "status = 'rescued'") !== false
        && strpos($service, "status = 'redeemed'") !== false
        && strpos($service, "status = 'replaced'") !== false,
    '服务必须覆盖救出、赎身和改宗 / Service must cover rescue, redemption, and supersession'
);
assertVassalTrue(
    strpos($service, 'snapshotRescueEligibility') !== false
        && strpos($service, 'copyRescueEligibility') !== false
        && strpos(
            $service,
            'WHERE relation_id = ? AND eligible_user_id = ?'
        ) !== false,
    '救出资格必须在首次失守时固化并在再征服时复制 / Rescue eligibility must be frozen on first capture and copied on supersession'
);
$rescueMethodStart = strpos($service, 'private function isRescueAttacker');
$restoreMethodStart = strpos(
    $service,
    'private function restorePreviousAlliance',
    $rescueMethodStart
);
$rescueMethod = substr(
    $service,
    $rescueMethodStart,
    $restoreMethodStart - $rescueMethodStart
);
assertVassalTrue(
    strpos($rescueMethod, 'vassal_rescue_eligibility') !== false
        && strpos($rescueMethod, 'alliance_members') === false
        && strpos($rescueMethod, 'readActiveRelation') === false,
    '救出判定只能依照首次失守快照，不得随后世盟籍或附属变化 / Rescue decisions must use only the first-capture snapshot, not later membership or vassalage changes'
);
assertVassalTrue(
    strpos(
        $upgradeSql,
        'INNER JOIN alliance_members am'
            . "\n  ON am.alliance_id = vr.previous_alliance_id"
    ) !== false
        && strpos($upgradeSql, "WHERE vr.status = 'active'") !== false,
    '升级脚本必须为旧有效关系最佳努力回填救出资格 / Upgrade must best-effort backfill rescue eligibility for active legacy relations'
);
assertVassalTrue(
    strpos($service, "['outer', 'middle', 'subbase']") !== false
        && strpos($service, 'lockRandomRelocationTile') !== false
        && strpos($service, 'randomRegionCoordinates') !== false,
    '三种后台迁城模式必须进入权威服务 / All relocation modes must reach the authoritative service'
);
$relocationPosition = strpos($service, '$relocation = $this->relocateAfterReleaseLocked');
$resourceLockPosition = strpos($service, '$resourceRows = $this->lockResourceRows', $relocationPosition);
assertVassalTrue(
    $relocationPosition !== false
        && $resourceLockPosition !== false
        && $relocationPosition < $resourceLockPosition,
    '赎身资源必须按统一锁序最后锁定 / Release resources must be locked last'
);
$refundMethodStart = strpos($service, 'private function refundTerritoryCircuit');
$emptyAllianceMethodStart = strpos(
    $service,
    'private function removeEmptyPreviousAlliance',
    $refundMethodStart
);
$refundMethod = substr(
    $service,
    $refundMethodStart,
    $emptyAllianceMethodStart - $refundMethodStart
);
assertVassalTrue(
    strpos($service, 'mergeTerritoryGarrisonsIntoCity') !== false
        && strpos($service, 'reduceSeasonTerritoryScore') !== false
        && strpos($service, 'npc_territory_count') !== false
        && strpos($service, 'removedScoredSubBases') !== false
        && strpos($service, 'refundTerritoryCircuit') !== false
        && strpos($refundMethod, 'circuit_points = circuit_points + ?') !== false
        && strpos($refundMethod, 'max_circuit_points') === false
        && strpos($refundMethod, 'return $requested;') !== false,
    '领地清算必须返兵、扣分并配置化全额返还回路 / Territory settlement must return troops, reduce score, and fully refund configured Circuit investment'
);
assertVassalTrue(
    strpos($gameConfig, '$category = null') !== false
        && strpos($gameConfig, '$insertCategory') !== false,
    '后台保存必须保留已有附属配置分类 / Admin saves must preserve the vassalage category'
);
assertVassalTrue(
    strpos($alliance, '$vassalService->canUsersFight') !== false
        && strpos($army, 'Cannot attack yourself or a member of the same force') !== false
        && strpos($season, 'areUsersInSameForce') !== false,
    '世界战与赛季战必须共用有效势力 / World and season combat must share effective-force rules'
);
$leaveAllianceStart = strpos($alliance, 'public function leaveAlliance');
$createOperationStart = strpos(
    $alliance,
    'public function createOperation',
    $leaveAllianceStart
);
$leaveAllianceMethod = substr(
    $alliance,
    $leaveAllianceStart,
    $createOperationStart - $leaveAllianceStart
);
assertVassalTrue(
    strpos($alliance, '$this->lockUserRows([$userId])') !== false
        && strpos($alliance, 'lockRelationsForUsers($participantIds)') !== false
        && strpos(
            $leaveAllianceMethod,
            '$this->lockUserRows([$userId])'
        ) !== false
        && strpos(
            $leaveAllianceMethod,
            '$this->lockRelationsForUsers([$userId])'
        ) !== false
        && strpos($army, '$combatUserIds') !== false,
    '联盟接纳与派兵必须和主城征服共用玩家锁 / Alliance admission and dispatch must share player locks with conquest'
);
assertVassalTrue(
    strpos($service, 'defender_tile.owner_id = ?') !== false,
    '迁城必须取消以玩家领地为目标的待处理战斗 / Relocation must cancel pending battles against player territory'
);
assertVassalTrue(
    strpos(
        $service,
        'public function getEffectiveForceOwnerIds($userIds)'
    ) !== false
        && strpos($service, 'while (!empty($pendingIds))') !== false
        && substr_count(
            $service,
            'resolveEffectiveForceOwnerFromGraph('
        ) >= 3
        && strpos(
            $rankingPage,
            '$vassalService->getEffectiveForceOwnerIds('
        ) !== false
        && strpos(
            $season,
            '$vassalService->getEffectiveForceOwnerIds('
        ) !== false,
    '世界与赛季排行必须共用批量递归势力解析 / World and season rankings must share recursive batch force resolution'
);
assertVassalTrue(
    strpos($season, "'territory_score' => 0") !== false
        && strpos($season, "'raid_score' => 0") !== false
        && strpos($season, '$row[\'total_score\'] =') !== false
        && strpos($season, 'array_slice($ranking, 0, 50)') !== false,
    '赛季排行必须把附属全部积分聚合到宗主势力 / Season ranking must aggregate all vassal scores'
);
assertVassalTrue(
    strpos(
        $service,
        'public function getEffectiveForceChainUserIds($userIds)'
    ) !== false
        && strpos(
            $service,
            'public function getEffectiveForceChainUserIdsForUpdate('
        ) !== false
        && strpos(
            $service,
            'public function getEffectiveForceOwnerIdsForUpdate('
        ) !== false
        && strpos($battle, '$verifiedForceChainUserIds') !== false
        && strpos(
            $battle,
            'getEffectiveForceChainUserIdsForUpdate('
        ) !== false
        && strpos($battle, 'array_diff(') !== false
        && strpos(
            $battle,
            '$vassalService->lockRelationsForUsers('
        ) !== false
        && strpos(
            $battle,
            '$this->lockedForceOwnerIds'
        ) !== false,
    '战斗结算必须以当前加锁读复核任意深势力链 / Battle resolution must revalidate arbitrarily deep force chains with locking current reads'
);
assertVassalTrue(
    strpos($battle, 'SET circuit_points = GREATEST(') !== false
        && strpos($economy, 'SET circuit_points = GREATEST(') !== false
        && strpos($userClass, 'ordinary grants must never reduce') !== false
        && strpos($mapClass, 'SET circuit_points = circuit_points + ?') !== false
        && strpos($subBase, 'SET circuit_points = circuit_points + ?') !== false,
    '超上限退款不得被奖励反向削减，其他投入退款也必须全额归还 / Over-cap refunds must not be reduced by grants, and other investment refunds must remain exact'
);
assertVassalTrue(
    strpos($page, "name=\"confirm_release\"") !== false
        && strpos($page, '$overview[\'tribute\']') !== false
        && strpos($page, 'isSeasonGameplayFrozen()') !== false,
    '高风险脱离页面必须显示贡金、二次确认并遵守冻结 / Release UI must preview, confirm, and honor freeze'
);

echo "Vassal rules tests passed: {$assertions} assertions.\n";

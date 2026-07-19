<?php
// 种火集结号 - 科研与经济集成静态测试 / Fireseed Engage - Research and economy integration invariant tests

$root = dirname(__DIR__);
$files = [
    'technology' => '/includes/classes/Technology.php',
    'user_technology' => '/includes/classes/UserTechnology.php',
    'effect_service' => '/includes/classes/TechnologyEffectService.php',
    'card_pool_service' => '/includes/classes/CardPoolService.php',
    'resource' => '/includes/classes/Resource.php',
    'facility' => '/includes/classes/Facility.php',
    'city' => '/includes/classes/City.php',
    'army' => '/includes/classes/Army.php',
    'general' => '/includes/classes/General.php',
    'user' => '/includes/classes/User.php',
    'subbase' => '/includes/classes/SubBaseService.php',
    'build' => '/build.php',
    'facility_page' => '/facility.php',
    'soldier' => '/includes/classes/Soldier.php',
    'map' => '/includes/classes/Map.php',
    'battle' => '/includes/classes/Battle.php',
    'game_config' => '/includes/classes/GameConfig.php',
    'admin_config' => '/admin/config.php',
    'fresh_pool_sql' => '/sql/gameplay_expansion.sql',
    'upgrade_sql' => '/sql/upgrade_20260719_research_economy.sql'
];
$sources = [];
$assertions = 0;

foreach ($files as $name => $path) {
    $sources[$name] = file_get_contents($root . $path);
    if ($sources[$name] === false) {
        fwrite(STDERR, "FAIL: Unable to read {$path}\n");
        exit(1);
    }
}

/**
 * 断言条件成立 / Assert that a condition is true
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertResearchEconomy($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * 截取两个标记之间的源码 / Extract source between two markers
 * @param string $source 源码 / Source
 * @param string $startMarker 起始标记 / Start marker
 * @param string $endMarker 结束标记 / End marker
 * @return string 截取结果 / Extracted source
 */
function extractResearchSection($source, $startMarker, $endMarker) {
    $start = strpos($source, $startMarker);
    $end = $start === false ? false : strpos($source, $endMarker, $start);
    if ($start === false || $end === false || $end <= $start) {
        return '';
    }
    return substr($source, $start, $end - $start);
}

$constructionOptions = extractResearchSection(
    $sources['build'],
    'function getFacilityConstructionOptions()',
    'function deductConstructionResources'
);
assertResearchEconomy(
    $constructionOptions !== ''
        && strpos($constructionOptions, "'bright' =>") === false
        && strpos($constructionOptions, "'night' =>") === false,
    'Ordinary construction options must not spend Bright or Night Crystals'
);

$facilityUpgradeCosts = extractResearchSection(
    $sources['facility'],
    'public function getUpgradeCost()',
    'public function createFacility('
);
assertResearchEconomy(
    $facilityUpgradeCosts !== ''
        && strpos($facilityUpgradeCosts, "'bright' =>") === false
        && strpos($facilityUpgradeCosts, "'night' =>") === false,
    'Facility upgrades must not spend Bright or Night Crystals'
);

assertResearchEconomy(
    strpos($sources['effect_service'], "['warm', 'cold', 'green', 'day']") !== false
        && strpos($sources['effect_service'], "['bright', 'night']") !== false,
    'Research cost policy must separate seasonal and persistent currencies'
);
assertResearchEconomy(
    strpos($sources['technology'], "'scope' => 'seasonal'") !== false
        && strpos($sources['technology'], "'scope' => 'permanent'") !== false
        && strpos($sources['technology'], "'name' => '亮晶晶产量提升'") === false
        && strpos($sources['technology'], "'name' => '夜静静产量提升'") === false,
    'Default research must have two scopes and defer persistent-currency output research'
);
assertResearchEconomy(
    strpos($sources['user_technology'], 'hasValidCostPolicy()') !== false,
    'Research start must enforce its scope cost policy at runtime'
);
assertResearchEconomy(
    ($researchCompletion = extractResearchSection(
        $sources['user_technology'],
        'public function completeResearch()',
        'public static function getUserTechnologies'
    )) !== ''
        && strpos(
            $researchCompletion,
            'technology.max_level'
        ) !== false
        && strpos(
            $researchCompletion,
            'INNER JOIN technologies AS technology'
        ) !== false
        && strpos(
            $researchCompletion,
            'if ($currentLevel >= $maxLevel)'
        ) !== false
        && strpos(
            $researchCompletion,
            '$newLevel = min($maxLevel, $currentLevel + 1)'
        ) !== false,
    'Research completion must never exceed a technology cap after configuration changes'
);
assertResearchEconomy(
    strpos($sources['card_pool_service'], 'normalizePoolCostBundle') !== false
        && strpos(
            $sources['card_pool_service'],
            'General pools may consume only Bright Crystals'
        ) !== false
        && strpos(
            $sources['card_pool_service'],
            'Skill pools may consume only Night Crystals'
        ) !== false,
    'Runtime card-pool loading must enforce the Bright/Night currency boundary'
);

foreach ([
    'resource' => ['resource_production_', 'resource_storage'],
    'city' => ['training_speed', 'build_speed', 'city_defense'],
    'army' => ['soldier_attack', 'soldier_defense'],
    'general' => ['getMaxGeneralCost()'],
    'subbase' => ['getDerivedPlayerLimits']
] as $sourceName => $needles) {
    foreach ($needles as $needle) {
        assertResearchEconomy(
            strpos($sources[$sourceName], $needle) !== false,
            "{$sourceName} must apply technology integration {$needle}"
        );
    }
}

assertResearchEconomy(
    strpos($sources['resource'], '$persistentCapacity = 2147483647') !== false
        && strpos(
            $sources['resource'],
            "in_array(\$type, ['bright', 'night'], true)"
        ) !== false
        && strpos(
            $sources['facility'],
            "'persistent_resource_production_multiplier'"
        ) !== false,
    'Persistent currencies must use independent storage and slower configurable production'
);
foreach ([
    'build',
    'facility_page',
    'soldier',
    'user_technology',
    'map'
] as $walletMutationSource) {
    assertResearchEconomy(
        strpos($sources[$walletMutationSource], 'last_update =') === false,
        "{$walletMutationSource} wallet mutations must not erase accrued production time"
    );
}
assertResearchEconomy(
    strpos(
        $sources['map'],
        "getUserResourceStorageCapacity(\n                \$userId,\n                \$resourceType"
    ) !== false
        && strpos(
            $sources['battle'],
            "in_array(\$type, ['bright', 'night'], true)"
        ) !== false,
    'Map collection and battle loot must exempt Bright and Night from seasonal storage'
);

$allPlayerSync = extractResearchSection(
    $sources['effect_service'],
    'public static function synchronizeAllPlayerLimitsInCurrentTransaction()',
    "\n    }\n}"
);
assertResearchEconomy(
    $allPlayerSync !== ''
        && strpos($allPlayerSync, 'FOR UPDATE') !== false,
    'Bulk cap synchronization must lock players before calculating permanent effects'
);
$singlePlayerSync = extractResearchSection(
    $sources['effect_service'],
    'public static function synchronizePlayerLimits(',
    'public static function synchronizeAllPlayerLimits('
);
assertResearchEconomy(
    $singlePlayerSync !== ''
        && strpos($singlePlayerSync, 'SET max_circuit_points = ?') !== false
        && strpos($singlePlayerSync, 'circuit_points = LEAST') === false,
    'Cap synchronization must preserve refundable Circuit balances above the cap'
);
assertResearchEconomy(
    strpos(
        $sources['game_config'],
        'synchronizeAllPlayerLimitsInCurrentTransaction()'
    ) !== false
        && strpos(
            $sources['admin_config'],
            'synchronizeConfiguredPlayerLimits'
        ) === false,
    'Base-cap configuration and materialized player caps must share one transaction'
);
assertResearchEconomy(
    strpos(
        $sources['game_config'],
        "'persistent_resource_production_multiplier' => ["
    ) !== false
        && strpos(
            $sources['game_config'],
            "'max' => 1.0"
        ) !== false,
    'Persistent producer multiplier validation must match its slower-than-seasonal runtime cap'
);
assertResearchEconomy(
    strpos($sources['user'], '$this->db->begin_transaction()') !== false
        && strpos($sources['user'], '$this->db->commit()') !== false
        && strpos($sources['user'], 'initial_bright_crystal') !== false
        && strpos($sources['user'], 'initial_night_crystal') !== false,
    'Account and configured initial wallet creation must be atomic'
);
assertResearchEconomy(
    strpos($sources['user'], 'UPDATE users SET level = ? WHERE user_id = ?') !== false
        && strpos(
            $sources['user'],
            'SET level = ?, max_circuit_points = ?, max_general_cost = ?'
        ) === false,
    'Compatibility player level must not change progression caps'
);

foreach ([
    "'general_normal','general','常规契约','消耗亮晶晶的常驻武将契约。','{\"bright\":500}'",
    "'general_advanced','general','高级契约','消耗较多亮晶晶的高级武将契约。','{\"bright\":1500}'",
    "'general_resonance','general','亮晶共鸣','消耗亮晶晶的高阶武将契约。','{\"bright\":5000}'",
    "'skill_standard','skill','夜静技能卡池','消耗夜静静抽取技能卡的常驻卡池。','{\"night\":250}'"
] as $poolSeed) {
    assertResearchEconomy(
        strpos($sources['fresh_pool_sql'], $poolSeed) !== false,
        "Fresh card-pool seed must obey currency boundary: {$poolSeed}"
    );
}

assertResearchEconomy(
    strpos(
        $sources['upgrade_sql'],
        "`scope` enum(''seasonal'',''permanent'')"
    ) !== false
        && strpos(
            $sources['upgrade_sql'],
            "technology.`scope` = 'permanent'"
        ) !== false
        && strpos(
            $sources['upgrade_sql'],
            "`cost_json` = '{\"bright\":5000}'"
        ) !== false,
    'Upgrade must add research scope, materialize permanent caps, and migrate pool costs'
);

echo "Research economy invariant tests passed: {$assertions} assertions.\n";

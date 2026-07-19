<?php
// 种火集结号 - 全图可见、资源占领与赛季重置规则测试 / Fireseed Engage - full-map, resource occupation, and season reset rule tests

$root = dirname(__DIR__);
require_once $root . '/includes/classes/MapGenerator.php';

$map = file_get_contents($root . '/includes/classes/Map.php');
$battle = file_get_contents($root . '/includes/classes/Battle.php');
$generator = file_get_contents($root . '/includes/classes/MapGenerator.php');
$season = file_get_contents($root . '/includes/classes/SeasonService.php');
$seasonPage = file_get_contents($root . '/season.php');
$subBase = file_get_contents($root . '/includes/classes/SubBaseService.php');
$vassal = file_get_contents($root . '/includes/classes/VassalService.php');
$mapPage = file_get_contents($root . '/map.php');
$mapScript = file_get_contents($root . '/assets/js/map.js');
$mapSchema = file_get_contents($root . '/sql/map_tiles.sql');
$gameConfig = file_get_contents($root . '/sql/game_config.sql');
$upgradeSql = file_get_contents(
    $root . '/sql/upgrade_20260719_world_season.sql'
);
$assertions = 0;

/**
 * 断言世界与赛季规则 / Assert a world or season rule
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertWorldSeasonRule($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$weights = [
    'bright' => 4,
    'warm' => 23,
    'cold' => 23,
    'green' => 23,
    'day' => 23,
    'night' => 4
];
$quotas = MapGenerator::calculateWeightedQuotas(1000, $weights);
assertWorldSeasonRule(
    array_sum($quotas) === 1000,
    'Weighted resource quotas must assign every configured resource tile'
);
assertWorldSeasonRule(
    $quotas['bright'] < $quotas['warm']
        && $quotas['night'] < $quotas['day'],
    'Bright and night resource nodes must be rarer than seasonal nodes'
);

assertWorldSeasonRule(
    strpos($map, 'public static function exploreTiles') === false
        && strpos($mapPage, 'id="explore-btn"') === false
        && strpos($mapScript, 'api/explore_map.php') === false,
    'The retired map-exploration flow must not remain callable from the game UI'
);
assertWorldSeasonRule(
    strpos($mapSchema, '`is_visible` tinyint(1) NOT NULL DEFAULT 1') !== false
        && strpos(
            $mapSchema,
            '`occupation_circuit_cost` int(11) NOT NULL DEFAULT 0'
        ) !== false
        && strpos(
            $generator,
            "\$values[] = \"(\$x, \$y, 'empty', 1)\";"
        ) !== false,
    'Fresh and regenerated worlds must expose every tile'
);
assertWorldSeasonRule(
    strpos($upgradeSql, 'MODIFY `is_visible` tinyint(1) NOT NULL DEFAULT 1')
        !== false
        && strpos($upgradeSql, "SET `is_visible` = 1") !== false,
    'Existing installations must migrate every tile to public visibility'
);
assertWorldSeasonRule(
    strpos($upgradeSql, 'fireseed_legacy_empty_refund') !== false
        && strpos($upgradeSql, "`type` = 'empty'") !== false
        && strpos(
            $upgradeSql,
            'player.`circuit_points` + refund.`refund_amount`'
        ) !== false
        && substr_count(
            $upgradeSql,
            'fireseed_empty_refund_overflow_guard'
        ) >= 4,
    'Migration must refund legacy empty-tile Circuit investments without truncation'
);

$occupyStart = strpos($map, 'public static function occupyTile');
$abandonStart = strpos($map, 'public static function abandonTile');
$occupyMethod = $occupyStart !== false && $abandonStart !== false
    ? substr($map, $occupyStart, $abandonStart - $occupyStart)
    : '';
assertWorldSeasonRule(
    strpos($occupyMethod, "\$tile['type'] === 'resource'") !== false
        && strpos($occupyMethod, 'occupation_circuit_cost = ?') !== false
        && strpos($occupyMethod, '地图格子尚未被发现') === false,
    'Only resource occupation may charge Circuit and visibility may not block it'
);

$abandonEnd = strpos($map, 'private static function', $abandonStart);
$abandonMethod = $abandonStart !== false && $abandonEnd !== false
    ? substr($map, $abandonStart, $abandonEnd - $abandonStart)
    : '';
assertWorldSeasonRule(
    strpos($abandonMethod, "\$tile['type'] === 'resource'") !== false
        && strpos($abandonMethod, "\$tile['occupation_circuit_cost']") !== false
        && strpos($subBase, 'refunded_circuit_points') !== false
        && strpos($subBase, "\$tile['occupation_circuit_cost']") !== false
        && strpos($vassal, 'resource_territory_count') !== false,
    'Only released resource nodes and converted resource nodes may refund Circuit'
);
assertWorldSeasonRule(
    strpos($battle, 'consumeResourceOccupationCost') !== false
        && strpos($battle, 'consumeTerritoryOccupationCost') === false,
    'Battle capture must charge Circuit only for resource-node control'
);

foreach ([
    'map_resource_weight_bright',
    'map_resource_weight_warm',
    'map_resource_weight_cold',
    'map_resource_weight_green',
    'map_resource_weight_day',
    'map_resource_weight_night',
    'map_resource_tile_ratio',
    'map_resource_amount_min',
    'map_resource_amount_max',
    'map_npc_fort_tile_ratio',
    'map_npc_fort_weight_level_1',
    'map_npc_fort_weight_level_2',
    'map_npc_fort_weight_level_3',
    'map_npc_fort_weight_level_4',
    'map_npc_fort_weight_level_5',
    'map_npc_fort_weight_level_6',
    'map_npc_fort_weight_level_7',
    'map_npc_fort_weight_level_8',
    'map_npc_fort_weight_level_9',
    'season_start_bright_grant',
    'season_start_night_grant'
] as $configKey) {
    assertWorldSeasonRule(
        strpos($gameConfig, "'{$configKey}'") !== false
            && strpos($upgradeSql, "'{$configKey}'") !== false,
        "Fresh and upgraded configuration must seed {$configKey}"
    );
}
assertWorldSeasonRule(
    strpos($generator, "'map_resource_tile_ratio'") !== false
        && strpos($generator, "'map_resource_amount_min'") !== false
        && strpos($generator, "'map_resource_amount_max'") !== false
        && strpos($generator, "'map_npc_fort_tile_ratio'") !== false
        && strpos(
            $generator,
            "'map_npc_fort_weight_level_' . \$level"
        ) !== false
        && strpos($generator, 'FLOOR(5000 + RAND() * 5001)') === false
        && strpos($generator, '(MAP_WIDTH * MAP_HEIGHT) * 0.25') === false,
    'Provisional world-generation values must come from central configuration'
);

assertWorldSeasonRule(
    strpos($generator, 'regenerateMapInCurrentTransaction') !== false
        && strpos($generator, '$this->clearExistingWorld();') !== false
        && strpos($generator, '$this->db->begin_transaction();')
            < strpos($generator, '$this->clearExistingWorld();'),
    'World replacement must delete and regenerate inside a rollbackable transaction'
);

foreach ([
    'DELETE FROM general_assignments',
    'DELETE FROM armies',
    'DELETE FROM cities',
    "t.scope = 'seasonal'",
    'SET warm_crystal = ?',
    'warm_production_remainder = 0',
    'cold_production_remainder = 0',
    'green_production_remainder = 0',
    'day_production_remainder = 0',
    'skill_points = skill_points',
    'SET contribution = 0',
    'SET level = 1, experience = 0',
    'DELETE FROM alliance_applications',
    'DELETE FROM alliance_aid_log',
    'restoreVassalAllianceMembershipsForSeasonReset',
    'DELETE FROM vassal_relations',
    'DELETE FROM user_quests',
    'DELETE FROM gameplay_events',
    'DELETE FROM raid_participation',
    'DELETE FROM arena_battles',
    'DELETE FROM tower_progress',
    'regenerateMapInCurrentTransaction',
    'createInitialPlayerCityInCurrentTransaction',
    'season_start_bright_grant',
    'season_start_night_grant'
] as $resetFragment) {
    assertWorldSeasonRule(
        strpos($season, $resetFragment) !== false,
        "Season reset must include: {$resetFragment}"
    );
}
assertWorldSeasonRule(
    strpos($season, 'DELETE FROM generals') === false
        && strpos($season, 'DELETE FROM general_progression') === false
        && strpos($season, 'DELETE FROM user_skill_cards') === false
        && strpos($season, 'DELETE FROM user_items') === false,
    'Season reset must preserve card, general, skill, and long-term item progression'
);
assertWorldSeasonRule(
    strpos(
        $seasonPage,
        '账号、亮晶晶与夜静静、武将与技能卡成长'
    ) !== false
        && strpos(
            $seasonPage,
            '玩家武将、城池、资源与联盟关系在赛季重置后保留'
        ) === false,
    'Season page must explain the actual persistent and seasonal-reset assets'
);
assertWorldSeasonRule(
    strpos($season, 'bright_production_remainder = 0') === false
        && strpos($season, 'night_production_remainder = 0') === false,
    'Season reset must preserve Bright and Night fractional production'
);

echo "World and season rule tests passed: {$assertions} assertions.\n";

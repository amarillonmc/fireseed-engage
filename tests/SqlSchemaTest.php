<?php
// 种火集结号 - SQL 架构与升级脚本静态测试 / Fireseed Engage - static SQL schema and migration tests

$root = dirname(__DIR__);
$freshPath = $root . '/sql/gameplay_expansion.sql';
$upgradePath = $root . '/sql/upgrade_20260717_gameplay_expansion.sql';
$cardPoolUpgradePath = $root . '/sql/upgrade_20260717_card_pool_resources.sql';
$freshSql = file_get_contents($freshPath);
$baseUpgradeSql = file_get_contents($upgradePath);
$cardPoolUpgradeSql = file_get_contents($cardPoolUpgradePath);
$upgradeSql = $baseUpgradeSql === false || $cardPoolUpgradeSql === false
    ? false
    : $baseUpgradeSql . "\n" . $cardPoolUpgradeSql;
$assertions = 0;

/**
 * 断言条件成立 / Asserts that a condition is true
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败消息 / Failure message
 * @return void
 */
function assertSql($condition, $message) {
    global $assertions;
    $assertions++;

    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * 提取 CREATE TABLE IF NOT EXISTS 表名 / Extracts CREATE TABLE IF NOT EXISTS names
 *
 * @param string $sql SQL 文本 / SQL text
 * @return array 表名 / Table names
 */
function extractTables($sql) {
    preg_match_all(
        '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`([^`]+)`/i',
        $sql,
        $matches
    );
    return array_values(array_unique($matches[1]));
}

/**
 * 检查忽略字符串与注释后的括号平衡 / Checks parenthesis balance outside strings and comments
 *
 * @param string $sql SQL 文本 / SQL text
 * @return bool 是否平衡 / Whether balanced
 */
function hasBalancedSqlParentheses($sql) {
    $depth = 0;
    $inString = false;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if (!$inString && $character === '-' && $next === '-') {
            $newline = strpos($sql, "\n", $index + 2);
            if ($newline === false) {
                break;
            }
            $index = $newline;
            continue;
        }

        if ($character === "'") {
            if ($inString && $next === "'") {
                $index++;
                continue;
            }
            $inString = !$inString;
            continue;
        }

        if ($inString) {
            continue;
        }

        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
            if ($depth < 0) {
                return false;
            }
        }
    }

    return !$inString && $depth === 0;
}

assertSql($freshSql !== false, 'Fresh expansion SQL must be readable');
assertSql($baseUpgradeSql !== false, 'Base gameplay upgrade SQL must be readable');
assertSql($cardPoolUpgradeSql !== false, 'Card-pool resource upgrade SQL must be readable');
assertSql($upgradeSql !== false, 'Complete upgrade chain must be readable');
assertSql(hasBalancedSqlParentheses($freshSql), 'Fresh expansion SQL parentheses must balance');
assertSql(hasBalancedSqlParentheses($upgradeSql), 'Upgrade SQL parentheses must balance');
assertSql(
    !preg_match('/^\s*SOURCE\s+/mi', $upgradeSql),
    'Upgrade must not depend on a SOURCE statement'
);

$resourceSeedScripts = [
    'fresh install' => $freshSql,
    'card-pool upgrade' => $cardPoolUpgradeSql
];
foreach ($resourceSeedScripts as $scriptName => $schemaSql) {
    $catalogSeedMarker = 'resource_catalog_seed_20260717';
    $catalogInsertMatch = [];
    $hasCatalogInsert = preg_match(
        '/INSERT(?:\s+IGNORE)?\s+INTO\s+`skill_card_catalog`/i',
        $schemaSql,
        $catalogInsertMatch,
        PREG_OFFSET_CAPTURE
    ) === 1;
    $firstCatalogInsert = $hasCatalogInsert
        ? (int) $catalogInsertMatch[0][1]
        : false;
    $firstMarker = strpos($schemaSql, $catalogSeedMarker);
    $lastMarker = strrpos($schemaSql, $catalogSeedMarker);
    $poolInsertMatch = [];
    $hasPoolInsert = preg_match(
        '/INSERT(?:\s+IGNORE)?\s+INTO\s+`card_pools`/i',
        $schemaSql,
        $poolInsertMatch,
        PREG_OFFSET_CAPTURE
    ) === 1;
    $firstPoolInsert = $hasPoolInsert
        ? (int) $poolInsertMatch[0][1]
        : false;
    $skillPoolMemberMatch = [];
    $hasSkillPoolMemberInsert = preg_match(
        '/INSERT(?:\s+IGNORE)?\s+INTO\s+`skill_pool_entries`/i',
        $schemaSql,
        $skillPoolMemberMatch,
        PREG_OFFSET_CAPTURE
    ) === 1;
    $skillPoolMemberInsert = $hasSkillPoolMemberInsert
        ? (int) $skillPoolMemberMatch[0][1]
        : false;
    $seedTransactionStart = strrpos(
        substr($schemaSql, 0, $firstCatalogInsert),
        'START TRANSACTION;'
    );
    $seedCommit = strpos($schemaSql, 'COMMIT;', $lastMarker);

    assertSql(
        $firstCatalogInsert !== false
            && $firstMarker !== false
            && $lastMarker !== false
            && $firstPoolInsert !== false
            && $skillPoolMemberInsert !== false
            && $firstMarker !== $lastMarker
            && $lastMarker > $firstCatalogInsert
            && $lastMarker > $skillPoolMemberInsert,
        "{$scriptName} must wrap catalog boilerplate in a durable one-time seed marker"
    );
    assertSql(
        $seedTransactionStart !== false
            && $seedTransactionStart < $firstCatalogInsert
            && $seedCommit !== false
            && $seedCommit > $lastMarker,
        "{$scriptName} must commit catalog, pool, and completion-marker writes atomically"
    );
    assertSql(
        preg_match(
            '/NOT\s+EXISTS\s*\(.*?game_config.*?'
            . $catalogSeedMarker
            . '/is',
            $schemaSql
            ) === 1
            && preg_match(
                '/INSERT(?:\s+IGNORE)?\s+INTO\s+`?game_config`?'
                . '.*?'
                . $catalogSeedMarker
                . '.*?FROM\s+`fireseed_resource_seed_gate`'
                . '/is',
                $schemaSql
            ) === 1,
        "{$scriptName} must test and persist the catalog seed marker"
    );
    assertSql(
        strpos($schemaSql, 'fireseed_skill_card_seed') !== false
            && preg_match(
                '/INSERT(?:\s+IGNORE)?\s+INTO\s+`skill_card_catalog`'
                . '.*?FROM\s+`fireseed_skill_card_seed`\s+AS\s+seed'
                . '.*?JOIN\s+`fireseed_resource_seed_gate`\s+AS\s+seed_gate'
                . '.*?LEFT\s+JOIN\s+`skill_card_catalog`\s+AS\s+existing'
                . '.*?existing\.`card_id`\s+IS\s+NULL'
                . '/is',
                $schemaSql
            ) === 1,
        "{$scriptName} must not repopulate retired skill catalog seeds after completion"
    );
    assertSql(
        strpos($schemaSql, 'fireseed_general_seed_targets') !== false
            && preg_match(
                '/INSERT\s+INTO\s+`fireseed_general_seed_targets`'
                . '.*?JOIN\s+`fireseed_resource_seed_gate`\s+AS\s+seed_gate'
                . '.*?LEFT\s+JOIN\s+`general_template_catalog`\s+AS\s+catalog'
                . '.*?catalog\.`template_code`\s+IS\s+NULL'
                . '/is',
                $schemaSql
            ) === 1
            && substr_count(
                $schemaSql,
                'JOIN `fireseed_general_seed_targets` AS seed_target'
            ) >= 3,
        "{$scriptName} must restrict general boilerplate writes to first-run target codes"
    );
    $poolTargetSnapshot = strpos(
        $schemaSql,
        'INSERT INTO `fireseed_pool_seed_targets`'
    );
    $poolTargetSection = $poolTargetSnapshot === false
        || $firstPoolInsert === false
        || $poolTargetSnapshot >= $firstPoolInsert
        ? ''
        : substr(
            $schemaSql,
            $poolTargetSnapshot,
            $firstPoolInsert - $poolTargetSnapshot
        );
    $poolAbsenceCheck = preg_match(
        '/NOT\s+EXISTS\s*\(.*?FROM\s+`card_pools`/is',
        $poolTargetSection
    ) === 1
        || (
            strpos($poolTargetSection, 'LEFT JOIN `card_pools`') !== false
            && preg_match(
                '/existing\.`pool_id`\s+IS\s+NULL/i',
                $poolTargetSection
            ) === 1
        );
    assertSql(
        $poolTargetSnapshot !== false
            && $firstPoolInsert !== false
            && $poolTargetSnapshot < $firstPoolInsert
            && $poolAbsenceCheck
            && strpos(
                $poolTargetSection,
                'JOIN `fireseed_resource_seed_gate` AS seed_gate'
            ) !== false
            && substr_count(
                $schemaSql,
                'JOIN `fireseed_pool_seed_targets` AS seed_target'
            ) >= 3,
        "{$scriptName} must seed members only into default pools absent before this seed run"
    );
}

$freshTables = extractTables($freshSql);
$upgradeTables = extractTables($upgradeSql);
foreach ($freshTables as $table) {
    assertSql(
        in_array($table, $upgradeTables, true),
        "Upgrade must contain fresh-install table {$table}"
    );
}

foreach (['resources', 'cities', 'soldiers'] as $coreTable) {
    assertSql(
        in_array($coreTable, $upgradeTables, true),
        "Upgrade must create missing legacy core table {$coreTable}"
    );
}

foreach ([$freshSql, $upgradeSql] as $schemaSql) {
    assertSql(
        preg_match(
            '/CREATE TABLE IF NOT EXISTS `active_skill_effects`.*?PRIMARY KEY \(`skill_id`\).*?KEY `expires_at` \(`expires_at`\).*?FOREIGN KEY \(`skill_id`\).*?ON DELETE CASCADE.*?FOREIGN KEY \(`user_id`\).*?ON DELETE CASCADE.*?FOREIGN KEY \(`general_id`\).*?ON DELETE CASCADE/s',
            $schemaSql
        ) === 1,
        'Active skill effects must persist duration with cascading ownership links'
    );
}

foreach ([
    "table_name = 'general_skills' AND column_name = 'slot'",
    "table_name = 'map_tiles' AND column_name = 'npc_garrison'",
    "table_name = 'map_tiles' AND column_name = 'last_collection_time'",
    "ALTER TABLE `battles`",
    "ALTER TABLE `generals` MODIFY COLUMN `owner_id`"
] as $legacyUpgradeFragment) {
    assertSql(
        strpos($upgradeSql, $legacyUpgradeFragment) !== false,
        "Upgrade must include legacy operation: {$legacyUpgradeFragment}"
    );
}

$documentTemplates = [
    "'G001','白银之主','S',3.0,'亮晶晶',20,80,50,100",
    "'G002','晶光使者','A',2.0,'亮晶晶',15,60,40,80",
    "'G003','炎之剑客','S',3.0,'暖洋洋',100,20,80,50",
    "'G004','烈火战士','A',2.0,'暖洋洋',80,15,60,40",
    "'G005','冰霜守护者','S',3.0,'冷冰冰',50,100,20,50",
    "'G006','寒冰战士','A',2.0,'冷冰冰',40,80,15,40",
    "'G007','森林之王','S',3.0,'郁萌萌',100,20,80,50",
    "'G008','翠绿射手','A',2.0,'郁萌萌',80,15,60,40",
    "'G009','太阳神使','S',3.0,'昼闪闪',20,50,80,100",
    "'G010','光明祭司','A',2.0,'昼闪闪',15,40,60,80",
    "'G011','暗影大师','S',3.0,'夜静静',20,80,50,100",
    "'G012','夜行者','A',2.0,'夜静静',15,60,40,80",
    "'G013','数据之王','SS',3.5,'亮晶晶',30,90,60,120",
    "'G014','银白之孔守护者','P',4.0,'夜静静',40,100,70,150"
];

foreach ([$freshSql, $upgradeSql] as $schemaSql) {
    foreach ($documentTemplates as $template) {
        assertSql(
            strpos($schemaSql, $template) !== false,
            "Schema must seed documented template {$template}"
        );
    }

    foreach (['G002B', 'G004B', 'G006B', 'G008B', 'G010B', 'G012B'] as $bTemplate) {
        assertSql(
            strpos($schemaSql, "'{$bTemplate}'") !== false,
            "Schema must seed B-rarity general variant {$bTemplate}"
        );
    }

    foreach ([
        'training_acceleration_basic',
        'lightning_march_basic',
        'iron_wall_basic',
        'battle_burst_basic',
        'healing_basic',
        'scout_enhancement_basic'
    ] as $bCard) {
        assertSql(
            strpos($schemaSql, "'{$bCard}'") !== false,
            "Schema must seed B-rarity skill card {$bCard}"
        );
    }

    assertSql(
        strpos(
            $schemaSql,
            'INSERT INTO `equipped_skill_cards` (`skill_id`,`card_id`,`equipped_at`)'
        ) !== false,
        'Template inherent skills must be mapped to their skill cards'
    );

    assertSql(
        preg_match(
            '/`winner_id`\s+int\(11\)\s+DEFAULT NULL.*?FOREIGN KEY \(`winner_id`\).*?ON DELETE SET NULL/s',
            $schemaSql
        ) === 1,
        'Arena winner must allow NULL and use ON DELETE SET NULL'
    );
}

assertSql(
    strpos(
        $baseUpgradeSql,
        'fireseed_gameplay_general_seed_gate'
    ) !== false
        && strpos(
            $baseUpgradeSql,
            "WHERE `key` = 'resource_catalog_seed_20260717'"
        ) !== false
        && substr_count(
            $baseUpgradeSql,
            'JOIN `fireseed_gameplay_general_seed_gate` AS resource_gate'
        ) >= 6,
    'Base gameplay migration reruns must freeze legacy general seeds after the resource catalog marker'
);
assertSql(
    preg_match(
        '/UPDATE\s+`generals`\s+AS\s+general.*?'
        . 'JOIN\s+`fireseed_gameplay_general_seed_gate`/is',
        $baseUpgradeSql
    ) === 1
        && preg_match(
            '/UPDATE\s+`general_skills`\s+AS\s+skill.*?'
            . 'JOIN\s+`fireseed_gameplay_general_seed_gate`/is',
            $baseUpgradeSql
        ) === 1,
    'Legacy general and inherent-skill overwrites must both obey the catalog seed gate'
);

$resourceLockScripts = [
    'fresh install' => $freshSql,
    'card-pool upgrade' => $cardPoolUpgradeSql
];
foreach ($resourceLockScripts as $scriptName => $schemaSql) {
    assertSql(
        strpos(
            $schemaSql,
            'CREATE TABLE IF NOT EXISTS `resource_admin_locks`'
        ) !== false
            && strpos(
                $schemaSql,
                "VALUES ('catalog_pools')"
            ) !== false,
        "{$scriptName} must install the shared resource-administration mutex"
    );
}

$worldSites = [
    "'silver_hole','silver_hole','银白之孔',256,256",
    "'gateway_minjing','gateway','明京 Minjing',256,77",
    "'gateway_ninghai','gateway','宁海 Ninghai',346,101",
    "'gateway_wuyue','gateway','五岳 Wuyue',411,167",
    "'gateway_luhai','gateway','陆合 Luhai',435,256",
    "'gateway_misawa','gateway','米萨瓦 Misawa',411,346",
    "'gateway_kanata','gateway','卡拉塔 Kanata',346,411",
    "'gateway_yozora','gateway','约左拉 Yozora',256,435",
    "'gateway_naomi','gateway','娜奥美 Naomi',167,411",
    "'gateway_minster','gateway','明斯特尔 Minster',101,346",
    "'gateway_elise','gateway','艾尔利斯 Elise',77,256",
    "'gateway_redknife','gateway','雷德奈芙 Redknife',101,167",
    "'gateway_caeperra','gateway','开里培拉 Caeperra',167,101"
];

foreach ($worldSites as $worldSite) {
    assertSql(
        strpos($upgradeSql, $worldSite) !== false,
        "Upgrade must backfill world site {$worldSite}"
    );
}

assertSql(
    stripos($upgradeSql, 'DELETE FROM `map_tiles`') === false
        && stripos($upgradeSql, 'TRUNCATE TABLE `map_tiles`') === false,
    'World-site backfill must not wipe the existing map'
);
assertSql(
    strpos($upgradeSql, '`tile_id` = VALUES(`tile_id`)') !== false,
    'World-site upsert must keep site codes attached to canonical tiles'
);
assertSql(
    strpos($upgradeSql, 'fireseed_world_site_assignment') !== false
        && strpos($upgradeSql, 'LEFT JOIN `cities` AS city') !== false
        && substr_count($upgradeSql, 'tile.`owner_id` IS NULL') >= 13,
    'New world sites must be assigned only after excluding cities and player territories'
);
assertSql(
    strpos($upgradeSql, "WHERE tile.`subtype` = 'silver_hole'") !== false
        && strpos($upgradeSql, "site.`site_type` = 'silver_hole'") !== false
        && strpos($upgradeSql, 'fireseed_missing_world_site_guard') !== false,
    'Migration must deterministically reuse legacy Silver Holes and fail if a site has no safe tile'
);
assertSql(
    strpos($upgradeSql, "managed_site.`site_code` <> 'silver_hole'") !== false
        && strpos($upgradeSql, "DELETE FROM `world_sites`\nWHERE `site_type` = 'silver_hole'") !== false,
    'Migration must remove unmanaged or duplicate fake Silver Holes'
);
assertSql(
    strpos($upgradeSql, 'START TRANSACTION;') !== false
        && strpos($upgradeSql, 'COMMIT;') !== false,
    'Validated world-site changes must be applied atomically'
);

assertSql(
    strpos($upgradeSql, 'fireseed_resource_merge') !== false
        && strpos($upgradeSql, 'MIN(`resource_id`) AS `keep_resource_id`') !== false
        && substr_count($upgradeSql, 'MAX(`') >= 7
        && strpos($upgradeSql, 'DELETE duplicate_resource') !== false,
    'Duplicate resource rows must be merged deterministically before deletion'
);
assertSql(
    strpos(
        $upgradeSql,
        'ADD UNIQUE KEY `uq_resources_user_id` (`user_id`)'
    ) !== false,
    'Resource ownership must be protected by a forced unique index'
);
assertSql(
    substr_count(
        $upgradeSql,
        'INSERT INTO `fireseed_duplicate_main_city_guard` (`owner_id`)'
    ) === 2,
    'Duplicate legacy main cities must abort through an explicit double-insert guard'
);
assertSql(
    strpos($upgradeSql, 'GENERATED ALWAYS AS (CASE WHEN `is_main_city` = 1') !== false
        && strpos(
            $upgradeSql,
            'ADD UNIQUE KEY `uq_cities_one_main_city` (`main_city_owner_id`)'
        ) !== false,
    'Each user must be constrained to at most one main city'
);

assertSql(
    stripos($upgradeSql, 'CREATE PROCEDURE') === false
        && stripos($upgradeSql, 'DELIMITER //') === false,
    'Upgrade must not require stored-routine privileges or client delimiters'
);

echo "SQL schema static tests passed: {$assertions} assertions\n";

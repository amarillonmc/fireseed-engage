<?php
// 种火集结号 - 世界状态事务不变量测试 / Fireseed Engage - World-state transaction invariant tests

$root = dirname(__DIR__);
$city = file_get_contents($root . '/includes/classes/City.php');
$citiesSql = file_get_contents($root . '/sql/cities.sql');
$alliance = file_get_contents($root . '/includes/classes/AllianceService.php');
$index = file_get_contents($root . '/index.php');
$defense = file_get_contents($root . '/defense.php');
$assertions = 0;

/**
 * 断言世界状态不变量 / Assert a world-state invariant
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertWorldMutationInvariant($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$initialCityStart = strpos(
    $city,
    'public static function createInitialPlayerCity'
);
$initialFacilitiesStart = strpos(
    $city,
    'private static function createInitialFacilities',
    $initialCityStart
);
$initialCity = $initialCityStart !== false
    && $initialFacilitiesStart !== false
    ? substr($city, $initialCityStart, $initialFacilitiesStart - $initialCityStart)
    : '';

assertWorldMutationInvariant(
    strpos($initialCity, '$db->begin_transaction();') !== false
        && strpos($initialCity, 'lockSeasonForWorldAction($db);') !== false
        && strpos($initialCity, 'FROM users') !== false
        && substr_count($initialCity, 'FOR UPDATE') >= 3,
    'Initial-city creation must lock season, user, existing city, and candidate tile'
);
assertWorldMutationInvariant(
    strpos($initialCity, 'INSERT INTO cities') !== false
        && strpos($initialCity, "SET type = 'player_city'") !== false
        && strpos($initialCity, 'self::createInitialFacilities($cityId)') !== false
        && strpos($initialCity, '$db->commit();') !== false
        && strpos($initialCity, '$db->rollback();') !== false,
    'City, map tile, and initial facilities must share one atomic transaction'
);
assertWorldMutationInvariant(
    strpos($citiesSql, '`main_city_owner_id`') !== false
        && strpos($citiesSql, 'CASE WHEN `is_main_city` = 1') !== false
        && strpos($citiesSql, 'UNIQUE KEY `uq_cities_one_main_city`') !== false,
    'Fresh schema must enforce at most one main city for each user'
);

assertWorldMutationInvariant(
    substr_count(
        $alliance,
        'lockSeasonForWorldAction($this->db);'
    ) >= 4,
    'Alliance operation creation, joining, dispatch, and cleanup must lock the season'
);

foreach ([
    'challenges.php',
    'engage.php',
    'quests.php',
    'season.php',
    'shop.php'
] as $page) {
    $source = file_get_contents($root . '/' . $page);
    assertWorldMutationInvariant(
        strpos($source, 'if (!$user->isValid())') !== false
            && strpos($source, 'session_destroy();') !== false,
        "{$page} must reject a stale session whose user no longer exists"
    );
}

assertWorldMutationInvariant(
    strpos($defense, 'cities.php') === false,
    'Defense redirects must point to an existing page'
);
assertWorldMutationInvariant(
    strpos($index, 'isSeasonGameplayFrozen()') !== false
        && strpos($index, 'getSeasonGameplayFreezeMessage()') !== false,
    'Onboarding must explain a season freeze instead of reporting an installation error'
);

echo "World mutation invariant tests passed: {$assertions} assertions.\n";

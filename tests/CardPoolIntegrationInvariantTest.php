<?php
// 种火集结号 - 卡池后台与运行时整合静态测试 / Fireseed Engage - static card-pool administration and runtime integration tests

$root = dirname(__DIR__);
$files = [
    'init' => file_get_contents($root . '/includes/init.php'),
    'pool_service' => file_get_contents(
        $root . '/includes/classes/CardPoolService.php'
    ),
    'recruitment' => file_get_contents(
        $root . '/includes/classes/RecruitmentService.php'
    ),
    'skill_service' => file_get_contents(
        $root . '/includes/classes/SkillCardService.php'
    ),
    'admin_home' => file_get_contents($root . '/admin/index.php'),
    'admin_resources' => file_get_contents($root . '/admin/resources.php'),
    'admin_pools' => file_get_contents($root . '/admin/card_pools.php'),
    'recruit_page' => file_get_contents($root . '/recruit.php'),
    'skill_page' => file_get_contents($root . '/skills.php'),
    'fresh_sql' => file_get_contents($root . '/sql/gameplay_expansion.sql'),
    'upgrade_sql' => file_get_contents(
        $root . '/sql/upgrade_20260717_card_pool_resources.sql'
    )
];
$assertions = 0;

/**
 * 断言卡池整合条件 / Asserts a card-pool integration condition
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败消息 / Failure message
 * @return void
 */
function assertCardPoolIntegration($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ($files as $name => $contents) {
    assertCardPoolIntegration(
        $contents !== false,
        "Required card-pool file must be readable: {$name}"
    );
}

assertCardPoolIntegration(
    strpos($files['init'], 'CardPoolService.php') !== false
        && strpos($files['init'], 'CardPoolService.php')
            < strpos($files['init'], 'RecruitmentService.php')
        && strpos($files['init'], 'CardPoolService.php')
            < strpos($files['init'], 'SkillCardService.php'),
    'CardPoolService must load before both draw services'
);

foreach (['recruitment', 'skill_service'] as $serviceName) {
    $service = $files[$serviceName];
    assertCardPoolIntegration(
        strpos($service, 'lockPoolForDraw(') !== false
            && strpos($service, 'consumeCost(') !== false
            && strpos($service, 'selectWeightedEntry(') !== false,
        "{$serviceName} must draw and charge through CardPoolService"
    );
    assertCardPoolIntegration(
        strpos($service, 'ORDER BY RAND()') === false
            && strpos($service, 'getRarityFallbackOrder(') === false,
        "{$serviceName} must not retain uniform or cross-rarity fallback draws"
    );
    assertCardPoolIntegration(
        strpos($service, 'pool_code_snapshot') !== false
            && strpos($service, 'pool_revision') !== false
            && strpos($service, 'entry_weight') !== false
            && strpos($service, 'total_weight') !== false
            && strpos($service, 'cost_json') !== false,
        "{$serviceName} must persist an auditable pool snapshot"
    );
}

assertCardPoolIntegration(
    strpos($files['admin_home'], '数值层') !== false
        && strpos($files['admin_home'], '资源层') !== false
        && strpos($files['admin_home'], 'resources.php') !== false,
    'Admin home must visibly split numeric and resource layers'
);
assertCardPoolIntegration(
    strpos($files['admin_resources'], 'generals.php') !== false
        && strpos($files['admin_resources'], 'skills.php') !== false
        && strpos($files['admin_resources'], 'card_pools.php') !== false,
    'Resource layer must link both catalogs and pool management'
);
assertCardPoolIntegration(
    strpos($files['admin_pools'], "action === 'create_pool'") !== false
        && strpos($files['admin_pools'], "action === 'upsert_entry'") !== false
        && strpos($files['admin_pools'], "action === 'remove_entry'") !== false
        && strpos($files['admin_pools'], "action === 'publish_pool'") !== false
        && strpos($files['admin_pools'], "action === 'archive_pool'") !== false,
    'Pool administration must cover pool and member lifecycle actions'
);
assertCardPoolIntegration(
    strpos($files['admin_pools'], 'validateCsrfToken()') !== false
        && strpos($files['admin_pools'], 'begin_transaction()') !== false
        && strpos($files['admin_pools'], 'FOR UPDATE') !== false,
    'Pool mutations must use CSRF validation, transactions, and row locks'
);

foreach (['recruit_page', 'skill_page'] as $pageName) {
    assertCardPoolIntegration(
        strpos($files[$pageName], 'getDrawPools()') !== false
            && strpos($files[$pageName], "name=\"pool_id\"") !== false
            && strpos($files[$pageName], 'rarity_probabilities') !== false
            && strpos($files[$pageName], "['probability']") !== false,
        "{$pageName} must expose selectable pools and published odds"
    );
}

foreach (['fresh_sql', 'upgrade_sql'] as $schemaName) {
    $schema = $files[$schemaName];
    foreach ([
        'card_pools',
        'general_pool_entries',
        'skill_pool_entries',
        'pool_code_snapshot',
        'pool_revision',
        'entry_weight',
        'total_weight',
        'cost_json'
    ] as $fragment) {
        assertCardPoolIntegration(
            strpos($schema, $fragment) !== false,
            "{$schemaName} must contain schema fragment {$fragment}"
        );
    }
    foreach ([
        'general_normal',
        'general_advanced',
        'general_resonance',
        'skill_standard',
        'pawn_assault_doctrine',
        'bright_resonance_assault',
        'night_resonance_assault'
    ] as $seedCode) {
        assertCardPoolIntegration(
            strpos($schema, "'{$seedCode}'") !== false,
            "{$schemaName} must contain install seed {$seedCode}"
        );
    }
}

echo "Card-pool integration invariant tests passed: {$assertions} assertions\n";

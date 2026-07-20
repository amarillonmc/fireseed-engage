<?php
// 种火集结号 - 内测整合不变量测试 / Fireseed Engage - Internal-beta integration invariant tests

$root = dirname(__DIR__);
$assertions = 0;

/**
 * 断言内测整合不变量 / Assert an internal-beta integration invariant
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertInternalBetaInvariant($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$sources = [
    'config' => file_get_contents($root . '/config/config.php'),
    'installer' => file_get_contents($root . '/install.php'),
    'installer_authorization' => file_get_contents(
        $root . '/includes/installer_authorization.php'
    ),
    'database' => file_get_contents($root . '/includes/database.php'),
    'resource' => file_get_contents($root . '/includes/classes/Resource.php'),
    'map' => file_get_contents($root . '/includes/classes/Map.php'),
    'map_generator' => file_get_contents(
        $root . '/includes/classes/MapGenerator.php'
    ),
    'ranking' => file_get_contents($root . '/ranking.php'),
    'profile' => file_get_contents($root . '/profile.php'),
    'admin_users' => file_get_contents($root . '/admin/users.php'),
    'admin_map' => file_get_contents($root . '/admin/map.php'),
    'legacy_admin_map' => file_get_contents(
        $root . '/admin/generate_map.php'
    ),
    'admin_pools' => file_get_contents($root . '/admin/card_pools.php'),
    'research_api' => file_get_contents(
        $root . '/api/start_research.php'
    ),
    'resources_api' => file_get_contents(
        $root . '/api/get_user_resources.php'
    ),
    'research_migration' => file_get_contents(
        $root . '/sql/upgrade_20260719_research_economy.sql'
    ),
    'readme' => file_get_contents($root . '/README.md')
];
foreach ($sources as $name => $source) {
    assertInternalBetaInvariant(
        $source !== false,
        "Required source must be readable: {$name}"
    );
}

assertInternalBetaInvariant(
    strpos($sources['config'], 'your_password_here') === false
        && strpos($sources['config'], "DEBUG_MODE', true") === false
        && strpos($sources['config'], "getenv('FIRESEED_' . \$key)")
            !== false
        && strpos($sources['config'], "__DIR__ . '/local.php'") !== false,
    'Tracked configuration must not contain deployment secrets or debug-on defaults'
);

assertInternalBetaInvariant(
    strpos($sources['installer'], "__DIR__ . '/config/local.php'")
        !== false
        && strpos($sources['installer'], 'FIRESEED_INSTALL_TOKEN') !== false
        && strpos(
            $sources['installer'],
            'isDirectInstallerLoopbackRequest($_SERVER)'
        ) !== false
        && strpos(
            $sources['installer'],
            'resolveInstallerAuthorizationToken('
        ) !== false
        && strpos($sources['installer'], "\$_GET['install_token']") === false
        && strpos($sources['installer'], "fopen(\$tokenClaimPath, 'x')")
            !== false
        && strpos(
            $sources['installer'],
            '/config/.install-token-consumed'
        ) !== false
        && strpos(
            $sources['installer'],
            'session_regenerate_id(true)'
        ) !== false
        && strpos(
            $sources['installer'],
            "getenv('FIRESEED_TRUST_PROXY_HEADERS')"
        ) !== false
        && strpos(
            $sources['installer_authorization'],
            'function isDirectInstallerLoopbackRequest('
        ) !== false
        && strpos(
            $sources['installer_authorization'],
            'HTTP_X_FORWARDED_PROTO'
        ) !== false
        && strpos(
            $sources['installer_authorization'],
            'HTTP_X_FORWARDED_FOR'
        ) !== false
        && strpos(
            $sources['installer_authorization'],
            "'install-token.php'"
        ) !== false
        && strpos(
            $sources['installer'],
            'FIRESEED_ALLOW_INSECURE_LOCAL_INSTALL'
        ) === false
        && strpos($sources['installer'], '$hasSafeTokenTransport') !== false
        && strpos(
            $sources['installer'],
            "header('Location: install.php', true, 303)"
        ) !== false
        && strpos($sources['installer'], 'installer_csrf_token') !== false
        && strpos(
            $sources['installer'],
            'if (!Technology::initializeDefaultTechnologies())'
        ) !== false
        && strpos(
            $sources['installer'],
            "/includes/classes/GameConfig.php"
        ) !== false,
    'Installer must protect remote setup, write local secrets, and verify seed initialization'
);

assertInternalBetaInvariant(
    strpos($sources['database'], 'die("数据库连接失败: "') === false
        && strpos(
            $sources['database'],
            'Database connection failed: '
        ) !== false,
    'Database connection details must be logged rather than rendered'
);

assertInternalBetaInvariant(
    strpos(
        $sources['resource'],
        'SET $column = ?, last_update = ?'
    ) === false
        && strpos(
            $sources['resource'],
            'SET $column = $column + ?'
        ) !== false
        && strpos(
            $sources['resource'],
            'SET $column = $column - ?'
        ) !== false,
    'Wallet mutations must be atomic and must not erase accrued production time'
);

assertInternalBetaInvariant(
    strpos($sources['map'], 'new Map(null, $row)') !== false
        && strpos($sources['map'], 'LIMIT ? OFFSET ?') !== false
        && strpos($sources['map'], 'return true;') !== false,
    'Full visibility must use prefetched and paginated map reads'
);

assertInternalBetaInvariant(
    strpos(
        $sources['map_generator'],
        '$clockHour = $index + 1;'
    ) !== false
        && strpos(
            $sources['map_generator'],
            'deg2rad(($clockHour - 3) * 30)'
        ) !== false
        && strpos(
            $sources['map_generator'],
            'MAP_CENTER_X'
        ) !== false,
    'The named Twelve Gateways must map to one through twelve o’clock around the center'
);

assertInternalBetaInvariant(
    strpos($sources['ranking'], "'level' => '等级排行'") === false
        && strpos($sources['ranking'], "case 'level':") === false
        && strpos($sources['profile'], '$user->getLevel()') === false
        && strpos($sources['admin_users'], "value=\"update_level\"")
            === false,
    'Player level must remain a compatibility field rather than visible progression'
);

assertInternalBetaInvariant(
    strpos($sources['admin_map'], "case 'clear_map':") === false
        && strpos(
            $sources['legacy_admin_map'],
            "hasPermission('manage_map')"
        ) !== false,
    'Map administration must not expose an unsafe partial clear or bypass permissions'
);

assertInternalBetaInvariant(
    substr_count(
        $sources['admin_pools'],
        'CardPoolService::normalizePoolCostBundle('
    ) >= 3,
    'Card-pool create, edit, and publish paths must enforce currency policy'
);

assertInternalBetaInvariant(
    strpos($sources['research_api'], "'服务器错误: ' . \$e->getMessage()")
        === false
        && strpos(
            $sources['resources_api'],
            "'服务器错误: ' . \$e->getMessage()"
        ) === false,
    'Public APIs must not expose exception details'
);

assertInternalBetaInvariant(
    strpos(
        $sources['research_migration'],
        "'level_up_circuit_bonus'"
    ) !== false
        && strpos(
            $sources['research_migration'],
            "'level_up_general_cost_bonus'"
        ) !== false,
    'Upgrade migration must remove obsolete player-level growth settings'
);

$migrationOrder = [
    'upgrade_20260717_gameplay_expansion.sql',
    'upgrade_20260717_card_pool_resources.sql',
    'upgrade_20260718_image_resources.sql',
    'upgrade_20260719_world_season.sql',
    'upgrade_20260719_research_economy.sql'
];
$lastPosition = -1;
foreach ($migrationOrder as $migrationName) {
    $position = strpos($sources['readme'], $migrationName);
    assertInternalBetaInvariant(
        $position !== false && $position > $lastPosition,
        'README must document migrations in executable order'
    );
    $lastPosition = $position;
}
assertInternalBetaInvariant(
    strpos(
        $sources['readme'],
        '旧版 `config/config.php`'
    ) !== false
        && strpos($sources['readme'], '`config/local.php`') !== false
        && strpos($sources['readme'], '`0600`') !== false
        && strpos($sources['readme'], '`config/installed.lock`') !== false,
    'Legacy upgrade runbook must preserve runtime secrets and keep the installer locked'
);

echo "Internal-beta integration tests passed: {$assertions} assertions.\n";

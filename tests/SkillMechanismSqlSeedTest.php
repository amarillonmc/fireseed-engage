<?php
// 种火集结号 - 技能机制第二版SQL种子测试 / Fireseed Engage - skill-mechanism v2 SQL seed tests

$root = dirname(__DIR__);
$installSql = file_get_contents($root . '/install.php');
$freshGeneralSql = file_get_contents($root . '/sql/general_skills.sql');
$migrationSql = file_get_contents(
    $root . '/sql/upgrade_20260718_skill_mechanisms.sql'
);

require_once $root . '/includes/classes/SkillMechanismRegistry.php';
require_once $root . '/includes/classes/SkillValueResolver.php';
require_once $root . '/includes/classes/SkillDefinitionValidator.php';

$assertions = 0;

/**
 * 断言技能种子条件成立 / Asserts a skill-seed condition
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败消息 / Failure message
 * @return void
 */
function assertSkillSeed($condition, $message) {
    global $assertions;
    $assertions++;

    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertSkillSeed($installSql !== false, 'Installer must be readable');
assertSkillSeed($freshGeneralSql !== false, 'Fresh general-skill schema must be readable');
assertSkillSeed($migrationSql !== false, 'Skill-mechanism migration must be readable');
assertSkillSeed(
    strpos(
        $migrationSql,
        'upgrade_20260717_gameplay_expansion.sql'
    ) !== false
        && strpos(
            $migrationSql,
            'upgrade_20260717_card_pool_resources.sql'
        ) !== false,
    'Legacy migration instructions must name both required schema upgrades'
);

$gameplayPosition = strpos($installSql, "'sql/gameplay_expansion.sql'");
$mechanismPosition = strpos(
    $installSql,
    "'sql/upgrade_20260718_skill_mechanisms.sql'"
);
assertSkillSeed(
    $gameplayPosition !== false
        && $mechanismPosition !== false
        && $mechanismPosition > $gameplayPosition,
    'Fresh installer must run the mechanism seed after the catalog schema'
);
assertSkillSeed(
    strpos(
        $installSql,
        'isInstallerSqlServerPrepareCommand('
    ) !== false
        && strpos($installSql, '$db->query($statement)') !== false,
    'Fresh installer must execute SQL-level PREPARE controls outside the mysqli prepared protocol'
);
assertSkillSeed(
    strpos($freshGeneralSql, '`skill_name` varchar(100) NOT NULL') !== false,
    'Fresh general skills must allow 100-character catalog names'
);
assertSkillSeed(
    strpos(
        $migrationSql,
        '@fireseed_skill_name_length >= 100'
    ) !== false
        && strpos(
            $migrationSql,
            'MODIFY COLUMN `skill_name` varchar(100) NOT NULL'
        ) !== false,
    'Upgrade must widen only legacy skill-name columns below 100 characters'
);

$marker = 'me_skill_mechanism_seed_20260718';
assertSkillSeed(
    substr_count($migrationSql, $marker) >= 3
        && strpos($migrationSql, 'fireseed_me_skill_seed_gate') !== false
        && preg_match(
            '/NOT\s+EXISTS\s*\(.*?game_config.*?'
            . $marker
            . '/is',
            $migrationSql
        ) === 1,
    'Mechanism seed must use an independent one-time completion gate'
);
assertSkillSeed(
    preg_match(
        '/INSERT\s+INTO\s+`skill_card_catalog`.*?'
        . 'FROM\s+`fireseed_me_skill_seed`\s+AS\s+seed.*?'
        . 'JOIN\s+`fireseed_me_skill_seed_gate`\s+AS\s+seed_gate.*?'
        . 'LEFT\s+JOIN\s+`skill_card_catalog`\s+AS\s+existing.*?'
        . 'existing\.`card_id`\s+IS\s+NULL/is',
        $migrationSql
    ) === 1,
    'Seed must insert only absent card codes and never overwrite catalog rows'
);
$seedMutationOffset = strpos(
    $migrationSql,
    '-- 独立完成标记'
);
$seedMutationSql = $seedMutationOffset === false
    ? $migrationSql
    : substr($migrationSql, $seedMutationOffset);
assertSkillSeed(
    stripos($seedMutationSql, 'UPDATE `skill_card_catalog`') === false
        && stripos($seedMutationSql, 'ON DUPLICATE KEY UPDATE') === false,
    'Mechanism migration must not overwrite operator-edited catalog data'
);
assertSkillSeed(
    strpos($migrationSql, 'HH:MM') !== false
        && strpos($migrationSql, 'never inferred as effect duration') !== false,
    'Migration must document the source CT conversion without inventing duration'
);
assertSkillSeed(
    strpos($migrationSql, '`skill_pool_entries`') === false,
    'Catalog migration must not mutate an operator-managed skill pool'
);

$seedSectionMatches = [];
assertSkillSeed(
    preg_match(
        '/INSERT\s+INTO\s+`fireseed_me_skill_seed`.*?VALUES\s*'
        . '(.*?)\s*;\s*INSERT\s+INTO\s+`skill_card_catalog`/is',
        $migrationSql,
        $seedSectionMatches
    ) === 1,
    'Migration must contain a parseable mechanism seed section'
);

$seedRows = [];
preg_match_all(
    "/^\\('([^']+)','([^']*)','([^']*)','([^']+)',"
        . "'([^']+)','(active|passive)','([^']+)',"
        . "'(\\{.*\\})',(\\d+),(\\d+)\\)[,;]?$/m",
    trim($seedSectionMatches[1]),
    $seedRows,
    PREG_SET_ORDER
);
assertSkillSeed(
    count($seedRows) === 13,
    'Migration must seed all thirteen selected passive and instant source skills'
);

$expectedNames = [
    '剣士の士気',
    '司祭の士気',
    '騎士の士気',
    '疾駆の心得',
    '大地の士気',
    '太陽の士気',
    '星の士気',
    '月の士気',
    '防戦準備',
    'マナの洗礼',
    '女神の慈愛',
    '拠点増強',
    '詠唱短縮'
];

// 按稳定卡代码锁定每张基础种子的机制契约，不耦合描述或数值曲线。 / Lock each base seed's mechanism contract by stable card code without coupling descriptions or value curves.
$expectedDefinitions = [
    'me_rook_morale' => [
        'mechanism' => 'army_stat_percent',
        'parameters' => ['stat' => 'attack', 'unit_type' => 'rook']
    ],
    'me_bishop_morale' => [
        'mechanism' => 'army_stat_percent',
        'parameters' => ['stat' => 'attack', 'unit_type' => 'bishop']
    ],
    'me_knight_morale' => [
        'mechanism' => 'army_stat_percent',
        'parameters' => ['stat' => 'attack', 'unit_type' => 'knight']
    ],
    'me_march_expertise' => [
        'mechanism' => 'army_stat_percent',
        'parameters' => ['stat' => 'speed', 'unit_type' => 'all']
    ],
    'me_earth_morale' => [
        'mechanism' => 'army_element_stat_percent',
        'parameters' => ['element' => 'green', 'stat' => 'attack']
    ],
    'me_sun_morale' => [
        'mechanism' => 'army_element_stat_percent',
        'parameters' => ['element' => 'day', 'stat' => 'attack']
    ],
    'me_star_morale' => [
        'mechanism' => 'army_element_stat_percent',
        'parameters' => ['element' => 'bright', 'stat' => 'attack']
    ],
    'me_moon_morale' => [
        'mechanism' => 'army_element_stat_percent',
        'parameters' => ['element' => 'night', 'stat' => 'attack']
    ],
    'me_defense_preparation' => [
        'mechanism' => 'city_defense_percent',
        'parameters' => []
    ],
    'me_mana_baptism' => [
        'mechanism' => 'grant_resources',
        'parameters' => ['resource' => 'all']
    ],
    'me_goddess_charity' => [
        'mechanism' => 'heal_generals',
        'parameters' => ['target' => 'all_owned']
    ],
    'me_base_reinforcement' => [
        'mechanism' => 'repair_assigned_city',
        'parameters' => []
    ],
    'me_casting_reduction' => [
        'mechanism' => 'reduce_skill_cooldowns',
        'parameters' => ['target' => 'unassigned_owned']
    ]
];
$seenNames = [];
$seenCardCodes = [];

foreach ($seedRows as $row) {
    $cardCode = $row[1];
    $name = $row[2];
    $activationType = $row[6];
    $effectJson = $row[8];
    $baseCooldown = (int) $row[9];
    $maxLevel = (int) $row[10];
    $definition = json_decode($effectJson, true);

    assertSkillSeed(
        isset($expectedDefinitions[$cardCode]),
        "Seed card code {$cardCode} must belong to the selected base set"
    );
    assertSkillSeed(
        !isset($seenCardCodes[$cardCode]),
        "Seed card code {$cardCode} must be unique"
    );
    $seenCardCodes[$cardCode] = true;
    assertSkillSeed(
        $maxLevel === 10,
        "Seed {$cardCode} must preserve the source ten-level progression"
    );
    assertSkillSeed(
        is_array($definition) && json_last_error() === JSON_ERROR_NONE,
        "Seed {$cardCode} must contain valid JSON"
    );
    $validation = SkillDefinitionValidator::validate(
        $definition,
        $maxLevel,
        $activationType,
        false
    );
    assertSkillSeed(
        $validation['valid'],
        "Seed {$cardCode} must pass the central version-two validator: "
            . implode('; ', $validation['errors'])
    );
    assertSkillSeed(
        isset($definition['schema_version'])
            && $definition['schema_version'] === 2,
        "Seed {$cardCode} must use schema_version 2"
    );
    assertSkillSeed(
        !isset($definition['duration'])
            && $definition['application_mode'] !== 'timed'
            && $definition['application_mode'] !== 'next_dispatch'
            && $definition['application_mode'] !== 'dispatch_snapshot',
        "Seed {$cardCode} must be passive-continuous or active-instant"
    );
    $expectedDefinition = $expectedDefinitions[$cardCode];
    $seedEffects = isset($definition['effects'])
        && is_array($definition['effects'])
        ? $definition['effects']
        : [];
    assertSkillSeed(
        count($seedEffects) === 1,
        "Seed {$cardCode} must contain its one classified base mechanism"
    );
    $seedEffect = isset($seedEffects[0]) && is_array($seedEffects[0])
        ? $seedEffects[0]
        : [];
    assertSkillSeed(
        isset($seedEffect['mechanism'])
            && $seedEffect['mechanism'] === $expectedDefinition['mechanism'],
        "Seed {$cardCode} must use classified mechanism "
            . $expectedDefinition['mechanism']
    );
    assertSkillSeed(
        isset($seedEffect['parameters'])
            && $seedEffect['parameters'] == $expectedDefinition['parameters'],
        "Seed {$cardCode} must preserve its classified mechanism parameters"
    );

    if ($activationType === 'active') {
        $resolvedCooldown = SkillValueResolver::resolve(
            $definition['cooldown'],
            ['skill_level' => 1]
        );
        assertSkillSeed(
            $definition['application_mode'] === 'instant'
                && $resolvedCooldown === (float) $baseCooldown,
            "Active seed {$cardCode} must keep Lv1 CT in cooldown only"
        );
        if ($cardCode === 'me_casting_reduction') {
            assertSkillSeed(
                $definition['effects'][0]['parameters']['target']
                    === 'unassigned_owned',
                'Casting Reduction must explicitly adapt source waiting generals to unassigned owned generals'
            );
        }
    } else {
        assertSkillSeed(
            $definition['application_mode'] === 'continuous'
                && !isset($definition['cooldown'])
                && $baseCooldown === 0,
            "Passive seed {$cardCode} must be continuously active without CT"
        );
    }

    $seenNames[] = $name;
}

sort($expectedNames);
sort($seenNames);
assertSkillSeed(
    $seenNames === $expectedNames,
    'Seed names must exactly match the selected source skill names'
);
$expectedCardCodes = array_keys($expectedDefinitions);
$actualCardCodes = array_keys($seenCardCodes);
sort($expectedCardCodes);
sort($actualCardCodes);
assertSkillSeed(
    $actualCardCodes === $expectedCardCodes,
    'Seed card codes must exactly match the thirteen classified base skills'
);

echo "Skill mechanism SQL seed tests passed: {$assertions} assertions\n";

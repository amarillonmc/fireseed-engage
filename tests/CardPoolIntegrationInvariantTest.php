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
    'general_skill' => file_get_contents(
        $root . '/includes/classes/GeneralSkill.php'
    ),
    'general' => file_get_contents($root . '/includes/classes/General.php'),
    'functions' => file_get_contents($root . '/includes/functions.php'),
    'admin_home' => file_get_contents($root . '/admin/index.php'),
    'admin_resources' => file_get_contents($root . '/admin/resources.php'),
    'admin_pools' => file_get_contents($root . '/admin/card_pools.php'),
    'admin_generals' => file_get_contents($root . '/admin/generals.php'),
    'admin_skills' => file_get_contents($root . '/admin/skills.php'),
    'recruit_page' => file_get_contents($root . '/recruit.php'),
    'skill_page' => file_get_contents($root . '/skills.php'),
    'generals_page' => file_get_contents($root . '/generals.php'),
    'general_detail_page' => file_get_contents($root . '/general_detail.php'),
    'installer' => file_get_contents($root . '/install.php'),
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

/**
 * 提取独立的PHP函数源码 / Extracts standalone PHP function source
 *
 * @param string $source PHP源码 / PHP source
 * @param string $functionName 函数名 / Function name
 * @return string|null 函数源码 / Function source
 */
function extractCardPoolTestFunction($source, $functionName) {
    $start = strpos($source, 'function ' . $functionName . '(');
    if ($start === false) {
        return null;
    }

    $openingBrace = strpos($source, '{', $start);
    if ($openingBrace === false) {
        return null;
    }

    $depth = 0;
    $length = strlen($source);
    for ($index = $openingBrace; $index < $length; $index++) {
        if ($source[$index] === '{') {
            $depth++;
        } elseif ($source[$index] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $index - $start + 1);
            }
        }
    }

    return null;
}

foreach ($files as $name => $contents) {
    assertCardPoolIntegration(
        $contents !== false,
        "Required card-pool file must be readable: {$name}"
    );
}

$sqlSplitterSource = extractCardPoolTestFunction(
    $files['installer'],
    'splitInstallerSqlStatements'
);
assertCardPoolIntegration(
    $sqlSplitterSource !== null,
    'Fresh installer must provide a quote-and-comment-aware SQL splitter'
);
if ($sqlSplitterSource !== null
    && !function_exists('splitInstallerSqlStatements')) {
    eval($sqlSplitterSource);
}
$splitSql = <<<'SQL'
-- leading comment; this semicolon is not a delimiter
INSERT INTO `demo;table` (`value`) VALUES ('a;b', "c;d");
# another; comment
/* block; comment */
START TRANSACTION;
INSERT INTO demo (`value`) VALUES ('it''s;safe', 'slash\\;safe');
COMMIT;
-- trailing; comment only
SQL;
$splitStatements = splitInstallerSqlStatements($splitSql);
assertCardPoolIntegration(
    count($splitStatements) === 4
        && strpos($splitStatements[0], "'a;b'") !== false
        && strpos($splitStatements[0], '`demo;table`') !== false
        && strpos($splitStatements[2], "'it''s;safe'") !== false
        && stripos($splitStatements[1], 'START TRANSACTION') !== false
        && stripos($splitStatements[3], 'COMMIT') !== false,
    'Installer SQL splitter must ignore semicolons inside quotes and SQL comments'
);
$triggerStatements = splitInstallerSqlStatements(
    "DROP TRIGGER IF EXISTS fireseed_prod_test;\n"
    . "CREATE TRIGGER fireseed_prod_test\n"
    . "AFTER INSERT ON resources\n"
    . "FOR EACH ROW INSERT INTO audit_log VALUES (NEW.user_id);"
);
assertCardPoolIntegration(
    count($triggerStatements) === 2
        && stripos($triggerStatements[0], 'DROP TRIGGER') !== false
        && stripos($triggerStatements[1], 'CREATE TRIGGER') !== false,
    'Installer SQL splitter must emit each single-statement trigger DDL separately'
);

$serverPrepareDetectorSource = extractCardPoolTestFunction(
    $files['installer'],
    'isInstallerSqlServerPrepareCommand'
);
assertCardPoolIntegration(
    $serverPrepareDetectorSource !== null,
    'Fresh installer must identify SQL-level prepared-statement controls'
);
if ($serverPrepareDetectorSource !== null
    && !function_exists('isInstallerSqlServerPrepareCommand')) {
    eval($serverPrepareDetectorSource);
}
assertCardPoolIntegration(
    isInstallerSqlServerPrepareCommand(
        "-- bundled DDL control / 内置DDL控制\n"
        . 'PREPARE fireseed_stmt FROM @fireseed_ddl'
    )
        && isInstallerSqlServerPrepareCommand(
            'EXECUTE fireseed_stmt'
        )
        && isInstallerSqlServerPrepareCommand(
            'DEALLOCATE PREPARE fireseed_stmt'
        )
        && isInstallerSqlServerPrepareCommand(
            'DROP TRIGGER IF EXISTS fireseed_prod_test'
        )
        && isInstallerSqlServerPrepareCommand(
            "CREATE TRIGGER fireseed_prod_test\n"
            . "AFTER INSERT ON resources\n"
            . "FOR EACH ROW INSERT INTO audit_log VALUES (NEW.user_id)"
        )
        && !isInstallerSqlServerPrepareCommand(
            'SELECT * FROM skill_card_catalog'
        )
        && !isInstallerSqlServerPrepareCommand(
            "SELECT 1\n"
            . "CREATE TRIGGER fireseed_prod_test\n"
            . "AFTER INSERT ON resources\n"
            . "FOR EACH ROW INSERT INTO audit_log VALUES (NEW.user_id)"
        )
        && !isInstallerSqlServerPrepareCommand(
            "SELECT 1\nPREPARE fireseed_stmt FROM @fireseed_ddl"
        )
        && !isInstallerSqlServerPrepareCommand(
            "DROP TRIGGER IF EXISTS fireseed_prod_test\nSELECT 1"
        ),
    'Only one complete bundled SQL control or trigger DDL statement may bypass mysqli prepare'
);

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
    strpos(
        $files['pool_service'],
        'catalog.template_code'
    ) !== false
        && strpos(
            $files['pool_service'],
            'LEFT JOIN general_template_catalog catalog'
        ) !== false
        && strpos(
            $files['pool_service'],
            "empty(\$row['template_code'])"
        ) !== false,
    'Published general-pool entries must expose an authoritative template code'
);
assertCardPoolIntegration(
    strpos(
        $files['recruitment'],
        "'template_code' => (string) \$template['template_code']"
    ) !== false
        && strpos(
            $files['recruitment'],
            "\$ownedGeneral['template_code'] = (string) \$template['template_code'];"
        ) !== false,
    'Starter, new-draw, and duplicate-draw payloads must expose template codes'
);
assertCardPoolIntegration(
    substr_count(
        $files['recruitment'],
        'SELECT gs.skill_name'
    ) >= 2
        && strpos(
            $files['recruitment'],
            "\$ownedGeneral['skill_name']"
        ) !== false
        && strpos(
            $files['recruitment'],
            "'skill_name' => isset(\$template['skill_name'])"
        ) !== false
        && strpos(
            $files['pool_service'],
            'SELECT gs.skill_name'
        ) !== false
        && strpos(
            $files['pool_service'],
            "\$row['skill_name']"
        ) !== false,
    'Starter, draw, duplicate, and published-pool cards must expose inherent skill names'
);
assertCardPoolIntegration(
    strpos($files['admin_generals'], '$nameLength > 100') !== false
        && strpos(
            $files['admin_generals'],
            '固有技能的100字符上限'
        ) !== false
        && strpos($files['admin_generals'], '$nameLength > 50') === false,
    'General-template mapping must accept the catalog-wide 100-character skill-name limit'
);

assertCardPoolIntegration(
    strpos($files['general_skill'], 'LEFT JOIN equipped_skill_cards') !== false
        && strpos($files['general_skill'], 'LEFT JOIN skill_card_catalog') !== false
        && strpos($files['general_skill'], 'catalog_name') !== false
        && strpos($files['general_skill'], 'catalog_effect_json') !== false
        && preg_match(
            '/hasCatalogMapping.{0,240}catalog_effect_json'
            . '.{0,500}hasCatalogCard.{0,240}catalog_name/is',
            $files['general_skill']
        ) === 1,
    'Mapped general skills must use authoritative catalog names and effects'
);
assertCardPoolIntegration(
    strpos(
        $files['general_skill'],
        'public function isCatalogCardDisabled()'
    ) !== false
        && strpos($files['general_skill'], 'catalog_is_active') !== false
        && strpos($files['general_skill'], '!$hasCatalogCard') !== false,
    'GeneralSkill must expose disabled or missing mapped catalog cards'
);
foreach (['generals_page', 'general_detail_page'] as $pageName) {
    assertCardPoolIntegration(
        strpos($files[$pageName], 'isCatalogCardDisabled()') !== false
            && strpos($files[$pageName], 'Disabled') !== false,
        "{$pageName} must label disabled mapped skill cards"
    );
    assertCardPoolIntegration(
        preg_match(
            '/escapeHtml\(\s*\$skill->getSkillName\(\)\s*\)/',
            $files[$pageName]
        ) === 1
            && preg_match(
                '/escapeHtml\(\s*\$skill->getSkillType\(\)\s*\)/',
                $files[$pageName]
            ) === 1,
        "{$pageName} must escape administrator-editable skill labels"
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
assertCardPoolIntegration(
    strpos(
        $files['functions'],
        'function lockResourceAdministrationBoundary('
    ) !== false
        && strpos($files['functions'], 'resource_admin_locks') !== false
        && strpos($files['functions'], "lock_name = 'catalog_pools'") !== false
        && strpos($files['functions'], 'FOR UPDATE') !== false,
    'Catalog and pool mutations must share a durable transaction mutex'
);
assertCardPoolIntegration(
    substr_count($files['admin_pools'], 'begin_transaction()') > 0
        && substr_count(
            $files['admin_pools'],
            'lockResourceAdministrationBoundary($db);'
        ) === substr_count($files['admin_pools'], 'begin_transaction()'),
    'Every pool-administration transaction must acquire the shared mutex first'
);
assertCardPoolIntegration(
    strpos(
        $files['general'],
        'lockResourceAdministrationBoundary($this->db);'
    ) !== false
        && strpos($files['general'], '(int) $this->ownerId === 0') !== false,
    'Public general-template deletion must share the pool-administration mutex'
);

foreach (['admin_resources', 'admin_pools'] as $pageName) {
    assertCardPoolIntegration(
        preg_match(
            '/if\s*\(\s*!\s*\$user->isValid\(\)\s*\)\s*\{'
            . '.*?session_unset\(\)\s*;'
            . '.*?session_destroy\(\)\s*;'
            . ".*?header\(\s*'Location:\s*login\\.php'\s*\)\s*;"
            . '.*?exit\s*;.*?\}/s',
            $files[$pageName]
        ) === 1,
        "{$pageName} must clear an invalid login session before redirecting"
    );
}

$catalogPoolRules = [
    'admin_generals' => [
        'load_helper' => 'adminGeneralLoadPublishedPoolsForUpdate',
        'touch_helper' => 'adminGeneralTouchPublishedPools',
        'entry_table' => 'general_pool_entries',
        'resource_key' => 'general_id'
    ],
    'admin_skills' => [
        'load_helper' => 'adminSkillLoadPublishedPoolsForUpdate',
        'touch_helper' => 'adminSkillTouchPublishedPools',
        'entry_table' => 'skill_pool_entries',
        'resource_key' => 'card_id'
    ]
];
foreach ($catalogPoolRules as $pageName => $rule) {
    $page = $files[$pageName];
    $loadHelperSource = extractCardPoolTestFunction(
        $page,
        $rule['load_helper']
    );
    assertCardPoolIntegration(
        $loadHelperSource !== null
            && substr_count($page, $rule['load_helper'] . '(') >= 2
            && strpos($page, $rule['entry_table']) !== false
            && strpos($page, $rule['resource_key']) !== false
            && strpos($loadHelperSource, 'pool.status') !== false
            && strpos(
                $loadHelperSource,
                "pool.status = 'published'"
            ) === false
            && strpos($loadHelperSource, 'FOR UPDATE') !== false
            && strpos(
                $loadHelperSource,
                "\$pool['status'] === 'published'"
            ) !== false,
        "{$pageName} must lock every containing pool before selecting published pools"
    );
    assertCardPoolIntegration(
        substr_count($page, 'begin_transaction()') > 0
            && substr_count(
                $page,
                'lockResourceAdministrationBoundary($db);'
            ) === substr_count($page, 'begin_transaction()'),
        "{$pageName} catalog transactions must acquire the shared mutex first"
    );
    assertCardPoolIntegration(
        strpos($page, "hasPermission('publish_card_pools')") !== false
            && preg_match(
                '/rarity.{0,240}!==.{0,240}rarity/is',
                $page
            ) === 1,
        "{$pageName} must require publish permission when rarity changes affect published odds"
    );
    assertCardPoolIntegration(
        strpos($page, 'function ' . $rule['touch_helper'] . '(') !== false
            && substr_count($page, $rule['touch_helper'] . '(') >= 2
            && preg_match(
                '/UPDATE\s+card_pools\s+SET\s+revision\s*=\s*revision\s*\+\s*1/is',
                $page
            ) === 1,
        "{$pageName} must increment affected published-pool revisions"
    );
}

$disableSkillStart = strpos(
    $files['admin_skills'],
    "\$action === 'disable_skill'"
);
$disableSkillSection = $disableSkillStart === false
    ? ''
    : substr($files['admin_skills'], $disableSkillStart, 5000);
assertCardPoolIntegration(
    $disableSkillSection !== ''
        && strpos(
            $disableSkillSection,
            'adminSkillLoadPublishedPoolsForUpdate('
        ) !== false
        && strpos($disableSkillSection, 'DomainException') !== false
        && strpos($disableSkillSection, 'UPDATE skill_card_catalog') !== false
        && strpos($disableSkillSection, 'adminSkillLoadPublishedPoolsForUpdate(')
            < strpos($disableSkillSection, 'UPDATE skill_card_catalog'),
    'Skill retirement must be rejected before writing when a published pool references the card'
);

assertCardPoolIntegration(
    strpos(
        $files['admin_skills'],
        'SkillDefinitionValidator::validate('
    ) !== false
        && strpos(
            $files['admin_skills'],
            '$allowLegacy && $definitionMode'
        ) !== false,
    'Catalog writes must delegate every curve mode to the central definition validator'
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
assertCardPoolIntegration(
    strpos($files['skill_page'], '$inventoryCardDisabled') !== false
        && strpos(
            $files['skill_page'],
            '库存记录保留但不能新装备'
        ) !== false,
    'Disabled inventory cards must be labeled and excluded from equip controls'
);

foreach (['fresh_sql', 'upgrade_sql'] as $schemaName) {
    $schema = $files[$schemaName];
    foreach ([
        'card_pools',
        'general_pool_entries',
        'skill_pool_entries',
        'resource_admin_locks',
        "'catalog_pools'",
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

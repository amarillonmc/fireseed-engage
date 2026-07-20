<?php
// 种火集结号 - 服务层安全不变量静态测试 / Fireseed Engage - Service safety invariant static tests

$assertions = 0;

/**
 * 断言服务源码必须满足的安全不变量 / Assert a required service-source invariant
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertServiceInvariant($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$progress = file_get_contents($root . '/includes/classes/ProgressService.php');
$economy = file_get_contents($root . '/includes/classes/EconomyService.php');
$skillCards = file_get_contents($root . '/includes/classes/SkillCardService.php');
$general = file_get_contents($root . '/includes/classes/General.php');
$database = file_get_contents($root . '/includes/database.php');
$season = file_get_contents($root . '/includes/classes/SeasonService.php');
$mapGenerator = file_get_contents($root . '/includes/classes/MapGenerator.php');
$challenge = file_get_contents($root . '/includes/classes/ChallengeService.php');
$alliance = file_get_contents($root . '/includes/classes/AllianceService.php');
$recruitment = file_get_contents($root . '/includes/classes/RecruitmentService.php');
$cron = file_get_contents($root . '/cron_tasks.php');
$startResearch = file_get_contents($root . '/api/start_research.php');
$trainSoldiers = file_get_contents($root . '/api/train_soldiers.php');
$buildPage = file_get_contents($root . '/build.php');
$facilityPage = file_get_contents($root . '/facility.php');
$moveArmyPage = file_get_contents($root . '/move_army.php');
$defensePage = file_get_contents($root . '/defense.php');
$functions = file_get_contents($root . '/includes/functions.php');
$adminGenerals = file_get_contents($root . '/admin/generals.php');
$adminSkills = file_get_contents($root . '/admin/skills.php');
$getGeneralApi = file_get_contents($root . '/api/get_general.php');
$getSkillApi = file_get_contents($root . '/api/get_skill.php');
$challengesPage = file_get_contents($root . '/challenges.php');
$gameRules = file_get_contents($root . '/includes/classes/GameRules.php');
$install = file_get_contents($root . '/install.php');
$configLoader = file_get_contents($root . '/config/config.php');
$versionConfig = file_get_contents($root . '/config/version.php');
$gitignore = file_get_contents($root . '/.gitignore');
$localConfigExample = file_get_contents(
    $root . '/config/local.php.example'
);
$freshSql = file_get_contents($root . '/sql/gameplay_expansion.sql');
$upgradeSql = file_get_contents(
    $root . '/sql/upgrade_20260717_gameplay_expansion.sql'
);
$territoryGarrison = file_get_contents(
    $root . '/includes/classes/TerritoryGarrisonService.php'
);
$deployGarrison = file_get_contents($root . '/api/deploy_garrison.php');
$withdrawGarrison = file_get_contents($root . '/api/withdraw_garrison.php');
$battle = file_get_contents($root . '/includes/classes/Battle.php');
$map = file_get_contents($root . '/includes/classes/Map.php');
$army = file_get_contents($root . '/includes/classes/Army.php');
$getMap = file_get_contents($root . '/api/get_map.php');
$attackTarget = file_get_contents($root . '/api/attack_target.php');
$battleReportPage = file_get_contents($root . '/battle_report.php');
$battleReportApi = file_get_contents($root . '/api/get_battle_report.php');
$imageResources = file_get_contents($root . '/includes/image_resources.php');
$gameConfig = file_get_contents($root . '/includes/classes/GameConfig.php');
$adminConfig = file_get_contents($root . '/admin/config.php');
$init = file_get_contents($root . '/includes/init.php');

assertServiceInvariant(
    strpos($progress, 'IF(progress + ? >= ?') === false,
    'Quest completion must not add the same event twice'
);
assertServiceInvariant(
    strpos($progress, 'unlocked_at IS NULL AND progress + ? >= ?') === false,
    'Achievement unlock must not add the same event twice'
);
assertServiceInvariant(
    substr_count($progress, 'IF(progress >= ?,') >= 1,
    'Quest completion must evaluate the newly assigned progress'
);
assertServiceInvariant(
    substr_count($progress, 'unlocked_at IS NULL AND progress >= ?') >= 1,
    'Achievement unlock must evaluate the newly assigned progress'
);

assertServiceInvariant(
    strpos($skillCards, 'WHERE card_id = ? AND is_active = 1') !== false,
    'Equipping must reject disabled catalog cards'
);
assertServiceInvariant(
    strpos($skillCards, 'AND c.is_active = 1') !== false,
    'Mapped-skill mutation must reject disabled catalog cards'
);
assertServiceInvariant(
    strpos($skillCards, 'A general at zero HP cannot activate skills') !== false,
    'Zero-HP generals must not activate skills'
);
assertServiceInvariant(
    strpos($general, "if ((int) \$row['is_active'] !== 1)") !== false,
    'Disabled mapped cards must contribute no general bonus'
);
assertServiceInvariant(
    strpos($general, "['主动', '主动技能', 'active']") !== false,
    'Every supported legacy active-skill label must fail closed'
);
assertServiceInvariant(
    strpos($general, 'function getSkillEffectTotal') !== false,
    'Special skill-effect keys must have a bounded consumer API'
);
assertServiceInvariant(
    strpos($general, 'private $templateCode;') !== false
        && strpos($general, 'public function getTemplateCode()') !== false,
    'General objects must expose their authoritative template code'
);
assertServiceInvariant(
    strpos($general, 'LEFT JOIN general_template_catalog direct_catalog') !== false
        && strpos($general, 'FROM recruitment_history history') !== false
        && strpos(
            $general,
            'source_catalog.general_id = history.template_general_id'
        ) !== false,
    'Owned generals must resolve template codes through recruitment history'
);
assertServiceInvariant(
    preg_match(
        '/\\$stmt = \\$this->db->prepare\\(\\$query\\);\\s+if \\(!\\$stmt\\) \\{\\s+return \\[\\];/s',
        $general
    ) === 1,
    'Skill-table query failures must contribute no bonus'
);
assertServiceInvariant(
    strpos($skillCards, "getSkillEffectTotal('skill_power'") !== false,
    'Skill power must modify active-skill calculations'
);
assertServiceInvariant(
    strpos($progress, "getSkillEffectTotal(\n                'quest_reward'") !== false,
    'Quest-reward skill effects must modify claimed rewards'
);

assertServiceInvariant(
    strpos($database, "SET SESSION time_zone = '+08:00'") !== false,
    'Runtime database sessions must match PHP time'
);
assertServiceInvariant(
    strpos($database, 'function executePreparedSql') !== false
        && strpos($database, '$stmt = $db->prepare($sql);') !== false
        && strpos($database, '$stmt->close();') !== false,
    'Parameterless SQL must still use a closed prepared statement'
);
foreach ([
    'cron' => $cron,
    'alliance' => $alliance,
    'economy' => $economy,
    'map generator' => $mapGenerator,
    'progress' => $progress,
    'season' => $season,
    'installer' => $install
] as $sourceName => $source) {
    assertServiceInvariant(
        strpos($source, '->query(') === false,
        "{$sourceName} mutations and reads must not bypass prepared statements"
    );
}
assertServiceInvariant(
    strpos($freshSql, "SET SESSION time_zone = '+08:00'") !== false,
    'Fresh schema seeding must use the application time zone'
);
assertServiceInvariant(
    strpos($upgradeSql, "SET SESSION time_zone = '+08:00'") !== false,
    'Upgrade seeding must use the application time zone'
);

assertServiceInvariant(
    preg_match(
        '/FROM armies\s+WHERE army_id = \?\s+FOR UPDATE/s',
        $season
    ) === 1,
    'Season assaults must lock and revalidate the army'
);
assertServiceInvariant(
    preg_match(
        '/FROM army_units\s+WHERE army_id = \?\s+FOR UPDATE/s',
        $season
    ) === 1,
    'Season assaults must lock their troop composition'
);
assertServiceInvariant(
    strpos($season, "DELETE FROM battles WHERE result = 'pending'") !== false,
    'Season reset must cancel old-season pending battles'
);
assertServiceInvariant(
    strpos($season, "SET status = 'cancelled'") !== false,
    'Season reset must cancel unresolved alliance operations'
);
assertServiceInvariant(
    substr_count($challenge, 'lockCombatArmies([') >= 2,
    'Arena and Tower resolution must lock live army compositions'
);
assertServiceInvariant(
    strpos($challenge, 'function maintainRaidCycle') !== false,
    'Raid events must have a recurring lifecycle'
);
assertServiceInvariant(
    strpos($alliance, 'getOperationTargetCoordinates(') !== false,
    'Alliance-operation coordinates must be server-derived'
);
assertServiceInvariant(
    strpos(
        $cron,
        'DELETE FROM active_skill_effects WHERE expires_at <= NOW()'
    ) !== false,
    'Cron must clean up expired active effects'
);
assertServiceInvariant(
    strpos($cron, 'maintainRaidCycle()') !== false
        && strpos($cron, 'processDueOperations()') !== false,
    'Cron must drive raid and alliance-operation lifecycles'
);
assertServiceInvariant(
    strpos($startResearch, 'validateCsrfToken(') !== false,
    'Research mutations must validate a CSRF token'
);
assertServiceInvariant(
    strpos($functions, 'function getSeasonGameplayLockState') !== false
        && strpos($functions, "\$season['status'] === 'reset_pending'") !== false,
    'Season settlement must expose one central city/map freeze boundary'
);
foreach ([
    'construction' => $buildPage,
    'facility upgrade' => $facilityPage,
    'army movement' => $moveArmyPage,
    'soldier training' => $trainSoldiers,
    'research' => $startResearch,
    'city defense' => $defensePage
] as $operationName => $source) {
    assertServiceInvariant(
        strpos($source, 'isSeasonGameplayFrozen()') !== false,
        ucfirst($operationName) . ' must honor the season freeze'
    );
}
assertServiceInvariant(
    strpos($season, 'function quiesceWorldOperationsForFreeze') !== false
        && strpos($season, "DELETE FROM battles WHERE result = 'pending'") !== false
        && strpos($season, "WHERE status IN ('marching', 'returning')") !== false,
    'Entering season freeze must quiesce unresolved world actions'
);
$lifecyclePosition = strpos($cron, '$seasonService->checkLifecycle()');
$allianceDispatchPosition = strpos($cron, '$allianceService->processDueOperations()');
assertServiceInvariant(
    $lifecyclePosition !== false
        && $allianceDispatchPosition !== false
        && $lifecyclePosition < $allianceDispatchPosition
        && strpos($cron, 'if (!$worldFrozen)') !== false,
    'Cron must advance season state before gated world lifecycles'
);

assertServiceInvariant(
    strpos($adminGenerals, 'function synchronizeAdminInherentSkill') !== false
        && strpos($adminGenerals, "AND owner_id = 0") !== false
        && strpos($adminGenerals, 'INSERT INTO equipped_skill_cards') !== false,
    'Admin general mutations must synchronize public-template inherent cards'
);
assertServiceInvariant(
    strpos($adminGenerals, 'An inactive card may only preserve') !== false
        && strpos($adminGenerals, "gs.skill_type = '自带'") !== false
        && strpos($getGeneralApi, "'inherent_card_is_active'") !== false,
    'Historical inactive inherent-card mappings must round-trip without allowing reassignment'
);
assertServiceInvariant(
    strpos($adminSkills, 'validateCsrfToken()') !== false
        && strpos($adminSkills, 'adminSkillValidateCatalogInput') !== false
        && strpos($adminSkills, 'skill_card_catalog') !== false,
    'Skill administration must mutate the validated catalog behind CSRF'
);
assertServiceInvariant(
    strpos($adminSkills, 'UPDATE skill_card_catalog SET is_active = 0') !== false
        && strpos($adminSkills, 'DELETE FROM skill_card_catalog') === false
        && strpos($getSkillApi, "hasPermission('manage_skills')") !== false,
    'Catalog removal must be non-destructive and its detail API permission-gated'
);
assertServiceInvariant(
    strpos($imageResources, 'function getImageDisplayMode()') !== false
        && strpos($imageResources, 'function isImageDisplayEnabled()') !== false
        && strpos($imageResources, 'function getImageResourceManifest()') !== false
        && strpos($imageResources, 'function renderImageResource(') !== false
        && strpos($imageResources, 'function getImageResourceClientConfig(') !== false,
    'Image resources must expose one shared server and client helper API'
);
assertServiceInvariant(
    strpos($imageResources, 'isImageResourceFileAvailable(') !== false
        && strpos($imageResources, '<source type="image/webp"') !== false
        && strpos($imageResources, 'onerror="this.parentElement.hidden=true;') !== false,
    'Each formal image must be server-verified and retain browser Emoji fallback'
);
assertServiceInvariant(
    strpos($gameConfig, "'image_display_mode' => 'image'") !== false
        && strpos($gameConfig, "'values' => ['image', 'emoji_fallback']") !== false,
    'Image display configuration must use a strict enum with image as default'
);
assertServiceInvariant(
    strpos($adminConfig, 'validateCsrfToken()') !== false
        && substr_count($adminConfig, 'csrfField()') >= 4
        && strpos($adminConfig, 'csrf_token:') !== false
        && strpos($adminConfig, 'name="config_key" value="image_display_mode"') !== false
        && strpos($adminConfig, 'image-mode-preview') !== false,
    'Every configuration form and reset must use CSRF while exposing a mode preview'
);
$functionsLoad = strpos($init, "/includes/functions.php");
$imageResourcesLoad = strpos(
    $init,
    "/includes/image_resources.php"
);
assertServiceInvariant(
    $functionsLoad !== false
        && $imageResourcesLoad !== false
        && $functionsLoad < $imageResourcesLoad,
    'Image rendering helpers must load after the shared escaping helpers'
);

assertServiceInvariant(
    strpos($gameRules, 'function getRaidMinimumContribution') !== false
        && strpos($challenge, 'GameRules::getRaidMinimumContribution') !== false
        && strpos($challengesPage, 'GameRules::getRaidMinimumContribution') !== false,
    'Raid reward eligibility must share one authoritative threshold rule'
);

assertServiceInvariant(
    preg_match_all(
        '/var_export\(\$config\[[^\]]+\], true\)/',
        $install,
        $configExports
    ) >= 6,
    'Generated configuration must safely export every user-supplied value'
);
assertServiceInvariant(
    strpos($install, "__DIR__ . '/config/local.php'") !== false
        && strpos(
            $install,
            'function writeInstallerFileAtomically('
        ) !== false
        && strpos($install, 'LOCK_EX') !== false
        && strpos($install, '$writtenBytes !== $expectedBytes') !== false
        && strpos($install, '@rename($temporaryPath, $path)') !== false
        && strpos($localConfigExample, "'DB_PASS' =>") !== false
        && strpos($gitignore, 'config/local.php') !== false
        && strpos(
            $gitignore,
            'config/.*-installer-backup-*.php'
        ) !== false,
    'Installer secrets must be written atomically to an untracked local configuration'
);
assertServiceInvariant(
    strpos($install, "'/config/.installing.lock'") !== false
        && strpos($install, 'LOCK_EX | LOCK_NB') !== false
        && strpos($install, 'flock($installationLockHandle, LOCK_UN)')
            !== false
        && strpos($gitignore, 'config/.installing.lock') !== false
        && strpos(
            $gitignore,
            'config/.install-token-consumed'
        ) !== false
        && strpos($gitignore, 'config/install-token.php') !== false,
    'Installer authorization and execution locks must remain serialized and untracked'
);
assertServiceInvariant(
    strpos($install, '!is_file($sqlFilePath)') !== false
        && strpos($install, '!is_readable($sqlFilePath)') !== false
        && strpos($install, "trim(\$sql) === ''") !== false
        && strpos($install, 'count($statements) === 0') !== false,
    'Every required schema file must be readable, non-empty, and executable'
);
assertServiceInvariant(
    strpos($install, "'FIRESEED_DB_HOST'") !== false
        && strpos($install, "'FIRESEED_DB_USER'") !== false
        && strpos($install, "'FIRESEED_DB_PASS'") !== false
        && strpos($install, "'FIRESEED_DB_NAME'") !== false
        && strpos(
            $install,
            '$effectiveDatabaseConfig !== $expectedDatabaseConfig'
        ) !== false,
    'Installer and runtime services must not silently target different databases'
);
assertServiceInvariant(
    strpos(
        $configLoader,
        "require_once __DIR__ . '/game_constants.php';"
    ) !== false
        && strpos(
            $configLoader,
            "require_once __DIR__ . '/game_variables.php';"
        ) !== false
        && strpos($configLoader, "getenv('FIRESEED_' . \$key)") !== false,
    'Tracked configuration must load game settings and support environment overrides'
);
assertServiceInvariant(
    strpos($configLoader, "'DB_CHARSET' => 'utf8mb4'") !== false
        && strpos(
            $configLoader,
            "date_default_timezone_set('Asia/Shanghai')"
        ) !== false
        && strpos($configLoader, "readConfigValue(\n    'TIMEZONE'") === false
        && strpos($localConfigExample, "'TIMEZONE' =>") === false,
    'Runtime character set and timezone must match schema, installer, and migrations'
);
assertServiceInvariant(
    strpos($versionConfig, "define('GAME_VERSION', '0.1.0-beta')") !== false
        && strpos(
            $configLoader,
            "require_once __DIR__ . '/version.php';"
        ) !== false
        && strpos(
            $install,
            "require_once __DIR__ . '/config/version.php';"
        ) !== false
        && strpos($install, '"安装版本: " . GAME_VERSION') !== false,
    'Runtime and installer metadata must share one beta version source'
);
$generatedConfigLoad = strpos(
    $install,
    "require_once __DIR__ . '/config/config.php';"
);
$databaseClassLoad = strpos(
    $install,
    "require_once __DIR__ . '/includes/database.php';"
);
$userClassLoad = strpos(
    $install,
    "require_once __DIR__ . '/includes/classes/User.php';"
);
assertServiceInvariant(
    $generatedConfigLoad !== false
        && $databaseClassLoad !== false
        && $userClassLoad !== false
        && $generatedConfigLoad < $databaseClassLoad
        && $databaseClassLoad < $userClassLoad,
    'Installer must load generated configuration before Database and User classes'
);
assertServiceInvariant(
    strpos($install, 'function makeInstallerSqlStatementRerunnable') !== false
        && strpos($install, 'CREATE TABLE IF NOT EXISTS ') !== false
        && strpos($install, 'INSERT IGNORE INTO `game_config`') !== false,
    'Interrupted installation reruns must make base DDL and configuration seeds idempotent'
);
assertServiceInvariant(
    strpos($install, 'function getInstallerSqlTransactionCommand') !== false
        && strpos($install, "\$transactionCommand === 'START'") !== false
        && strpos($install, "\$transactionCommand === 'COMMIT'") !== false
        && strpos($install, '$db->begin_transaction()') !== false
        && strpos($install, '$db->commit()') !== false
        && strpos($install, '$db->rollback()') !== false
        && strpos($install, '$db->prepare($statement)') !== false,
    'Installer must route transaction controls through mysqli transaction APIs while preparing ordinary SQL'
);
assertServiceInvariant(
    strpos($install, 'function splitInstallerSqlStatements(') !== false
        && strpos($install, "explode(';', \$sql)") === false,
    'Installer must split SQL without breaking semicolons inside strings or comments'
);
assertServiceInvariant(
    strpos($install, 'function createOrRecoverInstallationAdmin') !== false
        && strpos($install, 'WHERE username = ? OR email = ?') !== false
        && strpos($install, "\$accounts[0]['username'] !== \$username") !== false
        && strpos($install, "\$accounts[0]['email'] !== \$email") !== false
        && strpos($install, '已被不同账户占用，安装已中止') !== false,
    'Installer recovery must require one exact username-and-email match and reject conflicts'
);
assertServiceInvariant(
    strpos($install, 'password_hash(') !== false
        && strpos($install, 'SET password = ?, admin_level = 9') !== false
        && strpos($install, '$db->begin_transaction()') !== false
        && strpos($install, '$db->commit()') !== false
        && strpos($install, '$db->rollback()') !== false,
    'Recovered administrator credentials and privileges must update transactionally'
);
assertServiceInvariant(
    strpos($install, 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)') !== false
        && strpos($install, 'never reset balances on a recovered account') !== false,
    'Installer recovery must ensure one resource row without resetting existing balances'
);

assertServiceInvariant(
    strpos($recruitment, 'private const DUPLICATE_SKILL_POINTS') !== false
        && strpos($recruitment, "'B' => 1") !== false
        && strpos($recruitment, "'A' => 2") !== false
        && strpos($recruitment, "'S' => 5") !== false
        && strpos($recruitment, "'SS' => 10") !== false
        && strpos($recruitment, "'P' => 20") !== false,
    'Duplicate recruitment conversion must use the rarity skill-point schedule'
);
assertServiceInvariant(
    strpos($recruitment, "['S', 'SS', 'P']") !== false
        && strpos($recruitment, 'Starter choices are limited') !== false,
    'Starter selection rarity must be enforced by the service boundary'
);
assertServiceInvariant(
    strpos($recruitment, 'getOwnedGeneralForTemplateLocked(') !== false
        && strpos($recruitment, 'FROM recruitment_history history') !== false
        && strpos($recruitment, 'AND history.template_general_id = ?') !== false
        && strpos($recruitment, 'FOR UPDATE";') !== false,
    'Recruitment must lock an existing general before duplicate conversion'
);
assertServiceInvariant(
    strpos($recruitment, 'INSERT INTO gameplay_wallets (user_id, skill_points)') !== false
        && strpos($recruitment, 'skill_points + VALUES(skill_points)') !== false,
    'Duplicate recruitment must atomically credit skill points'
);
assertServiceInvariant(
    strpos($recruitment, "'general_duplicate_converted'") !== false
        && strpos($recruitment, '$this->recordRecruitment(') !== false
        && strpos($recruitment, "\$ownedGeneral['duplicate'] = true;") !== false
        && strpos($recruitment, "\$ownedGeneral['duplicate_skill_points'] = \$skillPoints;") !== false,
    'Duplicate recruitment must retain history and expose its conversion result'
);
assertServiceInvariant(
    substr_count($recruitment, 'catalog.template_code') >= 2
        && strpos(
            $recruitment,
            "\$ownedGeneral['template_code'] = (string) \$template['template_code'];"
        ) !== false
        && strpos(
            $recruitment,
            "'template_code' => (string) \$template['template_code']"
        ) !== false,
    'Starter and draw results must preserve catalog template codes'
);

foreach ([
    'garrison deployment' => $deployGarrison,
    'garrison withdrawal' => $withdrawGarrison
] as $operationName => $source) {
    assertServiceInvariant(
        strpos($source, 'isValidPostRequest()') !== false
            && strpos($source, 'isSeasonGameplayFrozen()') !== false
            && strpos($source, 'http_response_code(409)') !== false,
        ucfirst($operationName) . ' must enforce POST, CSRF, and season freeze'
    );
}
assertServiceInvariant(
    strpos($territoryGarrison, 'function deployArmy') !== false
        && strpos($territoryGarrison, "ga.assignment_type = 'army'") !== false
        && strpos($territoryGarrison, "b.result = 'pending'") !== false
        && strpos($territoryGarrison, 'alliance_operation_armies') !== false
        && strpos($territoryGarrison, 'arena_profiles') !== false,
    'Whole-army deployment must reject every reserved army'
);
assertServiceInvariant(
    strpos($territoryGarrison, 'DELETE FROM armies') !== false
        && strpos($territoryGarrison, 'INSERT INTO territory_garrisons') !== false
        && strpos($territoryGarrison, 'quantity = quantity + VALUES(quantity)') !== false,
    'Deployment must transfer, rather than copy, an entire army into the garrison'
);
assertServiceInvariant(
    strpos($territoryGarrison, 'function normalizeWithdrawalUnits') !== false
        && strpos($territoryGarrison, '同一兵种不能重复提交') !== false
        && strpos($territoryGarrison, "status = 'returning'") !== false
        && strpos($territoryGarrison, "status = 'idle'") !== false,
    'Withdrawal must validate unique positive types and create an idle or returning army'
);
assertServiceInvariant(
    strpos($map, 'TERRITORY_OCCUPATION_COST') !== false
        && strpos($map, '请先撤回全部驻军再放弃领地') !== false
        && strpos($map, 'circuit_points + ?') !== false,
    'Ordinary territory abandonment must reject garrisons and refund the shared cost'
);
assertServiceInvariant(
    substr_count($battle, "['empty', 'resource'") >= 2
        && strpos($battle, 'applyTileGarrisonLosses') !== false
        && strpos($battle, "type IN ('empty', 'resource')") !== false
        && strpos($battle, 'GREATEST(0, territory_score + ?)') !== false,
    'Empty and resource territories must share garrison combat and capture handling'
);
$seasonLockPosition = strpos($battle, '$this->lockCurrentSeasonForScoring();');
$battleLockPosition = strpos(
    $battle,
    'SELECT * FROM battles WHERE battle_id = ? FOR UPDATE'
);
assertServiceInvariant(
    $seasonLockPosition !== false
        && $battleLockPosition !== false
        && $seasonLockPosition < $battleLockPosition,
    'Battle resolution must lock season before battle to match reset ordering'
);
assertServiceInvariant(
    strpos($getMap, "'garrison_total'") !== false
        && strpos(
            $getMap,
            "(int) \$tile->getOwnerId() === (int) \$_SESSION['user_id']"
        ) !== false
        && strpos($getMap, "'garrison_units'") !== false,
    'Map responses must expose enemy garrison totals without their composition'
);
assertServiceInvariant(
    strpos($attackTarget, "['npc_fort', 'empty', 'resource']") !== false,
    'The authoritative attack endpoint must accept owned empty territories'
);
assertServiceInvariant(
    strpos($map, 'function isAdjacentToUserControl') !== false
        && strpos($map, "type IN ('empty', 'resource')") !== false
        && strpos($map, 'ABS(x - ?) + ABS(y - ?) = 1') !== false
        && strpos($attackTarget, 'Map::isAdjacentToUserControl') !== false,
    'World attacks must share an exact Manhattan-adjacency boundary'
);
assertServiceInvariant(
    strpos($battle, '$this->lockCurrentSeasonForScoring();') !== false
        && strpos($army, 'Map::isAdjacentToUserControl(') !== false,
    'Army dispatch must revalidate the shared attack boundary after locking'
);
assertServiceInvariant(
    strpos($battle, 'attacker_composition_snapshot') !== false
        && strpos($battle, '$useDepartureSnapshot') !== false
        && strpos($battle, "'army_unit_id' => (int) \$unit['army_unit_id']") !== false,
    'Pending battles must preserve departure power, composition IDs, and snapshot resolution'
);
assertServiceInvariant(
    strpos($battle, 'Resource::getUserResourceStorageCapacity') !== false
        && strpos($battle, '{$column} = {$column} - ?') !== false
        && strpos($battle, '$actualRewards[$type] = $actual') !== false,
    'Player-city loot must be a storage-capped transfer rather than minted resources'
);
assertServiceInvariant(
    strpos($battle, 'npc_garrison = npc_garrison - ?') !== false
        && strpos($battle, 'npc_respawn_time = ?, owner_id = ?') !== false
        && strpos($battle, 'GOLEM_CITY_ATTACK') !== false,
    'NPC forts and city durability must use persistent garrison and surviving-golem damage'
);
assertServiceInvariant(
    strpos($battleReportPage, 'canUserView') !== false
        && strpos($battleReportApi, 'canUserView') !== false
        && strpos($battle, 'battle_participants bp') !== false,
    'Battle lists and reports must authorize from participant snapshots'
);
assertServiceInvariant(
    substr_count($army, 'lockSeasonForWorldAction($this->db);') >= 6
        && strpos($army, "AND arrival_time <= NOW()") !== false
        && strpos($army, "AND return_time <= NOW()") !== false
        && substr_count($army, '$stmt->affected_rows === 1') >= 4,
    'Army world mutations and arrival transitions must be season-locked and state-guarded'
);

echo "Service invariant tests passed: {$assertions} assertions.\n";

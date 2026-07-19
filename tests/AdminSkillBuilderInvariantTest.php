<?php
// 种火集结号 - 管理后台技能组合器不变量测试 / Fireseed Engage - Admin skill-builder invariant tests

$assertions = 0;

/**
 * 断言技能组合器源码必须满足的安全不变量 / Asserts a required skill-builder source invariant
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertAdminSkillBuilderInvariant($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

/**
 * 提取可独立执行的后台函数源码 / Extracts standalone admin function source
 *
 * @param string $source PHP源码 / PHP source
 * @param string $functionName 函数名 / Function name
 * @return string|null 函数源码 / Function source
 */
function extractAdminSkillBuilderFunction($source, $functionName) {
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

$root = dirname(__DIR__);
require_once $root . '/includes/classes/SkillMechanismRegistry.php';
require_once $root . '/includes/classes/SkillValueResolver.php';
require_once $root . '/includes/classes/SkillDefinitionValidator.php';

$adminSkills = file_get_contents($root . '/admin/skills.php');
$mechanismApi = file_get_contents(
    $root . '/api/get_skill_mechanisms.php'
);
$builderScript = file_get_contents(
    $root . '/assets/js/admin-skills.js'
);
$builderStyles = file_get_contents(
    $root . '/assets/css/admin-skills.css'
);

assertAdminSkillBuilderInvariant(
    strpos($adminSkills, 'SkillDefinitionValidator::validate(') !== false
        && strpos(
            $adminSkills,
            "\$allowLegacy && \$definitionMode === 'legacy'"
        ) !== false
        && strpos($adminSkills, "['builder', 'legacy']") !== false,
    'Every catalog save must use central validation with an explicit legacy gate'
);
assertAdminSkillBuilderInvariant(
    strpos($adminSkills, 'adminSkillValidateCatalogInput($_POST, false)')
        !== false
        && strpos($adminSkills, 'effect_json') !== false
        && strpos($adminSkills, '$existingIsLegacy') !== false,
    'New cards must require schema v2 while only existing legacy rows may use compatibility mode'
);
assertAdminSkillBuilderInvariant(
    strpos($adminSkills, 'name="definition_mode"') !== false
        && strpos($adminSkills, 'id="definitionBuilderPanel"') !== false
        && strpos($adminSkills, 'id="legacyJsonPanel"') !== false
        && strpos($builderScript, 'effectJson.required = !builder') !== false
        && strpos($adminSkills, '旧JSON兼容 / Legacy JSON') !== false,
    'The editor must separate modes without hidden required controls blocking builder submission'
);
assertAdminSkillBuilderInvariant(
    strpos($adminSkills, '../assets/js/admin-skills.js') !== false
        && strpos($adminSkills, '../assets/css/admin-skills.css') !== false
        && strlen($builderScript) > 0
        && strlen($builderStyles) > 0,
    'The skill builder must be delivered through dedicated admin assets'
);
assertAdminSkillBuilderInvariant(
    strpos($mechanismApi, "hasPermission('manage_skills')") !== false
        && strpos(
            $mechanismApi,
            'SkillMechanismRegistry::publicCatalog()'
        ) !== false
        && strpos(
            $mechanismApi,
            'SkillMechanismRegistry::conditions()'
        ) !== false
        && strpos($mechanismApi, "'maximum_depth'") !== false
        && strpos($mechanismApi, "'maximum_nodes'") !== false
        && strpos($mechanismApi, "'Cache-Control: private, no-store") !== false,
    'The mechanism catalog API must be permission-gated and non-cacheable'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, 'function getEffectConditionScope(') !== false
        && strpos($builderScript, 'allowed_conditions_by_parameter')
            !== false
        && strpos($builderScript, 'allowed_phase_values_by_parameter')
            !== false
        && strpos($builderScript, 'refreshEffectConditionScope(card)')
            !== false,
    'The builder must only expose conditions backed by each mechanism runtime context'
);
$publicCatalog = SkillMechanismRegistry::publicCatalog();
assertAdminSkillBuilderInvariant(
    isset(
        $publicCatalog['army_stat_percent']['allowed_conditions'],
        $publicCatalog['army_stat_percent']['allowed_phase_values'],
        $publicCatalog['army_stat_percent']
            ['allowed_conditions_by_parameter'],
        $publicCatalog['army_stat_percent']
            ['allowed_phase_values_by_parameter']
    ),
    'The administration API catalog must serialize condition-scope metadata'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, 'schema_version:') !== false
        && strpos($builderScript, 'function addEffect(') !== false
        && strpos($builderScript, 'function addCondition(') !== false
        && strpos($builderScript, 'function readValueEditor(') !== false
        && strpos(
            $builderScript,
            'cost_plus_intelligence_level_values'
        ) !== false,
    'The builder must compose multiple mechanisms, conditions, and every curve shape'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, "applicationMode.value === 'timed'") !== false
        && strpos($builderScript, "definition.kind === 'modifier'") !== false
        && strpos($builderScript, "definition.kind === 'action'") !== false
        && strpos($builderScript, 'Timed active skills need at least one modifier')
            !== false,
    'Timed active definitions must allow mixed actions and modifiers while requiring a modifier'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, "definition.status !== 'placeholder'") !== false
        && strpos($builderScript, 'option.disabled = true') !== false
        && strpos($builderScript, 'renderPlaceholderCatalog') !== false,
    'Placeholder mechanisms must remain visible while disabled for saving'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, '.innerHTML') === false
        && strpos($builderScript, 'eval(') === false
        && strpos($builderScript, 'new Function') === false,
    'The builder must create DOM safely without executable definition content'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, 'function associateLabel(') !== false
        && substr_count($builderScript, 'associateLabel(') >= 8,
    'Dynamic builder controls must receive programmatically associated labels'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, 'JSON.parse(effectJson.value)') !== false
        && strpos($builderScript, 'Legacy flat JSON cannot be converted') !== false
        && strpos($builderScript, "definitionMode.value = mode") !== false,
    'Legacy rows must round-trip only through the visibly selected compatibility mode'
);
assertAdminSkillBuilderInvariant(
    strpos($adminSkills, 'strlen($effectInput) > 60000') !== false
        && strpos($adminSkills, 'strlen($effectJson) > 60000')
            !== false
        && strpos(
            $adminSkills,
            'Normalized effect JSON must contain 1 to 60000 bytes'
        ) !== false
        && strpos($adminSkills, 'maxlength="60000"') !== false
        && strpos($builderScript, 'byteLength > 60000') !== false,
    'Raw and normalized definitions must share one safe 60000-byte limit in browser and server validation'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, 'values.length !== getMaximumLevel()')
        !== false
        && strpos($builderScript, 'Must contain exactly max level Lv.')
            !== false,
    'The builder must require every level curve to exactly match max_level'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, 'let modalGeneration = 0') !== false
        && substr_count(
            $builderScript,
            'generation !== modalGeneration'
        ) >= 4
        && strpos($builderScript, 'modalGeneration++') !== false,
    'Stale modal requests must not overwrite a newer editor session'
);
assertAdminSkillBuilderInvariant(
    strpos($builderScript, 'function measureDefinitionShape(') !== false
        && strpos($builderScript, 'catalogData.limits.maximum_depth') !== false
        && strpos($builderScript, 'catalogData.limits.maximum_nodes') !== false,
    'The builder must enforce the same global shape budget as central validation'
);
$cardLockPosition = strpos(
    $adminSkills,
    '$existingCard = adminSkillLoadCardForUpdate'
);
$equippedLevelLockPosition = $cardLockPosition === false
    ? false
    : strpos(
        $adminSkills,
        'adminSkillLoadHighestEquippedLevelForUpdate(',
        $cardLockPosition
    );
assertAdminSkillBuilderInvariant(
    strpos($adminSkills, 'FROM equipped_skill_cards equipped') !== false
        && strpos(
            $adminSkills,
            'JOIN general_skills skill'
        ) !== false
        && strpos(
            $adminSkills,
            'ORDER BY equipped.skill_id ASC'
        ) !== false
        && strpos($adminSkills, 'FOR UPDATE";') !== false
        && $cardLockPosition !== false
        && $equippedLevelLockPosition !== false
        && $cardLockPosition < $equippedLevelLockPosition
        && strpos(
            $adminSkills,
            'adminSkillAssertMaximumLevelSupportsEquipped(',
            $equippedLevelLockPosition
        ) !== false,
    'Catalog updates must lock equipped skills in stable order after the card and reject a destructive maximum'
);

$structuredMixedDefinition = [
    'schema_version' => 2,
    'application_mode' => 'timed',
    'cooldown' => [
        'mode' => 'level_values',
        'values' => [3600, 3300]
    ],
    'duration' => 300,
    'effects' => [
        [
            'mechanism' => 'army_stat_percent',
            'parameters' => [
                'stat' => 'attack',
                'unit_type' => 'all'
            ],
            'value' => [
                'mode' => 'level_values',
                'values' => [10, 15]
            ],
            'conditions' => []
        ],
        [
            'mechanism' => 'grant_resources',
            'parameters' => ['resource' => 'bright'],
            'value' => 100,
            'conditions' => []
        ]
    ]
];
$mixedValidation = SkillDefinitionValidator::validate(
    $structuredMixedDefinition,
    2,
    'active',
    false
);
assertAdminSkillBuilderInvariant(
    $mixedValidation['valid'],
    'Central validation must accept a timed schema-v2 skill mixing actions and modifiers'
);

$legacyDefinition = ['attack' => 10];
assertAdminSkillBuilderInvariant(
    !SkillDefinitionValidator::validate(
        $legacyDefinition,
        1,
        'passive',
        false
    )['valid']
        && SkillDefinitionValidator::validate(
            $legacyDefinition,
            1,
            'passive',
            true
        )['valid'],
    'Central validation must reject new legacy definitions while preserving the explicit compatibility path'
);

$placeholderDefinition = [
    'schema_version' => 2,
    'application_mode' => 'continuous',
    'effects' => [
        [
            'mechanism' => 'treasure_find_chance',
            'parameters' => [],
            'value' => 10,
            'conditions' => []
        ]
    ]
];
assertAdminSkillBuilderInvariant(
    !SkillDefinitionValidator::validate(
        $placeholderDefinition,
        1,
        'passive',
        false
    )['valid'],
    'Central validation must fail closed when a placeholder mechanism is submitted'
);

foreach ([
    'adminSkillTextLength',
    'adminSkillScalarText',
    'adminSkillValidateCatalogInput',
    'adminSkillAssertMaximumLevelSupportsEquipped'
] as $functionName) {
    $functionSource = extractAdminSkillBuilderFunction(
        $adminSkills,
        $functionName
    );
    assertAdminSkillBuilderInvariant(
        $functionSource !== null,
        "Admin validation function must be independently extractable: {$functionName}"
    );
    if ($functionSource !== null && !function_exists($functionName)) {
        eval($functionSource);
    }
}

$maximumLevelRejected = false;
$maximumLevelMessage = '';
try {
    adminSkillAssertMaximumLevelSupportsEquipped(4, 5);
} catch (DomainException $exception) {
    $maximumLevelRejected = true;
    $maximumLevelMessage = $exception->getMessage();
}
assertAdminSkillBuilderInvariant(
    $maximumLevelRejected
        && strpos($maximumLevelMessage, '最高等级不能低于') !== false
        && strpos($maximumLevelMessage, 'Maximum level cannot be lower')
            !== false,
    'The pure maximum-level guard must reject a value below an equipped level with a bilingual error'
);
adminSkillAssertMaximumLevelSupportsEquipped(5, 5);
adminSkillAssertMaximumLevelSupportsEquipped(6, 5);
assertAdminSkillBuilderInvariant(
    true,
    'The pure maximum-level guard must accept equal and higher catalog maxima'
);

$structuredInput = [
    'card_code' => 'builder_contract_card',
    'name' => '组合器契约技能',
    'description' => '结构化组合测试 / Structured composition test',
    'rarity' => 'B',
    'element' => '亮晶晶',
    'activation_type' => 'active',
    'category' => 'support',
    'effect_json' => json_encode(
        $structuredMixedDefinition,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ),
    'definition_mode' => 'builder',
    'base_cooldown' => '3600',
    'max_level' => '2',
    'is_active' => '1'
];
$structuredInputValidation = adminSkillValidateCatalogInput(
    $structuredInput,
    false
);
assertAdminSkillBuilderInvariant(
    empty($structuredInputValidation['errors'])
        && strpos(
            $structuredInputValidation['data']['effect_json'],
            '"schema_version":2'
        ) !== false,
    'Admin input validation must accept and normalize a valid schema-v2 builder definition'
);

$legacyInput = $structuredInput;
$legacyInput['activation_type'] = 'passive';
$legacyInput['effect_json'] = '{"attack":10}';
$legacyInput['definition_mode'] = 'legacy';
$legacyInput['base_cooldown'] = '0';
$legacyInput['max_level'] = '1';
$newLegacyValidation = adminSkillValidateCatalogInput(
    $legacyInput,
    false
);
$existingLegacyValidation = adminSkillValidateCatalogInput(
    $legacyInput,
    true
);
$normalizedLegacyEffect = json_decode(
    $existingLegacyValidation['data']['effect_json'],
    true
);
assertAdminSkillBuilderInvariant(
    !empty($newLegacyValidation['errors'])
        && empty($existingLegacyValidation['errors'])
        && is_array($normalizedLegacyEffect)
        && isset($normalizedLegacyEffect['attack'])
        && (float) $normalizedLegacyEffect['attack'] === 10.0,
    'Admin validation must reject a new legacy card but preserve a valid existing legacy row'
);

echo "Admin skill builder invariant tests passed ({$assertions} assertions).\n";

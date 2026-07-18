<?php
// 种火集结号 - 技能曲线与定向加成规则测试 / Fireseed Engage - Skill curves and targeted-bonus rule tests

require_once __DIR__ . '/../includes/classes/General.php';
require_once __DIR__ . '/../includes/classes/Army.php';

$assertions = 0;

/**
 * 断言两个值严格相同 / Assert that two values are strictly identical
 * @param mixed $expected 期望值 / Expected value
 * @param mixed $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSkillTargetingSame($expected, $actual, $message) {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

/**
 * 断言两个浮点值近似相同 / Assert that two floating-point values are approximately equal
 * @param float $expected 期望值 / Expected value
 * @param float $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSkillTargetingFloat($expected, $actual, $message) {
    global $assertions;
    $assertions++;
    if (abs((float) $expected - (float) $actual) > 0.000001) {
        throw new RuntimeException(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

try {
    $legacyScaled = General::scalePassiveEffectValues(
        ['attack' => 10, 'duration' => 300],
        2,
        20,
        3.5
    );
    assertSkillTargetingSame(
        ['attack' => 16.8],
        $legacyScaled,
        '旧平面数值必须保持等级与智力缩放 / Legacy flat values must retain level-and-intelligence scaling'
    );

    assertSkillTargetingFloat(
        8.0,
        General::resolvePassiveEffectDescriptor(
            [
                'mode' => 'level_values',
                'values' => [6, 7, 8, 9, 10]
            ],
            3,
            4
        ),
        '等级曲线应使用技能等级对应项 / Level curves must select the skill-level entry'
    );
    assertSkillTargetingFloat(
        38.5,
        General::resolvePassiveEffectDescriptor(
            [
                'mode' => 'cost_level_values',
                'values' => [8, 11, 13]
            ],
            2,
            3.5
        ),
        'COST曲线应乘以武将COST / COST curves must multiply by general COST'
    );

    $descriptorScaled = General::scalePassiveEffectValues(
        [
            'attack' => [
                'mode' => 'level_values',
                'values' => [6, 7, 8]
            ],
            'unit_attack_pawn' => [
                'mode' => 'cost_level_values',
                'values' => [8, 11, 13]
            ]
        ],
        2,
        999,
        3.5
    );
    assertSkillTargetingSame(
        ['attack' => 7.0, 'unit_attack_pawn' => 38.5],
        $descriptorScaled,
        '描述符结果不应再次套用旧智力倍率 / Descriptor results must not receive the legacy intelligence multiplier again'
    );

    $invalidDescriptors = [
        [
            'mode' => 'level_values',
            'values' => [-1]
        ],
        [
            'mode' => 'level_values',
            'values' => [INF]
        ],
        [
            'mode' => 'level_values',
            'values' => ['6']
        ],
        [
            'mode' => 'unknown',
            'values' => [6]
        ],
        [
            'mode' => 'level_values',
            'values' => []
        ]
    ];
    foreach ($invalidDescriptors as $invalidDescriptor) {
        assertSkillTargetingSame(
            null,
            General::resolvePassiveEffectDescriptor(
                $invalidDescriptor,
                1,
                3
            ),
            '非法描述符必须被忽略 / Invalid descriptors must be ignored'
        );
    }
    assertSkillTargetingSame(
        null,
        General::resolvePassiveEffectDescriptor(
            [
                'mode' => 'cost_level_values',
                'values' => [8]
            ],
            1,
            -1
        ),
        '非法COST必须拒绝 / Invalid COST must be rejected'
    );

    $legacyDefinition = ['attack' => 10];
    assertSkillTargetingSame(
        ['attack' => 25],
        General::selectPassiveEffectDefinition(
            '{"attack":25}',
            $legacyDefinition,
            true
        ),
        '映射技能必须使用目录权威定义 / Mapped skills must use the authoritative catalog definition'
    );
    assertSkillTargetingSame(
        [],
        General::selectPassiveEffectDefinition(
            '{invalid',
            $legacyDefinition,
            true
        ),
        '损坏的目录定义必须关闭而非回退旧快照 / A malformed mapped definition must fail closed instead of falling back'
    );
    assertSkillTargetingSame(
        $legacyDefinition,
        General::selectPassiveEffectDefinition(
            null,
            $legacyDefinition,
            false
        ),
        '无目录映射的旧技能必须保留旧快照 / Unmapped legacy skills must keep their snapshot'
    );

    $elementMap = Army::getElementNameMap();
    assertSkillTargetingSame(
        [
            'bright' => '亮晶晶',
            'warm' => '暖洋洋',
            'cold' => '冷冰冰',
            'green' => '郁萌萌',
            'day' => '昼闪闪',
            'night' => '夜静静'
        ],
        $elementMap,
        '六个元素键必须映射到中文元素 / All six element keys must map to their Chinese names'
    );
    assertSkillTargetingSame(
        ['pawn', 'knight', 'rook', 'bishop', 'golem', 'scout'],
        Army::getSupportedSoldierTypes(),
        '六个兵种键必须完整受支持 / All six soldier-type keys must be supported'
    );

    $bonusRules = Army::aggregateArmyBonusRules(
        [
            [
                'attack' => 10,
                'defense' => 20,
                'speed' => 5,
                'damage_reduction' => 80,
                'scout_range' => 20,
                'unit_attack_pawn' => 25,
                'unit_defense_rook' => 40,
                'unit_speed_knight' => 50,
                'element_attack_per_bright' => 6,
                'element_defense_per_bright' => 4,
                'element_speed_per_cold' => 10
            ],
            [
                'unit_attack_pawn' => 5,
                'unit_attack_knight' => -100,
                'element_attack_per_bright' => 1,
                'element_attack_per_unknown' => 999
            ]
        ],
        [
            '亮晶晶',
            'bright',
            '亮晶晶',
            '亮晶晶',
            '冷冰冰',
            '未知元素'
        ]
    );

    assertSkillTargetingSame(
        3,
        $bonusRules['element_stacks']['bright'],
        '同元素武将默认最多三层 / Matching generals must default to a three-stack cap'
    );
    assertSkillTargetingSame(
        1,
        $bonusRules['element_stacks']['cold'],
        '其他元素应独立计层 / Other elements must count independently'
    );
    assertSkillTargetingFloat(
        61.0,
        Army::getUnitBonusPercent($bonusRules, 'pawn', 'attack'),
        '兵卒应获得全局、兵种及元素攻击加成 / Pawns must receive global, unit, and elemental attack bonuses'
    );
    assertSkillTargetingFloat(
        31.0,
        Army::getUnitBonusPercent($bonusRules, 'knight', 'attack'),
        '兵卒定向攻击不得作用于骑士 / Pawn-specific attack must not affect knights'
    );
    assertSkillTargetingFloat(
        72.0,
        Army::getUnitBonusPercent($bonusRules, 'rook', 'defense'),
        '城壁应获得自身守备定向加成 / Rooks must receive their defense-specific bonus'
    );
    assertSkillTargetingFloat(
        32.0,
        Army::getUnitBonusPercent($bonusRules, 'pawn', 'defense'),
        '城壁守备加成不得作用于兵卒 / Rook-specific defense must not affect pawns'
    );
    assertSkillTargetingFloat(
        65.0,
        Army::getUnitBonusPercent($bonusRules, 'knight', 'speed'),
        '骑士应获得自身速度及元素速度加成 / Knights must receive their unit and elemental speed bonuses'
    );
    assertSkillTargetingFloat(
        15.0,
        Army::getUnitBonusPercent($bonusRules, 'pawn', 'speed'),
        '骑士速度加成不得作用于兵卒 / Knight-specific speed must not affect pawns'
    );
    assertSkillTargetingFloat(
        75.0,
        $bonusRules['damage_reduction'],
        '旧减伤必须继续按原上限封顶 / Legacy damage reduction must retain its cap'
    );
    assertSkillTargetingFloat(
        15.0,
        $bonusRules['scout_range'],
        '旧侦察范围必须继续按原上限封顶 / Legacy scout range must retain its cap'
    );

    assertSkillTargetingFloat(
        161.0,
        Army::applyUnitPercentageBonus(
            100,
            $bonusRules,
            'pawn',
            'attack'
        ),
        '兵种攻击应在对应单位上实际结算 / Unit attack bonuses must affect the matching unit total'
    );
    assertSkillTargetingFloat(
        131.0,
        Army::applyUnitPercentageBonus(
            100,
            $bonusRules,
            'knight',
            'attack'
        ),
        '其他兵种不应获得兵卒定向攻击 / Other units must not receive pawn-specific attack'
    );

    $cappedRules = Army::aggregateArmyBonusRules(
        [
            [
                'attack' => 5000,
                'unit_attack_pawn' => 5000,
                'element_attack_per_bright' => 5000
            ]
        ],
        ['亮晶晶', '亮晶晶', '亮晶晶']
    );
    assertSkillTargetingFloat(
        1000.0,
        $cappedRules['attack'],
        '全局加成必须独立封顶 / Global bonuses must be capped independently'
    );
    assertSkillTargetingFloat(
        1000.0,
        $cappedRules['directional']['pawn']['attack'],
        '定向加成必须独立封顶 / Directional bonuses must be capped independently'
    );
    assertSkillTargetingFloat(
        2000.0,
        Army::getUnitBonusPercent($cappedRules, 'pawn', 'attack'),
        '两个安全桶可在各自封顶后合并 / The two safe buckets may combine after separate capping'
    );

    $speedRules = Army::aggregateArmyBonusRules(
        [['unit_speed_knight' => 100]],
        []
    );
    assertSkillTargetingFloat(
        10.0,
        Army::calculateSlowestMovementSpeed(
            ['pawn' => 10, 'knight' => 5],
            ['pawn', 'knight'],
            $speedRules
        ),
        '混编速度应先逐兵种加成再取最慢值 / Mixed movement must apply per-unit bonuses before selecting the slowest speed'
    );
    assertSkillTargetingFloat(
        4.0,
        Army::calculateSlowestMovementSpeed(
            ['pawn' => 10, 'knight' => 5, 'bishop' => 4],
            ['pawn', 'knight', 'bishop'],
            $speedRules
        ),
        '未受加成的更慢兵种仍决定军速 / A slower unaffected unit must still determine army speed'
    );

    $twoStackCounts = Army::countElementStacks(
        ['昼闪闪', '昼闪闪', '昼闪闪'],
        2
    );
    assertSkillTargetingSame(
        2,
        $twoStackCounts['day'],
        '显式元素上限必须生效 / An explicit elemental stack cap must apply'
    );

    $generalSource = file_get_contents(
        __DIR__ . '/../includes/classes/General.php'
    );
    assertSkillTargetingSame(
        true,
        strpos(
            $generalSource,
            'card.effect_json AS catalog_effect_json'
        ) !== false,
        '被动读取路径必须查询目录权威定义 / The passive read path must query the authoritative catalog definition'
    );

    echo 'Skill targeting rule tests passed: '
        . $assertions . ' assertions.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

<?php
// 种火集结号 - 技能倍率组合规则测试 / Fireseed Engage - Skill multiplier composition rule tests

require_once __DIR__ . '/../includes/classes/SkillMechanismRegistry.php';
require_once __DIR__ . '/../includes/classes/SkillValueResolver.php';
require_once __DIR__ . '/../includes/classes/SkillDefinitionValidator.php';
require_once __DIR__ . '/../includes/classes/SkillEffectEngine.php';
require_once __DIR__ . '/../includes/classes/General.php';
require_once __DIR__ . '/../includes/classes/Army.php';

$assertions = 0;

/**
 * 断言浮点值近似相同 / Asserts approximate floating-point equality
 *
 * @param float $expected 期望值 / Expected value
 * @param float $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSkillMultiplierFloat($expected, $actual, $message) {
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

/**
 * 返回数组的所有排列 / Returns every permutation of an array
 *
 * @param array $values 输入值 / Input values
 * @return array 排列集合 / Permutations
 */
function getSkillMultiplierPermutations(array $values) {
    if (count($values) <= 1) {
        return [$values];
    }

    $permutations = [];
    foreach ($values as $index => $value) {
        $remaining = $values;
        array_splice($remaining, $index, 1);
        foreach (getSkillMultiplierPermutations($remaining) as $suffix) {
            $permutations[] = array_merge([$value], $suffix);
        }
    }

    return $permutations;
}

try {
    $combined = General::mergeSkillBonusEffects(
        [],
        [
            'attack' => 10,
            'attack_multiplier' => 0.5,
            'unit_attack_multiplier_pawn' => 2.0
        ]
    );
    $combined = General::mergeSkillBonusEffects(
        $combined,
        [
            'attack' => 15,
            'attack_multiplier' => 1.5,
            'unit_attack_multiplier_pawn' => 0.5
        ]
    );
    assertSkillMultiplierFloat(
        25.0,
        $combined['attack'],
        '普通百分比必须跨技能相加 / Ordinary percentages must add across skills'
    );
    assertSkillMultiplierFloat(
        0.75,
        $combined['attack_multiplier'],
        '全军倍率必须跨技能相乘 / Global multipliers must multiply across skills'
    );
    assertSkillMultiplierFloat(
        1.0,
        $combined['unit_attack_multiplier_pawn'],
        '兵种倍率必须跨技能相乘 / Unit multipliers must multiply across skills'
    );

    $permutations = getSkillMultiplierPermutations([10.0, 10.0, 0.1]);
    foreach ($permutations as $index => $factors) {
        $generalBonusSets = [];
        foreach ($factors as $factor) {
            $generalBonusSets[] = [
                'attack_multiplier' => $factor,
                'unit_attack_multiplier_pawn' => $factor,
                'siege_damage_multiplier' => $factor
            ];
        }
        $rules = Army::aggregateArmyBonusRules(
            $generalBonusSets,
            []
        );
        $permutationLabel = ' #' . ($index + 1);
        assertSkillMultiplierFloat(
            10.0,
            $rules['attack_multiplier'],
            '全军倍率必须与武将次序无关 / Global multipliers must be order-independent'
                . $permutationLabel
        );
        assertSkillMultiplierFloat(
            10.0,
            $rules['directional']['pawn']['multipliers']['attack'],
            '定向兵种倍率必须与武将次序无关 / Unit multipliers must be order-independent'
                . $permutationLabel
        );
        assertSkillMultiplierFloat(
            10.0,
            $rules['siege_damage_multiplier'],
            '攻城倍率必须与武将次序无关 / Siege multipliers must be order-independent'
                . $permutationLabel
        );
    }

    $uncappedRules = Army::aggregateArmyBonusRules(
        [
            [
                'attack_multiplier' => 10.0,
                'unit_attack_multiplier_pawn' => 10.0,
                'siege_damage_multiplier' => 10.0
            ],
            [
                'attack_multiplier' => 10.0,
                'unit_attack_multiplier_pawn' => 10.0,
                'siege_damage_multiplier' => 10.0
            ]
        ],
        []
    );
    assertSkillMultiplierFloat(
        100.0,
        $uncappedRules['attack_multiplier'],
        '合并阶段不得提前截断全军乘积 / Aggregation must not prematurely cap the global product'
    );
    assertSkillMultiplierFloat(
        1000.0,
        Army::applyUnitPercentageBonus(
            100,
            $uncappedRules,
            'knight',
            'attack'
        ),
        '属性消费端必须将最终全军倍率封顶为十 / Stat consumption must cap the final global multiplier at ten'
    );

    $inputBoundedRules = Army::aggregateArmyBonusRules(
        [
            ['attack_multiplier' => 100.0],
            ['attack_multiplier' => 0.1]
        ],
        []
    );
    assertSkillMultiplierFloat(
        1.0,
        $inputBoundedRules['attack_multiplier'],
        '每个倍率输入必须先独立限制到零至十 / Each multiplier input must be independently bounded from zero to ten'
    );

    $zeroRules = Army::aggregateArmyBonusRules(
        [
            ['attack_multiplier' => INF],
            ['attack_multiplier' => 0.0],
            ['attack_multiplier' => 10.0]
        ],
        []
    );
    assertSkillMultiplierFloat(
        0.0,
        $zeroRules['attack_multiplier'],
        '零倍率必须安全主导乘积且非有限输入视为中性 / Zero must safely dominate while non-finite inputs stay neutral'
    );

    $overflowBonusSets = array_fill(
        0,
        400,
        ['attack_multiplier' => 10.0]
    );
    $overflowRules = Army::aggregateArmyBonusRules(
        $overflowBonusSets,
        []
    );
    if (!is_finite($overflowRules['attack_multiplier'])
        || $overflowRules['attack_multiplier'] !== PHP_FLOAT_MAX) {
        throw new RuntimeException(
            '溢出乘积必须安全饱和为有限最大值 / Overflowing products must safely saturate at the finite maximum'
        );
    }
    $assertions++;
    assertSkillMultiplierFloat(
        1000.0,
        Army::applyUnitPercentageBonus(
            100,
            $overflowRules,
            'knight',
            'attack'
        ),
        '溢出后的属性消费仍必须封顶为十倍 / Stat consumption after overflow must still cap at ten'
    );

    $definition = [
        'schema_version' => 2,
        'duration' => 300,
        'effects' => [
            [
                'mechanism' => 'army_stat_multiplier',
                'parameters' => [
                    'stat' => 'speed',
                    'unit_type' => 'all'
                ],
                'value' => 0.5
            ],
            [
                'mechanism' => 'army_stat_multiplier',
                'parameters' => [
                    'stat' => 'attack',
                    'unit_type' => 'all'
                ],
                'value' => 1.0
            ],
            [
                'mechanism' => 'army_siege_damage_multiplier',
                'parameters' => [],
                'value' => 1.5
            ]
        ]
    ];
    $context = [
        'skill_level' => 1,
        'max_level' => 1,
        'general_cost' => 1.0,
        'general_intelligence' => 0,
        'skill_power_percent' => 20.0,
        'phase' => 'battle'
    ];
    $evaluation = SkillEffectEngine::evaluate($definition, $context);
    if (!$evaluation['valid']) {
        throw new RuntimeException(implode('; ', $evaluation['errors']));
    }
    assertSkillMultiplierFloat(
        0.4,
        $evaluation['modifiers']['speed_multiplier'],
        '技能威力必须放大减益倍率离中性点的距离 / Skill power must amplify a penalty multiplier away from neutral'
    );
    assertSkillMultiplierFloat(
        1.0,
        $evaluation['modifiers']['attack_multiplier'],
        '技能威力不得改变中性倍率 / Skill power must preserve a neutral multiplier'
    );
    assertSkillMultiplierFloat(
        1.6,
        $evaluation['modifiers']['siege_damage_multiplier'],
        '技能威力必须放大增益倍率离中性点的距离 / Skill power must amplify a bonus multiplier away from neutral'
    );

    $snapshot = SkillEffectEngine::snapshotTimedEffects(
        $definition,
        $context
    );
    $snapshotEvaluation = SkillEffectEngine::evaluate(
        $snapshot,
        ['phase' => 'battle'],
        true
    );
    if (!$snapshotEvaluation['valid']) {
        throw new RuntimeException(
            implode('; ', $snapshotEvaluation['errors'])
        );
    }
    assertSkillMultiplierFloat(
        $evaluation['modifiers']['speed_multiplier'],
        $snapshotEvaluation['modifiers']['speed_multiplier'],
        '倍率快照必须与直接求值一致 / Multiplier snapshots must match direct evaluation'
    );
    assertSkillMultiplierFloat(
        $evaluation['modifiers']['attack_multiplier'],
        $snapshotEvaluation['modifiers']['attack_multiplier'],
        '中性倍率快照必须与直接求值一致 / Neutral multiplier snapshots must match direct evaluation'
    );
    assertSkillMultiplierFloat(
        $evaluation['modifiers']['siege_damage_multiplier'],
        $snapshotEvaluation['modifiers']['siege_damage_multiplier'],
        '攻城倍率快照必须与直接求值一致 / Siege multiplier snapshots must match direct evaluation'
    );

    echo 'Skill multiplier rule tests passed: '
        . $assertions . ' assertions.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

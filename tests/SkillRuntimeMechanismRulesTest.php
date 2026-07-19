<?php
// 种火集结号 - 技能运行钩子规则测试 / Fireseed Engage - Skill runtime-hook rule tests

require_once __DIR__ . '/../includes/classes/Army.php';
require_once __DIR__ . '/../includes/classes/Battle.php';
require_once __DIR__ . '/../includes/classes/Soldier.php';

$assertions = 0;

/**
 * 断言严格相同 / Asserts strict equality
 *
 * @param mixed $expected 期望值 / Expected value
 * @param mixed $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSkillRuntimeSame($expected, $actual, $message) {
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
 * 断言浮点近似相同 / Asserts approximate floating-point equality
 *
 * @param float $expected 期望值 / Expected value
 * @param float $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSkillRuntimeFloat($expected, $actual, $message) {
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
    $multipliers = Army::aggregateArmyBonusRules(
        [
            [
                'attack_multiplier' => 0.5,
                'unit_attack_multiplier_pawn' => 2.0
            ]
        ],
        []
    );
    assertSkillRuntimeFloat(
        100.0,
        Army::applyUnitPercentageBonus(
            100,
            $multipliers,
            'pawn',
            'attack'
        ),
        '全军与兵种倍率必须同时结算 / Global and unit multipliers must both apply'
    );
    assertSkillRuntimeFloat(
        50.0,
        Army::applyUnitPercentageBonus(
            100,
            $multipliers,
            'knight',
            'attack'
        ),
        '兵种倍率不得泄漏到其他兵种 / Unit multipliers must not leak to other units'
    );

    assertSkillRuntimeSame(
        325,
        Battle::applySiegeDamageModifiers(
            100,
            [
                'siege_damage_percent' => 50,
                'siege_damage_multiplier' => 2,
                'siege_damage_flat' => 25
            ]
        ),
        '攻城百分比、倍率和固定值必须按约定次序组合 / Siege modifiers must compose in the documented order'
    );
    assertSkillRuntimeSame(
        2147483647,
        Battle::applySiegeDamageModifiers(
            PHP_INT_MAX,
            [
                'siege_damage_percent' => 1000,
                'siege_damage_multiplier' => 10,
                'siege_damage_flat' => 1000000000
            ]
        ),
        '攻城伤害必须限制在数据库安全整数范围 / Siege damage must stay in the database-safe integer range'
    );
    assertSkillRuntimeSame(
        0,
        Battle::applySiegeDamageModifiers(
            0,
            ['siege_damage_flat' => 1000]
        ),
        '没有存活锤子兵时固定值不得凭空造成耐久伤害 / Flat siege damage must not create a durability hit without surviving golems'
    );

    assertSkillRuntimeSame(
        37,
        Battle::decodeAttackerBattleDistanceSnapshot(
            json_encode([
                'schema_version' => 2,
                'units' => [],
                'battle_context' => ['distance' => 37]
            ])
        ),
        '战斗距离必须从新版出征快照恢复 / Battle distance must be restored from the versioned departure snapshot'
    );
    assertSkillRuntimeSame(
        0,
        Battle::decodeAttackerBattleDistanceSnapshot(
            json_encode([
                [
                    'army_unit_id' => 1,
                    'soldier_type' => 'pawn',
                    'level' => 1,
                    'quantity' => 1
                ]
            ])
        ),
        '旧版编成快照必须安全回退零距离 / Legacy composition snapshots must safely fall back to zero distance'
    );
    assertSkillRuntimeSame(
        0,
        Battle::decodeAttackerBattleDistanceSnapshot(
            '{"schema_version":2,"battle_context":{"distance":-1}}'
        ),
        '非法距离快照必须安全回退 / Invalid distance snapshots must safely fall back'
    );

    assertSkillRuntimeSame(
        ['warm' => 50, 'day' => 25],
        Soldier::applyTrainingCostReduction(
            ['warm' => 100, 'day' => 50],
            50
        ),
        '训练费用减免必须逐资源应用 / Training-cost reduction must apply per resource'
    );
    assertSkillRuntimeSame(
        ['warm' => 5],
        Soldier::applyTrainingCostReduction(
            ['warm' => 100],
            500
        ),
        '训练费用减免必须封顶95% / Training-cost reduction must cap at 95%'
    );
    assertSkillRuntimeSame(
        ['warm' => 34],
        Soldier::applyTrainingCostReduction(
            ['warm' => 101],
            66.67
        ),
        '训练费用必须向上取整避免额外免费量 / Reduced costs must round up to avoid unintended free resources'
    );

    $battleSource = file_get_contents(
        __DIR__ . '/../includes/classes/Battle.php'
    );
    assertSkillRuntimeSame(
        true,
        strpos($battleSource, "'skill_modifiers' =>") !== false
            && strpos(
                $battleSource,
                'decodeAttackerSkillModifierSnapshot'
            ) !== false,
        '攻城技能必须随出征快照固化 / Siege skills must be frozen in the departure snapshot'
    );
    assertSkillRuntimeSame(
        true,
        substr_count(
            $battleSource,
            "'distance' => \$departureBattleDistance"
        ) === 2
            && strpos(
                $battleSource,
                'getCombatPower($attackerContext)'
            ) !== false
            && strpos(
                $battleSource,
                'getDamageReduction($attackerContext)'
            ) !== false
            && strpos(
                $battleSource,
                'getSkillModifiers($attackerContext)'
            ) !== false,
        '结算期战斗条件必须统一使用快照距离 / Resolution-time battle conditions must consistently use the snapshotted distance'
    );

    $resourceSource = file_get_contents(
        __DIR__ . '/../includes/classes/Resource.php'
    );
    assertSkillRuntimeSame(
        true,
        strpos($resourceSource, "'production_' . \$resourceType") !== false,
        '资源结算必须读取分资源生产键 / Production settlement must read resource-specific keys'
    );

    $garrisonSource = file_get_contents(
        __DIR__ . '/../includes/classes/TerritoryGarrisonService.php'
    );
    assertSkillRuntimeSame(
        true,
        strpos($garrisonSource, "'phase' => 'return'") !== false
            && strpos($garrisonSource, "'distance' => \$distance") !== false,
        '领地驻军撤回必须使用返程技能上下文 / Territory-garrison withdrawals must use the return-skill context'
    );

    echo 'Skill runtime mechanism tests passed: '
        . $assertions . ' assertions.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

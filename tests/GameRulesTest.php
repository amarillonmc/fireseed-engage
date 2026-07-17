<?php
// 种火集结号 - 游戏规则轻量测试 / Fireseed Engage - Lightweight game rules tests

require_once __DIR__ . '/../includes/classes/GameRules.php';

$assertionCount = 0;

/**
 * 断言两个值严格相同 / Assert that two values are strictly identical
 *
 * @param mixed $expected 期望值 / Expected value
 * @param mixed $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSameValue($expected, $actual, $message)
{
    global $assertionCount;
    $assertionCount++;

    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) .
            "\nActual: " . var_export($actual, true)
        );
    }
}

/**
 * 断言两个浮点数近似相等 / Assert that two floating-point values are approximately equal
 *
 * @param float $expected 期望值 / Expected value
 * @param float $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @param float $epsilon 允许误差 / Allowed error
 * @return void
 */
function assertApproximate($expected, $actual, $message, $epsilon = 0.000001)
{
    global $assertionCount;
    $assertionCount++;

    if (abs($expected - $actual) > $epsilon) {
        throw new RuntimeException(
            $message . "\nExpected: " . $expected . "\nActual: " . $actual
        );
    }
}

/**
 * 断言回调抛出指定异常 / Assert that a callback throws the expected exception
 *
 * @param string $exceptionClass 异常类名 / Exception class name
 * @param callable $callback 测试回调 / Test callback
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertThrowsException($exceptionClass, callable $callback, $message)
{
    global $assertionCount;
    $assertionCount++;

    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException(
            $message . "\nExpected exception: " . $exceptionClass .
            "\nActual exception: " . get_class($exception)
        );
    }

    throw new RuntimeException($message . "\nExpected exception was not thrown.");
}

/**
 * 断言概率权重总和为百分之百 / Assert that probability weights total one hundred percent
 *
 * @param array $weights 概率权重 / Probability weights
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertProbabilityTotal(array $weights, $message)
{
    assertApproximate(100.0, (float) array_sum($weights), $message);
}

try {
    $normalWeights = GameRules::getGeneralRecruitmentProbabilities('normal');
    $advancedWeights = GameRules::getGeneralRecruitmentProbabilities('advanced');
    $resonanceWeights = GameRules::getGeneralRecruitmentProbabilities('resonance');
    $skillWeights = GameRules::getSkillCardProbabilities();

    assertProbabilityTotal($normalWeights, '普通招募概率总和必须为100 / Normal recruitment probabilities must total 100');
    assertProbabilityTotal($advancedWeights, '高级招募概率总和必须为100 / Advanced recruitment probabilities must total 100');
    assertProbabilityTotal($resonanceWeights, '共鸣招募概率总和必须为100 / Resonance recruitment probabilities must total 100');
    assertProbabilityTotal($skillWeights, '技能卡概率总和必须为100 / Skill-card probabilities must total 100');

    assertSameValue('B', GameRules::rollGeneralRarity('normal', 70.0), '普通招募70应为B / Normal roll 70 should be B');
    assertSameValue('A', GameRules::rollGeneralRarity('normal', 70.01), '普通招募70.01应为A / Normal roll 70.01 should be A');
    assertSameValue('A', GameRules::rollGeneralRarity('normal', 95.0), '普通招募95应为A / Normal roll 95 should be A');
    assertSameValue('S', GameRules::rollGeneralRarity('normal', 95.01), '普通招募95.01应为S / Normal roll 95.01 should be S');
    assertSameValue('S', GameRules::rollGeneralRarity('normal', 100.0), '普通招募100应为S / Normal roll 100 should be S');

    assertSameValue('A', GameRules::rollGeneralRarity('advanced', 1.0), '高级招募不应产生B / Advanced recruitment should not produce B');
    assertSameValue('A', GameRules::rollGeneralRarity('advanced', 70.0), '高级招募70应为A / Advanced roll 70 should be A');
    assertSameValue('S', GameRules::rollGeneralRarity('advanced', 70.01), '高级招募70.01应为S / Advanced roll 70.01 should be S');
    assertSameValue('S', GameRules::rollGeneralRarity('advanced', 95.0), '高级招募95应为S / Advanced roll 95 should be S');
    assertSameValue('SS', GameRules::rollGeneralRarity('advanced', 95.01), '高级招募95.01应为SS / Advanced roll 95.01 should be SS');

    assertSameValue('S', GameRules::rollGeneralRarity('resonance', 50.0), '共鸣招募50应为S / Resonance roll 50 should be S');
    assertSameValue('SS', GameRules::rollGeneralRarity('resonance', 50.01), '共鸣招募50.01应为SS / Resonance roll 50.01 should be SS');
    assertSameValue('SS', GameRules::rollGeneralRarity('resonance', 85.0), '共鸣招募85应为SS / Resonance roll 85 should be SS');
    assertSameValue('P', GameRules::rollGeneralRarity('resonance', 85.01), '共鸣招募85.01应为P / Resonance roll 85.01 should be P');
    assertSameValue('P', GameRules::rollGeneralRarity('resonance', 100.0), '共鸣招募100应为P / Resonance roll 100 should be P');

    assertSameValue('B', GameRules::rollSkillCardRarity(55.0), '技能卡55应为B / Skill-card roll 55 should be B');
    assertSameValue('A', GameRules::rollSkillCardRarity(55.01), '技能卡55.01应为A / Skill-card roll 55.01 should be A');
    assertSameValue('S', GameRules::rollSkillCardRarity(85.01), '技能卡85.01应为S / Skill-card roll 85.01 should be S');
    assertSameValue('SS', GameRules::rollSkillCardRarity(95.01), '技能卡95.01应为SS / Skill-card roll 95.01 should be SS');
    assertSameValue('P', GameRules::rollSkillCardRarity(99.01), '技能卡99.01应为P / Skill-card roll 99.01 should be P');
    assertSameValue('P', GameRules::rollSkillCardRarity(100.0), '技能卡100应为P / Skill-card roll 100 should be P');

    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::getGeneralRecruitmentProbabilities('invalid');
        },
        '未知招募类型必须被拒绝 / Unknown recruitment types must be rejected'
    );
    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::rollSkillCardRarity(0.0);
        },
        '概率下界之外的掷骰必须被拒绝 / Rolls below the probability range must be rejected'
    );
    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::rollGeneralRarity('normal', 100.01);
        },
        '概率上界之外的掷骰必须被拒绝 / Rolls above the probability range must be rejected'
    );

    $counterPairs = [
        ['pawn', 'golem'],
        ['knight', 'pawn'],
        ['knight', 'rook'],
        ['rook', 'pawn'],
        ['rook', 'bishop'],
        ['bishop', 'pawn'],
        ['bishop', 'knight']
    ];
    foreach ($counterPairs as $pair) {
        assertApproximate(
            1.5,
            GameRules::getUnitCounterMultiplier($pair[0], $pair[1]),
            '指定兵种相克倍率应为1.5 / Configured unit counters should use a 1.5 multiplier'
        );
    }
    assertApproximate(1.0, GameRules::getUnitCounterMultiplier('pawn', 'knight'), '非相克组合应为1.0 / Neutral unit matchups should use 1.0');
    assertApproximate(1.0, GameRules::getUnitCounterMultiplier('scout', 'golem'), '侦察兵组合应为1.0 / Scout matchups should use 1.0');
    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::getUnitCounterMultiplier('dragon', 'pawn');
        },
        '未知兵种必须被拒绝 / Unknown unit types must be rejected'
    );

    $expectedBreakRules = [
        'B' => [40, 1, 1000],
        'A' => [50, 2, 2500],
        'S' => [60, 3, 5000],
        'SS' => [80, 5, 10000],
        'P' => [100, 8, 20000]
    ];
    foreach ($expectedBreakRules as $rarity => $expectedRule) {
        assertSameValue($expectedRule[0], GameRules::getBreakLevelCap($rarity), 'BREAK等级上限不正确 / BREAK level cap is incorrect');
        $cost = GameRules::getBreakCost($rarity);
        assertSameValue($expectedRule[1], $cost['break_material'], 'BREAK材料费用不正确 / BREAK material cost is incorrect');
        assertSameValue($expectedRule[2], $cost['bright_crystal'], 'BREAK亮晶晶费用不正确 / BREAK bright-crystal cost is incorrect');
    }
    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::getBreakCost('UR');
        },
        '未知稀有度必须被拒绝 / Unknown rarities must be rejected'
    );

    assertSameValue('attacker_win_big', GameRules::calculateBattleOutcome(151, 100), '超过1.5倍应为攻击方大胜 / Above 1.5x should be a major attacker win');
    assertSameValue('attacker_win', GameRules::calculateBattleOutcome(150, 100), '恰好1.5倍应为攻击方胜 / Exactly 1.5x should be a regular attacker win');
    assertSameValue('draw', GameRules::calculateBattleOutcome(100, 100), '相同战力应为平局 / Equal power should draw');
    assertSameValue('defender_win', GameRules::calculateBattleOutcome(100, 150), '恰好1.5倍应为防守方胜 / Exactly 1.5x should be a regular defender win');
    assertSameValue('defender_win_big', GameRules::calculateBattleOutcome(100, 151), '超过1.5倍应为防守方大胜 / Above 1.5x should be a major defender win');
    assertSameValue('draw', GameRules::calculateBattleOutcome(0, 0), '双方零战力应为平局 / Zero power on both sides should draw');

    assertSameValue(
        ['attacker' => 0.05, 'defender' => 0.50],
        GameRules::getBattleLossRates('attacker_win_big'),
        '攻击方大胜损耗率不正确 / Major attacker-win loss rates are incorrect'
    );
    assertSameValue(
        ['attacker' => 0.20, 'defender' => 0.20],
        GameRules::getBattleLossRates('draw'),
        '平局损耗率不正确 / Draw loss rates are incorrect'
    );
    assertSameValue(4, GameRules::calculateBattleLosses(31, 0.10), '损耗数量应向上取整 / Battle losses should round up');
    assertSameValue(0, GameRules::calculateBattleLosses(0, 0.50), '零兵力损耗应为零 / Zero troops should produce zero losses');
    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::calculateBattleOutcome(-1, 100);
        },
        '负战力必须被拒绝 / Negative battle power must be rejected'
    );

    assertSameValue(20, GameRules::calculateCaptiveCount(100, 'attacker_win_big', 'attacker'), '大胜应俘虏20% / Major wins should capture 20 percent');
    assertSameValue(10, GameRules::calculateCaptiveCount(100, 'attacker_win', 'attacker'), '普通胜利应俘虏10% / Regular wins should capture 10 percent');
    assertSameValue(0, GameRules::calculateCaptiveCount(100, 'defender_win', 'attacker'), '失败方不能俘虏 / Losing sides cannot take captives');
    assertSameValue(20, GameRules::calculateCaptiveCount(101, 'defender_win_big', 'defender'), '俘虏数量应向下取整 / Captive counts should round down');
    assertSameValue(0, GameRules::calculateCaptiveCount(100, 'draw', 'attacker'), '平局不产生俘虏 / Draws should not produce captives');

    assertSameValue(
        ['player_a' => 16, 'player_b' => -16],
        GameRules::calculateArenaEloChanges(1500, 1500, 1.0),
        '同分玩家胜负应变动16分 / Equal-rated decisive matches should change ratings by 16'
    );
    assertSameValue(
        ['player_a' => 0, 'player_b' => 0],
        GameRules::calculateArenaEloChanges(1500, 1500, 0.5),
        '同分玩家平局应不变 / Equal-rated draws should not change ratings'
    );
    $upsetChanges = GameRules::calculateArenaEloChanges(1200, 1800, 1.0);
    assertSameValue(true, $upsetChanges['player_a'] > 16, '爆冷胜利应获得更多分数 / Upset wins should gain more rating');
    assertSameValue(0, $upsetChanges['player_a'] + $upsetChanges['player_b'], 'Elo变化必须零和 / Elo changes must be zero-sum');
    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::calculateArenaEloChanges(1500, 1500, 0.25);
        },
        '非法比赛结果必须被拒绝 / Invalid arena scores must be rejected'
    );

    assertSameValue(
        100,
        GameRules::getRaidMinimumContribution(0),
        '低生命讨伐目标仍需至少100贡献 / Low-HP raids still require 100 contribution'
    );
    assertSameValue(
        100,
        GameRules::getRaidMinimumContribution(100000),
        '十万生命目标门槛应为100 / A 100,000-HP raid should require 100 contribution'
    );
    assertSameValue(
        101,
        GameRules::getRaidMinimumContribution(100001),
        '千分之一门槛必须向上取整 / The one-per-thousand threshold must round up'
    );
    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::getRaidMinimumContribution(-1);
        },
        '负数讨伐生命必须被拒绝 / Negative raid HP must be rejected'
    );

    assertSameValue(1000, GameRules::getTowerEnemyPower(1), '战斗塔第一层战力应为1000 / Tower floor one power should be 1000');
    assertSameValue(2239, GameRules::getTowerEnemyPower(10), '战斗塔第十层战力不正确 / Tower floor ten power is incorrect');
    assertSameValue(true, GameRules::getTowerEnemyPower(11) > GameRules::getTowerEnemyPower(10), '战斗塔战力必须递增 / Tower power must increase by floor');
    assertSameValue(
        ['bright_crystal' => 200, 'night_crystal' => 20, 'break_material' => 1],
        GameRules::getTowerReward(1),
        '战斗塔第一层奖励不正确 / Tower floor one reward is incorrect'
    );
    assertSameValue(1, GameRules::getTowerReward(10)['break_material'], '第十层材料奖励不正确 / Floor ten material reward is incorrect');
    assertSameValue(2, GameRules::getTowerReward(11)['break_material'], '第十一层材料奖励不正确 / Floor eleven material reward is incorrect');
    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::getTowerEnemyPower(0);
        },
        '零层必须被拒绝 / Floor zero must be rejected'
    );
    assertThrowsException(
        InvalidArgumentException::class,
        function () {
            GameRules::getTowerReward(INF);
        },
        '无限大的楼层必须被拒绝 / Infinite floors must be rejected'
    );

    echo "GameRules tests passed: " . $assertionCount . " assertions.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "GameRules tests failed: " . $exception->getMessage() . "\n");
    exit(1);
}

<?php
// 种火集结号 - 科技规则轻量测试 / Fireseed Engage - Lightweight technology rules tests

require_once __DIR__ . '/../includes/classes/TechnologyEffectService.php';

$assertions = 0;

/**
 * 断言条件成立 / Assert that a condition is true
 * @param bool $condition 条件 / Condition
 * @param string $message 失败消息 / Failure message
 * @return void
 */
function assertTechnologyRule($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 断言浮点值近似相等 / Assert that floating-point values are approximately equal
 * @param float $expected 期望值 / Expected value
 * @param float $actual 实际值 / Actual value
 * @param string $message 失败消息 / Failure message
 * @return void
 */
function assertTechnologyApproximate($expected, $actual, $message) {
    assertTechnologyRule(abs($expected - $actual) < 0.000001, $message);
}

try {
    assertTechnologyRule(
        TechnologyEffectService::isCostPolicyValid(
            'seasonal',
            ['warm' => 100, 'cold' => 100, 'green' => 100, 'day' => 100]
        ),
        '季节科研应允许四种赛季资源 / Seasonal research should allow four seasonal resources'
    );
    assertTechnologyRule(
        !TechnologyEffectService::isCostPolicyValid(
            'seasonal',
            ['bright' => 1, 'warm' => 100]
        ),
        '季节科研不得消耗亮晶晶 / Seasonal research must not consume bright crystals'
    );
    assertTechnologyRule(
        TechnologyEffectService::isCostPolicyValid(
            'permanent',
            ['bright' => 1000, 'night' => 200]
        ),
        '永久科研应允许亮晶晶和夜静静 / Permanent research should allow persistent currencies'
    );
    assertTechnologyRule(
        !TechnologyEffectService::isCostPolicyValid(
            'permanent',
            ['bright' => 1000, 'warm' => 1]
        ),
        '永久科研不得消耗赛季资源 / Permanent research must not consume seasonal resources'
    );
    assertTechnologyRule(
        !TechnologyEffectService::isCostPolicyValid('permanent', []),
        '科研不得使用空消耗 / Research must not have an empty cost'
    );

    assertTechnologyApproximate(
        0.15,
        TechnologyEffectService::calculateEffectAtLevel(0.05, 3),
        '科技效果应按等级线性累计 / Technology effects should accumulate linearly by level'
    );
    assertTechnologyApproximate(
        115.0,
        TechnologyEffectService::applyFractionalBonus(100, 0.15),
        '百分比效果应正确应用 / Percentage effects should apply correctly'
    );
    assertTechnologyRule(
        TechnologyEffectService::applySpeedBonusToDuration(115, 0.15) === 100,
        '加速效果应缩短耗时 / Speed effects should shorten duration'
    );
    assertTechnologyRule(
        TechnologyEffectService::calculateIntegerLimit(10, 2.9) === 12,
        '整数上限应向下取整科研加成 / Integer caps should floor research bonuses'
    );
    assertTechnologyApproximate(
        12.5,
        TechnologyEffectService::calculateDecimalLimit(10.0, 2.5),
        '小数上限应保留科研加成 / Decimal caps should preserve research bonuses'
    );

    echo "Technology rules tests passed: {$assertions} assertions.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Technology rules tests failed: " . $exception->getMessage() . "\n");
    exit(1);
}

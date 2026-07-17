<?php
// 种火集结号 - 驻城武将加成规则测试 / Fireseed Engage - assigned-general city bonus rule tests

require_once __DIR__ . '/../includes/classes/City.php';

$assertions = 0;

/**
 * 断言两个值严格相等 / Assert that two values are strictly equal
 * @param mixed $expected 期望值 / Expected value
 * @param mixed $actual 实际值 / Actual value
 * @param string $message 断言说明 / Assertion description
 */
function assertCityBonusSame($expected, $actual, $message) {
    global $assertions;
    $assertions++;

    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL
        );
        exit(1);
    }
}

assertCityBonusSame(
    0.0,
    City::clampAssignedGeneralBonus(-25),
    '负百分比会被归零 / Negative percentages are clamped to zero'
);
assertCityBonusSame(
    1000.0,
    City::clampAssignedGeneralBonus(5000),
    '异常大百分比会被封顶 / Excessive percentages are capped'
);
assertCityBonusSame(
    0.0,
    City::clampAssignedGeneralBonus(NAN),
    '非有限百分比会被归零 / Non-finite percentages are rejected'
);
assertCityBonusSame(
    100.0,
    City::applyPercentageBonus(100, 0),
    '零加成保持原值 / Zero bonus preserves the base value'
);
assertCityBonusSame(
    150.0,
    City::applyPercentageBonus(100, 50),
    '生产与防御按百分比增长 / Production and defense grow by percentage'
);
assertCityBonusSame(
    0.0,
    City::applyPercentageBonus(-100, 50),
    '负基础值不会产生负结果 / Negative base values cannot produce negative results'
);
assertCityBonusSame(
    100,
    City::applySpeedBonusToDuration(100, 0),
    '零速度加成保持原时长 / Zero speed bonus preserves duration'
);
assertCityBonusSame(
    80,
    City::applySpeedBonusToDuration(100, 25),
    '速度加成按倍率缩短时长 / Speed bonus shortens duration multiplicatively'
);
assertCityBonusSame(
    1,
    City::applySpeedBonusToDuration(1, 1000),
    '正时长始终至少一秒 / Positive durations remain at least one second'
);
assertCityBonusSame(
    0,
    City::applySpeedBonusToDuration(0, 50),
    '零时长保持为零 / Zero duration remains zero'
);

echo 'City bonus rule tests passed: ' . $assertions . ' assertions.' . PHP_EOL;

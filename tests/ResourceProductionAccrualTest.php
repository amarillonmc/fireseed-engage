<?php
// 种火集结号 - 资源小数产出累计测试 / Fireseed Engage - fractional resource production accrual tests

require_once dirname(__DIR__) . '/includes/classes/Resource.php';

$assertions = 0;

/**
 * 断言小数产出累计结果 / Assert a fractional production result
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertResourceAccrual($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$credited = 0;
$remainder = 0.0;
for ($tick = 0; $tick < 5; $tick++) {
    $settled = Resource::splitProductionAccrual(0.2, $remainder);
    $credited += $settled['whole'];
    $remainder = $settled['remainder'];
}

assertResourceAccrual(
    $credited === 1 && abs($remainder) < 0.000001,
    'Five 0.2 production ticks must credit exactly one resource'
);

$settled = Resource::splitProductionAccrual(1.05, 0.95);
assertResourceAccrual(
    $settled['whole'] === 2
        && abs($settled['remainder']) < 0.000001,
    'Research and carried fractions must cross an integer boundary exactly'
);

$settled = Resource::splitProductionAccrual(-10, 2);
assertResourceAccrual(
    $settled['whole'] === 0
        && abs($settled['remainder'] - 0.999999) < 0.000001,
    'Malformed negative production and oversized remainder must be bounded'
);

echo "Resource production accrual tests passed: {$assertions} assertions.\n";

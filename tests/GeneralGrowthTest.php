<?php
// 种火集结号 - 武将成长回归测试 / Fireseed Engage - General growth regression tests

require_once __DIR__ . '/../config/game_constants.php';
require_once __DIR__ . '/../includes/classes/General.php';

$assertions = 0;

/**
 * 断言武将成长条件 / Assert a general-growth condition
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertGeneralGrowth($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertGeneralGrowth(
    General::calculateLevelUpAttribute(100, 3.0, false) === 104,
    'A COST 3 combat attribute should grow by one stable step'
);
assertGeneralGrowth(
    General::calculateLevelUpAttribute(100, 3.0, true) === 102,
    'Speed should use its lower stable growth rate'
);

$attribute = 100;
for ($level = 1; $level < 100; $level++) {
    $next = General::calculateLevelUpAttribute($attribute, 4.0, false);
    assertGeneralGrowth($next > $attribute, 'Growth must remain monotonic below the cap');
    $attribute = $next;
}
assertGeneralGrowth(
    $attribute < 100000,
    'Level 100 growth must remain within balanced integer scale'
);
assertGeneralGrowth(
    General::calculateLevelUpAttribute(2050830979, 3.0, false)
        === GENERAL_ATTRIBUTE_HARD_CAP,
    'Legacy inflated values must clamp instead of overflowing MySQL INT'
);
assertGeneralGrowth(
    General::calculateLevelUpAttribute(GENERAL_ATTRIBUTE_HARD_CAP, 4.0, false)
        === GENERAL_ATTRIBUTE_HARD_CAP,
    'Attributes at the hard cap must remain representable'
);

echo "General growth tests passed: {$assertions} assertions.\n";

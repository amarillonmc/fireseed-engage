<?php
// 种火集结号 - 侦察规则轻量测试 / Fireseed Engage - Lightweight scouting rules tests

require_once __DIR__ . '/../includes/classes/ScoutingService.php';

$assertions = 0;

/**
 * 断言两个值严格相同 / Assert that two values are strictly identical
 *
 * @param mixed $expected 期望值 / Expected value
 * @param mixed $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertScoutingSame($expected, $actual, $message) {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: "
            . var_export($expected, true)
            . "\nActual: "
            . var_export($actual, true)
        );
    }
}

try {
    assertScoutingSame(
        15,
        ScoutingService::countExclusiveScouts([
            ['soldier_type' => 'scout', 'quantity' => 10],
            ['soldier_type' => 'scout', 'quantity' => 5]
        ]),
        '纯侦察编成应汇总全部侦察兵 / Scout-only compositions must total all scouts'
    );
    assertScoutingSame(
        0,
        ScoutingService::countExclusiveScouts([]),
        '空军队不能执行侦察 / Empty armies cannot scout'
    );
    assertScoutingSame(
        0,
        ScoutingService::countExclusiveScouts([
            ['soldier_type' => 'scout', 'quantity' => 5],
            ['soldier_type' => 'pawn', 'quantity' => 1]
        ]),
        '混编军队不能执行侦察 / Mixed armies cannot scout'
    );
    assertScoutingSame(
        0,
        ScoutingService::countExclusiveScouts([
            ['soldier_type' => 'scout', 'quantity' => 0]
        ]),
        '零兵力军队不能执行侦察 / Zero-strength armies cannot scout'
    );
    assertScoutingSame(
        1,
        ScoutingService::calculateNpcCounterScouts(0),
        '空NPC据点仍至少有一名反侦察单位 / NPC forts always have at least one counter-scout'
    );
    assertScoutingSame(
        1,
        ScoutingService::calculateNpcCounterScouts(20),
        '二十名NPC守军应产生一名反侦察单位 / Twenty NPC defenders yield one counter-scout'
    );
    assertScoutingSame(
        2,
        ScoutingService::calculateNpcCounterScouts(21),
        'NPC反侦察应向上取整 / NPC counter-scouts must round up'
    );
    assertScoutingSame(
        false,
        ScoutingService::doesScoutingSucceed(2, 2),
        '侦察兵数量相等时应失败 / Equal scouting forces must fail'
    );
    assertScoutingSame(
        true,
        ScoutingService::doesScoutingSucceed(3, 2),
        '派出侦察兵严格更多时应成功 / Strictly more attacking scouts must succeed'
    );

    echo "Scouting rules: {$assertions} assertions passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

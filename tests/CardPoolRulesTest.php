<?php
// 种火集结号 - 卡池权重与配置规则测试 / Fireseed Engage - card-pool weight and configuration rule tests

require_once __DIR__ . '/../includes/classes/CardPoolService.php';

$assertionCount = 0;

/**
 * 断言严格相等 / Asserts strict equality
 *
 * @param mixed $expected 期望值 / Expected value
 * @param mixed $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertPoolSame($expected, $actual, $message) {
    global $assertionCount;
    $assertionCount++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

/**
 * 断言调用抛出指定异常 / Asserts that a callback throws the expected exception
 *
 * @param string $exceptionClass 异常类 / Exception class
 * @param callable $callback 被测调用 / Callback under test
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertPoolThrows($exceptionClass, callable $callback, $message) {
    global $assertionCount;
    $assertionCount++;
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException(
            $message . ': ' . get_class($exception) . ' / ' . $exception->getMessage()
        );
    }
    throw new RuntimeException($message . ': no exception was thrown');
}

try {
    assertPoolSame(
        ['bright' => 100, 'night' => 25, 'circuit_points' => 2],
        CardPoolService::normalizeCostBundle(
            '{"night":25,"bright":100,"circuit_points":2,"warm":0}'
        ),
        '成本资源必须按白名单顺序标准化 / Costs must normalize in allow-list order'
    );
    assertPoolSame(
        [],
        CardPoolService::normalizeCostBundle('{}'),
        '空对象必须支持免费卡池 / An empty object must support a free pool'
    );
    assertPoolSame(
        [1, 5, 10],
        CardPoolService::normalizeAllowedCounts('[10,1,5,5]'),
        '抽取次数必须去重并排序 / Draw counts must be unique and sorted'
    );
    assertPoolSame(
        ['night' => 750, 'skill_points' => 6],
        CardPoolService::multiplyCost(
            ['night' => 250, 'skill_points' => 2],
            3
        ),
        '多抽成本必须逐项相乘 / Multi-draw costs must multiply every component'
    );
    assertPoolSame(
        ['bright' => 500],
        CardPoolService::normalizePoolCostBundle(
            'general',
            ['bright' => 500]
        ),
        '武将卡池应只允许亮晶晶 / General pools should allow only Bright Crystals'
    );
    assertPoolSame(
        ['night' => 250],
        CardPoolService::normalizePoolCostBundle(
            'skill',
            ['night' => 250]
        ),
        '技能卡池应只允许夜静静 / Skill pools should allow only Night Crystals'
    );
    assertPoolThrows(
        InvalidArgumentException::class,
        function() {
            CardPoolService::normalizePoolCostBundle(
                'general',
                ['bright' => 500, 'warm' => 1]
            );
        },
        '武将卡池不得混入赛季资源 / General pools must reject seasonal resources'
    );
    assertPoolThrows(
        InvalidArgumentException::class,
        function() {
            CardPoolService::normalizePoolCostBundle(
                'skill',
                ['bright' => 250]
            );
        },
        '技能卡池不得消耗亮晶晶 / Skill pools must reject Bright Crystals'
    );

    $entries = [
        ['resource_id' => 11, 'weight' => 2],
        ['resource_id' => 22, 'weight' => 3],
        ['resource_id' => 33, 'weight' => 5]
    ];
    assertPoolSame(
        11,
        CardPoolService::selectWeightedEntry($entries, 1)['resource_id'],
        '第一个权重边界必须选中第一项 / The first weight boundary must select the first entry'
    );
    assertPoolSame(
        11,
        CardPoolService::selectWeightedEntry($entries, 2)['resource_id'],
        '第一项上界必须仍选中第一项 / The first entry upper boundary must remain inclusive'
    );
    assertPoolSame(
        22,
        CardPoolService::selectWeightedEntry($entries, 3)['resource_id'],
        '第二项起点必须选中第二项 / The second entry lower boundary must select it'
    );
    $lastSelection = CardPoolService::selectWeightedEntry($entries, 10);
    assertPoolSame(
        33,
        $lastSelection['resource_id'],
        '总权重上界必须选中最后一项 / The total-weight boundary must select the final entry'
    );
    assertPoolSame(
        5,
        $lastSelection['entry_weight'],
        '返回值必须携带成员权重快照 / Selection must include the entry-weight snapshot'
    );
    assertPoolSame(
        10,
        $lastSelection['total_weight'],
        '返回值必须携带总权重快照 / Selection must include the total-weight snapshot'
    );

    assertPoolThrows(
        InvalidArgumentException::class,
        function() {
            CardPoolService::normalizeCostBundle('[]');
        },
        '数组不能伪装成成本对象 / A JSON array must not masquerade as a cost object'
    );
    assertPoolThrows(
        InvalidArgumentException::class,
        function() {
            CardPoolService::normalizeCostBundle('{"premium":1}');
        },
        '未授权的课金资源键必须被拒绝 / An unsupported premium-currency key must be rejected'
    );
    assertPoolThrows(
        InvalidArgumentException::class,
        function() {
            CardPoolService::normalizeAllowedCounts('[0,1]');
        },
        '零次抽取必须被拒绝 / A zero draw count must be rejected'
    );
    assertPoolThrows(
        InvalidArgumentException::class,
        function() use ($entries) {
            CardPoolService::selectWeightedEntry($entries, 11);
        },
        '超出总权重的落点必须被拒绝 / A roll above total weight must be rejected'
    );
    assertPoolThrows(
        InvalidArgumentException::class,
        function() {
            CardPoolService::selectWeightedEntry([
                ['resource_id' => 1, 'weight' => 0]
            ], 1);
        },
        '非正成员权重必须被拒绝 / Non-positive entry weights must be rejected'
    );

    echo "Card-pool rules tests passed: {$assertionCount} assertions.\n";
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "Card-pool rules tests failed: " . $exception->getMessage() . "\n"
    );
    exit(1);
}

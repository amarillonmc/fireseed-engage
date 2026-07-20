<?php
// 种火集结号 - 武将编制上限规则测试 / Fireseed Engage - general roster-limit rule tests

$root = dirname(__DIR__);
require_once $root . '/includes/classes/General.php';

$assertions = 0;

/**
 * 断言武将编制规则 / Assert a general roster rule
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertGeneralRosterRule($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$roster = [
    [
        'assignment_id' => 30,
        'owner_id' => 7,
        'cost' => 6,
        'is_active' => 1
    ],
    [
        'assignment_id' => 10,
        'owner_id' => 7,
        'cost' => 5,
        'is_active' => 1
    ],
    [
        'assignment_id' => 20,
        'owner_id' => 7,
        'cost' => 4,
        'is_active' => 1
    ]
];

$overflow = General::calculateOverflowAssignmentIds(
    $roster,
    2,
    10,
    7,
    [10, 20]
);
assertGeneralRosterRule(
    $overflow === [10],
    'Existing assignments must outrank transfers while a later cheaper transfer may still fit'
);
assertGeneralRosterRule(
    $roster[0]['assignment_id'] === 30,
    'Overflow calculation must not mutate caller roster order'
);

$overflow = General::calculateOverflowAssignmentIds(
    $roster,
    2,
    10,
    7
);
assertGeneralRosterRule(
    $overflow === [30],
    'Without transfer preference, assignment IDs must provide deterministic priority'
);

$overflow = General::calculateOverflowAssignmentIds(
    [
        [
            'assignment_id' => 30,
            'owner_id' => 7,
            'cost' => 1,
            'is_active' => 1
        ],
        [
            'assignment_id' => 10,
            'owner_id' => 7,
            'cost' => 1,
            'is_active' => 1
        ],
        [
            'assignment_id' => 20,
            'owner_id' => 7,
            'cost' => 1,
            'is_active' => 1
        ]
    ],
    2,
    100,
    7,
    [10, 20]
);
assertGeneralRosterRule(
    $overflow === [20],
    'Headcount overflow must remove transferred assignments after incumbents'
);

$overflow = General::calculateOverflowAssignmentIds(
    [
        [
            'assignment_id' => 1,
            'owner_id' => 7,
            'cost' => 9,
            'is_active' => 1
        ],
        [
            'assignment_id' => 2,
            'owner_id' => 7,
            'cost' => 999,
            'is_active' => 0
        ],
        [
            'assignment_id' => 3,
            'owner_id' => 7,
            'cost' => 1,
            'is_active' => 1
        ]
    ],
    2,
    10,
    7
);
assertGeneralRosterRule(
    $overflow === [],
    'Inactive owned generals must not consume headcount or COST'
);

$overflow = General::calculateOverflowAssignmentIds(
    [
        [
            'assignment_id' => 1,
            'owner_id' => 8,
            'cost' => 0,
            'is_active' => 0
        ]
    ],
    10,
    100,
    7
);
assertGeneralRosterRule(
    $overflow === [1],
    'A target roster must reject assignments owned by another player'
);

$overflow = General::calculateOverflowAssignmentIds(
    [
        [
            'assignment_id' => 1,
            'owner_id' => 7,
            'cost' => -10,
            'is_active' => 1
        ]
    ],
    1,
    0,
    7
);
assertGeneralRosterRule(
    $overflow === [],
    'Negative legacy COST values must be treated as zero'
);

echo "General roster rule tests passed: {$assertions} assertions.\n";

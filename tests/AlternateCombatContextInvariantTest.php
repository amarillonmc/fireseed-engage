<?php
// 种火集结号 - 非标准战斗技能上下文不变量测试 / Fireseed Engage - Alternate-combat skill-context invariant tests

require_once __DIR__ . '/../includes/classes/SkillMechanismRegistry.php';
require_once __DIR__ . '/../includes/classes/SkillValueResolver.php';
require_once __DIR__ . '/../includes/classes/SkillDefinitionValidator.php';

$assertions = 0;

/**
 * 断言非标准战斗接线与目录约束 / Asserts alternate-combat wiring and catalog constraints
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertAlternateCombatInvariant($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 构造带单一条件的被动机制 / Builds a passive mechanism with one condition
 *
 * @param string $mechanism 机制代码 / Mechanism code
 * @param array $condition 条件 / Condition
 * @return array 技能定义 / Skill definition
 */
function alternateCombatDefinition($mechanism, array $condition) {
    return [
        'schema_version' => 2,
        'effects' => [
            [
                'mechanism' => $mechanism,
                'parameters' => [],
                'value' => 10,
                'conditions' => [$condition]
            ]
        ]
    ];
}

/**
 * 构造带多个AND条件的被动机制 / Builds a passive mechanism with multiple AND conditions
 *
 * @param string $mechanism 机制代码 / Mechanism code
 * @param array $parameters 机制参数 / Mechanism parameters
 * @param array $conditions 条件列表 / Condition list
 * @return array 技能定义 / Skill definition
 */
function alternateCombatDefinitionWithConditions(
    $mechanism,
    array $parameters,
    array $conditions
) {
    return [
        'schema_version' => 2,
        'effects' => [
            [
                'mechanism' => $mechanism,
                'parameters' => $parameters,
                'value' => 10,
                'conditions' => $conditions
            ]
        ]
    ];
}

try {
    $catalog = SkillMechanismRegistry::publicCatalog();
    foreach ([
        'army_siege_damage_percent',
        'army_siege_damage_flat',
        'army_siege_damage_multiplier'
    ] as $mechanism) {
        assertAlternateCombatInvariant(
            $catalog[$mechanism]['allowed_condition_values']['side']
                === ['attack'],
            '攻城机制只能配置进攻方条件 / Siege mechanisms must only allow the attack side'
        );
        assertAlternateCombatInvariant(
            $catalog[$mechanism]['allowed_condition_values']['target_tag']
                === ['city', 'structure', 'player'],
            '攻城机制只能配置实际城池目标标签 / Siege mechanisms must only expose real city target tags'
        );
    }
    assertAlternateCombatInvariant(
        $catalog['city_defense_percent']['allowed_condition_values']['side']
            === ['defense']
            && $catalog['city_defense_percent']
                ['allowed_condition_values']['target_tag']
                === ['army', 'player'],
        '城防机制只能配置实际防守上下文 / City defense must only expose its real defense context'
    );

    $invalidSiegeSide = SkillDefinitionValidator::validate(
        alternateCombatDefinition(
            'army_siege_damage_percent',
            [
                'type' => 'side',
                'operator' => 'eq',
                'value' => 'defense'
            ]
        ),
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        !$invalidSiegeSide['valid'],
        '永不触发的防守方攻城条件必须被拒绝 / An unreachable defense-side siege condition must be rejected'
    );

    $invalidSiegeTarget = SkillDefinitionValidator::validate(
        alternateCombatDefinition(
            'army_siege_damage_flat',
            [
                'type' => 'target_tag',
                'operator' => 'in',
                'value' => ['city', 'npc']
            ]
        ),
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        !$invalidSiegeTarget['valid'],
        '攻城目标列表含无消费者标签时必须整项拒绝 / A siege target list containing a hookless tag must be rejected'
    );

    $validSiegeContext = SkillDefinitionValidator::validate(
        [
            'schema_version' => 2,
            'effects' => [
                [
                    'mechanism' => 'army_siege_damage_multiplier',
                    'parameters' => [],
                    'value' => 2,
                    'conditions' => [
                        [
                            'type' => 'side',
                            'operator' => 'eq',
                            'value' => 'attack'
                        ],
                        [
                            'type' => 'target_tag',
                            'operator' => 'in',
                            'value' => ['city', 'structure']
                        ]
                    ]
                ]
            ]
        ],
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        $validSiegeContext['valid'],
        '实际攻城上下文必须保持可配置 / The real siege context must remain configurable'
    );

    $invalidCityDefenseSide = SkillDefinitionValidator::validate(
        alternateCombatDefinition(
            'city_defense_percent',
            [
                'type' => 'side',
                'operator' => 'eq',
                'value' => 'attack'
            ]
        ),
        1,
        'passive'
    );
    $invalidCityDefenseTarget = SkillDefinitionValidator::validate(
        alternateCombatDefinition(
            'city_defense_percent',
            [
                'type' => 'target_tag',
                'operator' => 'eq',
                'value' => 'city'
            ]
        ),
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        !$invalidCityDefenseSide['valid']
            && !$invalidCityDefenseTarget['valid'],
        '城防机制必须拒绝进攻方与城池目标组合 / City defense must reject attack-side and city-target combinations'
    );

    $validCityDefenseContext = SkillDefinitionValidator::validate(
        [
            'schema_version' => 2,
            'effects' => [
                [
                    'mechanism' => 'city_defense_percent',
                    'parameters' => [],
                    'value' => 20,
                    'conditions' => [
                        [
                            'type' => 'side',
                            'operator' => 'eq',
                            'value' => 'defense'
                        ],
                        [
                            'type' => 'target_tag',
                            'operator' => 'in',
                            'value' => ['army', 'player']
                        ]
                    ]
                ]
            ]
        ],
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        $validCityDefenseContext['valid'],
        '实际城防上下文必须保持可配置 / The real city-defense context must remain configurable'
    );

    $unreachableFixedTagExclusion = SkillDefinitionValidator::validate(
        alternateCombatDefinition(
            'city_defense_percent',
            [
                'type' => 'target_tag',
                'operator' => 'not_in',
                'value' => ['army']
            ]
        ),
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        !$unreachableFixedTagExclusion['valid'],
        '固定多标签消费者不得接受必定冲突的排除条件'
            . ' / Fixed multi-tag consumers must reject exclusions that always conflict'
    );

    $unreachableSideConjunction = SkillDefinitionValidator::validate(
        alternateCombatDefinitionWithConditions(
            'army_stat_percent',
            [
                'stat' => 'attack',
                'unit_type' => 'all'
            ],
            [
                [
                    'type' => 'side',
                    'operator' => 'eq',
                    'value' => 'attack'
                ],
                [
                    'type' => 'side',
                    'operator' => 'eq',
                    'value' => 'defense'
                ]
            ]
        ),
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        !$unreachableSideConjunction['valid'],
        '互斥攻守方AND条件必须在保存前被拒绝'
            . ' / Mutually exclusive side conditions must be rejected before save'
    );

    $unreachableCrossContext = SkillDefinitionValidator::validate(
        alternateCombatDefinitionWithConditions(
            'army_stat_percent',
            [
                'stat' => 'attack',
                'unit_type' => 'all'
            ],
            [
                [
                    'type' => 'side',
                    'operator' => 'eq',
                    'value' => 'defense'
                ],
                [
                    'type' => 'target_tag',
                    'operator' => 'eq',
                    'value' => 'npc'
                ]
            ]
        ),
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        !$unreachableCrossContext['valid'],
        '条件组合必须匹配同一个真实消费者上下文'
            . ' / Condition conjunctions must match one shared real consumer context'
    );

    $reachableCrossContext = SkillDefinitionValidator::validate(
        alternateCombatDefinitionWithConditions(
            'army_stat_percent',
            [
                'stat' => 'attack',
                'unit_type' => 'all'
            ],
            [
                [
                    'type' => 'side',
                    'operator' => 'eq',
                    'value' => 'attack'
                ],
                [
                    'type' => 'target_tag',
                    'operator' => 'eq',
                    'value' => 'army'
                ],
                [
                    'type' => 'target_tag',
                    'operator' => 'not_in',
                    'value' => ['player']
                ]
            ]
        ),
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        $reachableCrossContext['valid'],
        '攻击NPC军队的可达标签组合必须保持合法'
            . ' / Reachable attack-side NPC-army tag combinations must remain valid'
    );

    foreach ([1023, 1024] as $unreachableDistance) {
        $unreachableDistanceContext = SkillDefinitionValidator::validate(
            alternateCombatDefinitionWithConditions(
                'army_stat_percent',
                [
                    'stat' => 'attack',
                    'unit_type' => 'all'
                ],
                [
                    [
                        'type' => 'distance',
                        'operator' => 'eq',
                        'value' => $unreachableDistance
                    ]
                ]
            ),
            1,
            'passive'
        );
        assertAlternateCombatInvariant(
            !$unreachableDistanceContext['valid'],
            '超出512乘512地图真实曼哈顿上限的距离必须被拒绝'
                . ' / Distances beyond the real 512-by-512 Manhattan limit must be rejected'
        );
    }

    $maximumReachableDistanceContext = SkillDefinitionValidator::validate(
        alternateCombatDefinitionWithConditions(
            'army_stat_percent',
            [
                'stat' => 'attack',
                'unit_type' => 'all'
            ],
            [
                [
                    'type' => 'distance',
                    'operator' => 'eq',
                    'value' => 1022
                ]
            ]
        ),
        1,
        'passive'
    );
    assertAlternateCombatInvariant(
        $maximumReachableDistanceContext['valid'],
        '地图对角间的最大真实曼哈顿距离必须保持可达'
            . ' / The maximum real corner-to-corner Manhattan distance must remain reachable'
    );

    $challenge = file_get_contents(
        __DIR__ . '/../includes/classes/ChallengeService.php'
    );
    assertAlternateCombatInvariant(
        strpos($challenge, '$arenaAttackerContext') !== false
            && strpos($challenge, '$arenaDefenderContext') !== false
            && preg_match(
                '/getCombatPower\(\s*\$arenaAttackerContext\s*\)/s',
                $challenge
            ) === 1
            && preg_match(
                '/getCombatPower\(\s*\$arenaDefenderContext\s*\)/s',
                $challenge
            ) === 1,
        '竞技场双方战力必须使用明确攻守上下文 / Both Arena powers must use explicit side contexts'
    );
    assertAlternateCombatInvariant(
        strpos($challenge, '$towerBattleContext') !== false
            && strpos(
                $challenge,
                'getCombatPower($towerBattleContext)'
            ) !== false
            && strpos($challenge, '$raidBattleContext') !== false
            && strpos(
                $challenge,
                'getCombatPower($raidBattleContext)'
            ) !== false,
        '塔与讨伐战力必须使用明确目标上下文 / Tower and Raid powers must use explicit target contexts'
    );
    assertAlternateCombatInvariant(
        strpos($challenge, "'combat_context' =>") !== false
            && strpos(
                $challenge,
                'getCombatPower($combatContext)'
            ) !== false,
        '锁后军队校验必须沿用调用方权威上下文 / Post-lock army validation must retain the caller-authoritative context'
    );
    assertAlternateCombatInvariant(
        strpos($challenge, 'getCombatPower()') === false,
        '挑战服务不得保留无上下文战力调用 / Challenge service must retain no contextless combat-power call'
    );

    $season = file_get_contents(
        __DIR__ . '/../includes/classes/SeasonService.php'
    );
    assertAlternateCombatInvariant(
        strpos($season, '$siteBattleContext') !== false
            && substr_count(
                $season,
                'getCombatPower($siteBattleContext)'
            ) >= 2,
        '赛季地点战的锁后校验与结算必须共享上下文 / Season-site validation and resolution must share one context'
    );
    assertAlternateCombatInvariant(
        preg_match(
            '/getDamageReduction\(\s*\$siteBattleContext\s*\)/s',
            $season
        ) === 1
            && strpos(
                $season,
                'applyDamageReductionToLossRate('
            ) !== false,
        '赛季地点实际战损必须消费同上下文减免 / Actual Season-site casualties must consume context-aware reduction'
    );
    assertAlternateCombatInvariant(
        strpos($season, 'getCombatPower()') === false,
        '赛季服务不得保留无上下文战力调用 / Season service must retain no contextless combat-power call'
    );

    $armySelect = file_get_contents(__DIR__ . '/../army_select.php');
    assertAlternateCombatInvariant(
        strpos(
            $armySelect,
            "['city', 'structure', 'player']"
        ) !== false
            && strpos(
                $armySelect,
                "\$targetTags = ['tile', 'npc', 'structure']"
            ) !== false
            && strpos(
                $armySelect,
                "'target_tags' => \$targetTags"
            ) !== false,
        '出征页必须按权威目标类型构造技能标签 / Dispatch preview must build skill tags from the authoritative target type'
    );
    assertAlternateCombatInvariant(
        strpos($armySelect, '$army->getCombatPower($battleContext)') !== false
            && strpos(
                $armySelect,
                '$army->getMovementSpeed($marchContext)'
            ) !== false
            && strpos($armySelect, '$army->getCombatPower()') === false
            && strpos($armySelect, '$army->getMovementSpeed()') === false,
        '出征预览必须与真实战斗和行军共享条件上下文 / Dispatch previews must share the real battle and march contexts'
    );

    $moveArmy = file_get_contents(__DIR__ . '/../move_army.php');
    assertAlternateCombatInvariant(
        strpos($moveArmy, "\$marchContext = ['phase' => 'march']") !== false
            && strpos(
                $moveArmy,
                "\$marchContext['distance'] = \$moveDistance"
            ) !== false
            && strpos(
                $moveArmy,
                '$army->getMovementSpeed($marchContext)'
            ) !== false
            && strpos($moveArmy, '$previewMovementSeconds') !== false,
        '移动页必须用已知曼哈顿距离预览实际行军速度与时间 / Movement previews must use known Manhattan distance for actual speed and travel time'
    );
    assertAlternateCombatInvariant(
        strpos(
            $moveArmy,
            '$army->getCombatPower($baseCombatContext)'
        ) !== false
            && strpos($moveArmy, '未应用攻守方与目标条件') !== false
            && strpos($moveArmy, '$army->getCombatPower()') === false
            && strpos($moveArmy, '$army->getMovementSpeed()') === false,
        '未知战斗目标只能显示明确标注的无目标基准 / An unknown battle target must use an explicitly labeled targetless baseline'
    );

    $seasonPage = file_get_contents(__DIR__ . '/../season.php');
    assertAlternateCombatInvariant(
        strpos($seasonPage, "\$siteTargetTags = ['structure']") !== false
            && strpos($seasonPage, "? 'npc'") !== false
            && strpos($seasonPage, ": 'player'") !== false
            && strpos($seasonPage, "'side' => 'attack'") !== false
            && strpos(
                $seasonPage,
                "'target_tags' => \$siteTargetTags"
            ) !== false
            && strpos($seasonPage, "'distance' => 0") !== false,
        '赛季地点预览必须使用同址进攻方与实际拥有者标签 / Season-site previews must use colocated attack-side and real-owner tags'
    );
    assertAlternateCombatInvariant(
        strpos(
            $seasonPage,
            "(int) \$position[0] !== (int) \$site['x']"
        ) !== false
            && strpos(
                $seasonPage,
                "(int) \$position[1] !== (int) \$site['y']"
            ) !== false
            && preg_match(
                '/getCombatPower\(\s*\$siteBattleContext\s*\)/s',
                $seasonPage
            ) === 1
            && strpos($seasonPage, '$army->getCombatPower()') === false,
        '赛季军队筛选与显示必须复用同址地点战上下文 / Season army filtering and display must reuse the colocated site context'
    );

    $challengesPage = file_get_contents(__DIR__ . '/../challenges.php');
    assertAlternateCombatInvariant(
        strpos(
            $challengesPage,
            "'target_tags' => ['army', 'npc']"
        ) !== false
            && strpos(
                $challengesPage,
                "'target_tags' => ['npc', 'structure']"
            ) !== false
            && substr_count(
                $challengesPage,
                "'target_tags' => ['army', 'player']"
            ) >= 2
            && substr_count($challengesPage, "'distance' => 0") >= 4,
        '挑战页必须声明塔、讨伐与竞技场的权威同址目标 / Challenge previews must declare authoritative colocated Tower, Raid, and Arena targets'
    );
    assertAlternateCombatInvariant(
        strpos(
            $challengesPage,
            '$army->getCombatPower($towerBattleContext)'
        ) !== false
            && strpos(
                $challengesPage,
                '$army->getCombatPower($raidBattleContext)'
            ) !== false
            && strpos(
                $challengesPage,
                '$army->getCombatPower($arenaAttackContext)'
            ) !== false
            && strpos(
                $challengesPage,
                '$army->getCombatPower($arenaDefenseContext)'
            ) !== false
            && strpos(
                $challengesPage,
                '$army->getCombatPower()'
            ) === false,
        '各挑战列表必须按自身攻守目标上下文筛选和显示 / Every challenge list must filter and display with its own side-target context'
    );

    $builder = file_get_contents(
        __DIR__ . '/../assets/js/admin-skills.js'
    );
    assertAlternateCombatInvariant(
        strpos($builder, 'allowed_condition_values') !== false
            && strpos($builder, 'scope.values[type]') !== false,
        '后台必须按机制目录过滤条件值 / The admin builder must filter condition values by mechanism catalog'
    );

    echo 'Alternate combat context invariant tests passed: '
        . $assertions . ' assertions.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

<?php
// 种火集结号 - 可组合技能机制规则测试 / Fireseed Engage - Composable skill-mechanism rule tests

require_once __DIR__ . '/../includes/classes/SkillMechanismRegistry.php';
require_once __DIR__ . '/../includes/classes/SkillValueResolver.php';
require_once __DIR__ . '/../includes/classes/SkillDefinitionValidator.php';
require_once __DIR__ . '/../includes/classes/SkillEffectEngine.php';

$assertions = 0;

/**
 * 断言两个值严格相同 / Assert that two values are strictly identical
 *
 * @param mixed $expected 期望值 / Expected value
 * @param mixed $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSkillMechanismSame($expected, $actual, $message) {
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
 * 断言浮点值近似相同 / Assert that two floating-point values are approximately equal
 *
 * @param float $expected 期望值 / Expected value
 * @param float $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSkillMechanismFloat($expected, $actual, $message) {
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
    assertSkillMechanismSame(
        1,
        SkillValueResolver::clampSkillLevel(0, 5),
        '求值等级不得低于一级 / Evaluated skill level must not fall below one'
    );
    assertSkillMechanismSame(
        5,
        SkillValueResolver::clampSkillLevel(9, 5),
        '求值等级不得超过目录上限 / Evaluated skill level must not exceed the catalog maximum'
    );
    assertSkillMechanismSame(
        3,
        SkillValueResolver::clampSkillLevel(3, 5),
        '合法求值等级必须保持不变 / A valid evaluated skill level must remain unchanged'
    );

    assertSkillMechanismSame(
        true,
        SkillMechanismRegistry::isImplemented('army_stat_percent'),
        '军队属性机制必须已经注册 / Army-stat mechanism must be registered'
    );
    assertSkillMechanismSame(
        false,
        SkillMechanismRegistry::isImplemented('treasure_find_chance'),
        '寻宝机制必须保持占位 / Treasure finding must remain a placeholder'
    );
    $questRewardMechanism = SkillMechanismRegistry::get(
        'quest_reward_percent'
    );
    assertSkillMechanismSame(
        50.0,
        $questRewardMechanism['value']['maximum'],
        '任务奖励全局光环的单项上限必须与50%总上限一致 / Quest-reward aura values must respect the 50% global cap'
    );

    $compositeDefinition = [
        'schema_version' => 2,
        'cooldown' => [
            'mode' => 'level_values',
            'values' => [3600, 3540, 3480]
        ],
        'duration' => [
            'mode' => 'level_values',
            'values' => [180, 240, 300]
        ],
        'effects' => [
            [
                'mechanism' => 'army_stat_percent',
                'parameters' => [
                    'stat' => 'attack',
                    'unit_type' => 'all'
                ],
                'value' => [
                    'mode' => 'cost_level_values',
                    'values' => [20, 25, 30]
                ],
                'conditions' => [
                    [
                        'type' => 'side',
                        'operator' => 'eq',
                        'value' => 'attack'
                    ],
                    [
                        'type' => 'distance',
                        'operator' => 'lte',
                        'value' => 10
                    ]
                ]
            ],
            [
                'mechanism' => 'army_stat_percent',
                'parameters' => [
                    'stat' => 'speed',
                    'unit_type' => 'knight'
                ],
                'value' => [
                    'mode' => 'cost_plus_intelligence_level_values',
                    'values' => [
                        ['cost' => 0.5, 'intelligence' => 0.01],
                        ['cost' => 1.0, 'intelligence' => 0.03],
                        ['cost' => 1.5, 'intelligence' => 0.05]
                    ]
                ]
            ]
        ]
    ];

    $validation = SkillDefinitionValidator::validate(
        $compositeDefinition,
        3,
        'active'
    );
    assertSkillMechanismSame(
        true,
        $validation['valid'],
        '合法复合技能必须通过中央校验 / A valid composite skill must pass central validation'
    );

    $evaluation = SkillEffectEngine::evaluate(
        $validation['definition'],
        [
            'skill_level' => 2,
            'general_cost' => 3.0,
            'general_intelligence' => 100,
            'side' => 'attack',
            'phase' => 'battle',
            'target_tags' => ['npc', 'tile', 'structure'],
            'distance' => 8
        ]
    );
    assertSkillMechanismSame(
        true,
        $evaluation['valid'],
        '合法定义必须可求值 / A valid definition must be evaluable'
    );
    assertSkillMechanismFloat(
        75.0,
        $evaluation['modifiers']['attack'],
        'COST等级曲线必须解析为攻击加成 / Cost-level curves must resolve into attack bonuses'
    );
    assertSkillMechanismFloat(
        6.0,
        $evaluation['modifiers']['unit_speed_knight'],
        'COST×智力曲线必须解析到指定兵种 / Cost-intelligence curves must resolve for the targeted unit'
    );
    assertSkillMechanismSame(
        3540,
        $evaluation['cooldown_seconds'],
        '冷却必须按当前技能等级读取曲线 / Cooldown must use the current skill-level curve'
    );
    assertSkillMechanismSame(
        240,
        $evaluation['duration_seconds'],
        '持续时间必须按当前技能等级读取曲线 / Duration must use the current skill-level curve'
    );

    $boundedLifecycleDefinition = [
        'schema_version' => 2,
        'application_mode' => 'timed',
        'cooldown' => [
            'mode' => 'cost_level_values',
            'values' => [31536000]
        ],
        'duration' => [
            'mode' => 'cost_level_values',
            'values' => [31536000]
        ],
        'effects' => [
            [
                'mechanism' => 'army_stat_percent',
                'parameters' => [
                    'stat' => 'attack',
                    'unit_type' => 'all'
                ],
                'value' => 1
            ]
        ]
    ];
    $boundedLifecycleValidation = SkillDefinitionValidator::validate(
        $boundedLifecycleDefinition,
        1,
        'active'
    );
    assertSkillMechanismSame(
        true,
        $boundedLifecycleValidation['valid'],
        '合法的COST生命周期曲线必须通过校验 / A valid cost-scaled lifecycle curve must pass validation'
    );
    $boundedLifecycleEvaluation = SkillEffectEngine::evaluate(
        $boundedLifecycleValidation['definition'],
        [
            'skill_level' => 1,
            'max_level' => 1,
            'general_cost' => 1.0e20,
            'general_intelligence' => 100,
            'phase' => 'battle'
        ]
    );
    assertSkillMechanismSame(
        31536000,
        $boundedLifecycleEvaluation['cooldown_seconds'],
        '解析后的冷却不得超过一年 / Resolved cooldown must not exceed one year'
    );
    assertSkillMechanismSame(
        31536000,
        $boundedLifecycleEvaluation['duration_seconds'],
        '解析后的持续时间不得超过一年 / Resolved duration must not exceed one year'
    );

    $outOfRange = SkillEffectEngine::evaluate(
        $validation['definition'],
        [
            'skill_level' => 2,
            'general_cost' => 3.0,
            'general_intelligence' => 100,
            'side' => 'attack',
            'phase' => 'battle',
            'target_tags' => ['npc'],
            'distance' => 11
        ]
    );
    assertSkillMechanismSame(
        false,
        isset($outOfRange['modifiers']['attack']),
        '超过距离限制时相应效果不得生效 / A distance-gated effect must not apply out of range'
    );
    assertSkillMechanismFloat(
        6.0,
        $outOfRange['modifiers']['unit_speed_knight'],
        '无距离条件的同卡机制仍须生效 / An ungated mechanism on the same card must still apply'
    );

    $passiveDefinition = [
        'schema_version' => 2,
        'effects' => [
            [
                'mechanism' => 'city_resource_production_percent',
                'parameters' => ['resource' => 'bright'],
                'value' => [
                    'mode' => 'level_values',
                    'values' => [10, 15, 20]
                ]
            ],
            [
                'mechanism' => 'city_training_speed_percent',
                'parameters' => ['unit_type' => 'pawn'],
                'value' => 12
            ],
            [
                'mechanism' => 'army_element_stat_percent',
                'parameters' => [
                    'element' => 'warm',
                    'stat' => 'defense'
                ],
                'value' => 7
            ]
        ]
    ];
    $passiveValidation = SkillDefinitionValidator::validate(
        $passiveDefinition,
        3,
        'passive'
    );
    assertSkillMechanismSame(
        true,
        $passiveValidation['valid'],
        '跨领域被动复合技能必须可配置 / A cross-domain passive composite must be configurable'
    );
    $passiveEvaluation = SkillEffectEngine::evaluate(
        $passiveValidation['definition'],
        [
            'skill_level' => 2,
            'general_cost' => 2.5,
            'general_intelligence' => 80,
            'phase' => 'production'
        ]
    );
    assertSkillMechanismFloat(
        15.0,
        $passiveEvaluation['modifiers']['production_bright'],
        '分资源生产机制必须生成明确效果键 / Resource-specific production must compile to an explicit modifier'
    );
    assertSkillMechanismFloat(
        12.0,
        $passiveEvaluation['modifiers']['training_speed_pawn'],
        '兵种训练机制必须生成明确效果键 / Unit-specific training must compile to an explicit modifier'
    );
    assertSkillMechanismFloat(
        7.0,
        $passiveEvaluation['modifiers']['element_defense_per_warm'],
        '元素叠层机制必须生成现有运行时键 / Element stacking must compile to the runtime key'
    );

    $unknownDefinition = $passiveDefinition;
    $unknownDefinition['effects'][0]['mechanism'] = 'execute_arbitrary_php';
    $unknownValidation = SkillDefinitionValidator::validate(
        $unknownDefinition,
        3,
        'passive'
    );
    assertSkillMechanismSame(
        false,
        $unknownValidation['valid'],
        '未知机制必须被拒绝而非静默保存 / Unknown mechanisms must be rejected instead of silently saved'
    );

    $placeholderDefinition = $passiveDefinition;
    $placeholderDefinition['effects'][0]['mechanism'] =
        'treasure_find_chance';
    $placeholderValidation = SkillDefinitionValidator::validate(
        $placeholderDefinition,
        3,
        'passive'
    );
    assertSkillMechanismSame(
        false,
        $placeholderValidation['valid'],
        '占位机制必须禁止启用 / Placeholder mechanisms must not be activatable'
    );

    $shortCurveDefinition = $passiveDefinition;
    $shortCurveDefinition['effects'][0]['value']['values'] = [10, 15];
    $shortCurveValidation = SkillDefinitionValidator::validate(
        $shortCurveDefinition,
        3,
        'passive'
    );
    assertSkillMechanismSame(
        false,
        $shortCurveValidation['valid'],
        '等级曲线项数不得少于最高等级 / Level curves cannot be shorter than max_level'
    );
    $longCurveDefinition = $passiveDefinition;
    $longCurveDefinition['effects'][0]['value']['values'] = [
        10,
        15,
        20,
        25
    ];
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            $longCurveDefinition,
            3,
            'passive'
        )['valid'],
        '等级曲线项数不得超过最高等级 / Level curves cannot exceed max_level'
    );

    $activeWithoutDuration = [
        'schema_version' => 2,
        'effects' => [
            [
                'mechanism' => 'army_stat_percent',
                'parameters' => [
                    'stat' => 'attack',
                    'unit_type' => 'all'
                ],
                'value' => 20
            ]
        ]
    ];
    $activeWithoutDurationValidation =
        SkillDefinitionValidator::validate(
            $activeWithoutDuration,
            1,
            'active'
        );
    assertSkillMechanismSame(
        false,
        $activeWithoutDurationValidation['valid'],
        '限时主动加成必须提供持续时间 / Timed active modifiers must provide a duration'
    );

    $activeActionDefinition = [
        'schema_version' => 2,
        'cooldown' => 3600,
        'effects' => [
            [
                'mechanism' => 'grant_resources',
                'parameters' => ['resource' => 'all'],
                'value' => [
                    'mode' => 'level_values',
                    'values' => [100, 200, 300]
                ]
            ],
            [
                'mechanism' => 'heal_generals',
                'parameters' => ['target' => 'self'],
                'value' => 25
            ]
        ]
    ];
    $activeActionValidation = SkillDefinitionValidator::validate(
        $activeActionDefinition,
        3,
        'active'
    );
    assertSkillMechanismSame(
        true,
        $activeActionValidation['valid'],
        '即时主动机制不应强制持续时间 / Instant active mechanisms must not require a duration'
    );
    $activeActionEvaluation = SkillEffectEngine::evaluate(
        $activeActionValidation['definition'],
        [
            'skill_level' => 3,
            'general_cost' => 2.0,
            'general_intelligence' => 50,
            'phase' => 'activation'
        ]
    );
    assertSkillMechanismSame(
        2,
        count($activeActionEvaluation['actions']),
        '复合主动技能必须保留全部即时动作 / Composite active skills must retain every instant action'
    );
    assertSkillMechanismFloat(
        300.0,
        $activeActionEvaluation['actions'][0]['value'],
        '主动动作必须解析等级曲线 / Active actions must resolve level curves'
    );

    $skillPowerActionEvaluation = SkillEffectEngine::evaluate(
        $activeActionValidation['definition'],
        [
            'skill_level' => 3,
            'general_cost' => 2.0,
            'general_intelligence' => 50,
            'skill_power_percent' => 20.0,
            'phase' => 'activation'
        ]
    );
    assertSkillMechanismFloat(
        360.0,
        $skillPowerActionEvaluation['actions'][0]['value'],
        '技能威力必须一致放大即时动作 / Skill power must consistently amplify instant actions'
    );

    $unsupportedLegacyPassive = SkillDefinitionValidator::validate(
        [
            'attack' => [
                'mode' => 'intelligence_level_values',
                'values' => [1]
            ]
        ],
        1,
        'passive'
    );
    assertSkillMechanismSame(
        false,
        $unsupportedLegacyPassive['valid'],
        '旧被动格式不得接受运行时无法执行的曲线 / Legacy passive data must reject curves its runtime cannot execute'
    );
    $unsupportedLegacyActive = SkillDefinitionValidator::validate(
        [
            'all_resources' => [
                'mode' => 'level_values',
                'values' => [100]
            ]
        ],
        1,
        'active'
    );
    assertSkillMechanismSame(
        false,
        $unsupportedLegacyActive['valid'],
        '旧主动格式不得接受运行时无法执行的曲线 / Legacy active data must reject curves its runtime cannot execute'
    );

    $publicCatalog = SkillMechanismRegistry::publicCatalog();
    assertSkillMechanismSame(
        true,
        isset(
            $publicCatalog['army_stat_percent']['allowed_conditions'],
            $publicCatalog['army_stat_percent']['allowed_phase_values'],
            $publicCatalog['army_stat_percent']
                ['allowed_conditions_by_parameter'],
            $publicCatalog['army_stat_percent']
                ['allowed_phase_values_by_parameter']
        ),
        '管理目录必须公开机制运行上下文白名单 / The admin catalog must expose mechanism runtime-context allowlists'
    );
    assertSkillMechanismSame(
        ['passive'],
        $publicCatalog['city_resource_production_percent']
            ['activation_types'],
        '区间生产机制只能被动生效 / Interval-based production modifiers must be passive-only'
    );

    $activeContinuousDefinition = $activeActionDefinition;
    $activeContinuousDefinition['application_mode'] = 'continuous';
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            $activeContinuousDefinition,
            3,
            'active'
        )['valid'],
        '主动技能不得伪装为持续被动 / Active skills must not use continuous mode'
    );

    $activeProductionDefinition = [
        'schema_version' => 2,
        'application_mode' => 'timed',
        'duration' => 300,
        'effects' => [
            [
                'mechanism' => 'city_resource_production_percent',
                'parameters' => ['resource' => 'bright'],
                'value' => 20,
                'conditions' => []
            ]
        ]
    ];
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            $activeProductionDefinition,
            1,
            'active'
        )['valid'],
        '无法追溯历史区间的生产修正不得配置为主动'
            . ' / Production modifiers without interval history must not be active'
    );

    $attackWrongPhase = [
        'schema_version' => 2,
        'effects' => [
            [
                'mechanism' => 'army_stat_percent',
                'parameters' => [
                    'stat' => 'attack',
                    'unit_type' => 'all'
                ],
                'value' => 10,
                'conditions' => [
                    [
                        'type' => 'phase',
                        'operator' => 'eq',
                        'value' => 'march'
                    ]
                ]
            ]
        ]
    ];
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            $attackWrongPhase,
            1,
            'passive'
        )['valid'],
        '攻击修正不得接受行军阶段条件 / Attack modifiers must reject march-phase conditions'
    );

    $speedWrongContext = $attackWrongPhase;
    $speedWrongContext['effects'][0]['parameters']['stat'] = 'speed';
    $speedWrongContext['effects'][0]['conditions'] = [
        [
            'type' => 'side',
            'operator' => 'eq',
            'value' => 'attack'
        ]
    ];
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            $speedWrongContext,
            1,
            'passive'
        )['valid'],
        '移动速度修正不得接受战斗攻守方条件 / Movement-speed modifiers must reject battle-side conditions'
    );
    $speedMovementContext = $speedWrongContext;
    $speedMovementContext['effects'][0]['conditions'] = [
        [
            'type' => 'phase',
            'operator' => 'in',
            'value' => ['march', 'return']
        ],
        [
            'type' => 'distance',
            'operator' => 'lte',
            'value' => 20
        ]
    ];
    assertSkillMechanismSame(
        true,
        SkillDefinitionValidator::validate(
            $speedMovementContext,
            1,
            'passive'
        )['valid'],
        '移动速度修正应允许行军阶段与距离条件 / Movement-speed modifiers should allow movement phases and distance'
    );

    $productionDistanceCondition = $passiveDefinition;
    $productionDistanceCondition['effects'] = [
        $productionDistanceCondition['effects'][0]
    ];
    $productionDistanceCondition['effects'][0]['conditions'] = [
        [
            'type' => 'distance',
            'operator' => 'lte',
            'value' => 5
        ]
    ];
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            $productionDistanceCondition,
            3,
            'passive'
        )['valid'],
        '资源生产不得接受没有消费者的距离条件 / Production must reject distance conditions with no consumer'
    );

    $conditionedAction = $activeActionDefinition;
    $conditionedAction['effects'][0]['conditions'] = [
        [
            'type' => 'phase',
            'operator' => 'eq',
            'value' => 'activation'
        ]
    ];
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            $conditionedAction,
            3,
            'active'
        )['valid'],
        '即时动作不得暴露无意义条件 / Instant actions must not expose meaningless conditions'
    );

    $targetTagDefinition = [
        'schema_version' => 2,
        'effects' => [
            [
                'mechanism' => 'army_stat_percent',
                'parameters' => [
                    'stat' => 'attack',
                    'unit_type' => 'all'
                ],
                'value' => 10,
                'conditions' => [
                    [
                        'type' => 'target_tag',
                        'operator' => 'not_in',
                        'value' => ['npc']
                    ]
                ]
            ]
        ]
    ];
    $targetTagValidation = SkillDefinitionValidator::validate(
        $targetTagDefinition,
        1,
        'passive'
    );
    assertSkillMechanismSame(
        true,
        $targetTagValidation['valid'],
        '战斗修正必须允许目标标签条件 / Battle modifiers must allow target-tag conditions'
    );
    $missingTargetTags = SkillEffectEngine::evaluate(
        $targetTagValidation['definition'],
        [
            'skill_level' => 1,
            'phase' => 'battle',
            'side' => 'attack',
            'distance' => 0
        ]
    );
    assertSkillMechanismSame(
        false,
        isset($missingTargetTags['modifiers']['attack']),
        '缺少目标标签上下文时not_in也必须失败关闭'
            . ' / not_in must fail closed when target-tag context is missing'
    );

    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            [
                'schema_version' => 2,
                'snapshot' => true,
                'effects' => [
                    [
                        'mechanism' => 'skill_power_percent',
                        'parameters' => [],
                        'value' => 10,
                        'conditions' => []
                    ]
                ]
            ],
            1,
            'active',
            false,
            true
        )['valid'],
        '内部快照也不得注入仅被动机制 / Internal snapshots must reject passive-only mechanisms'
    );

    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            ['all_resources' => 100],
            1,
            'passive'
        )['valid'],
        '旧被动定义不得包含即时动作 / Legacy passive definitions must reject instant actions'
    );
    assertSkillMechanismSame(
        true,
        SkillDefinitionValidator::validate(
            ['all_resources' => 100],
            1,
            'active'
        )['valid'],
        '旧主动定义应保留纯即时动作 / Legacy active definitions must preserve action-only skills'
    );
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            ['attack' => 10],
            1,
            'active'
        )['valid'],
        '旧主动修正必须设置持续时间 / Legacy active modifiers must require duration'
    );
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            ['attack' => 10, 'duration' => 0],
            1,
            'active'
        )['valid'],
        '旧主动修正持续时间必须为正数 / Legacy active modifier duration must be positive'
    );
    assertSkillMechanismSame(
        true,
        SkillDefinitionValidator::validate(
            ['attack' => 10, 'duration' => 60],
            1,
            'active'
        )['valid'],
        '旧主动限时修正必须继续兼容 / Legacy timed active modifiers must remain compatible'
    );
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            ['duration' => 60],
            1,
            'active'
        )['valid'],
        '旧主动定义不得只含持续时间 / Legacy active definitions must reject duration-only data'
    );
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            ['healing' => 10, 'duration' => 60],
            1,
            'active'
        )['valid'],
        '旧即时动作不得携带无消费者的持续时间 / Legacy instant actions must reject unused duration'
    );
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            ['production' => 10, 'duration' => 60],
            1,
            'active'
        )['valid'],
        '旧主动技能不得包含全资源区间生产 / Legacy active skills must reject global interval production'
    );
    assertSkillMechanismSame(
        false,
        SkillDefinitionValidator::validate(
            ['production_bright' => 10, 'duration' => 60],
            1,
            'active'
        )['valid'],
        '旧主动技能不得包含分资源区间生产 / Legacy active skills must reject scoped interval production'
    );

    $healTargets = SkillMechanismRegistry::get('heal_generals')
        ['parameters']['target']['options'];
    $cooldownTargets = SkillMechanismRegistry::get(
        'reduce_skill_cooldowns'
    )['parameters']['target']['options'];
    assertSkillMechanismSame(
        true,
        isset(
            $healTargets['unassigned_owned'],
            $cooldownTargets['unassigned_owned']
        ),
        '恢复与冷却缩短必须保留未分配武将作用域'
            . ' / Healing and cooldown reduction must retain unassigned-owned scope'
    );

    $snapshot = SkillEffectEngine::snapshotTimedEffects(
        $validation['definition'],
        [
            'skill_level' => 2,
            'general_cost' => 3.0,
            'general_intelligence' => 100
        ]
    );
    assertSkillMechanismSame(
        2,
        count($snapshot['effects']),
        '限时快照必须保留全部修正机制 / Timed snapshots must preserve every modifier'
    );
    assertSkillMechanismSame(
        75.0,
        $snapshot['effects'][0]['value'],
        '限时快照必须固化发动时数值 / Timed snapshots must freeze activation-time values'
    );
    $untrustedSnapshot = SkillEffectEngine::evaluate(
        $snapshot,
        [
            'skill_level' => 1,
            'phase' => 'battle',
            'side' => 'attack',
            'distance' => 5
        ]
    );
    assertSkillMechanismSame(
        false,
        $untrustedSnapshot['valid'],
        '输入中的snapshot标记不得自行取得内部授权'
            . ' / A snapshot flag in input must not grant its own internal authorization'
    );
    $snapshotInRange = SkillEffectEngine::evaluate(
        $snapshot,
        [
            'skill_level' => 1,
            'general_cost' => 99,
            'general_intelligence' => 999,
            'side' => 'attack',
            'phase' => 'battle',
            'distance' => 5
        ],
        true
    );
    assertSkillMechanismFloat(
        75.0,
        $snapshotInRange['modifiers']['attack'],
        '快照效果不得随之后的属性变化重算 / Snapshotted effects must not recalculate after attributes change'
    );

    echo 'Skill mechanism rule tests passed: '
        . $assertions . ' assertions.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

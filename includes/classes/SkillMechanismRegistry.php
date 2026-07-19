<?php
// 种火集结号 - 技能机制注册表 / Fireseed Engage - Skill mechanism registry

/**
 * 维护可由数据安全组合的技能机制白名单 / Owns the allowlist of skill mechanisms that data may safely compose
 */
class SkillMechanismRegistry {
    const STATUS_IMPLEMENTED = 'implemented';
    const STATUS_PLACEHOLDER = 'placeholder';

    /**
     * 获取全部机制定义 / Gets every mechanism definition
     *
     * @return array 机制定义 / Mechanism definitions
     */
    public static function all() {
        $combatStats = self::combatStatOptions();
        $unitTypes = self::unitTypeOptions(true);
        $elements = self::elementOptions();
        $resources = self::resourceOptions(true);

        $mechanisms = [
            'army_stat_percent' => self::implemented(
                '军队属性百分比',
                'Army stat percentage',
                '按全军或指定兵种提高攻击、守备或移动速度。',
                'Raises attack, defense, or movement speed for the whole army or one unit type.',
                'combat_modifier',
                ['passive', 'active'],
                [
                    'stat' => self::enumParameter(
                        '属性 / Stat',
                        $combatStats,
                        'attack'
                    ),
                    'unit_type' => self::enumParameter(
                        '兵种 / Unit type',
                        $unitTypes,
                        'all'
                    )
                ],
                0.0,
                1000.0
            ),
            'army_stat_multiplier' => self::implemented(
                '军队属性倍率',
                'Army stat multiplier',
                '以倍率乘算全军或指定兵种属性，可表达速度减半等代价。',
                'Multiplies an army or unit stat and can express trade-offs such as half speed.',
                'combat_modifier',
                ['passive', 'active'],
                [
                    'stat' => self::enumParameter(
                        '属性 / Stat',
                        $combatStats,
                        'speed'
                    ),
                    'unit_type' => self::enumParameter(
                        '兵种 / Unit type',
                        $unitTypes,
                        'all'
                    )
                ],
                0.0,
                10.0
            ),
            'army_element_stat_percent' => self::implemented(
                '元素编成叠层',
                'Element roster stacking',
                '每名同元素随军武将提供一次指定属性百分比。',
                'Adds a stat percentage for each participating general of a configured element.',
                'combat_modifier',
                ['passive', 'active'],
                [
                    'element' => self::enumParameter(
                        '元素 / Element',
                        $elements,
                        'bright'
                    ),
                    'stat' => self::enumParameter(
                        '属性 / Stat',
                        $combatStats,
                        'attack'
                    )
                ],
                0.0,
                1000.0
            ),
            'army_damage_reduction_percent' => self::implemented(
                '战损减免',
                'Casualty reduction',
                '降低所属军队结算出的战损率。',
                'Reduces the resolved casualty rate of the assigned army.',
                'combat_modifier',
                ['passive', 'active'],
                [],
                0.0,
                75.0
            ),
            'army_return_speed_percent' => self::implemented(
                '返程速度',
                'Return speed',
                '只在军队返程时提高移动速度。',
                'Raises movement speed only while an army is returning.',
                'march_modifier',
                ['passive', 'active'],
                [],
                0.0,
                1000.0
            ),
            'army_siege_damage_percent' => self::implemented(
                '攻城伤害百分比',
                'Siege damage percentage',
                '攻击城池时提高锤子兵造成的耐久伤害。',
                'Raises durability damage dealt by golems against cities.',
                'siege_modifier',
                ['passive', 'active'],
                [],
                0.0,
                1000.0
            ),
            'army_siege_damage_flat' => self::implemented(
                '攻城固定伤害',
                'Flat siege damage',
                '有存活锤子兵造成基础攻城伤害时，追加固定耐久伤害。',
                'Adds flat durability damage when surviving golems deal base siege damage.',
                'siege_modifier',
                ['passive', 'active'],
                [],
                0.0,
                1000000000.0
            ),
            'army_siege_damage_multiplier' => self::implemented(
                '攻城伤害倍率',
                'Siege damage multiplier',
                '以倍率乘算锤子兵对城池造成的耐久伤害。',
                'Multiplies durability damage dealt by golems against cities.',
                'siege_modifier',
                ['passive', 'active'],
                [],
                0.0,
                10.0
            ),
            'scout_range_bonus' => self::implemented(
                '侦察范围',
                'Scouting range',
                '提高所属军队可使用的侦察范围。',
                'Raises the scouting range available to the assigned army.',
                'scouting_modifier',
                ['passive', 'active'],
                [],
                0.0,
                15.0
            ),
            'city_resource_production_percent' => self::implemented(
                '城池资源生产',
                'City resource production',
                '提高驻扎城池全部或指定资源的设施产量。',
                'Raises facility production for all or one resource in the assigned city.',
                'city_modifier',
                ['passive'],
                [
                    'resource' => self::enumParameter(
                        '资源 / Resource',
                        $resources,
                        'all'
                    )
                ],
                0.0,
                1000.0
            ),
            'city_training_speed_percent' => self::implemented(
                '士兵训练速度',
                'Soldier training speed',
                '缩短驻扎城池全部或指定兵种的训练时间。',
                'Shortens training time for all or one unit type in the assigned city.',
                'city_modifier',
                ['passive', 'active'],
                [
                    'unit_type' => self::enumParameter(
                        '兵种 / Unit type',
                        $unitTypes,
                        'all'
                    )
                ],
                0.0,
                1000.0
            ),
            'city_training_cost_reduction_percent' => self::implemented(
                '士兵训练成本减免',
                'Soldier training cost reduction',
                '降低驻扎城池全部或指定兵种的训练资源。',
                'Reduces resource costs for all or one trained unit type in the assigned city.',
                'city_modifier',
                ['passive', 'active'],
                [
                    'unit_type' => self::enumParameter(
                        '兵种 / Unit type',
                        $unitTypes,
                        'all'
                    )
                ],
                0.0,
                95.0
            ),
            'city_construction_speed_percent' => self::implemented(
                '城池建造速度',
                'City construction speed',
                '缩短驻扎城池的设施建造和升级时间。',
                'Shortens facility construction and upgrade time in the assigned city.',
                'city_modifier',
                ['passive', 'active'],
                [],
                0.0,
                1000.0
            ),
            'city_defense_percent' => self::implemented(
                '城池防御',
                'City defense',
                '提高驻扎城池的最终防御力。',
                'Raises the final defense power of the assigned city.',
                'city_modifier',
                ['passive', 'active'],
                [],
                0.0,
                1000.0
            ),
            'skill_power_percent' => self::implemented(
                '主动技能威力',
                'Active skill power',
                '提高同一武将其他主动技能的数值效果。',
                'Raises numeric effects of the same general’s other active skills.',
                'meta_modifier',
                ['passive'],
                [],
                0.0,
                100.0
            ),
            'quest_reward_percent' => self::implemented(
                '任务奖励',
                'Quest rewards',
                '作为存活武将的全局光环提高所属玩家任务奖励，全部武将合计最高50%。',
                'Globally raises the owner’s quest rewards while this general is alive, capped at 50% across all generals.',
                'meta_modifier',
                ['passive'],
                [],
                0.0,
                50.0
            ),
            'grant_resources' => self::implemented(
                '立即获得资源',
                'Grant resources',
                '发动时立即获得全部或指定资源。',
                'Immediately grants all or one configured resource when activated.',
                'instant_action',
                ['active'],
                [
                    'resource' => self::enumParameter(
                        '资源 / Resource',
                        $resources,
                        'all'
                    )
                ],
                0.0,
                1000000000.0,
                true
            ),
            'heal_generals' => self::implemented(
                '恢复武将HP',
                'Heal generals',
                '发动时恢复自身、同驻城、未分配或全部所属武将的HP。',
                'Heals the activating general, assigned-city, unassigned, or all owned generals.',
                'instant_action',
                ['active'],
                [
                    'target' => self::enumParameter(
                        '目标 / Target',
                        [
                            'self' => '自身 / Self',
                            'assigned_city' => '同驻城 / Assigned city',
                            'unassigned_owned' =>
                                '未分配所属 / Unassigned owned',
                            'all_owned' => '全部所属 / All owned'
                        ],
                        'self'
                    )
                ],
                0.0,
                1000000.0,
                true
            ),
            'repair_assigned_city' => self::implemented(
                '恢复驻城耐久',
                'Repair assigned city',
                '发动时恢复武将驻扎城池的耐久。',
                'Repairs durability of the city where the activating general is assigned.',
                'instant_action',
                ['active'],
                [],
                0.0,
                1000000000.0,
                true
            ),
            'reduce_skill_cooldowns' => self::implemented(
                '缩短技能冷却',
                'Reduce skill cooldowns',
                '发动时缩短自身、未分配或全部所属武将技能的剩余冷却。',
                'Reduces remaining cooldowns for this general, unassigned generals, or all owned generals.',
                'instant_action',
                ['active'],
                [
                    'target' => self::enumParameter(
                        '目标 / Target',
                        [
                            'self_general' => '自身技能 / This general',
                            'unassigned_owned' =>
                                '未分配所属 / Unassigned owned',
                            'all_owned' => '全部所属技能 / All owned skills'
                        ],
                        'self_general'
                    )
                ],
                0.0,
                31536000.0,
                true
            ),

            // 下列机制来自原作资料，但当前企划缺少权威状态或事件钩子。 / The following source mechanisms lack authoritative state or event hooks in the current game design.
            'treasure_find_chance' => self::placeholder(
                '宝箱发现率',
                'Treasure discovery chance',
                '当前没有寻宝与宝箱系统。',
                'The current game has no treasure or chest system.'
            ),
            'treasure_empty_rate_reduction' => self::placeholder(
                '空宝箱率降低',
                'Empty-chest reduction',
                '当前没有寻宝与宝箱系统。',
                'The current game has no treasure or chest system.'
            ),
            'territory_popularity_damage' => self::placeholder(
                '领地人气伤害',
                'Territory popularity damage',
                '当前领地没有人气值。',
                'Territories currently have no popularity stat.'
            ),
            'territory_popularity_restore' => self::placeholder(
                '领地人气恢复',
                'Territory popularity restoration',
                '当前领地没有人气值。',
                'Territories currently have no popularity stat.'
            ),
            'tension_change' => self::placeholder(
                'テンション变化',
                'Tension change',
                '当前武将没有テンション状态。',
                'Generals currently have no tension state.'
            ),
            'resource_collection_percent' => self::placeholder(
                '地图资源采集量',
                'Map resource collection',
                '武将目前不能分配到资源领地，无法确定作用域。',
                'Generals cannot currently be assigned to resource tiles, so scope is undefined.'
            ),
            'battle_reward_resource_percent' => self::placeholder(
                '战斗资源收益',
                'Battle resource rewards',
                '战斗奖励尚无统一、可封顶的资源收益钩子。',
                'Battle rewards do not yet expose one bounded resource-reward hook.'
            ),
            'resource_conversion_rate' => self::placeholder(
                '资源转换率',
                'Resource conversion rate',
                '当前没有资源转换炉系统。',
                'The current game has no resource-conversion facility.'
            ),
            'territory_development_speed' => self::placeholder(
                '领地升级速度',
                'Territory development speed',
                '当前领地没有独立升级计时。',
                'Territories currently have no independent level-up timer.'
            ),
            'reinforcement_only_modifier' => self::placeholder(
                '援军专用修正',
                'Reinforcement-only modifier',
                '当前没有可识别的援军行军与战斗身份。',
                'The current game has no authoritative reinforcement march/combat role.'
            ),
            'skirmish_only_modifier' => self::placeholder(
                '牵制战修正',
                'Skirmish-only modifier',
                '当前没有牵制战事件。',
                'The current game has no skirmish event.'
            ),
            'unit_transfer_on_reinforcement' => self::placeholder(
                '援军兵力移送',
                'Unit transfer on reinforcement',
                '当前没有盟友援军移交兵力流程。',
                'The current game has no allied reinforcement-transfer flow.'
            ),
            'adjacent_allied_territory_scaling' => self::placeholder(
                '相邻盟友领地叠层',
                'Adjacent allied-territory stacking',
                '战斗上下文尚未提供稳定的相邻盟友领地快照。',
                'Combat context does not yet provide a stable adjacent-allied-territory snapshot.'
            ),
            'gender_roster_scaling' => self::placeholder(
                '性别编成叠层',
                'Gender roster stacking',
                '武将目录当前没有性别字段。',
                'The general catalog currently has no gender field.'
            ),
            'defender_general_damage' => self::placeholder(
                '守方武将直接伤害',
                'Direct defender-general damage',
                '当前战斗没有概率性全体武将直接伤害事件。',
                'Combat currently has no probabilistic all-general direct-damage event.'
            ),
            'hp_cost_on_attack' => self::placeholder(
                '出征HP代价',
                'HP cost on attack',
                '当前攻击快照没有可审计的一次性HP消费钩子。',
                'Attack snapshots do not yet expose an auditable one-time HP-cost hook.'
            ),
            'heal_on_battle_success' => self::placeholder(
                '战斗成功后恢复',
                'Heal after battle success',
                '当前战后事件尚未保存技能动作快照。',
                'Post-battle events do not yet preserve a skill-action snapshot.'
            ),
            'base_damage_reduction' => self::placeholder(
                '跨据点破坏减免',
                'Cross-base destruction reduction',
                '当前没有开发据点与跨据点光环模型。',
                'The current game has no developed-base or cross-base aura model.'
            ),
            'waiting_roster_heal' => self::placeholder(
                '待机编成恢复',
                'Waiting-roster healing',
                '当前没有原作式卡组待机状态。',
                'The current game has no source-style deck waiting state.'
            ),
            'advanced_unit_scope' => self::placeholder(
                '上位兵种独立作用域',
                'Advanced-unit scope',
                '当前六兵种没有独立的上位兵种实体。',
                'The current six unit types have no separate advanced-unit entities.'
            )
        ];

        $executionScopes = self::executionScopes();
        foreach ($mechanisms as $code => &$definition) {
            $scope = isset($executionScopes[$code])
                ? $executionScopes[$code]
                : [];
            $definition['allowed_conditions'] = isset(
                $scope['allowed_conditions']
            ) ? $scope['allowed_conditions'] : [];
            $definition['allowed_phase_values'] = isset(
                $scope['allowed_phase_values']
            ) ? $scope['allowed_phase_values'] : [];
            $definition['allowed_conditions_by_parameter'] = isset(
                $scope['allowed_conditions_by_parameter']
            ) ? $scope['allowed_conditions_by_parameter'] : [];
            $definition['allowed_phase_values_by_parameter'] = isset(
                $scope['allowed_phase_values_by_parameter']
            ) ? $scope['allowed_phase_values_by_parameter'] : [];
            $definition['allowed_condition_values'] = isset(
                $scope['allowed_condition_values']
            ) ? $scope['allowed_condition_values'] : [];
            $definition['allowed_condition_values_by_parameter'] = isset(
                $scope['allowed_condition_values_by_parameter']
            ) ? $scope['allowed_condition_values_by_parameter'] : [];
        }
        unset($definition);

        return $mechanisms;
    }

    /**
     * 获取单一机制 / Gets one mechanism
     *
     * @param string $mechanism 机制代码 / Mechanism code
     * @return array|null 机制定义 / Mechanism definition
     */
    public static function get($mechanism) {
        $mechanisms = self::all();
        return isset($mechanisms[$mechanism])
            ? $mechanisms[$mechanism]
            : null;
    }

    /**
     * 判断机制是否可执行 / Checks whether a mechanism is executable
     *
     * @param string $mechanism 机制代码 / Mechanism code
     * @return bool 是否可执行 / Whether executable
     */
    public static function isImplemented($mechanism) {
        $definition = self::get(trim((string) $mechanism));
        return $definition !== null
            && $definition['status'] === self::STATUS_IMPLEMENTED;
    }

    /**
     * 获取供管理界面使用的安全目录 / Gets the safe catalog for administration
     *
     * @return array 可序列化目录 / Serializable catalog
     */
    public static function publicCatalog() {
        $catalog = [];
        foreach (self::all() as $code => $definition) {
            $catalog[$code] = [
                'code' => $code,
                'status' => $definition['status'],
                'label' => $definition['label'],
                'label_en' => $definition['label_en'],
                'description' => $definition['description'],
                'description_en' => $definition['description_en'],
                'hook' => $definition['hook'],
                'kind' => $definition['kind'],
                'activation_types' => $definition['activation_types'],
                'parameters' => $definition['parameters'],
                'value' => $definition['value'],
                'allowed_conditions' =>
                    $definition['allowed_conditions'],
                'allowed_phase_values' =>
                    $definition['allowed_phase_values'],
                'allowed_conditions_by_parameter' =>
                    $definition['allowed_conditions_by_parameter'],
                'allowed_phase_values_by_parameter' =>
                    $definition['allowed_phase_values_by_parameter'],
                'allowed_condition_values' =>
                    $definition['allowed_condition_values'],
                'allowed_condition_values_by_parameter' =>
                    $definition['allowed_condition_values_by_parameter']
            ];
        }

        return $catalog;
    }

    /**
     * 获取结构化条件目录 / Gets the structured condition catalog
     *
     * @return array 条件定义 / Condition definitions
     */
    public static function conditions() {
        return [
            'phase' => [
                'operators' => ['eq', 'in'],
                'type' => 'enum',
                'options' => [
                    'battle' => '战斗 / Battle',
                    'march' => '出征 / March',
                    'return' => '返程 / Return',
                    'production' => '生产 / Production',
                    'training' => '训练 / Training',
                    'construction' => '建造 / Construction',
                    'city_defense' => '城防 / City defense',
                    'scouting' => '侦察 / Scouting',
                    'activation' => '发动 / Activation'
                ]
            ],
            'side' => [
                'operators' => ['eq', 'in'],
                'type' => 'enum',
                'options' => [
                    'attack' => '进攻 / Attack',
                    'defense' => '防守 / Defense'
                ]
            ],
            'target_tag' => [
                'operators' => ['eq', 'in', 'not_in'],
                'type' => 'enum',
                'options' => [
                    'player' => '玩家 / Player',
                    'npc' => 'NPC',
                    'city' => '城池 / City',
                    'army' => '军队 / Army',
                    'tile' => '领地 / Tile',
                    'structure' => '建筑目标 / Structure'
                ]
            ],
            'distance' => [
                'operators' => ['lte', 'gte', 'lt', 'gt', 'eq'],
                'type' => 'number',
                'minimum' => 0,
                'maximum' => 1024
            ]
        ];
    }

    /**
     * 声明各机制实际拥有的运行上下文 / Declares the runtime context actually available to each mechanism
     *
     * @return array 机制执行作用域 / Mechanism execution scopes
     */
    private static function executionScopes() {
        $battleConditions = [
            'phase',
            'side',
            'target_tag',
            'distance'
        ];
        $movementConditions = ['phase', 'distance'];
        $siegeConditionValues = [
            'side' => ['attack'],
            'target_tag' => ['city', 'structure', 'player']
        ];
        $cityDefenseConditionValues = [
            'side' => ['defense'],
            'target_tag' => ['army', 'player']
        ];
        $statConditionsByParameter = [
            'stat' => [
                'attack' => $battleConditions,
                'defense' => $battleConditions,
                'speed' => $movementConditions
            ]
        ];
        $statPhasesByParameter = [
            'stat' => [
                'attack' => ['battle'],
                'defense' => ['battle'],
                'speed' => ['march', 'return']
            ]
        ];

        return [
            'army_stat_percent' => [
                'allowed_conditions' => $battleConditions,
                'allowed_phase_values' => [
                    'battle',
                    'march',
                    'return'
                ],
                'allowed_conditions_by_parameter' =>
                    $statConditionsByParameter,
                'allowed_phase_values_by_parameter' =>
                    $statPhasesByParameter
            ],
            'army_stat_multiplier' => [
                'allowed_conditions' => $battleConditions,
                'allowed_phase_values' => [
                    'battle',
                    'march',
                    'return'
                ],
                'allowed_conditions_by_parameter' =>
                    $statConditionsByParameter,
                'allowed_phase_values_by_parameter' =>
                    $statPhasesByParameter
            ],
            'army_element_stat_percent' => [
                'allowed_conditions' => $battleConditions,
                'allowed_phase_values' => [
                    'battle',
                    'march',
                    'return'
                ],
                'allowed_conditions_by_parameter' =>
                    $statConditionsByParameter,
                'allowed_phase_values_by_parameter' =>
                    $statPhasesByParameter
            ],
            'army_damage_reduction_percent' => [
                'allowed_conditions' => $battleConditions,
                'allowed_phase_values' => ['battle']
            ],
            'army_return_speed_percent' => [
                'allowed_conditions' => $movementConditions,
                'allowed_phase_values' => ['return']
            ],
            'army_siege_damage_percent' => [
                'allowed_conditions' => $battleConditions,
                'allowed_phase_values' => ['battle'],
                'allowed_condition_values' => $siegeConditionValues
            ],
            'army_siege_damage_flat' => [
                'allowed_conditions' => $battleConditions,
                'allowed_phase_values' => ['battle'],
                'allowed_condition_values' => $siegeConditionValues
            ],
            'army_siege_damage_multiplier' => [
                'allowed_conditions' => $battleConditions,
                'allowed_phase_values' => ['battle'],
                'allowed_condition_values' => $siegeConditionValues
            ],
            'scout_range_bonus' => [
                'allowed_conditions' => ['phase'],
                'allowed_phase_values' => ['scouting']
            ],
            'city_resource_production_percent' => [
                'allowed_conditions' => ['phase'],
                'allowed_phase_values' => ['production']
            ],
            'city_training_speed_percent' => [
                'allowed_conditions' => ['phase'],
                'allowed_phase_values' => ['training']
            ],
            'city_training_cost_reduction_percent' => [
                'allowed_conditions' => ['phase'],
                'allowed_phase_values' => ['training']
            ],
            'city_construction_speed_percent' => [
                'allowed_conditions' => ['phase'],
                'allowed_phase_values' => ['construction']
            ],
            'city_defense_percent' => [
                'allowed_conditions' => $battleConditions,
                'allowed_phase_values' => ['city_defense'],
                'allowed_condition_values' =>
                    $cityDefenseConditionValues
            ],
            'skill_power_percent' => [
                'allowed_conditions' => [],
                'allowed_phase_values' => []
            ],
            'quest_reward_percent' => [
                'allowed_conditions' => [],
                'allowed_phase_values' => []
            ],
            'grant_resources' => [
                'allowed_conditions' => [],
                'allowed_phase_values' => []
            ],
            'heal_generals' => [
                'allowed_conditions' => [],
                'allowed_phase_values' => []
            ],
            'repair_assigned_city' => [
                'allowed_conditions' => [],
                'allowed_phase_values' => []
            ],
            'reduce_skill_cooldowns' => [
                'allowed_conditions' => [],
                'allowed_phase_values' => []
            ]
        ];
    }

    /**
     * 获取旧平面JSON允许的键 / Gets legacy flat-JSON keys
     *
     * @return array 允许键 / Allowed keys
     */
    public static function legacyEffectKeys() {
        $keys = [
            'attack',
            'defense',
            'speed',
            'damage_reduction',
            'scout_range',
            'return_speed',
            'siege_damage_percent',
            'siege_damage_flat',
            'siege_damage_multiplier',
            'production',
            'training_speed',
            'training_cost_reduction',
            'build_speed',
            'construction_speed',
            'city_defense',
            'skill_power',
            'quest_reward',
            'all_resources',
            'healing',
            'duration'
        ];

        foreach (array_keys(self::unitTypeOptions(false)) as $unitType) {
            foreach (['attack', 'defense', 'speed'] as $stat) {
                $keys[] = 'unit_' . $stat . '_' . $unitType;
            }
            $keys[] = 'training_speed_' . $unitType;
            $keys[] = 'training_cost_reduction_' . $unitType;
        }
        foreach (array_keys(self::elementOptions()) as $element) {
            foreach (['attack', 'defense', 'speed'] as $stat) {
                $keys[] = 'element_' . $stat . '_per_' . $element;
            }
        }
        foreach (array_keys(self::resourceOptions(false)) as $resource) {
            $keys[] = 'production_' . $resource;
        }

        return $keys;
    }

    /**
     * 获取数值曲线模式 / Gets value-curve modes
     *
     * @return array 模式标签 / Mode labels
     */
    public static function valueModes() {
        return [
            'fixed' => '固定值 / Fixed',
            'level_values' => '等级曲线 / Level curve',
            'cost_level_values' => 'COST×等级曲线 / Cost × level curve',
            'intelligence_level_values' =>
                '智力×等级曲线 / Intelligence × level curve',
            'cost_intelligence_level_values' =>
                'COST×智力×等级曲线 / Cost × intelligence × level curve',
            'cost_plus_intelligence_level_values' =>
                'COST项+智力项曲线 / Cost term + intelligence term curve',
            'stat_level_values' =>
                '指定属性×等级曲线 / General stat × level curve'
        ];
    }

    /**
     * 构建已实现机制定义 / Builds an implemented mechanism definition
     *
     * @param string $label 中文名 / Chinese label
     * @param string $labelEn 英文名 / English label
     * @param string $description 中文说明 / Chinese description
     * @param string $descriptionEn 英文说明 / English description
     * @param string $hook 运行钩子 / Runtime hook
     * @param array $activationTypes 发动类型 / Activation types
     * @param array $parameters 参数定义 / Parameter definitions
     * @param float $minimum 最小值 / Minimum value
     * @param float $maximum 最大值 / Maximum value
     * @param bool $integer 是否按整数执行 / Whether execution uses integers
     * @return array 机制定义 / Mechanism definition
     */
    private static function implemented(
        $label,
        $labelEn,
        $description,
        $descriptionEn,
        $hook,
        array $activationTypes,
        array $parameters,
        $minimum,
        $maximum,
        $integer = false
    ) {
        return [
            'status' => self::STATUS_IMPLEMENTED,
            'label' => $label,
            'label_en' => $labelEn,
            'description' => $description,
            'description_en' => $descriptionEn,
            'hook' => $hook,
            'kind' => $hook === 'instant_action' ? 'action' : 'modifier',
            'activation_types' => $activationTypes,
            'parameters' => $parameters,
            'value' => [
                'minimum' => (float) $minimum,
                'maximum' => (float) $maximum,
                'integer' => (bool) $integer
            ]
        ];
    }

    /**
     * 构建占位机制定义 / Builds a placeholder mechanism definition
     *
     * @param string $label 中文名 / Chinese label
     * @param string $labelEn 英文名 / English label
     * @param string $description 中文说明 / Chinese description
     * @param string $descriptionEn 英文说明 / English description
     * @return array 机制定义 / Mechanism definition
     */
    private static function placeholder(
        $label,
        $labelEn,
        $description,
        $descriptionEn
    ) {
        return [
            'status' => self::STATUS_PLACEHOLDER,
            'label' => $label,
            'label_en' => $labelEn,
            'description' => $description,
            'description_en' => $descriptionEn,
            'hook' => 'placeholder',
            'kind' => 'placeholder',
            'activation_types' => [],
            'parameters' => [],
            'value' => null
        ];
    }

    /**
     * 构建枚举参数定义 / Builds an enum parameter definition
     *
     * @param string $label 标签 / Label
     * @param array $options 可选项 / Options
     * @param string $default 默认值 / Default value
     * @return array 参数定义 / Parameter definition
     */
    private static function enumParameter($label, array $options, $default) {
        return [
            'type' => 'enum',
            'label' => $label,
            'options' => $options,
            'default' => $default,
            'required' => true
        ];
    }

    /**
     * 获取战斗属性选项 / Gets combat-stat options
     *
     * @return array 属性选项 / Stat options
     */
    private static function combatStatOptions() {
        return [
            'attack' => '攻击 / Attack',
            'defense' => '守备 / Defense',
            'speed' => '速度 / Speed'
        ];
    }

    /**
     * 获取兵种选项 / Gets unit-type options
     *
     * @param bool $includeAll 是否包含全部 / Whether to include all
     * @return array 兵种选项 / Unit options
     */
    private static function unitTypeOptions($includeAll) {
        $options = [
            'pawn' => '兵卒 / Pawn',
            'knight' => '骑士 / Knight',
            'rook' => '城壁 / Rook',
            'bishop' => '主教 / Bishop',
            'golem' => '锤子兵 / Golem',
            'scout' => '侦察兵 / Scout'
        ];

        return $includeAll
            ? array_merge(['all' => '全部 / All'], $options)
            : $options;
    }

    /**
     * 获取元素选项 / Gets element options
     *
     * @return array 元素选项 / Element options
     */
    private static function elementOptions() {
        return [
            'bright' => '亮晶晶 / Bright',
            'warm' => '暖洋洋 / Warm',
            'cold' => '冷冰冰 / Cold',
            'green' => '郁萌萌 / Green',
            'day' => '昼闪闪 / Day',
            'night' => '夜静静 / Night'
        ];
    }

    /**
     * 获取资源选项 / Gets resource options
     *
     * @param bool $includeAll 是否包含全部 / Whether to include all
     * @return array 资源选项 / Resource options
     */
    private static function resourceOptions($includeAll) {
        $options = [
            'bright' => '亮晶晶 / Bright',
            'warm' => '暖洋洋 / Warm',
            'cold' => '冷冰冰 / Cold',
            'green' => '郁萌萌 / Green',
            'day' => '昼闪闪 / Day',
            'night' => '夜静静 / Night'
        ];

        return $includeAll
            ? array_merge(['all' => '全部 / All'], $options)
            : $options;
    }
}

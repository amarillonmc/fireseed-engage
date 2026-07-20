<?php
// 种火集结号 - 技能效果执行器 / Fireseed Engage - Skill effect engine

/**
 * 将安全的技能定义编译为现有玩法系统可消费的修正与动作 / Compiles safe skill definitions into runtime modifiers and actions
 */
class SkillEffectEngine {
    private const MAX_LIFECYCLE_SECONDS = 31536000;

    /**
     * 校验并求值一个技能定义 / Validates and evaluates one skill definition
     *
     * @param array $definition 技能定义 / Skill definition
     * @param array $context 求值上下文 / Evaluation context
     * @param bool $allowSnapshot 是否允许受信任内部快照 / Whether a trusted internal snapshot is allowed
     * @return array 求值结果 / Evaluation result
     */
    public static function evaluate(
        array $definition,
        array $context = [],
        $allowSnapshot = false
    ) {
        $maxLevel = self::inferMaxLevel($definition, $context);
        $activationType = self::inferActivationType($definition);
        $validation = SkillDefinitionValidator::validate(
            $definition,
            $maxLevel,
            $activationType,
            true,
            $allowSnapshot === true
        );

        if (!$validation['valid']) {
            return self::invalidResult($validation['errors']);
        }

        if ($validation['legacy']) {
            return self::evaluateLegacy(
                $validation['definition'],
                $context
            );
        }

        $normalized = $validation['definition'];
        $result = [
            'valid' => true,
            'errors' => [],
            'modifiers' => [],
            'actions' => [],
            'cooldown_seconds' => null,
            'duration_seconds' => null
        ];

        if (isset($normalized['cooldown'])) {
            $cooldown = SkillValueResolver::resolve(
                $normalized['cooldown'],
                $context
            );
            if ($cooldown === null) {
                return self::invalidResult([
                    '无法解析冷却曲线 / Unable to resolve cooldown curve'
                ]);
            }
            $result['cooldown_seconds'] =
                $cooldown >= self::MAX_LIFECYCLE_SECONDS
                    ? self::MAX_LIFECYCLE_SECONDS
                    : max(0, (int) round($cooldown));
        }
        if (isset($normalized['duration'])) {
            $duration = SkillValueResolver::resolve(
                $normalized['duration'],
                $context
            );
            if ($duration === null) {
                return self::invalidResult([
                    '无法解析持续时间曲线 / Unable to resolve duration curve'
                ]);
            }
            $result['duration_seconds'] =
                $duration >= self::MAX_LIFECYCLE_SECONDS
                    ? self::MAX_LIFECYCLE_SECONDS
                    : max(1, (int) round($duration));
        }

        $isSnapshot = isset($normalized['snapshot'])
            && $normalized['snapshot'] === true;
        $isActive = !$isSnapshot
            && isset($normalized['application_mode'])
            && $normalized['application_mode'] !== 'continuous';
        foreach ($normalized['effects'] as $effect) {
            if (!self::conditionsMatch($effect['conditions'], $context)) {
                continue;
            }

            $value = SkillValueResolver::resolve(
                $effect['value'],
                $context
            );
            if ($value === null) {
                return self::invalidResult([
                    '无法解析机制数值：' . $effect['mechanism']
                    . ' / Unable to resolve mechanism value'
                ]);
            }
            $value = self::boundResolvedValue(
                $effect['mechanism'],
                $value
            );
            if ($isActive
                && isset($context['skill_power_percent'])
                && SkillValueResolver::isFiniteNumber(
                    $context['skill_power_percent']
                )) {
                $power = max(
                    0.0,
                    min(100.0, (float) $context['skill_power_percent'])
                );
                $value = self::boundResolvedValue(
                    $effect['mechanism'],
                    self::applySkillPower(
                        $effect['mechanism'],
                        $value,
                        $power
                    )
                );
            }

            $mechanism = SkillMechanismRegistry::get(
                $effect['mechanism']
            );
            if ($mechanism['kind'] === 'action') {
                if (!empty($mechanism['value']['integer'])) {
                    $value = (float) round($value);
                }
                $result['actions'][] = [
                    'mechanism' => $effect['mechanism'],
                    'parameters' => $effect['parameters'],
                    'value' => $value
                ];
                continue;
            }

            $compiled = self::compileModifier(
                $effect['mechanism'],
                $effect['parameters'],
                $value
            );
            if ($compiled === null) {
                return self::invalidResult([
                    '机制缺少运行时编译器：' . $effect['mechanism']
                    . ' / Mechanism has no runtime compiler'
                ]);
            }
            self::mergeModifier(
                $result['modifiers'],
                $compiled['key'],
                $compiled['value'],
                $compiled['operation']
            );
        }

        return $result;
    }

    /**
     * 固化限时主动技能的发动时数值 / Freezes activation-time values for a timed active skill
     *
     * @param array $definition 已校验或原始定义 / Validated or raw definition
     * @param array $context 发动时上下文 / Activation-time context
     * @return array 可存储快照 / Storable snapshot
     * @throws InvalidArgumentException 定义无法快照时 / When the definition cannot be snapshotted
     */
    public static function snapshotTimedEffects(
        array $definition,
        array $context
    ) {
        $maxLevel = self::inferMaxLevel($definition, $context);
        $validation = SkillDefinitionValidator::validate(
            $definition,
            $maxLevel,
            'active',
            false,
            false
        );
        if (!$validation['valid']
            || !isset($validation['definition']['application_mode'])
            || $validation['definition']['application_mode'] !== 'timed') {
            throw new InvalidArgumentException(
                '只有合法限时主动技能可以生成快照'
                . ' / Only valid timed active skills can be snapshotted'
            );
        }

        $snapshot = [
            'schema_version' => SkillDefinitionValidator::SCHEMA_VERSION,
            'snapshot' => true,
            'effects' => []
        ];
        foreach ($validation['definition']['effects'] as $effect) {
            $mechanism = SkillMechanismRegistry::get(
                $effect['mechanism']
            );
            if ($mechanism === null || $mechanism['kind'] !== 'modifier') {
                continue;
            }

            $value = SkillValueResolver::resolve(
                $effect['value'],
                $context
            );
            if ($value === null) {
                throw new InvalidArgumentException(
                    '无法固化技能机制数值：' . $effect['mechanism']
                    . ' / Unable to freeze mechanism value'
                );
            }
            if (isset($context['skill_power_percent'])
                && SkillValueResolver::isFiniteNumber(
                    $context['skill_power_percent']
                )) {
                $power = max(
                    0.0,
                    min(100.0, (float) $context['skill_power_percent'])
                );
                $value = self::applySkillPower(
                    $effect['mechanism'],
                    $value,
                    $power
                );
            }

            $snapshot['effects'][] = [
                'mechanism' => $effect['mechanism'],
                'parameters' => $effect['parameters'],
                'value' => self::boundResolvedValue(
                    $effect['mechanism'],
                    $value
                ),
                'conditions' => $effect['conditions']
            ];
        }

        $snapshotValidation = SkillDefinitionValidator::validate(
            $snapshot,
            1,
            'active',
            false,
            true
        );
        if (!$snapshotValidation['valid']) {
            throw new InvalidArgumentException(
                implode('; ', $snapshotValidation['errors'])
            );
        }

        return $snapshotValidation['definition'];
    }

    /**
     * 求值旧平面效果 / Evaluates a legacy flat effect
     *
     * @param array $definition 标准化旧定义 / Normalized legacy definition
     * @param array $context 求值上下文 / Evaluation context
     * @return array 求值结果 / Evaluation result
     */
    private static function evaluateLegacy(
        array $definition,
        array $context
    ) {
        $result = [
            'valid' => true,
            'errors' => [],
            'modifiers' => [],
            'actions' => [],
            'cooldown_seconds' => null,
            'duration_seconds' => null
        ];
        foreach ($definition as $key => $descriptor) {
            $value = SkillValueResolver::resolve($descriptor, $context);
            if ($value === null) {
                return self::invalidResult([
                    '无法解析旧效果：' . $key
                    . ' / Unable to resolve legacy effect'
                ]);
            }
            if ($key === 'duration') {
                $result['duration_seconds'] = max(
                    0,
                    (int) round($value)
                );
            } elseif ($key === 'all_resources') {
                $result['actions'][] = [
                    'mechanism' => 'grant_resources',
                    'parameters' => ['resource' => 'all'],
                    'value' => (float) round($value)
                ];
            } elseif ($key === 'healing') {
                $result['actions'][] = [
                    'mechanism' => 'heal_generals',
                    'parameters' => ['target' => 'self'],
                    'value' => (float) round($value)
                ];
            } else {
                $result['modifiers'][$key] = (float) $value;
            }
        }

        return $result;
    }

    /**
     * 将机制映射为运行时修正键 / Maps a mechanism to a runtime modifier key
     *
     * @param string $mechanism 机制代码 / Mechanism code
     * @param array $parameters 机制参数 / Mechanism parameters
     * @param float $value 已解析数值 / Resolved value
     * @return array|null 编译结果 / Compiled result
     */
    private static function compileModifier(
        $mechanism,
        array $parameters,
        $value
    ) {
        $operation = 'sum';
        switch ($mechanism) {
            case 'army_stat_percent':
                $key = $parameters['unit_type'] === 'all'
                    ? $parameters['stat']
                    : 'unit_' . $parameters['stat']
                        . '_' . $parameters['unit_type'];
                break;
            case 'army_stat_multiplier':
                $key = $parameters['unit_type'] === 'all'
                    ? $parameters['stat'] . '_multiplier'
                    : 'unit_' . $parameters['stat']
                        . '_multiplier_' . $parameters['unit_type'];
                $operation = 'multiply';
                break;
            case 'army_element_stat_percent':
                $key = 'element_' . $parameters['stat']
                    . '_per_' . $parameters['element'];
                break;
            case 'army_damage_reduction_percent':
                $key = 'damage_reduction';
                break;
            case 'army_return_speed_percent':
                $key = 'return_speed';
                break;
            case 'army_siege_damage_percent':
                $key = 'siege_damage_percent';
                break;
            case 'army_siege_damage_flat':
                $key = 'siege_damage_flat';
                break;
            case 'army_siege_damage_multiplier':
                $key = 'siege_damage_multiplier';
                $operation = 'multiply';
                break;
            case 'scout_range_bonus':
                $key = 'scout_range';
                break;
            case 'city_resource_production_percent':
                $key = $parameters['resource'] === 'all'
                    ? 'production'
                    : 'production_' . $parameters['resource'];
                break;
            case 'city_training_speed_percent':
                $key = $parameters['unit_type'] === 'all'
                    ? 'training_speed'
                    : 'training_speed_' . $parameters['unit_type'];
                break;
            case 'city_training_cost_reduction_percent':
                $key = $parameters['unit_type'] === 'all'
                    ? 'training_cost_reduction'
                    : 'training_cost_reduction_'
                        . $parameters['unit_type'];
                break;
            case 'city_construction_speed_percent':
                $key = 'build_speed';
                break;
            case 'city_defense_percent':
                $key = 'city_defense';
                break;
            case 'skill_power_percent':
                $key = 'skill_power';
                break;
            case 'quest_reward_percent':
                $key = 'quest_reward';
                break;
            default:
                return null;
        }

        return [
            'key' => $key,
            'value' => (float) $value,
            'operation' => $operation
        ];
    }

    /**
     * 合并一个修正值 / Merges one modifier value
     *
     * @param array $modifiers 修正集合 / Modifier collection
     * @param string $key 修正键 / Modifier key
     * @param float $value 修正值 / Modifier value
     * @param string $operation 合并方式 / Merge operation
     * @return void
     */
    private static function mergeModifier(
        array &$modifiers,
        $key,
        $value,
        $operation
    ) {
        if ($operation === 'multiply') {
            $modifiers[$key] = isset($modifiers[$key])
                ? (float) $modifiers[$key] * (float) $value
                : (float) $value;
            return;
        }

        $modifiers[$key] = isset($modifiers[$key])
            ? (float) $modifiers[$key] + (float) $value
            : (float) $value;
    }

    /**
     * 以正确的中性点应用技能威力 / Applies skill power around the correct neutral point
     *
     * @param string $mechanism 机制代码 / Mechanism code
     * @param float $value 原始机制数值 / Original mechanism value
     * @param float $power 技能威力百分比 / Skill-power percentage
     * @return float 放大后的数值 / Amplified value
     */
    private static function applySkillPower(
        $mechanism,
        $value,
        $power
    ) {
        $factor = 1.0 + (float) $power / 100.0;
        if (substr((string) $mechanism, -11) === '_multiplier') {
            return 1.0 + ((float) $value - 1.0) * $factor;
        }

        return (float) $value * $factor;
    }

    /**
     * 判断全部条件是否匹配 / Checks whether every condition matches
     *
     * @param array $conditions 条件列表 / Conditions
     * @param array $context 运行上下文 / Runtime context
     * @return bool 是否匹配 / Whether matched
     */
    private static function conditionsMatch(
        array $conditions,
        array $context
    ) {
        foreach ($conditions as $condition) {
            if (!self::conditionMatches($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断单一条件是否匹配 / Checks whether one condition matches
     *
     * @param array $condition 条件 / Condition
     * @param array $context 运行上下文 / Runtime context
     * @return bool 是否匹配 / Whether matched
     */
    private static function conditionMatches(
        array $condition,
        array $context
    ) {
        $type = $condition['type'];
        $operator = $condition['operator'];
        $expected = $condition['value'];

        if ($type === 'target_tag') {
            if (!array_key_exists('target_tags', $context)
                || !is_array($context['target_tags'])) {
                return false;
            }
            $actualTags = array_values(array_filter(
                $context['target_tags'],
                'is_string'
            ));
            $expectedTags = is_array($expected) ? $expected : [$expected];
            $intersection = array_intersect($expectedTags, $actualTags);
            if ($operator === 'not_in') {
                return empty($intersection);
            }
            return !empty($intersection);
        }

        if (!array_key_exists($type, $context)) {
            return false;
        }
        $actual = $context[$type];
        if ($type === 'distance') {
            if (!is_numeric($actual) || !is_finite((float) $actual)) {
                return false;
            }
            $actual = (float) $actual;
            $expected = (float) $expected;
            switch ($operator) {
                case 'lte':
                    return $actual <= $expected;
                case 'gte':
                    return $actual >= $expected;
                case 'lt':
                    return $actual < $expected;
                case 'gt':
                    return $actual > $expected;
                case 'eq':
                    return abs($actual - $expected) < 0.000001;
                default:
                    return false;
            }
        }

        if ($operator === 'eq') {
            return is_string($actual) && $actual === $expected;
        }
        if ($operator === 'in') {
            return is_string($actual)
                && is_array($expected)
                && in_array($actual, $expected, true);
        }

        return false;
    }

    /**
     * 将求值结果限制在机制安全范围 / Bounds a resolved value to the mechanism-safe range
     *
     * @param string $mechanism 机制代码 / Mechanism code
     * @param float $value 求值结果 / Resolved value
     * @return float 安全值 / Safe value
     */
    private static function boundResolvedValue($mechanism, $value) {
        $definition = SkillMechanismRegistry::get($mechanism);
        if ($definition === null || $definition['value'] === null) {
            return 0.0;
        }

        return max(
            (float) $definition['value']['minimum'],
            min((float) $definition['value']['maximum'], (float) $value)
        );
    }

    /**
     * 从曲线和上下文推断最高等级 / Infers max level from curves and context
     *
     * @param array $definition 技能定义 / Skill definition
     * @param array $context 求值上下文 / Evaluation context
     * @return int 最高等级 / Maximum level
     */
    private static function inferMaxLevel(
        array $definition,
        array $context
    ) {
        $maximum = isset($context['max_level'])
            ? max(1, min(100, (int) $context['max_level']))
            : 1;
        self::scanCurveLength($definition, $maximum);
        return max(
            $maximum,
            isset($context['skill_level'])
                ? max(1, min(100, (int) $context['skill_level']))
                : 1
        );
    }

    /**
     * 递归寻找最长等级曲线 / Recursively finds the longest level curve
     *
     * @param mixed $value 当前值 / Current value
     * @param int $maximum 当前最大长度 / Current maximum length
     * @return void
     */
    private static function scanCurveLength($value, &$maximum) {
        if (!is_array($value)) {
            return;
        }
        if (isset($value['mode'], $value['values'])
            && is_string($value['mode'])
            && ($value['mode'] === 'level_values'
                || substr($value['mode'], -13) === '_level_values')
            && is_array($value['values'])) {
            $maximum = max($maximum, min(100, count($value['values'])));
        }
        foreach ($value as $child) {
            self::scanCurveLength($child, $maximum);
        }
    }

    /**
     * 推断主动或被动类型 / Infers active or passive activation type
     *
     * @param array $definition 技能定义 / Skill definition
     * @return string 发动类型 / Activation type
     */
    private static function inferActivationType(array $definition) {
        if (isset($definition['snapshot'])
            && $definition['snapshot'] === true) {
            return 'active';
        }
        if (isset($definition['application_mode'])
            && $definition['application_mode'] === 'continuous') {
            return 'passive';
        }
        if (isset($definition['schema_version'])) {
            if (isset($definition['cooldown'])
                || isset($definition['duration'])) {
                return 'active';
            }
            foreach ($definition['effects'] ?? [] as $effect) {
                if (!is_array($effect)
                    || !isset($effect['mechanism'])) {
                    continue;
                }
                $mechanism = SkillMechanismRegistry::get(
                    $effect['mechanism']
                );
                if ($mechanism !== null
                    && $mechanism['kind'] === 'action') {
                    return 'active';
                }
            }
            return 'passive';
        }

        return isset($definition['duration'])
            || isset($definition['all_resources'])
            || isset($definition['healing'])
            ? 'active'
            : 'passive';
    }

    /**
     * 构建失败结果 / Builds an invalid evaluation result
     *
     * @param array $errors 错误列表 / Errors
     * @return array 失败结果 / Invalid result
     */
    private static function invalidResult(array $errors) {
        return [
            'valid' => false,
            'errors' => array_values($errors),
            'modifiers' => [],
            'actions' => [],
            'cooldown_seconds' => null,
            'duration_seconds' => null
        ];
    }
}

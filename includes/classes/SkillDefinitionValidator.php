<?php
// 种火集结号 - 技能定义校验器 / Fireseed Engage - Skill definition validator

/**
 * 对旧平面定义与第二版可组合定义执行统一校验 / Centrally validates legacy flat definitions and version-two composable definitions
 */
class SkillDefinitionValidator {
    const SCHEMA_VERSION = 2;
    const MAX_EFFECTS = 32;
    const MAX_CONDITIONS = 8;
    const MAX_DEPTH = 10;
    const MAX_NODES = 2000;

    /**
     * 校验技能定义 / Validates a skill definition
     *
     * @param array $definition 技能效果定义 / Skill-effect definition
     * @param int $maxLevel 技能最高等级 / Maximum skill level
     * @param string $activationType 发动类型 / Activation type
     * @param bool $allowLegacy 是否允许旧平面格式 / Whether legacy flat format is allowed
     * @param bool $allowSnapshot 是否允许内部限时快照 / Whether an internal timed snapshot is allowed
     * @return array 校验结果 / Validation result
     */
    public static function validate(
        array $definition,
        $maxLevel,
        $activationType,
        $allowLegacy = true,
        $allowSnapshot = false
    ) {
        $maxLevel = (int) $maxLevel;
        $activationType = strtolower(trim((string) $activationType));
        $errors = [];

        if ($maxLevel < 1 || $maxLevel > SkillValueResolver::MAX_CURVE_LENGTH) {
            $errors[] = '最高等级须为1至100 / max_level must be between 1 and 100';
        }
        if (!in_array($activationType, ['active', 'passive'], true)) {
            $errors[] = '发动类型无效 / Invalid activation type';
        }

        $shape = self::measureShape($definition);
        if ($shape['depth'] > self::MAX_DEPTH) {
            $errors[] = '技能定义嵌套过深 / Skill definition is nested too deeply';
        }
        if ($shape['nodes'] > self::MAX_NODES) {
            $errors[] = '技能定义项目过多 / Skill definition contains too many nodes';
        }
        if (!empty($errors)) {
            return self::result(false, $errors, null, false);
        }

        if (!array_key_exists('schema_version', $definition)) {
            if (!$allowLegacy) {
                return self::result(
                    false,
                    ['必须使用第二版技能定义 / Version-two skill definition required'],
                    null,
                    false
                );
            }

            return self::validateLegacy(
                $definition,
                $maxLevel,
                $activationType
            );
        }

        return self::validateStructured(
            $definition,
            $maxLevel,
            $activationType,
            $allowSnapshot
        );
    }

    /**
     * 判断是否为第二版结构化定义 / Checks whether a definition uses schema version two
     *
     * @param array $definition 技能定义 / Skill definition
     * @return bool 是否为第二版 / Whether version two
     */
    public static function isStructured(array $definition) {
        return isset($definition['schema_version'])
            && (int) $definition['schema_version'] === self::SCHEMA_VERSION;
    }

    /**
     * 校验旧平面定义 / Validates a legacy flat definition
     *
     * @param array $definition 旧定义 / Legacy definition
     * @param int $maxLevel 最高等级 / Maximum level
     * @param string $activationType 发动类型 / Activation type
     * @return array 校验结果 / Validation result
     */
    private static function validateLegacy(
        array $definition,
        $maxLevel,
        $activationType
    ) {
        $errors = [];
        $normalized = [];
        $allowedKeys = SkillMechanismRegistry::legacyEffectKeys();

        if (empty($definition) || self::isList($definition)) {
            return self::result(
                false,
                ['旧技能效果必须是非空对象 / Legacy effects must be a non-empty object'],
                null,
                true
            );
        }

        foreach ($definition as $effectKey => $effectValue) {
            if (!is_string($effectKey)
                || !in_array($effectKey, $allowedKeys, true)) {
                $errors[] = '未知旧效果键：' . (string) $effectKey
                    . ' / Unknown legacy effect key';
                continue;
            }

            if (is_array($effectValue)) {
                $legacyMode = isset($effectValue['mode'])
                    && is_string($effectValue['mode'])
                    ? $effectValue['mode']
                    : '';
                $supportedModes = $activationType === 'passive'
                    ? ['level_values', 'cost_level_values']
                    : [];
                if (!in_array($legacyMode, $supportedModes, true)) {
                    $errors[] = $effectKey
                        . ' 使用了旧运行时不支持的曲线模式；请改用第二版机制'
                        . ' / uses a curve mode unsupported by the legacy runtime; use schema version two';
                    continue;
                }
            }

            $bounds = self::legacyBounds($effectKey);
            $valueValidation = SkillValueResolver::validate(
                $effectValue,
                $maxLevel,
                $bounds['minimum'],
                $bounds['maximum'],
                $effectKey
            );
            if (!$valueValidation['valid']) {
                $errors = array_merge(
                    $errors,
                    $valueValidation['errors']
                );
                continue;
            }

            $normalized[$effectKey] = $valueValidation['value'];
        }

        $actionKeys = ['all_resources', 'healing'];
        $hasAction = false;
        $hasModifier = false;
        $hasProductionModifier = false;
        foreach (array_keys($normalized) as $effectKey) {
            if (in_array($effectKey, $actionKeys, true)) {
                $hasAction = true;
            } elseif ($effectKey !== 'duration') {
                $hasModifier = true;
                if ($effectKey === 'production'
                    || strpos($effectKey, 'production_') === 0) {
                    $hasProductionModifier = true;
                }
            }
        }
        $hasDuration = array_key_exists('duration', $normalized);

        if ($activationType === 'passive') {
            if ($hasAction) {
                $errors[] = '旧被动技能不能包含即时动作'
                    . ' / Legacy passive skills cannot contain instant actions';
            }
            if ($hasDuration) {
                $errors[] = '被动技能不能设置duration'
                    . ' / Passive skills cannot define duration';
            }
        } else {
            if ($hasProductionModifier) {
                $errors[] = '旧主动技能不能包含区间生产修正'
                    . ' / Legacy active skills cannot contain interval-based production modifiers';
            }
            if ($hasModifier) {
                if (!$hasDuration
                    || (float) $normalized['duration'] <= 0.0) {
                    $errors[] = '旧主动修正必须设置正数duration'
                        . ' / Legacy active modifiers require a positive duration';
                }
            } elseif ($hasDuration) {
                $errors[] = '旧即时动作和duration不能单独或共同使用'
                    . ' / Legacy duration requires a modifier and cannot accompany action-only skills';
            } elseif (!$hasAction) {
                $errors[] = '旧主动技能必须包含即时动作或限时修正'
                    . ' / Legacy active skills need an instant action or timed modifier';
            }
        }

        return self::result(
            empty($errors),
            $errors,
            empty($errors) ? $normalized : null,
            true
        );
    }

    /**
     * 校验第二版结构化定义 / Validates a version-two structured definition
     *
     * @param array $definition 技能定义 / Skill definition
     * @param int $maxLevel 最高等级 / Maximum level
     * @param string $activationType 发动类型 / Activation type
     * @param bool $allowSnapshot 是否允许内部快照 / Whether an internal snapshot is allowed
     * @return array 校验结果 / Validation result
     */
    private static function validateStructured(
        array $definition,
        $maxLevel,
        $activationType,
        $allowSnapshot
    ) {
        $errors = [];
        $isSnapshot = isset($definition['snapshot'])
            && $definition['snapshot'] === true;
        $allowedKeys = [
            'schema_version',
            'application_mode',
            'cooldown',
            'duration',
            'effects'
        ];
        if ($allowSnapshot) {
            $allowedKeys[] = 'snapshot';
        }
        self::rejectUnknownKeys(
            $definition,
            $allowedKeys,
            'definition',
            $errors
        );

        if (!isset($definition['schema_version'])
            || !is_int($definition['schema_version'])
            || $definition['schema_version'] !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version 必须严格为2 / schema_version must be integer 2';
        }
        if ($isSnapshot && !$allowSnapshot) {
            $errors[] = '管理数据不能伪造运行时快照 / Administration data cannot forge runtime snapshots';
        }

        if (!isset($definition['effects'])
            || !is_array($definition['effects'])
            || !self::isList($definition['effects'])
            || empty($definition['effects'])) {
            $errors[] = 'effects 必须是非空数组 / effects must be a non-empty list';
            return self::result(false, $errors, null, false);
        }
        if (count($definition['effects']) > self::MAX_EFFECTS) {
            $errors[] = '单技能最多组合32个机制 / A skill may compose at most 32 mechanisms';
        }

        $normalized = [
            'schema_version' => self::SCHEMA_VERSION,
            'effects' => []
        ];
        if ($isSnapshot && $allowSnapshot) {
            $normalized['snapshot'] = true;
        }

        foreach (['cooldown', 'duration'] as $metaKey) {
            if (!array_key_exists($metaKey, $definition)) {
                continue;
            }
            if ($isSnapshot) {
                $errors[] = '运行时快照不能包含 ' . $metaKey
                    . ' / Runtime snapshots cannot include ' . $metaKey;
                continue;
            }

            $minimum = $metaKey === 'duration' ? 1.0 : 0.0;
            $metaValidation = SkillValueResolver::validate(
                $definition[$metaKey],
                $maxLevel,
                $minimum,
                31536000.0,
                $metaKey
            );
            if (!$metaValidation['valid']) {
                $errors = array_merge($errors, $metaValidation['errors']);
            } else {
                $normalized[$metaKey] = $metaValidation['value'];
            }
        }

        $modifierCount = 0;
        $actionCount = 0;
        foreach ($definition['effects'] as $index => $effect) {
            $validatedEffect = self::validateEffect(
                $effect,
                $maxLevel,
                $activationType,
                $isSnapshot,
                $index
            );
            if (!$validatedEffect['valid']) {
                $errors = array_merge($errors, $validatedEffect['errors']);
                continue;
            }

            $normalized['effects'][] = $validatedEffect['effect'];
            if ($validatedEffect['kind'] === 'action') {
                $actionCount++;
            } else {
                $modifierCount++;
            }
        }

        if ($isSnapshot) {
            if ($actionCount > 0) {
                $errors[] = '限时快照不能包含即时动作 / Timed snapshots cannot contain instant actions';
            }
            if ($modifierCount === 0) {
                $errors[] = '限时快照至少需要一个修正 / Timed snapshots need at least one modifier';
            }
            return self::result(
                empty($errors),
                $errors,
                empty($errors) ? $normalized : null,
                false
            );
        }

        $applicationMode = self::resolveApplicationMode(
            $definition,
            $activationType,
            $modifierCount,
            $actionCount
        );
        if ($applicationMode === null) {
            $errors[] = 'application_mode 无效 / Invalid application_mode';
        } else {
            $normalized['application_mode'] = $applicationMode;
        }

        if ($activationType === 'passive') {
            if ($applicationMode !== 'continuous') {
                $errors[] = '被动技能必须持续生效 / Passive skills must use continuous mode';
            }
            if (isset($definition['cooldown'])
                || isset($definition['duration'])) {
                $errors[] = '被动技能不能设置冷却或持续时间 / Passive skills cannot define cooldown or duration';
            }
        } elseif ($applicationMode === 'continuous') {
            $errors[] = '主动技能不能使用continuous模式'
                . ' / Active skills cannot use continuous mode';
        } elseif ($applicationMode === 'timed') {
            if (!isset($definition['duration'])) {
                $errors[] = 'timed主动技能必须设置duration / Timed active skills must define duration';
            }
            if ($modifierCount === 0) {
                $errors[] = 'timed主动技能至少需要一个修正 / Timed active skills need a modifier';
            }
        } elseif ($applicationMode === 'instant') {
            if ($modifierCount > 0 || $actionCount === 0) {
                $errors[] = 'instant主动技能只能包含即时动作 / Instant active skills may contain only actions';
            }
            if (isset($definition['duration'])) {
                $errors[] = 'instant主动技能不能设置duration / Instant active skills cannot define duration';
            }
        } elseif (in_array(
            $applicationMode,
            ['next_dispatch', 'dispatch_snapshot'],
            true
        )) {
            $errors[] = '下一次出征型主动技能目前仅分类占位，尚未开放'
                . ' / Next-dispatch active skills are currently placeholders';
        }

        return self::result(
            empty($errors),
            $errors,
            empty($errors) ? $normalized : null,
            false
        );
    }

    /**
     * 校验单一机制项 / Validates one mechanism entry
     *
     * @param mixed $effect 机制项 / Mechanism entry
     * @param int $maxLevel 最高等级 / Maximum level
     * @param string $activationType 发动类型 / Activation type
     * @param bool $isSnapshot 是否为内部快照 / Whether this is an internal snapshot
     * @param int $index 数组索引 / List index
     * @return array 校验结果 / Validation result
     */
    private static function validateEffect(
        $effect,
        $maxLevel,
        $activationType,
        $isSnapshot,
        $index
    ) {
        $path = 'effects[' . $index . ']';
        $errors = [];
        if (!is_array($effect) || self::isList($effect)) {
            return [
                'valid' => false,
                'errors' => [$path . ' 必须是对象 / must be an object'],
                'effect' => null,
                'kind' => null
            ];
        }
        self::rejectUnknownKeys(
            $effect,
            ['mechanism', 'parameters', 'value', 'conditions'],
            $path,
            $errors
        );

        $mechanism = isset($effect['mechanism'])
            && is_string($effect['mechanism'])
            ? trim($effect['mechanism'])
            : '';
        $mechanismDefinition = $mechanism !== ''
            ? SkillMechanismRegistry::get($mechanism)
            : null;
        if ($mechanismDefinition === null) {
            $errors[] = $path . '.mechanism 未注册 / is not registered';
        } elseif ($mechanismDefinition['status']
            !== SkillMechanismRegistry::STATUS_IMPLEMENTED) {
            $errors[] = $path . '.mechanism 目前仅为占位 / is currently a placeholder';
        } elseif (!in_array(
                $activationType,
                $mechanismDefinition['activation_types'],
                true
            )) {
            $errors[] = $path . '.mechanism 与发动类型不兼容'
                . ' / is incompatible with the activation type';
        }

        if ($mechanismDefinition === null
            || $mechanismDefinition['status']
                !== SkillMechanismRegistry::STATUS_IMPLEMENTED) {
            return [
                'valid' => false,
                'errors' => $errors,
                'effect' => null,
                'kind' => null
            ];
        }

        $parameters = isset($effect['parameters'])
            ? $effect['parameters']
            : [];
        $normalizedParameters = self::validateParameters(
            $parameters,
            $mechanismDefinition['parameters'],
            $path . '.parameters',
            $errors
        );

        if (!array_key_exists('value', $effect)) {
            $errors[] = $path . '.value 缺失 / is required';
            $normalizedValue = null;
        } else {
            $valueDefinition = $mechanismDefinition['value'];
            $valueValidation = SkillValueResolver::validate(
                $effect['value'],
                $isSnapshot ? 1 : $maxLevel,
                $valueDefinition['minimum'],
                $valueDefinition['maximum'],
                $path . '.value'
            );
            if (!$valueValidation['valid']) {
                $errors = array_merge($errors, $valueValidation['errors']);
                $normalizedValue = null;
            } else {
                $normalizedValue = $valueValidation['value'];
            }
        }

        $conditions = isset($effect['conditions'])
            ? $effect['conditions']
            : [];
        $conditionScope = self::resolveConditionScope(
            $mechanismDefinition,
            $normalizedParameters
        );
        $normalizedConditions = self::validateConditions(
            $conditions,
            $path . '.conditions',
            $conditionScope['allowed_conditions'],
            $conditionScope['allowed_phase_values'],
            $conditionScope['allowed_condition_values'],
            $errors
        );
        if (empty($errors)
            && !empty($normalizedConditions)
            && !self::conditionsMatchAnyConsumerContext(
                $mechanism,
                $normalizedParameters,
                $normalizedConditions
            )) {
            $errors[] = $path . '.conditions 无法被该机制的任何实际消费者上下文满足'
                . ' / cannot be satisfied by any real consumer context for this mechanism';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'effect' => empty($errors)
                ? [
                    'mechanism' => $mechanism,
                    'parameters' => $normalizedParameters,
                    'value' => $normalizedValue,
                    'conditions' => $normalizedConditions
                ]
                : null,
            'kind' => $mechanismDefinition['kind']
        ];
    }

    /**
     * 按机制参数收窄实际可用条件 / Narrows executable conditions by mechanism parameters
     *
     * @param array $mechanismDefinition 机制定义 / Mechanism definition
     * @param array $parameters 已标准化参数 / Normalized parameters
     * @return array 条件、阶段与条件值白名单 / Condition, phase, and condition-value allowlists
     */
    private static function resolveConditionScope(
        array $mechanismDefinition,
        array $parameters
    ) {
        $allowedConditions = isset(
            $mechanismDefinition['allowed_conditions']
        ) ? $mechanismDefinition['allowed_conditions'] : [];
        $allowedPhases = isset(
            $mechanismDefinition['allowed_phase_values']
        ) ? $mechanismDefinition['allowed_phase_values'] : [];
        $allowedConditionValues = isset(
            $mechanismDefinition['allowed_condition_values']
        ) ? $mechanismDefinition['allowed_condition_values'] : [];

        $conditionOverrides = isset(
            $mechanismDefinition['allowed_conditions_by_parameter']
        ) ? $mechanismDefinition['allowed_conditions_by_parameter'] : [];
        foreach ($conditionOverrides as $parameterName => $valueMap) {
            if (!isset($parameters[$parameterName])
                || !isset($valueMap[$parameters[$parameterName]])) {
                continue;
            }
            $allowedConditions = array_values(
                array_intersect(
                    $allowedConditions,
                    $valueMap[$parameters[$parameterName]]
                )
            );
        }

        $phaseOverrides = isset(
            $mechanismDefinition['allowed_phase_values_by_parameter']
        ) ? $mechanismDefinition['allowed_phase_values_by_parameter'] : [];
        foreach ($phaseOverrides as $parameterName => $valueMap) {
            if (!isset($parameters[$parameterName])
                || !isset($valueMap[$parameters[$parameterName]])) {
                continue;
            }
            $allowedPhases = array_values(
                array_intersect(
                    $allowedPhases,
                    $valueMap[$parameters[$parameterName]]
                )
            );
        }

        $valueOverrides = isset(
            $mechanismDefinition[
                'allowed_condition_values_by_parameter'
            ]
        ) ? $mechanismDefinition[
            'allowed_condition_values_by_parameter'
        ] : [];
        foreach ($valueOverrides as $parameterName => $valueMap) {
            if (!isset($parameters[$parameterName])
                || !isset($valueMap[$parameters[$parameterName]])
                || !is_array($valueMap[$parameters[$parameterName]])) {
                continue;
            }
            foreach (
                $valueMap[$parameters[$parameterName]]
                as $conditionType => $values
            ) {
                if (!is_array($values)) {
                    continue;
                }
                $allowedConditionValues[$conditionType] = isset(
                    $allowedConditionValues[$conditionType]
                )
                    ? array_values(
                        array_intersect(
                            $allowedConditionValues[$conditionType],
                            $values
                        )
                    )
                    : array_values($values);
            }
        }

        return [
            'allowed_conditions' => array_values(
                array_unique($allowedConditions)
            ),
            'allowed_phase_values' => array_values(
                array_unique($allowedPhases)
            ),
            'allowed_condition_values' => $allowedConditionValues
        ];
    }

    /**
     * 校验机制参数 / Validates mechanism parameters
     *
     * @param mixed $parameters 输入参数 / Input parameters
     * @param array $definitions 参数定义 / Parameter definitions
     * @param string $path 错误路径 / Error path
     * @param array $errors 错误列表 / Error list
     * @return array 标准化参数 / Normalized parameters
     */
    private static function validateParameters(
        $parameters,
        array $definitions,
        $path,
        array &$errors
    ) {
        $emptyObjectEquivalent = is_array($parameters)
            && empty($parameters)
            && empty($definitions);
        if (!is_array($parameters)
            || (self::isList($parameters) && !$emptyObjectEquivalent)) {
            $errors[] = $path . ' 必须是对象 / must be an object';
            return [];
        }

        self::rejectUnknownKeys(
            $parameters,
            array_keys($definitions),
            $path,
            $errors
        );
        $normalized = [];
        foreach ($definitions as $parameterName => $definition) {
            $hasValue = array_key_exists($parameterName, $parameters);
            $value = $hasValue
                ? $parameters[$parameterName]
                : ($definition['default'] ?? null);
            if (!$hasValue
                && !array_key_exists('default', $definition)
                && !empty($definition['required'])) {
                $errors[] = $path . '.' . $parameterName
                    . ' 缺失 / is required';
                continue;
            }

            if ($definition['type'] === 'enum') {
                if (!is_string($value)
                    || !isset($definition['options'][$value])) {
                    $errors[] = $path . '.' . $parameterName
                        . ' 不是允许值 / is not an allowed value';
                    continue;
                }
                $normalized[$parameterName] = $value;
            } else {
                $errors[] = $path . '.' . $parameterName
                    . ' 使用未知参数类型 / uses an unknown parameter type';
            }
        }

        return $normalized;
    }

    /**
     * 校验条件列表 / Validates a condition list
     *
     * @param mixed $conditions 条件列表 / Condition list
     * @param string $path 错误路径 / Error path
     * @param array $allowedConditionTypes 可用条件 / Allowed conditions
     * @param array $allowedPhaseValues 可用阶段 / Allowed phase values
     * @param array $allowedConditionValues 条件值白名单 / Condition-value allowlists
     * @param array $errors 错误列表 / Error list
     * @return array 标准化条件 / Normalized conditions
     */
    private static function validateConditions(
        $conditions,
        $path,
        array $allowedConditionTypes,
        array $allowedPhaseValues,
        array $allowedConditionValues,
        array &$errors
    ) {
        if (!is_array($conditions) || !self::isList($conditions)) {
            $errors[] = $path . ' 必须是数组 / must be a list';
            return [];
        }
        if (count($conditions) > self::MAX_CONDITIONS) {
            $errors[] = $path . ' 最多8项 / may contain at most 8 entries';
        }

        $conditionDefinitions = SkillMechanismRegistry::conditions();
        $normalized = [];
        foreach ($conditions as $index => $condition) {
            $conditionPath = $path . '[' . $index . ']';
            if (!is_array($condition) || self::isList($condition)) {
                $errors[] = $conditionPath
                    . ' 必须是对象 / must be an object';
                continue;
            }
            self::rejectUnknownKeys(
                $condition,
                ['type', 'operator', 'value'],
                $conditionPath,
                $errors
            );

            $type = isset($condition['type'])
                && is_string($condition['type'])
                ? trim($condition['type'])
                : '';
            if (!isset($conditionDefinitions[$type])) {
                $errors[] = $conditionPath
                    . '.type 未注册 / is not registered';
                continue;
            }
            if (!in_array($type, $allowedConditionTypes, true)) {
                $errors[] = $conditionPath
                    . '.type 在该机制运行上下文中不可用'
                    . ' / is unavailable in this mechanism runtime context';
                continue;
            }
            $definition = $conditionDefinitions[$type];
            $operator = isset($condition['operator'])
                && is_string($condition['operator'])
                ? trim($condition['operator'])
                : '';
            if (!in_array($operator, $definition['operators'], true)) {
                $errors[] = $conditionPath
                    . '.operator 不受支持 / is not supported';
                continue;
            }
            if (!array_key_exists('value', $condition)) {
                $errors[] = $conditionPath . '.value 缺失 / is required';
                continue;
            }

            $value = $condition['value'];
            if ($definition['type'] === 'number') {
                if (!SkillValueResolver::isFiniteNumber($value)
                    || (float) $value < (float) $definition['minimum']
                    || (float) $value > (float) $definition['maximum']) {
                    $errors[] = $conditionPath
                        . '.value 超出允许范围 / is outside the allowed range';
                    continue;
                }
                $normalizedValue = (float) $value;
            } else {
                $expectsList = in_array(
                    $operator,
                    ['in', 'not_in'],
                    true
                );
                $candidateValues = $expectsList ? $value : [$value];
                if (!is_array($candidateValues)
                    || empty($candidateValues)
                    || count($candidateValues) > 16) {
                    $errors[] = $conditionPath
                        . '.value 不是有效枚举列表 / is not a valid enum list';
                    continue;
                }
                $valid = true;
                $candidateValues = array_values(array_unique($candidateValues));
                foreach ($candidateValues as $candidateValue) {
                    if (!is_string($candidateValue)
                        || !isset($definition['options'][$candidateValue])) {
                        $valid = false;
                        break;
                    }
                }
                if (!$valid) {
                    $errors[] = $conditionPath
                        . '.value 含未知枚举值 / contains an unknown enum value';
                    continue;
                }
                $normalizedValue = $expectsList
                    ? $candidateValues
                    : $candidateValues[0];
            }

            if ($type === 'phase') {
                $phaseValues = is_array($normalizedValue)
                    ? $normalizedValue
                    : [$normalizedValue];
                $unsupportedPhase = false;
                foreach ($phaseValues as $phaseValue) {
                    if (!in_array(
                        $phaseValue,
                        $allowedPhaseValues,
                        true
                    )) {
                        $unsupportedPhase = true;
                        break;
                    }
                }
                if ($unsupportedPhase) {
                    $errors[] = $conditionPath
                        . '.value 在该机制中没有运行时消费者'
                        . ' / has no runtime consumer for this mechanism';
                    continue;
                }
            }

            if (isset($allowedConditionValues[$type])) {
                $conditionValues = is_array($normalizedValue)
                    ? $normalizedValue
                    : [$normalizedValue];
                $unsupportedValue = false;
                foreach ($conditionValues as $conditionValue) {
                    if (!in_array(
                        $conditionValue,
                        $allowedConditionValues[$type],
                        true
                    )) {
                        $unsupportedValue = true;
                        break;
                    }
                }
                if ($unsupportedValue) {
                    $errors[] = $conditionPath
                        . '.value 在该机制中没有运行时消费者'
                        . ' / has no runtime consumer for this mechanism';
                    continue;
                }
            }

            $normalized[] = [
                'type' => $type,
                'operator' => $operator,
                'value' => $normalizedValue
            ];
        }

        return $normalized;
    }

    /**
     * 判断AND条件能否命中至少一个真实消费者上下文 / Checks whether AND conditions can match at least one real consumer context
     *
     * @param string $mechanism 机制代码 / Mechanism code
     * @param array $parameters 已标准化参数 / Normalized parameters
     * @param array $conditions 已标准化条件 / Normalized conditions
     * @return bool 是否存在可达上下文 / Whether a reachable context exists
     */
    private static function conditionsMatchAnyConsumerContext(
        $mechanism,
        array $parameters,
        array $conditions
    ) {
        $contexts = self::resolveConsumerContexts(
            $mechanism,
            $parameters
        );
        foreach ($contexts as $context) {
            if (self::consumerContextMatchesConditions(
                $context,
                $conditions
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * 按机制与参数解析真实消费者上下文 / Resolves real consumer contexts by mechanism and parameters
     *
     * 新机制只需登记所使用的上下文档案；参数分流机制可按参数值选择档案。
     * A new mechanism only needs a context-profile route; parameterized mechanisms may select profiles by parameter value.
     *
     * @param string $mechanism 机制代码 / Mechanism code
     * @param array $parameters 已标准化参数 / Normalized parameters
     * @return array 消费者上下文列表 / Consumer context list
     */
    private static function resolveConsumerContexts(
        $mechanism,
        array $parameters
    ) {
        $routes = [
            'army_stat_percent' => [
                'parameter' => 'stat',
                'profiles_by_value' => [
                    'attack' => ['battle_power'],
                    'defense' => ['battle_power'],
                    'speed' => ['movement']
                ]
            ],
            'army_stat_multiplier' => [
                'parameter' => 'stat',
                'profiles_by_value' => [
                    'attack' => ['battle_power'],
                    'defense' => ['battle_power'],
                    'speed' => ['movement']
                ]
            ],
            'army_element_stat_percent' => [
                'parameter' => 'stat',
                'profiles_by_value' => [
                    'attack' => ['battle_power'],
                    'defense' => ['battle_power'],
                    'speed' => ['movement']
                ]
            ],
            'army_damage_reduction_percent' => [
                'profiles' => ['battle_damage_reduction']
            ],
            'army_return_speed_percent' => [
                'profiles' => ['return_movement']
            ],
            'army_siege_damage_percent' => [
                'profiles' => ['siege']
            ],
            'army_siege_damage_flat' => [
                'profiles' => ['siege']
            ],
            'army_siege_damage_multiplier' => [
                'profiles' => ['siege']
            ],
            'scout_range_bonus' => [
                'profiles' => ['scouting']
            ],
            'city_resource_production_percent' => [
                'profiles' => ['production']
            ],
            'city_training_speed_percent' => [
                'profiles' => ['training']
            ],
            'city_training_cost_reduction_percent' => [
                'profiles' => ['training']
            ],
            'city_construction_speed_percent' => [
                'profiles' => ['construction']
            ],
            'city_defense_percent' => [
                'profiles' => ['city_defense']
            ]
        ];
        if (!isset($routes[$mechanism])) {
            return [];
        }

        $route = $routes[$mechanism];
        if (isset($route['parameter'], $route['profiles_by_value'])) {
            $parameterName = $route['parameter'];
            $parameterValue = isset($parameters[$parameterName])
                ? $parameters[$parameterName]
                : null;
            $profileNames = $parameterValue !== null
                && isset($route['profiles_by_value'][$parameterValue])
                ? $route['profiles_by_value'][$parameterValue]
                : [];
        } else {
            $profileNames = isset($route['profiles'])
                ? $route['profiles']
                : [];
        }

        $profiles = self::consumerContextProfiles();
        $contexts = [];
        foreach ($profileNames as $profileName) {
            if (!isset($profiles[$profileName])) {
                continue;
            }
            foreach ($profiles[$profileName] as $context) {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    /**
     * 声明现有玩法钩子的真实上下文签名 / Declares real context signatures for current gameplay hooks
     *
     * @return array 上下文档案 / Context profiles
     */
    private static function consumerContextProfiles() {
        $conditionDefinitions = SkillMechanismRegistry::conditions();
        $distanceDefinition = $conditionDefinitions['distance'];
        $mapWidth = defined('MAP_WIDTH')
            ? max(1, (int) constant('MAP_WIDTH'))
            : 512;
        $mapHeight = defined('MAP_HEIGHT')
            ? max(1, (int) constant('MAP_HEIGHT'))
            : 512;
        // 条件目录的宽松安全上限不能扩大地图真实可达距离。 / The condition catalog's loose safety cap must not expand the map's reachable distance.
        $maximumMapDistance = ($mapWidth - 1) + ($mapHeight - 1);
        $distanceRange = [
            'minimum' => (float) $distanceDefinition['minimum'],
            'maximum' => min(
                (float) $distanceDefinition['maximum'],
                (float) $maximumMapDistance
            )
        ];
        $zeroDistance = [
            'minimum' => 0.0,
            'maximum' => 0.0
        ];

        // 常规战斗目标由Battle::getDefenderTargetTags()产生。 / Standard battle targets come from Battle::getDefenderTargetTags().
        $standardAttackContexts = [
            [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['city', 'structure', 'player'],
                'distance_range' => $distanceRange
            ],
            [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['army', 'player'],
                'distance_range' => $distanceRange
            ],
            [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['tile'],
                'distance_range' => $distanceRange
            ],
            [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['tile', 'player'],
                'distance_range' => $distanceRange
            ],
            [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['tile', 'npc', 'structure'],
                'distance_range' => $distanceRange
            ]
        ];
        $standardDefenseContext = [
            'phase' => 'battle',
            'side' => 'defense',
            'target_tags' => ['army', 'player'],
            'distance_range' => $distanceRange
        ];
        $challengeContexts = [
            [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['army', 'npc'],
                'distance_range' => $zeroDistance
            ],
            [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['npc', 'structure'],
                'distance_range' => $zeroDistance
            ]
        ];
        $seasonContexts = [
            [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['structure', 'npc'],
                'distance_range' => $zeroDistance
            ],
            [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['structure', 'player'],
                'distance_range' => $zeroDistance
            ]
        ];

        return [
            'battle_power' => array_merge(
                $standardAttackContexts,
                [$standardDefenseContext],
                $challengeContexts,
                $seasonContexts
            ),
            'battle_damage_reduction' => array_merge(
                $standardAttackContexts,
                [$standardDefenseContext],
                $seasonContexts
            ),
            'movement' => [
                [
                    'phase' => 'march',
                    'distance_range' => $distanceRange
                ],
                [
                    'phase' => 'return',
                    'distance_range' => $distanceRange
                ]
            ],
            'return_movement' => [
                [
                    'phase' => 'return',
                    'distance_range' => $distanceRange
                ]
            ],
            'siege' => [
                [
                    'phase' => 'battle',
                    'side' => 'attack',
                    'target_tags' => ['city', 'structure', 'player'],
                    'distance_range' => $distanceRange
                ]
            ],
            'scouting' => [
                ['phase' => 'scouting']
            ],
            'production' => [
                ['phase' => 'production']
            ],
            'training' => [
                ['phase' => 'training']
            ],
            'construction' => [
                ['phase' => 'construction']
            ],
            'city_defense' => [
                [
                    'phase' => 'city_defense',
                    'side' => 'defense',
                    'target_tags' => ['army', 'player'],
                    'distance_range' => $distanceRange
                ]
            ]
        ];
    }

    /**
     * 判断一个上下文签名是否满足全部条件 / Checks whether one context signature satisfies every condition
     *
     * @param array $context 上下文签名 / Context signature
     * @param array $conditions 已标准化条件 / Normalized conditions
     * @return bool 是否满足 / Whether matched
     */
    private static function consumerContextMatchesConditions(
        array $context,
        array $conditions
    ) {
        $distanceConditions = [];
        foreach ($conditions as $condition) {
            $type = $condition['type'];
            $operator = $condition['operator'];
            $expected = $condition['value'];
            if ($type === 'distance') {
                $distanceConditions[] = $condition;
                continue;
            }
            if ($type === 'target_tag') {
                if (!isset($context['target_tags'])
                    || !is_array($context['target_tags'])) {
                    return false;
                }
                $expectedTags = is_array($expected)
                    ? $expected
                    : [$expected];
                $intersection = array_intersect(
                    $expectedTags,
                    $context['target_tags']
                );
                if ($operator === 'not_in') {
                    if (!empty($intersection)) {
                        return false;
                    }
                } elseif (empty($intersection)) {
                    return false;
                }
                continue;
            }
            if (!isset($context[$type])) {
                return false;
            }
            if ($operator === 'eq') {
                if ($context[$type] !== $expected) {
                    return false;
                }
            } elseif ($operator === 'in') {
                if (!is_array($expected)
                    || !in_array($context[$type], $expected, true)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        if (empty($distanceConditions)) {
            return true;
        }
        if (!isset($context['distance_range'])
            || !is_array($context['distance_range'])) {
            return false;
        }

        return self::distanceRangeMatchesConditions(
            $context['distance_range'],
            $distanceConditions
        );
    }

    /**
     * 判断整数距离范围内是否有值满足全部条件 / Checks whether an integer distance in a range satisfies every condition
     *
     * @param array $range 距离范围 / Distance range
     * @param array $conditions 距离条件 / Distance conditions
     * @return bool 是否存在匹配距离 / Whether a matching distance exists
     */
    private static function distanceRangeMatchesConditions(
        array $range,
        array $conditions
    ) {
        if (!isset($range['minimum'], $range['maximum'])) {
            return false;
        }
        $minimum = (int) ceil((float) $range['minimum']);
        $maximum = (int) floor((float) $range['maximum']);
        for ($distance = $minimum; $distance <= $maximum; $distance++) {
            $matches = true;
            foreach ($conditions as $condition) {
                $expected = (float) $condition['value'];
                switch ($condition['operator']) {
                    case 'lte':
                        $matches = (float) $distance <= $expected;
                        break;
                    case 'gte':
                        $matches = (float) $distance >= $expected;
                        break;
                    case 'lt':
                        $matches = (float) $distance < $expected;
                        break;
                    case 'gt':
                        $matches = (float) $distance > $expected;
                        break;
                    case 'eq':
                        $matches = abs(
                            (float) $distance - $expected
                        ) < 0.000001;
                        break;
                    default:
                        $matches = false;
                        break;
                }
                if (!$matches) {
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析应用方式 / Resolves the application mode
     *
     * @param array $definition 技能定义 / Skill definition
     * @param string $activationType 发动类型 / Activation type
     * @param int $modifierCount 修正数量 / Modifier count
     * @param int $actionCount 动作数量 / Action count
     * @return string|null 应用方式 / Application mode
     */
    private static function resolveApplicationMode(
        array $definition,
        $activationType,
        $modifierCount,
        $actionCount
    ) {
        if (isset($definition['application_mode'])) {
            if (!is_string($definition['application_mode'])) {
                return null;
            }
            $mode = trim($definition['application_mode']);
        } elseif ($activationType === 'passive') {
            $mode = 'continuous';
        } elseif (isset($definition['duration'])) {
            $mode = 'timed';
        } elseif ($modifierCount === 0 && $actionCount > 0) {
            $mode = 'instant';
        } else {
            $mode = 'timed';
        }

        return in_array(
            $mode,
            [
                'continuous',
                'instant',
                'timed',
                'next_dispatch',
                'dispatch_snapshot'
            ],
            true
        ) ? $mode : null;
    }

    /**
     * 获取旧效果的安全范围 / Gets safe bounds for a legacy effect
     *
     * @param string $effectKey 效果键 / Effect key
     * @return array 安全范围 / Safe bounds
     */
    private static function legacyBounds($effectKey) {
        if ($effectKey === 'duration') {
            return ['minimum' => 1.0, 'maximum' => 31536000.0];
        }
        if ($effectKey === 'all_resources') {
            return ['minimum' => 0.0, 'maximum' => 1000000000.0];
        }
        if ($effectKey === 'healing') {
            return ['minimum' => 0.0, 'maximum' => 1000000.0];
        }
        if ($effectKey === 'damage_reduction') {
            return ['minimum' => 0.0, 'maximum' => 75.0];
        }
        if ($effectKey === 'scout_range') {
            return ['minimum' => 0.0, 'maximum' => 15.0];
        }
        if (strpos($effectKey, 'training_cost_reduction') === 0) {
            return ['minimum' => 0.0, 'maximum' => 95.0];
        }
        if ($effectKey === 'siege_damage_flat') {
            return ['minimum' => 0.0, 'maximum' => 1000000000.0];
        }
        if ($effectKey === 'siege_damage_multiplier') {
            return ['minimum' => 0.0, 'maximum' => 10.0];
        }

        return ['minimum' => 0.0, 'maximum' => 1000.0];
    }

    /**
     * 测量数组深度和节点数 / Measures array depth and node count
     *
     * @param mixed $value 待测值 / Value to measure
     * @param int $depth 当前深度 / Current depth
     * @return array 形状信息 / Shape information
     */
    private static function measureShape($value, $depth = 1) {
        if (!is_array($value)) {
            return ['depth' => $depth, 'nodes' => 1];
        }

        $maximumDepth = $depth;
        $nodes = 1;
        foreach ($value as $child) {
            $shape = self::measureShape($child, $depth + 1);
            $maximumDepth = max($maximumDepth, $shape['depth']);
            $nodes += $shape['nodes'];
            if ($nodes > self::MAX_NODES) {
                break;
            }
        }

        return ['depth' => $maximumDepth, 'nodes' => $nodes];
    }

    /**
     * 判断数组是否为连续列表 / Checks whether an array is a sequential list
     *
     * @param array $value 输入数组 / Input array
     * @return bool 是否为列表 / Whether a list
     */
    private static function isList(array $value) {
        return empty($value)
            || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * 拒绝未知字段 / Rejects unknown fields
     *
     * @param array $value 输入对象 / Input object
     * @param array $allowedKeys 允许字段 / Allowed fields
     * @param string $path 错误路径 / Error path
     * @param array $errors 错误列表 / Error list
     * @return void
     */
    private static function rejectUnknownKeys(
        array $value,
        array $allowedKeys,
        $path,
        array &$errors
    ) {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                $errors[] = $path . ' 包含未知字段 '
                    . (string) $key . ' / contains an unknown field';
            }
        }
    }

    /**
     * 构建校验结果 / Builds a validation result
     *
     * @param bool $valid 是否有效 / Whether valid
     * @param array $errors 错误列表 / Errors
     * @param array|null $definition 标准化定义 / Normalized definition
     * @param bool $legacy 是否为旧格式 / Whether legacy
     * @return array 校验结果 / Validation result
     */
    private static function result(
        $valid,
        array $errors,
        $definition,
        $legacy
    ) {
        return [
            'valid' => (bool) $valid,
            'errors' => array_values($errors),
            'definition' => $definition,
            'legacy' => (bool) $legacy
        ];
    }
}

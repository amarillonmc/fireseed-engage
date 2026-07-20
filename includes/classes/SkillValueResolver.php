<?php
// 种火集结号 - 技能数值解析器 / Fireseed Engage - Skill value resolver

/**
 * 校验并解析受限的技能数值描述符 / Validates and resolves bounded skill-value descriptors
 */
class SkillValueResolver {
    const MAX_CURVE_LENGTH = 100;

    /**
     * 将脏数据中的技能等级钳制到可求值范围 / Clamps a dirty skill level to its evaluable range
     *
     * @param mixed $skillLevel 当前技能等级 / Current skill level
     * @param mixed $maxLevel 目录最高等级 / Catalog maximum level
     * @return int 介于1与最高等级之间的有效等级 / Effective level between one and the maximum
     */
    public static function clampSkillLevel($skillLevel, $maxLevel) {
        $normalizedMaxLevel = max(1, (int) $maxLevel);
        return min(
            $normalizedMaxLevel,
            max(1, (int) $skillLevel)
        );
    }

    /**
     * 校验并标准化一个数值描述符 / Validates and normalizes one value descriptor
     *
     * @param mixed $descriptor 数值或描述符 / Numeric value or descriptor
     * @param int $maxLevel 技能最高等级 / Maximum skill level
     * @param float $minimum 最小允许值 / Minimum allowed value
     * @param float $maximum 最大允许值 / Maximum allowed value
     * @param string $path 错误路径 / Error path
     * @return array 校验结果 / Validation result
     */
    public static function validate(
        $descriptor,
        $maxLevel,
        $minimum,
        $maximum,
        $path = 'value'
    ) {
        $errors = [];
        $maxLevel = max(1, min(self::MAX_CURVE_LENGTH, (int) $maxLevel));
        $minimum = (float) $minimum;
        $maximum = (float) $maximum;

        if ($maximum < $minimum) {
            return [
                'valid' => false,
                'errors' => [$path . ' 的允许范围无效 / has an invalid range'],
                'value' => null
            ];
        }

        if (self::isFiniteNumber($descriptor)) {
            $value = (float) $descriptor;
            if ($value < $minimum || $value > $maximum) {
                $errors[] = self::rangeError($path, $minimum, $maximum);
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'value' => empty($errors) ? $value : null
            ];
        }

        if (!is_array($descriptor)
            || !isset($descriptor['mode'])
            || !is_string($descriptor['mode'])) {
            return [
                'valid' => false,
                'errors' => [
                    $path . ' 必须是有限数值或曲线描述符'
                    . ' / must be a finite number or curve descriptor'
                ],
                'value' => null
            ];
        }

        $mode = trim($descriptor['mode']);
        $allowedModes = array_keys(SkillMechanismRegistry::valueModes());
        if (!in_array($mode, $allowedModes, true)) {
            return [
                'valid' => false,
                'errors' => [
                    $path . ' 使用了未知数值模式 / uses an unknown value mode'
                ],
                'value' => null
            ];
        }

        if ($mode === 'fixed') {
            $allowedKeys = ['mode', 'value'];
            self::rejectUnknownKeys(
                $descriptor,
                $allowedKeys,
                $path,
                $errors
            );
            if (!array_key_exists('value', $descriptor)
                || !self::isFiniteNumber($descriptor['value'])) {
                $errors[] = $path
                    . '.value 必须是有限数值 / must be a finite number';
            } else {
                $fixedValue = (float) $descriptor['value'];
                if ($fixedValue < $minimum || $fixedValue > $maximum) {
                    $errors[] = self::rangeError(
                        $path . '.value',
                        $minimum,
                        $maximum
                    );
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'value' => empty($errors)
                    ? ['mode' => 'fixed', 'value' => (float) $descriptor['value']]
                    : null
            ];
        }

        $allowedKeys = ['mode', 'values'];
        if ($mode === 'stat_level_values') {
            $allowedKeys[] = 'stat';
        }
        self::rejectUnknownKeys($descriptor, $allowedKeys, $path, $errors);

        if (!isset($descriptor['values'])
            || !is_array($descriptor['values'])) {
            $errors[] = $path
                . '.values 必须是数组 / must be an array';
            return [
                'valid' => false,
                'errors' => $errors,
                'value' => null
            ];
        }

        $curveLength = count($descriptor['values']);
        if ($curveLength !== $maxLevel) {
            $errors[] = $path
                . '.values 项数必须与技能最高等级相同'
                . ' / must contain exactly max_level entries';
        }
        if ($curveLength > self::MAX_CURVE_LENGTH) {
            $errors[] = $path
                . '.values 超过100级上限 / exceeds the 100-level limit';
        }

        if ($mode === 'stat_level_values') {
            $allowedStats = ['attack', 'defense', 'speed', 'intelligence'];
            if (!isset($descriptor['stat'])
                || !is_string($descriptor['stat'])
                || !in_array($descriptor['stat'], $allowedStats, true)) {
                $errors[] = $path
                    . '.stat 必须是有效武将属性 / must be a valid general stat';
            }
        }

        $normalizedValues = [];
        foreach ($descriptor['values'] as $index => $curveValue) {
            $curvePath = $path . '.values[' . $index . ']';
            if ($mode === 'cost_plus_intelligence_level_values') {
                $normalizedAffine = self::validateAffineValue(
                    $curveValue,
                    $minimum,
                    $maximum,
                    $curvePath,
                    $errors
                );
                if ($normalizedAffine !== null) {
                    $normalizedValues[] = $normalizedAffine;
                }
                continue;
            }

            if (!self::isFiniteNumber($curveValue)) {
                $errors[] = $curvePath
                    . ' 必须是有限数值 / must be a finite number';
                continue;
            }

            $numericValue = (float) $curveValue;
            if ($numericValue < $minimum || $numericValue > $maximum) {
                $errors[] = self::rangeError(
                    $curvePath,
                    $minimum,
                    $maximum
                );
                continue;
            }
            $normalizedValues[] = $numericValue;
        }

        $normalized = [
            'mode' => $mode,
            'values' => $normalizedValues
        ];
        if ($mode === 'stat_level_values'
            && isset($descriptor['stat'])
            && is_string($descriptor['stat'])) {
            $normalized['stat'] = $descriptor['stat'];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'value' => empty($errors) ? $normalized : null
        ];
    }

    /**
     * 按当前上下文解析数值 / Resolves a value for the current context
     *
     * @param mixed $descriptor 数值描述符 / Value descriptor
     * @param array $context 求值上下文 / Evaluation context
     * @return float|null 解析值或空 / Resolved value or null
     */
    public static function resolve($descriptor, array $context) {
        if (self::isFiniteNumber($descriptor)) {
            return (float) $descriptor;
        }
        if (!is_array($descriptor)
            || !isset($descriptor['mode'])
            || !is_string($descriptor['mode'])) {
            return null;
        }

        $mode = $descriptor['mode'];
        if ($mode === 'fixed') {
            return isset($descriptor['value'])
                && self::isFiniteNumber($descriptor['value'])
                ? (float) $descriptor['value']
                : null;
        }
        if (!isset($descriptor['values'])
            || !is_array($descriptor['values'])) {
            return null;
        }

        $level = isset($context['skill_level'])
            ? max(1, (int) $context['skill_level'])
            : 1;
        $levelIndex = $level - 1;
        if (!array_key_exists($levelIndex, $descriptor['values'])) {
            return null;
        }

        $curveValue = $descriptor['values'][$levelIndex];
        if ($mode === 'cost_plus_intelligence_level_values') {
            if (!is_array($curveValue)
                || !isset($curveValue['cost'], $curveValue['intelligence'])
                || !self::isFiniteNumber($curveValue['cost'])
                || !self::isFiniteNumber($curveValue['intelligence'])) {
                return null;
            }

            $cost = self::contextNumber($context, 'general_cost');
            $intelligence = self::contextNumber(
                $context,
                'general_intelligence'
            );
            if ($cost === null || $intelligence === null) {
                return null;
            }
            $constant = isset($curveValue['constant'])
                && self::isFiniteNumber($curveValue['constant'])
                ? (float) $curveValue['constant']
                : 0.0;
            $resolved = $cost * (float) $curveValue['cost']
                + $intelligence * (float) $curveValue['intelligence']
                + $constant;
            return is_finite($resolved) ? round($resolved, 6) : null;
        }

        if (!self::isFiniteNumber($curveValue)) {
            return null;
        }
        $resolved = (float) $curveValue;

        switch ($mode) {
            case 'level_values':
                break;
            case 'cost_level_values':
                $cost = self::contextNumber($context, 'general_cost');
                if ($cost === null) {
                    return null;
                }
                $resolved *= $cost;
                break;
            case 'intelligence_level_values':
                $intelligence = self::contextNumber(
                    $context,
                    'general_intelligence'
                );
                if ($intelligence === null) {
                    return null;
                }
                $resolved *= $intelligence;
                break;
            case 'cost_intelligence_level_values':
                $cost = self::contextNumber($context, 'general_cost');
                $intelligence = self::contextNumber(
                    $context,
                    'general_intelligence'
                );
                if ($cost === null || $intelligence === null) {
                    return null;
                }
                $resolved *= $cost * $intelligence;
                break;
            case 'stat_level_values':
                if (!isset($descriptor['stat'])
                    || !is_string($descriptor['stat'])) {
                    return null;
                }
                $statValue = self::resolveGeneralStat(
                    $descriptor['stat'],
                    $context
                );
                if ($statValue === null) {
                    return null;
                }
                $resolved *= $statValue;
                break;
            default:
                return null;
        }

        return is_finite($resolved) ? round($resolved, 6) : null;
    }

    /**
     * 判断值是否为有限原生数值 / Checks whether a value is a finite native number
     *
     * @param mixed $value 待检查值 / Value to inspect
     * @return bool 是否有效 / Whether valid
     */
    public static function isFiniteNumber($value) {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value);
    }

    /**
     * 校验COST项、智力项与常数项 / Validates cost, intelligence, and constant terms
     *
     * @param mixed $value 仿射项 / Affine terms
     * @param float $minimum 最小值 / Minimum
     * @param float $maximum 最大值 / Maximum
     * @param string $path 错误路径 / Error path
     * @param array $errors 错误列表 / Error list
     * @return array|null 标准化仿射项 / Normalized affine terms
     */
    private static function validateAffineValue(
        $value,
        $minimum,
        $maximum,
        $path,
        array &$errors
    ) {
        if (!is_array($value)) {
            $errors[] = $path
                . ' 必须包含cost与intelligence项 / must contain cost and intelligence terms';
            return null;
        }

        self::rejectUnknownKeys(
            $value,
            ['cost', 'intelligence', 'constant'],
            $path,
            $errors
        );
        $normalized = [];
        foreach (['cost', 'intelligence'] as $term) {
            if (!array_key_exists($term, $value)
                || !self::isFiniteNumber($value[$term])) {
                $errors[] = $path . '.' . $term
                    . ' 必须是有限数值 / must be a finite number';
                continue;
            }
            $termValue = (float) $value[$term];
            if ($termValue < $minimum || $termValue > $maximum) {
                $errors[] = self::rangeError(
                    $path . '.' . $term,
                    $minimum,
                    $maximum
                );
                continue;
            }
            $normalized[$term] = $termValue;
        }

        if (array_key_exists('constant', $value)) {
            if (!self::isFiniteNumber($value['constant'])) {
                $errors[] = $path
                    . '.constant 必须是有限数值 / must be a finite number';
            } else {
                $constant = (float) $value['constant'];
                if ($constant < $minimum || $constant > $maximum) {
                    $errors[] = self::rangeError(
                        $path . '.constant',
                        $minimum,
                        $maximum
                    );
                } else {
                    $normalized['constant'] = $constant;
                }
            }
        }

        return isset($normalized['cost'], $normalized['intelligence'])
            ? $normalized
            : null;
    }

    /**
     * 获取安全上下文数值 / Gets a safe numeric context value
     *
     * @param array $context 上下文 / Context
     * @param string $key 键 / Key
     * @return float|null 数值或空 / Value or null
     */
    private static function contextNumber(array $context, $key) {
        if (!isset($context[$key])
            || !is_numeric($context[$key])
            || !is_finite((float) $context[$key])
            || (float) $context[$key] < 0.0) {
            return null;
        }

        return (float) $context[$key];
    }

    /**
     * 获取武将属性上下文 / Gets a general-stat context value
     *
     * @param string $stat 属性名 / Stat name
     * @param array $context 上下文 / Context
     * @return float|null 数值或空 / Value or null
     */
    private static function resolveGeneralStat($stat, array $context) {
        if ($stat === 'intelligence') {
            return self::contextNumber($context, 'general_intelligence');
        }
        if (!isset($context['general_stats'])
            || !is_array($context['general_stats'])
            || !isset($context['general_stats'][$stat])
            || !is_numeric($context['general_stats'][$stat])
            || !is_finite((float) $context['general_stats'][$stat])
            || (float) $context['general_stats'][$stat] < 0.0) {
            return null;
        }

        return (float) $context['general_stats'][$stat];
    }

    /**
     * 拒绝描述符中的未知键 / Rejects unknown descriptor keys
     *
     * @param array $value 输入数组 / Input array
     * @param array $allowedKeys 允许键 / Allowed keys
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
     * 构建范围错误 / Builds a range error
     *
     * @param string $path 错误路径 / Error path
     * @param float $minimum 最小值 / Minimum
     * @param float $maximum 最大值 / Maximum
     * @return string 错误信息 / Error message
     */
    private static function rangeError($path, $minimum, $maximum) {
        return $path . ' 必须介于 ' . $minimum . ' 与 ' . $maximum
            . ' / must be between ' . $minimum . ' and ' . $maximum;
    }
}

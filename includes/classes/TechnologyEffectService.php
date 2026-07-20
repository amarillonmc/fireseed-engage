<?php
// 种火集结号 - 科技效果服务 / Fireseed Engage - Technology effect service

class TechnologyEffectService {
    const SCOPE_SEASONAL = 'seasonal';
    const SCOPE_PERMANENT = 'permanent';

    private static $userEffectCache = [];

    /**
     * 校验科技消耗是否符合持久化边界 / Validate a technology cost against its persistence boundary
     * @param string $scope 科技范围 / Technology scope
     * @param array $cost 资源消耗 / Resource cost
     * @return bool 是否有效 / Whether the cost is valid
     */
    public static function isCostPolicyValid($scope, array $cost) {
        $allowedResources = $scope === self::SCOPE_SEASONAL
            ? ['warm', 'cold', 'green', 'day']
            : ($scope === self::SCOPE_PERMANENT
                ? ['bright', 'night']
                : []);
        if (empty($allowedResources) || empty($cost)) {
            return false;
        }

        foreach ($cost as $resourceType => $amount) {
            if (!in_array($resourceType, $allowedResources, true)
                || !is_numeric($amount)
                || (int) $amount < 0) {
                return false;
            }
        }

        return array_sum(array_map('intval', $cost)) > 0;
    }

    /**
     * 计算科技在指定等级的累计效果 / Calculate a technology's cumulative effect at a level
     * @param float $baseEffect 每级基础效果 / Base effect per level
     * @param int $level 当前等级 / Current level
     * @return float 累计效果 / Cumulative effect
     */
    public static function calculateEffectAtLevel($baseEffect, $level) {
        if (!is_numeric($baseEffect) || !is_finite((float) $baseEffect)) {
            return 0.0;
        }

        return max(0.0, (float) $baseEffect) * max(0, (int) $level);
    }

    /**
     * 应用小数形式的百分比加成 / Apply a fractional percentage bonus
     * @param int|float $baseValue 基础值 / Base value
     * @param float $bonusFraction 加成小数 / Bonus fraction
     * @return float 调整后的值 / Adjusted value
     */
    public static function applyFractionalBonus($baseValue, $bonusFraction) {
        $baseValue = is_numeric($baseValue) ? (float) $baseValue : 0.0;
        $bonusFraction = is_numeric($bonusFraction)
            ? max(0.0, min(100.0, (float) $bonusFraction))
            : 0.0;
        return $baseValue * (1 + $bonusFraction);
    }

    /**
     * 按科技加速缩短耗时 / Shorten a duration using a technology speed bonus
     * @param int|float $baseSeconds 基础秒数 / Base duration in seconds
     * @param float $bonusFraction 加速小数 / Speed bonus fraction
     * @return int 调整后的秒数 / Adjusted duration
     */
    public static function applySpeedBonusToDuration($baseSeconds, $bonusFraction) {
        if (!is_numeric($baseSeconds)
            || !is_finite((float) $baseSeconds)
            || (float) $baseSeconds <= 0) {
            return 0;
        }

        $bonusFraction = is_numeric($bonusFraction)
            ? max(0.0, min(100.0, (float) $bonusFraction))
            : 0.0;
        $adjustedSeconds = (float) $baseSeconds / (1 + $bonusFraction);
        return max(1, (int) ceil($adjustedSeconds - 0.000000001));
    }

    /**
     * 从基础值和科研加成计算整数上限 / Calculate an integer cap from a base value and research bonus
     * @param int $baseValue 基础上限 / Base cap
     * @param float $bonus 科研加成 / Research bonus
     * @return int 最终上限 / Final cap
     */
    public static function calculateIntegerLimit($baseValue, $bonus) {
        return max(0, (int) $baseValue + (int) floor(max(0.0, (float) $bonus)));
    }

    /**
     * 从基础值和科研加成计算小数上限 / Calculate a decimal cap from a base value and research bonus
     * @param float $baseValue 基础上限 / Base cap
     * @param float $bonus 科研加成 / Research bonus
     * @return float 最终上限 / Final cap
     */
    public static function calculateDecimalLimit($baseValue, $bonus) {
        return max(0.0, (float) $baseValue + max(0.0, (float) $bonus));
    }

    /**
     * 获取玩家已完成科研的全部效果 / Get all completed technology effects for a player
     * @param int $userId 玩家ID / User ID
     * @return array<string,float> 按效果键汇总的效果 / Effects grouped by key
     */
    public static function getUserEffects($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return [];
        }
        if (isset(self::$userEffectCache[$userId])) {
            return self::$userEffectCache[$userId];
        }

        $db = Database::getInstance()->getConnection();
        $query = "SELECT t.effect_key, t.base_effect, ut.level
                  FROM user_technologies ut
                  INNER JOIN technologies t ON t.tech_id = ut.tech_id
                  WHERE ut.user_id = ? AND ut.level > 0
                    AND t.effect_key <> ''";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $effects = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $effectKey = (string) $row['effect_key'];
            $effectValue = self::calculateEffectAtLevel(
                $row['base_effect'],
                $row['level']
            );
            $effects[$effectKey] = ($effects[$effectKey] ?? 0.0) + $effectValue;
        }
        $stmt->close();

        self::$userEffectCache[$userId] = $effects;
        return $effects;
    }

    /**
     * 获取玩家指定科研效果 / Get one technology effect for a player
     * @param int $userId 玩家ID / User ID
     * @param string $effectKey 效果键 / Effect key
     * @return float 效果值 / Effect value
     */
    public static function getUserEffect($userId, $effectKey) {
        $effects = self::getUserEffects($userId);
        return isset($effects[$effectKey]) ? (float) $effects[$effectKey] : 0.0;
    }

    /**
     * 清除玩家的请求内科研缓存 / Clear a player's request-local technology cache
     * @param int $userId 玩家ID / User ID
     * @return void
     */
    public static function clearUserCache($userId) {
        unset(self::$userEffectCache[(int) $userId]);
    }

    /**
     * 获取由永久科研决定的三项玩家上限 / Get the three caps derived from permanent research
     * @param int $userId 玩家ID / User ID
     * @return array 上限集合 / Derived caps
     */
    public static function getDerivedPlayerLimits($userId) {
        $baseCircuit = max(
            1,
            (int) GameConfig::get('initial_max_circuit_points', 10)
        );
        $baseGeneralCost = max(
            0.0,
            (float) GameConfig::get('initial_max_general_cost', 10.0)
        );
        $baseSubBases = max(
            0,
            (int) GameConfig::get('initial_subbase_limit', 1)
        );

        return [
            'max_circuit_points' => self::calculateIntegerLimit(
                $baseCircuit,
                self::getUserEffect($userId, 'circuit_capacity')
            ),
            'max_general_cost' => self::calculateDecimalLimit(
                $baseGeneralCost,
                self::getUserEffect($userId, 'general_cost_capacity')
            ),
            'max_subbases' => self::calculateIntegerLimit(
                $baseSubBases,
                self::getUserEffect($userId, 'subbase_capacity')
            )
        ];
    }

    /**
     * 将物化上限同步到兼容字段 / Synchronize materialized caps to compatibility columns
     * @param int $userId 玩家ID / User ID
     * @return bool 是否成功 / Whether synchronization succeeded
     */
    public static function synchronizePlayerLimits($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }

        self::clearUserCache($userId);
        $limits = self::getDerivedPlayerLimits($userId);
        $db = Database::getInstance()->getConnection();
        $query = "UPDATE users
                  SET max_circuit_points = ?, max_general_cost = ?
                  WHERE user_id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'idi',
            $limits['max_circuit_points'],
            $limits['max_general_cost'],
            $userId
        );
        $success = $stmt->execute() && $stmt->affected_rows <= 1;
        $stmt->close();
        return $success;
    }

    /**
     * 为全部玩家重新物化永久科研上限 / Rematerialize permanent-research caps for every player
     *
     * @return bool 是否全部成功 / Whether every player synchronized
     */
    public static function synchronizeAllPlayerLimits() {
        $db = Database::getInstance()->getConnection();
        if (!$db->begin_transaction()) {
            return false;
        }

        try {
            if (!self::synchronizeAllPlayerLimitsInCurrentTransaction()) {
                throw new RuntimeException(
                    '同步玩家成长上限失败 / Failed to synchronize player limits'
                );
            }
            if (!$db->commit()) {
                throw new RuntimeException(
                    '提交玩家成长上限失败 / Failed to commit player limits'
                );
            }
            return true;
        } catch (Throwable $exception) {
            $db->rollback();
            error_log(
                'Player-limit synchronization failed: '
                . $exception->getMessage()
            );
            return false;
        }
    }

    /**
     * 在调用者事务中为全部玩家重新物化上限 / Rematerialize every player's caps in the caller's transaction
     *
     * 先锁定玩家行可防止旧科研快照覆盖刚完成的永久科研；调用者负责提交或回滚。
     * Locking player rows first prevents a stale research snapshot from
     * overwriting a just-completed permanent technology. The caller owns
     * commit and rollback.
     *
     * @return bool 是否全部成功 / Whether every player synchronized
     */
    public static function synchronizeAllPlayerLimitsInCurrentTransaction() {
        $db = Database::getInstance()->getConnection();
        $query = "SELECT user_id
                  FROM users
                  ORDER BY user_id
                  FOR UPDATE";
        $stmt = $db->prepare($query);
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            return false;
        }

        $result = $stmt->get_result();
        $userIds = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $userIds[] = (int) $row['user_id'];
        }
        $stmt->close();

        foreach ($userIds as $userId) {
            if (!self::synchronizePlayerLimits($userId)) {
                return false;
            }
        }
        return true;
    }
}

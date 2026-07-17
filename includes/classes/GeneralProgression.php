<?php
// 种火集结号 - 武将成长与BREAK服务 / Fireseed Engage - General progression and BREAK service

/**
 * 管理武将成长记录、等级上限、BREAK与离线HP回复 / Manages general progression records, level caps, BREAK, and offline HP recovery
 */
class GeneralProgression {
    private const INITIAL_LEVEL_CAP = 20;
    private const LEVELS_PER_BREAK = 20;
    private const BASE_HP_RECOVERY_PER_HOUR = 5.0;

    private $db;

    /**
     * 创建武将成长服务 / Creates the general-progression service
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 确保武将拥有成长记录 / Ensures that a general has a progression record
     *
     * @param int $generalId 武将ID / General ID
     * @return bool 成长记录是否存在 / Whether the progression record exists
     */
    public function ensure($generalId): bool {
        $normalizedGeneralId = (int) $generalId;

        if ($normalizedGeneralId <= 0) {
            return false;
        }

        try {
            $query = "INSERT IGNORE INTO general_progression (general_id)
                      SELECT general_id
                      FROM generals
                      WHERE general_id = ?";
            $stmt = $this->db->prepare($query);

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('i', $normalizedGeneralId);

            if (!$stmt->execute()) {
                $stmt->close();
                return false;
            }

            $stmt->close();
            $check = "SELECT general_id
                      FROM general_progression
                      WHERE general_id = ?";
            $stmt = $this->db->prepare($check);

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('i', $normalizedGeneralId);

            if (!$stmt->execute()) {
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result();
            $exists = $result && $result->num_rows > 0;
            $stmt->close();

            return $exists;
        } catch (Throwable $e) {
            error_log('GeneralProgression::ensure failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取武将成长状态与当前等级上限 / Gets a general's progression state and current level cap
     *
     * @param int $generalId 武将ID / General ID
     * @return array 结构化成长结果 / Structured progression result
     */
    public function get($generalId): array {
        $normalizedGeneralId = (int) $generalId;

        if ($normalizedGeneralId <= 0) {
            return $this->result(false, '武将无效 / Invalid general');
        }

        if (!$this->ensure($normalizedGeneralId)) {
            return $this->result(false, '武将不存在 / General does not exist');
        }

        try {
            $query = "SELECT g.general_id, g.owner_id, g.name, g.rarity,
                             g.level, g.hp, g.max_hp, g.attack, g.defense,
                             g.speed, g.intelligence,
                             gp.break_level, gp.experience,
                             gp.skill_points_spent, gp.last_hp_recovery,
                             gp.updated_at
                      FROM generals g
                      JOIN general_progression gp
                        ON gp.general_id = g.general_id
                      WHERE g.general_id = ?";
            $stmt = $this->db->prepare($query);

            if (!$stmt) {
                throw new RuntimeException('无法读取武将成长 / Unable to read general progression');
            }

            $stmt->bind_param('i', $normalizedGeneralId);
            $this->executeOrFail(
                $stmt,
                '无法读取武将成长 / Unable to read general progression'
            );
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$row) {
                return $this->result(false, '武将不存在 / General does not exist');
            }

            return $this->result(
                true,
                '武将成长读取成功 / General progression loaded',
                $this->buildProgressionData($row)
            );
        } catch (Throwable $e) {
            error_log('GeneralProgression::get failed: ' . $e->getMessage());

            return $this->result(
                false,
                '武将成长读取失败 / Failed to load general progression'
            );
        }
    }

    /**
     * 判断武将当前是否满足BREAK条件 / Determines whether a general currently meets BREAK requirements
     *
     * @param int $generalId 武将ID / General ID
     * @return bool 是否可BREAK / Whether BREAK is available
     */
    public function canBreak($generalId): bool {
        $result = $this->get($generalId);

        return !empty($result['success'])
            && !empty($result['progression']['can_break']);
    }

    /**
     * 消耗亮晶晶与蜕变核心完成一次BREAK / Completes one BREAK by consuming bright crystals and break cores
     *
     * @param int $userId 玩家ID / User ID
     * @param int $generalId 武将ID / General ID
     * @return array 结构化BREAK结果 / Structured BREAK result
     */
    public function breakGeneral($userId, $generalId): array {
        $normalizedUserId = (int) $userId;
        $normalizedGeneralId = (int) $generalId;

        if ($normalizedUserId <= 0 || $normalizedGeneralId <= 0) {
            return $this->result(false, 'BREAK参数无效 / Invalid BREAK parameters');
        }

        $transactionStarted = false;

        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockUser($normalizedUserId);
            $general = $this->lockOwnedGeneral(
                $normalizedUserId,
                $normalizedGeneralId
            );
            $this->ensureLocked($normalizedGeneralId);
            $progression = $this->getProgressionLocked($normalizedGeneralId);
            $breakLevel = (int) $progression['break_level'];
            $finalLevelCap = GameRules::getBreakLevelCap(
                (string) $general['rarity']
            );
            $currentLevelCap = $this->getCurrentLevelCap(
                $finalLevelCap,
                $breakLevel
            );

            if ((int) $general['level'] < $currentLevelCap) {
                throw new DomainException(
                    '武将必须达到当前等级上限后才能BREAK / General must reach the current level cap before BREAK'
                );
            }

            if ($currentLevelCap >= $finalLevelCap) {
                throw new DomainException(
                    '武将已达到最终等级上限 / General has reached the final level cap'
                );
            }

            $nextBreakLevel = $breakLevel + 1;
            $baseCost = GameRules::getBreakCost((string) $general['rarity']);
            $brightCost = (int) $baseCost['bright_crystal'] * $nextBreakLevel;
            $coreCost = (int) $baseCost['break_material'] * $nextBreakLevel;
            $this->consumeBreakCost(
                $normalizedUserId,
                $brightCost,
                $coreCost
            );
            $newMaxHp = $this->increaseByTenPercent(
                (int) $general['max_hp']
            );
            $newAttack = $this->increaseByTenPercent(
                (int) $general['attack']
            );
            $newDefense = $this->increaseByTenPercent(
                (int) $general['defense']
            );
            $newSpeed = $this->increaseByTenPercent(
                (int) $general['speed']
            );
            $newIntelligence = $this->increaseByTenPercent(
                (int) $general['intelligence']
            );
            $generalUpdate = "UPDATE generals
                              SET hp = ?, max_hp = ?, attack = ?, defense = ?,
                                  speed = ?, intelligence = ?
                              WHERE general_id = ?";
            $stmt = $this->db->prepare($generalUpdate);

            if (!$stmt) {
                throw new RuntimeException('无法更新BREAK属性 / Unable to update BREAK attributes');
            }

            $stmt->bind_param(
                'iiiiiii',
                $newMaxHp,
                $newMaxHp,
                $newAttack,
                $newDefense,
                $newSpeed,
                $newIntelligence,
                $normalizedGeneralId
            );
            $this->executeOrFail(
                $stmt,
                '无法更新BREAK属性 / Unable to update BREAK attributes'
            );
            $stmt->close();
            $progressionUpdate = "UPDATE general_progression
                                  SET break_level = ?,
                                      last_hp_recovery = NOW()
                                  WHERE general_id = ?";
            $stmt = $this->db->prepare($progressionUpdate);

            if (!$stmt) {
                throw new RuntimeException('无法更新BREAK等级 / Unable to update BREAK level');
            }

            $stmt->bind_param(
                'ii',
                $nextBreakLevel,
                $normalizedGeneralId
            );
            $this->executeOrFail(
                $stmt,
                '无法更新BREAK等级 / Unable to update BREAK level'
            );
            $stmt->close();
            $this->recordGameplayEvent(
                $normalizedUserId,
                'general_broken',
                1,
                'general',
                $normalizedGeneralId
            );
            $newLevelCap = $this->getCurrentLevelCap(
                $finalLevelCap,
                $nextBreakLevel
            );
            $this->commitTransaction();
            $transactionStarted = false;

            return $this->result(
                true,
                '武将BREAK成功 / General BREAK successful',
                [
                    'general_id' => $normalizedGeneralId,
                    'break_level' => $nextBreakLevel,
                    'level' => (int) $general['level'],
                    'current_level_cap' => $newLevelCap,
                    'final_level_cap' => $finalLevelCap,
                    'hp' => $newMaxHp,
                    'max_hp' => $newMaxHp,
                    'attack' => $newAttack,
                    'defense' => $newDefense,
                    'speed' => $newSpeed,
                    'intelligence' => $newIntelligence,
                    'can_break' => (int) $general['level'] >= $newLevelCap
                        && $newLevelCap < $finalLevelCap,
                    'cost' => [
                        'bright' => $brightCost,
                        'items' => ['break_core' => $coreCost]
                    ]
                ]
            );
        } catch (DomainException $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            return $this->result(false, $e->getMessage());
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            error_log('GeneralProgression::breakGeneral failed: ' . $e->getMessage());

            return $this->result(
                false,
                '武将BREAK失败，未消耗材料 / General BREAK failed and no materials were consumed'
            );
        }
    }

    /**
     * 为玩家全部武将结算离线HP回复 / Settles offline HP recovery for all of a player's generals
     *
     * @param int $userId 玩家ID / User ID
     * @return array 结构化HP回复结果 / Structured HP-recovery result
     */
    public function recoverAllHp($userId): array {
        $normalizedUserId = (int) $userId;

        if ($normalizedUserId <= 0) {
            return $this->result(false, '玩家无效 / Invalid user');
        }

        $transactionStarted = false;

        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockUser($normalizedUserId);
            $this->ensureAllUserProgressionLocked($normalizedUserId);
            $nowTimestamp = $this->getDatabaseTimestamp();
            $modifier = $this->getHpRecoveryModifier();
            $ratePerSecond = self::BASE_HP_RECOVERY_PER_HOUR
                * $modifier
                / 3600.0;
            $query = "SELECT g.general_id, g.hp, g.max_hp,
                             UNIX_TIMESTAMP(gp.last_hp_recovery) AS recovery_timestamp
                      FROM generals g
                      JOIN general_progression gp
                        ON gp.general_id = g.general_id
                      WHERE g.owner_id = ? AND g.is_active = 1
                      ORDER BY g.general_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);

            if (!$stmt) {
                throw new RuntimeException('无法读取武将HP / Unable to read general HP');
            }

            $stmt->bind_param('i', $normalizedUserId);
            $this->executeOrFail(
                $stmt,
                '无法读取武将HP / Unable to read general HP'
            );
            $result = $stmt->get_result();
            $generals = [];

            while ($result && ($row = $result->fetch_assoc())) {
                $generals[] = $row;
            }

            $stmt->close();
            $recoveredGenerals = [];
            $totalRecovered = 0;

            foreach ($generals as $general) {
                $generalId = (int) $general['general_id'];
                $maxHp = max(1, (int) $general['max_hp']);
                $currentHp = min($maxHp, max(0, (int) $general['hp']));
                $lastTimestamp = $general['recovery_timestamp'] === null
                    ? $nowTimestamp
                    : (int) $general['recovery_timestamp'];
                $newHp = $currentHp;
                $newRecoveryTimestamp = null;
                $actualRecovery = 0;

                if ($currentHp >= $maxHp
                    || $ratePerSecond <= 0.0
                    || $lastTimestamp <= 0
                    || $lastTimestamp > $nowTimestamp) {
                    $newRecoveryTimestamp = $nowTimestamp;
                } else {
                    $elapsedSeconds = $nowTimestamp - $lastTimestamp;
                    $potentialRecovery = (int) floor(
                        $elapsedSeconds * $ratePerSecond + 0.000000001
                    );

                    if ($potentialRecovery > 0) {
                        $missingHp = $maxHp - $currentHp;
                        $actualRecovery = min(
                            $missingHp,
                            $potentialRecovery
                        );
                        $newHp = $currentHp + $actualRecovery;

                        if ($newHp >= $maxHp) {
                            $newRecoveryTimestamp = $nowTimestamp;
                        } else {
                            $consumedSeconds = max(
                                1,
                                (int) floor(
                                    $potentialRecovery / $ratePerSecond
                                )
                            );
                            $newRecoveryTimestamp = min(
                                $nowTimestamp,
                                $lastTimestamp + $consumedSeconds
                            );
                        }
                    }
                }

                if ($newRecoveryTimestamp !== null) {
                    $this->updateHpRecoveryLocked(
                        $generalId,
                        $newHp,
                        $newRecoveryTimestamp
                    );
                }

                if ($actualRecovery > 0) {
                    $totalRecovered += $actualRecovery;
                    $recoveredGenerals[] = [
                        'general_id' => $generalId,
                        'recovered_hp' => $actualRecovery,
                        'hp' => $newHp,
                        'max_hp' => $maxHp,
                        'last_hp_recovery' => date(
                            'Y-m-d H:i:s',
                            $newRecoveryTimestamp
                        )
                    ];
                }
            }

            $this->commitTransaction();
            $transactionStarted = false;

            return $this->result(
                true,
                '武将HP回复结算完成 / General HP recovery settled',
                [
                    'total_recovered_hp' => $totalRecovered,
                    'recovered_count' => count($recoveredGenerals),
                    'modifier' => $modifier,
                    'generals' => $recoveredGenerals
                ]
            );
        } catch (DomainException $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            return $this->result(false, $e->getMessage());
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            error_log('GeneralProgression::recoverAllHp failed: ' . $e->getMessage());

            return $this->result(
                false,
                '武将HP回复结算失败 / Failed to settle general HP recovery'
            );
        }
    }

    /**
     * 开始事务并验证结果 / Starts a transaction and validates the result
     */
    private function beginTransaction(): void {
        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('无法开始事务 / Unable to start transaction');
        }
    }

    /**
     * 提交事务并验证结果 / Commits a transaction and validates the result
     */
    private function commitTransaction(): void {
        if (!$this->db->commit()) {
            throw new RuntimeException('无法提交事务 / Unable to commit transaction');
        }
    }

    /**
     * 锁定并验证玩家 / Locks and validates a user
     *
     * @param int $userId 玩家ID / User ID
     */
    private function lockUser($userId): void {
        $query = "SELECT user_id FROM users WHERE user_id = ? FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法锁定玩家 / Unable to lock user');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail($stmt, '无法锁定玩家 / Unable to lock user');
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            throw new DomainException('玩家不存在 / User does not exist');
        }
    }

    /**
     * 锁定并读取玩家拥有的武将 / Locks and reads a general owned by the user
     *
     * @param int $userId 玩家ID / User ID
     * @param int $generalId 武将ID / General ID
     * @return array 武将行 / General row
     */
    private function lockOwnedGeneral($userId, $generalId): array {
        $query = "SELECT general_id, owner_id, name, rarity, level, hp, max_hp,
                         attack, defense, speed, intelligence
                  FROM generals
                  WHERE general_id = ? AND owner_id = ? AND is_active = 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法锁定武将 / Unable to lock general');
        }

        $stmt->bind_param('ii', $generalId, $userId);
        $this->executeOrFail($stmt, '无法锁定武将 / Unable to lock general');
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new DomainException('武将不存在或不属于玩家 / General does not exist or is not owned');
        }

        return $row;
    }

    /**
     * 在事务内确保单个成长记录存在 / Ensures one progression record exists inside a transaction
     *
     * @param int $generalId 武将ID / General ID
     */
    private function ensureLocked($generalId): void {
        $query = "INSERT IGNORE INTO general_progression (general_id)
                  VALUES (?)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法初始化武将成长 / Unable to initialize general progression');
        }

        $stmt->bind_param('i', $generalId);
        $this->executeOrFail(
            $stmt,
            '无法初始化武将成长 / Unable to initialize general progression'
        );
        $stmt->close();
    }

    /**
     * 在事务内确保玩家全部武将拥有成长记录 / Ensures all user generals have progression records inside a transaction
     *
     * @param int $userId 玩家ID / User ID
     */
    private function ensureAllUserProgressionLocked($userId): void {
        $query = "INSERT IGNORE INTO general_progression (general_id)
                  SELECT general_id
                  FROM generals
                  WHERE owner_id = ?";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法初始化武将成长 / Unable to initialize general progression');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail(
            $stmt,
            '无法初始化武将成长 / Unable to initialize general progression'
        );
        $stmt->close();
    }

    /**
     * 锁定并读取成长记录 / Locks and reads a progression record
     *
     * @param int $generalId 武将ID / General ID
     * @return array 成长记录 / Progression row
     */
    private function getProgressionLocked($generalId): array {
        $query = "SELECT general_id, break_level, experience,
                         skill_points_spent, last_hp_recovery
                  FROM general_progression
                  WHERE general_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法锁定武将成长 / Unable to lock general progression');
        }

        $stmt->bind_param('i', $generalId);
        $this->executeOrFail(
            $stmt,
            '无法锁定武将成长 / Unable to lock general progression'
        );
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('武将成长记录不存在 / General progression record does not exist');
        }

        return $row;
    }

    /**
     * 锁定并扣除BREAK资源与材料 / Locks and consumes BREAK resources and materials
     *
     * @param int $userId 玩家ID / User ID
     * @param int $brightCost 亮晶晶费用 / Bright-crystal cost
     * @param int $coreCost 蜕变核心费用 / Break-core cost
     */
    private function consumeBreakCost(
        $userId,
        $brightCost,
        $coreCost
    ): void {
        $resourceQuery = "SELECT bright_crystal
                          FROM resources
                          WHERE user_id = ?
                          FOR UPDATE";
        $stmt = $this->db->prepare($resourceQuery);

        if (!$stmt) {
            throw new RuntimeException('无法读取亮晶晶 / Unable to read bright crystals');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail(
            $stmt,
            '无法读取亮晶晶 / Unable to read bright crystals'
        );
        $result = $stmt->get_result();
        $resource = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$resource) {
            throw new DomainException('玩家资源记录不存在 / User resource record does not exist');
        }

        $itemInsert = "INSERT IGNORE INTO user_items
                         (user_id, item_code, quantity)
                       VALUES (?, 'break_core', 0)";
        $stmt = $this->db->prepare($itemInsert);

        if (!$stmt) {
            throw new RuntimeException('无法初始化蜕变核心库存 / Unable to initialize break-core inventory');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail(
            $stmt,
            '无法初始化蜕变核心库存 / Unable to initialize break-core inventory'
        );
        $stmt->close();
        $itemQuery = "SELECT quantity
                      FROM user_items
                      WHERE user_id = ? AND item_code = 'break_core'
                      FOR UPDATE";
        $stmt = $this->db->prepare($itemQuery);

        if (!$stmt) {
            throw new RuntimeException('无法读取蜕变核心 / Unable to read break cores');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail(
            $stmt,
            '无法读取蜕变核心 / Unable to read break cores'
        );
        $result = $stmt->get_result();
        $item = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ((int) $resource['bright_crystal'] < $brightCost) {
            throw new DomainException('亮晶晶不足 / Insufficient bright crystals');
        }

        if (!$item || (int) $item['quantity'] < $coreCost) {
            throw new DomainException('蜕变核心不足 / Insufficient break cores');
        }

        $resourceUpdate = "UPDATE resources
                           SET bright_crystal = bright_crystal - ?
                           WHERE user_id = ?";
        $stmt = $this->db->prepare($resourceUpdate);

        if (!$stmt) {
            throw new RuntimeException('无法扣除亮晶晶 / Unable to consume bright crystals');
        }

        $stmt->bind_param('ii', $brightCost, $userId);
        $this->executeOrFail(
            $stmt,
            '无法扣除亮晶晶 / Unable to consume bright crystals'
        );
        $stmt->close();
        $itemUpdate = "UPDATE user_items
                       SET quantity = quantity - ?
                       WHERE user_id = ?
                         AND item_code = 'break_core'
                         AND quantity >= ?";
        $stmt = $this->db->prepare($itemUpdate);

        if (!$stmt) {
            throw new RuntimeException('无法扣除蜕变核心 / Unable to consume break cores');
        }

        $stmt->bind_param('iii', $coreCost, $userId, $coreCost);
        $this->executeOrFail(
            $stmt,
            '无法扣除蜕变核心 / Unable to consume break cores'
        );
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if ($affectedRows !== 1) {
            throw new DomainException('蜕变核心不足 / Insufficient break cores');
        }
    }

    /**
     * 获取当前等级上限 / Gets the current level cap
     *
     * @param int $finalLevelCap 最终等级上限 / Final level cap
     * @param int $breakLevel BREAK等级 / BREAK level
     * @return int 当前等级上限 / Current level cap
     */
    private function getCurrentLevelCap($finalLevelCap, $breakLevel): int {
        return min(
            max(self::INITIAL_LEVEL_CAP, (int) $finalLevelCap),
            self::INITIAL_LEVEL_CAP
                + max(0, (int) $breakLevel) * self::LEVELS_PER_BREAK
        );
    }

    /**
     * 将整数属性提高百分之十 / Increases an integer attribute by ten percent
     *
     * @param int $value 当前值 / Current value
     * @return int 提升后的值 / Increased value
     */
    private function increaseByTenPercent($value): int {
        $hardCap = defined('GENERAL_ATTRIBUTE_HARD_CAP')
            ? (int) GENERAL_ATTRIBUTE_HARD_CAP
            : 2000000000;
        $increased = round(max(0, (int) $value) * 1.1);

        return min($hardCap, max(0, (int) $increased));
    }

    /**
     * 获取数据库当前Unix时间 / Gets the database's current Unix timestamp
     *
     * @return int 当前Unix时间 / Current Unix timestamp
     */
    private function getDatabaseTimestamp(): int {
        $query = "SELECT UNIX_TIMESTAMP(NOW()) AS current_unix_timestamp";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法读取数据库时间 / Unable to read database time');
        }

        $this->executeOrFail(
            $stmt,
            '无法读取数据库时间 / Unable to read database time'
        );
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row || (int) $row['current_unix_timestamp'] <= 0) {
            throw new RuntimeException('数据库时间无效 / Invalid database time');
        }

        return (int) $row['current_unix_timestamp'];
    }

    /**
     * 获取全局武将HP回复修正 / Gets the global general-HP recovery modifier
     *
     * @return float HP回复修正 / HP-recovery modifier
     */
    private function getHpRecoveryModifier(): float {
        $modifier = isset($GLOBALS['GENERAL_HP_RECOVERY_MODIFIER'])
            && is_numeric($GLOBALS['GENERAL_HP_RECOVERY_MODIFIER'])
            ? (float) $GLOBALS['GENERAL_HP_RECOVERY_MODIFIER']
            : 1.0;

        if (!is_finite($modifier) || $modifier < 0.0) {
            return 1.0;
        }

        return $modifier;
    }

    /**
     * 更新武将HP与已消费的回复时间 / Updates general HP and consumed recovery time
     *
     * @param int $generalId 武将ID / General ID
     * @param int $hp 新HP / New HP
     * @param int $recoveryTimestamp 新回复时间戳 / New recovery timestamp
     */
    private function updateHpRecoveryLocked(
        $generalId,
        $hp,
        $recoveryTimestamp
    ): void {
        $query = "UPDATE generals g
                  JOIN general_progression gp
                    ON gp.general_id = g.general_id
                  SET g.hp = ?,
                      gp.last_hp_recovery = FROM_UNIXTIME(?)
                  WHERE g.general_id = ?";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法更新武将HP回复 / Unable to update general HP recovery');
        }

        $stmt->bind_param(
            'iii',
            $hp,
            $recoveryTimestamp,
            $generalId
        );
        $this->executeOrFail(
            $stmt,
            '无法更新武将HP回复 / Unable to update general HP recovery'
        );
        $stmt->close();
    }

    /**
     * 构造成长数据并计算等级上限与下阶费用 / Builds progression data with level caps and next-BREAK cost
     *
     * @param array $row 数据库行 / Database row
     * @return array 成长数据 / Progression data
     */
    private function buildProgressionData(array $row): array {
        $breakLevel = max(0, (int) $row['break_level']);
        $level = max(1, (int) $row['level']);
        $finalLevelCap = GameRules::getBreakLevelCap((string) $row['rarity']);
        $currentLevelCap = $this->getCurrentLevelCap(
            $finalLevelCap,
            $breakLevel
        );
        $canBreak = $level >= $currentLevelCap
            && $currentLevelCap < $finalLevelCap;
        $nextBreakCost = null;

        if ($currentLevelCap < $finalLevelCap) {
            $nextBreakLevel = $breakLevel + 1;
            $baseCost = GameRules::getBreakCost((string) $row['rarity']);
            $nextBreakCost = [
                'bright' => (int) $baseCost['bright_crystal']
                    * $nextBreakLevel,
                'items' => [
                    'break_core' => (int) $baseCost['break_material']
                        * $nextBreakLevel
                ]
            ];
        }

        return [
            'general_id' => (int) $row['general_id'],
            'owner_id' => (int) $row['owner_id'],
            'name' => (string) $row['name'],
            'rarity' => (string) $row['rarity'],
            'level' => $level,
            'break_level' => $breakLevel,
            'experience' => max(0, (int) $row['experience']),
            'skill_points_spent' => max(
                0,
                (int) $row['skill_points_spent']
            ),
            'current_level_cap' => $currentLevelCap,
            'final_level_cap' => $finalLevelCap,
            'can_break' => $canBreak,
            'next_break_cost' => $nextBreakCost,
            'hp' => max(0, (int) $row['hp']),
            'max_hp' => max(1, (int) $row['max_hp']),
            'attack' => max(0, (int) $row['attack']),
            'defense' => max(0, (int) $row['defense']),
            'speed' => max(0, (int) $row['speed']),
            'intelligence' => max(0, (int) $row['intelligence']),
            'last_hp_recovery' => (string) $row['last_hp_recovery'],
            'updated_at' => (string) $row['updated_at']
        ];
    }

    /**
     * 写入统一玩法事件 / Writes a unified gameplay event
     *
     * @param int $userId 玩家ID / User ID
     * @param string $eventType 事件类型 / Event type
     * @param int $eventValue 事件值 / Event value
     * @param string $referenceType 引用类型 / Reference type
     * @param int $referenceId 引用ID / Reference ID
     */
    private function recordGameplayEvent(
        $userId,
        $eventType,
        $eventValue,
        $referenceType,
        $referenceId
    ): void {
        $query = "INSERT INTO gameplay_events
                    (user_id, event_type, event_value, reference_type, reference_id)
                  VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法记录玩法事件 / Unable to record gameplay event');
        }

        $stmt->bind_param(
            'isisi',
            $userId,
            $eventType,
            $eventValue,
            $referenceType,
            $referenceId
        );
        $this->executeOrFail(
            $stmt,
            '无法记录玩法事件 / Unable to record gameplay event'
        );
        $stmt->close();
    }

    /**
     * 执行预处理语句或抛出异常 / Executes a prepared statement or throws
     *
     * @param mysqli_stmt $stmt 预处理语句 / Prepared statement
     * @param string $message 失败信息 / Failure message
     */
    private function executeOrFail($stmt, $message): void {
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($message . ': ' . $error);
        }
    }

    /**
     * 构建一致的成长服务结果 / Builds a consistent progression-service result
     *
     * @param bool $success 是否成功 / Whether the operation succeeded
     * @param string $message 结果信息 / Result message
     * @param array $progression 成长数据 / Progression data
     * @return array 结构化结果 / Structured result
     */
    private function result(
        $success,
        $message,
        array $progression = []
    ): array {
        return [
            'success' => (bool) $success,
            'message' => (string) $message,
            'progression' => $progression
        ];
    }
}

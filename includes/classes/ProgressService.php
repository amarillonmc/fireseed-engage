<?php
// 种火集结号 - 任务、成就与事件进度服务 / Fireseed Engage - Quest, achievement, and event progress service

class ProgressService {
    private $db;

    /**
     * 构造函数 / Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 记录玩法事件并推进任务和成就 / Record an event and advance quests and achievements
     * @param int $userId 用户ID / User ID
     * @param string $eventType 事件类型 / Event type
     * @param int $eventValue 事件值 / Event value
     * @param string|null $referenceType 关联类型 / Reference type
     * @param int|null $referenceId 关联ID / Reference ID
     * @return bool 是否成功 / Whether recording succeeded
     */
    public function recordEvent(
        $userId,
        $eventType,
        $eventValue = 1,
        $referenceType = null,
        $referenceId = null
    ) {
        $userId = (int) $userId;
        $eventType = normalizeTextInput($eventType, 64);
        $eventValue = (int) $eventValue;
        if ($userId < 1 || $eventType === '' || $eventValue < 1) {
            return false;
        }

        $this->db->begin_transaction();

        try {
            $query = "INSERT INTO gameplay_events
                        (user_id, event_type, event_value, reference_type, reference_id)
                      VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'isisi',
                $userId,
                $eventType,
                $eventValue,
                $referenceType,
                $referenceId
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('记录玩法事件失败');
            }
            $stmt->close();

            $this->advanceQuests($userId, $eventType, $eventValue);
            $this->advanceAchievements($userId, $eventType, $eventValue);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Progress event failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取任务与成就面板 / Get the quest and achievement dashboard
     * @param int $userId 用户ID / User ID
     * @return array 面板数据 / Dashboard data
     */
    public function getDashboard($userId) {
        $this->synchronizeUser((int) $userId);

        return [
            'quests' => $this->getQuests((int) $userId),
            'achievements' => $this->getAchievements((int) $userId)
        ];
    }

    /**
     * 根据事件账本重建玩家任务与成就进度 / Rebuild quest and achievement progress from the event ledger
     * @param int $userId 用户ID / User ID
     * @return bool 是否成功 / Whether synchronization succeeded
     */
    public function synchronizeUser($userId) {
        $userId = (int) $userId;
        if ($userId < 1) {
            return false;
        }

        $this->db->begin_transaction();

        try {
            $questResult = executePreparedSql(
                $this->db,
                "SELECT quest_id, event_type, target_value, reset_cycle
                 FROM quest_definitions WHERE is_active = 1"
            );
            if (!$questResult) {
                throw new RuntimeException('读取任务定义失败');
            }
            while ($quest = $questResult->fetch_assoc()) {
                $periodKey = $this->getPeriodKey($quest['reset_cycle']);
                $progress = $this->sumEventProgress(
                    $userId,
                    $quest['event_type'],
                    $quest['reset_cycle']
                );
                $progress = min((int) $quest['target_value'], $progress);
                $status = $progress >= (int) $quest['target_value']
                    ? 'completed'
                    : 'active';
                $query = "INSERT INTO user_quests
                            (user_id, quest_id, period_key, progress, status)
                          VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE
                            progress = VALUES(progress),
                            status = IF(status = 'claimed', 'claimed', VALUES(status))";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param(
                    'iisis',
                    $userId,
                    $quest['quest_id'],
                    $periodKey,
                    $progress,
                    $status
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException('同步任务进度失败');
                }
                $stmt->close();
            }

            $achievementResult = executePreparedSql(
                $this->db,
                "SELECT achievement_id, event_type, target_value
                 FROM achievement_definitions WHERE is_active = 1"
            );
            if (!$achievementResult) {
                throw new RuntimeException('读取成就定义失败');
            }
            while ($achievement = $achievementResult->fetch_assoc()) {
                $progress = $this->sumEventProgress(
                    $userId,
                    $achievement['event_type'],
                    'none'
                );
                $progress = min((int) $achievement['target_value'], $progress);
                $unlocked = $progress >= (int) $achievement['target_value'] ? 1 : 0;
                $query = "INSERT INTO user_achievements
                            (user_id, achievement_id, progress, unlocked_at)
                          VALUES (?, ?, ?, IF(? = 1, NOW(), NULL))
                          ON DUPLICATE KEY UPDATE
                            progress = VALUES(progress),
                            unlocked_at = IF(
                              unlocked_at IS NULL AND ? = 1,
                              NOW(),
                              unlocked_at
                            )";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param(
                    'iiiii',
                    $userId,
                    $achievement['achievement_id'],
                    $progress,
                    $unlocked,
                    $unlocked
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException('同步成就进度失败');
                }
                $stmt->close();
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Progress synchronization failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 领取任务奖励 / Claim a quest reward
     * @param int $userId 用户ID / User ID
     * @param int $userQuestId 玩家任务ID / User quest ID
     * @return array 操作结果 / Operation result
     */
    public function claimQuest($userId, $userQuestId) {
        $this->db->begin_transaction();

        try {
            $query = "SELECT uq.user_quest_id, uq.status, q.name, q.reward_json
                      FROM user_quests uq
                      INNER JOIN quest_definitions q ON q.quest_id = uq.quest_id
                      WHERE uq.user_quest_id = ? AND uq.user_id = ? FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $userQuestId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $quest = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$quest || $quest['status'] !== 'completed') {
                throw new RuntimeException('任务尚未完成或奖励已领取');
            }

            $query = "UPDATE user_quests
                      SET status = 'claimed', claimed_at = NOW()
                      WHERE user_quest_id = ? AND status = 'completed'";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userQuestId);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('更新任务领取状态失败');
            }
            $stmt->close();

            $reward = $this->applyQuestRewardBonus(
                $userId,
                decodeJsonObject($quest['reward_json'])
            );
            EconomyService::applyRewardInTransaction($this->db, $userId, $reward);
            $this->db->commit();
            return [
                'success' => true,
                'message' => '任务奖励已领取',
                'name' => $quest['name'],
                'reward' => $reward
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 领取成就奖励 / Claim an achievement reward
     * @param int $userId 用户ID / User ID
     * @param int $achievementId 成就ID / Achievement ID
     * @return array 操作结果 / Operation result
     */
    public function claimAchievement($userId, $achievementId) {
        $this->db->begin_transaction();

        try {
            $query = "SELECT ua.achievement_id, ua.unlocked_at, ua.claimed_at,
                             a.name, a.reward_json
                      FROM user_achievements ua
                      INNER JOIN achievement_definitions a
                        ON a.achievement_id = ua.achievement_id
                      WHERE ua.user_id = ? AND ua.achievement_id = ? FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $userId, $achievementId);
            $stmt->execute();
            $result = $stmt->get_result();
            $achievement = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$achievement || !$achievement['unlocked_at'] || $achievement['claimed_at']) {
                throw new RuntimeException('成就尚未解锁或奖励已领取');
            }

            $query = "UPDATE user_achievements SET claimed_at = NOW()
                      WHERE user_id = ? AND achievement_id = ?
                        AND unlocked_at IS NOT NULL AND claimed_at IS NULL";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $userId, $achievementId);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('更新成就领取状态失败');
            }
            $stmt->close();

            $reward = $this->applyQuestRewardBonus(
                $userId,
                decodeJsonObject($achievement['reward_json'])
            );
            EconomyService::applyRewardInTransaction($this->db, $userId, $reward);
            $this->db->commit();
            return [
                'success' => true,
                'message' => '成就奖励已领取',
                'name' => $achievement['name'],
                'reward' => $reward
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 推进匹配任务 / Advance matching quests
     * @param int $userId 用户ID / User ID
     * @param string $eventType 事件类型 / Event type
     * @param int $eventValue 事件值 / Event value
     * @return void
     */
    private function advanceQuests($userId, $eventType, $eventValue) {
        $query = "SELECT quest_id, target_value, reset_cycle
                  FROM quest_definitions WHERE event_type = ? AND is_active = 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $eventType);
        $stmt->execute();
        $result = $stmt->get_result();
        $quests = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $quests[] = $row;
            }
        }
        $stmt->close();

        foreach ($quests as $quest) {
            $periodKey = $this->getPeriodKey($quest['reset_cycle']);
            $target = (int) $quest['target_value'];
            $query = "INSERT INTO user_quests
                        (user_id, quest_id, period_key, progress, status)
                      VALUES (?, ?, ?, LEAST(?, ?), IF(? >= ?, 'completed', 'active'))
                      ON DUPLICATE KEY UPDATE
                        progress = IF(
                          status = 'claimed',
                          progress,
                          LEAST(?, progress + ?)
                        ),
                        status = IF(
                          status = 'claimed',
                          'claimed',
                          IF(progress >= ?, 'completed', status)
                        )";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iisiiiiiii',
                $userId,
                $quest['quest_id'],
                $periodKey,
                $eventValue,
                $target,
                $eventValue,
                $target,
                $target,
                $eventValue,
                $target
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('推进任务失败');
            }
            $stmt->close();
        }
    }

    /**
     * 推进匹配成就 / Advance matching achievements
     * @param int $userId 用户ID / User ID
     * @param string $eventType 事件类型 / Event type
     * @param int $eventValue 事件值 / Event value
     * @return void
     */
    private function advanceAchievements($userId, $eventType, $eventValue) {
        $query = "SELECT achievement_id, target_value
                  FROM achievement_definitions
                  WHERE event_type = ? AND is_active = 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $eventType);
        $stmt->execute();
        $result = $stmt->get_result();
        $achievements = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $achievements[] = $row;
            }
        }
        $stmt->close();

        foreach ($achievements as $achievement) {
            $target = (int) $achievement['target_value'];
            $query = "INSERT INTO user_achievements
                        (user_id, achievement_id, progress, unlocked_at)
                      VALUES (?, ?, LEAST(?, ?), IF(? >= ?, NOW(), NULL))
                      ON DUPLICATE KEY UPDATE
                        progress = LEAST(?, progress + ?),
                        unlocked_at = IF(
                          unlocked_at IS NULL AND progress >= ?,
                          NOW(),
                          unlocked_at
                        )";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iiiiiiiii',
                $userId,
                $achievement['achievement_id'],
                $eventValue,
                $target,
                $eventValue,
                $target,
                $target,
                $eventValue,
                $target
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('推进成就失败');
            }
            $stmt->close();
        }
    }

    /**
     * 获取任务列表 / Get quest entries
     * @param int $userId 用户ID / User ID
     * @return array 任务列表 / Quest list
     */
    private function getQuests($userId) {
        $query = "SELECT * FROM quest_definitions WHERE is_active = 1
                  ORDER BY FIELD(reset_cycle, 'daily', 'weekly', 'none'), quest_id";
        $result = executePreparedSql($this->db, $query);
        $quests = [];
        if (!$result) {
            return $quests;
        }

        while ($definition = $result->fetch_assoc()) {
            $periodKey = $this->getPeriodKey($definition['reset_cycle']);
            $query = "SELECT user_quest_id, progress, status, claimed_at
                      FROM user_quests
                      WHERE user_id = ? AND quest_id = ? AND period_key = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iis', $userId, $definition['quest_id'], $periodKey);
            $stmt->execute();
            $progressResult = $stmt->get_result();
            $progress = $progressResult ? $progressResult->fetch_assoc() : null;
            $stmt->close();

            $definition['period_key'] = $periodKey;
            $definition['user_quest_id'] = $progress
                ? (int) $progress['user_quest_id']
                : null;
            $definition['progress'] = $progress ? (int) $progress['progress'] : 0;
            $definition['status'] = $progress ? $progress['status'] : 'active';
            $definition['claimed_at'] = $progress ? $progress['claimed_at'] : null;
            $definition['reward'] = decodeJsonObject($definition['reward_json']);
            $quests[] = $definition;
        }

        return $quests;
    }

    /**
     * 获取成就列表 / Get achievement entries
     * @param int $userId 用户ID / User ID
     * @return array 成就列表 / Achievement list
     */
    private function getAchievements($userId) {
        $query = "SELECT a.*, COALESCE(ua.progress, 0) AS progress,
                         ua.unlocked_at, ua.claimed_at
                  FROM achievement_definitions a
                  LEFT JOIN user_achievements ua
                    ON ua.achievement_id = a.achievement_id AND ua.user_id = ?
                  WHERE a.is_active = 1 ORDER BY a.achievement_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $achievements = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['progress'] = (int) $row['progress'];
                $row['reward'] = decodeJsonObject($row['reward_json']);
                $achievements[] = $row;
            }
        }
        $stmt->close();

        return $achievements;
    }

    /**
     * 应用玩家武将提供的任务奖励加成 / Apply quest-reward bonuses from a player's living generals
     * @param int $userId 用户ID / User ID
     * @param array $reward 基础奖励 / Base reward
     * @return array 加成后的奖励 / Boosted reward
     */
    private function applyQuestRewardBonus($userId, $reward) {
        $bonus = $this->getQuestRewardBonus((int) $userId);
        if ($bonus <= 0.0) {
            return $reward;
        }

        $factor = 1 + $bonus / 100;
        foreach (['resources', 'wallet', 'items'] as $bucket) {
            if (!isset($reward[$bucket]) || !is_array($reward[$bucket])) {
                continue;
            }
            foreach ($reward[$bucket] as $key => $value) {
                if (!is_numeric($value) || (float) $value <= 0.0) {
                    continue;
                }
                $reward[$bucket][$key] = max(
                    (int) $value,
                    (int) floor((float) $value * $factor)
                );
            }
        }

        return $reward;
    }

    /**
     * 汇总存活武将的任务奖励加成，最高50% / Sum living generals' quest-reward bonus, capped at 50%
     * @param int $userId 用户ID / User ID
     * @return float 奖励加成百分比 / Reward bonus percentage
     */
    private function getQuestRewardBonus($userId) {
        $query = "SELECT general_id
                  FROM generals
                  WHERE owner_id = ? AND is_active = 1 AND hp > 0
                  ORDER BY general_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $bonus = 0.0;
        while ($result && ($row = $result->fetch_assoc())) {
            $general = new General((int) $row['general_id']);
            if (!$general->isValid()
                || (int) $general->getOwnerId() !== $userId
                || (int) $general->getHp() <= 0) {
                continue;
            }
            $bonus += $general->getSkillEffectTotal(
                'quest_reward',
                50.0 - $bonus
            );
            if ($bonus >= 50.0) {
                $bonus = 50.0;
                break;
            }
        }
        $stmt->close();

        return max(0.0, min(50.0, $bonus));
    }

    /**
     * 获取任务周期键 / Get a quest period key
     * @param string $resetCycle 重置周期 / Reset cycle
     * @return string 周期键 / Period key
     */
    private function getPeriodKey($resetCycle) {
        if ($resetCycle === 'daily') {
            return date('Y-m-d');
        }
        if ($resetCycle === 'weekly') {
            return date('o-\WW');
        }

        return 'lifetime';
    }

    /**
     * 汇总指定周期的事件值 / Sum event values for a reset period
     * @param int $userId 用户ID / User ID
     * @param string $eventType 事件类型 / Event type
     * @param string $resetCycle 重置周期 / Reset cycle
     * @return int 汇总进度 / Summed progress
     */
    private function sumEventProgress($userId, $eventType, $resetCycle) {
        $query = "SELECT COALESCE(SUM(event_value), 0) AS progress
                  FROM gameplay_events WHERE user_id = ? AND event_type = ?";
        $periodStart = null;
        if ($resetCycle === 'daily') {
            $periodStart = date('Y-m-d 00:00:00');
        } elseif ($resetCycle === 'weekly') {
            $periodStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
        }
        if ($periodStart !== null) {
            $query .= " AND created_at >= ?";
        }

        $stmt = $this->db->prepare($query);
        if ($periodStart !== null) {
            $stmt->bind_param('iss', $userId, $eventType, $periodStart);
        } else {
            $stmt->bind_param('is', $userId, $eventType);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? max(0, (int) $row['progress']) : 0;
    }
}

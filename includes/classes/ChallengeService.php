<?php
// 种火集结号 - 讨伐战、竞技场与战斗之塔服务 / Fireseed Engage - Raid, Arena, and Battle Tower service

class ChallengeService {
    private $db;

    /**
     * 构造函数 / Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 获取挑战玩法总览 / Get the challenge-mode dashboard
     * @param int $userId 用户ID / User ID
     * @return array 总览数据 / Dashboard data
     */
    public function getDashboard($userId) {
        $this->ensureArenaProfile($userId);
        $this->ensureTowerProgress($userId);

        return [
            'raids' => $this->getRaids($userId),
            'arena_profile' => $this->getArenaProfile($userId),
            'arena_opponents' => $this->getArenaOpponents($userId),
            'tower' => $this->getTowerProgress($userId),
            'armies' => Army::getUserArmies($userId)
        ];
    }

    /**
     * 设置竞技场防守军队 / Set the Arena defense army
     * @param int $userId 用户ID / User ID
     * @param int $armyId 军队ID / Army ID
     * @return array 操作结果 / Operation result
     */
    public function setArenaDefense($userId, $armyId) {
        $userId = (int) $userId;
        $armyId = (int) $armyId;
        if ($userId < 1 || $armyId < 1) {
            return ['success' => false, 'message' => '竞技场防守军队无效'];
        }

        $this->ensureArenaProfile($userId);
        $this->db->begin_transaction();

        try {
            // 先锁定竞技场资料，再锁定军队与编成，顺序与竞技场结算一致 / Lock the Arena profile before the army and composition, matching challenge resolution order
            $query = "SELECT user_id
                      FROM arena_profiles
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $profile = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$profile) {
                throw new RuntimeException('竞技场资料不存在');
            }

            // 竞技场是抽象同址战斗，权威距离固定为0 / Arena is an abstract colocated battle, so its authoritative distance is zero
            $arenaDefenseContext = [
                'phase' => 'battle',
                'side' => 'defense',
                'target_tags' => ['army', 'player'],
                'distance' => 0
            ];
            $lockedArmies = $this->lockCombatArmies([
                [
                    'key' => 'defense',
                    'army_id' => $armyId,
                    'owner_id' => $userId,
                    'combat_context' => $arenaDefenseContext
                ]
            ]);
            if (!isset($lockedArmies['defense'])) {
                throw new RuntimeException('只能选择自己的待命军队');
            }

            $query = "UPDATE arena_profiles
                      SET defense_army_id = ?
                      WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $armyId, $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('更新防守军队失败');
            }
            $stmt->close();

            $this->db->commit();
            return ['success' => true, 'message' => '竞技场防守军队已更新'];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 发起竞技场挑战 / Start an Arena challenge
     * @param int $attackerId 攻击者ID / Attacker ID
     * @param int $defenderId 防守者ID / Defender ID
     * @param int $attackerArmyId 攻击军队ID / Attacking army ID
     * @return array 战斗结果 / Battle result
     */
    public function challengeArena($attackerId, $defenderId, $attackerArmyId) {
        $attackerId = (int) $attackerId;
        $defenderId = (int) $defenderId;
        if ($attackerId < 1 || $defenderId < 1 || $attackerId === $defenderId) {
            return ['success' => false, 'message' => '竞技场对手无效'];
        }
        $attackerArmyId = (int) $attackerArmyId;
        if ($attackerArmyId < 1) {
            return ['success' => false, 'message' => '攻击军队无效'];
        }

        $query = "SELECT COUNT(*) AS attempts FROM arena_battles
                  WHERE attacker_id = ? AND created_at >= CURDATE()
                    AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $attackerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($row && (int) $row['attempts'] >= 5) {
            return ['success' => false, 'message' => '今日竞技场挑战次数已用完'];
        }

        $this->ensureArenaProfile($attackerId);
        $this->ensureArenaProfile($defenderId);
        $this->db->begin_transaction();

        try {
            $firstId = min($attackerId, $defenderId);
            $secondId = max($attackerId, $defenderId);
            $query = "SELECT user_id, defense_army_id, rating
                      FROM arena_profiles WHERE user_id IN (?, ?)
                      ORDER BY user_id FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $firstId, $secondId);
            $stmt->execute();
            $profileResult = $stmt->get_result();
            $profiles = [];
            if ($profileResult) {
                while ($profile = $profileResult->fetch_assoc()) {
                    $profiles[(int) $profile['user_id']] = $profile;
                }
            }
            $stmt->close();

            if (!isset($profiles[$attackerId], $profiles[$defenderId])
                || !$profiles[$defenderId]['defense_army_id']) {
                throw new RuntimeException('对手尚未设置防守军队');
            }

            $query = "SELECT COUNT(*) AS attempts FROM arena_battles
                      WHERE attacker_id = ? AND created_at >= CURDATE()
                        AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $attackerId);
            $stmt->execute();
            $attemptResult = $stmt->get_result();
            $attemptRow = $attemptResult ? $attemptResult->fetch_assoc() : null;
            $stmt->close();
            if ($attemptRow && (int) $attemptRow['attempts'] >= 5) {
                throw new RuntimeException('今日竞技场挑战次数已用完');
            }

            // 竞技场是抽象同址战斗，双方距离固定为0并各自面向玩家军队 / Arena is abstract and colocated at distance zero, with each side targeting a player army
            $arenaAttackerContext = [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['army', 'player'],
                'distance' => 0
            ];
            $arenaDefenderContext = [
                'phase' => 'battle',
                'side' => 'defense',
                'target_tags' => ['army', 'player'],
                'distance' => 0
            ];
            // 按军队ID顺序锁定双方编成，防止解散或行军与结算交错 / Lock both compositions by army ID so disbanding or marching cannot race resolution
            $lockedArmies = $this->lockCombatArmies([
                [
                    'key' => 'attacker',
                    'army_id' => $attackerArmyId,
                    'owner_id' => $attackerId,
                    'combat_context' => $arenaAttackerContext
                ],
                [
                    'key' => 'defender',
                    'army_id' => (int) $profiles[$defenderId]['defense_army_id'],
                    'owner_id' => $defenderId,
                    'combat_context' => $arenaDefenderContext
                ]
            ]);
            $attackerArmy = $lockedArmies['attacker'];
            $defenderArmy = $lockedArmies['defender'];

            $attackerPower = $attackerArmy->getCombatPower(
                $arenaAttackerContext
            );
            $defenderPower = $defenderArmy->getCombatPower(
                $arenaDefenderContext
            );
            $outcome = GameRules::calculateBattleOutcome($attackerPower, $defenderPower);
            $draw = $outcome === 'draw';
            $attackerWon = strpos($outcome, 'attacker_win') === 0;
            $winnerId = $draw ? null : ($attackerWon ? $attackerId : $defenderId);
            $elo = GameRules::calculateArenaEloChanges(
                (int) $profiles[$attackerId]['rating'],
                (int) $profiles[$defenderId]['rating'],
                $draw ? 0.5 : ($attackerWon ? 1.0 : 0.0)
            );
            $ratingChange = $elo['player_a'];

            $query = "INSERT INTO arena_battles
                        (attacker_id, defender_id, attacker_army_id, defender_army_id,
                         attacker_power, defender_power, winner_id, rating_change)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $defenderArmyId = $defenderArmy->getArmyId();
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iiiiiiii',
                $attackerId,
                $defenderId,
                $attackerArmyId,
                $defenderArmyId,
                $attackerPower,
                $defenderPower,
                $winnerId,
                $ratingChange
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('记录竞技场战斗失败');
            }
            $stmt->close();

            $attackerWinDelta = $attackerWon ? 1 : 0;
            $attackerLossDelta = !$draw && !$attackerWon ? 1 : 0;
            $query = "UPDATE arena_profiles
                      SET rating = GREATEST(0, rating + ?),
                          wins = wins + ?, losses = losses + ?,
                          season_points = season_points + ?
                      WHERE user_id = ?";
            $points = $attackerWon ? 3 : 1;
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iiiii',
                $ratingChange,
                $attackerWinDelta,
                $attackerLossDelta,
                $points,
                $attackerId
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('更新攻击者竞技场资料失败');
            }
            $stmt->close();

            $defenderRatingChange = -$ratingChange;
            $defenderWinDelta = !$draw && !$attackerWon ? 1 : 0;
            $defenderLossDelta = $attackerWon ? 1 : 0;
            $query = "UPDATE arena_profiles
                      SET rating = GREATEST(0, rating + ?),
                          wins = wins + ?, losses = losses + ?
                      WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iiii',
                $defenderRatingChange,
                $defenderWinDelta,
                $defenderLossDelta,
                $defenderId
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('更新防守者竞技场资料失败');
            }
            $stmt->close();

            EconomyService::applyRewardInTransaction(
                $this->db,
                $attackerId,
                ['wallet' => ['arena_tokens' => $attackerWon ? 10 : 3]]
            );
            $this->db->commit();

            return [
                'success' => true,
                'message' => $draw
                    ? '竞技场挑战平局'
                    : ($attackerWon ? '竞技场挑战胜利' : '竞技场挑战失败'),
                'outcome' => $outcome,
                'attacker_power' => $attackerPower,
                'defender_power' => $defenderPower,
                'rating_change' => $ratingChange
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 挑战战斗之塔当前楼层 / Challenge the current Battle Tower floor
     * @param int $userId 用户ID / User ID
     * @param int $armyId 军队ID / Army ID
     * @return array 挑战结果 / Challenge result
     */
    public function challengeTower($userId, $armyId) {
        $userId = (int) $userId;
        $armyId = (int) $armyId;
        if ($userId < 1 || $armyId < 1) {
            return ['success' => false, 'message' => '挑战军队无效'];
        }

        $this->ensureTowerProgress($userId);
        $this->db->begin_transaction();

        try {
            $query = "SELECT * FROM tower_progress WHERE user_id = ? FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $progress = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$progress) {
                throw new RuntimeException('战斗之塔进度不存在');
            }

            $attempts = $progress['attempt_date'] === date('Y-m-d')
                ? (int) $progress['attempts_today']
                : 0;
            if ($attempts >= 5) {
                throw new RuntimeException('今日战斗之塔挑战次数已用完');
            }

            // 战斗之塔是抽象同址的NPC军队战，权威距离固定为0 / Battle Tower is an abstract colocated NPC-army fight with authoritative distance zero
            $towerBattleContext = [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['army', 'npc'],
                'distance' => 0
            ];
            // 奖励结算前锁定并重建实际编成 / Lock and rebuild the live composition before resolving rewards
            $lockedArmies = $this->lockCombatArmies([
                [
                    'key' => 'tower',
                    'army_id' => $armyId,
                    'owner_id' => $userId,
                    'combat_context' => $towerBattleContext
                ]
            ]);
            $army = $lockedArmies['tower'];
            $floor = (int) $progress['current_floor'];
            $enemyPower = GameRules::getTowerEnemyPower($floor);
            $armyPower = $army->getCombatPower($towerBattleContext);
            $won = $armyPower >= $enemyPower;
            $nextFloor = $won ? $floor + 1 : $floor;
            $highestFloor = $won
                ? max((int) $progress['highest_floor'], $floor)
                : (int) $progress['highest_floor'];
            $attempts++;

            $query = "UPDATE tower_progress
                      SET current_floor = ?, highest_floor = ?,
                          attempts_today = ?, attempt_date = CURDATE()
                      WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iiii', $nextFloor, $highestFloor, $attempts, $userId);
            if (!$stmt->execute()) {
                throw new RuntimeException('更新战斗之塔进度失败');
            }
            $stmt->close();

            $reward = [];
            if ($won) {
                $towerReward = GameRules::getTowerReward($floor);
                $reward = [
                    'resources' => [
                        'bright' => $towerReward['bright_crystal'],
                        'night' => $towerReward['night_crystal']
                    ],
                    'items' => [
                        'break_core' => $towerReward['break_material']
                    ]
                ];
                EconomyService::applyRewardInTransaction($this->db, $userId, $reward);
            }
            $this->db->commit();

            if ($won) {
                $progressService = new ProgressService();
                $progressService->recordEvent(
                    $userId,
                    'tower_floor_cleared',
                    1,
                    'tower_floor',
                    $floor
                );
            }

            return [
                'success' => true,
                'message' => $won ? '战斗之塔挑战成功' : '战斗之塔挑战失败',
                'won' => $won,
                'floor' => $floor,
                'army_power' => $armyPower,
                'enemy_power' => $enemyPower,
                'reward' => $reward
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 攻击当前讨伐目标 / Attack an active raid target
     * @param int $userId 用户ID / User ID
     * @param int $raidId 讨伐战ID / Raid ID
     * @param int $armyId 军队ID / Army ID
     * @return array 攻击结果 / Attack result
     */
    public function attackRaid($userId, $raidId, $armyId) {
        $userId = (int) $userId;
        $raidId = (int) $raidId;
        $armyId = (int) $armyId;
        if ($userId < 1 || $raidId < 1 || $armyId < 1) {
            return ['success' => false, 'message' => '讨伐军队无效'];
        }

        $this->db->begin_transaction();

        try {
            // 先锁定当前赛季以统一跨玩法的事务加锁顺序 / Lock the current season first to keep cross-mode transaction ordering consistent
            $query = "SELECT season_id
                      FROM seasons
                      WHERE ended_at IS NULL
                      ORDER BY season_number DESC
                      LIMIT 1
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            if (!$stmt->execute()) {
                throw new RuntimeException('读取当前赛季失败');
            }
            $seasonResult = $stmt->get_result();
            $season = $seasonResult ? $seasonResult->fetch_assoc() : null;
            $seasonId = $season ? (int) $season['season_id'] : null;
            $stmt->close();

            // 锁定并重新校验军队，避免使用事务开始前的过期对象状态 / Lock and revalidate the army to avoid stale pre-transaction object state
            $query = "SELECT owner_id, status
                      FROM armies
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $armyResult = $stmt->get_result();
            $armyRow = $armyResult ? $armyResult->fetch_assoc() : null;
            $stmt->close();
            if (!$armyRow
                || (int) $armyRow['owner_id'] !== $userId
                || $armyRow['status'] !== 'idle') {
                throw new RuntimeException('讨伐军队无效');
            }

            // 锁定完整编成，防止攻击结算期间士兵被解散或转移 / Lock the full composition so units cannot be disbanded or transferred during resolution
            $query = "SELECT army_unit_id, soldier_type, level, quantity
                      FROM army_units
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $unitResult = $stmt->get_result();
            $hasLivingUnits = false;
            while ($unitResult && ($unit = $unitResult->fetch_assoc())) {
                if ((int) $unit['level'] > 0 && (int) $unit['quantity'] > 0) {
                    $hasLivingUnits = true;
                }
            }
            $stmt->close();
            if (!$hasLivingUnits) {
                throw new RuntimeException('讨伐军队没有可作战单位');
            }

            // 讨伐目标是抽象同址的NPC结构，权威距离固定为0 / A Raid target is an abstract colocated NPC structure with authoritative distance zero
            $raidBattleContext = [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => ['npc', 'structure'],
                'distance' => 0
            ];
            $army = new Army($armyId);
            $armyPower = $army->getCombatPower($raidBattleContext);
            if (!$army->isValid()
                || (int) $army->getOwnerId() !== $userId
                || $army->getStatus() !== 'idle'
                || $armyPower <= 0) {
                throw new RuntimeException('讨伐军队没有有效战斗力');
            }

            $query = "SELECT * FROM raid_events WHERE raid_id = ? FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $raidId);
            $stmt->execute();
            $result = $stmt->get_result();
            $raid = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$raid
                || !in_array($raid['status'], ['scheduled', 'active'], true)
                || strtotime($raid['starts_at']) > time()
                || strtotime($raid['ends_at']) <= time()
                || (int) $raid['current_hp'] <= 0) {
                throw new RuntimeException('讨伐目标当前不可攻击');
            }

            $query = "SELECT last_attack_at FROM raid_participation
                      WHERE raid_id = ? AND user_id = ? FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $raidId, $userId);
            $stmt->execute();
            $participationResult = $stmt->get_result();
            $participation = $participationResult
                ? $participationResult->fetch_assoc()
                : null;
            $stmt->close();
            if ($participation
                && $participation['last_attack_at']
                && time() - strtotime($participation['last_attack_at']) < 600) {
                throw new RuntimeException('讨伐军整备中，请十分钟后再试');
            }

            $damage = max(1, $armyPower - (int) $raid['defense_power']);
            $damage = min($damage, (int) $raid['current_hp']);
            $newHp = (int) $raid['current_hp'] - $damage;
            $newStatus = $newHp <= 0 ? 'defeated' : 'active';

            $query = "UPDATE raid_events
                      SET current_hp = ?, status = ? WHERE raid_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('isi', $newHp, $newStatus, $raidId);
            if (!$stmt->execute()) {
                throw new RuntimeException('更新讨伐目标失败');
            }
            $stmt->close();

            $query = "INSERT INTO raid_participation
                        (raid_id, user_id, total_damage, attack_count, last_attack_at)
                      VALUES (?, ?, ?, 1, NOW())
                      ON DUPLICATE KEY UPDATE
                        total_damage = total_damage + VALUES(total_damage),
                        attack_count = attack_count + 1,
                        last_attack_at = NOW()";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iii', $raidId, $userId, $damage);
            if (!$stmt->execute()) {
                throw new RuntimeException('记录讨伐贡献失败');
            }
            $stmt->close();

            // 当前赛季存在时按单次伤害累计有上限的讨伐积分 / Add bounded raid score from this hit when a current season exists
            if ($seasonId !== null) {
                $raidScore = min(1000, max(1, (int) ceil($damage / 1000)));
                $query = "INSERT INTO season_scores
                            (season_id, user_id, raid_score)
                          VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE
                            raid_score = LEAST(
                              2147483647,
                              raid_score + VALUES(raid_score)
                            )";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('iii', $seasonId, $userId, $raidScore);
                if (!$stmt->execute()) {
                    throw new RuntimeException('更新赛季讨伐积分失败');
                }
                $stmt->close();
            }

            $this->db->commit();

            $progressService = new ProgressService();
            $progressService->recordEvent(
                $userId,
                'raid_damage',
                $damage,
                'raid',
                $raidId
            );

            return [
                'success' => true,
                'message' => $newHp <= 0 ? '讨伐目标已被击破' : '讨伐攻击完成',
                'damage' => $damage,
                'remaining_hp' => $newHp,
                'defeated' => $newHp <= 0
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 领取讨伐战贡献奖励 / Claim raid participation rewards
     * @param int $userId 用户ID / User ID
     * @param int $raidId 讨伐战ID / Raid ID
     * @return array 操作结果 / Operation result
     */
    public function claimRaidReward($userId, $raidId) {
        $this->db->begin_transaction();

        try {
            $query = "SELECT r.status, r.ends_at, r.reward_json, r.max_hp,
                             p.total_damage, p.reward_claimed_at
                      FROM raid_events r
                      INNER JOIN raid_participation p ON p.raid_id = r.raid_id
                      WHERE r.raid_id = ? AND p.user_id = ? FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $raidId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$row
                || ($row['status'] !== 'defeated' && strtotime($row['ends_at']) > time())
                || $row['reward_claimed_at']) {
                throw new RuntimeException('讨伐奖励尚不可领取或已领取');
            }

            $minimumContribution = GameRules::getRaidMinimumContribution(
                max(0, (int) $row['max_hp'])
            );
            if ((int) $row['total_damage'] < $minimumContribution) {
                throw new RuntimeException(
                    '讨伐贡献不足，至少需要造成'
                    . $minimumContribution
                    . '点伤害'
                );
            }

            $reward = decodeJsonObject($row['reward_json']);
            $contributionTokens = min(100, max(1, intdiv((int) $row['total_damage'], 1000)));
            if (!isset($reward['wallet']) || !is_array($reward['wallet'])) {
                $reward['wallet'] = [];
            }
            $reward['wallet']['arena_tokens'] = $contributionTokens;
            EconomyService::applyRewardInTransaction($this->db, $userId, $reward);

            $query = "UPDATE raid_participation SET reward_claimed_at = NOW()
                      WHERE raid_id = ? AND user_id = ?
                        AND reward_claimed_at IS NULL";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $raidId, $userId);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('更新讨伐奖励状态失败');
            }
            $stmt->close();
            $this->db->commit();

            return [
                'success' => true,
                'message' => '讨伐奖励已领取',
                'reward' => $reward
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 维护讨伐周期并在需要时创建下一期 / Maintain the raid cycle and create the next event when needed
     * @return array 周期维护结果 / Cycle maintenance result
     */
    public function maintainRaidCycle() {
        $this->db->begin_transaction();

        try {
            // 锁定最近一期作为轮换互斥点，阻止并发任务重复开期 / Lock the latest event as a rotation mutex to prevent duplicate concurrent creation
            $query = "SELECT raid_id
                      FROM raid_events
                      ORDER BY raid_id DESC
                      LIMIT 1
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            if (!$stmt->execute()) {
                throw new RuntimeException('锁定讨伐轮换失败');
            }
            $latestResult = $stmt->get_result();
            if ($latestResult) {
                $latestResult->fetch_assoc();
            }
            $stmt->close();

            $query = "UPDATE raid_events
                      SET status = 'ended'
                      WHERE status IN ('scheduled', 'active')
                        AND ends_at <= NOW()";
            $stmt = $this->db->prepare($query);
            if (!$stmt->execute()) {
                throw new RuntimeException('更新到期讨伐状态失败');
            }
            $endedCount = $stmt->affected_rows;
            $stmt->close();

            // 修正极端并发下生命归零但状态尚未同步的目标 / Normalize zero-HP targets whose status lagged under exceptional concurrency
            $query = "UPDATE raid_events
                      SET status = 'defeated'
                      WHERE status IN ('scheduled', 'active')
                        AND current_hp <= 0";
            $stmt = $this->db->prepare($query);
            if (!$stmt->execute()) {
                throw new RuntimeException('同步已击破讨伐状态失败');
            }
            $stmt->close();

            $query = "UPDATE raid_events
                      SET status = 'active'
                      WHERE status = 'scheduled'
                        AND starts_at <= NOW()
                        AND ends_at > NOW()
                        AND current_hp > 0";
            $stmt = $this->db->prepare($query);
            if (!$stmt->execute()) {
                throw new RuntimeException('激活本期讨伐失败');
            }
            $stmt->close();

            $query = "SELECT raid_id
                      FROM raid_events
                      WHERE status IN ('scheduled', 'active')
                        AND ends_at > NOW()
                        AND current_hp > 0
                      ORDER BY starts_at ASC, raid_id ASC
                      LIMIT 1
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            if (!$stmt->execute()) {
                throw new RuntimeException('读取当前讨伐失败');
            }
            $availableResult = $stmt->get_result();
            $availableRaid = $availableResult
                ? $availableResult->fetch_assoc()
                : null;
            $stmt->close();
            if ($availableRaid) {
                $this->db->commit();
                return [
                    'created' => false,
                    'raid_id' => (int) $availableRaid['raid_id'],
                    'message' => $endedCount > 0
                        ? '已结束到期讨伐，当前仍有可用目标'
                        : '当前讨伐目标仍可用'
                ];
            }

            $query = "SELECT COUNT(*) AS raid_count FROM raid_events";
            $stmt = $this->db->prepare($query);
            if (!$stmt->execute()) {
                throw new RuntimeException('统计讨伐期数失败');
            }
            $countResult = $stmt->get_result();
            $countRow = $countResult ? $countResult->fetch_assoc() : null;
            $stmt->close();
            $raidCount = $countRow ? (int) $countRow['raid_count'] : 0;

            // 线性成长最多计算四十期，避免长期运营后数值无界膨胀 / Scale linearly for at most forty tiers to prevent unbounded long-term inflation
            $growthTier = min(40, max(0, $raidCount));
            $maxHp = 5000000 + $growthTier * 500000;
            $defensePower = 1000 + $growthTier * 250;
            $brightReward = 2000 + $growthTier * 250;
            $nightReward = 500 + $growthTier * 100;
            $breakCoreReward = 1 + intdiv($growthTier, 10);
            $cycleNumber = $raidCount + 1;
            $name = '数据潮汐·第' . $cycleNumber . '期';
            $description = '数据海的周期潮汐凝聚为无主巨像，等待所有钻探者共同解构。';
            $reward = [
                'resources' => [
                    'bright' => $brightReward,
                    'night' => $nightReward
                ],
                'items' => [
                    'break_core' => $breakCoreReward
                ]
            ];
            $rewardJson = json_encode($reward, JSON_UNESCAPED_UNICODE);
            if ($rewardJson === false) {
                throw new RuntimeException('生成讨伐奖励失败');
            }

            $query = "INSERT INTO raid_events
                        (name, description, max_hp, current_hp, defense_power,
                         starts_at, ends_at, status, reward_json)
                      VALUES (?, ?, ?, ?, ?, NOW(),
                              DATE_ADD(NOW(), INTERVAL 7 DAY), 'active', ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'ssiiis',
                $name,
                $description,
                $maxHp,
                $maxHp,
                $defensePower,
                $rewardJson
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('创建下一期讨伐失败');
            }
            $raidId = (int) $this->db->insert_id;
            $stmt->close();
            $this->db->commit();

            return [
                'created' => true,
                'raid_id' => $raidId,
                'message' => '下一期数据潮汐讨伐已开启'
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return [
                'created' => false,
                'raid_id' => null,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * 获取讨伐战列表 / Get raid entries
     * @param int $userId 用户ID / User ID
     * @return array 讨伐战列表 / Raid list
     */
    private function getRaids($userId) {
        $query = "SELECT r.*, COALESCE(p.total_damage, 0) AS user_damage,
                         COALESCE(p.attack_count, 0) AS user_attacks,
                         p.last_attack_at, p.reward_claimed_at
                  FROM raid_events r
                  LEFT JOIN raid_participation p
                    ON p.raid_id = r.raid_id AND p.user_id = ?
                  WHERE r.ends_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                  ORDER BY r.starts_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $raids = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['reward'] = decodeJsonObject($row['reward_json']);
                $raids[] = $row;
            }
        }
        $stmt->close();

        return $raids;
    }

    /**
     * 按确定顺序锁定并重建可战斗军队 / Lock and rebuild combat-ready armies in deterministic order
     * @param array $specifications 含权威战斗上下文的军队要求 / Army specifications with authoritative combat contexts
     * @return array<string,Army> 以调用方键索引的军队 / Armies indexed by caller keys
     */
    private function lockCombatArmies($specifications) {
        usort($specifications, function ($left, $right) {
            return (int) $left['army_id'] <=> (int) $right['army_id'];
        });

        $armies = [];
        foreach ($specifications as $specification) {
            $armyId = (int) $specification['army_id'];
            $ownerId = (int) $specification['owner_id'];
            if (!isset($specification['combat_context'])
                || !is_array($specification['combat_context'])) {
                throw new RuntimeException('参战军队缺少战斗上下文');
            }
            $combatContext = $specification['combat_context'];
            $query = "SELECT owner_id, status
                      FROM armies
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $armyRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$armyRow
                || (int) $armyRow['owner_id'] !== $ownerId
                || $armyRow['status'] !== 'idle') {
                throw new RuntimeException('参战军队已失效或不再待命');
            }

            $query = "SELECT army_unit_id, level, quantity
                      FROM army_units
                      WHERE army_id = ?
                      ORDER BY army_unit_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $unitResult = $stmt->get_result();
            $hasUnits = false;
            while ($unitResult && ($unit = $unitResult->fetch_assoc())) {
                if ((int) $unit['level'] > 0 && (int) $unit['quantity'] > 0) {
                    $hasUnits = true;
                }
            }
            $stmt->close();
            if (!$hasUnits) {
                throw new RuntimeException('参战军队没有可用单位');
            }

            $army = new Army($armyId);
            if (!$army->isValid()
                || (int) $army->getOwnerId() !== $ownerId
                || $army->getStatus() !== 'idle'
                || $army->getCombatPower($combatContext) <= 0) {
                throw new RuntimeException('参战军队没有有效战斗力');
            }
            $armies[(string) $specification['key']] = $army;
        }

        return $armies;
    }

    /**
     * 获取竞技场资料 / Get an Arena profile
     * @param int $userId 用户ID / User ID
     * @return array|null 竞技场资料 / Arena profile
     */
    private function getArenaProfile($userId) {
        $query = "SELECT ap.*, a.name AS defense_army_name
                  FROM arena_profiles ap
                  LEFT JOIN armies a ON a.army_id = ap.defense_army_id
                  WHERE ap.user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $profile = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $profile;
    }

    /**
     * 获取竞技场对手 / Get Arena opponents
     * @param int $userId 用户ID / User ID
     * @return array 对手列表 / Opponent list
     */
    private function getArenaOpponents($userId) {
        $query = "SELECT ap.user_id, u.username, ap.rating, ap.wins, ap.losses,
                         a.name AS defense_army_name
                  FROM arena_profiles ap
                  INNER JOIN users u ON u.user_id = ap.user_id
                  INNER JOIN armies a ON a.army_id = ap.defense_army_id
                  WHERE ap.user_id <> ?
                  ORDER BY ap.rating DESC LIMIT 20";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $opponents = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $opponents[] = $row;
            }
        }
        $stmt->close();

        return $opponents;
    }

    /**
     * 获取战斗之塔进度 / Get Battle Tower progress
     * @param int $userId 用户ID / User ID
     * @return array|null 进度数据 / Progress data
     */
    private function getTowerProgress($userId) {
        $query = "SELECT * FROM tower_progress WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $progress = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($progress) {
            $floor = (int) $progress['current_floor'];
            $progress['enemy_power'] = GameRules::getTowerEnemyPower($floor);
            $progress['reward'] = GameRules::getTowerReward($floor);
            if ($progress['attempt_date'] !== date('Y-m-d')) {
                $progress['attempts_today'] = 0;
            }
        }

        return $progress;
    }

    /**
     * 确保竞技场资料存在 / Ensure an Arena profile exists
     * @param int $userId 用户ID / User ID
     * @return void
     */
    private function ensureArenaProfile($userId) {
        $query = "INSERT IGNORE INTO arena_profiles (user_id) VALUES (?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * 确保战斗之塔进度存在 / Ensure Battle Tower progress exists
     * @param int $userId 用户ID / User ID
     * @return void
     */
    private function ensureTowerProgress($userId) {
        $query = "INSERT IGNORE INTO tower_progress (user_id) VALUES (?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

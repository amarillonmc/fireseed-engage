<?php
// 种火集结号 - 十二门、银白之孔与赛季服务 / Fireseed Engage - Twelve Gateways, Silver Hole, and season service

class SeasonService {
    private $db;

    /**
     * 构造函数 / Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 获取当前赛季总览 / Get the current season overview
     * @param int $userId 用户ID / User ID
     * @return array 赛季数据 / Season data
     */
    public function getOverview($userId) {
        $season = $this->getCurrentSeason();

        return [
            'season' => $season,
            'sites' => $this->getWorldSites(),
            'ranking' => $season ? $this->getRanking((int) $season['season_id']) : [],
            'has_gateway_access' => $this->hasGatewayAccess((int) $userId),
            'armies' => Army::getUserArmies((int) $userId)
        ];
    }

    /**
     * 攻击十二门或银白之孔 / Assault a Gateway or the Silver Hole
     * @param int $userId 用户ID / User ID
     * @param int $siteId 地点ID / Site ID
     * @param int $armyId 军队ID / Army ID
     * @return array 战斗结果 / Battle result
     */
    public function assaultSite($userId, $siteId, $armyId) {
        $userId = (int) $userId;
        $siteId = (int) $siteId;
        $armyId = (int) $armyId;
        $army = new Army($armyId);
        if (!$army->isValid()
            || (int) $army->getOwnerId() !== $userId
            || $army->getStatus() !== 'idle'
            || $army->getCombatPower() <= 0) {
            return ['success' => false, 'message' => '只能使用自己的待命军队'];
        }

        $this->db->begin_transaction();

        try {
            $season = $this->getCurrentSeason(true);
            if (!$season || !in_array($season['status'], ['active', 'victory_countdown'], true)) {
                throw new RuntimeException('赛季当前处于冻结或重置阶段');
            }

            $query = "SELECT ws.*, mt.x, mt.y, mt.npc_garrison
                      FROM world_sites ws
                      INNER JOIN map_tiles mt ON mt.tile_id = ws.tile_id
                      WHERE ws.site_id = ? FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            $site = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$site) {
                throw new RuntimeException('世界地点不存在');
            }

            // 锁定并重新读取军队，不能使用等待赛季锁之前的旧快照 / Lock and reload the army after waiting for season locks
            $query = "SELECT owner_id, status, current_x, current_y
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
                throw new RuntimeException('军队状态已经变化');
            }

            // 锁定编成后重建对象，确保战力和随后扣除的兵量来自同一快照 / Lock the composition and rebuild from the same troop snapshot
            $query = "SELECT army_unit_id
                      FROM army_units
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法锁定军队编成');
            }
            $stmt->close();

            $army = new Army($armyId);
            if (!$army->isValid()
                || (int) $army->getOwnerId() !== $userId
                || $army->getStatus() !== 'idle'
                || $army->getCombatPower() <= 0) {
                throw new RuntimeException('军队已经失效或没有战斗力');
            }

            if ((int) $armyRow['current_x'] !== (int) $site['x']
                || (int) $armyRow['current_y'] !== (int) $site['y']) {
                throw new RuntimeException('军队必须先行军到目标地点');
            }
            if ($site['owner_id'] !== null && (int) $site['owner_id'] === $userId) {
                throw new RuntimeException('不能攻击自己占领的地点');
            }
            if ($site['owner_id'] !== null
                && $this->areUsersAllied($userId, (int) $site['owner_id'])) {
                throw new RuntimeException('不能攻击同联盟成员占领的地点');
            }
            if ($site['site_type'] === 'silver_hole'
                && !$this->hasGatewayAccessInTransaction($userId)) {
                throw new RuntimeException('必须先由自己或联盟成员占领至少一座十二门');
            }

            $attackerPower = $army->getCombatPower();
            $defenderGarrison = max(0, (int) $site['npc_garrison']);
            $defenderPower = max(
                1,
                $defenderGarrison * 2 + (int) ceil((int) $site['durability'] / 10)
            );
            $outcome = GameRules::calculateBattleOutcome($attackerPower, $defenderPower);
            $lossRates = GameRules::getBattleLossRates($outcome);
            $attackerLosses = $this->applyArmyLosses(
                $armyId,
                $lossRates['attacker']
            );
            $generalHpLosses = $this->applyAssignedGeneralHpLosses(
                $armyId,
                $lossRates['attacker']
            );
            $defenderLoss = GameRules::calculateBattleLosses(
                $defenderGarrison,
                $lossRates['defender']
            );
            $newGarrison = max(0, $defenderGarrison - $defenderLoss);
            $attackerWon = strpos($outcome, 'attacker_win') === 0;
            $durabilityDamage = $attackerWon
                ? max(1, $attackerPower)
                : max(1, (int) floor($attackerPower * 0.10));
            $newDurability = max(0, (int) $site['durability'] - $durabilityDamage);
            $captured = $attackerWon && $newGarrison === 0 && $newDurability === 0;

            if ($captured) {
                $installedGarrison = max(100, (int) floor($attackerPower / 4));
                $occupationStarted = $site['site_type'] === 'silver_hole'
                    ? date('Y-m-d H:i:s')
                    : null;
                $query = "UPDATE world_sites
                          SET owner_id = ?, durability = max_durability,
                              captured_at = NOW(), occupation_started_at = ?
                          WHERE site_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('isi', $userId, $occupationStarted, $siteId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('更新地点占领状态失败');
                }
                $stmt->close();

                $query = "UPDATE map_tiles
                          SET owner_id = ?, npc_garrison = ?
                          WHERE tile_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param(
                    'iii',
                    $userId,
                    $installedGarrison,
                    $site['tile_id']
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException('更新地点地图状态失败');
                }
                $stmt->close();

                if ($site['site_type'] === 'silver_hole') {
                    $query = "UPDATE seasons
                              SET status = 'victory_countdown',
                                  winner_id = NULL, victory_at = NULL, reset_at = NULL
                              WHERE season_id = ?";
                    $stmt = $this->db->prepare($query);
                    $stmt->bind_param('i', $season['season_id']);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('启动胜利倒计时失败');
                    }
                    $stmt->close();
                }
            } else {
                $query = "UPDATE world_sites SET durability = ? WHERE site_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $newDurability, $siteId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('更新地点耐久失败');
                }
                $stmt->close();

                $query = "UPDATE map_tiles SET npc_garrison = ? WHERE tile_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $newGarrison, $site['tile_id']);
                if (!$stmt->execute()) {
                    throw new RuntimeException('更新地点驻军失败');
                }
                $stmt->close();
            }

            $battleId = $this->recordBattle(
                $armyId,
                (int) $site['tile_id'],
                $outcome,
                $attackerLosses,
                [
                    'garrison_lost' => $defenderLoss,
                    'durability_damage' => $durabilityDamage
                ],
                ['general_hp_losses' => $generalHpLosses]
            );
            $this->recordBattleParticipant(
                $battleId,
                $userId,
                $site['owner_id'] === null ? null : (int) $site['owner_id'],
                $attackerPower,
                $defenderPower,
                $outcome
            );
            $gatewayScore = $captured && $site['site_type'] === 'gateway' ? 1 : 0;
            $this->addSeasonScore(
                (int) $season['season_id'],
                $userId,
                $attackerWon ? 1 : 0,
                $gatewayScore
            );
            $this->db->commit();

            $progressService = new ProgressService();
            $progressService->recordEvent(
                $userId,
                'battle_completed',
                1,
                'battle',
                $battleId
            );
            if ($attackerWon) {
                $progressService->recordEvent(
                    $userId,
                    'battle_won',
                    1,
                    'battle',
                    $battleId
                );
            }
            if ($gatewayScore > 0) {
                $progressService->recordEvent(
                    $userId,
                    'gateway_captured',
                    1,
                    'world_site',
                    $siteId
                );
            }

            return [
                'success' => true,
                'message' => $captured
                    ? '地点已占领'
                    : ($attackerWon ? '进攻获胜，目标尚未完全失守' : '进攻未能突破'),
                'outcome' => $outcome,
                'captured' => $captured,
                'attacker_power' => $attackerPower,
                'defender_power' => $defenderPower,
                'attacker_losses' => $attackerLosses,
                'general_hp_losses' => $generalHpLosses,
                'defender_garrison' => $captured ? 0 : $newGarrison,
                'durability' => $captured ? (int) $site['max_durability'] : $newDurability,
                'battle_id' => $battleId
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 检查胜利计时并在到期后开启新赛季 / Check victory timing and start a new season after reset
     * @return array 生命周期结果 / Lifecycle result
     */
    public function checkLifecycle() {
        $this->db->begin_transaction();

        try {
            // 锁序必须与进攻一致：赛季后银白之孔 / Match assault lock order: season, then Silver Hole
            $season = $this->getCurrentSeason(true);
            if (!$season) {
                $this->db->commit();
                return ['changed' => false, 'message' => '没有当前赛季'];
            }

            if ($season['status'] === 'victory_countdown') {
                $query = "SELECT owner_id, occupation_started_at,
                                 TIMESTAMPDIFF(
                                   SECOND,
                                   occupation_started_at,
                                   NOW()
                                 ) AS held_seconds
                          FROM world_sites
                          WHERE site_type = 'silver_hole'
                          LIMIT 1
                          FOR UPDATE";
                $result = executePreparedSql($this->db, $query);
                $silverHole = $result ? $result->fetch_assoc() : null;
                if (!$silverHole
                    || !$silverHole['owner_id']
                    || !$silverHole['occupation_started_at']) {
                    $query = "UPDATE seasons SET status = 'active'
                              WHERE season_id = ?
                                AND status = 'victory_countdown'";
                    $stmt = $this->db->prepare($query);
                    $stmt->bind_param('i', $season['season_id']);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('取消胜利计时失败');
                    }
                    $changed = $stmt->affected_rows === 1;
                    $stmt->close();
                    $this->db->commit();
                    return [
                        'changed' => $changed,
                        'message' => $changed ? '胜利计时已取消' : '赛季状态未变化'
                    ];
                }

                $requiredSeconds = VICTORY_OCCUPATION_DAYS * 86400;
                if ((int) $silverHole['held_seconds'] >= $requiredSeconds) {
                    $winnerId = (int) $silverHole['owner_id'];
                    $resetHours = (int) SEASON_RESET_DELAY_HOURS;
                    $query = "UPDATE seasons
                              SET status = 'reset_pending', winner_id = ?,
                                  victory_at = NOW(),
                                  reset_at = DATE_ADD(
                                    NOW(),
                                    INTERVAL {$resetHours} HOUR
                                  )
                              WHERE season_id = ?
                                AND status = 'victory_countdown'";
                    $stmt = $this->db->prepare($query);
                    $stmt->bind_param(
                        'ii',
                        $winnerId,
                        $season['season_id']
                    );
                    if (!$stmt->execute()) {
                        throw new RuntimeException('确定赛季胜者失败');
                    }
                    $changed = $stmt->affected_rows === 1;
                    $stmt->close();
                    if ($changed) {
                        // 仅在正式进入重置冻结期时停止世界行动 / Quiesce world actions only when reset freeze actually begins
                        $this->quiesceWorldOperationsForFreeze();
                    }
                    $this->db->commit();
                    return [
                        'changed' => $changed,
                        'message' => $changed
                            ? '赛季胜者已确定，进入冻结期'
                            : '赛季状态未变化'
                    ];
                }
            }

            $resetDue = false;
            if ($season['status'] === 'reset_pending' && $season['reset_at']) {
                $query = "SELECT reset_at <= NOW() AS reset_due
                          FROM seasons WHERE season_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $season['season_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $resetDue = $row && (int) $row['reset_due'] === 1;
                $stmt->close();
            }

            $seasonId = (int) $season['season_id'];
            $this->db->commit();
            if ($resetDue) {
                return $this->resetSeason($seasonId);
            }

            return ['changed' => false, 'message' => '赛季状态正常'];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['changed' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 获取当前赛季 / Get the current season
     * @param bool $forUpdate 是否锁定 / Whether to lock the row
     * @return array|null 赛季数据 / Season data
     */
    private function getCurrentSeason($forUpdate = false) {
        $query = "SELECT s.*, u.username AS winner_name
                  FROM seasons s
                  LEFT JOIN users u ON u.user_id = s.winner_id
                  WHERE s.ended_at IS NULL
                  ORDER BY s.season_number DESC LIMIT 1";
        if ($forUpdate) {
            $query .= " FOR UPDATE";
        }
        $result = executePreparedSql($this->db, $query);

        return $result ? $result->fetch_assoc() : null;
    }

    /**
     * 获取世界特殊地点 / Get world special sites
     * @return array 地点列表 / Site list
     */
    private function getWorldSites() {
        $query = "SELECT ws.*, mt.x, mt.y, mt.npc_garrison, u.username AS owner_name
                  FROM world_sites ws
                  INNER JOIN map_tiles mt ON mt.tile_id = ws.tile_id
                  LEFT JOIN users u ON u.user_id = ws.owner_id
                  ORDER BY FIELD(ws.site_type, 'silver_hole', 'gateway'), ws.site_id";
        $result = executePreparedSql($this->db, $query);
        $sites = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if ($row['site_type'] === 'silver_hole'
                    && $row['occupation_started_at']) {
                    $elapsed = max(0, time() - strtotime($row['occupation_started_at']));
                    $row['occupation_seconds'] = $elapsed;
                    $row['occupation_required_seconds'] = VICTORY_OCCUPATION_DAYS * 86400;
                }
                $sites[] = $row;
            }
        }

        return $sites;
    }

    /**
     * 获取赛季排行 / Get season rankings
     * @param int $seasonId 赛季ID / Season ID
     * @return array 排名 / Rankings
     */
    private function getRanking($seasonId) {
        $query = "SELECT ss.*, u.username,
                         (ss.territory_score + ss.battle_score * 2
                          + ss.gateway_score * 100 + ss.raid_score) AS total_score
                  FROM season_scores ss
                  INNER JOIN users u ON u.user_id = ss.user_id
                  WHERE ss.season_id = ?
                  ORDER BY total_score DESC, ss.updated_at ASC LIMIT 50";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $seasonId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ranking = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $ranking[] = $row;
            }
        }
        $stmt->close();

        return $ranking;
    }

    /**
     * 检查玩家或其联盟是否拥有十二门 / Check whether a player or alliance owns a Gateway
     * @param int $userId 用户ID / User ID
     * @return bool 是否拥有通行权 / Whether access is available
     */
    private function hasGatewayAccess($userId) {
        return $this->hasGatewayAccessInTransaction($userId);
    }

    /**
     * 在当前事务内检查十二门通行权 / Check Gateway access in the current transaction
     * @param int $userId 用户ID / User ID
     * @return bool 是否拥有通行权 / Whether access is available
     */
    private function hasGatewayAccessInTransaction($userId) {
        $query = "SELECT 1
                  FROM world_sites ws
                  LEFT JOIN alliance_members mine ON mine.user_id = ?
                  LEFT JOIN alliance_members owner_member
                    ON owner_member.user_id = ws.owner_id
                  WHERE ws.site_type = 'gateway'
                    AND (
                      ws.owner_id = ?
                      OR (
                        mine.alliance_id IS NOT NULL
                        AND owner_member.alliance_id = mine.alliance_id
                      )
                    )
                  LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $hasAccess = $result && $result->num_rows > 0;
        $stmt->close();

        return $hasAccess;
    }

    /**
     * 检查两名玩家是否在同一联盟 / Check whether two users share an alliance
     * @param int $firstUserId 第一玩家ID / First user ID
     * @param int $secondUserId 第二玩家ID / Second user ID
     * @return bool 是否同盟 / Whether they are allied
     */
    private function areUsersAllied($firstUserId, $secondUserId) {
        $query = "SELECT 1
                  FROM alliance_members first_member
                  INNER JOIN alliance_members second_member
                    ON second_member.alliance_id = first_member.alliance_id
                  WHERE first_member.user_id = ? AND second_member.user_id = ?
                  LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $firstUserId, $secondUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        $allied = $result && $result->num_rows > 0;
        $stmt->close();

        return $allied;
    }

    /**
     * 应用攻击方战损 / Apply attacker troop losses
     * @param int $armyId 军队ID / Army ID
     * @param float $lossRate 战损率 / Loss rate
     * @return array 战损明细 / Loss details
     */
    private function applyArmyLosses($armyId, $lossRate) {
        $query = "SELECT army_unit_id, soldier_type, quantity
                  FROM army_units WHERE army_id = ? FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $armyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $units = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $units[] = $row;
            }
        }
        $stmt->close();

        $losses = [];
        foreach ($units as $unit) {
            $loss = GameRules::calculateBattleLosses(
                (int) $unit['quantity'],
                $lossRate
            );
            $newQuantity = max(0, (int) $unit['quantity'] - $loss);
            $query = "UPDATE army_units SET quantity = ?
                      WHERE army_unit_id = ? AND army_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iii',
                $newQuantity,
                $unit['army_unit_id'],
                $armyId
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('应用军队战损失败');
            }
            $stmt->close();
            $losses[$unit['soldier_type']] = $loss;
        }
        $query = "DELETE FROM army_units WHERE army_id = ? AND quantity <= 0";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $armyId);
        $stmt->execute();
        $stmt->close();

        return $losses;
    }

    /**
     * 按战损率扣除参战武将HP / Reduce assigned-general HP from the battle loss rate
     * @param int $armyId 军队ID / Army ID
     * @param float $lossRate 战损率 / Battle loss rate
     * @return array 武将HP损失 / General HP losses
     */
    private function applyAssignedGeneralHpLosses($armyId, $lossRate) {
        $query = "SELECT g.general_id, g.hp, g.max_hp
                  FROM general_assignments ga
                  INNER JOIN generals g
                    ON g.general_id = ga.general_id
                  WHERE ga.assignment_type = 'army'
                    AND ga.target_id = ?
                    AND g.is_active = 1
                    AND g.hp > 0
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $armyId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法锁定参战武将');
        }
        $result = $stmt->get_result();
        $generals = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $generals[] = $row;
        }
        $stmt->close();

        $hpLossRate = min(0.50, max(0.02, (float) $lossRate * 0.50));
        $losses = [];
        foreach ($generals as $general) {
            $damage = max(
                1,
                (int) ceil((int) $general['max_hp'] * $hpLossRate)
            );
            $actualDamage = min((int) $general['hp'], $damage);
            $query = "UPDATE generals
                      SET hp = GREATEST(0, hp - ?)
                      WHERE general_id = ? AND hp > 0";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'ii',
                $actualDamage,
                $general['general_id']
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法应用武将HP损失');
            }
            $stmt->close();

            $query = "INSERT INTO general_progression
                        (general_id, last_hp_recovery)
                      VALUES (?, NOW())
                      ON DUPLICATE KEY UPDATE
                        last_hp_recovery = NOW()";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $general['general_id']);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法记录武将受伤时间');
            }
            $stmt->close();
            $losses[] = [
                'general_id' => (int) $general['general_id'],
                'hp_loss' => $actualDamage,
                'remaining_hp' => max(
                    0,
                    (int) $general['hp'] - $actualDamage
                )
            ];
        }

        return $losses;
    }

    /**
     * 写入战报 / Record a battle report
     * @param int $armyId 攻击军队ID / Attacking army ID
     * @param int $tileId 防守格子ID / Defending tile ID
     * @param string $outcome 结果 / Outcome
     * @param array $attackerLosses 攻击方损失 / Attacker losses
     * @param array $defenderLosses 防守方损失 / Defender losses
     * @param array $rewards 奖励与附加战果 / Rewards and supplemental outcome
     * @return int 战斗ID / Battle ID
     */
    private function recordBattle(
        $armyId,
        $tileId,
        $outcome,
        $attackerLosses,
        $defenderLosses,
        $rewards = []
    ) {
        $attackerJson = json_encode($attackerLosses, JSON_UNESCAPED_UNICODE);
        $defenderJson = json_encode($defenderLosses, JSON_UNESCAPED_UNICODE);
        $rewardsJson = json_encode($rewards, JSON_UNESCAPED_UNICODE);
        $query = "INSERT INTO battles
                    (attacker_army_id, defender_tile_id, battle_time, result,
                     attacker_losses, defender_losses, rewards)
                  VALUES (?, ?, NOW(), ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iissss',
            $armyId,
            $tileId,
            $outcome,
            $attackerJson,
            $defenderJson,
            $rewardsJson
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('写入战报失败');
        }
        $battleId = (int) $this->db->insert_id;
        $stmt->close();

        return $battleId;
    }

    /**
     * 写入战力快照 / Record a battle-power snapshot
     * @param int $battleId 战斗ID / Battle ID
     * @param int $attackerId 攻击玩家ID / Attacker user ID
     * @param int|null $defenderId 防守玩家ID / Defender user ID
     * @param int $attackerPower 攻击战力 / Attacker power
     * @param int $defenderPower 防守战力 / Defender power
     * @param string $outcome 结果 / Outcome
     * @return void
     */
    private function recordBattleParticipant(
        $battleId,
        $attackerId,
        $defenderId,
        $attackerPower,
        $defenderPower,
        $outcome
    ) {
        $details = json_encode(['outcome' => $outcome], JSON_UNESCAPED_UNICODE);
        $query = "INSERT INTO battle_participants
                    (battle_id, attacker_user_id, defender_user_id,
                     attacker_power, defender_power, counter_details)
                  VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iiiiis',
            $battleId,
            $attackerId,
            $defenderId,
            $attackerPower,
            $defenderPower,
            $details
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('写入战力快照失败');
        }
        $stmt->close();
    }

    /**
     * 增加赛季分数 / Add season scores
     * @param int $seasonId 赛季ID / Season ID
     * @param int $userId 用户ID / User ID
     * @param int $battleScore 战斗分 / Battle score
     * @param int $gatewayScore 十二门分 / Gateway score
     * @return void
     */
    private function addSeasonScore(
        $seasonId,
        $userId,
        $battleScore,
        $gatewayScore
    ) {
        $query = "INSERT INTO season_scores
                    (season_id, user_id, battle_score, gateway_score)
                  VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                    battle_score = LEAST(
                      2147483647,
                      battle_score + VALUES(battle_score)
                    ),
                    gateway_score = LEAST(
                      2147483647,
                      gateway_score + VALUES(gateway_score)
                    )";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iiii',
            $seasonId,
            $userId,
            $battleScore,
            $gatewayScore
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('更新赛季分数失败');
        }
        $stmt->close();
    }

    /**
     * 在赛季冻结开始时停止所有未结地图行动 / Stop unresolved world actions when season freeze begins
     * @return void
     */
    private function quiesceWorldOperationsForFreeze() {
        $resetHours = max(0, (int) SEASON_RESET_DELAY_HOURS);
        $statements = [
            [
                "DELETE FROM battles WHERE result = 'pending'",
                '取消冻结期待处理战斗失败'
            ],
            [
                'DELETE FROM alliance_operation_armies',
                '释放冻结期协同作战军队失败'
            ],
            [
                "UPDATE alliance_operations
                 SET status = 'cancelled'
                 WHERE status IN ('open', 'launched')",
                '取消冻结期协同作战失败'
            ],
            [
                "UPDATE armies
                 SET status = 'idle',
                     target_x = NULL, target_y = NULL,
                     departure_time = NULL, arrival_time = NULL,
                     return_time = NULL
                 WHERE status IN ('marching', 'returning')",
                '停止冻结期行军失败'
            ],
            [
                "UPDATE facilities
                 SET construction_time = CASE
                       WHEN construction_time IS NULL THEN NULL
                       ELSE DATE_ADD(
                         construction_time,
                         INTERVAL {$resetHours} HOUR
                       )
                     END,
                     upgrade_time = CASE
                       WHEN upgrade_time IS NULL THEN NULL
                       ELSE DATE_ADD(
                         upgrade_time,
                         INTERVAL {$resetHours} HOUR
                       )
                     END
                 WHERE construction_time IS NOT NULL
                    OR upgrade_time IS NOT NULL",
                '暂停冻结期设施计时失败'
            ],
            [
                "UPDATE soldiers
                 SET training_complete_time = DATE_ADD(
                       training_complete_time,
                       INTERVAL {$resetHours} HOUR
                     )
                 WHERE in_training > 0
                   AND training_complete_time IS NOT NULL",
                '暂停冻结期训练计时失败'
            ],
            [
                "UPDATE user_technologies
                 SET research_time = DATE_ADD(
                       research_time,
                       INTERVAL {$resetHours} HOUR
                     )
                 WHERE research_time IS NOT NULL",
                '暂停冻结期研究计时失败'
            ],
            [
                "UPDATE resources
                 SET last_update = DATE_ADD(
                       last_update,
                       INTERVAL {$resetHours} HOUR
                     )",
                '暂停冻结期资源生产失败'
            ],
            [
                "UPDATE cities
                 SET last_circuit_production = CASE
                       WHEN last_circuit_production IS NULL THEN NULL
                       ELSE DATE_ADD(
                         last_circuit_production,
                         INTERVAL {$resetHours} HOUR
                       )
                     END
                 WHERE last_circuit_production IS NOT NULL",
                '暂停冻结期思考回路生产失败'
            ],
            [
                "UPDATE map_tiles
                 SET last_collection_time = DATE_ADD(
                       last_collection_time,
                       INTERVAL {$resetHours} HOUR
                     )
                 WHERE last_collection_time IS NOT NULL",
                '暂停冻结期领地采集失败'
            ]
        ];

        foreach ($statements as $statement) {
            $stmt = $this->db->prepare($statement[0]);
            if (!$stmt) {
                throw new RuntimeException($statement[1]);
            }
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException($statement[1]);
            }
            $stmt->close();
        }
    }

    /**
     * 重置地图占领状态并创建下一赛季 / Reset occupation state and create the next season
     * @param int $seasonId 赛季ID / Season ID
     * @return array 重置结果 / Reset result
     */
    private function resetSeason($seasonId) {
        $this->db->begin_transaction();

        try {
            $query = "SELECT season_number FROM seasons
                      WHERE season_id = ? AND status = 'reset_pending' FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $seasonId);
            $stmt->execute();
            $result = $stmt->get_result();
            $season = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$season) {
                throw new RuntimeException('赛季不满足重置条件');
            }

            // 先取消仍在路上的旧赛季攻击，避免它们在新赛季重新结算 / Cancel in-flight old-season attacks before they can resolve in the new season
            if (!executePreparedSql(
                $this->db,
                "DELETE FROM battles WHERE result = 'pending'"
            )) {
                throw new RuntimeException('取消旧赛季待处理战斗失败');
            }
            if (!executePreparedSql(
                $this->db,
                "DELETE FROM alliance_operation_armies"
            )) {
                throw new RuntimeException('释放旧赛季协同作战军队失败');
            }
            if (!executePreparedSql(
                $this->db,
                "UPDATE alliance_operations
                 SET status = 'cancelled'
                 WHERE status IN ('open', 'launched')"
            )) {
                throw new RuntimeException('取消旧赛季协同作战失败');
            }

            // 当前赛季采用非破坏性重置，因此先把领地驻军送回每名玩家的主城 / This season reset is non-destructive, so return territory troops to each owner's main city first
            $query = "INSERT INTO soldiers
                         (city_id, type, level, quantity, in_training)
                      SELECT homes.city_id, g.soldier_type, MAX(g.level),
                             LEAST(2147483647, SUM(g.quantity)), 0
                      FROM territory_garrisons g
                      INNER JOIN (
                        SELECT owner_id,
                               COALESCE(
                                 MIN(CASE WHEN is_main_city = 1 THEN city_id END),
                                 MIN(city_id)
                               ) AS city_id
                        FROM cities
                        GROUP BY owner_id
                      ) homes ON homes.owner_id = g.owner_id
                      GROUP BY homes.city_id, g.soldier_type
                      ON DUPLICATE KEY UPDATE
                        level = GREATEST(level, VALUES(level)),
                        quantity = LEAST(
                          2147483647,
                          quantity + VALUES(quantity)
                        )";
            if (!executePreparedSql($this->db, $query)) {
                throw new RuntimeException('返还赛季领地驻军失败');
            }
            if (!executePreparedSql(
                $this->db,
                "DELETE FROM territory_garrisons"
            )) {
                throw new RuntimeException('清理领地驻军失败');
            }
            if (!executePreparedSql(
                $this->db,
                "UPDATE map_tiles SET owner_id = NULL
                 WHERE type IN ('empty', 'resource', 'npc_fort', 'special')"
            )) {
                throw new RuntimeException('清理地图占领状态失败');
            }
            if (!executePreparedSql(
                $this->db,
                "UPDATE world_sites
                 SET owner_id = NULL, durability = max_durability,
                     captured_at = NULL, occupation_started_at = NULL"
            )) {
                throw new RuntimeException('重置特殊地点失败');
            }
            if (!executePreparedSql(
                $this->db,
                "UPDATE arena_profiles SET season_points = 0"
            )) {
                throw new RuntimeException('重置竞技场赛季积分失败');
            }

            $gatewayGarrison = (int) round(
                NPC_FORT_BASE_GARRISON * pow(NPC_FORT_GARRISON_COEFFICIENT, 9)
            );
            $query = "UPDATE map_tiles mt
                      INNER JOIN world_sites ws ON ws.tile_id = mt.tile_id
                      SET mt.npc_garrison = CASE
                        WHEN ws.site_type = 'gateway' THEN ?
                        ELSE 1000000000
                      END";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $gatewayGarrison);
            if (!$stmt->execute()) {
                throw new RuntimeException('恢复特殊地点驻军失败');
            }
            $stmt->close();

            $query = "UPDATE armies a
                      INNER JOIN cities c ON c.city_id = a.city_id
                      SET a.status = 'idle', a.current_x = c.x, a.current_y = c.y,
                          a.target_x = NULL, a.target_y = NULL,
                          a.departure_time = NULL, a.arrival_time = NULL,
                          a.return_time = NULL";
            if (!executePreparedSql($this->db, $query)) {
                throw new RuntimeException('召回赛季军队失败');
            }

            $query = "UPDATE seasons SET ended_at = NOW() WHERE season_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $seasonId);
            if (!$stmt->execute()) {
                throw new RuntimeException('结束旧赛季失败');
            }
            $stmt->close();

            $nextNumber = (int) $season['season_number'] + 1;
            $query = "INSERT INTO seasons (season_number, status, started_at)
                      VALUES (?, 'active', NOW())";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $nextNumber);
            if (!$stmt->execute()) {
                throw new RuntimeException('创建新赛季失败');
            }
            $stmt->close();
            $this->db->commit();

            return [
                'changed' => true,
                'message' => '赛季已重置，武将、城池、资源与联盟均已保留',
                'season_number' => $nextNumber
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['changed' => false, 'message' => $e->getMessage()];
        }
    }
}

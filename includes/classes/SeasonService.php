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
            || $army->getStatus() !== 'idle') {
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
                || $army->getStatus() !== 'idle') {
                throw new RuntimeException('军队已经失效或没有战斗力');
            }

            $siteBattleDistance = abs(
                (int) $armyRow['current_x'] - (int) $site['x']
            ) + abs(
                (int) $armyRow['current_y'] - (int) $site['y']
            );
            $siteTargetTags = ['structure'];
            $siteTargetTags[] = $site['owner_id'] === null
                ? 'npc'
                : 'player';
            // 地点战要求军队已在目标坐标，故权威曼哈顿距离必须为0 / Site assaults require the army at the target coordinates, so authoritative Manhattan distance must be zero
            $siteBattleContext = [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => $siteTargetTags,
                'distance' => $siteBattleDistance
            ];
            if ($siteBattleDistance !== 0) {
                throw new RuntimeException('军队必须先行军到目标地点');
            }
            if ($army->getCombatPower($siteBattleContext) <= 0) {
                throw new RuntimeException('军队已经失效或没有战斗力');
            }
            if ($site['owner_id'] !== null && (int) $site['owner_id'] === $userId) {
                throw new RuntimeException('不能攻击自己占领的地点');
            }
            if ($site['owner_id'] !== null
                && $this->areUsersInSameForce(
                    $userId,
                    (int) $site['owner_id']
                )) {
                throw new RuntimeException(
                    '不能攻击同势力成员占领的地点 / Cannot attack a site held by the same force'
                );
            }
            if ($site['site_type'] === 'silver_hole'
                && !$this->hasGatewayAccessInTransaction($userId)) {
                throw new RuntimeException(
                    '必须先由自己或同势力成员占领至少一座十二门 / Your force must control a Gateway first'
                );
            }

            $attackerPower = $army->getCombatPower($siteBattleContext);
            $defenderGarrison = max(0, (int) $site['npc_garrison']);
            $defenderPower = max(
                1,
                $defenderGarrison * 2 + (int) ceil((int) $site['durability'] / 10)
            );
            $outcome = GameRules::calculateBattleOutcome($attackerPower, $defenderPower);
            $lossRates = GameRules::getBattleLossRates($outcome);
            $attackerDamageReduction = $army->getDamageReduction(
                $siteBattleContext
            );
            $attackerLossRate = $this->applyDamageReductionToLossRate(
                $lossRates['attacker'],
                $attackerDamageReduction
            );
            $attackerLosses = $this->applyArmyLosses(
                $armyId,
                $attackerLossRate
            );
            $generalHpLosses = $this->applyAssignedGeneralHpLosses(
                $armyId,
                $attackerLossRate
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
                    // 胜利归属使用当前有效势力领袖，附属玩家不能单独截留胜利。 / Attribute victory to the current effective-force owner so a vassal cannot retain it separately.
                    $vassalService = new VassalService();
                    $winnerId = (int) $vassalService
                        ->getEffectiveForceOwnerId(
                            (int) $silverHole['owner_id']
                        );
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
        $query = "SELECT ss.user_id, ss.territory_score,
                         ss.battle_score, ss.gateway_score,
                         ss.raid_score, ss.updated_at
                  FROM season_scores ss
                  WHERE ss.season_id = ?
                  ORDER BY ss.user_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $seasonId);
        $stmt->execute();
        $result = $stmt->get_result();
        $scoreRows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $scoreRows[] = $row;
            }
        }
        $stmt->close();

        if (empty($scoreRows)) {
            return [];
        }

        $vassalService = new VassalService();
        $ownerIds = $vassalService->getEffectiveForceOwnerIds(
            array_column($scoreRows, 'user_id')
        );
        $grouped = [];
        foreach ($scoreRows as $row) {
            $userId = (int) $row['user_id'];
            $ownerId = isset($ownerIds[$userId])
                ? (int) $ownerIds[$userId]
                : $userId;
            if (!isset($grouped[$ownerId])) {
                $grouped[$ownerId] = [
                    'user_id' => $ownerId,
                    'contributor_ids' => [],
                    'territory_score' => 0,
                    'battle_score' => 0,
                    'gateway_score' => 0,
                    'raid_score' => 0,
                    'updated_at' => (string) $row['updated_at']
                ];
            }

            $grouped[$ownerId]['contributor_ids'][$userId] = true;
            foreach ([
                'territory_score',
                'battle_score',
                'gateway_score',
                'raid_score'
            ] as $scoreColumn) {
                $grouped[$ownerId][$scoreColumn] +=
                    (int) $row[$scoreColumn];
            }
            if (strcmp(
                (string) $row['updated_at'],
                (string) $grouped[$ownerId]['updated_at']
            ) < 0) {
                $grouped[$ownerId]['updated_at'] =
                    (string) $row['updated_at'];
            }
        }

        $usernames = $this->readUsernamesByIds(array_keys($grouped));
        $ranking = [];
        foreach ($grouped as $ownerId => $row) {
            $row['username'] = isset($usernames[$ownerId])
                ? $usernames[$ownerId]
                : (string) $ownerId;
            $row['contributor_count'] = count($row['contributor_ids']);
            unset($row['contributor_ids']);
            $row['total_score'] = (int) $row['territory_score']
                + (int) $row['battle_score'] * 2
                + (int) $row['gateway_score'] * 100
                + (int) $row['raid_score'];
            $ranking[] = $row;
        }

        usort($ranking, function ($left, $right) {
            $comparison = (int) $right['total_score']
                <=> (int) $left['total_score'];
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = strcmp(
                (string) $left['updated_at'],
                (string) $right['updated_at']
            );
            return $comparison !== 0
                ? $comparison
                : ((int) $left['user_id'] <=> (int) $right['user_id']);
        });

        return array_slice($ranking, 0, 50);
    }

    /**
     * 批量读取势力领袖显示名 / Read force-owner display names in batches
     * @param array $userIds 玩家ID / User IDs
     * @return array 以玩家ID为键的显示名 / Display names keyed by user ID
     */
    private function readUsernamesByIds($userIds) {
        $usernames = [];
        foreach (array_chunk(array_values($userIds), 500) as $chunk) {
            if (empty($chunk)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $query = "SELECT user_id, username
                      FROM users
                      WHERE user_id IN ({$placeholders})";
            $stmt = $this->db->prepare($query);
            $parameters = array_map('intval', $chunk);
            $types = str_repeat('i', count($parameters));
            $stmt->bind_param($types, ...$parameters);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $usernames[(int) $row['user_id']] =
                    (string) $row['username'];
            }
            $stmt->close();
        }

        return $usernames;
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
        $query = "SELECT owner_id
                  FROM world_sites
                  WHERE site_type = 'gateway' AND owner_id IS NOT NULL
                  ORDER BY site_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $vassalService = new VassalService();
        $hasAccess = false;
        while ($result && ($row = $result->fetch_assoc())) {
            if ($vassalService->areUsersInSameForce(
                $userId,
                (int) $row['owner_id']
            )) {
                $hasAccess = true;
                break;
            }
        }
        $stmt->close();

        return $hasAccess;
    }

    /**
     * 检查两名玩家是否归属同一有效势力 / Check whether two users belong to the same effective force
     * @param int $firstUserId 第一玩家ID / First user ID
     * @param int $secondUserId 第二玩家ID / Second user ID
     * @return bool 是否同势力 / Whether they share an effective force
     */
    private function areUsersInSameForce($firstUserId, $secondUserId) {
        $vassalService = new VassalService();
        return $vassalService->areUsersInSameForce(
            $firstUserId,
            $secondUserId
        );
    }

    /**
     * 对战损率应用军队技能减免 / Applies army-skill reduction to a casualty rate
     * @param float $lossRate 原始战损率 / Raw casualty rate
     * @param float $reductionPercent 减免百分比 / Reduction percentage
     * @return float 封顶后的战损率 / Bounded casualty rate
     */
    private function applyDamageReductionToLossRate(
        $lossRate,
        $reductionPercent
    ) {
        $boundedRate = min(1.0, max(0.0, (float) $lossRate));
        $boundedReduction = min(
            Army::MAX_DAMAGE_REDUCTION_PERCENT,
            max(0.0, (float) $reductionPercent)
        );
        return $boundedRate * (1.0 - $boundedReduction / 100.0);
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
                "UPDATE resource_production_states
                 SET settled_at = DATE_ADD(
                       settled_at,
                       INTERVAL {$resetHours} HOUR
                     ),
                     dirty_at = CASE
                       WHEN dirty_at IS NULL THEN NULL
                       ELSE DATE_ADD(
                         dirty_at,
                         INTERVAL {$resetHours} HOUR
                       )
                     END",
                '暂停冻结期独立资源生产游标失败'
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
     * 原子重建赛季世界并创建下一赛季 / Atomically rebuild the world and create the next season
     * @param int $seasonId 赛季ID / Season ID
     * @return array 重置结果 / Reset result
     */
    private function resetSeason($seasonId) {
        $this->db->begin_transaction();

        try {
            $query = "SELECT season_number FROM seasons
                      WHERE season_id = ? AND status = 'reset_pending'
                        AND ended_at IS NULL
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $seasonId);
            $stmt->execute();
            $result = $stmt->get_result();
            $season = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$season) {
                throw new RuntimeException(
                    '赛季不满足重置条件 / Season is not eligible for reset'
                );
            }

            $userIds = $this->lockAllPlayersForSeasonReset();

            // 同盟本体、成员与职位跨赛季保留；其余同盟态全部重新开始。
            // Alliance identity, membership, and roles persist; all seasonal
            // alliance state starts over.
            $this->executeSeasonResetStatement(
                'DELETE FROM alliance_operation_armies',
                '清理联盟协同军队失败 / Failed to clear alliance operation armies'
            );
            $this->executeSeasonResetStatement(
                'DELETE FROM alliance_operations',
                '清理联盟行动失败 / Failed to clear alliance operations'
            );
            $this->executeSeasonResetStatement(
                'DELETE FROM alliance_applications',
                '清理联盟申请失败 / Failed to clear alliance applications'
            );
            $this->executeSeasonResetStatement(
                'DELETE FROM alliance_aid_log',
                '清理联盟援助记录失败 / Failed to clear alliance aid records'
            );
            $this->restoreVassalAllianceMembershipsForSeasonReset();
            $this->executeSeasonResetStatement(
                'DELETE FROM vassal_relations',
                '清理附属关系失败 / Failed to clear vassalage'
            );
            $this->executeSeasonResetStatement(
                'UPDATE alliance_members SET contribution = 0',
                '重置联盟贡献失败 / Failed to reset alliance contribution'
            );
            $this->executeSeasonResetStatement(
                'UPDATE alliances SET level = 1, experience = 0',
                '重置联盟成长失败 / Failed to reset alliance progression'
            );

            // 武将与技能成长保留，但一切城池、军队分配及临时技能态随赛季清空。
            // General and skill progression persists, while city/army
            // assignments and transient effects reset.
            $this->executeSeasonResetStatement(
                'DELETE FROM general_assignments',
                '清理武将分配失败 / Failed to clear general assignments'
            );
            $this->executeSeasonResetStatement(
                'DELETE FROM active_skill_effects',
                '清理临时技能效果失败 / Failed to clear temporary skill effects'
            );
            $this->executeSeasonResetStatement(
                'DELETE FROM skill_cooldowns',
                '清理技能冷却失败 / Failed to clear skill cooldowns'
            );

            // 任务、战斗、挑战与侦察记录都是赛季进度；成就与长期材料不在此列。
            // Quest, battle, challenge, and scouting state is seasonal;
            // achievements and long-term items are deliberately untouched.
            $seasonalProgressStatements = [
                [
                    'DELETE FROM user_quests',
                    '重置任务进度失败 / Failed to reset quest progress'
                ],
                [
                    'DELETE FROM gameplay_events',
                    '重置玩法事件失败 / Failed to reset gameplay events'
                ],
                [
                    'DELETE FROM raid_participation',
                    '重置讨伐参与失败 / Failed to reset raid participation'
                ],
                [
                    'DELETE FROM raid_events',
                    '重置讨伐事件失败 / Failed to reset raid events'
                ],
                [
                    'DELETE FROM arena_battles',
                    '重置竞技场战报失败 / Failed to reset arena battles'
                ],
                [
                    "UPDATE arena_profiles
                     SET defense_army_id = NULL, rating = 1000,
                         wins = 0, losses = 0, season_points = 0",
                    '重置竞技场档案失败 / Failed to reset arena profiles'
                ],
                [
                    'DELETE FROM tower_progress',
                    '重置高塔进度失败 / Failed to reset tower progress'
                ],
                [
                    'DELETE FROM scouting_missions',
                    '重置侦察记录失败 / Failed to reset scouting records'
                ],
                [
                    'DELETE FROM prisoners',
                    '重置俘虏失败 / Failed to reset prisoners'
                ],
                [
                    'DELETE FROM battles',
                    '重置战斗记录失败 / Failed to reset battles'
                ]
            ];
            foreach ($seasonalProgressStatements as $statement) {
                $this->executeSeasonResetStatement(
                    $statement[0],
                    $statement[1]
                );
            }

            // 仅清除赛季科研；永久科研与由其提供的上限必须保留。
            // Remove only seasonal research and preserve permanent effects.
            $this->executeSeasonResetStatement(
                "DELETE ut
                 FROM user_technologies ut
                 INNER JOIN technologies t ON t.tech_id = ut.tech_id
                 WHERE t.scope = 'seasonal'",
                '重置赛季科研失败 / Failed to reset seasonal research'
            );
            foreach ($userIds as $userId) {
                if (class_exists('TechnologyEffectService')
                    && !TechnologyEffectService::synchronizePlayerLimits(
                        $userId
                    )) {
                    throw new RuntimeException(
                        '同步永久科研上限失败 / Failed to synchronize permanent research caps'
                    );
                }
            }

            // 先解除引用，再删除全部赛季军事与城市实体。 / Release references before deleting seasonal military and city entities.
            $this->executeSeasonResetStatement(
                'DELETE FROM territory_garrisons',
                '清理领地驻军失败 / Failed to clear territory garrisons'
            );
            $this->executeSeasonResetStatement(
                'DELETE FROM armies',
                '清理军队失败 / Failed to clear armies'
            );
            $this->executeSeasonResetStatement(
                'DELETE FROM cities',
                '清理城市失败 / Failed to clear cities'
            );

            $initialCircuit = $this->readBoundedSeasonConfig(
                'initial_circuit_points',
                1
            );
            $query = "UPDATE users
                      SET level = 1,
                          circuit_points = LEAST(max_circuit_points, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $initialCircuit);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    '重置思考回路失败 / Failed to reset Circuit Points'
                );
            }
            $stmt->close();

            // 技能点和长期道具保留；功勋及竞技场代币归零。 / Preserve skill points and items; reset seasonal wallet balances.
            $this->executeSeasonResetStatement(
                'UPDATE gameplay_wallets
                 SET skill_points = skill_points,
                     merit_points = 0, arena_tokens = 0',
                '重置赛季钱包失败 / Failed to reset seasonal wallet balances'
            );
            $this->resetResourceWalletsForNewSeason();

            // 地图替换处于同一事务；生成失败会完整恢复旧地图、城市和资源。
            // World replacement shares this transaction, so any generation
            // failure restores the former map, cities, and balances.
            $mapGenerator = new MapGenerator();
            $mapGenerator->regenerateMapInCurrentTransaction();
            foreach ($userIds as $userId) {
                City::createInitialPlayerCityInCurrentTransaction($userId);
            }

            $query = "UPDATE seasons
                      SET status = 'won', ended_at = NOW()
                      WHERE season_id = ? AND status = 'reset_pending'
                        AND ended_at IS NULL";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $seasonId);
            $ended = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$ended) {
                throw new RuntimeException(
                    '结束旧赛季失败 / Failed to close the former season'
                );
            }

            $nextNumber = (int) $season['season_number'] + 1;
            $query = "INSERT INTO seasons (season_number, status, started_at)
                      VALUES (?, 'active', NOW())";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $nextNumber);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    '创建新赛季失败 / Failed to create the next season'
                );
            }
            $stmt->close();
            $this->db->commit();

            return [
                'changed' => true,
                'message' => '赛季已原子重建；长期资产与联盟关系已保留',
                'season_number' => $nextNumber
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['changed' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 锁定并列出全部玩家 / Lock and list every player
     * @return array 玩家ID / User IDs
     */
    private function lockAllPlayersForSeasonReset() {
        $query = "SELECT user_id FROM users ORDER BY user_id FOR UPDATE";
        $stmt = $this->db->prepare($query);
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            throw new RuntimeException(
                '锁定赛季玩家失败 / Failed to lock players for season reset'
            );
        }
        $result = $stmt->get_result();
        $userIds = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $userIds[] = (int) $row['user_id'];
        }
        $stmt->close();
        return $userIds;
    }

    /**
     * 在清除附属状态前恢复原联盟社交关系 / Restore former alliance ties before clearing vassalage
     * @return void
     */
    private function restoreVassalAllianceMembershipsForSeasonReset() {
        $query = "SELECT vassal_id, previous_alliance_id,
                         previous_alliance_role, previous_alliance_joined_at
                  FROM vassal_relations
                  WHERE status = 'active'
                    AND previous_alliance_id IS NOT NULL
                  ORDER BY previous_alliance_id, vassal_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            throw new RuntimeException(
                '读取附属联盟关系失败 / Failed to read vassal alliance ties'
            );
        }
        $result = $stmt->get_result();
        $relations = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $relations[] = $row;
        }
        $stmt->close();

        foreach ($relations as $relation) {
            $allianceId = (int) $relation['previous_alliance_id'];
            $userId = (int) $relation['vassal_id'];
            $query = "SELECT leader_id
                      FROM alliances
                      WHERE alliance_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $allianceId);
            $stmt->execute();
            $result = $stmt->get_result();
            $alliance = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$alliance) {
                continue;
            }

            $query = "SELECT member_id
                      FROM alliance_members
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $hasMembership = $result && $result->num_rows > 0;
            $stmt->close();
            if ($hasMembership) {
                continue;
            }

            $role = (string) $relation['previous_alliance_role'];
            if (!in_array($role, ['leader', 'officer', 'member'], true)) {
                $role = 'member';
            }
            if ($alliance['leader_id'] === null) {
                $role = 'leader';
                $query = "UPDATE alliances
                          SET leader_id = ?
                          WHERE alliance_id = ? AND leader_id IS NULL";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $userId, $allianceId);
                $restoredLeader = $stmt->execute()
                    && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$restoredLeader) {
                    throw new RuntimeException(
                        '恢复联盟盟主失败 / Failed to restore alliance leader'
                    );
                }
            } elseif ($role === 'leader') {
                $role = 'officer';
            }

            $joinedAt = $relation['previous_alliance_joined_at']
                ?: date('Y-m-d H:i:s');
            $query = "INSERT INTO alliance_members
                         (alliance_id, user_id, role, contribution, joined_at)
                      VALUES (?, ?, ?, 0, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iiss',
                $allianceId,
                $userId,
                $role,
                $joinedAt
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    '恢复原联盟身份失败 / Failed to restore former alliance membership'
                );
            }
            $stmt->close();
        }
    }

    /**
     * 执行无参数赛季重置语句 / Execute a parameterless season-reset statement
     * @param string $query SQL语句 / SQL statement
     * @param string $message 失败消息 / Failure message
     * @return void
     */
    private function executeSeasonResetStatement($query, $message) {
        $stmt = $this->db->prepare($query);
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            throw new RuntimeException($message);
        }
        $stmt->close();
    }

    /**
     * 读取非负整数赛季配置 / Read a bounded non-negative season setting
     * @param string $key 配置键 / Configuration key
     * @param int $default 默认值 / Default value
     * @return int 配置值 / Configuration value
     */
    private function readBoundedSeasonConfig($key, $default) {
        $value = GameConfig::get($key, $default);
        if (!is_numeric($value)) {
            $value = $default;
        }
        return max(0, min(2147483647, (int) $value));
    }

    /**
     * 重置四色资源并发放一次赛季亮夜奖励 / Reset seasonal resources and grant Bright/Night once
     * @return void
     */
    private function resetResourceWalletsForNewSeason() {
        $warm = $this->readBoundedSeasonConfig(
            'initial_warm_crystal',
            1000
        );
        $cold = $this->readBoundedSeasonConfig(
            'initial_cold_crystal',
            1000
        );
        $green = $this->readBoundedSeasonConfig(
            'initial_green_crystal',
            1000
        );
        $day = $this->readBoundedSeasonConfig(
            'initial_day_crystal',
            1000
        );
        $brightGrant = $this->readBoundedSeasonConfig(
            'season_start_bright_grant',
            1000
        );
        $nightGrant = $this->readBoundedSeasonConfig(
            'season_start_night_grant',
            1000
        );

        $maximumBrightBeforeGrant = 2147483647 - $brightGrant;
        $maximumNightBeforeGrant = 2147483647 - $nightGrant;
        $query = "SELECT resource_id
                  FROM resources
                  WHERE bright_crystal > ? OR night_crystal > ?
                  ORDER BY resource_id
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'ii',
            $maximumBrightBeforeGrant,
            $maximumNightBeforeGrant
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '检查赛季奖励余额失败 / Failed to validate season grants'
            );
        }
        $result = $stmt->get_result();
        $wouldOverflow = $result && $result->num_rows > 0;
        $stmt->close();
        if ($wouldOverflow) {
            throw new RuntimeException(
                '赛季开始奖励会导致资源溢出 / Season-start grant would overflow'
            );
        }

        $query = "UPDATE resources
                  SET warm_crystal = ?, cold_crystal = ?,
                      green_crystal = ?, day_crystal = ?,
                      warm_production_remainder = 0,
                      cold_production_remainder = 0,
                      green_production_remainder = 0,
                      day_production_remainder = 0,
                      bright_crystal = bright_crystal + ?,
                      night_crystal = night_crystal + ?,
                      last_update = NOW()";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iiiiii',
            $warm,
            $cold,
            $green,
            $day,
            $brightGrant,
            $nightGrant
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '重置赛季资源失败 / Failed to reset seasonal resources'
            );
        }
        $stmt->close();
    }
}

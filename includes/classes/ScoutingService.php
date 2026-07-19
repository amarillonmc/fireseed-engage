<?php
// 种火集结号 - 侦察任务服务 / Fireseed Engage - Scouting mission service

class ScoutingService {
    const STATUS_LAUNCHED = 'launched';
    const STATUS_SUCCEEDED = 'succeeded';
    const STATUS_FAILED = 'failed';

    private $db;

    /**
     * 创建侦察任务服务 / Create the scouting mission service
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 统计纯侦察编成的兵力；混编、空编或零兵力均返回零 / Count scouts in an exclusive scout composition; mixed, empty, or zero forces return zero
     *
     * @param array $units 军队单位 / Army units
     * @return int 可执行任务的侦察兵数量 / Eligible scout count
     */
    public static function countExclusiveScouts($units) {
        if (!is_array($units) || empty($units)) {
            return 0;
        }

        $total = 0;
        foreach ($units as $unit) {
            if (!is_array($unit)
                || !isset($unit['soldier_type'], $unit['quantity'])
                || (string) $unit['soldier_type'] !== 'scout') {
                return 0;
            }

            $quantity = (int) $unit['quantity'];
            if ($quantity <= 0) {
                return 0;
            }
            $total += $quantity;
            if ($total > 2147483647) {
                return 2147483647;
            }
        }

        return $total;
    }

    /**
     * 按NPC守军的百分之五计算反侦察兵力，且至少为一 / Calculate NPC counter-scouts as five percent of the garrison, with a minimum of one
     *
     * @param int $npcGarrison NPC守军 / NPC garrison
     * @return int 反侦察兵力 / Counter-scout count
     */
    public static function calculateNpcCounterScouts($npcGarrison) {
        return max(1, (int) ceil(max(0, (int) $npcGarrison) * 0.05));
    }

    /**
     * 只有派出侦察兵严格多于反侦察兵力时才成功 / Succeed only when dispatched scouts strictly outnumber counter-scouts
     *
     * @param int $sentScouts 派出侦察兵 / Dispatched scouts
     * @param int $counterScouts 反侦察兵 / Counter-scouts
     * @return bool 是否成功 / Whether scouting succeeds
     */
    public static function doesScoutingSucceed($sentScouts, $counterScouts) {
        return (int) $sentScouts > (int) $counterScouts;
    }

    /**
     * 获取玩家可派出的纯侦察待命军队 / Get the user's idle scout-only armies
     *
     * @param int $userId 玩家ID / User ID
     * @return array 军队摘要 / Army summaries
     */
    public function getEligibleArmies($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return [];
        }

        $query = "SELECT a.army_id, a.name, a.current_x, a.current_y,
                         SUM(au.quantity) AS scout_count
                  FROM armies a
                  INNER JOIN army_units au ON au.army_id = a.army_id
                  WHERE a.owner_id = ? AND a.status = 'idle'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM battles b
                        WHERE b.attacker_army_id = a.army_id
                          AND b.result = 'pending'
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM scouting_missions sm
                        WHERE sm.army_id = a.army_id
                          AND sm.status = 'launched'
                    )
                  GROUP BY a.army_id, a.name, a.current_x, a.current_y
                  HAVING SUM(
                      CASE
                          WHEN au.soldier_type <> 'scout' OR au.quantity <= 0
                          THEN 1
                          ELSE 0
                      END
                  ) = 0
                    AND SUM(au.quantity) > 0
                  ORDER BY a.army_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $armies = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $armies[] = [
                'army_id' => (int) $row['army_id'],
                'name' => (string) $row['name'],
                'current_x' => (int) $row['current_x'],
                'current_y' => (int) $row['current_y'],
                'scout_count' => (int) $row['scout_count']
            ];
        }
        $stmt->close();

        return $armies;
    }

    /**
     * 获取可见的敌对城池与NPC据点摘要 / Get summaries of visible hostile cities and NPC forts
     *
     * @param int $userId 玩家ID / User ID
     * @param int $limit 最大条数 / Maximum rows
     * @return array 目标摘要 / Target summaries
     */
    public function getDiscoveredTargets($userId, $limit = 200) {
        $userId = (int) $userId;
        $limit = max(1, min(500, (int) $limit));
        if ($userId <= 0) {
            return [];
        }

        $query = "SELECT mt.tile_id, mt.x, mt.y, mt.type, mt.subtype,
                         mt.npc_level, mt.npc_garrison,
                         COALESCE(c.owner_id, mt.owner_id) AS target_owner_id,
                         c.name AS city_name,
                         ws.display_name AS site_name
                  FROM map_tiles mt
                  LEFT JOIN cities c
                    ON mt.type = 'player_city'
                   AND c.x = mt.x AND c.y = mt.y
                  LEFT JOIN world_sites ws ON ws.tile_id = mt.tile_id
                  WHERE mt.is_visible = 1
                    AND mt.type IN ('player_city', 'npc_fort')
                    AND (
                        COALESCE(c.owner_id, mt.owner_id) IS NULL
                        OR COALESCE(c.owner_id, mt.owner_id) <> ?
                    )
                  ORDER BY mt.type, mt.tile_id
                  LIMIT ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $targets = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $targets[] = [
                'tile_id' => (int) $row['tile_id'],
                'x' => (int) $row['x'],
                'y' => (int) $row['y'],
                'type' => (string) $row['type'],
                'name' => $this->getTargetDisplayName($row),
                'level' => $row['type'] === 'npc_fort'
                    ? max(1, (int) $row['npc_level'])
                    : null
            ];
        }
        $stmt->close();

        return $targets;
    }

    /**
     * 获取玩家自己的侦察任务与报告 / Get the user's own scouting missions and reports
     *
     * @param int $userId 玩家ID / User ID
     * @param int $limit 最大条数 / Maximum rows
     * @return array 任务记录 / Mission records
     */
    public function getUserMissions($userId, $limit = 50) {
        $userId = (int) $userId;
        $limit = max(1, min(100, (int) $limit));
        if ($userId <= 0) {
            return [];
        }

        $query = "SELECT sm.*, a.name AS army_name,
                         mt.x AS target_x, mt.y AS target_y,
                         mt.type AS target_type, mt.subtype AS target_subtype,
                         c.name AS city_name, ws.display_name AS site_name
                  FROM scouting_missions sm
                  LEFT JOIN armies a ON a.army_id = sm.army_id
                  LEFT JOIN map_tiles mt ON mt.tile_id = sm.target_tile_id
                  LEFT JOIN cities c
                    ON mt.type = 'player_city'
                   AND c.x = mt.x AND c.y = mt.y
                  LEFT JOIN world_sites ws ON ws.tile_id = mt.tile_id
                  WHERE sm.user_id = ?
                  ORDER BY sm.mission_id DESC
                  LIMIT ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $missions = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $report = null;
            if ($row['report_json'] !== null && $row['report_json'] !== '') {
                $decoded = json_decode($row['report_json'], true);
                if (is_array($decoded)) {
                    $report = $decoded;
                }
            }

            $missions[] = [
                'mission_id' => (int) $row['mission_id'],
                'army_id' => $row['army_id'] === null
                    ? null
                    : (int) $row['army_id'],
                'army_name' => $row['army_name'] === null
                    ? '已解散军队'
                    : (string) $row['army_name'],
                'target_x' => $row['target_x'] === null
                    ? null
                    : (int) $row['target_x'],
                'target_y' => $row['target_y'] === null
                    ? null
                    : (int) $row['target_y'],
                'target_name' => $this->getTargetDisplayName($row),
                'status' => (string) $row['status'],
                'outcome' => $row['outcome'],
                'report' => $report,
                'launched_at' => (string) $row['launched_at'],
                'arrival_at' => (string) $row['arrival_at'],
                'resolved_at' => $row['resolved_at']
            ];
        }
        $stmt->close();

        return $missions;
    }

    /**
     * 在锁内重新解析坐标并发起侦察任务 / Resolve coordinates again under locks and launch a scouting mission
     *
     * @param int $userId 玩家ID / User ID
     * @param int $armyId 军队ID / Army ID
     * @param int $targetX 目标X / Target X
     * @param int $targetY 目标Y / Target Y
     * @return array 操作结果 / Operation result
     */
    public function launchMission($userId, $armyId, $targetX, $targetY) {
        $userId = (int) $userId;
        $armyId = (int) $armyId;
        $targetX = (int) $targetX;
        $targetY = (int) $targetY;
        if ($userId <= 0
            || $armyId <= 0
            || $targetX < 0
            || $targetX >= MAP_WIDTH
            || $targetY < 0
            || $targetY >= MAP_HEIGHT) {
            return $this->failure('侦察参数无效');
        }

        $this->db->begin_transaction();
        try {
            // 先锁赛季再锁军队，保证冻结切换与派遣互斥 / Lock the season before the army so freeze transitions and dispatch are mutually exclusive
            if ($this->lockGameplaySeasonIsFrozen()) {
                throw new DomainException(getSeasonGameplayFreezeMessage());
            }

            $query = "SELECT a.army_id, a.owner_id, a.status,
                             a.current_x, a.current_y, a.city_id,
                             EXISTS(
                                 SELECT 1
                                 FROM battles b
                                 WHERE b.attacker_army_id = a.army_id
                                   AND b.result = 'pending'
                             ) AS has_pending_battle
                      FROM armies a
                      WHERE a.army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $armyRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$armyRow
                || (int) $armyRow['owner_id'] !== $userId
                || $armyRow['status'] !== 'idle'
                || (int) $armyRow['has_pending_battle'] !== 0) {
                throw new DomainException('只能派遣自己拥有且没有待结算战斗的待命军队');
            }

            $query = "SELECT soldier_type, quantity
                      FROM army_units
                      WHERE army_id = ?
                      ORDER BY army_unit_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $unitResult = $stmt->get_result();
            $units = [];
            while ($unitResult && ($row = $unitResult->fetch_assoc())) {
                $units[] = $row;
            }
            $stmt->close();
            $scoutCount = self::countExclusiveScouts($units);
            if ($scoutCount <= 0) {
                throw new DomainException('侦察任务只能由数量大于零的纯侦察兵军队执行');
            }

            $query = "SELECT mission_id
                      FROM scouting_missions
                      WHERE army_id = ? AND status = 'launched'
                      LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $missionResult = $stmt->get_result();
            $hasMission = $missionResult && $missionResult->num_rows > 0;
            $stmt->close();
            if ($hasMission) {
                throw new DomainException('该军队已有进行中的侦察任务');
            }

            // 坐标只作为查找键，目标类型、可见性和拥有者均从锁定行重读 / Coordinates are lookup keys only; reload target type, visibility, and owner from locked rows
            $query = "SELECT tile_id, type, subtype, owner_id, is_visible
                      FROM map_tiles
                      WHERE x = ? AND y = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $targetX, $targetY);
            $stmt->execute();
            $targetResult = $stmt->get_result();
            $target = $targetResult ? $targetResult->fetch_assoc() : null;
            $stmt->close();
            if (!$target
                || (int) $target['is_visible'] !== 1
                || !in_array($target['type'], ['player_city', 'npc_fort'], true)) {
                throw new DomainException('目标必须是已经发现的玩家城池或NPC据点');
            }

            $targetOwnerId = $target['owner_id'] === null
                ? null
                : (int) $target['owner_id'];
            if ($target['type'] === 'player_city') {
                $query = "SELECT city_id, owner_id
                          FROM cities
                          WHERE x = ? AND y = ?
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $targetX, $targetY);
                $stmt->execute();
                $cityResult = $stmt->get_result();
                $city = $cityResult ? $cityResult->fetch_assoc() : null;
                $stmt->close();
                if (!$city) {
                    throw new DomainException('目标玩家城池已经失效');
                }
                $targetOwnerId = (int) $city['owner_id'];
            }
            if ($targetOwnerId !== null) {
                $vassalService = new VassalService();
                if ($vassalService->areUsersInSameForce(
                    $userId,
                    $targetOwnerId
                )) {
                    throw new DomainException(
                        '不能侦察自己或同势力的城池与据点 / Cannot scout your own force'
                    );
                }
            }

            $army = new Army($armyId);
            if (!$army->isValid()
                || (int) $army->getOwnerId() !== $userId
                || $army->getStatus() !== 'idle') {
                throw new DomainException('军队状态已经变化，请刷新后重试');
            }

            $alreadyAtTarget = (int) $armyRow['current_x'] === $targetX
                && (int) $armyRow['current_y'] === $targetY;
            if ($alreadyAtTarget) {
                // 同址任务仍占用军队到本轮定时结算，避免重复派遣 / Same-tile missions reserve the army until the next settlement run
                $arrivalAt = date('Y-m-d H:i:s');
                $query = "UPDATE armies AS scouting_army
                          SET status = 'marching',
                              target_x = ?, target_y = ?,
                              departure_time = ?, arrival_time = ?,
                              return_time = NULL
                          WHERE scouting_army.army_id = ?
                            AND scouting_army.owner_id = ?
                            AND scouting_army.status = 'idle'
                            AND NOT EXISTS (
                                SELECT 1
                                FROM battles pending_battle
                                WHERE pending_battle.attacker_army_id =
                                      scouting_army.army_id
                                  AND pending_battle.result = 'pending'
                            )";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param(
                    'iissii',
                    $targetX,
                    $targetY,
                    $arrivalAt,
                    $arrivalAt,
                    $armyId,
                    $userId
                );
                $reserved = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$reserved) {
                    throw new DomainException('军队已被其他操作占用');
                }
            } else {
                if (!$army->moveArmy($targetX, $targetY)) {
                    throw new DomainException('军队派遣失败，状态可能已经变化');
                }
                $arrivalAt = $army->getArrivalTime();
            }
            if (!$arrivalAt) {
                throw new RuntimeException('侦察任务没有有效到达时间 / Scouting mission has no arrival time');
            }

            $query = "INSERT INTO scouting_missions
                        (user_id, army_id, target_tile_id, target_owner_id,
                         status, outcome, report_json,
                         launched_at, arrival_at, resolved_at)
                      VALUES (?, ?, ?, ?, 'launched', NULL, NULL,
                              NOW(), ?, NULL)";
            $stmt = $this->db->prepare($query);
            $targetTileId = (int) $target['tile_id'];
            $stmt->bind_param(
                'iiiis',
                $userId,
                $armyId,
                $targetTileId,
                $targetOwnerId,
                $arrivalAt
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法建立侦察任务 / Failed to create scouting mission');
            }
            $missionId = (int) $this->db->insert_id;
            $stmt->close();

            $this->db->commit();
            return [
                'success' => true,
                'message' => '侦察军队已出发，抵达后将自动生成结果',
                'data' => [
                    'mission_id' => $missionId,
                    'arrival_at' => $arrivalAt,
                    'scout_count' => $scoutCount
                ]
            ];
        } catch (DomainException $exception) {
            $this->db->rollback();
            $message = $exception->getMessage();
            return $this->failure(
                $message,
                $message === getSeasonGameplayFreezeMessage()
                    ? 'frozen'
                    : 'invalid_state'
            );
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Scouting launch failed: ' . $exception->getMessage());
            return $this->failure('侦察任务发起失败，请稍后重试');
        }
    }

    /**
     * 结算所有到期任务；冻结期由调用者暂停，本方法也会再次拒绝推进 / Resolve all due missions; callers pause during freezes and this method also fails closed
     *
     * @param int $limit 单次上限 / Per-run limit
     * @return array 已结算任务ID / Resolved mission IDs
     */
    public function processDueMissions($limit = 200) {
        $limit = max(1, min(500, (int) $limit));
        if (isSeasonGameplayFrozen()) {
            return [];
        }

        $query = "SELECT mission_id
                  FROM scouting_missions
                  WHERE status = 'launched' AND arrival_at <= NOW()
                  ORDER BY mission_id
                  LIMIT ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $missionIds = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $missionIds[] = (int) $row['mission_id'];
        }
        $stmt->close();

        $resolved = [];
        foreach ($missionIds as $missionId) {
            if ($this->resolveMission($missionId)) {
                $resolved[] = $missionId;
            }
        }

        return $resolved;
    }

    /**
     * 锁定并结算单个侦察任务 / Lock and resolve one scouting mission
     *
     * @param int $missionId 任务ID / Mission ID
     * @return bool 是否结算 / Whether resolved
     */
    private function resolveMission($missionId) {
        $missionId = (int) $missionId;
        $this->db->begin_transaction();
        try {
            if ($this->lockGameplaySeasonIsFrozen()) {
                $this->db->rollback();
                return false;
            }

            $query = "SELECT *
                      FROM scouting_missions
                      WHERE mission_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $missionId);
            $stmt->execute();
            $result = $stmt->get_result();
            $mission = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$mission
                || $mission['status'] !== self::STATUS_LAUNCHED
                || strtotime($mission['arrival_at']) > time()) {
                $this->db->rollback();
                return false;
            }

            $armyId = $mission['army_id'] === null
                ? 0
                : (int) $mission['army_id'];
            $query = "SELECT *
                      FROM armies
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $armyResult = $stmt->get_result();
            $armyRow = $armyResult ? $armyResult->fetch_assoc() : null;
            $stmt->close();

            $success = false;
            $report = null;
            if ($armyRow
                && (int) $armyRow['owner_id'] === (int) $mission['user_id']
                && $armyRow['status'] === 'idle'
                && (int) $armyRow['current_x'] === (int) $armyRow['target_x']
                && (int) $armyRow['current_y'] === (int) $armyRow['target_y']) {
                $query = "SELECT soldier_type, quantity
                          FROM army_units
                          WHERE army_id = ?
                          ORDER BY army_unit_id
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $armyId);
                $stmt->execute();
                $unitResult = $stmt->get_result();
                $units = [];
                while ($unitResult && ($row = $unitResult->fetch_assoc())) {
                    $units[] = $row;
                }
                $stmt->close();
                $sentScouts = self::countExclusiveScouts($units);

                $target = $this->lockMissionTarget(
                    (int) $mission['target_tile_id'],
                    (int) $mission['user_id']
                );
                if ($sentScouts > 0 && $target !== null) {
                    if ($target['type'] === 'player_city') {
                        $counterScouts = $this->getLockedCityScoutCount(
                            (int) $target['city_id'],
                            $armyId
                        );
                    } else {
                        $counterScouts = self::calculateNpcCounterScouts(
                            (int) $target['npc_garrison']
                        );
                    }
                    $success = self::doesScoutingSucceed(
                        $sentScouts,
                        $counterScouts
                    );
                    if ($success) {
                        $report = $target['type'] === 'player_city'
                            ? $this->buildPlayerCityReport(
                                $target,
                                $sentScouts,
                                $counterScouts
                            )
                            : $this->buildNpcFortReport(
                                $target,
                                $sentScouts,
                                $counterScouts
                            );
                    }
                }
            }

            $reportJson = null;
            if ($success && is_array($report)) {
                $reportJson = json_encode(
                    $report,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if ($reportJson === false) {
                    throw new RuntimeException('无法编码侦察报告 / Failed to encode scouting report');
                }
            }
            $status = $success
                ? self::STATUS_SUCCEEDED
                : self::STATUS_FAILED;
            $outcome = $success ? 'success' : 'failure';
            $query = "UPDATE scouting_missions
                      SET status = ?, outcome = ?, report_json = ?,
                          resolved_at = NOW()
                      WHERE mission_id = ? AND status = 'launched'";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'sssi',
                $status,
                $outcome,
                $reportJson,
                $missionId
            );
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException('侦察任务状态已经变化 / Scouting mission state changed');
            }

            if ($armyRow
                && (int) $armyRow['owner_id'] === (int) $mission['user_id']
                && in_array($armyRow['status'], ['idle', 'marching'], true)) {
                $this->scheduleArmyReturnLocked($armyRow);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log(
                'Scouting resolution failed for mission '
                . $missionId
                . ': '
                . $exception->getMessage()
            );
            return false;
        }
    }

    /**
     * 锁定当前任务目标并拒绝已失效或变为己方的目标 / Lock the current target and reject stale or newly owned targets
     *
     * @param int $tileId 地图格ID / Tile ID
     * @param int $userId 发起人ID / Initiator ID
     * @return array|null 权威目标 / Authoritative target
     */
    private function lockMissionTarget($tileId, $userId) {
        if ($tileId <= 0) {
            return null;
        }

        $query = "SELECT tile_id, x, y, type, subtype, owner_id,
                         npc_level, npc_garrison
                  FROM map_tiles
                  WHERE tile_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $tileId);
        $stmt->execute();
        $result = $stmt->get_result();
        $target = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$target
            || !in_array($target['type'], ['player_city', 'npc_fort'], true)) {
            return null;
        }

        if ($target['type'] === 'player_city') {
            $query = "SELECT city_id, owner_id, name, level,
                             durability, max_durability
                      FROM cities
                      WHERE x = ? AND y = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $x = (int) $target['x'];
            $y = (int) $target['y'];
            $stmt->bind_param('ii', $x, $y);
            $stmt->execute();
            $cityResult = $stmt->get_result();
            $city = $cityResult ? $cityResult->fetch_assoc() : null;
            $stmt->close();
            if (!$city) {
                return null;
            }
            $vassalService = new VassalService();
            if ($vassalService->areUsersInSameForce(
                $userId,
                (int) $city['owner_id']
            )) {
                return null;
            }
            return array_merge($target, $city);
        }

        if ($target['owner_id'] !== null) {
            $vassalService = new VassalService();
            if ($vassalService->areUsersInSameForce(
                $userId,
                (int) $target['owner_id']
            )) {
                return null;
            }
        }

        return $target;
    }

    /**
     * 锁定并汇总城内库存及实际驻城待命军队的侦察兵 / Lock and total city-stock scouts plus scouts in idle armies physically stationed there
     *
     * @param int $cityId 城池ID / City ID
     * @param int $attackingArmyId 本次侦察军队ID / Scouting army ID
     * @return int 侦察兵守军 / Scout defenders
     */
    private function getLockedCityScoutCount($cityId, $attackingArmyId) {
        $query = "SELECT owner_id, x, y
                  FROM cities
                  WHERE city_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $city = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$city) {
            return 0;
        }

        $query = "SELECT soldier_id, quantity
                  FROM soldiers
                  WHERE city_id = ? AND type = 'scout'
                  ORDER BY soldier_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $total = 0;
        while ($result && ($row = $result->fetch_assoc())) {
            $total += max(0, (int) $row['quantity']);
        }
        $stmt->close();

        // 组军时士兵已从城内扣除，因此还要统计实际停在城池坐标的守方待命军队 / Army creation deducts city stock, so also count defender armies idling at the city coordinates
        $query = "SELECT army_id
                  FROM armies
                  WHERE owner_id = ?
                    AND status = 'idle'
                    AND current_x = ? AND current_y = ?
                    AND army_id <> ?
                  ORDER BY army_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $ownerId = (int) $city['owner_id'];
        $cityX = (int) $city['x'];
        $cityY = (int) $city['y'];
        $attackingArmyId = (int) $attackingArmyId;
        $stmt->bind_param(
            'iiii',
            $ownerId,
            $cityX,
            $cityY,
            $attackingArmyId
        );
        $stmt->execute();
        $armyResult = $stmt->get_result();
        $defendingArmyIds = [];
        while ($armyResult && ($row = $armyResult->fetch_assoc())) {
            $defendingArmyIds[] = (int) $row['army_id'];
        }
        $stmt->close();

        $reachedMaximum = false;
        foreach ($defendingArmyIds as $defendingArmyId) {
            // 混编守军中的侦察兵也提供反侦察，不要求守军为纯侦察编成 / Scouts in mixed defending armies also counter-scout
            $query = "SELECT army_unit_id, quantity
                      FROM army_units
                      WHERE army_id = ? AND soldier_type = 'scout'
                      ORDER BY army_unit_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $defendingArmyId);
            $stmt->execute();
            $unitResult = $stmt->get_result();
            while ($unitResult && ($row = $unitResult->fetch_assoc())) {
                $total += max(0, (int) $row['quantity']);
                if ($total >= 2147483647) {
                    $total = 2147483647;
                    $reachedMaximum = true;
                    break;
                }
            }
            $stmt->close();
            if ($reachedMaximum) {
                break;
            }
        }

        return min(2147483647, $total);
    }

    /**
     * 构建玩家城池的成功侦察报告 / Build a successful player-city scouting report
     *
     * @param array $target 城池数据 / City data
     * @param int $sentScouts 派出侦察兵 / Sent scouts
     * @param int $counterScouts 反侦察兵 / Counter-scouts
     * @return array 报告 / Report
     */
    private function buildPlayerCityReport($target, $sentScouts, $counterScouts) {
        $cityId = (int) $target['city_id'];
        $query = "SELECT type, subtype, level
                  FROM facilities
                  WHERE city_id = ?
                  ORDER BY facility_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $facilities = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $facilities[] = [
                'type' => (string) $row['type'],
                'name' => $this->getFacilityDisplayName(
                    $row['type'],
                    $row['subtype']
                ),
                'level' => max(1, (int) $row['level'])
            ];
        }
        $stmt->close();

        $query = "SELECT type, level, quantity
                  FROM soldiers
                  WHERE city_id = ? AND quantity > 0
                  ORDER BY soldier_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $soldiers = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $soldiers[] = [
                'type' => (string) $row['type'],
                'name' => $this->getSoldierDisplayName($row['type']),
                'level' => max(1, (int) $row['level']),
                'quantity' => max(0, (int) $row['quantity'])
            ];
        }
        $stmt->close();

        $query = "SELECT g.name, g.rarity, g.level, g.hp, g.max_hp
                  FROM general_assignments ga
                  INNER JOIN generals g ON g.general_id = ga.general_id
                  WHERE ga.assignment_type = 'city'
                    AND ga.target_id = ?
                    AND g.is_active = 1
                  ORDER BY g.general_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $generals = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $generals[] = [
                'name' => (string) $row['name'],
                'rarity' => (string) $row['rarity'],
                'level' => max(1, (int) $row['level']),
                'hp' => max(0, (int) $row['hp']),
                'max_hp' => max(0, (int) $row['max_hp'])
            ];
        }
        $stmt->close();

        return [
            'target_type' => 'player_city',
            'city_name' => (string) $target['name'],
            'coordinates' => [
                'x' => (int) $target['x'],
                'y' => (int) $target['y']
            ],
            'city_level' => max(1, (int) $target['level']),
            'durability' => max(0, (int) $target['durability']),
            'max_durability' => max(0, (int) $target['max_durability']),
            'facilities' => $facilities,
            'soldiers' => $soldiers,
            'generals' => $generals,
            'scouts_sent' => (int) $sentScouts,
            'counter_scouts' => (int) $counterScouts
        ];
    }

    /**
     * 构建NPC据点的成功侦察报告 / Build a successful NPC-fort scouting report
     *
     * @param array $target 据点数据 / Fort data
     * @param int $sentScouts 派出侦察兵 / Sent scouts
     * @param int $counterScouts 反侦察兵 / Counter-scouts
     * @return array 报告 / Report
     */
    private function buildNpcFortReport($target, $sentScouts, $counterScouts) {
        $query = "SELECT display_name, durability, max_durability
                  FROM world_sites
                  WHERE tile_id = ?
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $tileId = (int) $target['tile_id'];
        $stmt->bind_param('i', $tileId);
        $stmt->execute();
        $result = $stmt->get_result();
        $site = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        $npcLevel = max(1, (int) $target['npc_level']);
        $npcGarrison = max(0, (int) $target['npc_garrison']);
        $estimatedMaximum = $npcGarrison;
        if (defined('NPC_FORT_BASE_GARRISON')
            && defined('NPC_FORT_GARRISON_COEFFICIENT')) {
            $estimatedMaximum = max(
                $npcGarrison,
                (int) round(
                    NPC_FORT_BASE_GARRISON
                    * pow($npcLevel, NPC_FORT_GARRISON_COEFFICIENT)
                )
            );
        }
        return [
            'target_type' => 'npc_fort',
            'city_name' => $site
                ? (string) $site['display_name']
                : 'NPC数据据点',
            'coordinates' => [
                'x' => (int) $target['x'],
                'y' => (int) $target['y']
            ],
            'city_level' => $npcLevel,
            'durability' => $site
                ? max(0, (int) $site['durability'])
                : $npcGarrison,
            'max_durability' => $site
                ? max(0, (int) $site['max_durability'])
                : $estimatedMaximum,
            'facilities' => [
                [
                    'type' => 'npc_fortification',
                    'name' => '数据防壁',
                    'level' => $npcLevel
                ]
            ],
            'soldiers' => [
                [
                    'type' => 'npc_garrison',
                    'name' => 'NPC守军',
                    'level' => $npcLevel,
                    'quantity' => $npcGarrison
                ]
            ],
            'generals' => [],
            'scouts_sent' => (int) $sentScouts,
            'counter_scouts' => (int) $counterScouts
        ];
    }

    /**
     * 使用现有军队速度公式安排返城 / Schedule the return using the existing army speed formula
     *
     * @param array $armyRow 已锁定军队 / Locked army row
     * @return void
     */
    private function scheduleArmyReturnLocked($armyRow) {
        $armyId = (int) $armyRow['army_id'];
        $cityId = (int) $armyRow['city_id'];
        if ($cityId <= 0) {
            return;
        }

        $query = "SELECT x, y
                  FROM cities
                  WHERE city_id = ? AND owner_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $ownerId = (int) $armyRow['owner_id'];
        $stmt->bind_param('ii', $cityId, $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $city = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$city) {
            return;
        }

        $cityX = (int) $city['x'];
        $cityY = (int) $city['y'];
        $currentX = (int) $armyRow['current_x'];
        $currentY = (int) $armyRow['current_y'];
        if ($currentX === $cityX && $currentY === $cityY) {
            $query = "UPDATE armies
                      SET status = 'idle', target_x = NULL, target_y = NULL,
                          departure_time = NULL, arrival_time = NULL,
                          return_time = NULL
                      WHERE army_id = ? AND owner_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $armyId, $ownerId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法结束同址侦察 / Failed to finish same-tile scouting');
            }
            $stmt->close();
            return;
        }

        $distance = abs($cityX - $currentX) + abs($cityY - $currentY);
        $army = new Army($armyId);
        $movementSpeed = $army->isValid()
            ? $army->getMovementSpeed([
                'phase' => 'return',
                'distance' => $distance
            ])
            : 0;
        if ($movementSpeed <= 0) {
            throw new RuntimeException('侦察军队无法返城 / Scouting army cannot return');
        }
        $movementSeconds = $distance / $movementSpeed * 3600;
        $returnTime = date('Y-m-d H:i:s', (int) (time() + $movementSeconds));

        $query = "UPDATE armies
                  SET status = 'returning', target_x = ?, target_y = ?,
                      return_time = ?, arrival_time = NULL
                  WHERE army_id = ? AND owner_id = ?
                    AND status IN ('idle', 'marching')";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iisii',
            $cityX,
            $cityY,
            $returnTime,
            $armyId,
            $ownerId
        );
        $scheduled = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$scheduled) {
            throw new RuntimeException('军队返城状态已经变化 / Army return state changed');
        }
    }

    /**
     * 锁定当前赛季并返回是否冻结 / Lock the current season and return whether gameplay is frozen
     *
     * @return bool 是否冻结 / Whether frozen
     */
    private function lockGameplaySeasonIsFrozen() {
        $query = "SELECT status
                  FROM seasons
                  WHERE ended_at IS NULL
                  ORDER BY season_number DESC
                  LIMIT 1
                  LOCK IN SHARE MODE";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $season = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $season && $season['status'] === 'reset_pending';
    }

    /**
     * 获取目标显示名称 / Get a target display name
     *
     * @param array $row 目标行 / Target row
     * @return string 显示名称 / Display name
     */
    private function getTargetDisplayName($row) {
        if (array_key_exists('target_x', $row)
            && $row['target_x'] === null) {
            return '已失效目标';
        }
        if (isset($row['city_name']) && $row['city_name'] !== null) {
            return (string) $row['city_name'];
        }
        if (isset($row['site_name']) && $row['site_name'] !== null) {
            return (string) $row['site_name'];
        }
        if (isset($row['target_type'])
            && $row['target_type'] === 'player_city') {
            return '玩家城池';
        }
        if (isset($row['type']) && $row['type'] === 'player_city') {
            return '玩家城池';
        }

        return 'NPC数据据点';
    }

    /**
     * 获取设施显示名称 / Get a facility display name
     *
     * @param string $type 设施类型 / Facility type
     * @param string|null $subtype 子类型 / Subtype
     * @return string 显示名称 / Display name
     */
    private function getFacilityDisplayName($type, $subtype) {
        $names = [
            'governor_office' => '总督府',
            'barracks' => '兵营',
            'research_lab' => '研究所',
            'dormitory' => '宿舍',
            'storage' => '贮存所',
            'watchtower' => '瞭望台',
            'workshop' => '工程所'
        ];
        if ($type === 'resource_production') {
            $resourceNames = [
                'bright' => '亮晶晶产出点',
                'warm' => '暖洋洋产出点',
                'cold' => '冷冰冰产出点',
                'green' => '郁萌萌产出点',
                'day' => '昼闪闪产出点',
                'night' => '夜静静产出点'
            ];
            return isset($resourceNames[$subtype])
                ? $resourceNames[$subtype]
                : '资源产出点';
        }

        return isset($names[$type]) ? $names[$type] : '未知设施';
    }

    /**
     * 获取兵种显示名称 / Get a soldier display name
     *
     * @param string $type 兵种 / Soldier type
     * @return string 显示名称 / Display name
     */
    private function getSoldierDisplayName($type) {
        $names = [
            'pawn' => '兵卒',
            'knight' => '骑士',
            'rook' => '城壁',
            'bishop' => '主教',
            'golem' => '锤子兵',
            'scout' => '侦察兵'
        ];

        return isset($names[$type]) ? $names[$type] : '未知士兵';
    }

    /**
     * 构建失败响应 / Build a failure response
     *
     * @param string $message 消息 / Message
     * @param string $code 错误代码 / Error code
     * @return array 失败响应 / Failure response
     */
    private function failure($message, $code = 'invalid_request') {
        return [
            'success' => false,
            'message' => $message,
            'code' => $code
        ];
    }
}

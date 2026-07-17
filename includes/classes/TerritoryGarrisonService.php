<?php
// 种火集结号 - 领地驻军服务 / Fireseed Engage - territory garrison service

class TerritoryGarrisonService {
    const MAX_UNIT_QUANTITY = 2147483647;

    private $db;
    private $soldierTypes = [
        'pawn',
        'knight',
        'rook',
        'bishop',
        'golem',
        'scout'
    ];

    /**
     * 构造函数 / Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 获取玩家普通领地、驻军与同坐标待命军队 / Get a user's ordinary territories, garrisons, and co-located idle armies
     * @param int $userId 玩家ID / User ID
     * @return array 领地列表 / Territory list
     */
    public function getUserTerritories($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return [];
        }

        $query = "SELECT tile_id, x, y, type, subtype, resource_amount,
                         last_collection_time, collection_efficiency
                  FROM map_tiles
                  WHERE owner_id = ? AND type IN ('empty', 'resource')
                  ORDER BY type, tile_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $territories = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $tileId = (int) $row['tile_id'];
            $tile = new Map($tileId);
            $territories[$tileId] = [
                'tile_id' => $tileId,
                'x' => (int) $row['x'],
                'y' => (int) $row['y'],
                'type' => $row['type'],
                'subtype' => $row['subtype'],
                'resource_amount' => $row['resource_amount'] === null
                    ? null
                    : (int) $row['resource_amount'],
                'last_collection_time' => $row['last_collection_time'],
                'collection_efficiency' => (int) $row['collection_efficiency'],
                'name' => $tile->isValid() ? $tile->getName() : '普通领地',
                'garrison_total' => 0,
                'garrison_units' => [],
                'idle_armies' => []
            ];
        }
        $stmt->close();
        if (empty($territories)) {
            return [];
        }

        $query = "SELECT tg.tile_id, tg.soldier_type, tg.level, tg.quantity
                  FROM territory_garrisons tg
                  INNER JOIN map_tiles mt ON mt.tile_id = tg.tile_id
                  WHERE mt.owner_id = ? AND tg.owner_id = ?
                    AND mt.type IN ('empty', 'resource')
                    AND tg.quantity > 0
                  ORDER BY tg.tile_id,
                           FIELD(tg.soldier_type,
                               'pawn','knight','rook','bishop','golem','scout'),
                           tg.level";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $tileId = (int) $row['tile_id'];
            if (!isset($territories[$tileId])) {
                continue;
            }
            $quantity = max(0, (int) $row['quantity']);
            $territories[$tileId]['garrison_total'] += $quantity;
            $territories[$tileId]['garrison_units'][] = [
                'soldier_type' => $row['soldier_type'],
                'level' => max(1, (int) $row['level']),
                'quantity' => $quantity
            ];
        }
        $stmt->close();

        $query = "SELECT a.army_id, a.name, a.current_x, a.current_y,
                         COALESCE((
                             SELECT SUM(au.quantity)
                             FROM army_units au
                             WHERE au.army_id = a.army_id
                         ), 0) AS unit_count,
                         EXISTS(
                             SELECT 1 FROM general_assignments ga
                             WHERE ga.assignment_type = 'army'
                               AND ga.target_id = a.army_id
                         ) AS has_general,
                         EXISTS(
                             SELECT 1 FROM battles b
                             WHERE b.result = 'pending'
                               AND (b.attacker_army_id = a.army_id
                                    OR b.defender_army_id = a.army_id)
                         ) AS has_pending_battle,
                         EXISTS(
                             SELECT 1 FROM alliance_operation_armies aoa
                             WHERE aoa.army_id = a.army_id
                         ) AS in_alliance_operation,
                         EXISTS(
                             SELECT 1 FROM arena_profiles ap
                             WHERE ap.defense_army_id = a.army_id
                         ) AS in_challenge
                  FROM armies a
                  WHERE a.owner_id = ? AND a.status = 'idle'
                    AND EXISTS(
                        SELECT 1 FROM map_tiles mt
                        WHERE mt.owner_id = ? AND mt.type IN ('empty', 'resource')
                          AND mt.x = a.current_x AND mt.y = a.current_y
                    )
                  ORDER BY a.army_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $tileId = $this->findTerritoryIdAtCoordinates(
                $territories,
                (int) $row['current_x'],
                (int) $row['current_y']
            );
            if ($tileId === null) {
                continue;
            }

            $blockedReasons = [];
            if ((int) $row['unit_count'] <= 0) {
                $blockedReasons[] = '没有士兵';
            }
            if ((int) $row['has_general'] !== 0) {
                $blockedReasons[] = '编有武将';
            }
            if ((int) $row['has_pending_battle'] !== 0) {
                $blockedReasons[] = '有待结算战斗';
            }
            if ((int) $row['in_alliance_operation'] !== 0) {
                $blockedReasons[] = '参加联盟行动';
            }
            if ((int) $row['in_challenge'] !== 0) {
                $blockedReasons[] = '担任竞技场防守';
            }

            $territories[$tileId]['idle_armies'][] = [
                'army_id' => (int) $row['army_id'],
                'name' => $row['name'],
                'unit_count' => max(0, (int) $row['unit_count']),
                'deployable' => empty($blockedReasons),
                'blocked_reason' => implode('、', $blockedReasons)
            ];
        }
        $stmt->close();

        return array_values($territories);
    }

    /**
     * 获取地图范围内的驻军可见信息 / Get visibility-safe garrison data in a map range
     * @param int $startX 起始X坐标 / Start X
     * @param int $startY 起始Y坐标 / Start Y
     * @param int $endX 结束X坐标 / End X
     * @param int $endY 结束Y坐标 / End Y
     * @param int $viewerId 查看者ID / Viewer ID
     * @return array 以地图格ID索引的驻军信息 / Garrison data indexed by tile ID
     */
    public function getMapGarrisonsInRange(
        $startX,
        $startY,
        $endX,
        $endY,
        $viewerId
    ) {
        $query = "SELECT tg.tile_id, tg.owner_id, tg.soldier_type,
                         tg.level, tg.quantity
                  FROM territory_garrisons tg
                  INNER JOIN map_tiles mt ON mt.tile_id = tg.tile_id
                  WHERE mt.x >= ? AND mt.x <= ?
                    AND mt.y >= ? AND mt.y <= ?
                    AND mt.type IN ('empty', 'resource')
                    AND tg.quantity > 0
                  ORDER BY tg.tile_id,
                           FIELD(tg.soldier_type,
                               'pawn','knight','rook','bishop','golem','scout'),
                           tg.level";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iiii', $startX, $endX, $startY, $endY);
        $stmt->execute();
        $result = $stmt->get_result();
        $garrisons = [];
        $viewerId = (int) $viewerId;
        while ($result && ($row = $result->fetch_assoc())) {
            $tileId = (int) $row['tile_id'];
            if (!isset($garrisons[$tileId])) {
                $garrisons[$tileId] = [
                    'total' => 0,
                    'units' => []
                ];
            }
            $quantity = max(0, (int) $row['quantity']);
            $garrisons[$tileId]['total'] += $quantity;
            if ((int) $row['owner_id'] === $viewerId) {
                $garrisons[$tileId]['units'][] = [
                    'soldier_type' => $row['soldier_type'],
                    'level' => max(1, (int) $row['level']),
                    'quantity' => $quantity
                ];
            }
        }
        $stmt->close();

        return $garrisons;
    }

    /**
     * 将同坐标待命军队整军部署为驻军 / Deploy a co-located idle army as a whole garrison
     * @param int $userId 玩家ID / User ID
     * @param int $tileId 地图格ID / Tile ID
     * @param int $armyId 军队ID / Army ID
     * @return array 操作结果 / Operation result
     */
    public function deployArmy($userId, $tileId, $armyId) {
        $userId = (int) $userId;
        $tileId = (int) $tileId;
        $armyId = (int) $armyId;
        if ($userId <= 0 || $tileId <= 0 || $armyId <= 0) {
            return $this->failure('驻军部署参数无效');
        }

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            // 竞技场以资料行优先加锁；沿用相同顺序避免与防守军队设置互锁 / Arena locks its profile first; follow that order to avoid inversion with defense setup
            $query = "SELECT defense_army_id
                      FROM arena_profiles
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $arenaProfile = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            // 待结算战斗必须先于军队锁定，与战斗结算的battle→army顺序一致 / Lock pending battles before the army to match battle resolution's battle-to-army order
            $query = "SELECT battle_id
                      FROM battles
                      WHERE result = 'pending'
                        AND (attacker_army_id = ? OR defender_army_id = ?)
                      ORDER BY battle_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $armyId, $armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $hasPendingBattle = $result && $result->num_rows > 0;
            $stmt->close();
            if ($hasPendingBattle) {
                throw new RuntimeException('有待结算战斗的军队不能部署');
            }

            $query = "SELECT army_id, owner_id, status, current_x, current_y
                      FROM armies
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $army = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$army
                || (int) $army['owner_id'] !== $userId
                || $army['status'] !== 'idle') {
                throw new RuntimeException('只能部署自己拥有的待命军队');
            }

            // 获取军队锁后再次读取待处理战斗，捕获等待军队期间刚提交的战斗 / Re-read pending battles after acquiring the army lock to catch a battle committed while this transaction was waiting
            $query = "SELECT battle_id
                      FROM battles
                      WHERE result = 'pending'
                        AND (attacker_army_id = ? OR defender_army_id = ?)
                      ORDER BY battle_id
                      LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $armyId, $armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $hasPendingBattle = $result && $result->num_rows > 0;
            $stmt->close();
            if ($hasPendingBattle) {
                throw new RuntimeException('有待结算战斗的军队不能部署');
            }

            $query = "SELECT army_unit_id, soldier_type, level, quantity
                      FROM army_units
                      WHERE army_id = ?
                      ORDER BY army_unit_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $units = [];
            while ($result && ($row = $result->fetch_assoc())) {
                if (!$this->isSoldierType($row['soldier_type'])
                    || (int) $row['level'] <= 0
                    || (int) $row['quantity'] <= 0) {
                    throw new RuntimeException('军队编成包含无效单位');
                }
                $units[] = [
                    'soldier_type' => $row['soldier_type'],
                    'level' => (int) $row['level'],
                    'quantity' => (int) $row['quantity']
                ];
            }
            $stmt->close();
            if (empty($units)) {
                throw new RuntimeException('没有可部署的士兵');
            }

            $query = "SELECT tile_id, owner_id, type, x, y
                      FROM map_tiles
                      WHERE tile_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $tileId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tile = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$tile
                || (int) $tile['owner_id'] !== $userId
                || !in_array($tile['type'], ['empty', 'resource'], true)) {
                throw new RuntimeException('只能在自己的普通领地部署驻军');
            }
            if ((int) $army['current_x'] !== (int) $tile['x']
                || (int) $army['current_y'] !== (int) $tile['y']) {
                throw new RuntimeException('军队必须先移动到该领地才能部署');
            }

            $query = "SELECT garrison_id, owner_id, soldier_type,
                             level, quantity
                      FROM territory_garrisons
                      WHERE tile_id = ?
                      ORDER BY garrison_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $tileId);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing = [];
            while ($result && ($row = $result->fetch_assoc())) {
                if ((int) $row['owner_id'] !== $userId) {
                    throw new RuntimeException('驻军拥有权与领地不一致');
                }
                $key = $row['soldier_type'] . ':' . (int) $row['level'];
                $existing[$key] = (int) $row['quantity'];
            }
            $stmt->close();

            $this->assertArmyIsUnreserved(
                $armyId,
                $arenaProfile
                    && $arenaProfile['defense_army_id'] !== null
                    ? (int) $arenaProfile['defense_army_id']
                    : null
            );

            foreach ($units as $unit) {
                $key = $unit['soldier_type'] . ':' . $unit['level'];
                $current = isset($existing[$key]) ? $existing[$key] : 0;
                if ($current > self::MAX_UNIT_QUANTITY - $unit['quantity']) {
                    throw new RuntimeException('该领地的同类驻军数量已达上限');
                }

                $query = "INSERT INTO territory_garrisons
                             (tile_id, owner_id, soldier_type, level, quantity)
                          VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE
                              owner_id = VALUES(owner_id),
                              quantity = quantity + VALUES(quantity)";
                $stmt = $this->db->prepare($query);
                $soldierType = $unit['soldier_type'];
                $level = (int) $unit['level'];
                $quantity = (int) $unit['quantity'];
                $stmt->bind_param(
                    'iisii',
                    $tileId,
                    $userId,
                    $soldierType,
                    $level,
                    $quantity
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('写入领地驻军失败');
                }
                $stmt->close();
                $existing[$key] = $current + $quantity;
            }

            // 军队记录与编成由外键级联删除，士兵只存在于驻军表中一次 / Delete the army and its units by cascade so troops remain exactly once in the garrison table
            $query = "DELETE FROM armies
                      WHERE army_id = ? AND owner_id = ? AND status = 'idle'";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $armyId, $userId);
            $deleted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$deleted) {
                throw new RuntimeException('军队状态已经变化');
            }

            $this->db->commit();
            return [
                'success' => true,
                'message' => '军队已整军部署为领地驻军',
                'tile_id' => $tileId
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Territory garrison deployment failed: ' . $exception->getMessage());
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * 从领地驻军中按兵种撤回部分士兵 / Withdraw partial quantities by soldier type from a territory garrison
     * @param int $userId 玩家ID / User ID
     * @param int $tileId 地图格ID / Tile ID
     * @param int $cityId 目标城池ID / Destination city ID
     * @param string $name 新军队名称 / New army name
     * @param array $requestedUnits 撤回请求 / Withdrawal request
     * @return array 操作结果 / Operation result
     */
    public function withdrawGarrison(
        $userId,
        $tileId,
        $cityId,
        $name,
        $requestedUnits
    ) {
        $userId = (int) $userId;
        $tileId = (int) $tileId;
        $cityId = (int) $cityId;
        $name = trim((string) $name);
        $requested = $this->normalizeWithdrawalUnits($requestedUnits);
        if ($userId <= 0 || $tileId <= 0 || $cityId <= 0) {
            return $this->failure('驻军撤回参数无效');
        }
        if ($name === '' || $this->stringLength($name) > 50) {
            return $this->failure('军队名称必须为1至50个字符');
        }
        if (isset($requested['error'])) {
            return $this->failure($requested['error']);
        }

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            $query = "SELECT tile_id, owner_id, type, x, y
                      FROM map_tiles
                      WHERE tile_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $tileId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tile = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$tile
                || (int) $tile['owner_id'] !== $userId
                || !in_array($tile['type'], ['empty', 'resource'], true)) {
                throw new RuntimeException('只能从自己的普通领地撤回驻军');
            }

            $query = "SELECT garrison_id, owner_id, soldier_type,
                             level, quantity
                      FROM territory_garrisons
                      WHERE tile_id = ?
                      ORDER BY FIELD(soldier_type,
                                   'pawn','knight','rook','bishop','golem','scout'),
                               level DESC, garrison_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $tileId);
            $stmt->execute();
            $result = $stmt->get_result();
            $garrisonRows = [];
            $availableByType = [];
            while ($result && ($row = $result->fetch_assoc())) {
                if ((int) $row['owner_id'] !== $userId) {
                    throw new RuntimeException('驻军拥有权与领地不一致');
                }
                $garrisonRows[] = $row;
                $soldierType = $row['soldier_type'];
                $availableByType[$soldierType] = isset($availableByType[$soldierType])
                    ? $availableByType[$soldierType] + (int) $row['quantity']
                    : (int) $row['quantity'];
            }
            $stmt->close();

            foreach ($requested['units'] as $soldierType => $quantity) {
                if (!isset($availableByType[$soldierType])
                    || $availableByType[$soldierType] < $quantity) {
                    throw new RuntimeException(
                        getSoldierName($soldierType) . '驻军数量不足'
                    );
                }
            }

            $query = "SELECT city_id, owner_id, x, y
                      FROM cities
                      WHERE city_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $city = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$city || (int) $city['owner_id'] !== $userId) {
                throw new RuntimeException('撤回目标必须是自己的城池');
            }

            $currentX = (int) $tile['x'];
            $currentY = (int) $tile['y'];
            $query = "INSERT INTO armies
                         (owner_id, name, status, current_x, current_y, city_id)
                      VALUES (?, ?, 'idle', ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'isiii',
                $userId,
                $name,
                $currentX,
                $currentY,
                $cityId
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('创建撤回军队失败');
            }
            $armyId = (int) $this->db->insert_id;
            $stmt->close();

            $armyUnits = $this->deductRequestedGarrison(
                $tileId,
                $userId,
                $requested['units'],
                $garrisonRows
            );
            foreach ($armyUnits as $unit) {
                $query = "INSERT INTO army_units
                             (army_id, soldier_type, level, quantity)
                          VALUES (?, ?, ?, ?)";
                $stmt = $this->db->prepare($query);
                $soldierType = $unit['soldier_type'];
                $level = (int) $unit['level'];
                $quantity = (int) $unit['quantity'];
                $stmt->bind_param(
                    'isii',
                    $armyId,
                    $soldierType,
                    $level,
                    $quantity
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('写入撤回军队编成失败');
                }
                $stmt->close();
            }

            $status = 'idle';
            $returnTime = null;
            $cityX = (int) $city['x'];
            $cityY = (int) $city['y'];
            if ($currentX !== $cityX || $currentY !== $cityY) {
                $withdrawalArmy = new Army($armyId);
                $speed = $withdrawalArmy->getMovementSpeed();
                if (!$withdrawalArmy->isValid() || $speed <= 0) {
                    throw new RuntimeException('撤回军队无法计算返程速度');
                }
                $distance = abs($cityX - $currentX) + abs($cityY - $currentY);
                $seconds = (int) ceil($distance / $speed * 3600);
                $returnTime = date('Y-m-d H:i:s', time() + max(1, $seconds));

                $query = "UPDATE armies
                          SET status = 'returning', target_x = ?, target_y = ?,
                              return_time = ?, arrival_time = NULL
                          WHERE army_id = ? AND status = 'idle'";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param(
                    'iisi',
                    $cityX,
                    $cityY,
                    $returnTime,
                    $armyId
                );
                $scheduled = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$scheduled) {
                    throw new RuntimeException('安排驻军返程失败');
                }
                $status = 'returning';
            }

            $this->db->commit();
            return [
                'success' => true,
                'message' => $status === 'idle'
                    ? '驻军已在目标城池集结'
                    : '驻军已组成军队并开始返城',
                'army_id' => $armyId,
                'status' => $status,
                'return_time' => $returnTime
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Territory garrison withdrawal failed: ' . $exception->getMessage());
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * 验证军队没有被其他玩法占用 / Assert that an army is not reserved by another mode
     * @param int $armyId 军队ID / Army ID
     * @param int|null $arenaDefenseArmyId 竞技场防守军队ID / Arena defense army ID
     * @return void
     */
    private function assertArmyIsUnreserved($armyId, $arenaDefenseArmyId) {
        if ($arenaDefenseArmyId !== null && $arenaDefenseArmyId === $armyId) {
            throw new RuntimeException('竞技场防守军队不能部署为驻军');
        }

        $query = "SELECT assignment_id
                  FROM general_assignments
                  WHERE assignment_type = 'army' AND target_id = ?
                  ORDER BY assignment_id
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $armyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $hasGeneral = $result && $result->num_rows > 0;
        $stmt->close();
        if ($hasGeneral) {
            throw new RuntimeException('请先解除军队中的全部武将');
        }

        $query = "SELECT operation_id
                  FROM alliance_operation_armies
                  WHERE army_id = ?
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $armyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $inAllianceOperation = $result && $result->num_rows > 0;
        $stmt->close();
        if ($inAllianceOperation) {
            throw new RuntimeException('参加联盟行动的军队不能部署');
        }

        $query = "SELECT user_id
                  FROM arena_profiles
                  WHERE defense_army_id = ?
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $armyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $inChallenge = $result && $result->num_rows > 0;
        $stmt->close();
        if ($inChallenge) {
            throw new RuntimeException('挑战玩法占用的军队不能部署');
        }
    }

    /**
     * 规范化每兵种一次的正整数撤回请求 / Normalize a positive, unique request for each soldier type
     * @param mixed $requestedUnits 原始请求 / Raw request
     * @return array 规范化结果 / Normalized result
     */
    private function normalizeWithdrawalUnits($requestedUnits) {
        if (!is_array($requestedUnits)
            || empty($requestedUnits)
            || count($requestedUnits) > count($this->soldierTypes)) {
            return ['error' => '必须选择至少一种且至多六种驻军'];
        }

        $normalized = [];
        foreach ($requestedUnits as $unit) {
            $keys = is_array($unit) ? array_keys($unit) : [];
            sort($keys, SORT_STRING);
            if (!is_array($unit)
                || $keys !== ['quantity', 'soldier_type']
                || !is_string($unit['soldier_type'])
                || !$this->isSoldierType($unit['soldier_type'])
                || !is_int($unit['quantity'])
                || $unit['quantity'] <= 0
                || $unit['quantity'] > self::MAX_UNIT_QUANTITY) {
                return ['error' => '撤回编成必须使用六类合法兵种与正整数数量'];
            }

            $soldierType = $unit['soldier_type'];
            if (isset($normalized[$soldierType])) {
                return ['error' => '同一兵种不能重复提交'];
            }
            $normalized[$soldierType] = $unit['quantity'];
        }

        return ['units' => $normalized];
    }

    /**
     * 从锁定驻军中扣除请求并保留等级 / Deduct requested troops from locked garrisons while preserving levels
     * @return array 新军队编成 / New army composition
     */
    private function deductRequestedGarrison(
        $tileId,
        $userId,
        $requested,
        $garrisonRows
    ) {
        $remaining = $requested;
        $armyUnits = [];
        foreach ($garrisonRows as $row) {
            $soldierType = $row['soldier_type'];
            if (!isset($remaining[$soldierType])
                || $remaining[$soldierType] <= 0) {
                continue;
            }

            $quantity = min(
                (int) $row['quantity'],
                (int) $remaining[$soldierType]
            );
            if ($quantity <= 0) {
                continue;
            }

            $query = "UPDATE territory_garrisons
                      SET quantity = quantity - ?
                      WHERE garrison_id = ? AND tile_id = ? AND owner_id = ?
                        AND quantity >= ?";
            $stmt = $this->db->prepare($query);
            $garrisonId = (int) $row['garrison_id'];
            $stmt->bind_param(
                'iiiii',
                $quantity,
                $garrisonId,
                $tileId,
                $userId,
                $quantity
            );
            $deducted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$deducted) {
                throw new RuntimeException('驻军数量已经变化');
            }

            $armyUnits[] = [
                'soldier_type' => $soldierType,
                'level' => max(1, (int) $row['level']),
                'quantity' => $quantity
            ];
            $remaining[$soldierType] -= $quantity;
        }

        foreach ($remaining as $quantity) {
            if ($quantity !== 0) {
                throw new RuntimeException('无法完整扣除所选驻军');
            }
        }

        $query = "DELETE FROM territory_garrisons
                  WHERE tile_id = ? AND owner_id = ? AND quantity = 0";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $tileId, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法清理空驻军记录');
        }
        $stmt->close();

        return $armyUnits;
    }

    /**
     * 按坐标定位已加载的领地ID / Find a loaded territory ID by coordinates
     * @return int|null 地图格ID / Tile ID
     */
    private function findTerritoryIdAtCoordinates($territories, $x, $y) {
        foreach ($territories as $tileId => $territory) {
            if ((int) $territory['x'] === (int) $x
                && (int) $territory['y'] === (int) $y) {
                return (int) $tileId;
            }
        }
        return null;
    }

    /**
     * 检查兵种枚举 / Check the soldier-type enumeration
     * @param string $soldierType 兵种 / Soldier type
     * @return bool 是否有效 / Whether valid
     */
    private function isSoldierType($soldierType) {
        return in_array($soldierType, $this->soldierTypes, true);
    }

    /**
     * 获取兼容环境的字符串长度 / Get a string length in compatible environments
     * @param string $value 文本 / Text
     * @return int 长度 / Length
     */
    private function stringLength($value) {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $characterCount = preg_match_all('/./us', $value, $matches);
        return $characterCount === false ? strlen($value) : $characterCount;
    }

    /**
     * 构造失败结果 / Build a failure result
     * @param string $message 提示 / Message
     * @return array 结果 / Result
     */
    private function failure($message) {
        return [
            'success' => false,
            'message' => (string) $message
        ];
    }
}

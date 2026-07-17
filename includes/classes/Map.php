<?php
// 种火集结号 - 地图类

class Map {
    private $db;
    private $tileId;
    private $x;
    private $y;
    private $type;
    private $subtype;
    private $ownerId;
    private $resourceAmount;
    private $npcLevel;
    private $npcGarrison;
    private $npcRespawnTime;
    private $isVisible;
    private $lastCollectionTime;
    private $collectionEfficiency;
    private $isValid = false;

    /**
     * 构造函数
     * @param int $tileId 地图格子ID
     */
    public function __construct($tileId = null) {
        $this->db = Database::getInstance()->getConnection();

        if ($tileId !== null) {
            $this->tileId = $tileId;
            $this->loadTileData();
        }
    }

    /**
     * 加载地图格子数据
     */
    private function loadTileData() {
        $query = "SELECT * FROM map_tiles WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->tileId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $tileData = $result->fetch_assoc();
            $this->x = $tileData['x'];
            $this->y = $tileData['y'];
            $this->type = $tileData['type'];
            $this->subtype = $tileData['subtype'];
            $this->ownerId = $tileData['owner_id'];
            $this->resourceAmount = $tileData['resource_amount'];
            $this->npcLevel = $tileData['npc_level'];
            $this->npcGarrison = $tileData['npc_garrison'];
            $this->npcRespawnTime = $tileData['npc_respawn_time'];
            $this->isVisible = $tileData['is_visible'];
            $this->lastCollectionTime = $tileData['last_collection_time'];
            $this->collectionEfficiency = $tileData['collection_efficiency'] ?? 100;
            $this->isValid = true;
        }

        $stmt->close();
    }

    /**
     * 通过坐标加载地图格子数据
     * @param int $x X坐标
     * @param int $y Y坐标
     * @return bool
     */
    public function loadByCoordinates($x, $y) {
        $query = "SELECT * FROM map_tiles WHERE x = ? AND y = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $x, $y);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $tileData = $result->fetch_assoc();
            $this->tileId = $tileData['tile_id'];
            $this->x = $tileData['x'];
            $this->y = $tileData['y'];
            $this->type = $tileData['type'];
            $this->subtype = $tileData['subtype'];
            $this->ownerId = $tileData['owner_id'];
            $this->resourceAmount = $tileData['resource_amount'];
            $this->npcLevel = $tileData['npc_level'];
            $this->npcGarrison = $tileData['npc_garrison'];
            $this->npcRespawnTime = $tileData['npc_respawn_time'];
            $this->isVisible = $tileData['is_visible'];
            $this->lastCollectionTime = $tileData['last_collection_time'];
            $this->collectionEfficiency = $tileData['collection_efficiency'] ?? 100;
            $this->isValid = true;
            $stmt->close();
            return true;
        }

        $stmt->close();
        return false;
    }

    /**
     * 检查地图格子是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }

    /**
     * 获取地图格子ID
     * @return int
     */
    public function getTileId() {
        return $this->tileId;
    }

    /**
     * 获取X坐标
     * @return int
     */
    public function getX() {
        return $this->x;
    }

    /**
     * 获取Y坐标
     * @return int
     */
    public function getY() {
        return $this->y;
    }

    /**
     * 获取地图格子类型
     * @return string
     */
    public function getType() {
        return $this->type;
    }

    /**
     * 获取地图格子子类型
     * @return string|null
     */
    public function getSubtype() {
        return $this->subtype;
    }

    /**
     * 获取拥有者ID
     * @return int|null
     */
    public function getOwnerId() {
        return $this->ownerId;
    }

    /**
     * 获取资源数量
     * @return int|null
     */
    public function getResourceAmount() {
        return $this->resourceAmount;
    }

    /**
     * 获取NPC等级
     * @return int
     */
    public function getNpcLevel() {
        return $this->npcLevel ?? 1;
    }

    /**
     * 检查地图格子是否可见
     * @return bool
     */
    public function isVisible() {
        return $this->isVisible;
    }

    /**
     * 设置地图格子可见性
     * @param bool $isVisible 是否可见
     * @return bool
     */
    public function setVisible($isVisible) {
        if (!$this->isValid) {
            return false;
        }

        $query = "UPDATE map_tiles SET is_visible = ? WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $visibleInt = $isVisible ? 1 : 0;
        $stmt->bind_param('ii', $visibleInt, $this->tileId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->isVisible = $isVisible;
            return true;
        }

        return false;
    }

    /**
     * 设置地图格子拥有者
     * @param int|null $ownerId 拥有者ID
     * @return bool
     */
    public function setOwner($ownerId) {
        if (!$this->isValid) {
            return false;
        }

        $query = "UPDATE map_tiles SET owner_id = ? WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $ownerId, $this->tileId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->ownerId = $ownerId;
            return true;
        }

        return false;
    }

    /**
     * 设置资源数量
     * @param int $resourceAmount 资源数量
     * @return bool
     */
    public function setResourceAmount($resourceAmount) {
        if (!$this->isValid) {
            return false;
        }

        $query = "UPDATE map_tiles SET resource_amount = ? WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $resourceAmount, $this->tileId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->resourceAmount = $resourceAmount;
            return true;
        }

        return false;
    }

    /**
     * 创建新地图格子
     * @param int $x X坐标
     * @param int $y Y坐标
     * @param string $type 地图格子类型
     * @param string|null $subtype 地图格子子类型
     * @param int|null $ownerId 拥有者ID
     * @param int|null $resourceAmount 资源数量
     * @param int|null $npcLevel NPC等级
     * @param bool $isVisible 是否可见
     * @return bool|int 成功返回地图格子ID，失败返回false
     */
    public function createTile($x, $y, $type, $subtype = null, $ownerId = null, $resourceAmount = null, $npcLevel = null, $isVisible = false) {
        // 检查坐标是否在地图范围内
        if ($x < 0 || $x >= MAP_WIDTH || $y < 0 || $y >= MAP_HEIGHT) {
            return false;
        }

        // 检查坐标是否已被占用
        $query = "SELECT tile_id FROM map_tiles WHERE x = ? AND y = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $x, $y);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $stmt->close();
            return false; // 坐标已被占用
        }

        $stmt->close();

        // 检查地图格子类型是否有效
        $validTypes = ['empty', 'resource', 'npc_fort', 'player_city', 'special'];
        if (!in_array($type, $validTypes)) {
            return false;
        }

        // 创建新地图格子
        $query = "INSERT INTO map_tiles (x, y, type, subtype, owner_id, resource_amount, npc_level, is_visible)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $visibleInt = $isVisible ? 1 : 0;
        $stmt->bind_param('iissiiis', $x, $y, $type, $subtype, $ownerId, $resourceAmount, $npcLevel, $visibleInt);
        $result = $stmt->execute();

        if ($result) {
            $tileId = $this->db->insert_id;
            $stmt->close();

            // 设置对象属性
            $this->tileId = $tileId;
            $this->x = $x;
            $this->y = $y;
            $this->type = $type;
            $this->subtype = $subtype;
            $this->ownerId = $ownerId;
            $this->resourceAmount = $resourceAmount;
            $this->npcLevel = $npcLevel;
            $this->isVisible = $isVisible;
            $this->isValid = true;

            return $tileId;
        }

        $stmt->close();
        return false;
    }

    /**
     * 获取地图格子名称
     * @return string
     */
    public function getName() {
        if (!$this->isValid) {
            return '';
        }

        switch ($this->type) {
            case 'empty':
                return '空地';
            case 'resource':
                switch ($this->subtype) {
                    case 'bright':
                        return '亮晶晶资源点';
                    case 'warm':
                        return '暖洋洋资源点';
                    case 'cold':
                        return '冷冰冰资源点';
                    case 'green':
                        return '郁萌萌资源点';
                    case 'day':
                        return '昼闪闪资源点';
                    case 'night':
                        return '夜静静资源点';
                    default:
                        return '资源点';
                }
            case 'npc_fort':
                return 'NPC城池 (Lv.' . $this->npcLevel . ')';
            case 'player_city':
                // 按坐标查找城池，拥有者ID并不是城池ID / Resolve the city by coordinates because an owner ID is not a city ID
                $query = "SELECT name FROM cities WHERE x = ? AND y = ? LIMIT 1";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $this->x, $this->y);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                if ($row) {
                    return $row['name'];
                }
                return '玩家城池';
            case 'special':
                switch ($this->subtype) {
                    case 'silver_hole':
                        return '银白之孔';
                    default:
                        return '特殊地点';
                }
            default:
                return '未知地点';
        }
    }

    /**
     * 获取地图格子描述
     * @return string
     */
    public function getDescription() {
        if (!$this->isValid) {
            return '';
        }

        switch ($this->type) {
            case 'empty':
                return '一片空地，可以占领建造城池。';
            case 'resource':
                switch ($this->subtype) {
                    case 'bright':
                        return '亮晶晶资源点，可以产出亮晶晶资源。';
                    case 'warm':
                        return '暖洋洋资源点，可以产出暖洋洋资源。';
                    case 'cold':
                        return '冷冰冰资源点，可以产出冷冰冰资源。';
                    case 'green':
                        return '郁萌萌资源点，可以产出郁萌萌资源。';
                    case 'day':
                        return '昼闪闪资源点，可以产出昼闪闪资源。';
                    case 'night':
                        return '夜静静资源点，可以产出夜静静资源。';
                    default:
                        return '资源点，可以产出资源。';
                }
            case 'npc_fort':
                return 'NPC城池，等级 ' . $this->npcLevel . '，可以攻占获得资源和奖励。';
            case 'player_city':
                // 获取城池拥有者
                $user = new User($this->ownerId);
                if ($user->isValid()) {
                    return '玩家 ' . $user->getUsername() . ' 的城池。';
                }
                return '玩家城池。';
            case 'special':
                switch ($this->subtype) {
                    case 'silver_hole':
                        return '银白之孔，游戏的最终目标，占领并持有30天即可获得胜利。';
                    default:
                        return '特殊地点，具有特殊效果。';
                }
            default:
                return '未知地点。';
        }
    }

    /**
     * 获取周围的地图格子
     * @param int $radius 半径
     * @return array 地图格子数组
     */
    public function getSurroundingTiles($radius = 1) {
        if (!$this->isValid) {
            return [];
        }

        $tiles = [];

        for ($dx = -$radius; $dx <= $radius; $dx++) {
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                // 跳过中心点
                if ($dx == 0 && $dy == 0) {
                    continue;
                }

                $newX = $this->x + $dx;
                $newY = $this->y + $dy;

                // 检查坐标是否在地图范围内
                if ($newX >= 0 && $newX < MAP_WIDTH && $newY >= 0 && $newY < MAP_HEIGHT) {
                    $tile = new Map();
                    if ($tile->loadByCoordinates($newX, $newY)) {
                        $tiles[] = $tile;
                    }
                }
            }
        }

        return $tiles;
    }

    /**
     * 获取指定范围内的地图格子
     * @param int $startX 起始X坐标
     * @param int $startY 起始Y坐标
     * @param int $endX 结束X坐标
     * @param int $endY 结束Y坐标
     * @param bool $visibleOnly 是否只返回可见的格子
     * @return array 地图格子数组
     */
    public static function getTilesInRange($startX, $startY, $endX, $endY, $visibleOnly = false) {
        $db = Database::getInstance()->getConnection();

        // 确保坐标在地图范围内
        $startX = max(0, min(MAP_WIDTH - 1, $startX));
        $startY = max(0, min(MAP_HEIGHT - 1, $startY));
        $endX = max(0, min(MAP_WIDTH - 1, $endX));
        $endY = max(0, min(MAP_HEIGHT - 1, $endY));

        $query = "SELECT * FROM map_tiles WHERE x >= ? AND x <= ? AND y >= ? AND y <= ?";
        if ($visibleOnly) {
            $query .= " AND is_visible = 1";
        }

        $stmt = $db->prepare($query);
        $stmt->bind_param('iiii', $startX, $endX, $startY, $endY);
        $stmt->execute();
        $result = $stmt->get_result();

        $tiles = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tile = new Map($row['tile_id']);
                if ($tile->isValid()) {
                    $tiles[] = $tile;
                }
            }
        }

        $stmt->close();
        return $tiles;
    }

    /**
     * 获取用户可见的地图格子
     * @param int $userId 用户ID
     * @return array 地图格子数组
     */
    public static function getUserVisibleTiles($userId) {
        $db = Database::getInstance()->getConnection();

        $query = "SELECT * FROM map_tiles WHERE is_visible = 1 AND (owner_id = ? OR owner_id IS NULL)";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $tiles = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tile = new Map($row['tile_id']);
                if ($tile->isValid()) {
                    $tiles[] = $tile;
                }
            }
        }

        $stmt->close();
        return $tiles;
    }

    /**
     * 探索地图格子并在有新发现时原子扣除思考回路 / Explore tiles and atomically charge circuit points only for discoveries
     * @param int $userId 用户ID / User ID
     * @param int $x X坐标 / X coordinate
     * @param int $y Y坐标 / Y coordinate
     * @param int $armyId 可选的待命侦察军队ID / Optional idle scouting army ID
     * @return array|string 新发现的格子，失败时返回错误信息 / Discovered tiles or an error message
     */
    public static function exploreTiles($userId, $x, $y, $armyId = 0) {
        $userId = (int) $userId;
        $x = (int) $x;
        $y = (int) $y;
        $armyId = (int) $armyId;
        if ($userId <= 0 || $x < 0 || $x >= MAP_WIDTH || $y < 0 || $y >= MAP_HEIGHT) {
            return '坐标超出地图范围';
        }
        if ($armyId < 0) {
            return '侦察军队参数无效';
        }

        $db = Database::getInstance()->getConnection();
        $db->begin_transaction();

        try {
            lockSeasonForWorldAction($db);

            $query = "SELECT circuit_points
                      FROM users
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $userRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$userRow) {
                $db->rollback();
                return '用户信息无效';
            }

            // 浏览器不能指定半径；基础半径固定为3，侦察点每5点增加1且至多增加3 / The browser cannot set radius; base is 3, plus 1 per 5 scout points up to 3
            $baseRadius = 3;
            $bonusRadius = 0;
            if ($armyId > 0) {
                $query = "SELECT owner_id, status, current_x, current_y
                          FROM armies
                          WHERE army_id = ?
                          FOR UPDATE";
                $stmt = $db->prepare($query);
                $stmt->bind_param('i', $armyId);
                $stmt->execute();
                $armyResult = $stmt->get_result();
                $armyRow = $armyResult ? $armyResult->fetch_assoc() : null;
                $stmt->close();
                if (!$armyRow
                    || (int) $armyRow['owner_id'] !== $userId
                    || $armyRow['status'] !== 'idle') {
                    $db->rollback();
                    return '只能选择自己拥有的待命军队进行侦察';
                }

                $scoutingArmy = new Army($armyId);
                if (!$scoutingArmy->isValid()) {
                    $db->rollback();
                    return '侦察军队已经失效';
                }
                $scoutPoints = max(
                    0.0,
                    min(15.0, (float) $scoutingArmy->getScoutRangeBonus())
                );
                $bonusRadius = min(3, (int) floor($scoutPoints / 5));
                $effectiveRadius = $baseRadius + $bonusRadius;
                $armyDistance = abs((int) $armyRow['current_x'] - $x)
                    + abs((int) $armyRow['current_y'] - $y);
                if ($armyDistance > $effectiveRadius) {
                    $db->rollback();
                    return '选定军队距离探索中心过远';
                }
            }
            $radius = $baseRadius + $bonusRadius;

            // 未选择军队时，探索中心必须能从自己的城池、待命军队或领地抵达 / Without a selected army, the center must be reachable from an owned city, idle army, or territory
            if ($armyId === 0
                && !self::hasReachableAnchor($db, $userId, $x, $y, $radius)) {
                $db->rollback();
                return '探索地点距离你的城池、待命军队或领地过远';
            }

            $startX = max(0, $x - $radius);
            $startY = max(0, $y - $radius);
            $endX = min(MAP_WIDTH - 1, $x + $radius);
            $endY = min(MAP_HEIGHT - 1, $y + $radius);
            $query = "SELECT tile_id
                      FROM map_tiles
                      WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ?
                        AND is_visible = 0
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('iiii', $startX, $endX, $startY, $endY);
            $stmt->execute();
            $result = $stmt->get_result();
            $tileIds = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $tileIds[] = (int) $row['tile_id'];
            }
            $stmt->close();

            if (empty($tileIds)) {
                $db->commit();
                return [];
            }
            if ((int) $userRow['circuit_points'] < 1) {
                $db->rollback();
                return '思考回路不足';
            }

            $query = "UPDATE map_tiles
                      SET is_visible = 1
                      WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ?
                        AND is_visible = 0";
            $stmt = $db->prepare($query);
            $stmt->bind_param('iiii', $startX, $endX, $startY, $endY);
            if (!$stmt->execute()) {
                throw new RuntimeException('无法更新地图可见性 / Failed to reveal map tiles');
            }
            $stmt->close();

            $query = "UPDATE users
                      SET circuit_points = circuit_points - 1
                      WHERE user_id = ? AND circuit_points >= 1";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $userId);
            $charged = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$charged) {
                throw new RuntimeException('无法扣除思考回路 / Failed to charge circuit points');
            }

            $db->commit();
            $tiles = [];
            foreach ($tileIds as $tileId) {
                $tile = new Map($tileId);
                if ($tile->isValid()) {
                    $tiles[] = $tile;
                }
            }
            return $tiles;
        } catch (Throwable $exception) {
            $db->rollback();
            error_log('Map exploration failed: ' . $exception->getMessage());
            return '探索失败，请稍后再试';
        }
    }

    /**
     * 原子占领相邻格子并扣除两点思考回路 / Atomically occupy an adjacent tile and charge two circuit points
     * @param int $userId 用户ID / User ID
     * @param int $x X坐标 / X coordinate
     * @param int $y Y坐标 / Y coordinate
     * @return bool|string 成功返回true，失败返回错误信息 / True on success or an error message
     */
    public static function occupyTile($userId, $x, $y) {
        $userId = (int) $userId;
        $x = (int) $x;
        $y = (int) $y;
        if ($userId <= 0 || $x < 0 || $x >= MAP_WIDTH || $y < 0 || $y >= MAP_HEIGHT) {
            return '坐标超出地图范围';
        }

        $db = Database::getInstance()->getConnection();
        $db->begin_transaction();

        try {
            lockSeasonForWorldAction($db);

            $query = "SELECT circuit_points
                      FROM users
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $userRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$userRow) {
                $db->rollback();
                return '用户信息无效';
            }
            $occupationCost = max(0, (int) TERRITORY_OCCUPATION_COST);
            if ((int) $userRow['circuit_points'] < $occupationCost) {
                $db->rollback();
                return '思考回路不足';
            }

            $query = "SELECT tile_id, type, subtype, owner_id, is_visible
                      FROM map_tiles
                      WHERE x = ? AND y = ?
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $x, $y);
            $stmt->execute();
            $result = $stmt->get_result();
            $tile = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$tile) {
                $db->rollback();
                return '地图格子不存在';
            }
            if (!(bool) $tile['is_visible']) {
                $db->rollback();
                return '地图格子尚未被发现';
            }
            if ($tile['owner_id'] !== null) {
                $db->rollback();
                return '地图格子已被占领';
            }
            if (!in_array($tile['type'], ['empty', 'resource'], true)) {
                $db->rollback();
                return $tile['type'] === 'special'
                    ? '特殊地点必须通过赛季战占领'
                    : '该类型的地图格子不可直接占领';
            }
            if (!self::hasAdjacentTerritoryOrCity($db, $userId, $x, $y)) {
                $db->rollback();
                return '只能占领与你的领地或城池相邻的格子';
            }

            $query = "UPDATE map_tiles
                      SET owner_id = ?,
                          last_collection_time = CASE
                            WHEN type = 'resource' THEN NOW()
                            ELSE last_collection_time
                          END
                      WHERE tile_id = ? AND owner_id IS NULL";
            $stmt = $db->prepare($query);
            $tileId = (int) $tile['tile_id'];
            $stmt->bind_param('ii', $userId, $tileId);
            $occupied = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$occupied) {
                throw new RuntimeException('地图格子已经变化 / Tile ownership changed');
            }

            if ($occupationCost > 0) {
                $query = "UPDATE users
                          SET circuit_points = circuit_points - ?
                          WHERE user_id = ? AND circuit_points >= ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param(
                    'iii',
                    $occupationCost,
                    $userId,
                    $occupationCost
                );
                $charged = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$charged) {
                    throw new RuntimeException('无法扣除思考回路 / Failed to charge circuit points');
                }
            }

            self::recordGameplayEvent(
                $db,
                $userId,
                'territory_captured',
                1,
                'map_tile',
                $tileId
            );
            self::adjustCurrentSeasonTerritoryScore($db, $userId, 1);

            $db->commit();
            return true;
        } catch (Throwable $exception) {
            $db->rollback();
            error_log('Tile occupation failed: ' . $exception->getMessage());
            return '占领失败，地图状态可能已经变化';
        }
    }

    /**
     * 仅放弃自己拥有的普通领地 / Abandon only an owned ordinary territory
     * @param int $userId 用户ID / User ID
     * @param int $x X坐标 / X coordinate
     * @param int $y Y坐标 / Y coordinate
     * @return bool|string 成功返回true，失败返回错误信息 / True on success or an error message
     */
    public static function abandonTile($userId, $x, $y) {
        $userId = (int) $userId;
        $x = (int) $x;
        $y = (int) $y;
        if ($userId <= 0 || $x < 0 || $x >= MAP_WIDTH || $y < 0 || $y >= MAP_HEIGHT) {
            return '坐标超出地图范围';
        }

        $db = Database::getInstance()->getConnection();
        $db->begin_transaction();

        try {
            lockSeasonForWorldAction($db);

            // 赛季锁后采用与占领相同的玩家优先锁序，串行化同一玩家的领地变更 / After the season lock, match occupation's user-first order to serialize territory changes
            $query = "SELECT user_id, circuit_points, max_circuit_points
                      FROM users
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $userResult = $stmt->get_result();
            $userExists = $userResult && $userResult->num_rows === 1;
            $stmt->close();
            if (!$userExists) {
                $db->rollback();
                return '用户信息无效';
            }

            $query = "SELECT tile_id, type, owner_id
                      FROM map_tiles
                      WHERE x = ? AND y = ?
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $x, $y);
            $stmt->execute();
            $tileResult = $stmt->get_result();
            $tile = $tileResult ? $tileResult->fetch_assoc() : null;
            $stmt->close();
            if (!$tile
                || $tile['owner_id'] === null
                || (int) $tile['owner_id'] !== $userId
                || !in_array($tile['type'], ['empty', 'resource'], true)) {
                $db->rollback();
                return '地图格子不存在、不属于你或不能直接放弃';
            }

            $tileId = (int) $tile['tile_id'];
            $query = "SELECT garrison_id, owner_id, quantity
                      FROM territory_garrisons
                      WHERE tile_id = ?
                      ORDER BY garrison_id
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $tileId);
            $stmt->execute();
            $garrisonResult = $stmt->get_result();
            $hasGarrison = false;
            while ($garrisonResult && ($garrison = $garrisonResult->fetch_assoc())) {
                if ((int) $garrison['owner_id'] !== $userId) {
                    $stmt->close();
                    throw new RuntimeException('驻军拥有权与领地不一致 / Garrison ownership does not match the tile');
                }
                if ((int) $garrison['quantity'] > 0) {
                    $hasGarrison = true;
                }
            }
            $stmt->close();
            if ($hasGarrison) {
                $db->rollback();
                return '请先撤回全部驻军再放弃领地';
            }

            $query = "DELETE FROM territory_garrisons
                      WHERE tile_id = ? AND quantity = 0";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $tileId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法清理空驻军记录 / Failed to remove empty garrison rows');
            }
            $stmt->close();

            $query = "UPDATE map_tiles
                      SET owner_id = NULL,
                          last_collection_time = CASE
                            WHEN type = 'resource' THEN NULL
                            ELSE last_collection_time
                          END
                      WHERE tile_id = ? AND owner_id = ?
                        AND type IN ('empty', 'resource')";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $tileId, $userId);
            $abandoned = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$abandoned) {
                throw new RuntimeException('地图格子所有权已经变化 / Tile ownership changed');
            }

            // 普通领地成本随放弃原子返还，但余额仍受玩家上限约束 / Refund the ordinary-territory cost atomically while respecting the user's cap
            $occupationCost = max(0, (int) TERRITORY_OCCUPATION_COST);
            $query = "UPDATE users
                      SET circuit_points = LEAST(
                          max_circuit_points,
                          circuit_points + ?
                      )
                      WHERE user_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $occupationCost, $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法返还思考回路 / Failed to refund circuit points');
            }
            $stmt->close();

            self::recordGameplayEvent(
                $db,
                $userId,
                'territory_abandoned',
                1,
                'map_tile',
                $tileId
            );
            // 放弃时扣回净领地分，避免占领后立刻放弃反复刷分 / Remove the net territory point on abandonment to prevent capture-abandon farming
            self::adjustCurrentSeasonTerritoryScore($db, $userId, -1);

            $db->commit();
            return true;
        } catch (Throwable $exception) {
            $db->rollback();
            error_log('Tile abandonment failed: ' . $exception->getMessage());
            return '放弃失败，地图状态可能已经变化';
        }
    }

    /**
     * 检查探索中心是否靠近玩家控制点 / Check whether an exploration center is near a player-controlled anchor
     */
    private static function hasReachableAnchor($db, $userId, $x, $y, $radius) {
        // 按地图、城池、军队的固定顺序锁定首个可达锚点 / Lock the first reachable anchor in a stable map-city-army order
        $query = "SELECT tile_id
                  FROM map_tiles
                  WHERE owner_id = ? AND ABS(x - ?) + ABS(y - ?) <= ?
                  ORDER BY tile_id
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $db->prepare($query);
        $stmt->bind_param('iiii', $userId, $x, $y, $radius);
        $stmt->execute();
        $result = $stmt->get_result();
        $reachable = $result && $result->num_rows > 0;
        $stmt->close();
        if ($reachable) {
            return true;
        }

        $query = "SELECT city_id
                  FROM cities
                  WHERE owner_id = ? AND ABS(x - ?) + ABS(y - ?) <= ?
                  ORDER BY city_id
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $db->prepare($query);
        $stmt->bind_param('iiii', $userId, $x, $y, $radius);
        $stmt->execute();
        $result = $stmt->get_result();
        $reachable = $result && $result->num_rows > 0;
        $stmt->close();
        if ($reachable) {
            return true;
        }

        $query = "SELECT army_id
                  FROM armies
                  WHERE owner_id = ? AND status = 'idle'
                    AND ABS(current_x - ?) + ABS(current_y - ?) <= ?
                  ORDER BY army_id
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $db->prepare($query);
        $stmt->bind_param('iiii', $userId, $x, $y, $radius);
        $stmt->execute();
        $result = $stmt->get_result();
        $reachable = $result && $result->num_rows > 0;
        $stmt->close();

        return $reachable;
    }

    /**
     * 检查目标是否与玩家普通领地或城池曼哈顿相邻 / Check Manhattan adjacency to an owned ordinary territory or city
     * @param int $userId 玩家ID / User ID
     * @param int $x 目标X坐标 / Target X
     * @param int $y 目标Y坐标 / Target Y
     * @param bool $lockForUpdate 是否锁定锚点用于事务重验 / Whether to lock the anchor for transactional revalidation
     * @return bool 是否相邻 / Whether adjacent
     */
    public static function isAdjacentToUserControl(
        $userId,
        $x,
        $y,
        $lockForUpdate = false
    ) {
        $userId = (int) $userId;
        $x = (int) $x;
        $y = (int) $y;
        if ($userId <= 0
            || $x < 0
            || $x >= MAP_WIDTH
            || $y < 0
            || $y >= MAP_HEIGHT) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        return self::queryAdjacentTerritoryOrCity(
            $db,
            $userId,
            $x,
            $y,
            (bool) $lockForUpdate
        );
    }

    /**
     * 在已有事务内检查并锁定相邻控制点 / Check and lock an adjacent control point in an existing transaction
     */
    private static function hasAdjacentTerritoryOrCity($db, $userId, $x, $y) {
        return self::queryAdjacentTerritoryOrCity(
            $db,
            $userId,
            $x,
            $y,
            true
        );
    }

    /**
     * 查询相邻的普通领地或城池 / Query an adjacent ordinary territory or city
     */
    private static function queryAdjacentTerritoryOrCity(
        $db,
        $userId,
        $x,
        $y,
        $lockForUpdate
    ) {
        $query = "SELECT tile_id
                  FROM map_tiles
                  WHERE owner_id = ? AND type IN ('empty', 'resource')
                    AND ABS(x - ?) + ABS(y - ?) = 1
                  ORDER BY tile_id
                  LIMIT 1";
        if ($lockForUpdate) {
            $query .= " FOR UPDATE";
        }
        $stmt = $db->prepare($query);
        $stmt->bind_param('iii', $userId, $x, $y);
        $stmt->execute();
        $result = $stmt->get_result();
        $adjacent = $result && $result->num_rows > 0;
        $stmt->close();
        if ($adjacent) {
            return true;
        }

        $query = "SELECT city_id
                  FROM cities
                  WHERE owner_id = ? AND ABS(x - ?) + ABS(y - ?) = 1
                  ORDER BY city_id
                  LIMIT 1";
        if ($lockForUpdate) {
            $query .= " FOR UPDATE";
        }
        $stmt = $db->prepare($query);
        $stmt->bind_param('iii', $userId, $x, $y);
        $stmt->execute();
        $result = $stmt->get_result();
        $adjacent = $result && $result->num_rows > 0;
        $stmt->close();

        return $adjacent;
    }

    /**
     * 在当前事务内记录玩法事件 / Record a gameplay event inside the current transaction
     * @param mysqli $db 数据库连接 / Database connection
     * @param int $userId 用户ID / User ID
     * @param string $eventType 事件类型 / Event type
     * @param int $eventValue 事件值 / Event value
     * @param string $referenceType 引用类型 / Reference type
     * @param int $referenceId 引用ID / Reference ID
     * @return void
     */
    private static function recordGameplayEvent(
        $db,
        $userId,
        $eventType,
        $eventValue,
        $referenceType,
        $referenceId
    ) {
        $eventValue = max(1, min(2147483647, (int) $eventValue));
        $query = "INSERT INTO gameplay_events
                    (user_id, event_type, event_value, reference_type, reference_id)
                  VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->bind_param(
            'isisi',
            $userId,
            $eventType,
            $eventValue,
            $referenceType,
            $referenceId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法记录地图玩法事件 / Failed to record map gameplay event');
        }
        $stmt->close();
    }

    /**
     * 对当前开放赛季的净领地分执行有界增减 / Apply a bounded delta to the current open season's net territory score
     * @param mysqli $db 数据库连接 / Database connection
     * @param int $userId 用户ID / User ID
     * @param int $delta 分数增量 / Score delta
     * @return void
     */
    private static function adjustCurrentSeasonTerritoryScore($db, $userId, $delta) {
        $delta = max(-1, min(1, (int) $delta));
        if ($delta === 0) {
            return;
        }

        $query = "SELECT season_id
                  FROM seasons
                  WHERE ended_at IS NULL
                    AND status IN ('active', 'victory_countdown')
                  ORDER BY season_number DESC
                  LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $season = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$season) {
            return;
        }

        $seasonId = (int) $season['season_id'];
        $initialScore = max(0, $delta);
        $query = "INSERT INTO season_scores
                    (season_id, user_id, territory_score)
                  VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                    territory_score = LEAST(
                        2147483647,
                        GREATEST(0, territory_score + ?)
                    )";
        $stmt = $db->prepare($query);
        $stmt->bind_param(
            'iiii',
            $seasonId,
            $userId,
            $initialScore,
            $delta
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法更新赛季领地分 / Failed to update season territory score');
        }
        $stmt->close();
    }

    /**
     * 获取上次收集时间
     * @return string|null
     */
    public function getLastCollectionTime() {
        return $this->lastCollectionTime;
    }

    /**
     * 设置上次收集时间
     * @param string $time 时间字符串
     * @return bool
     */
    public function setLastCollectionTime($time) {
        if (!$this->isValid) {
            return false;
        }

        $query = "UPDATE map_tiles SET last_collection_time = ? WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('si', $time, $this->tileId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->lastCollectionTime = $time;
            return true;
        }

        return false;
    }

    /**
     * 获取收集效率
     * @return int
     */
    public function getCollectionEfficiency() {
        return $this->collectionEfficiency ?? 100;
    }

    /**
     * 设置收集效率
     * @param int $efficiency 效率值
     * @return bool
     */
    public function setCollectionEfficiency($efficiency) {
        if (!$this->isValid) {
            return false;
        }

        $query = "UPDATE map_tiles SET collection_efficiency = ? WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $efficiency, $this->tileId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->collectionEfficiency = $efficiency;
            return true;
        }

        return false;
    }

    /**
     * 收集资源
     * @param int $userId 用户ID
     * @return int|bool 成功返回收集的资源量，失败返回false
     */
    public function collectResource($userId) {
        if (!$this->isValid || $this->getType() != 'resource' || $this->getOwnerId() != $userId) {
            return false;
        }

        $resourceColumns = [
            'bright' => 'bright_crystal',
            'warm' => 'warm_crystal',
            'cold' => 'cold_crystal',
            'green' => 'green_crystal',
            'day' => 'day_crystal',
            'night' => 'night_crystal'
        ];
        $resourceType = $this->getSubtype();
        if (!isset($resourceColumns[$resourceType])) {
            return false;
        }

        $userId = (int) $userId;
        $column = $resourceColumns[$resourceType];
        $this->db->begin_transaction();

        try {
            lockSeasonForWorldAction($this->db);

            // 赛季锁后依次锁定玩家、资源点与余额，避免重复收集及跨操作锁序冲突 / After the season lock, lock user, tile, then wallet to prevent duplicate collection and lock-order conflicts
            $query = "SELECT user_id FROM users WHERE user_id = ? FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $userResult = $stmt->get_result();
            $userExists = $userResult && $userResult->num_rows === 1;
            $stmt->close();
            if (!$userExists) {
                $this->db->rollback();
                return false;
            }

            $storageLimit = Resource::getUserResourceStorageCapacity($userId);
            $query = "SELECT owner_id, type, subtype, resource_amount,
                             last_collection_time, collection_efficiency
                      FROM map_tiles
                      WHERE tile_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->tileId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tile = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$tile
                || (int) $tile['owner_id'] !== $userId
                || $tile['type'] !== 'resource'
                || $tile['subtype'] !== $resourceType) {
                $this->db->rollback();
                return false;
            }

            $nowString = date('Y-m-d H:i:s');
            if ($tile['last_collection_time'] === null) {
                $query = "UPDATE map_tiles
                          SET last_collection_time = ?
                          WHERE tile_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('si', $nowString, $this->tileId);
                $stmt->execute();
                $stmt->close();
                $this->db->commit();
                $this->lastCollectionTime = $nowString;
                return 0;
            }

            $hoursPassed = (time() - strtotime($tile['last_collection_time'])) / 3600;
            if ($hoursPassed < 0.1) {
                $this->db->commit();
                return 0;
            }
            $resourceToCollect = min(
                max(0, (int) $tile['resource_amount']),
                (int) floor($hoursPassed * max(0, (int) $tile['collection_efficiency']))
            );
            if ($resourceToCollect <= 0) {
                $this->db->commit();
                return 0;
            }

            $query = "SELECT {$column} AS amount
                      FROM resources
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $wallet = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$wallet) {
                throw new RuntimeException('玩家资源记录不存在 / Resource wallet is missing');
            }

            $currentAmount = max(0, (int) $wallet['amount']);
            $resourceToCollect = min(
                $resourceToCollect,
                max(0, $storageLimit - $currentAmount)
            );
            if ($resourceToCollect <= 0) {
                $this->db->commit();
                return 0;
            }

            $newAmount = $currentAmount + $resourceToCollect;
            $query = "UPDATE resources
                      SET {$column} = ?, last_update = NOW()
                      WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $newAmount, $userId);
            if (!$stmt->execute()) {
                throw new RuntimeException('无法更新玩家资源 / Failed to update player resources');
            }
            $stmt->close();

            $remainingResource = max(
                0,
                (int) $tile['resource_amount'] - $resourceToCollect
            );
            $query = "UPDATE map_tiles
                      SET resource_amount = ?, last_collection_time = ?
                      WHERE tile_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'isi',
                $remainingResource,
                $nowString,
                $this->tileId
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('无法更新资源点 / Failed to update resource tile');
            }
            $stmt->close();

            self::recordGameplayEvent(
                $this->db,
                $userId,
                'resource_collected',
                $resourceToCollect,
                'map_tile',
                (int) $this->tileId
            );

            $this->db->commit();
            $this->resourceAmount = $remainingResource;
            $this->lastCollectionTime = $nowString;
            return $resourceToCollect;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Resource collection failed: ' . $exception->getMessage());
            return false;
        }
    }



    /**
     * 设置NPC城池等级
     * @param int $level 等级
     * @return bool
     */
    public function setNpcLevel($level) {
        if (!$this->isValid) {
            return false;
        }

        $query = "UPDATE map_tiles SET npc_level = ? WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $level, $this->tileId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->npcLevel = $level;
            return true;
        }

        return false;
    }

    /**
     * 获取NPC城池驻军数量
     * @return int
     */
    public function getNpcGarrison() {
        return $this->npcGarrison ?? 0;
    }

    /**
     * 设置NPC城池驻军数量
     * @param int $garrison 驻军数量
     * @return bool
     */
    public function setNpcGarrison($garrison) {
        if (!$this->isValid) {
            return false;
        }

        $query = "UPDATE map_tiles SET npc_garrison = ? WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $garrison, $this->tileId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->npcGarrison = $garrison;
            return true;
        }

        return false;
    }

    /**
     * 获取NPC城池重生时间
     * @return string|null
     */
    public function getNpcRespawnTime() {
        return $this->npcRespawnTime;
    }

    /**
     * 设置NPC城池重生时间
     * @param string $time 时间字符串
     * @return bool
     */
    public function setNpcRespawnTime($time) {
        if (!$this->isValid) {
            return false;
        }

        $query = "UPDATE map_tiles SET npc_respawn_time = ? WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('si', $time, $this->tileId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->npcRespawnTime = $time;
            return true;
        }

        return false;
    }

    /**
     * 重生NPC城池
     * @return bool
     */
    public function respawnNpcFort() {
        if (!$this->isValid || $this->type != 'npc_fort') {
            return false;
        }

        // 检查是否到达重生时间
        if ($this->npcRespawnTime && strtotime($this->npcRespawnTime) > time()) {
            return false;
        }

        // 计算新的NPC等级
        $newLevel = $this->calculateNewNpcLevel();

        // 计算新的驻军数量
        $newGarrison = $this->calculateNpcGarrison($newLevel);

        // 更新NPC城池信息
        $query = "UPDATE map_tiles SET npc_level = ?, npc_garrison = ?, npc_respawn_time = NULL, owner_id = NULL WHERE tile_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iii', $newLevel, $newGarrison, $this->tileId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->npcLevel = $newLevel;
            $this->npcGarrison = $newGarrison;
            $this->npcRespawnTime = null;
            $this->ownerId = null;
            return true;
        }

        return false;
    }

    /**
     * 计算新的NPC等级
     * @return int
     */
    private function calculateNewNpcLevel() {
        $currentLevel = $this->getNpcLevel();
        $rand = mt_rand(1, 100);

        if ($rand <= 80) {
            // 80%的概率保持原等级
            return $currentLevel;
        } elseif ($rand <= 90) {
            // 10%的概率升级
            return min(5, $currentLevel + 1);
        } else {
            // 10%的概率降级
            return max(1, $currentLevel - 1);
        }
    }

    /**
     * 计算NPC城池驻军数量
     * @param int $level NPC等级
     * @return int
     */
    private function calculateNpcGarrison($level) {
        return NPC_FORT_BASE_GARRISON * pow($level, NPC_FORT_GARRISON_COEFFICIENT);
    }

    /**
     * 检查并重生所有NPC城池
     * @return int 重生的NPC城池数量
     */
    public static function respawnAllNpcForts() {
        $db = Database::getInstance()->getConnection();

        $query = "SELECT tile_id FROM map_tiles WHERE type = 'npc_fort' AND npc_respawn_time IS NOT NULL AND npc_respawn_time <= NOW()";
        $result = $db->query($query);

        $respawnedCount = 0;

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $fort = new Map($row['tile_id']);
                if ($fort->isValid() && $fort->respawnNpcFort()) {
                    $respawnedCount++;
                }
            }
        }

        return $respawnedCount;
    }
}

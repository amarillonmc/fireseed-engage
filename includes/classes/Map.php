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
                // 获取城池名称
                $city = new City($this->ownerId);
                if ($city->isValid()) {
                    return $city->getName();
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
     * 探索地图格子
     * @param int $userId 用户ID
     * @param int $x X坐标
     * @param int $y Y坐标
     * @param int $radius 探索半径
     * @return array 新发现的地图格子数组
     */
    public static function exploreTiles($userId, $x, $y, $radius = 1) {
        // 检查坐标是否在地图范围内
        if ($x < 0 || $x >= MAP_WIDTH || $y < 0 || $y >= MAP_HEIGHT) {
            return [];
        }

        $newlyDiscoveredTiles = [];

        // 获取指定范围内的地图格子
        $startX = max(0, $x - $radius);
        $startY = max(0, $y - $radius);
        $endX = min(MAP_WIDTH - 1, $x + $radius);
        $endY = min(MAP_HEIGHT - 1, $y + $radius);

        $tiles = self::getTilesInRange($startX, $startY, $endX, $endY, false);

        foreach ($tiles as $tile) {
            // 如果地图格子尚未被发现，设置为可见
            if (!$tile->isVisible()) {
                $tile->setVisible(true);
                $newlyDiscoveredTiles[] = $tile;
            }
        }

        return $newlyDiscoveredTiles;
    }

    /**
     * 占领地图格子
     * @param int $userId 用户ID
     * @param int $x X坐标
     * @param int $y Y坐标
     * @return bool|string 成功返回true，失败返回错误信息
     */
    public static function occupyTile($userId, $x, $y) {
        // 检查坐标是否在地图范围内
        if ($x < 0 || $x >= MAP_WIDTH || $y < 0 || $y >= MAP_HEIGHT) {
            return '坐标超出地图范围';
        }

        // 获取地图格子
        $tile = new Map();
        if (!$tile->loadByCoordinates($x, $y)) {
            return '地图格子不存在';
        }

        // 检查地图格子是否可见
        if (!$tile->isVisible()) {
            return '地图格子尚未被发现';
        }

        // 检查地图格子是否已被占领
        if ($tile->getOwnerId() !== null) {
            return '地图格子已被占领';
        }

        // 检查地图格子类型是否可占领
        $type = $tile->getType();
        if ($type != 'empty' && $type != 'resource') {
            return '该类型的地图格子不可占领';
        }

        // 占领地图格子
        if ($tile->setOwner($userId)) {
            return true;
        }

        return '占领地图格子失败';
    }

    /**
     * 放弃地图格子
     * @param int $userId 用户ID
     * @param int $x X坐标
     * @param int $y Y坐标
     * @return bool|string 成功返回true，失败返回错误信息
     */
    public static function abandonTile($userId, $x, $y) {
        // 检查坐标是否在地图范围内
        if ($x < 0 || $x >= MAP_WIDTH || $y < 0 || $y >= MAP_HEIGHT) {
            return '坐标超出地图范围';
        }

        // 获取地图格子
        $tile = new Map();
        if (!$tile->loadByCoordinates($x, $y)) {
            return '地图格子不存在';
        }

        // 检查地图格子是否属于该用户
        if ($tile->getOwnerId() != $userId) {
            return '地图格子不属于该用户';
        }

        // 检查地图格子类型是否为玩家城池
        if ($tile->getType() == 'player_city') {
            return '玩家城池不能直接放弃，请先摧毁城池';
        }

        // 放弃地图格子
        if ($tile->setOwner(null)) {
            return true;
        }

        return '放弃地图格子失败';
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

        // 获取上次收集时间
        $lastCollectionTime = $this->getLastCollectionTime();
        if (!$lastCollectionTime) {
            // 如果是首次收集，设置当前时间为上次收集时间
            $this->setLastCollectionTime(date('Y-m-d H:i:s'));
            return 0;
        }

        // 计算时间间隔（小时）
        $now = time();
        $lastCollection = strtotime($lastCollectionTime);
        $hoursPassed = ($now - $lastCollection) / 3600;

        // 如果时间间隔太短，不进行收集
        if ($hoursPassed < 0.1) { // 至少6分钟
            return 0;
        }

        // 计算应收集的资源量
        $efficiency = $this->getCollectionEfficiency();
        $resourceToCollect = floor($hoursPassed * $efficiency);

        // 检查资源点剩余资源量
        $remainingResource = $this->getResourceAmount();
        if ($resourceToCollect > $remainingResource) {
            $resourceToCollect = $remainingResource;
        }

        // 如果没有资源可收集，返回
        if ($resourceToCollect <= 0) {
            return 0;
        }

        // 获取资源类型
        $resourceType = $this->getSubtype();

        // 获取用户资源
        $resource = new Resource($userId);
        if (!$resource->isValid()) {
            return false;
        }

        // 检查资源存储上限
        $storageLimit = $resource->getStorageLimit($resourceType);
        $currentResource = $resource->getResourceByType($resourceType);

        if ($currentResource >= $storageLimit) {
            return 0; // 资源已满
        }

        // 计算实际可添加的资源量
        $canAdd = $storageLimit - $currentResource;
        if ($resourceToCollect > $canAdd) {
            $resourceToCollect = $canAdd;
        }

        // 添加资源
        $resource->addResourceByType($resourceType, $resourceToCollect);

        // 减少资源点的资源量
        $this->setResourceAmount($remainingResource - $resourceToCollect);

        // 更新上次收集时间
        $this->setLastCollectionTime(date('Y-m-d H:i:s'));

        return $resourceToCollect;
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

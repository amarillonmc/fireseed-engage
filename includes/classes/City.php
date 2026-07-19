<?php
// 种火集结号 - 城池类

class City {
    /**
     * 驻城百分比加成上限，防止异常技能数据产生无界数值 / Maximum city bonus percentage to bound malformed skill data
     */
    const MAX_ASSIGNED_GENERAL_BONUS_PERCENT = 1000;

    private $db;
    private $cityId;
    private $name;
    private $ownerId;
    private $x;
    private $y;
    private $level;
    private $durability;
    private $maxDurability;
    private $isMainCity;
    private $isValid = false;

    /**
     * 构造函数
     * @param int $cityId 城池ID
     */
    public function __construct($cityId = null) {
        $this->db = Database::getInstance()->getConnection();

        if ($cityId !== null) {
            $this->cityId = $cityId;
            $this->loadCityData();
        }
    }

    /**
     * 加载城池数据
     */
    private function loadCityData() {
        $query = "SELECT * FROM cities WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->cityId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $cityData = $result->fetch_assoc();
            $this->name = $cityData['name'];
            $this->ownerId = $cityData['owner_id'];
            $this->x = $cityData['x'];
            $this->y = $cityData['y'];
            $this->level = $cityData['level'];
            $this->durability = $cityData['durability'];
            $this->maxDurability = $cityData['max_durability'];
            $this->isMainCity = $cityData['is_main_city'];
            $this->isValid = true;
        }

        $stmt->close();
    }

    /**
     * 检查城池是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }

    /**
     * 获取城池ID
     * @return int
     */
    public function getCityId() {
        return $this->cityId;
    }

    /**
     * 获取城池名称
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     * 获取拥有者ID
     * @return int
     */
    public function getOwnerId() {
        return $this->ownerId;
    }

    /**
     * 获取城池坐标
     * @return array [x, y]
     */
    public function getCoordinates() {
        return [$this->x, $this->y];
    }

    /**
     * 获取城池等级
     * @return int
     */
    public function getLevel() {
        return $this->level;
    }

    /**
     * 获取城池耐久度
     * @return int
     */
    public function getDurability() {
        return $this->durability;
    }

    /**
     * 获取城池最大耐久度
     * @return int
     */
    public function getMaxDurability() {
        return $this->maxDurability;
    }

    /**
     * 检查是否为主城
     * @return bool
     */
    public function isMainCity() {
        return $this->isMainCity;
    }

    /**
     * 设置城池名称
     * @param string $name 城池名称
     * @return bool
     */
    public function setName($name) {
        if (!$this->isValid) {
            return false;
        }

        $query = "UPDATE cities SET name = ? WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('si', $name, $this->cityId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->name = $name;
            return true;
        }

        return false;
    }

    /**
     * 升级城池
     * @return bool
     */
    public function upgrade() {
        if (!$this->isValid) {
            return false;
        }

        $newLevel = $this->level + 1;
        $newMaxDurability = $this->maxDurability * 1.2; // 每升一级增加20%最大耐久度

        $query = "UPDATE cities SET level = ?, max_durability = ? WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('idi', $newLevel, $newMaxDurability, $this->cityId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->level = $newLevel;
            $this->maxDurability = $newMaxDurability;
            return true;
        }

        return false;
    }

    /**
     * 修复城池耐久度
     * @param int $amount 修复量
     * @return bool
     */
    public function repair($amount) {
        if (!$this->isValid || $amount <= 0) {
            return false;
        }

        $newDurability = $this->durability + $amount;
        if ($newDurability > $this->maxDurability) {
            $newDurability = $this->maxDurability;
        }

        $query = "UPDATE cities SET durability = ? WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('di', $newDurability, $this->cityId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->durability = $newDurability;
            return true;
        }

        return false;
    }

    /**
     * 减少城池耐久度
     * @param int $amount 减少量
     * @return bool
     */
    public function reduceDurability($amount) {
        if (!$this->isValid || $amount <= 0) {
            return false;
        }

        $newDurability = $this->durability - $amount;
        if ($newDurability < 0) {
            $newDurability = 0;
        }

        $query = "UPDATE cities SET durability = ? WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('di', $newDurability, $this->cityId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->durability = $newDurability;
            return true;
        }

        return false;
    }

    /**
     * 创建新城池
     * @param string $name 城池名称
     * @param int $ownerId 拥有者ID
     * @param int $x X坐标
     * @param int $y Y坐标
     * @param bool $isMainCity 是否为主城
     * @return bool|int 成功返回城池ID，失败返回false
     */
    public function createCity($name, $ownerId, $x, $y, $isMainCity = false) {
        // 检查坐标是否已被占用
        $query = "SELECT city_id FROM cities WHERE x = ? AND y = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $x, $y);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $stmt->close();
            return false; // 坐标已被占用
        }

        $stmt->close();

        // 检查地图格子是否存在且可用
        $tile = new Map();
        if (!$tile->loadByCoordinates($x, $y)) {
            return false; // 地图格子不存在
        }

        if ($tile->getType() != 'empty' || $tile->getOwnerId() !== null) {
            return false; // 地图格子不可用
        }

        // 如果是主城，检查用户是否已有主城
        if ($isMainCity) {
            $query = "SELECT city_id FROM cities WHERE owner_id = ? AND is_main_city = 1";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $ownerId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $stmt->close();
                return false; // 用户已有主城
            }

            $stmt->close();
        }

        // 创建新城池
        $level = 1;
        $durability = 1000;
        $maxDurability = 1000;
        $isMainCityInt = $isMainCity ? 1 : 0;

        $query = "INSERT INTO cities (name, owner_id, x, y, level, durability, max_durability, is_main_city)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('siiidddi', $name, $ownerId, $x, $y, $level, $durability, $maxDurability, $isMainCityInt);
        $result = $stmt->execute();

        if ($result) {
            $cityId = $this->db->insert_id;
            $stmt->close();

            // 更新地图格子
            $tile->setOwner($ownerId);
            $tile->setVisible(true);

            // 更新地图格子类型为玩家城池
            $query = "UPDATE map_tiles SET type = 'player_city' WHERE x = ? AND y = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $x, $y);
            $stmt->execute();
            $stmt->close();

            // 设置对象属性
            $this->cityId = $cityId;
            $this->name = $name;
            $this->ownerId = $ownerId;
            $this->x = $x;
            $this->y = $y;
            $this->level = $level;
            $this->durability = $durability;
            $this->maxDurability = $maxDurability;
            $this->isMainCity = $isMainCity;
            $this->isValid = true;

            return $cityId;
        }

        $stmt->close();
        return false;
    }

    /**
     * 获取城池中的设施
     * @return array 设施数组
     */
    public function getFacilities() {
        if (!$this->isValid) {
            return [];
        }

        $facilities = [];

        $query = "SELECT facility_id FROM facilities WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->cityId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $facility = new Facility($row['facility_id']);
                if ($facility->isValid()) {
                    $facilities[] = $facility;
                }
            }
        }

        $stmt->close();
        return $facilities;
    }

    /**
     * 获取城池中的士兵
     * @return array 士兵数组
     */
    public function getSoldiers() {
        if (!$this->isValid) {
            return [];
        }

        $soldiers = [];

        $query = "SELECT soldier_id FROM soldiers WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->cityId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $soldier = new Soldier($row['soldier_id']);
                if ($soldier->isValid()) {
                    $soldiers[] = $soldier;
                }
            }
        }

        $stmt->close();
        return $soldiers;
    }

    /**
     * 获取城池中指定类型的士兵
     * @param string $type 士兵类型
     * @return Soldier|null 士兵对象，如果不存在则返回null
     */
    public function getSoldierByType($type) {
        if (!$this->isValid) {
            return null;
        }

        $query = "SELECT soldier_id FROM soldiers WHERE city_id = ? AND type = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('is', $this->cityId, $type);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();

            $soldier = new Soldier($row['soldier_id']);
            if ($soldier->isValid()) {
                return $soldier;
            }
        }

        $stmt->close();
        return null;
    }

    /**
     * 获取用户的所有城池
     * @param int $userId 用户ID
     * @return array 城池数组
     */
    public static function getUserCities($userId) {
        $db = Database::getInstance()->getConnection();

        $query = "SELECT city_id FROM cities WHERE owner_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $cities = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $city = new City($row['city_id']);
                if ($city->isValid()) {
                    $cities[] = $city;
                }
            }
        }

        $stmt->close();
        return $cities;
    }

    /**
     * 获取用户的主城
     * @param int $userId 用户ID
     * @return City|null 主城对象，如果没有则返回null
     */
    public static function getUserMainCity($userId) {
        $db = Database::getInstance()->getConnection();

        $query = "SELECT city_id FROM cities WHERE owner_id = ? AND is_main_city = 1";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();

            $city = new City($row['city_id']);
            if ($city->isValid()) {
                return $city;
            }
        }

        $stmt->close();
        return null;
    }

    /**
     * 创建初始玩家城池 / Create a player's initial city
     * @param int $userId 用户ID / User ID
     * @return bool|int 成功返回城池ID，失败返回false / City ID on success, false on failure
     */
    public static function createInitialPlayerCity($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $db->begin_transaction();

        try {
            lockSeasonForWorldAction($db);
            $cityId = self::createInitialPlayerCityInCurrentTransaction(
                $userId
            );
            $db->commit();
            return $cityId;
        } catch (Throwable $exception) {
            $db->rollback();
            error_log(
                'Initial city creation failed: ' . $exception->getMessage()
            );
            return false;
        }
    }

    /**
     * 在调用者事务中创建初始主城 / Create an initial main city in the caller's transaction
     *
     * 调用者必须已经开启事务并锁定当前赛季；本方法不会提交或回滚。
     * The caller must already own a transaction and the current-season lock;
     * this method never commits or rolls back.
     *
     * @param int $userId 用户ID / User ID
     * @return int 城池ID / City ID
     */
    public static function createInitialPlayerCityInCurrentTransaction($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            throw new InvalidArgumentException(
                '用户参数无效 / Invalid user parameter'
            );
        }

        $db = Database::getInstance()->getConnection();

        // 玩家锁会串行化同一账号的并发首访。 / The user lock serializes concurrent first visits for one account.
        $query = "SELECT user_id, username
                  FROM users
                  WHERE user_id = ?
                  FOR UPDATE";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$user) {
            throw new RuntimeException(
                '用户不存在 / User does not exist'
            );
        }

        // 锁内重验使重复请求复用已经创建的主城。 / Reuse a main city created by an earlier request.
        $query = "SELECT city_id
                  FROM cities
                  WHERE owner_id = ? AND is_main_city = 1
                  ORDER BY city_id
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $existingCity = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($existingCity) {
            return (int) $existingCity['city_id'];
        }

        $cityName = (string) $user['username'] . '的城池';
        $centerX = (int) MAP_CENTER_X;
        $centerY = (int) MAP_CENTER_Y;

        for ($radius = 10; $radius <= 100; $radius += 10) {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $angle = mt_rand(0, 360) * M_PI / 180;
                $distance = mt_rand(0, $radius);
                $x = (int) round($centerX + $distance * cos($angle));
                $y = (int) round($centerY + $distance * sin($angle));
                $x = max(0, min(MAP_WIDTH - 1, $x));
                $y = max(0, min(MAP_HEIGHT - 1, $y));

                // 只锁候选点，避免在大地图上取得范围锁。 / Lock only one candidate and avoid broad range locks.
                $query = "SELECT tile_id, type, owner_id
                          FROM map_tiles
                          WHERE x = ? AND y = ?
                          FOR UPDATE";
                $stmt = $db->prepare($query);
                $stmt->bind_param('ii', $x, $y);
                $stmt->execute();
                $result = $stmt->get_result();
                $tile = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                if (!$tile
                    || $tile['type'] !== 'empty'
                    || $tile['owner_id'] !== null) {
                    continue;
                }

                $level = 1;
                $durability = 1000;
                $maxDurability = 1000;
                $isMainCity = 1;
                $query = "INSERT INTO cities
                             (name, owner_id, x, y, level, durability,
                              max_durability, is_main_city)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $db->prepare($query);
                $stmt->bind_param(
                    'siiiiiii',
                    $cityName,
                    $userId,
                    $x,
                    $y,
                    $level,
                    $durability,
                    $maxDurability,
                    $isMainCity
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException(
                        '创建主城失败 / Failed to create the main city'
                    );
                }
                $cityId = (int) $db->insert_id;
                $stmt->close();

                $query = "UPDATE map_tiles
                          SET type = 'player_city',
                              subtype = NULL,
                              owner_id = ?,
                              resource_amount = NULL,
                              npc_level = NULL,
                              npc_garrison = 0,
                              npc_respawn_time = NULL,
                              is_visible = 1
                          WHERE tile_id = ?
                            AND type = 'empty'
                            AND owner_id IS NULL";
                $stmt = $db->prepare($query);
                $tileId = (int) $tile['tile_id'];
                $stmt->bind_param('ii', $userId, $tileId);
                $updated = $stmt->execute()
                    && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$updated || !self::createInitialFacilities($cityId)) {
                    throw new RuntimeException(
                        '初始化主城失败 / Failed to initialize the main city'
                    );
                }

                return $cityId;
            }
        }

        throw new RuntimeException(
            '没有可用的主城位置 / No initial-city location is available'
        );
    }

    /**
     * 创建初始设施 / Create initial facilities
     * @param int $cityId 城池ID / City ID
     * @return bool 是否全部创建成功 / Whether every facility was created
     */
    private static function createInitialFacilities($cityId) {
        // 创建总督府 / Create the governor office
        $governorOffice = new Facility();
        if (!$governorOffice->createFacility(
            $cityId,
            'governor_office',
            null,
            1,
            12,
            12
        )) {
            return false;
        }

        // 六系资源设施与本企划世界观保持一致 / Create one facility for each of the six local resources
        $resourcePositions = [
            'bright' => [1, 1],
            'warm' => [22, 1],
            'cold' => [1, 22],
            'green' => [22, 22],
            'day' => [1, 12],
            'night' => [22, 12]
        ];
        foreach ($resourcePositions as $resourceType => $position) {
            $resourceProduction = new Facility();
            if (!$resourceProduction->createFacility(
                $cityId,
                'resource_production',
                $resourceType,
                1,
                $position[0],
                $position[1]
            )) {
                return false;
            }
        }

        // 创建兵营 / Create the barracks
        $barracks = new Facility();
        if (!$barracks->createFacility(
            $cityId,
            'barracks',
            null,
            1,
            10,
            12
        )) {
            return false;
        }

        // 创建研究所 / Create the research lab
        $researchLab = new Facility();
        if (!$researchLab->createFacility(
            $cityId,
            'research_lab',
            null,
            1,
            14,
            12
        )) {
            return false;
        }

        return true;
    }

    /**
     * 汇总存活驻城武将提供的内政加成 / Aggregate internal-affairs bonuses from living assigned generals
     *
     * 仅接受生产、训练、城防和建造相关键；攻击与行军等战斗键不会进入城池数值。
     * Only production, training, city-defense, and construction keys are accepted;
     * combat keys such as attack and march speed never enter city calculations.
     *
     * @return array<string,float> 规范化后的百分比加成 / Normalized percentage bonuses
     */
    public function getAssignedGeneralCityBonuses() {
        $totals = [
            'production' => 0.0,
            'training_speed' => 0.0,
            'defense' => 0.0,
            'build_speed' => 0.0
        ];

        if (!$this->isValid) {
            return $totals;
        }

        // 在查询层校验分配、所有权、启用状态与生命值 / Validate assignment, ownership, active state, and HP in the query
        $query = "SELECT g.general_id
                  FROM general_assignments a
                  INNER JOIN generals g ON g.general_id = a.general_id
                  WHERE a.assignment_type = 'city'
                    AND a.target_id = ?
                    AND g.owner_id = ?
                    AND g.is_active = 1
                    AND g.hp > 0
                  ORDER BY a.assignment_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $this->cityId, $this->ownerId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && $row = $result->fetch_assoc()) {
            $general = new General((int) $row['general_id']);
            if (!$general->isValid()
                || (int) $general->getOwnerId() !== (int) $this->ownerId
                || (int) $general->getHp() <= 0) {
                continue;
            }

            // getBonus('city') 已合并基础值与技能值，不能再次累加同一技能 / getBonus('city') already merges base and skill values
            $bonus = $general->getBonus('city');

            $totals['production'] += self::readNonNegativeBonus($bonus, 'production');
            $totals['training_speed'] += self::readNonNegativeBonus($bonus, 'training_speed');
            $totals['build_speed'] += self::readNonNegativeBonus($bonus, 'build_speed');
            $totals['build_speed'] += self::readNonNegativeBonus($bonus, 'construction_speed');

            // 泛用 defense 技能属于战斗效果；这里只按武将基础属性重建驻城防御 / Generic defense skills are combat effects; rebuild city defense from base attributes only
            $totals['defense'] += max(
                0.0,
                (float) $general->getIntelligence() * 0.5
                    + (float) $general->getDefense() * 0.3
            );
            $totals['defense'] += self::readNonNegativeBonus($bonus, 'city_defense');
        }

        $stmt->close();

        foreach ($totals as $key => $value) {
            $totals[$key] = self::clampAssignedGeneralBonus($value);
        }

        return $totals;
    }

    /**
     * 从效果数组读取非负有限数值 / Read a finite non-negative value from an effect array
     * @param array $effects 效果数组 / Effect array
     * @param string $key 效果键 / Effect key
     * @return float 安全数值 / Safe value
     */
    private static function readNonNegativeBonus($effects, $key) {
        if (!is_array($effects) || !isset($effects[$key]) || !is_numeric($effects[$key])) {
            return 0.0;
        }

        $value = (float) $effects[$key];
        if (!is_finite($value)) {
            return 0.0;
        }

        return max(0.0, $value);
    }

    /**
     * 将百分比加成限制在安全范围 / Clamp a percentage bonus to a safe range
     * @param mixed $bonusPercentage 原始百分比 / Raw percentage
     * @return float 规范化百分比 / Normalized percentage
     */
    public static function clampAssignedGeneralBonus($bonusPercentage) {
        if (!is_numeric($bonusPercentage)) {
            return 0.0;
        }

        $normalized = (float) $bonusPercentage;
        if (!is_finite($normalized)) {
            return 0.0;
        }

        return min(
            (float) self::MAX_ASSIGNED_GENERAL_BONUS_PERCENT,
            max(0.0, $normalized)
        );
    }

    /**
     * 对非负基础值应用百分比增益 / Apply a percentage increase to a non-negative base value
     * @param int|float $baseValue 基础值 / Base value
     * @param int|float $bonusPercentage 增益百分比 / Bonus percentage
     * @return float 调整后的值 / Adjusted value
     */
    public static function applyPercentageBonus($baseValue, $bonusPercentage) {
        if (!is_numeric($baseValue) || !is_finite((float) $baseValue)) {
            return 0.0;
        }

        $safeBase = max(0.0, (float) $baseValue);
        $safeBonus = self::clampAssignedGeneralBonus($bonusPercentage);

        return $safeBase * (1 + ($safeBonus / 100));
    }

    /**
     * 按速度加成缩短持续时间 / Shorten a duration by a speed percentage
     * @param int|float $baseSeconds 基础秒数 / Base duration in seconds
     * @param int|float $speedPercentage 速度百分比 / Speed percentage
     * @return int 调整后的秒数 / Adjusted duration in seconds
     */
    public static function applySpeedBonusToDuration($baseSeconds, $speedPercentage) {
        if (!is_numeric($baseSeconds)
            || !is_finite((float) $baseSeconds)
            || (float) $baseSeconds <= 0) {
            return 0;
        }

        $safeBonus = self::clampAssignedGeneralBonus($speedPercentage);
        return max(1, (int) ceil((float) $baseSeconds / (1 + ($safeBonus / 100))));
    }

    /**
     * 获取指定内政动作的加速后时长 / Get a speed-adjusted duration for an internal-affairs action
     * @param int|float $baseSeconds 基础秒数 / Base duration in seconds
     * @param string $bonusKey training_speed 或 build_speed / training_speed or build_speed
     * @return int 调整后的秒数 / Adjusted duration in seconds
     */
    public function getAdjustedCityActionDuration($baseSeconds, $bonusKey) {
        if (!$this->isValid || !in_array($bonusKey, ['training_speed', 'build_speed'], true)) {
            return self::applySpeedBonusToDuration($baseSeconds, 0);
        }

        $bonuses = $this->getAssignedGeneralCityBonuses();
        $duration = self::applySpeedBonusToDuration(
            $baseSeconds,
            $bonuses[$bonusKey]
        );
        $technologyEffect = TechnologyEffectService::getUserEffect(
            $this->ownerId,
            $bonusKey
        );
        return TechnologyEffectService::applySpeedBonusToDuration(
            $duration,
            $technologyEffect
        );
    }

    /**
     * 获取城池防御力
     * @return int 防御力
     */
    public function getDefensePower() {
        if (!$this->isValid) {
            return 0;
        }

        // 城池基础防御力 = 城池等级 * 100
        $defensePower = $this->level * 100;

        // 城池中的士兵防御力
        $soldiers = $this->getSoldiers();
        foreach ($soldiers as $soldier) {
            $defensePower += $soldier->getDefensePower() * $soldier->getQuantity();
        }

        // 城池耐久度与防御策略共同影响最终值 / Durability and strategy affect final defense
        $durabilityPercentage = $this->maxDurability > 0 ? $this->durability / $this->maxDurability : 0;
        $strategyBonus = $this->getDefenseStrategyBonus();
        $defensePower = $defensePower * $durabilityPercentage;
        $defensePower *= $strategyBonus[0];

        // 存活驻城武将提供百分比城防增益 / Living assigned generals provide a percentage city-defense bonus
        $cityBonuses = $this->getAssignedGeneralCityBonuses();
        $defensePower = self::applyPercentageBonus($defensePower, $cityBonuses['defense']);
        $defensePower = TechnologyEffectService::applyFractionalBonus(
            $defensePower,
            TechnologyEffectService::getUserEffect(
                $this->ownerId,
                'city_defense'
            )
        );

        return floor($defensePower);
    }

    /**
     * 检查城池是否可以产出思考回路 / Check whether the city can produce a circuit point
     * @return bool 是否可以产出 / Whether production is available
     */
    public function canProduceCircuit() {
        if (!$this->isValid) {
            return false;
        }

        $query = "SELECT c.last_circuit_production
                  FROM cities c
                  INNER JOIN facilities f ON f.city_id = c.city_id
                  WHERE c.city_id = ? AND f.type = 'governor_office'
                    AND f.construction_time IS NULL AND f.upgrade_time IS NULL
                  LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return false;
        }

        $lastProduction = $row['last_circuit_production'];
        if ($lastProduction !== null
            && time() - strtotime($lastProduction) < CIRCUIT_PRODUCTION_INTERVAL) {
            return false;
        }

        $user = new User($this->ownerId);
        return $user->isValid() && $user->getCircuitPoints() < $user->getMaxCircuitPoints();
    }

    /**
     * 原子化产出一点思考回路 / Atomically produce one circuit point
     * @return bool 是否成功 / Whether production succeeded
     */
    public function produceCircuit() {
        if (!$this->isValid) {
            return false;
        }

        $this->db->begin_transaction();

        try {
            lockSeasonForWorldAction($this->db);

            // 与战斗、领地和分基地事务一致，先锁玩家再锁城池 / Match battle, territory, and sub-base transactions by locking the user before the city
            $query = "SELECT circuit_points, max_circuit_points
                      FROM users
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $ownerId = (int) $this->ownerId;
            $stmt->bind_param('i', $ownerId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$user
                || (int) $user['circuit_points']
                    >= (int) $user['max_circuit_points']) {
                $this->db->rollback();
                return false;
            }

            // 锁内重验总督府、拥有者与上次产出时间，防止并发重复产出 / Revalidate the office, owner, and timestamp under lock to prevent duplicate production
            $query = "SELECT c.owner_id, c.last_circuit_production
                      FROM cities c
                      INNER JOIN facilities f ON f.city_id = c.city_id
                      WHERE c.city_id = ? AND f.type = 'governor_office'
                        AND f.construction_time IS NULL
                        AND f.upgrade_time IS NULL
                      ORDER BY f.facility_id
                      LIMIT 1
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $city = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$city || (int) $city['owner_id'] !== (int) $this->ownerId) {
                $this->db->rollback();
                return false;
            }
            if ($city['last_circuit_production'] !== null
                && time() - strtotime($city['last_circuit_production'])
                    < CIRCUIT_PRODUCTION_INTERVAL) {
                $this->db->rollback();
                return false;
            }

            $query = "UPDATE users
                      SET circuit_points = circuit_points + 1
                      WHERE user_id = ? AND circuit_points < max_circuit_points";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $ownerId);
            $stmt->execute();
            $produced = $stmt->affected_rows === 1;
            $stmt->close();

            if (!$produced) {
                $this->db->rollback();
                return false;
            }

            $query = "UPDATE cities
                      SET last_circuit_production = NOW()
                      WHERE city_id = ? AND owner_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $this->cityId, $ownerId);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('更新思考回路产出时间失败 / Failed to update circuit production time');
            }
            $stmt->close();

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Circuit production failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取城池资源
     * @return Resource 资源对象
     */
    public function getResource() {
        if (!$this->isValid) {
            return null;
        }

        return new Resource($this->ownerId);
    }

    /**
     * 在赛季锁内设置城池防御策略 / Set a city defense strategy under the season lock
     * @param string $strategy 防御策略（defense, balanced, production） / Defense strategy
     * @return bool 是否成功 / Whether the update succeeded
     */
    public function setDefenseStrategy($strategy) {
        if (!$this->isValid) {
            return false;
        }

        // 检查策略是否有效
        $validStrategies = ['defense', 'balanced', 'production'];
        if (!in_array($strategy, $validStrategies)) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            // 锁定并重验拥有者，避免城池易主后旧页面仍能写入 / Lock and revalidate ownership so a stale page cannot write after capture
            $query = "SELECT owner_id
                      FROM cities
                      WHERE city_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $city = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$city || (int) $city['owner_id'] !== (int) $this->ownerId) {
                $this->db->rollback();
                return false;
            }

            $query = "UPDATE cities
                      SET defense_strategy = ?
                      WHERE city_id = ? AND owner_id = ?";
            $stmt = $this->db->prepare($query);
            $ownerId = (int) $this->ownerId;
            $stmt->bind_param('sii', $strategy, $this->cityId, $ownerId);
            $updated = $stmt->execute();
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException(
                    '城池防御策略状态已经变化 / City defense strategy state changed'
                );
            }

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Defense strategy update failed: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * 获取城池防御策略
     * @return string 防御策略
     */
    public function getDefenseStrategy() {
        if (!$this->isValid) {
            return 'balanced';
        }

        $query = "SELECT defense_strategy FROM cities WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->cityId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['defense_strategy'] ?: 'balanced';
        }

        $stmt->close();
        return 'balanced';
    }

    /**
     * 获取城池防御策略加成
     * @return array [防御力加成, 资源产出加成]
     */
    public function getDefenseStrategyBonus() {
        $strategy = $this->getDefenseStrategy();

        switch ($strategy) {
            case 'defense':
                return [1.5, 0.8]; // 防御力+50%, 资源产出-20%
            case 'production':
                return [0.8, 1.5]; // 防御力-20%, 资源产出+50%
            case 'balanced':
            default:
                return [1.0, 1.0]; // 防御力和资源产出不变
        }
    }
}

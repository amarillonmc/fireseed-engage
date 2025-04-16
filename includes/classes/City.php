<?php
// 种火集结号 - 城池类

class City {
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
     * 创建初始玩家城池
     * @param int $userId 用户ID
     * @return bool|int 成功返回城池ID，失败返回false
     */
    public static function createInitialPlayerCity($userId) {
        $db = Database::getInstance()->getConnection();

        // 检查用户是否已有城池
        $query = "SELECT city_id FROM cities WHERE owner_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $stmt->close();
            return false; // 用户已有城池
        }

        $stmt->close();

        // 获取用户名
        $user = new User($userId);
        if (!$user->isValid()) {
            return false;
        }

        $username = $user->getUsername();
        $cityName = $username . '的城池';

        // 寻找合适的位置
        $centerX = MAP_CENTER_X;
        $centerY = MAP_CENTER_Y;
        $radius = 10;
        $maxRadius = 100;

        while ($radius <= $maxRadius) {
            // 在当前半径内随机选择位置
            for ($i = 0; $i < 10; $i++) { // 尝试10次
                $angle = mt_rand(0, 360) * M_PI / 180;
                $distance = mt_rand(0, $radius);

                $x = round($centerX + $distance * cos($angle));
                $y = round($centerY + $distance * sin($angle));

                // 确保坐标在地图范围内
                $x = max(0, min(MAP_WIDTH - 1, $x));
                $y = max(0, min(MAP_HEIGHT - 1, $y));

                // 检查位置是否可用
                $tile = new Map();
                if ($tile->loadByCoordinates($x, $y) && $tile->getType() == 'empty' && $tile->getOwnerId() === null) {
                    // 创建城池
                    $city = new City();
                    $cityId = $city->createCity($cityName, $userId, $x, $y, true);

                    if ($cityId) {
                        // 创建初始设施
                        self::createInitialFacilities($cityId);

                        return $cityId;
                    }
                }
            }

            // 增加搜索半径
            $radius += 10;
        }

        return false; // 无法找到合适的位置
    }

    /**
     * 创建初始设施
     * @param int $cityId 城池ID
     */
    private static function createInitialFacilities($cityId) {
        // 创建总督府
        $governorOffice = new Facility();
        $governorOffice->createFacility($cityId, 'governor_office', null, 12, 12);

        // 创建资源生产设施
        $resourceProduction = new Facility();
        $resourceProduction->createFacility($cityId, 'resource_production', 'bright', 10, 12);

        $resourceProduction = new Facility();
        $resourceProduction->createFacility($cityId, 'resource_production', 'warm', 14, 12);

        $resourceProduction = new Facility();
        $resourceProduction->createFacility($cityId, 'resource_production', 'cold', 12, 10);

        $resourceProduction = new Facility();
        $resourceProduction->createFacility($cityId, 'resource_production', 'green', 12, 14);

        // 创建兵营
        $barracks = new Facility();
        $barracks->createFacility($cityId, 'barracks', null, 8, 12);

        // 创建研究所
        $researchLab = new Facility();
        $researchLab->createFacility($cityId, 'research_lab', null, 16, 12);
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
            $defensePower += $soldier->getDefensePower();
        }

        // 城池耐久度影响
        $durabilityPercentage = $this->durability / $this->maxDurability;
        $defensePower = $defensePower * $durabilityPercentage;

        return floor($defensePower);
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
     * 设置城池防御策略
     * @param string $strategy 防御策略（defense, balanced, production）
     * @return bool 是否成功
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

        // 更新城池防御策略
        $query = "UPDATE cities SET defense_strategy = ? WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('si', $strategy, $this->cityId);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
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

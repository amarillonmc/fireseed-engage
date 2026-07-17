<?php
// 种火集结号 - 资源类

class Resource {
    private $db;
    private $userId;
    private $resourceId;
    private $brightCrystal; // 亮晶晶
    private $warmCrystal;   // 暖洋洋
    private $coldCrystal;   // 冷冰冰
    private $greenCrystal;  // 郁萌萌
    private $dayCrystal;    // 昼闪闪
    private $nightCrystal;  // 夜静静
    private $lastUpdate;
    private $isValid = false;

    /**
     * 构造函数
     * @param int $userId 用户ID
     */
    public function __construct($userId) {
        $this->db = Database::getInstance()->getConnection();
        $this->userId = $userId;
        $this->loadResourceData();
    }

    /**
     * 加载资源数据
     */
    private function loadResourceData() {
        $query = "SELECT * FROM resources WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $resourceData = $result->fetch_assoc();
            $this->resourceId = $resourceData['resource_id'];
            $this->brightCrystal = $resourceData['bright_crystal'];
            $this->warmCrystal = $resourceData['warm_crystal'];
            $this->coldCrystal = $resourceData['cold_crystal'];
            $this->greenCrystal = $resourceData['green_crystal'];
            $this->dayCrystal = $resourceData['day_crystal'];
            $this->nightCrystal = $resourceData['night_crystal'];
            $this->lastUpdate = $resourceData['last_update'];
            $this->isValid = true;
        }

        $stmt->close();
    }

    /**
     * 检查资源是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }

    /**
     * 获取亮晶晶资源数量
     * @return int
     */
    public function getBrightCrystal() {
        return $this->brightCrystal;
    }

    /**
     * 获取暖洋洋资源数量
     * @return int
     */
    public function getWarmCrystal() {
        return $this->warmCrystal;
    }

    /**
     * 获取冷冰冰资源数量
     * @return int
     */
    public function getColdCrystal() {
        return $this->coldCrystal;
    }

    /**
     * 获取郁萌萌资源数量
     * @return int
     */
    public function getGreenCrystal() {
        return $this->greenCrystal;
    }

    /**
     * 获取昼闪闪资源数量
     * @return int
     */
    public function getDayCrystal() {
        return $this->dayCrystal;
    }

    /**
     * 获取夜静静资源数量
     * @return int
     */
    public function getNightCrystal() {
        return $this->nightCrystal;
    }

    /**
     * 获取最后更新时间
     * @return string
     */
    public function getLastUpdate() {
        return $this->lastUpdate;
    }

    /**
     * 增加资源
     * @param string $type 资源类型 (bright, warm, cold, green, day, night)
     * @param int $amount 增加的数量
     * @return bool
     */
    public function addResource($type, $amount) {
        if ($amount <= 0) {
            return false;
        }

        $column = '';
        $currentAmount = 0;

        switch ($type) {
            case 'bright':
                $column = 'bright_crystal';
                $currentAmount = $this->brightCrystal;
                break;
            case 'warm':
                $column = 'warm_crystal';
                $currentAmount = $this->warmCrystal;
                break;
            case 'cold':
                $column = 'cold_crystal';
                $currentAmount = $this->coldCrystal;
                break;
            case 'green':
                $column = 'green_crystal';
                $currentAmount = $this->greenCrystal;
                break;
            case 'day':
                $column = 'day_crystal';
                $currentAmount = $this->dayCrystal;
                break;
            case 'night':
                $column = 'night_crystal';
                $currentAmount = $this->nightCrystal;
                break;
            default:
                return false;
        }

        $newAmount = $currentAmount + $amount;
        $now = date('Y-m-d H:i:s');

        $query = "UPDATE resources SET $column = ?, last_update = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isi', $newAmount, $now, $this->userId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            switch ($type) {
                case 'bright':
                    $this->brightCrystal = $newAmount;
                    break;
                case 'warm':
                    $this->warmCrystal = $newAmount;
                    break;
                case 'cold':
                    $this->coldCrystal = $newAmount;
                    break;
                case 'green':
                    $this->greenCrystal = $newAmount;
                    break;
                case 'day':
                    $this->dayCrystal = $newAmount;
                    break;
                case 'night':
                    $this->nightCrystal = $newAmount;
                    break;
            }

            $this->lastUpdate = $now;
            return true;
        }

        return false;
    }

    /**
     * 减少资源
     * @param string $type 资源类型 (bright, warm, cold, green, day, night)
     * @param int $amount 减少的数量
     * @return bool
     */
    public function reduceResource($type, $amount) {
        if ($amount <= 0) {
            return false;
        }

        $column = '';
        $currentAmount = 0;

        switch ($type) {
            case 'bright':
                $column = 'bright_crystal';
                $currentAmount = $this->brightCrystal;
                break;
            case 'warm':
                $column = 'warm_crystal';
                $currentAmount = $this->warmCrystal;
                break;
            case 'cold':
                $column = 'cold_crystal';
                $currentAmount = $this->coldCrystal;
                break;
            case 'green':
                $column = 'green_crystal';
                $currentAmount = $this->greenCrystal;
                break;
            case 'day':
                $column = 'day_crystal';
                $currentAmount = $this->dayCrystal;
                break;
            case 'night':
                $column = 'night_crystal';
                $currentAmount = $this->nightCrystal;
                break;
            default:
                return false;
        }

        if ($currentAmount < $amount) {
            return false; // 资源不足
        }

        $newAmount = $currentAmount - $amount;
        $now = date('Y-m-d H:i:s');

        $query = "UPDATE resources SET $column = ?, last_update = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isi', $newAmount, $now, $this->userId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            switch ($type) {
                case 'bright':
                    $this->brightCrystal = $newAmount;
                    break;
                case 'warm':
                    $this->warmCrystal = $newAmount;
                    break;
                case 'cold':
                    $this->coldCrystal = $newAmount;
                    break;
                case 'green':
                    $this->greenCrystal = $newAmount;
                    break;
                case 'day':
                    $this->dayCrystal = $newAmount;
                    break;
                case 'night':
                    $this->nightCrystal = $newAmount;
                    break;
            }

            $this->lastUpdate = $now;
            return true;
        }

        return false;
    }

    /**
     * 检查资源是否足够
     * @param string $type 资源类型 (bright, warm, cold, green, day, night)
     * @param int $amount 需要的数量
     * @return bool
     */
    public function hasEnoughResource($type, $amount) {
        if ($amount <= 0) {
            return true;
        }

        switch ($type) {
            case 'bright':
                return $this->brightCrystal >= $amount;
            case 'warm':
                return $this->warmCrystal >= $amount;
            case 'cold':
                return $this->coldCrystal >= $amount;
            case 'green':
                return $this->greenCrystal >= $amount;
            case 'day':
                return $this->dayCrystal >= $amount;
            case 'night':
                return $this->nightCrystal >= $amount;
            default:
                return false;
        }
    }

    /**
     * 批量检查资源是否足够
     * @param array $resources 资源数组，格式为 ['type' => amount]
     * @return bool
     */
    public function hasEnoughResources($resources) {
        foreach ($resources as $type => $amount) {
            if (!$this->hasEnoughResource($type, $amount)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 批量减少资源
     * @param array $resources 资源数组，格式为 ['type' => amount]
     * @return bool
     */
    public function reduceResources($resources) {
        // 先检查是否有足够的资源
        if (!$this->hasEnoughResources($resources)) {
            return false;
        }

        // 开始事务
        $this->db->begin_transaction();

        $success = true;

        foreach ($resources as $type => $amount) {
            if (!$this->reduceResource($type, $amount)) {
                $success = false;
                break;
            }
        }

        if ($success) {
            $this->db->commit();
            return true;
        } else {
            $this->db->rollback();
            return false;
        }
    }

    /**
     * 增加亮晶晶资源
     * @param int $amount 增加的数量
     * @return bool
     */
    public function addBrightCrystal($amount) {
        return $this->addResource('bright', $amount);
    }

    /**
     * 增加暖洋洋资源
     * @param int $amount 增加的数量
     * @return bool
     */
    public function addWarmCrystal($amount) {
        return $this->addResource('warm', $amount);
    }

    /**
     * 增加冷冰冰资源
     * @param int $amount 增加的数量
     * @return bool
     */
    public function addColdCrystal($amount) {
        return $this->addResource('cold', $amount);
    }

    /**
     * 增加郁萌萌资源
     * @param int $amount 增加的数量
     * @return bool
     */
    public function addGreenCrystal($amount) {
        return $this->addResource('green', $amount);
    }

    /**
     * 增加昼闪闪资源
     * @param int $amount 增加的数量
     * @return bool
     */
    public function addDayCrystal($amount) {
        return $this->addResource('day', $amount);
    }

    /**
     * 增加夜静静资源
     * @param int $amount 增加的数量
     * @return bool
     */
    public function addNightCrystal($amount) {
        return $this->addResource('night', $amount);
    }

    /**
     * 减少亮晶晶资源
     * @param int $amount 减少的数量
     * @return bool
     */
    public function consumeBrightCrystal($amount) {
        return $this->reduceResource('bright', $amount);
    }

    /**
     * 减少暖洋洋资源
     * @param int $amount 减少的数量
     * @return bool
     */
    public function consumeWarmCrystal($amount) {
        return $this->reduceResource('warm', $amount);
    }

    /**
     * 减少冷冰冰资源
     * @param int $amount 减少的数量
     * @return bool
     */
    public function consumeColdCrystal($amount) {
        return $this->reduceResource('cold', $amount);
    }

    /**
     * 减少郁萌萌资源
     * @param int $amount 减少的数量
     * @return bool
     */
    public function consumeGreenCrystal($amount) {
        return $this->reduceResource('green', $amount);
    }

    /**
     * 减少昼闪闪资源
     * @param int $amount 减少的数量
     * @return bool
     */
    public function consumeDayCrystal($amount) {
        return $this->reduceResource('day', $amount);
    }

    /**
     * 减少夜静静资源
     * @param int $amount 减少的数量
     * @return bool
     */
    public function consumeNightCrystal($amount) {
        return $this->reduceResource('night', $amount);
    }

    /**
     * 原子化结算玩家城池资源产出 / Settle a player's city resource production atomically
     * @param int $userId 用户ID / User ID
     * @return bool 是否推进了产出时间 / Whether the production timestamp advanced
     */
    public static function updateResourceProduction($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $db->begin_transaction();

        try {
            lockSeasonForWorldAction($db);

            // 先按编号锁定全部城池，和建造及城战保持稳定的城市优先顺序 / Lock every city by ID first to match construction and siege ordering
            $query = "SELECT city_id
                      FROM cities
                      WHERE owner_id = ?
                      ORDER BY city_id
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $cityIds = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $cityIds[] = (int) $row['city_id'];
            }
            $stmt->close();

            $query = "SELECT bright_crystal, warm_crystal, cold_crystal,
                             green_crystal, day_crystal, night_crystal,
                             last_update
                      FROM resources
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $resourceRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$resourceRow) {
                $db->rollback();
                return false;
            }

            $now = time();
            $lastUpdate = strtotime((string) $resourceRow['last_update']);
            if ($lastUpdate === false) {
                $lastUpdate = $now;
            }
            $elapsedSeconds = max(0, $now - $lastUpdate);
            if ($elapsedSeconds < 1) {
                $db->commit();
                return false;
            }

            $production = [
                'bright' => 0,
                'warm' => 0,
                'cold' => 0,
                'green' => 0,
                'day' => 0,
                'night' => 0
            ];
            foreach ($cityIds as $cityId) {
                $city = new City($cityId);
                if (!$city->isValid()) {
                    continue;
                }
                // 每座城池只汇总一次驻城生产加成 / Aggregate the assigned-general production bonus once per city
                $cityBonuses = $city->getAssignedGeneralCityBonuses();
                $facilities = Facility::getCityFacilitiesByType(
                    $cityId,
                    'resource_production'
                );
                foreach ($facilities as $facility) {
                    if ($facility->isUnderConstruction() || $facility->isUpgrading()) {
                        continue;
                    }
                    $resourceType = $facility->getSubtype();
                    if (!isset($production[$resourceType])) {
                        continue;
                    }
                    $produced = $facility->calculateResourceProduction(
                        $elapsedSeconds,
                        $cityBonuses['production']
                    );
                    $production[$resourceType] = min(
                        2147483647,
                        $production[$resourceType] + max(0, (int) $produced)
                    );
                }
            }

            $storageCapacity = max(
                0,
                (int) self::getUserResourceStorageCapacity($userId)
            );
            $query = "UPDATE resources
                      SET bright_crystal = LEAST(?, bright_crystal + ?),
                          warm_crystal = LEAST(?, warm_crystal + ?),
                          cold_crystal = LEAST(?, cold_crystal + ?),
                          green_crystal = LEAST(?, green_crystal + ?),
                          day_crystal = LEAST(?, day_crystal + ?),
                          night_crystal = LEAST(?, night_crystal + ?),
                          last_update = ?
                      WHERE user_id = ?";
            $stmt = $db->prepare($query);
            $nowDate = date('Y-m-d H:i:s', $now);
            $stmt->bind_param(
                'iiiiiiiiiiiisi',
                $storageCapacity,
                $production['bright'],
                $storageCapacity,
                $production['warm'],
                $storageCapacity,
                $production['cold'],
                $storageCapacity,
                $production['green'],
                $storageCapacity,
                $production['day'],
                $storageCapacity,
                $production['night'],
                $nowDate,
                $userId
            );
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException(
                    '更新资源产出失败 / Failed to update resource production'
                );
            }
            $stmt->close();

            $db->commit();
            return true;
        } catch (Throwable $e) {
            $db->rollback();
            error_log('Resource production update failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取用户的资源存储上限
     * @param int $userId 用户ID
     * @return int
     */
    public static function getUserResourceStorageCapacity($userId) {
        // 获取用户的所有城池
        $cities = City::getUserCities($userId);

        // 初始资源存储上限
        $totalCapacity = INITIAL_RESOURCE_STORAGE;

        foreach ($cities as $city) {
            // 获取城池中的贮存所
            $storages = Facility::getCityFacilitiesByType($city->getCityId(), 'storage');

            foreach ($storages as $storage) {
                // 跳过正在建造或升级的设施
                if ($storage->isUnderConstruction() || $storage->isUpgrading()) {
                    continue;
                }

                // 增加贮存所提供的存储上限
                $totalCapacity += $storage->getResourceStorageCapacity();
            }
        }

        return $totalCapacity;
    }

    /**
     * 获取指定类型的资源数量
     * @param string $type 资源类型 (bright, warm, cold, green, day, night)
     * @return int
     */
    public function getResourceByType($type) {
        switch ($type) {
            case 'bright':
                return $this->brightCrystal;
            case 'warm':
                return $this->warmCrystal;
            case 'cold':
                return $this->coldCrystal;
            case 'green':
                return $this->greenCrystal;
            case 'day':
                return $this->dayCrystal;
            case 'night':
                return $this->nightCrystal;
            default:
                return 0;
        }
    }

    /**
     * 添加指定类型的资源
     * @param string $type 资源类型 (bright, warm, cold, green, day, night)
     * @param int $amount 添加的数量
     * @return bool
     */
    public function addResourceByType($type, $amount) {
        return $this->addResource($type, $amount);
    }

    /**
     * 减少指定类型的资源 / Subtract a resource by its short type
     * @param string $type 资源类型 / Resource type
     * @param int $amount 数量 / Amount
     * @return bool 是否成功 / Whether the subtraction succeeded
     */
    public function subtractResourceByType($type, $amount) {
        return $this->reduceResource($type, $amount);
    }

    /**
     * 获取资源存储上限
     * @param string $type 资源类型 (bright, warm, cold, green, day, night)
     * @return int
     */
    public function getStorageLimit($type = null) {
        // 获取用户的资源存储上限
        $capacity = self::getUserResourceStorageCapacity($this->userId);
        return $capacity;
    }

    /**
     * 更新思考回路产出
     * @param int $userId 用户ID
     * @return array 产出思考回路的城池数组
     */
    public static function updateCircuitProduction($userId) {
        // 获取用户
        $user = new User($userId);
        if (!$user->isValid()) {
            return [];
        }

        // 获取用户的所有城池
        $cities = City::getUserCities($userId);

        $producedCities = [];

        foreach ($cities as $city) {
            // 检查城池是否可以产出思考回路
            if ($city->canProduceCircuit()) {
                // 产出思考回路
                if ($city->produceCircuit()) {
                    $producedCities[] = [
                        'city_id' => $city->getCityId(),
                        'name' => $city->getName()
                    ];
                }
            }
        }

        return $producedCities;
    }
}

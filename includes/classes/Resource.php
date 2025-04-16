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
        $this->db->beginTransaction();

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
     * 更新资源产出
     * @param int $userId 用户ID
     * @return bool
     */
    public static function updateResourceProduction($userId) {
        $db = Database::getInstance()->getConnection();

        // 获取用户资源
        $resource = new Resource($userId);
        if (!$resource->isValid()) {
            return false;
        }

        // 获取上次更新时间
        $lastUpdate = strtotime($resource->getLastUpdate());
        $now = time();

        // 如果时间差小于1秒，不进行更新
        if ($now - $lastUpdate < 1) {
            return false;
        }

        // 获取用户的所有城池
        $cities = City::getUserCities($userId);

        // 计算资源产出
        $brightCrystalProduction = 0;
        $warmCrystalProduction = 0;
        $coldCrystalProduction = 0;
        $greenCrystalProduction = 0;
        $dayCrystalProduction = 0;
        $nightCrystalProduction = 0;

        foreach ($cities as $city) {
            // 获取城池中的资源产出设施
            $resourceFacilities = Facility::getCityFacilitiesByType($city->getCityId(), 'resource_production');

            foreach ($resourceFacilities as $facility) {
                // 跳过正在建造或升级的设施
                if ($facility->isUnderConstruction() || $facility->isUpgrading()) {
                    continue;
                }

                // 计算设施产出的资源
                $production = $facility->calculateResourceProduction($now - $lastUpdate);

                // 根据设施子类型增加对应资源
                switch ($facility->getSubtype()) {
                    case 'bright':
                        $brightCrystalProduction += $production;
                        break;
                    case 'warm':
                        $warmCrystalProduction += $production;
                        break;
                    case 'cold':
                        $coldCrystalProduction += $production;
                        break;
                    case 'green':
                        $greenCrystalProduction += $production;
                        break;
                    case 'day':
                        $dayCrystalProduction += $production;
                        break;
                    case 'night':
                        $nightCrystalProduction += $production;
                        break;
                }
            }
        }

        // 获取用户的资源存储上限
        $storageCapacity = self::getUserResourceStorageCapacity($userId);

        // 开始事务
        $db->beginTransaction();

        try {
            // 更新资源
            if ($brightCrystalProduction > 0) {
                $newBrightCrystal = min($resource->getBrightCrystal() + $brightCrystalProduction, $storageCapacity);
                $resource->addResource('bright', $newBrightCrystal - $resource->getBrightCrystal());
            }

            if ($warmCrystalProduction > 0) {
                $newWarmCrystal = min($resource->getWarmCrystal() + $warmCrystalProduction, $storageCapacity);
                $resource->addResource('warm', $newWarmCrystal - $resource->getWarmCrystal());
            }

            if ($coldCrystalProduction > 0) {
                $newColdCrystal = min($resource->getColdCrystal() + $coldCrystalProduction, $storageCapacity);
                $resource->addResource('cold', $newColdCrystal - $resource->getColdCrystal());
            }

            if ($greenCrystalProduction > 0) {
                $newGreenCrystal = min($resource->getGreenCrystal() + $greenCrystalProduction, $storageCapacity);
                $resource->addResource('green', $newGreenCrystal - $resource->getGreenCrystal());
            }

            if ($dayCrystalProduction > 0) {
                $newDayCrystal = min($resource->getDayCrystal() + $dayCrystalProduction, $storageCapacity);
                $resource->addResource('day', $newDayCrystal - $resource->getDayCrystal());
            }

            if ($nightCrystalProduction > 0) {
                $newNightCrystal = min($resource->getNightCrystal() + $nightCrystalProduction, $storageCapacity);
                $resource->addResource('night', $newNightCrystal - $resource->getNightCrystal());
            }

            // 更新最后更新时间
            $query = "UPDATE resources SET last_update = ? WHERE user_id = ?";
            $stmt = $db->prepare($query);
            $nowDate = date('Y-m-d H:i:s', $now);
            $stmt->bind_param('si', $nowDate, $userId);
            $stmt->execute();
            $stmt->close();

            $db->commit();
            return true;
        } catch (Exception $e) {
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

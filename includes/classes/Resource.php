<?php
// 种火集结号 - 资源类

class Resource {
    private const PRODUCTION_SNAPSHOT_SCHEMA_VERSION = 2;
    private const PRODUCTION_INTEGER_MAX = 2147483647;
    private const MAX_PRODUCTION_STREAMS_PER_RESOURCE = 10000;
    private const PRODUCTION_RESOURCE_TYPES = [
        'bright',
        'warm',
        'cold',
        'green',
        'day',
        'night'
    ];

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
        $amount = (int) $amount;
        $column = self::getResourceColumn($type);
        if ($amount <= 0 || $column === null) {
            return false;
        }

        // 钱包变更使用原子增量，且不得重置离线产出时钟 / Wallet mutations are atomic and never reset the production clock
        $query = "UPDATE resources
                  SET $column = $column + ?
                  WHERE user_id = ?
                    AND $column <= 2147483647 - ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iii', $amount, $this->userId, $amount);
        $result = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();

        if ($result) {
            $this->loadResourceData();
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
        $amount = (int) $amount;
        $column = self::getResourceColumn($type);
        if ($amount <= 0 || $column === null) {
            return false;
        }

        // 条件扣减避免并发请求透支余额 / Conditional deduction prevents concurrent overdrafts
        $query = "UPDATE resources
                  SET $column = $column - ?
                  WHERE user_id = ? AND $column >= ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iii', $amount, $this->userId, $amount);
        $result = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();

        if ($result) {
            $this->loadResourceData();
            return true;
        }

        return false;
    }

    /**
     * 将资源类型映射到受信任列名 / Map a resource type to a trusted column name
     *
     * @param string $type 资源类型 / Resource type
     * @return string|null 列名 / Column name
     */
    private static function getResourceColumn($type) {
        $columns = [
            'bright' => 'bright_crystal',
            'warm' => 'warm_crystal',
            'cold' => 'cold_crystal',
            'green' => 'green_crystal',
            'day' => 'day_crystal',
            'night' => 'night_crystal'
        ];
        return isset($columns[$type]) ? $columns[$type] : null;
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
        // 先检查是否有足够的资源 / Reject obviously insufficient requests early
        if (!$this->hasEnoughResources($resources)) {
            return false;
        }

        if (!$this->db->begin_transaction()) {
            return false;
        }

        foreach ($resources as $type => $amount) {
            if (!$this->reduceResource($type, $amount)) {
                $this->db->rollback();
                $this->loadResourceData();
                return false;
            }
        }

        if (!$this->db->commit()) {
            $this->db->rollback();
            $this->loadResourceData();
            return false;
        }
        $this->loadResourceData();
        return true;
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

            $query = "SELECT bright_crystal, bright_production_remainder,
                             warm_crystal, warm_production_remainder,
                             cold_crystal, cold_production_remainder,
                             green_crystal, green_production_remainder,
                             day_crystal, day_production_remainder,
                             night_crystal, night_production_remainder,
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
            $nowDate = date('Y-m-d H:i:s', $now);

            // 独立生产游标不会被消费、奖励或后台资源调整重置 / The independent production cursor is not reset by spending, rewards, or administrative resource changes
            // 锁定状态后的城池、设施、武将与技能读取均为非锁定MVCC读取；变更触发器可在本事务提交后线性化，不形成state→实体的反向等待 / City, facility, general, and skill reads after this lock are nonlocking MVCC reads; mutation triggers may linearize after this transaction commits without creating a state-to-entity reverse wait
            $query = "SELECT settled_at, dirty_since_offset_seconds,
                             dirty_at, change_count,
                             change_window_observed,
                             scheduled_offset_seconds,
                             scheduled_change_count, snapshot_json
                      FROM resource_production_states
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $productionState = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$productionState) {
                $query = "INSERT INTO resource_production_states
                          (user_id, settled_at,
                           dirty_since_offset_seconds, dirty_at,
                           change_count, change_window_observed,
                           scheduled_offset_seconds,
                           scheduled_change_count, snapshot_json)
                          VALUES (?, ?, NULL, NULL, 0, 0,
                                  NULL, 0, NULL)";
                $stmt = $db->prepare($query);
                $stmt->bind_param('is', $userId, $nowDate);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException(
                        '初始化资源生产状态失败 / Failed to initialize resource production state'
                    );
                }
                $stmt->close();
                $productionState = [
                    'settled_at' => $nowDate,
                    'dirty_since_offset_seconds' => null,
                    'dirty_at' => null,
                    'change_count' => 0,
                    'change_window_observed' => 0,
                    'scheduled_offset_seconds' => null,
                    'scheduled_change_count' => 0,
                    'snapshot_json' => null
                ];
            }
            $elapsedSeconds = max(0, $now - $lastUpdate);
            $productionInterval = max(
                1,
                (int) RESOURCE_PRODUCTION_INTERVAL
            );
            $completedTicks = intdiv($elapsedSeconds, $productionInterval);
            if ($completedTicks < 1) {
                $db->commit();
                return false;
            }
            // 只推进已经完整结算的周期，保留不足一个周期的离线时间 / Advance only complete ticks so sub-interval offline time remains accrued
            $settledSeconds = $completedTicks * $productionInterval;
            $settledAt = $lastUpdate + $settledSeconds;

            $production = [
                'bright' => 0.0,
                'warm' => 0.0,
                'cold' => 0.0,
                'green' => 0.0,
                'day' => 0.0,
                'night' => 0.0
            ];
            $technologyEffects = TechnologyEffectService::getUserEffects($userId);
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
                        $settledSeconds,
                        $cityBonuses['production']
                    );
                    $effectKey = 'resource_production_' . $resourceType;
                    $produced = TechnologyEffectService::applyFractionalBonus(
                        $produced,
                        $technologyEffects[$effectKey] ?? 0.0
                    );
                    $production[$resourceType] = min(
                        2147483647.999999,
                        $production[$resourceType]
                            + max(0.0, (float) $produced)
                    );
                }
                if ($captureRequested) {
                    // 边界覆盖的tick尚未结束时保留旧快照、首末边界与变化次数 / Retain the old snapshot, first/latest boundaries, and change count while a boundary-covered tick remains unsettled
                    $nextDirtyAt = date(
                        'Y-m-d H:i:s',
                        (int) $settlement[
                            'change_window_boundary_at'
                        ]
                    );
                    $nextDirtySinceOffset = max(
                        0,
                        (int) $dirtySinceAt - $nextSettledAt
                    );
                    $nextChangeCount = max(
                        1,
                        (int) $effectiveChangeCount
                    );
                    $nextChangeWindowObserved =
                        $effectiveChangeCount > 1;
                } else {
                    $nextDirtyAt = null;
                    $nextDirtySinceOffset = null;
                    $nextChangeCount = 0;
                    $nextChangeWindowObserved = false;
                }
            }

            if ((int) $settlement['settled_ticks'] < 1) {
                self::persistProductionState(
                    $db,
                    $userId,
                    date('Y-m-d H:i:s', $nextSettledAt),
                    $nextDirtySinceOffset,
                    $nextDirtyAt,
                    $nextChangeCount,
                    $nextChangeWindowObserved,
                    $nextSchedule['offset_seconds'],
                    $nextSchedule['change_count'],
                    $nextSnapshotJson
                );
                $db->commit();
                return false;
            }

            $remainderColumns = [
                'bright' => 'bright_production_remainder',
                'warm' => 'warm_production_remainder',
                'cold' => 'cold_production_remainder',
                'green' => 'green_production_remainder',
                'day' => 'day_production_remainder',
                'night' => 'night_production_remainder'
            ];
            $settledProduction = [];
            foreach ($production as $resourceType => $produced) {
                $remainderColumn = $remainderColumns[$resourceType];
                $settledProduction[$resourceType] =
                    self::splitProductionAccrual(
                        $produced,
                        $resourceRow[$remainderColumn]
                    );
            }

            $storageCapacity = max(
                0,
                (int) self::getUserResourceStorageCapacity($userId)
            );
            // 跨赛季货币不依赖会重置的贮存所容量 / Persistent currencies do not depend on seasonal storage facilities
            $persistentCapacity = 2147483647;
            $query = "UPDATE resources
                      SET bright_crystal = LEAST(?, bright_crystal + ?),
                          bright_production_remainder = ?,
                          warm_crystal = LEAST(?, warm_crystal + ?),
                          warm_production_remainder = ?,
                          cold_crystal = LEAST(?, cold_crystal + ?),
                          cold_production_remainder = ?,
                          green_crystal = LEAST(?, green_crystal + ?),
                          green_production_remainder = ?,
                          day_crystal = LEAST(?, day_crystal + ?),
                          day_production_remainder = ?,
                          night_crystal = LEAST(?, night_crystal + ?),
                          night_production_remainder = ?,
                          last_update = ?
                      WHERE user_id = ?";
            $stmt = $db->prepare($query);
            $settledDate = date('Y-m-d H:i:s', $settledAt);
            $stmt->bind_param(
                'iidiidiidiidiidiidsi',
                $persistentCapacity,
                $settledProduction['bright']['whole'],
                $settledProduction['bright']['remainder'],
                $storageCapacity,
                $settledProduction['warm']['whole'],
                $settledProduction['warm']['remainder'],
                $storageCapacity,
                $settledProduction['cold']['whole'],
                $settledProduction['cold']['remainder'],
                $storageCapacity,
                $settledProduction['green']['whole'],
                $settledProduction['green']['remainder'],
                $storageCapacity,
                $settledProduction['day']['whole'],
                $settledProduction['day']['remainder'],
                $persistentCapacity,
                $settledProduction['night']['whole'],
                $settledProduction['night']['remainder'],
                $settledDate,
                $userId
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    '更新资源产出失败 / Failed to update resource production'
                );
            }
            $stmt->close();

            self::persistProductionState(
                $db,
                $userId,
                $settlementDate,
                $nextDirtySinceOffset,
                $nextDirtyAt,
                $nextChangeCount,
                $nextChangeWindowObserved,
                $nextSchedule['offset_seconds'],
                $nextSchedule['change_count'],
                $nextSnapshotJson
            );

            $db->commit();
            return true;
        } catch (Throwable $e) {
            $db->rollback();
            error_log('Resource production update failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 将小数产量拆为本次入账整数与下一次余数 / Split fractional production into credited units and a carried remainder
     * @param int|float $produced 本次计算产量 / Production calculated now
     * @param int|float $remainder 上次保留余数 / Previously carried remainder
     * @return array{whole:int,remainder:float} 入账量与余数 / Credited amount and remainder
     */
    public static function splitProductionAccrual($produced, $remainder) {
        $safeProduced = is_numeric($produced)
            ? max(0.0, (float) $produced)
            : 0.0;
        $safeRemainder = is_numeric($remainder)
            ? max(0.0, min(0.999999, (float) $remainder))
            : 0.0;
        // 数据库以六位小数保存余数，先按相同精度归一化可避免浮点边界吞掉整点 / Match the six-decimal database precision before splitting to avoid losing a whole unit at floating-point boundaries
        $accrued = round(
            min(2147483647.999999, $safeProduced + $safeRemainder),
            6
        );
        $whole = min(2147483647, (int) floor($accrued));
        $nextRemainder = round(
            max(0.0, min(0.999999, $accrued - $whole)),
            6
        );

        return [
            'whole' => $whole,
            'remainder' => $nextRemainder
        ];
    }

    /**
     * 获取用户的资源存储上限
     * @param int $userId 用户ID / User ID
     * @param string|null $type 资源类型 / Resource type
     * @return int
     */
    public static function getUserResourceStorageCapacity(
        $userId,
        $type = null
    ) {
        // 亮、夜是跨赛季货币，不受赛季贮存所限制。 / Bright and Night are persistent currencies and ignore seasonal storage.
        if (in_array($type, ['bright', 'night'], true)) {
            return 2147483647;
        }

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

        $storageEffect = TechnologyEffectService::getUserEffect(
            $userId,
            'resource_storage'
        );
        return min(
            2147483647,
            (int) floor(
                TechnologyEffectService::applyFractionalBonus(
                    $totalCapacity,
                    $storageEffect
                )
            )
        );
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
        $capacity = self::getUserResourceStorageCapacity(
            $this->userId,
            $type
        );
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

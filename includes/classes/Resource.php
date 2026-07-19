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

            $currentState = self::buildProductionSnapshot(
                $cityIds,
                $now
            );
            $currentSnapshot = $currentState['snapshot'];
            $currentSnapshotJson = json_encode(
                $currentSnapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($currentSnapshotJson === false) {
                throw new RuntimeException(
                    '序列化资源生产快照失败 / Failed to serialize the resource production snapshot'
                );
            }

            $previousSnapshot = self::decodeProductionSnapshot(
                $productionState['snapshot_json']
            );
            $settledAt = strtotime(
                (string) $productionState['settled_at']
            );
            if ($previousSnapshot === null
                || $settledAt === false
                || $settledAt > $now) {
                // 旧服首次迁移不追授无法证明的技能倍率，先建立可信基线 / A legacy server's first migration does not backfill unverifiable skill multipliers and instead establishes a trusted baseline
                $baselineSchedule =
                    self::buildScheduledProductionState(
                        $currentState,
                        $now
                    );
                self::persistProductionState(
                    $db,
                    $userId,
                    $nowDate,
                    null,
                    null,
                    0,
                    false,
                    $baselineSchedule['offset_seconds'],
                    $baselineSchedule['change_count'],
                    $currentSnapshotJson
                );
                $db->commit();
                return false;
            }

            $dirtyAt = self::parseOptionalProductionTimestamp(
                $productionState['dirty_at']
            );
            $dirtySinceOffset = self::parseProductionOffset(
                $productionState['dirty_since_offset_seconds']
            );
            $storedChangeCount = self::parseProductionChangeCount(
                $productionState['change_count']
            );
            $changeWindowObserved =
                (int) $productionState['change_window_observed'] === 1;
            $scheduledOffset = self::parseProductionOffset(
                $productionState['scheduled_offset_seconds']
            );
            $scheduledChangeCount =
                self::parseProductionChangeCount(
                    $productionState['scheduled_change_count']
                );
            $scheduledAt = $scheduledOffset === null
                || $scheduledChangeCount < 1
                ? null
                : $settledAt + $scheduledOffset;
            $snapshotChanged = !self::productionSnapshotsEqual(
                $previousSnapshot,
                $currentSnapshot
            );
            $scheduledChangeIsDue =
                $scheduledAt !== null && $scheduledAt <= $now;
            $unexpectedSnapshotChange =
                $storedChangeCount === 0
                && !$scheduledChangeIsDue
                && $snapshotChanged;
            $captureRequested = $storedChangeCount > 0
                || $scheduledChangeIsDue
                || $unexpectedSnapshotChange;
            $effectiveChangeCount = $storedChangeCount;
            $effectiveWindowObserved = $changeWindowObserved;
            $dirtySinceAt = null;
            $latestBoundaryAt = null;
            if ($storedChangeCount > 0) {
                $effectiveChangeCount = $storedChangeCount;
                $latestBoundaryAt = $dirtyAt === null
                    ? $now
                    : $dirtyAt;
                $dirtySinceAt = $dirtySinceOffset === null
                    ? (
                        $storedChangeCount > 1
                            ? $settledAt
                            : $latestBoundaryAt
                    )
                    : $settledAt + $dirtySinceOffset;
            }
            if ($scheduledChangeIsDue) {
                // 已知计划边界独立于实际dirty窗口保存；到期后与实际变化次数合并，避免实际变更覆盖未来完成时间 / A known scheduled boundary is stored independently from the actual dirty window and is merged when due, preventing an actual change from overwriting a future completion
                $effectiveChangeCount =
                    self::saturatingProductionChangeCountAdd(
                        $effectiveChangeCount,
                        $scheduledChangeCount
                    );
                $dirtySinceAt = $dirtySinceAt === null
                    ? $scheduledAt
                    : min($dirtySinceAt, $scheduledAt);
                $latestBoundaryAt = $latestBoundaryAt === null
                    ? $scheduledAt
                    : max($latestBoundaryAt, $scheduledAt);
                $effectiveWindowObserved = false;
            } elseif ($unexpectedSnapshotChange) {
                // 未被触发器捕获的指纹变化只能从当前时刻起生效 / A fingerprint change missed by triggers may take effect only from now
                $effectiveChangeCount = 1;
                $dirtySinceAt = $now;
                $latestBoundaryAt = $now;
                $effectiveWindowObserved = false;
            }
            $settlement = self::calculateProductionAcrossSnapshotChanges(
                $previousSnapshot,
                $currentSnapshot,
                $settledAt,
                $dirtySinceAt,
                $latestBoundaryAt,
                $effectiveChangeCount,
                $now,
                $effectiveWindowObserved
            );
            if (!$settlement['valid']) {
                throw new RuntimeException(
                    '资源生产分段无效 / Invalid resource production segmentation'
                );
            }

            $production = $settlement['production'];
            $nextSettledAt = $settledAt
                + (int) $settlement['settled_seconds'];
            $nextSchedule = self::buildScheduledProductionState(
                $currentState,
                $nextSettledAt
            );
            $captureCurrentSnapshot = $captureRequested
                && (
                    (
                        $effectiveChangeCount === 1
                        && !$snapshotChanged
                    )
                    || $nextSettledAt >= (int) $settlement[
                        'current_snapshot_starts_at'
                    ]
                );
            if ($captureCurrentSnapshot) {
                // 所有未确定tick结束后才捕获当前快照，并清空已消费的变化计数 / Capture the current snapshot and clear consumed changes only after every uncertain tick has ended
                $nextSnapshotJson = $currentSnapshotJson;
                $nextDirtyAt = null;
                $nextDirtySinceOffset = null;
                $nextChangeCount = 0;
                $nextChangeWindowObserved = false;
            } else {
                $nextSnapshotJson = json_encode(
                    $previousSnapshot,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if ($nextSnapshotJson === false) {
                    throw new RuntimeException(
                        '序列化资源生产快照失败 / Failed to serialize the resource production snapshot'
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
                          last_update = GREATEST(last_update, ?)
                      WHERE user_id = ?";
            $stmt = $db->prepare($query);
            $settlementDate = date('Y-m-d H:i:s', $nextSettledAt);
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
                $settlementDate,
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
     * 按持久化设施生产流快照计算完整 tick 产量 / Calculates complete ticks from persisted facility-stream rates
     * @param array $snapshot 生产快照 / Production snapshot
     * @param int $elapsedSeconds 可证明未变化的秒数 / Seconds proven unchanged
     * @return array<string,int> 六色资源产量 / Production for all six resources
     */
    public static function calculateProductionFromSnapshot(
        array $snapshot,
        $elapsedSeconds
    ) {
        $normalized = self::normalizeProductionSnapshot($snapshot);
        $elapsedSeconds = max(0, (int) $elapsedSeconds);
        if ($normalized === null || $elapsedSeconds < 1) {
            return self::emptyProductionAmounts();
        }

        $ticks = intdiv(
            $elapsedSeconds,
            (int) $normalized['interval_seconds']
        );
        return self::calculateProductionForTicks($normalized, $ticks);
    }

    /**
     * 按最近状态边界分配完整 tick 并结算两份快照 / Allocates complete ticks across the latest state boundary and settles two snapshots
     *
     * 边界所在的完整 tick 按起点归入旧快照，下一完整 tick 才启用当前快照；
     * 两侧 tick 数由同一锚点分配，既不丢 tick，也不允许最后一刻换高追溯。
     * The complete tick containing the boundary belongs to the old snapshot by
     * its start time; the current snapshot begins with the next complete tick.
     * Both counts share one anchor, avoiding lost ticks and last-moment retroactivity.
     *
     * @param array $previousSnapshot 上次快照 / Previous snapshot
     * @param array $currentSnapshot 当前快照 / Current snapshot
     * @param int $settledAt 独立生产游标 / Independent production cursor
     * @param int|null $boundaryAt 最近变化边界 / Latest change boundary
     * @param int $now 当前时间 / Current time
     * @return array 分段产量和tick分配 / Segmented production and tick allocation
     */
    public static function calculateProductionAcrossSnapshotBoundary(
        array $previousSnapshot,
        array $currentSnapshot,
        $settledAt,
        $boundaryAt,
        $now
    ) {
        return self::calculateProductionAcrossSnapshotChanges(
            $previousSnapshot,
            $currentSnapshot,
            $settledAt,
            $boundaryAt,
            $boundaryAt,
            1,
            $now,
            false
        );
    }

    /**
     * 按首末边界和变化次数结算生产快照 / Settles production snapshots using first/latest boundaries and a change count
     *
     * 一次变化可精确使用旧、新端点；多次变化的中间状态不可重建，因此只支付两端
     * 同一设施、同一资源且完全相同的无技能基础率。这样端点相同也不能把中途未生效
     * 的技能高率追授到整段，同时未变化设施的正常基础产出不会归零。
     * One change can use the exact old and current endpoints. Intermediate states
     * across multiple changes cannot be reconstructed, so the uncertain segment
     * pays only identical unskilled base rates for the same facility and resource
     * at both endpoints. Equal endpoints therefore cannot backfill an inactive
     * skill bonus, while unchanged facilities retain normal base production.
     *
     * @param array $previousSnapshot 上次快照 / Previous snapshot
     * @param array $currentSnapshot 当前快照 / Current snapshot
     * @param int $settledAt 独立生产游标 / Independent production cursor
     * @param int|null $dirtySinceAt 首次变化边界 / First change boundary
     * @param int|null $latestBoundaryAt 最近变化边界 / Latest change boundary
     * @param int $changeCount 未结算变化次数 / Unsettled change count
     * @param int $now 当前时间 / Current time
     * @param bool $changeWindowObserved 多变更窗口是否已固定 / Whether the multi-change window was already fixed
     * @return array 分段产量和tick分配 / Segmented production and tick allocation
     */
    public static function calculateProductionAcrossSnapshotChanges(
        array $previousSnapshot,
        array $currentSnapshot,
        $settledAt,
        $dirtySinceAt,
        $latestBoundaryAt,
        $changeCount,
        $now,
        $changeWindowObserved = false
    ) {
        $previous = self::normalizeProductionSnapshot(
            $previousSnapshot
        );
        $current = self::normalizeProductionSnapshot(
            $currentSnapshot
        );
        $settledAt = (int) $settledAt;
        $now = (int) $now;
        $emptyResult = [
            'valid' => false,
            'production' => self::emptyProductionAmounts(),
            'settled_ticks' => 0,
            'previous_ticks' => 0,
            'conservative_ticks' => 0,
            'current_ticks' => 0,
            'settled_seconds' => 0,
            'current_snapshot_starts_at' => $settledAt,
            'change_window_boundary_at' => $settledAt
        ];
        if ($previous === null
            || $current === null
            || $settledAt < 0
            || $now < $settledAt
            || (int) $previous['interval_seconds']
                !== (int) $current['interval_seconds']) {
            return $emptyResult;
        }

        $intervalSeconds = (int) $previous['interval_seconds'];
        $settledTicks = intdiv(
            $now - $settledAt,
            $intervalSeconds
        );
        $snapshotsMatch = $previous === $current;
        $changeCount = max(0, (int) $changeCount);
        $changeWindowObserved = (bool) $changeWindowObserved;
        if ($changeCount === 0 && !$snapshotsMatch) {
            // 意外指纹变化按当前时刻的一次边界处理，不追溯当前状态 / Treat an unexpected fingerprint change as one boundary at now without backdating the current state
            $changeCount = 1;
            $dirtySinceAt = $now;
            $latestBoundaryAt = $now;
        }

        if ($changeCount === 0
            || ($changeCount === 1 && $snapshotsMatch)) {
            // 零次变化或可证明只有一次且端点相同，可安全连续使用旧快照 / With no changes, or exactly one proven change with identical endpoints, the old snapshot remains continuously safe
            $previousTicks = $settledTicks;
            $conservativeTicks = 0;
            $currentSnapshotStartsAt = $settledAt;
            $changeWindowBoundaryAt = $settledAt;
        } elseif ($changeCount === 1) {
            $normalizedBoundary = $latestBoundaryAt === null
                ? $now
                : (int) $latestBoundaryAt;
            $normalizedBoundary = min(
                $now,
                max($settledAt, $normalizedBoundary)
            );
            $boundaryOffset = $normalizedBoundary - $settledAt;
            $requiredPreviousTicks = intdiv(
                $boundaryOffset,
                $intervalSeconds
            );
            if ($boundaryOffset % $intervalSeconds !== 0) {
                $requiredPreviousTicks++;
            }
            // 以tick起点判定归属，跨越边界的tick仍使用旧快照，阻止最后一刻换高追溯 / Assign by tick start so the tick crossing the boundary still uses the old snapshot, preventing last-moment buff retroactivity
            $previousTicks = min(
                $settledTicks,
                $requiredPreviousTicks
            );
            $conservativeTicks = 0;
            $currentSnapshotStartsAt = $settledAt
                + $requiredPreviousTicks * $intervalSeconds;
            $changeWindowBoundaryAt = $normalizedBoundary;
        } else {
            $normalizedDirtySince = $dirtySinceAt === null
                ? $settledAt
                : (int) $dirtySinceAt;
            $normalizedLatestBoundary =
                $changeWindowObserved
                    && $latestBoundaryAt !== null
                    ? (int) $latestBoundaryAt
                    : $now;
            $normalizedDirtySince = min(
                $now,
                max($settledAt, $normalizedDirtySince)
            );
            $normalizedLatestBoundary = min(
                $now,
                max(
                    $normalizedDirtySince,
                    $normalizedLatestBoundary
                )
            );
            $firstUncertainTick = self::firstTickIndexAtOrAfter(
                $settledAt,
                $normalizedDirtySince,
                $intervalSeconds
            );
            $firstCurrentTick = self::firstTickIndexAtOrAfter(
                $settledAt,
                $normalizedLatestBoundary,
                $intervalSeconds
            );
            // 首边界之前使用旧快照，末边界之后使用当前快照，中间只使用共同基础下界 / Use the old snapshot before the first boundary, the current snapshot after the latest boundary, and only the common base floor between them
            $previousTicks = min(
                $settledTicks,
                $firstUncertainTick
            );
            $currentTicks = max(
                0,
                $settledTicks - $firstCurrentTick
            );
            $conservativeTicks = max(
                0,
                $settledTicks
                    - $previousTicks
                    - $currentTicks
            );
            $currentSnapshotStartsAt = $settledAt
                + $firstCurrentTick * $intervalSeconds;
            $changeWindowBoundaryAt =
                $normalizedLatestBoundary;
        }
        if ($changeCount <= 1) {
            $currentTicks = $settledTicks - $previousTicks;
        }
        $previousProduction = self::calculateProductionForTicks(
            $previous,
            $previousTicks
        );
        $conservativeProduction =
            self::calculateCommonBaseProductionForTicks(
                $previous,
                $current,
                $conservativeTicks
            );
        $currentProduction = self::calculateProductionForTicks(
            $current,
            $currentTicks
        );
        $production = self::emptyProductionAmounts();
        foreach (self::PRODUCTION_RESOURCE_TYPES as $resourceType) {
            $production[$resourceType] = min(
                self::PRODUCTION_INTEGER_MAX,
                $previousProduction[$resourceType]
                    + $conservativeProduction[$resourceType]
                    + $currentProduction[$resourceType]
            );
        }

        return [
            'valid' => true,
            'production' => $production,
            'settled_ticks' => $settledTicks,
            'previous_ticks' => $previousTicks,
            'conservative_ticks' => $conservativeTicks,
            'current_ticks' => $currentTicks,
            'settled_seconds' => $settledTicks * $intervalSeconds,
            'current_snapshot_starts_at' =>
                $currentSnapshotStartsAt,
            'change_window_boundary_at' =>
                $changeWindowBoundaryAt
        ];
    }

    /**
     * 取得不早于边界的首个tick序号 / Returns the first tick index not earlier than a boundary
     * @param int $settledAt 生产游标 / Production cursor
     * @param int $boundaryAt 边界时间 / Boundary timestamp
     * @param int $intervalSeconds tick秒数 / Tick duration in seconds
     * @return int tick序号 / Tick index
     */
    private static function firstTickIndexAtOrAfter(
        $settledAt,
        $boundaryAt,
        $intervalSeconds
    ) {
        $offset = max(0, (int) $boundaryAt - (int) $settledAt);
        $tickIndex = intdiv($offset, (int) $intervalSeconds);
        if ($offset % (int) $intervalSeconds !== 0) {
            $tickIndex++;
        }
        return $tickIndex;
    }

    /**
     * 计算两端完全相同设施基础率的保守产量 / Calculates conservative production from identical facility base rates at both endpoints
     * @param array $previous 规范化旧快照 / Normalized previous snapshot
     * @param array $current 规范化当前快照 / Normalized current snapshot
     * @param int $ticks 不确定tick数 / Uncertain tick count
     * @return array<string,int> 六色保守产量 / Conservative six-resource production
     */
    private static function calculateCommonBaseProductionForTicks(
        array $previous,
        array $current,
        $ticks
    ) {
        $production = self::emptyProductionAmounts();
        $ticks = max(0, (int) $ticks);
        if ($ticks < 1) {
            return $production;
        }

        foreach (self::PRODUCTION_RESOURCE_TYPES as $resourceType) {
            $currentBaseRates = [];
            foreach ($current['streams'][$resourceType] as $stream) {
                $currentBaseRates[(int) $stream['facility_id']] =
                    (float) $stream['base_per_tick'];
            }
            foreach ($previous['streams'][$resourceType] as $stream) {
                $facilityId = (int) $stream['facility_id'];
                $previousBaseRate =
                    (float) $stream['base_per_tick'];
                if (!array_key_exists(
                    $facilityId,
                    $currentBaseRates
                )
                    || $previousBaseRate
                        !== $currentBaseRates[$facilityId]) {
                    continue;
                }
                $rawAmount = (float) $ticks * $previousBaseRate;
                $amount = !is_finite($rawAmount)
                    || $rawAmount >= self::PRODUCTION_INTEGER_MAX
                    ? self::PRODUCTION_INTEGER_MAX
                    : max(0, (int) floor($rawAmount));
                $production[$resourceType] = min(
                    self::PRODUCTION_INTEGER_MAX,
                    $production[$resourceType] + $amount
                );
                if ($production[$resourceType]
                    >= self::PRODUCTION_INTEGER_MAX) {
                    break;
                }
            }
        }

        return $production;
    }

    /**
     * 计算规范化快照的指定 tick 产量 / Calculates production for a normalized snapshot over a tick count
     * @param array $snapshot 规范化快照 / Normalized snapshot
     * @param int $ticks 完整tick数 / Complete tick count
     * @return array<string,int> 六色产量 / Six-resource production
     */
    private static function calculateProductionForTicks(
        array $snapshot,
        $ticks
    ) {
        $production = self::emptyProductionAmounts();
        $ticks = max(0, (int) $ticks);
        if ($ticks < 1) {
            return $production;
        }

        foreach (self::PRODUCTION_RESOURCE_TYPES as $resourceType) {
            foreach ($snapshot['streams'][$resourceType] as $stream) {
                $rawAmount = (float) $ticks
                    * (float) $stream['per_tick'];
                $amount = !is_finite($rawAmount)
                    || $rawAmount >= self::PRODUCTION_INTEGER_MAX
                    ? self::PRODUCTION_INTEGER_MAX
                    : max(0, (int) floor($rawAmount));
                $production[$resourceType] = min(
                    self::PRODUCTION_INTEGER_MAX,
                    $production[$resourceType] + $amount
                );
                if ($production[$resourceType]
                    >= self::PRODUCTION_INTEGER_MAX) {
                    break;
                }
            }
        }

        return $production;
    }

    /**
     * 创建六色零产量结构 / Creates a zeroed six-resource production map
     * @return array<string,int> 零产量 / Zero production
     */
    private static function emptyProductionAmounts() {
        return array_fill_keys(
            self::PRODUCTION_RESOURCE_TYPES,
            0
        );
    }

    /**
     * 捕获各资源每座设施的单 tick 最终产率 / Captures each facility's final per-tick rate for every resource
     * @param array<int,int> $cityIds 已锁定城池ID / Locked city IDs
     * @param int $now 当前时间戳 / Current timestamp
     * @return array{snapshot:array,next_transition_at:?string,transition_count:int} 快照与定时状态变化 / Snapshot and scheduled state transitions
     */
    private static function buildProductionSnapshot(
        array $cityIds,
        $now
    ) {
        $streams = array_fill_keys(
            self::PRODUCTION_RESOURCE_TYPES,
            []
        );
        $nextTransition = null;
        $transitionCount = 0;

        foreach ($cityIds as $cityId) {
            $city = new City((int) $cityId);
            if (!$city->isValid()) {
                continue;
            }

            // 每座城池只汇总一次驻城生产加成 / Aggregate the assigned-general production bonus once per city
            $cityBonuses = $city->getAssignedGeneralCityBonuses([
                'phase' => 'production'
            ]);
            $facilities = Facility::getCityFacilitiesByType(
                (int) $cityId,
                'resource_production'
            );
            foreach ($facilities as $facility) {
                $isUnavailable = false;
                foreach ([
                    $facility->getConstructionTime(),
                    $facility->getUpgradeTime()
                ] as $transitionAt) {
                    if ($transitionAt === null) {
                        continue;
                    }
                    $transitionTimestamp = strtotime(
                        (string) $transitionAt
                    );
                    if ($transitionTimestamp !== false
                        && $transitionTimestamp > $now) {
                        $isUnavailable = true;
                        $transitionCount =
                            self::saturatingProductionChangeCountAdd(
                                $transitionCount,
                                1
                            );
                        $nextTransition = $nextTransition === null
                            ? $transitionTimestamp
                            : min($nextTransition, $transitionTimestamp);
                    }
                }
                if ($isUnavailable) {
                    continue;
                }

                $resourceType = (string) $facility->getSubtype();
                if (!array_key_exists($resourceType, $streams)) {
                    continue;
                }
                $scopedKey = 'production_' . $resourceType;
                $bonus = isset($cityBonuses['production'])
                    ? (float) $cityBonuses['production']
                    : 0.0;
                if (isset($cityBonuses[$scopedKey])) {
                    $bonus += (float) $cityBonuses[$scopedKey];
                }
                $baseRate = (float) $facility->getEffectValue();
                $rate = City::applyPercentageBonus(
                    $baseRate,
                    $bonus
                );
                if (is_finite($baseRate)
                    && $baseRate >= 0.0
                    && is_finite($rate)
                    && $rate > 0.0) {
                    $streams[$resourceType][] = [
                        'facility_id' => (int) $facility->getFacilityId(),
                        'per_tick' => (float) $rate,
                        'base_per_tick' => $baseRate
                    ];
                }
            }
        }

        // 按设施ID稳定排序，以保留逐设施取整语义并生成稳定指纹 / Sort by facility ID to preserve per-facility rounding semantics and produce a stable fingerprint
        foreach ($streams as &$resourceStreams) {
            usort(
                $resourceStreams,
                function ($left, $right) {
                    return (int) $left['facility_id']
                        <=> (int) $right['facility_id'];
                }
            );
        }
        unset($resourceStreams);

        return [
            'snapshot' => [
                'schema_version' =>
                    self::PRODUCTION_SNAPSHOT_SCHEMA_VERSION,
                'interval_seconds' => max(
                    1,
                    (int) RESOURCE_PRODUCTION_INTERVAL
                ),
                'streams' => $streams
            ],
            'next_transition_at' => $nextTransition === null
                ? null
                : date('Y-m-d H:i:s', $nextTransition),
            'transition_count' => $transitionCount
        ];
    }

    /**
     * 解码并验证生产快照 / Decodes and validates a production snapshot
     * @param mixed $snapshotJson 快照JSON / Snapshot JSON
     * @return array|null 规范化快照或空 / Normalized snapshot or null
     */
    private static function decodeProductionSnapshot($snapshotJson) {
        if (!is_string($snapshotJson)
            || trim($snapshotJson) === '') {
            return null;
        }
        $decoded = json_decode($snapshotJson, true);
        return is_array($decoded)
            ? self::normalizeProductionSnapshot($decoded)
            : null;
    }

    /**
     * 规范化不可信生产快照并限制工作量 / Normalizes an untrusted production snapshot and bounds its workload
     * @param array $snapshot 快照 / Snapshot
     * @return array|null 规范化快照或空 / Normalized snapshot or null
     */
    private static function normalizeProductionSnapshot(array $snapshot) {
        if (!isset(
            $snapshot['schema_version'],
            $snapshot['interval_seconds'],
            $snapshot['streams']
        )
            || (int) $snapshot['schema_version']
                !== self::PRODUCTION_SNAPSHOT_SCHEMA_VERSION
            || !is_int($snapshot['schema_version'])
            || !is_int($snapshot['interval_seconds'])
            || (int) $snapshot['interval_seconds'] < 1
            || (int) $snapshot['interval_seconds'] > 86400
            || !is_array($snapshot['streams'])) {
            return null;
        }

        $normalizedStreams = [];
        $seenFacilityIds = [];
        foreach (self::PRODUCTION_RESOURCE_TYPES as $resourceType) {
            if (!isset($snapshot['streams'][$resourceType])
                || !is_array($snapshot['streams'][$resourceType])
                || count($snapshot['streams'][$resourceType])
                    > self::MAX_PRODUCTION_STREAMS_PER_RESOURCE) {
                return null;
            }
            $normalizedStreams[$resourceType] = [];
            foreach ($snapshot['streams'][$resourceType] as $stream) {
                if (!is_array($stream)
                    || !isset(
                        $stream['facility_id'],
                        $stream['per_tick'],
                        $stream['base_per_tick']
                    )
                    || !is_int($stream['facility_id'])
                    || (int) $stream['facility_id'] <= 0
                    || (
                        !is_int($stream['per_tick'])
                        && !is_float($stream['per_tick'])
                    )
                    || !is_finite((float) $stream['per_tick'])
                    || (float) $stream['per_tick'] < 0.0
                    || (float) $stream['per_tick']
                        > self::PRODUCTION_INTEGER_MAX
                    || (
                        !is_int($stream['base_per_tick'])
                        && !is_float($stream['base_per_tick'])
                    )
                    || !is_finite(
                        (float) $stream['base_per_tick']
                    )
                    || (float) $stream['base_per_tick'] < 0.0
                    || (float) $stream['base_per_tick']
                        > self::PRODUCTION_INTEGER_MAX
                    || (float) $stream['base_per_tick']
                        > (float) $stream['per_tick']) {
                    return null;
                }
                $facilityId = (int) $stream['facility_id'];
                if (isset($seenFacilityIds[$facilityId])) {
                    return null;
                }
                $seenFacilityIds[$facilityId] = true;
                $normalizedStreams[$resourceType][] = [
                    'facility_id' => $facilityId,
                    'per_tick' => (float) $stream['per_tick'],
                    'base_per_tick' =>
                        (float) $stream['base_per_tick']
                ];
            }
            usort(
                $normalizedStreams[$resourceType],
                function ($left, $right) {
                    return (int) $left['facility_id']
                        <=> (int) $right['facility_id'];
                }
            );
        }

        return [
            'schema_version' => self::PRODUCTION_SNAPSHOT_SCHEMA_VERSION,
            'interval_seconds' => (int) $snapshot['interval_seconds'],
            'streams' => $normalizedStreams
        ];
    }

    /**
     * 判断两份规范化生产快照是否相同 / Checks whether two normalized production snapshots match
     * @param array $left 左快照 / Left snapshot
     * @param array $right 右快照 / Right snapshot
     * @return bool 是否相同 / Whether identical
     */
    private static function productionSnapshotsEqual(
        array $left,
        array $right
    ) {
        $normalizedLeft = self::normalizeProductionSnapshot($left);
        $normalizedRight = self::normalizeProductionSnapshot($right);
        return $normalizedLeft !== null
            && $normalizedRight !== null
            && $normalizedLeft === $normalizedRight;
    }

    /**
     * 持久化生产游标、变化窗口、计划边界与快照 / Persists the production cursor, change window, scheduled boundaries, and snapshot
     * @param mysqli $db 数据库连接 / Database connection
     * @param int $userId 玩家ID / User ID
     * @param string $settledAt 已结算时间 / Settled timestamp
     * @param int|null $dirtySinceOffset 首边界相对游标秒数 / First-boundary offset from the cursor
     * @param string|null $dirtyAt 最近实际或固定观察边界 / Latest actual or fixed observation boundary
     * @param int $changeCount 未结算变化次数 / Unsettled change count
     * @param bool $changeWindowObserved 多变更窗口已固定 / Multi-change window fixed
     * @param int|null $scheduledOffset 计划首边界相对游标秒数 / Scheduled first-boundary offset from the cursor
     * @param int $scheduledChangeCount 已知计划变化数 / Known scheduled change count
     * @param string $snapshotJson 快照JSON / Snapshot JSON
     * @return void
     */
    private static function persistProductionState(
        $db,
        $userId,
        $settledAt,
        $dirtySinceOffset,
        $dirtyAt,
        $changeCount,
        $changeWindowObserved,
        $scheduledOffset,
        $scheduledChangeCount,
        $snapshotJson
    ) {
        $query = "UPDATE resource_production_states
                  SET settled_at = ?,
                      dirty_since_offset_seconds = ?,
                      dirty_at = ?,
                      change_count = ?,
                      change_window_observed = ?,
                      scheduled_offset_seconds = ?,
                      scheduled_change_count = ?,
                      snapshot_json = ?
                  WHERE user_id = ?";
        $stmt = $db->prepare($query);
        $changeWindowObserved = $changeWindowObserved ? 1 : 0;
        $stmt->bind_param(
            'sisiiiisi',
            $settledAt,
            $dirtySinceOffset,
            $dirtyAt,
            $changeCount,
            $changeWindowObserved,
            $scheduledOffset,
            $scheduledChangeCount,
            $snapshotJson,
            $userId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '更新资源生产状态失败 / Failed to update resource production state'
            );
        }
        $stmt->close();
    }

    /**
     * 解析可空生产时间 / Parses an optional production timestamp
     * @param mixed $value 时间文本 / Timestamp text
     * @return int|null 时间戳或空 / Timestamp or null
     */
    private static function parseOptionalProductionTimestamp($value) {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    /**
     * 解析首边界相对游标秒数 / Parses the first-boundary offset from the cursor
     * @param mixed $value 偏移值 / Offset value
     * @return int|null 非负偏移或空 / Non-negative offset or null
     */
    private static function parseProductionOffset($value) {
        if ($value === null
            || $value === ''
            || !is_numeric($value)) {
            return null;
        }
        $offset = (int) $value;
        return $offset < 0 ? null : $offset;
    }

    /**
     * 解析未结算变化次数 / Parses the unsettled production change count
     * @param mixed $value 变化次数 / Change count
     * @return int 饱和非负次数 / Saturated non-negative count
     */
    private static function parseProductionChangeCount($value) {
        if (!is_numeric($value)) {
            return 0;
        }
        return min(4294967295, max(0, (int) $value));
    }

    /**
     * 饱和相加生产变化次数 / Saturating-adds production change counts
     * @param int $left 左次数 / Left count
     * @param int $right 右次数 / Right count
     * @return int 饱和次数 / Saturated count
     */
    private static function saturatingProductionChangeCountAdd(
        $left,
        $right
    ) {
        $left = self::parseProductionChangeCount($left);
        $right = self::parseProductionChangeCount($right);
        return $left >= 4294967295 - $right
            ? 4294967295
            : $left + $right;
    }

    /**
     * 将当前实体中的计划变化转为相对生产游标状态 / Converts scheduled entity transitions into cursor-relative state
     * @param array $currentState 当前生产快照状态 / Current production snapshot state
     * @param int $settledAt 下一生产游标 / Next production cursor
     * @return array{offset_seconds:?int,change_count:int} 计划状态 / Scheduled state
     */
    private static function buildScheduledProductionState(
        array $currentState,
        $settledAt
    ) {
        $transitionAt = self::parseOptionalProductionTimestamp(
            isset($currentState['next_transition_at'])
                ? $currentState['next_transition_at']
                : null
        );
        $transitionCount = self::parseProductionChangeCount(
            isset($currentState['transition_count'])
                ? $currentState['transition_count']
                : 0
        );
        if ($transitionAt === null || $transitionCount < 1) {
            return [
                'offset_seconds' => null,
                'change_count' => 0
            ];
        }
        return [
            'offset_seconds' => max(
                0,
                $transitionAt - (int) $settledAt
            ),
            'change_count' => $transitionCount
        ];
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

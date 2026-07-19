<?php
// 种火集结号 - 士兵类

class Soldier {
    private $db;
    private $soldierId;
    private $cityId;
    private $type;
    private $level;
    private $quantity;
    private $inTraining;
    private $trainingCompleteTime;
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $soldierId 士兵ID
     */
    public function __construct($soldierId = null) {
        $this->db = Database::getInstance()->getConnection();
        
        if ($soldierId !== null) {
            $this->soldierId = $soldierId;
            $this->loadSoldierData();
        }
    }
    
    /**
     * 加载士兵数据
     */
    private function loadSoldierData() {
        $query = "SELECT * FROM soldiers WHERE soldier_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->soldierId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $soldierData = $result->fetch_assoc();
            $this->cityId = $soldierData['city_id'];
            $this->type = $soldierData['type'];
            $this->level = $soldierData['level'];
            $this->quantity = $soldierData['quantity'];
            $this->inTraining = $soldierData['in_training'];
            $this->trainingCompleteTime = $soldierData['training_complete_time'];
            $this->isValid = true;
        }
        
        $stmt->close();
    }
    
    /**
     * 通过城池ID和士兵类型加载士兵数据
     * @param int $cityId 城池ID
     * @param string $type 士兵类型
     * @return bool
     */
    public function loadByCityAndType($cityId, $type) {
        $query = "SELECT * FROM soldiers WHERE city_id = ? AND type = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('is', $cityId, $type);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $soldierData = $result->fetch_assoc();
            $this->soldierId = $soldierData['soldier_id'];
            $this->cityId = $soldierData['city_id'];
            $this->type = $soldierData['type'];
            $this->level = $soldierData['level'];
            $this->quantity = $soldierData['quantity'];
            $this->inTraining = $soldierData['in_training'];
            $this->trainingCompleteTime = $soldierData['training_complete_time'];
            $this->isValid = true;
            $stmt->close();
            return true;
        }
        
        $stmt->close();
        return false;
    }
    
    /**
     * 检查士兵是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 获取士兵ID
     * @return int
     */
    public function getSoldierId() {
        return $this->soldierId;
    }
    
    /**
     * 获取城池ID
     * @return int
     */
    public function getCityId() {
        return $this->cityId;
    }
    
    /**
     * 获取士兵类型
     * @return string
     */
    public function getType() {
        return $this->type;
    }
    
    /**
     * 获取士兵等级
     * @return int
     */
    public function getLevel() {
        return $this->level;
    }
    
    /**
     * 获取士兵数量
     * @return int
     */
    public function getQuantity() {
        return $this->quantity;
    }
    
    /**
     * 获取训练中的士兵数量
     * @return int
     */
    public function getInTraining() {
        return $this->inTraining;
    }
    
    /**
     * 获取训练完成时间
     * @return string|null
     */
    public function getTrainingCompleteTime() {
        return $this->trainingCompleteTime;
    }
    
    /**
     * 检查训练是否完成
     * @return bool
     */
    public function isTrainingComplete() {
        if (!$this->isValid || $this->inTraining <= 0 || !$this->trainingCompleteTime) {
            return false;
        }
        
        $now = time();
        $trainingCompleteTime = strtotime($this->trainingCompleteTime);
        
        return $now >= $trainingCompleteTime;
    }
    
    /**
     * 完成训练
     * @return bool
     */
    public function completeTraining() {
        if (!$this->isValid || !$this->isTrainingComplete()) {
            return false;
        }

        $this->db->begin_transaction();

        try {
            lockSeasonForWorldAction($this->db);

            // 锁定训练队列并重新读取，避免覆盖并发追加的训练 / Lock and reload the queue to avoid overwriting concurrent additions
            $query = "SELECT quantity, in_training, training_complete_time
                      FROM soldiers
                      WHERE soldier_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->soldierId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$row
                || (int) $row['in_training'] <= 0
                || empty($row['training_complete_time'])
                || strtotime($row['training_complete_time']) > time()) {
                $this->db->rollback();
                return false;
            }

            $completedQuantity = (int) $row['in_training'];
            $newQuantity = (int) $row['quantity'] + $completedQuantity;
            $query = "UPDATE soldiers
                      SET quantity = ?, in_training = 0, training_complete_time = NULL
                      WHERE soldier_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $newQuantity, $this->soldierId);
            if (!$stmt->execute()) {
                throw new RuntimeException('完成士兵训练失败 / Failed to complete soldier training');
            }
            $stmt->close();

            $this->db->commit();
            $this->quantity = $newQuantity;
            $this->inTraining = 0;
            $this->trainingCompleteTime = null;
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Soldier training completion failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新训练状态
     * @param int $inTraining 训练中的士兵数量
     * @param string $trainingCompleteTime 训练完成时间
     * @return bool
     */
    public function updateTraining($inTraining, $trainingCompleteTime) {
        if (!$this->isValid) {
            return false;
        }
        
        $query = "UPDATE soldiers SET in_training = ?, training_complete_time = ? WHERE soldier_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isi', $inTraining, $trainingCompleteTime, $this->soldierId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->inTraining = $inTraining;
            $this->trainingCompleteTime = $trainingCompleteTime;
            return true;
        }
        
        return false;
    }
    
    /**
     * 增加士兵数量
     * @param int $amount 增加的数量
     * @return bool
     */
    public function addQuantity($amount) {
        if (!$this->isValid || $amount <= 0) {
            return false;
        }
        
        $newQuantity = $this->quantity + $amount;
        
        $query = "UPDATE soldiers SET quantity = ? WHERE soldier_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newQuantity, $this->soldierId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->quantity = $newQuantity;
            return true;
        }
        
        return false;
    }
    
    /**
     * 减少士兵数量
     * @param int $amount 减少的数量
     * @return bool
     */
    public function reduceQuantity($amount) {
        if (!$this->isValid || $amount <= 0 || $amount > $this->quantity) {
            return false;
        }
        
        $newQuantity = $this->quantity - $amount;
        
        $query = "UPDATE soldiers SET quantity = ? WHERE soldier_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newQuantity, $this->soldierId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->quantity = $newQuantity;
            return true;
        }
        
        return false;
    }
    
    /**
     * 升级士兵
     * @param int $newLevel 新等级
     * @return bool
     */
    public function upgrade($newLevel) {
        if (!$this->isValid || $newLevel <= $this->level) {
            return false;
        }
        
        $query = "UPDATE soldiers SET level = ? WHERE soldier_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newLevel, $this->soldierId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->level = $newLevel;
            return true;
        }
        
        return false;
    }
    
    /**
     * 创建新士兵
     * @param int $cityId 城池ID
     * @param string $type 士兵类型
     * @param int $level 士兵等级
     * @param int $quantity 士兵数量
     * @param int $inTraining 训练中的士兵数量
     * @param string|null $trainingCompleteTime 训练完成时间
     * @return bool|int 成功返回士兵ID，失败返回false
     */
    public function createSoldier($cityId, $type, $level = 1, $quantity = 0, $inTraining = 0, $trainingCompleteTime = null) {
        $cityId = (int) $cityId;
        $level = (int) $level;
        $quantity = (int) $quantity;
        $inTraining = (int) $inTraining;
        if ($cityId <= 0 || $level <= 0 || $quantity < 0 || $inTraining < 0) {
            return false;
        }

        // 检查城池ID是否存在
        $cityQuery = "SELECT city_id FROM cities WHERE city_id = ?";
        $cityStmt = $this->db->prepare($cityQuery);
        $cityStmt->bind_param('i', $cityId);
        $cityStmt->execute();
        $cityResult = $cityStmt->get_result();
        
        if (!$cityResult || $cityResult->num_rows == 0) {
            $cityStmt->close();
            return false; // 城池ID不存在
        }
        
        $cityStmt->close();
        
        // 检查士兵类型是否有效
        $validTypes = ['pawn', 'knight', 'rook', 'bishop', 'golem', 'scout'];
        if (!in_array($type, $validTypes)) {
            return false;
        }
        
        // 检查该城池是否已经有同类型的士兵
        $soldierQuery = "SELECT soldier_id FROM soldiers WHERE city_id = ? AND type = ?";
        $soldierStmt = $this->db->prepare($soldierQuery);
        $soldierStmt->bind_param('is', $cityId, $type);
        $soldierStmt->execute();
        $soldierResult = $soldierStmt->get_result();
        
        if ($soldierResult && $soldierResult->num_rows > 0) {
            $soldierStmt->close();
            return false; // 该城池已经有同类型的士兵
        }
        
        $soldierStmt->close();
        
        // 创建新士兵
        $query = "INSERT INTO soldiers (city_id, type, level, quantity, in_training, training_complete_time) 
                  VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isiiis', $cityId, $type, $level, $quantity, $inTraining, $trainingCompleteTime);
        $result = $stmt->execute();
        
        if ($result) {
            $soldierId = $this->db->insert_id;
            $stmt->close();
            
            // 设置对象属性
            $this->soldierId = $soldierId;
            $this->cityId = $cityId;
            $this->type = $type;
            $this->level = $level;
            $this->quantity = $quantity;
            $this->inTraining = $inTraining;
            $this->trainingCompleteTime = $trainingCompleteTime;
            $this->isValid = true;
            
            return $soldierId;
        }
        
        $stmt->close();
        return false;
    }

    /**
     * 获取兵种所需的训练设施类型 / Get the facility type required to train a soldier
     * @param string $soldierType 兵种类型 / Soldier type
     * @return string|null 设施类型 / Facility type
     */
    public static function getTrainingFacilityType($soldierType) {
        switch ($soldierType) {
            case 'pawn':
            case 'knight':
            case 'rook':
            case 'bishop':
                return 'barracks';
            case 'golem':
                return 'workshop';
            case 'scout':
                return 'watchtower';
            default:
                return null;
        }
    }

    /**
     * 获取士兵训练费用 / Get the resource cost for training soldiers
     * @param string $soldierType 兵种类型 / Soldier type
     * @param int $quantity 数量 / Quantity
     * @return array 资源费用 / Resource costs
     */
    public static function getTrainingCost($soldierType, $quantity = 1) {
        $quantity = (int) $quantity;
        if ($quantity <= 0) {
            return [];
        }

        switch ($soldierType) {
            case 'pawn':
                return ['day' => 10 * $quantity];
            case 'knight':
                return ['warm' => 20 * $quantity];
            case 'rook':
                return ['cold' => 20 * $quantity];
            case 'bishop':
                return ['green' => 20 * $quantity];
            case 'golem':
                return [
                    'warm' => 30 * $quantity,
                    'cold' => 30 * $quantity,
                    'green' => 30 * $quantity,
                    'day' => 30 * $quantity
                ];
            case 'scout':
                return [
                    'warm' => 15 * $quantity,
                    'cold' => 15 * $quantity,
                    'green' => 15 * $quantity,
                    'day' => 15 * $quantity
                ];
            default:
                return [];
        }
    }

    /**
     * 应用驻城技能的训练费用减免 / Applies assigned-general training-cost reduction
     * @param array $cost 基础资源费用 / Base resource costs
     * @param mixed $reductionPercent 减免百分比 / Reduction percentage
     * @return array 减免后的整数费用 / Reduced integer costs
     */
    public static function applyTrainingCostReduction(
        array $cost,
        $reductionPercent
    ) {
        $reduction = is_numeric($reductionPercent)
            && is_finite((float) $reductionPercent)
            ? min(95.0, max(0.0, (float) $reductionPercent))
            : 0.0;
        $adjusted = [];
        foreach ($cost as $resourceType => $amount) {
            if (!is_string($resourceType)
                || !is_numeric($amount)
                || (float) $amount < 0.0) {
                continue;
            }
            $adjusted[$resourceType] = max(
                0,
                (int) ceil(round(
                    (float) $amount
                        * (1.0 - $reduction / 100.0),
                    8
                ))
            );
        }

        return $adjusted;
    }

    /**
     * 获取驻城技能修正后的训练费用 / Gets training costs adjusted by assigned-city skills
     * @param int $cityId 城池ID / City ID
     * @param string $soldierType 兵种类型 / Soldier type
     * @param int $quantity 数量 / Quantity
     * @param array|null $cityBonuses 已汇总的驻城加成 / Pre-aggregated assigned-city bonuses
     * @return array 修正后的资源费用 / Adjusted resource costs
     */
    public static function getAdjustedTrainingCost(
        $cityId,
        $soldierType,
        $quantity = 1,
        $cityBonuses = null
    ) {
        $baseCost = self::getTrainingCost($soldierType, $quantity);
        if (empty($baseCost)) {
            return $baseCost;
        }

        if (!is_array($cityBonuses)) {
            $city = new City((int) $cityId);
            if (!$city->isValid()) {
                return $baseCost;
            }
            $cityBonuses = $city->getAssignedGeneralCityBonuses([
                'phase' => 'training'
            ]);
        }
        $reduction = isset($cityBonuses['training_cost_reduction'])
            ? (float) $cityBonuses['training_cost_reduction']
            : 0.0;
        $scopedKey = 'training_cost_reduction_' . $soldierType;
        if (isset($cityBonuses[$scopedKey])) {
            $reduction += (float) $cityBonuses[$scopedKey];
        }

        return self::applyTrainingCostReduction($baseCost, $reduction);
    }

    /**
     * 原子化追加士兵训练队列 / Atomically append a soldier training batch
     * @param int $userId 用户ID / User ID
     * @param int $cityId 城池ID / City ID
     * @param string $soldierType 兵种类型 / Soldier type
     * @param int $quantity 数量 / Quantity
     * @return array 训练结果 / Training result
     */
    public static function startTraining($userId, $cityId, $soldierType, $quantity) {
        $userId = (int) $userId;
        $cityId = (int) $cityId;
        $quantity = (int) $quantity;
        $facilityType = self::getTrainingFacilityType($soldierType);
        $trainingCost = self::getTrainingCost($soldierType, $quantity);

        if ($userId <= 0 || $cityId <= 0 || $quantity <= 0 || $quantity > 10000
            || $facilityType === null || empty($trainingCost)) {
            return ['success' => false, 'message' => '训练参数无效'];
        }

        $db = Database::getInstance()->getConnection();
        $db->begin_transaction();

        try {
            lockSeasonForWorldAction($db);

            // 锁定城池所有权，防止训练过程中城池易主 / Lock city ownership so it cannot change during training setup
            $query = "SELECT owner_id FROM cities WHERE city_id = ? FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $cityRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$cityRow || (int) $cityRow['owner_id'] !== $userId) {
                $db->rollback();
                return ['success' => false, 'message' => '城池不存在或不属于当前用户'];
            }

            // 选择等级最高且完全可用的训练设施 / Select the highest-level fully available training facility
            $query = "SELECT facility_id
                      FROM facilities
                      WHERE city_id = ?
                        AND type = ?
                        AND construction_time IS NULL
                        AND upgrade_time IS NULL
                      ORDER BY level DESC, facility_id ASC
                      LIMIT 1
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('is', $cityId, $facilityType);
            $stmt->execute();
            $result = $stmt->get_result();
            $facilityRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$facilityRow) {
                $db->rollback();
                return ['success' => false, 'message' => '没有可用的训练设施'];
            }

            $facility = new Facility((int) $facilityRow['facility_id']);
            $trainingSeconds = $facility->calculateSoldierTrainingTime($soldierType, $quantity);
            if (!$facility->isValid() || $trainingSeconds <= 0) {
                throw new RuntimeException('训练设施状态无效 / Training facility state is invalid');
            }

            // 全兵种与指定兵种减免可组合，并在扣款前封顶为95%。 / Global and unit-specific reductions compose and are capped at 95% before deduction.
            $trainingCost = self::getAdjustedTrainingCost(
                $cityId,
                $soldierType,
                $quantity
            );

            $trainingLevel = $facilityType === 'barracks'
                ? $facility->getMaxSoldierLevel()
                : $facility->getLevel();

            // 锁定全城士兵记录并核算宿舍容量 / Lock city soldier rows and calculate dormitory capacity usage
            $query = "SELECT soldier_id, type, level, quantity, in_training, training_complete_time
                      FROM soldiers
                      WHERE city_id = ?
                      FOR UPDATE";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $usedCapacity = 0;
            $targetRow = null;

            while ($result && $row = $result->fetch_assoc()) {
                $usedCapacity += (int) $row['quantity'] + (int) $row['in_training'];
                if ($row['type'] === $soldierType) {
                    $targetRow = $row;
                }
            }
            $stmt->close();

            $totalCapacity = Facility::getCityTotalSoldierCapacity($cityId);
            if ($usedCapacity + $quantity > $totalCapacity) {
                $db->rollback();
                return [
                    'success' => false,
                    'message' => '士兵容量不足，请先建造或升级宿舍',
                    'capacity' => $totalCapacity,
                    'used_capacity' => $usedCapacity
                ];
            }

            // 锁定并校验资源，再在同一事务中扣除 / Lock and validate resources before deducting them in the same transaction
            $query = "SELECT bright_crystal, warm_crystal, cold_crystal,
                             green_crystal, day_crystal, night_crystal
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
                throw new RuntimeException('玩家资源记录不存在 / User resource record does not exist');
            }

            $resourceColumns = [
                'bright' => 'bright_crystal',
                'warm' => 'warm_crystal',
                'cold' => 'cold_crystal',
                'green' => 'green_crystal',
                'day' => 'day_crystal',
                'night' => 'night_crystal'
            ];
            foreach ($trainingCost as $resourceType => $amount) {
                $column = $resourceColumns[$resourceType];
                if ((int) $resourceRow[$column] < (int) $amount) {
                    $db->rollback();
                    return ['success' => false, 'message' => getResourceName($resourceType) . '不足'];
                }
            }

            $brightCost = isset($trainingCost['bright']) ? (int) $trainingCost['bright'] : 0;
            $warmCost = isset($trainingCost['warm']) ? (int) $trainingCost['warm'] : 0;
            $coldCost = isset($trainingCost['cold']) ? (int) $trainingCost['cold'] : 0;
            $greenCost = isset($trainingCost['green']) ? (int) $trainingCost['green'] : 0;
            $dayCost = isset($trainingCost['day']) ? (int) $trainingCost['day'] : 0;
            $nightCost = isset($trainingCost['night']) ? (int) $trainingCost['night'] : 0;
            $nowDate = date('Y-m-d H:i:s');

            $query = "UPDATE resources
                      SET bright_crystal = bright_crystal - ?,
                          warm_crystal = warm_crystal - ?,
                          cold_crystal = cold_crystal - ?,
                          green_crystal = green_crystal - ?,
                          day_crystal = day_crystal - ?,
                          night_crystal = night_crystal - ?,
                          last_update = ?
                      WHERE user_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param(
                'iiiiiisi',
                $brightCost,
                $warmCost,
                $coldCost,
                $greenCost,
                $dayCost,
                $nightCost,
                $nowDate,
                $userId
            );
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('扣除训练资源失败 / Failed to deduct training resources');
            }
            $stmt->close();

            // 新批次从当前队列末尾开始，已到期批次先转为现役 / Start after the queue tail and first activate any elapsed batch
            $queueStart = time();
            $currentQuantity = 0;
            $currentTraining = 0;
            $soldierId = 0;

            if ($targetRow) {
                $soldierId = (int) $targetRow['soldier_id'];
                $currentQuantity = (int) $targetRow['quantity'];
                $currentTraining = (int) $targetRow['in_training'];
                $existingCompletion = !empty($targetRow['training_complete_time'])
                    ? strtotime($targetRow['training_complete_time'])
                    : 0;

                if ($currentTraining > 0 && $existingCompletion > 0 && $existingCompletion <= time()) {
                    $currentQuantity += $currentTraining;
                    $currentTraining = 0;
                } elseif ($currentTraining > 0 && $existingCompletion > time()) {
                    $queueStart = $existingCompletion;
                }

                $newTraining = $currentTraining + $quantity;
                $newLevel = max((int) $targetRow['level'], $trainingLevel);
                $trainingCompleteTime = date('Y-m-d H:i:s', $queueStart + $trainingSeconds);
                $query = "UPDATE soldiers
                          SET level = ?, quantity = ?, in_training = ?, training_complete_time = ?
                          WHERE soldier_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param(
                    'iiisi',
                    $newLevel,
                    $currentQuantity,
                    $newTraining,
                    $trainingCompleteTime,
                    $soldierId
                );
                if (!$stmt->execute() || $stmt->affected_rows > 1) {
                    throw new RuntimeException('更新士兵训练队列失败 / Failed to update soldier training queue');
                }
                $stmt->close();
            } else {
                $trainingCompleteTime = date('Y-m-d H:i:s', $queueStart + $trainingSeconds);
                $soldier = new Soldier();
                $soldierId = $soldier->createSoldier(
                    $cityId,
                    $soldierType,
                    $trainingLevel,
                    0,
                    $quantity,
                    $trainingCompleteTime
                );
                if (!$soldierId) {
                    throw new RuntimeException('创建士兵训练队列失败 / Failed to create soldier training queue');
                }
            }

            $db->commit();
            return [
                'success' => true,
                'message' => '士兵开始训练',
                'training' => [
                    'soldier_id' => (int) $soldierId,
                    'soldier_type' => $soldierType,
                    'level' => $trainingLevel,
                    'quantity' => $quantity,
                    'cost' => $trainingCost,
                    'training_seconds' => $trainingSeconds,
                    'training_complete_time' => $trainingCompleteTime
                ]
            ];
        } catch (Throwable $e) {
            $db->rollback();
            error_log('Soldier training start failed: ' . $e->getMessage());
            return ['success' => false, 'message' => '训练请求处理失败，请稍后重试'];
        }
    }
    
    /**
     * 获取士兵攻击力
     * @return int
     */
    public function getAttackPower() {
        if (!$this->isValid) {
            return 0;
        }
        
        $baseAttack = 0;
        
        switch ($this->type) {
            case 'pawn':
                $baseAttack = PAWN_ATTACK;
                break;
            case 'knight':
                $baseAttack = KNIGHT_ATTACK;
                break;
            case 'rook':
                $baseAttack = ROOK_ATTACK;
                break;
            case 'bishop':
                $baseAttack = BISHOP_ATTACK;
                break;
            case 'golem':
                $baseAttack = GOLEM_ATTACK;
                break;
            case 'scout':
                $baseAttack = 0; // 侦察兵没有攻击力
                break;
        }
        
        // 根据等级计算实际攻击力
        $levelCoefficient = 0.2; // 攻击力等级系数
        $actualAttack = $baseAttack * (1 + ($this->level - 1) * $levelCoefficient);
        
        // 应用全局攻击力修正
        $actualAttack *= $GLOBALS['SOLDIER_ATTACK_MODIFIER'];
        
        return $actualAttack;
    }
    
    /**
     * 获取士兵对城池的攻击力
     * @return int
     */
    public function getCityAttackPower() {
        if (!$this->isValid) {
            return 0;
        }
        
        $baseCityAttack = 0;
        
        switch ($this->type) {
            case 'pawn':
                $baseCityAttack = PAWN_CITY_ATTACK;
                break;
            case 'knight':
                $baseCityAttack = KNIGHT_CITY_ATTACK;
                break;
            case 'rook':
                $baseCityAttack = ROOK_CITY_ATTACK;
                break;
            case 'bishop':
                $baseCityAttack = BISHOP_CITY_ATTACK;
                break;
            case 'golem':
                $baseCityAttack = GOLEM_CITY_ATTACK;
                break;
            case 'scout':
                $baseCityAttack = 0; // 侦察兵没有攻城能力
                break;
        }
        
        // 根据等级计算实际攻城力
        $levelCoefficient = 0.2; // 攻城力等级系数
        $actualCityAttack = $baseCityAttack * (1 + ($this->level - 1) * $levelCoefficient);
        
        // 应用全局攻击力修正
        $actualCityAttack *= $GLOBALS['SOLDIER_ATTACK_MODIFIER'];
        
        return $actualCityAttack;
    }
    
    /**
     * 获取士兵防御力
     * @return int
     */
    public function getDefensePower() {
        if (!$this->isValid) {
            return 0;
        }
        
        $baseDefense = 0;
        
        switch ($this->type) {
            case 'pawn':
                $baseDefense = PAWN_DEFENSE;
                break;
            case 'knight':
                $baseDefense = KNIGHT_DEFENSE;
                break;
            case 'rook':
                $baseDefense = ROOK_DEFENSE;
                break;
            case 'bishop':
                $baseDefense = BISHOP_DEFENSE;
                break;
            case 'golem':
                $baseDefense = GOLEM_DEFENSE;
                break;
            case 'scout':
                $baseDefense = 0; // 侦察兵没有防御力
                break;
        }
        
        // 根据等级计算实际防御力
        $levelCoefficient = 0.2; // 防御力等级系数
        $actualDefense = $baseDefense * (1 + ($this->level - 1) * $levelCoefficient);
        
        // 应用全局防御力修正
        $actualDefense *= $GLOBALS['SOLDIER_DEFENSE_MODIFIER'];
        
        return $actualDefense;
    }
    
    /**
     * 获取士兵移动速度（秒/格）
     * @return int
     */
    public function getMovementSpeed() {
        if (!$this->isValid) {
            return 0;
        }
        
        $baseSpeed = 0;
        
        switch ($this->type) {
            case 'pawn':
                $baseSpeed = PAWN_MOVEMENT_SPEED;
                break;
            case 'knight':
                $baseSpeed = KNIGHT_MOVEMENT_SPEED;
                break;
            case 'rook':
                $baseSpeed = ROOK_MOVEMENT_SPEED;
                break;
            case 'bishop':
                $baseSpeed = BISHOP_MOVEMENT_SPEED;
                break;
            case 'golem':
                $baseSpeed = GOLEM_MOVEMENT_SPEED;
                break;
            case 'scout':
                $baseSpeed = SCOUT_MOVEMENT_SPEED;
                break;
        }
        
        // 应用全局移动速度修正
        $actualSpeed = $baseSpeed * $GLOBALS['ARMY_MOVEMENT_SPEED_MODIFIER'];
        
        return $actualSpeed;
    }
    
    /**
     * 获取士兵名称
     * @return string
     */
    public function getName() {
        if (!$this->isValid) {
            return '';
        }
        
        switch ($this->type) {
            case 'pawn':
                return '兵卒';
            case 'knight':
                return '骑士';
            case 'rook':
                return '城壁';
            case 'bishop':
                return '主教';
            case 'golem':
                return '锤子兵';
            case 'scout':
                return '侦察兵';
            default:
                return '未知士兵';
        }
    }
    
    /**
     * 获取城池中的所有士兵
     * @param int $cityId 城池ID
     * @return array 士兵数组
     */
    public static function getCitySoldiers($cityId) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT * FROM soldiers WHERE city_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $soldiers = [];
        
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
     * 检查并完成所有已完成训练的士兵
     * @return array 完成训练的士兵数组
     */
    public static function checkAndCompleteTraining() {
        $db = Database::getInstance()->getConnection();
        
        $now = date('Y-m-d H:i:s');
        
        $query = "SELECT soldier_id FROM soldiers WHERE in_training > 0 AND training_complete_time IS NOT NULL AND training_complete_time <= ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $now);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $completedSoldiers = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $soldier = new Soldier($row['soldier_id']);
                if ($soldier->isValid() && $soldier->completeTraining()) {
                    $completedSoldiers[] = [
                        'soldier_id' => $soldier->getSoldierId(),
                        'city_id' => $soldier->getCityId(),
                        'type' => $soldier->getType(),
                        'name' => $soldier->getName(),
                        'quantity' => $soldier->getQuantity()
                    ];
                }
            }
        }
        
        $stmt->close();
        return $completedSoldiers;
    }
}

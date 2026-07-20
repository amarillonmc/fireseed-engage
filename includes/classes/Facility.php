<?php
// 种火集结号 - 设施类

class Facility {
    private $db;
    private $facilityId;
    private $cityId;
    private $type;
    private $subtype;
    private $level;
    private $xPos;
    private $yPos;
    private $constructionTime;
    private $upgradeTime;
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $facilityId 设施ID
     */
    public function __construct($facilityId = null) {
        $this->db = Database::getInstance()->getConnection();
        
        if ($facilityId !== null) {
            $this->facilityId = $facilityId;
            $this->loadFacilityData();
        }
    }
    
    /**
     * 加载设施数据
     */
    private function loadFacilityData() {
        $query = "SELECT * FROM facilities WHERE facility_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->facilityId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $this->cityId = $data['city_id'];
            $this->type = $data['type'];
            $this->subtype = $data['subtype'];
            $this->level = $data['level'];
            $this->xPos = $data['x_pos'];
            $this->yPos = $data['y_pos'];
            $this->constructionTime = $data['construction_time'];
            $this->upgradeTime = $data['upgrade_time'];
            $this->isValid = true;
        }
        
        $stmt->close();
    }
    
    /**
     * 检查设施是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 获取设施ID
     * @return int
     */
    public function getFacilityId() {
        return $this->facilityId;
    }
    
    /**
     * 获取城池ID
     * @return int
     */
    public function getCityId() {
        return $this->cityId;
    }
    
    /**
     * 获取设施类型
     * @return string
     */
    public function getType() {
        return $this->type;
    }
    
    /**
     * 获取设施子类型
     * @return string|null
     */
    public function getSubtype() {
        return $this->subtype;
    }
    
    /**
     * 获取设施等级
     * @return int
     */
    public function getLevel() {
        return $this->level;
    }
    
    /**
     * 获取设施X坐标
     * @return int
     */
    public function getXPos() {
        return $this->xPos;
    }
    
    /**
     * 获取设施Y坐标
     * @return int
     */
    public function getYPos() {
        return $this->yPos;
    }
    
    /**
     * 获取设施建造完成时间
     * @return string|null
     */
    public function getConstructionTime() {
        return $this->constructionTime;
    }
    
    /**
     * 获取设施升级完成时间
     * @return string|null
     */
    public function getUpgradeTime() {
        return $this->upgradeTime;
    }
    
    /**
     * 检查设施是否正在建造
     * @return bool
     */
    public function isUnderConstruction() {
        if (!$this->isValid || !$this->constructionTime) {
            return false;
        }
        
        $constructionTime = strtotime($this->constructionTime);
        $now = time();
        
        return $now < $constructionTime;
    }
    
    /**
     * 检查设施是否正在升级
     * @return bool
     */
    public function isUpgrading() {
        if (!$this->isValid || !$this->upgradeTime) {
            return false;
        }
        
        $upgradeTime = strtotime($this->upgradeTime);
        $now = time();
        
        return $now < $upgradeTime;
    }
    
    /**
     * 获取设施名称
     * @return string
     */
    public function getName() {
        if (!$this->isValid) {
            return '';
        }
        
        switch ($this->type) {
            case 'resource_production':
                switch ($this->subtype) {
                    case 'bright':
                        return '亮晶晶产出点';
                    case 'warm':
                        return '暖洋洋产出点';
                    case 'cold':
                        return '冷冰冰产出点';
                    case 'green':
                        return '郁萌萌产出点';
                    case 'day':
                        return '昼闪闪产出点';
                    case 'night':
                        return '夜静静产出点';
                    default:
                        return '资源产出点';
                }
            case 'governor_office':
                return '总督府';
            case 'barracks':
                return '兵营';
            case 'research_lab':
                return '研究所';
            case 'dormitory':
                return '宿舍';
            case 'storage':
                return '贮存所';
            case 'watchtower':
                return '瞭望台';
            case 'workshop':
                return '工程所';
            default:
                return '未知设施';
        }
    }
    
    /**
     * 获取设施描述
     * @return string
     */
    public function getDescription() {
        if (!$this->isValid) {
            return '';
        }
        
        switch ($this->type) {
            case 'resource_production':
                switch ($this->subtype) {
                    case 'bright':
                        return '产出亮晶晶资源';
                    case 'warm':
                        return '产出暖洋洋资源';
                    case 'cold':
                        return '产出冷冰冰资源';
                    case 'green':
                        return '产出郁萌萌资源';
                    case 'day':
                        return '产出昼闪闪资源';
                    case 'night':
                        return '产出夜静静资源';
                    default:
                        return '产出资源';
                }
            case 'governor_office':
                return '城池的中心建筑，有耐久值，每48小时产出1点思考回路';
            case 'barracks':
                return '训练士兵的设施';
            case 'research_lab':
                return '研究科技的设施';
            case 'dormitory':
                return '存放士兵的设施';
            case 'storage':
                return '存放资源的设施';
            case 'watchtower':
                return '提高城池防御力，可以消耗资源产出侦察兵';
            case 'workshop':
                return '可以研究科技来提高城池防御力，并可以消耗资源产出锤子兵';
            default:
                return '未知设施';
        }
    }
    
    /**
     * 获取设施效果值
     * @return float
     */
    public function getEffectValue() {
        if (!$this->isValid) {
            return 0;
        }
        
        $baseValue = 0;
        $levelCoefficient = 0.5; // 默认等级系数
        
        switch ($this->type) {
            case 'resource_production':
                $baseValue = 1; // 基础资源产出：1点/3秒
                if (in_array($this->subtype, ['bright', 'night'], true)) {
                    // 跨赛季货币可日常产出，但默认显著慢于赛季资源 / Persistent currencies remain producible but default to a much slower rate
                    $baseValue *= max(
                        0.0,
                        min(
                            1.0,
                            (float) GameConfig::get(
                                'persistent_resource_production_multiplier',
                                0.2
                            )
                        )
                    );
                }
                break;
            case 'governor_office':
                $baseValue = 3000; // 基础耐久值
                break;
            case 'barracks':
                $baseValue = 1; // 可训练士兵等级
                break;
            case 'research_lab':
                $baseValue = 1; // 可研究科技等级
                break;
            case 'dormitory':
                $baseValue = 1000; // 基础士兵存放上限
                break;
            case 'storage':
                $baseValue = 100000; // 基础资源存放上限
                break;
            case 'watchtower':
                $baseValue = 0.1; // 基础城池防御力提升（10%）
                break;
            case 'workshop':
                $baseValue = 0.1; // 基础城池防御力提升（10%）
                break;
        }
        
        // 计算等级加成：效果值 = 基础值 * (1 + (等级-1) * 等级系数)
        return $baseValue * (1 + ($this->level - 1) * $levelCoefficient);
    }
    
    /**
     * 获取设施升级费用
     * @return array 资源费用数组
     */
    public function getUpgradeCost() {
        if (!$this->isValid) {
            return [];
        }
        
        $baseCost = [];
        $levelCoefficient = 1.5; // 升级费用系数
        
        switch ($this->type) {
            case 'resource_production':
                $baseCost = [
                    'warm' => 100,
                    'cold' => 100,
                    'green' => 100,
                    'day' => 100
                ];
                break;
            case 'governor_office':
                $baseCost = [
                    'warm' => 200,
                    'cold' => 200,
                    'green' => 200,
                    'day' => 200
                ];
                break;
            case 'barracks':
                $baseCost = [
                    'warm' => 300,
                    'cold' => 100,
                    'green' => 100,
                    'day' => 300
                ];
                break;
            case 'research_lab':
                $baseCost = [
                    'warm' => 100,
                    'cold' => 100,
                    'green' => 300,
                    'day' => 100
                ];
                break;
            case 'dormitory':
                $baseCost = [
                    'warm' => 150,
                    'cold' => 150,
                    'green' => 150,
                    'day' => 150
                ];
                break;
            case 'storage':
                $baseCost = [
                    'warm' => 100,
                    'cold' => 200,
                    'green' => 100,
                    'day' => 100
                ];
                break;
            case 'watchtower':
                $baseCost = [
                    'warm' => 100,
                    'cold' => 300,
                    'green' => 100,
                    'day' => 200
                ];
                break;
            case 'workshop':
                $baseCost = [
                    'warm' => 200,
                    'cold' => 200,
                    'green' => 200,
                    'day' => 100
                ];
                break;
        }
        
        // 计算等级加成：费用 = 基础费用 * (等级系数 ^ 等级)
        $upgradeCost = [];
        foreach ($baseCost as $resource => $cost) {
            $upgradeCost[$resource] = floor($cost * pow($levelCoefficient, $this->level));
        }
        
        return $upgradeCost;
    }

    /**
     * 创建新设施
     * @param int $cityId 城池ID
     * @param string $type 设施类型
     * @param string|null $subtype 设施子类型
     * @param int $level 设施等级
     * @param int $xPos 设施X坐标
     * @param int $yPos 设施Y坐标
     * @param string|null $constructionTime 建造完成时间
     * @param string|null $upgradeTime 升级完成时间
     * @return bool|int 成功返回设施ID，失败返回false
     */
    public function createFacility($cityId, $type, $subtype = null, $level = 1, $xPos = 0, $yPos = 0, $constructionTime = null, $upgradeTime = null) {
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

        // 检查设施类型是否有效
        $validTypes = ['resource_production', 'governor_office', 'barracks', 'research_lab', 'dormitory', 'storage', 'watchtower', 'workshop'];
        if (!in_array($type, $validTypes)) {
            return false;
        }

        // 检查设施子类型是否有效
        if ($type == 'resource_production') {
            $validSubtypes = ['bright', 'warm', 'cold', 'green', 'day', 'night'];
            if (!in_array($subtype, $validSubtypes)) {
                return false;
            }
        }

        // 检查位置是否已被占用
        $positionQuery = "SELECT facility_id FROM facilities WHERE city_id = ? AND x_pos = ? AND y_pos = ?";
        $positionStmt = $this->db->prepare($positionQuery);
        $positionStmt->bind_param('iii', $cityId, $xPos, $yPos);
        $positionStmt->execute();
        $positionResult = $positionStmt->get_result();

        if ($positionResult && $positionResult->num_rows > 0) {
            $positionStmt->close();
            return false; // 位置已被占用
        }

        $positionStmt->close();

        // 检查是否已经有同类型的唯一设施
        if (in_array($type, ['governor_office', 'research_lab', 'watchtower', 'workshop'])) {
            $uniqueQuery = "SELECT facility_id FROM facilities WHERE city_id = ? AND type = ?";
            $uniqueStmt = $this->db->prepare($uniqueQuery);
            $uniqueStmt->bind_param('is', $cityId, $type);
            $uniqueStmt->execute();
            $uniqueResult = $uniqueStmt->get_result();

            if ($uniqueResult && $uniqueResult->num_rows > 0) {
                $uniqueStmt->close();
                return false; // 已经有同类型的唯一设施
            }

            $uniqueStmt->close();
        }

        // 插入新设施
        $insertQuery = "INSERT INTO facilities (city_id, type, subtype, level, x_pos, y_pos, construction_time, upgrade_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $this->db->prepare($insertQuery);
        $insertStmt->bind_param('issiiiss', $cityId, $type, $subtype, $level, $xPos, $yPos, $constructionTime, $upgradeTime);
        $result = $insertStmt->execute();

        if ($result) {
            $facilityId = $this->db->insert_id;
            $insertStmt->close();

            // 设置当前对象的属性
            $this->facilityId = $facilityId;
            $this->cityId = $cityId;
            $this->type = $type;
            $this->subtype = $subtype;
            $this->level = $level;
            $this->xPos = $xPos;
            $this->yPos = $yPos;
            $this->constructionTime = $constructionTime;
            $this->upgradeTime = $upgradeTime;
            $this->isValid = true;

            return $facilityId;
        }

        $insertStmt->close();
        return false;
    }

    /**
     * 升级设施
     * @return bool
     */
    public function upgrade() {
        if (!$this->isValid || $this->level >= 10) {
            return false;
        }

        // 检查是否正在建造或升级
        if ($this->isUnderConstruction() || $this->isUpgrading()) {
            return false;
        }

        // 驻城武将的建造加速同样作用于设施升级 / Assigned-general construction speed also accelerates facility upgrades
        $baseUpgradeSeconds = 30 * $this->level;
        $city = new City($this->cityId);
        $upgradeSeconds = $city->isValid()
            ? $city->getAdjustedCityActionDuration($baseUpgradeSeconds, 'build_speed')
            : $baseUpgradeSeconds;
        $upgradeTime = date('Y-m-d H:i:s', time() + $upgradeSeconds);

        // 更新数据库
        $query = "UPDATE facilities
                  SET upgrade_time = ?
                  WHERE facility_id = ?
                    AND construction_time IS NULL
                    AND upgrade_time IS NULL
                    AND level < 10";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('si', $upgradeTime, $this->facilityId);
        $result = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();

        if ($result) {
            $this->upgradeTime = $upgradeTime;
            return true;
        }

        return false;
    }

    /**
     * 原子化完成升级并同步兵种等级 / Complete an upgrade and synchronize unit levels atomically
     * @return bool 是否完成 / Whether the upgrade completed
     */
    public function completeUpgrade() {
        if (!$this->isValid || !$this->upgradeTime) {
            return false;
        }

        $upgradeTime = strtotime($this->upgradeTime);
        $now = time();

        // 检查升级是否已完成
        if ($now < $upgradeTime) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            $query = "SELECT city_id, type, level, upgrade_time
                      FROM facilities
                      WHERE facility_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->facilityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $facility = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$facility
                || $facility['upgrade_time'] === null
                || strtotime($facility['upgrade_time']) > $now
                || (int) $facility['level'] >= 10) {
                $this->db->rollback();
                return false;
            }

            $newLevel = (int) $facility['level'] + 1;
            $completedAt = date('Y-m-d H:i:s', $now);
            $query = "UPDATE facilities
                      SET level = ?, upgrade_time = NULL
                      WHERE facility_id = ?
                        AND upgrade_time IS NOT NULL
                        AND upgrade_time <= ?
                        AND level = ?";
            $stmt = $this->db->prepare($query);
            $currentLevel = (int) $facility['level'];
            $stmt->bind_param(
                'iisi',
                $newLevel,
                $this->facilityId,
                $completedAt,
                $currentLevel
            );
            $completed = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$completed) {
                throw new RuntimeException(
                    '设施升级状态已经变化 / Facility upgrade state changed'
                );
            }

            // 建筑升级会同步提高对应兵种等级 / Facility upgrades synchronize the associated soldier levels
            $soldierTypes = [];
            if ($facility['type'] === 'barracks') {
                $soldierTypes = ['pawn', 'knight', 'rook', 'bishop'];
            } elseif ($facility['type'] === 'workshop') {
                $soldierTypes = ['golem'];
            } elseif ($facility['type'] === 'watchtower') {
                $soldierTypes = ['scout'];
            }

            if (!empty($soldierTypes)) {
                $placeholders = implode(',', array_fill(0, count($soldierTypes), '?'));
                $types = str_repeat('s', count($soldierTypes));
                $query = "UPDATE soldiers
                          SET level = GREATEST(level, ?)
                          WHERE city_id = ? AND type IN ($placeholders)";
                $stmt = $this->db->prepare($query);
                $cityId = (int) $facility['city_id'];
                $parameters = array_merge([$newLevel, $cityId], $soldierTypes);
                $bindTypes = 'ii' . $types;
                $stmt->bind_param($bindTypes, ...$parameters);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException(
                        '同步兵种等级失败 / Failed to synchronize soldier levels'
                    );
                }
                $stmt->close();
            }

            $this->db->commit();
            $this->level = $newLevel;
            $this->upgradeTime = null;
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Facility upgrade completion failed: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * 完成已到期的设施建造 / Complete a facility whose construction timer has elapsed
     * @return bool 是否完成 / Whether construction was completed
     */
    public function completeConstruction() {
        if (!$this->isValid || !$this->constructionTime) {
            return false;
        }

        $completedAt = date('Y-m-d H:i:s');
        if (strtotime($this->constructionTime) > strtotime($completedAt)) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            // 锁定后再核对到期时间，确保并发请求只完成一次 / Recheck the due time under lock so concurrent requests complete once
            $query = "SELECT construction_time
                      FROM facilities
                      WHERE facility_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->facilityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $facility = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$facility
                || $facility['construction_time'] === null
                || strtotime($facility['construction_time']) > strtotime($completedAt)) {
                $this->db->rollback();
                return false;
            }

            $query = "UPDATE facilities
                      SET construction_time = NULL
                      WHERE facility_id = ?
                        AND construction_time IS NOT NULL
                        AND construction_time <= ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('is', $this->facilityId, $completedAt);
            $completed = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$completed) {
                throw new RuntimeException(
                    '设施建造状态已经变化 / Facility construction state changed'
                );
            }

            $this->db->commit();
            $this->constructionTime = null;
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log(
                'Facility construction completion failed: '
                . $exception->getMessage()
            );
            return false;
        }
    }

    /**
     * 获取指定兵种在本设施的训练速率 / Get this facility's training rate for a soldier type
     * @param string $soldierType 兵种类型 / Soldier type
     * @return float 每秒训练数量 / Units trained per second
     */
    public function getSoldierTrainingRate($soldierType) {
        if (!$this->isValid || $this->constructionTime !== null || $this->upgradeTime !== null) {
            return 0;
        }

        $baseTime = 0;
        switch ($soldierType) {
            case 'pawn':
                $baseTime = PAWN_TRAINING_TIME;
                break;
            case 'knight':
                $baseTime = KNIGHT_TRAINING_TIME;
                break;
            case 'rook':
                $baseTime = ROOK_TRAINING_TIME;
                break;
            case 'bishop':
                $baseTime = BISHOP_TRAINING_TIME;
                break;
            case 'golem':
                $baseTime = GOLEM_TRAINING_TIME;
                break;
            case 'scout':
                $baseTime = SCOUT_TRAINING_TIME;
                break;
            default:
                return 0;
        }

        $requiredFacility = Soldier::getTrainingFacilityType($soldierType);
        if ($requiredFacility === null || $this->type !== $requiredFacility || $baseTime <= 0) {
            return 0;
        }

        $levelMultiplier = 1 + (($this->level - 1) * 0.2);
        return $levelMultiplier / $baseTime;
    }

    /**
     * 计算一批士兵的训练时间 / Calculate training time for a soldier batch
     * @param string $soldierType 兵种类型 / Soldier type
     * @param int $quantity 数量 / Quantity
     * @return int 训练秒数 / Training duration in seconds
     */
    public function calculateSoldierTrainingTime($soldierType, $quantity) {
        $quantity = (int) $quantity;
        if ($quantity <= 0) {
            return 0;
        }

        $rate = $this->getSoldierTrainingRate($soldierType);
        if ($rate <= 0) {
            return 0;
        }

        $baseSeconds = $quantity / $rate;
        $city = new City($this->cityId);
        if (!$city->isValid()) {
            return max(1, (int) ceil($baseSeconds));
        }

        // 驻城武将训练速度按倍率缩短整批队列时长 / Assigned-general training speed shortens the whole batch duration
        return $city->getAdjustedCityActionDuration(
            $baseSeconds,
            'training_speed',
            $soldierType
        );
    }

    /**
     * 获取宿舍提供的士兵容量 / Get the soldier capacity provided by a dormitory
     * @return int 士兵容量 / Soldier capacity
     */
    public function getSoldierStorageCapacity() {
        if (!$this->isValid || $this->type !== 'dormitory') {
            return 0;
        }

        return (int) floor($this->getEffectValue());
    }

    /**
     * 计算指定时长内的资源产量 / Calculate resource production for an elapsed duration
     * @param int $seconds 经过的秒数 / Elapsed seconds
     * @param float|null $productionBonus 已汇总的驻城生产百分比 / Pre-aggregated assigned-general production percentage
     * @return float 产出的资源数量 / Produced resource amount
     */
    public function calculateResourceProduction($seconds, $productionBonus = null) {
        if (!$this->isValid || $this->type !== 'resource_production' || $seconds <= 0) {
            return 0.0;
        }

        $productionTicks = intdiv(
            max(0, (int) $seconds),
            max(1, (int) RESOURCE_PRODUCTION_INTERVAL)
        );
        $baseProduction = $productionTicks * $this->getEffectValue();

        if ($productionBonus === null) {
            $city = new City($this->cityId);
            $bonuses = $city->isValid()
                ? $city->getAssignedGeneralCityBonuses([
                    'phase' => 'production'
                ])
                : ['production' => 0];
            $productionBonus = isset($bonuses['production'])
                ? (float) $bonuses['production']
                : 0.0;
            $scopedKey = 'production_' . (string) $this->subtype;
            if (isset($bonuses[$scopedKey])) {
                $productionBonus += (float) $bonuses[$scopedKey];
            }
        }

        // 在累计产量上应用驻城百分比，避免逐 tick 舍入损失 / Apply the city percentage after accumulation to avoid per-tick rounding loss
        return (float) City::applyPercentageBonus(
            $baseProduction,
            $productionBonus
        );
    }

    /**
     * 获取贮存所提供的容量 / Get the capacity supplied by a storage facility
     * @return int 额外容量 / Additional capacity
     */
    public function getResourceStorageCapacity() {
        if (!$this->isValid || $this->type !== 'storage') {
            return 0;
        }

        return (int) floor($this->getEffectValue());
    }

    /**
     * 获取兵营可训练的最高兵种等级 / Get the highest soldier level trainable by this barracks
     * @return int 可训练等级 / Trainable level
     */
    public function getMaxSoldierLevel() {
        if (!$this->isValid || $this->type !== 'barracks') {
            return 0;
        }

        return max(1, (int) $this->level);
    }

    /**
     * 获取城池中的所有设施
     * @param int $cityId 城池ID
     * @return array 设施数组
     */
    public static function getCityFacilities($cityId) {
        $db = Database::getInstance()->getConnection();
        $query = "SELECT facility_id FROM facilities WHERE city_id = ? ORDER BY type, subtype, level DESC";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();

        $facilities = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $facilities[] = new Facility($row['facility_id']);
            }
        }

        $stmt->close();
        return $facilities;
    }

    /**
     * 获取城池中指定类型的设施
     * @param int $cityId 城池ID
     * @param string $type 设施类型
     * @return array 设施数组
     */
    public static function getCityFacilitiesByType($cityId, $type) {
        $db = Database::getInstance()->getConnection();
        $query = "SELECT facility_id FROM facilities WHERE city_id = ? AND type = ? ORDER BY level DESC";
        $stmt = $db->prepare($query);
        $stmt->bind_param('is', $cityId, $type);
        $stmt->execute();
        $result = $stmt->get_result();

        $facilities = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $facilities[] = new Facility($row['facility_id']);
            }
        }

        $stmt->close();
        return $facilities;
    }

    /**
     * 获取城池总士兵容量 / Get a city's total soldier capacity
     * @param int $cityId 城池ID / City ID
     * @return int 总容量 / Total capacity
     */
    public static function getCityTotalSoldierCapacity($cityId) {
        $capacity = 0;
        $dormitories = self::getCityFacilitiesByType($cityId, 'dormitory');

        foreach ($dormitories as $dormitory) {
            if ($dormitory->getConstructionTime() !== null || $dormitory->getUpgradeTime() !== null) {
                continue;
            }

            $capacity += $dormitory->getSoldierStorageCapacity();
        }

        return $capacity;
    }

    /**
     * 检查并完成所有已完成的建造
     * @return array 完成的建造列表
     */
    public static function checkAndCompleteConstruction() {
        $db = Database::getInstance()->getConnection();
        $now = date('Y-m-d H:i:s');

        // 查找所有已完成建造的设施
        $query = "SELECT facility_id FROM facilities WHERE construction_time IS NOT NULL AND construction_time <= ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $now);
        $stmt->execute();
        $result = $stmt->get_result();

        $completedConstructions = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $facility = new Facility($row['facility_id']);
                if ($facility->isValid() && $facility->completeConstruction()) {
                    $completedConstructions[] = [
                        'facility_id' => $facility->getFacilityId(),
                        'name' => $facility->getName(),
                        'type' => $facility->getType(),
                        'level' => $facility->getLevel()
                    ];
                }
            }
        }

        $stmt->close();
        return $completedConstructions;
    }

    /**
     * 检查并完成所有已完成的升级
     * @return array 完成的升级列表
     */
    public static function checkAndCompleteUpgrade() {
        $db = Database::getInstance()->getConnection();
        $now = date('Y-m-d H:i:s');

        // 查找所有已完成升级的设施
        $query = "SELECT facility_id FROM facilities WHERE upgrade_time IS NOT NULL AND upgrade_time <= ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $now);
        $stmt->execute();
        $result = $stmt->get_result();

        $completedUpgrades = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $facility = new Facility($row['facility_id']);
                if ($facility->isValid() && $facility->completeUpgrade()) {
                    $completedUpgrades[] = [
                        'facility_id' => $facility->getFacilityId(),
                        'name' => $facility->getName(),
                        'type' => $facility->getType(),
                        'level' => $facility->getLevel()
                    ];
                }
            }
        }

        $stmt->close();
        return $completedUpgrades;
    }
}

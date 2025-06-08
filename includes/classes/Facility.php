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
                    'bright' => 100,
                    'warm' => 100,
                    'cold' => 100,
                    'green' => 100,
                    'day' => 100,
                    'night' => 0
                ];
                break;
            case 'governor_office':
                $baseCost = [
                    'bright' => 500,
                    'warm' => 200,
                    'cold' => 200,
                    'green' => 200,
                    'day' => 200,
                    'night' => 0
                ];
                break;
            case 'barracks':
                $baseCost = [
                    'bright' => 200,
                    'warm' => 300,
                    'cold' => 100,
                    'green' => 100,
                    'day' => 300,
                    'night' => 0
                ];
                break;
            case 'research_lab':
                $baseCost = [
                    'bright' => 400,
                    'warm' => 100,
                    'cold' => 100,
                    'green' => 300,
                    'day' => 100,
                    'night' => 0
                ];
                break;
            case 'dormitory':
                $baseCost = [
                    'bright' => 150,
                    'warm' => 150,
                    'cold' => 150,
                    'green' => 150,
                    'day' => 150,
                    'night' => 0
                ];
                break;
            case 'storage':
                $baseCost = [
                    'bright' => 200,
                    'warm' => 100,
                    'cold' => 200,
                    'green' => 100,
                    'day' => 100,
                    'night' => 0
                ];
                break;
            case 'watchtower':
                $baseCost = [
                    'bright' => 300,
                    'warm' => 100,
                    'cold' => 300,
                    'green' => 100,
                    'day' => 200,
                    'night' => 0
                ];
                break;
            case 'workshop':
                $baseCost = [
                    'bright' => 400,
                    'warm' => 200,
                    'cold' => 200,
                    'green' => 200,
                    'day' => 100,
                    'night' => 0
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
        $insertStmt->bind_param('issiisss', $cityId, $type, $subtype, $level, $xPos, $yPos, $constructionTime, $upgradeTime);
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
        if (!$this->isValid) {
            return false;
        }

        // 检查是否正在建造或升级
        if ($this->isUnderConstruction() || $this->isUpgrading()) {
            return false;
        }

        // 计算升级时间（基础时间：30秒 * 等级）
        $upgradeTime = date('Y-m-d H:i:s', time() + (30 * $this->level));

        // 更新数据库
        $query = "UPDATE facilities SET upgrade_time = ? WHERE facility_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('si', $upgradeTime, $this->facilityId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->upgradeTime = $upgradeTime;
            return true;
        }

        return false;
    }

    /**
     * 完成升级
     * @return bool
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

        // 升级等级
        $newLevel = $this->level + 1;

        // 更新数据库
        $query = "UPDATE facilities SET level = ?, upgrade_time = NULL WHERE facility_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newLevel, $this->facilityId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->level = $newLevel;
            $this->upgradeTime = null;
            return true;
        }

        return false;
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
                if ($facility->isValid()) {
                    // 完成建造
                    $updateQuery = "UPDATE facilities SET construction_time = NULL WHERE facility_id = ?";
                    $updateStmt = $db->prepare($updateQuery);
                    $updateStmt->bind_param('i', $facility->getFacilityId());
                    $updateStmt->execute();
                    $updateStmt->close();

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

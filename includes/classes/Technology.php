<?php
// 种火集结号 - 科技类

class Technology {
    private $db;
    private $techId;
    private $name;
    private $description;
    private $category;
    private $baseEffect;
    private $baseCost;
    private $levelCoefficient;
    private $maxLevel;
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $techId 科技ID
     */
    public function __construct($techId = null) {
        $this->db = Database::getInstance()->getConnection();
        
        if ($techId !== null) {
            $this->techId = $techId;
            $this->loadTechnologyData();
        }
    }
    
    /**
     * 加载科技数据
     */
    private function loadTechnologyData() {
        $query = "SELECT * FROM technologies WHERE tech_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->techId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $this->name = $data['name'];
            $this->description = $data['description'];
            $this->category = $data['category'];
            $this->baseEffect = $data['base_effect'];
            $this->baseCost = json_decode($data['base_cost'], true);
            $this->levelCoefficient = $data['level_coefficient'];
            $this->maxLevel = $data['max_level'];
            $this->isValid = true;
        }
        
        $stmt->close();
    }
    
    /**
     * 检查科技是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 获取科技ID
     * @return int
     */
    public function getTechId() {
        return $this->techId;
    }
    
    /**
     * 获取科技名称
     * @return string
     */
    public function getName() {
        return $this->name;
    }
    
    /**
     * 获取科技描述
     * @return string
     */
    public function getDescription() {
        return $this->description;
    }
    
    /**
     * 获取科技类别
     * @return string
     */
    public function getCategory() {
        return $this->category;
    }
    
    /**
     * 获取基础效果值
     * @return float
     */
    public function getBaseEffect() {
        return $this->baseEffect;
    }
    
    /**
     * 获取基础费用
     * @return array
     */
    public function getBaseCost() {
        return $this->baseCost;
    }
    
    /**
     * 获取等级系数
     * @return float
     */
    public function getLevelCoefficient() {
        return $this->levelCoefficient;
    }
    
    /**
     * 获取最高等级
     * @return int
     */
    public function getMaxLevel() {
        return $this->maxLevel;
    }
    
    /**
     * 计算指定等级的效果值
     * @param int $level 科技等级
     * @return float
     */
    public function getEffectAtLevel($level) {
        if (!$this->isValid || $level < 1 || $level > $this->maxLevel) {
            return 0;
        }
        
        // 科技效果值 = 基础效果值 * (1 + 科技等级 * 科技等级系数)
        return $this->baseEffect * (1 + $level * $this->levelCoefficient);
    }
    
    /**
     * 计算指定等级的升级费用
     * @param int $level 当前等级
     * @return array
     */
    public function getUpgradeCostAtLevel($level) {
        if (!$this->isValid || $level < 1 || $level >= $this->maxLevel) {
            return [];
        }
        
        $upgradeCost = [];
        // 科技升级费用 = 基础费用 * (1 + 科技等级 * 科技等级系数)
        $multiplier = 1 + $level * $this->levelCoefficient;
        
        foreach ($this->baseCost as $resource => $cost) {
            $upgradeCost[$resource] = floor($cost * $multiplier);
        }
        
        return $upgradeCost;
    }
    
    /**
     * 获取所有科技
     * @return array
     */
    public static function getAllTechnologies() {
        $db = Database::getInstance()->getConnection();
        $query = "SELECT tech_id FROM technologies ORDER BY category, name";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $technologies = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $technologies[] = new Technology($row['tech_id']);
            }
        }
        
        $stmt->close();
        return $technologies;
    }
    
    /**
     * 获取指定类别的科技
     * @param string $category 科技类别
     * @return array
     */
    public static function getTechnologiesByCategory($category) {
        $db = Database::getInstance()->getConnection();
        $query = "SELECT tech_id FROM technologies WHERE category = ? ORDER BY name";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $technologies = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $technologies[] = new Technology($row['tech_id']);
            }
        }
        
        $stmt->close();
        return $technologies;
    }
    
    /**
     * 创建新科技
     * @param string $name 科技名称
     * @param string $description 科技描述
     * @param string $category 科技类别
     * @param float $baseEffect 基础效果值
     * @param array $baseCost 基础费用
     * @param float $levelCoefficient 等级系数
     * @param int $maxLevel 最高等级
     * @return bool|int 成功返回科技ID，失败返回false
     */
    public static function createTechnology($name, $description, $category, $baseEffect, $baseCost, $levelCoefficient, $maxLevel) {
        $db = Database::getInstance()->getConnection();
        
        // 检查科技类别是否有效
        $validCategories = ['resource', 'soldier', 'city', 'governor'];
        if (!in_array($category, $validCategories)) {
            return false;
        }
        
        // 检查科技名称是否已存在
        $checkQuery = "SELECT tech_id FROM technologies WHERE name = ?";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bind_param('s', $name);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult && $checkResult->num_rows > 0) {
            $checkStmt->close();
            return false; // 科技名称已存在
        }
        
        $checkStmt->close();
        
        // 插入新科技
        $baseCostJson = json_encode($baseCost);
        $insertQuery = "INSERT INTO technologies (name, description, category, base_effect, base_cost, level_coefficient, max_level) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bind_param('sssdsdi', $name, $description, $category, $baseEffect, $baseCostJson, $levelCoefficient, $maxLevel);
        $result = $insertStmt->execute();
        
        if ($result) {
            $techId = $db->insert_id;
            $insertStmt->close();
            return $techId;
        }
        
        $insertStmt->close();
        return false;
    }
    
    /**
     * 初始化默认科技
     * @return bool
     */
    public static function initializeDefaultTechnologies() {
        // 资源科技
        $resourceTechs = [
            [
                'name' => '亮晶晶产量提升',
                'description' => '提高亮晶晶资源的产出效率',
                'category' => 'resource',
                'base_effect' => 0.1,
                'base_cost' => ['warm' => 1000, 'cold' => 1000, 'green' => 1000, 'day' => 1000],
                'level_coefficient' => 0.5,
                'max_level' => 10
            ],
            [
                'name' => '暖洋洋产量提升',
                'description' => '提高暖洋洋资源的产出效率',
                'category' => 'resource',
                'base_effect' => 0.1,
                'base_cost' => ['bright' => 1000, 'cold' => 1000, 'green' => 1000, 'day' => 1000],
                'level_coefficient' => 0.5,
                'max_level' => 10
            ],
            [
                'name' => '冷冰冰产量提升',
                'description' => '提高冷冰冰资源的产出效率',
                'category' => 'resource',
                'base_effect' => 0.1,
                'base_cost' => ['bright' => 1000, 'warm' => 1000, 'green' => 1000, 'day' => 1000],
                'level_coefficient' => 0.5,
                'max_level' => 10
            ],
            [
                'name' => '郁萌萌产量提升',
                'description' => '提高郁萌萌资源的产出效率',
                'category' => 'resource',
                'base_effect' => 0.1,
                'base_cost' => ['bright' => 1000, 'warm' => 1000, 'cold' => 1000, 'day' => 1000],
                'level_coefficient' => 0.5,
                'max_level' => 10
            ],
            [
                'name' => '昼闪闪产量提升',
                'description' => '提高昼闪闪资源的产出效率',
                'category' => 'resource',
                'base_effect' => 0.1,
                'base_cost' => ['bright' => 1000, 'warm' => 1000, 'cold' => 1000, 'green' => 1000],
                'level_coefficient' => 0.5,
                'max_level' => 10
            ],
            [
                'name' => '资源存储提升',
                'description' => '提高资源存储上限',
                'category' => 'resource',
                'base_effect' => 0.2,
                'base_cost' => ['bright' => 2000, 'warm' => 500, 'cold' => 500, 'green' => 500, 'day' => 500],
                'level_coefficient' => 0.3,
                'max_level' => 15
            ]
        ];
        
        foreach ($resourceTechs as $tech) {
            self::createTechnology(
                $tech['name'],
                $tech['description'],
                $tech['category'],
                $tech['base_effect'],
                $tech['base_cost'],
                $tech['level_coefficient'],
                $tech['max_level']
            );
        }
        
        return true;
    }
}

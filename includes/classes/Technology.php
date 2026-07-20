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
    private $scope;
    private $effectKey;
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
            $this->scope = isset($data['scope'])
                ? (string) $data['scope']
                : TechnologyEffectService::SCOPE_SEASONAL;
            $this->effectKey = isset($data['effect_key'])
                ? (string) $data['effect_key']
                : '';
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
     * 获取科技范围 / Get the technology scope
     * @return string seasonal 或 permanent / seasonal or permanent
     */
    public function getScope() {
        return $this->scope;
    }

    /**
     * 获取科技效果键 / Get the technology effect key
     * @return string 效果键 / Effect key
     */
    public function getEffectKey() {
        return $this->effectKey;
    }

    /**
     * 检查科技消耗是否符合范围规则 / Check whether the cost obeys scope rules
     * @return bool 是否有效 / Whether the policy is valid
     */
    public function hasValidCostPolicy() {
        return $this->isValid
            && $this->effectKey !== ''
            && TechnologyEffectService::isCostPolicyValid(
                $this->scope,
                is_array($this->baseCost) ? $this->baseCost : []
            );
    }

    /**
     * 判断效果是否为百分比 / Determine whether the effect is percentage-based
     * @return bool 是否为百分比 / Whether the effect is percentage-based
     */
    public function isPercentageEffect() {
        return !in_array(
            $this->effectKey,
            ['circuit_capacity', 'general_cost_capacity', 'subbase_capacity'],
            true
        );
    }

    /**
     * 格式化指定等级的效果 / Format the effect at a level
     * @param int $level 科技等级 / Technology level
     * @return string 玩家可读效果 / Human-readable effect
     */
    public function formatEffectAtLevel($level) {
        $effect = $this->getEffectAtLevel($level);
        if ($this->isPercentageEffect()) {
            return '+' . number_format($effect * 100, 1) . '%';
        }
        if ($this->effectKey === 'general_cost_capacity') {
            return '+' . number_format($effect, 1) . ' COST';
        }
        if ($this->effectKey === 'circuit_capacity') {
            return '+' . number_format((int) floor($effect)) . ' 思考回路上限';
        }
        return '+' . number_format((int) floor($effect)) . ' 分基地上限';
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
        
        // 每一级提供一份基础效果，便于通过数据表直接调平 / Each level grants one base-effect unit for data-driven tuning
        return TechnologyEffectService::calculateEffectAtLevel(
            $this->baseEffect,
            $level
        );
    }
    
    /**
     * 计算指定等级的升级费用
     * @param int $level 当前等级
     * @return array
     */
    public function getUpgradeCostAtLevel($level) {
        if (!$this->isValid || $level < 0 || $level >= $this->maxLevel) {
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
     * @param string $scope 科技范围 / Technology scope
     * @param string $effectKey 效果键 / Effect key
     * @return bool|int 成功返回科技ID，失败返回false
     */
    public static function createTechnology(
        $name,
        $description,
        $category,
        $baseEffect,
        $baseCost,
        $levelCoefficient,
        $maxLevel,
        $scope = TechnologyEffectService::SCOPE_SEASONAL,
        $effectKey = ''
    ) {
        $db = Database::getInstance()->getConnection();
        
        // 检查科技类别是否有效
        $validCategories = ['resource', 'soldier', 'city', 'governor'];
        if (!in_array($category, $validCategories)) {
            return false;
        }
        if ($effectKey === ''
            || !TechnologyEffectService::isCostPolicyValid($scope, $baseCost)) {
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
        $insertQuery = "INSERT INTO technologies
                          (name, description, category, base_effect, base_cost,
                           level_coefficient, max_level, scope, effect_key)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bind_param(
            'sssdsdiss',
            $name,
            $description,
            $category,
            $baseEffect,
            $baseCostJson,
            $levelCoefficient,
            $maxLevel,
            $scope,
            $effectKey
        );
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
        // 科技定义使用统一效果键，数值保持保守并可直接通过数据表调整 / Definitions use shared effect keys with conservative database-tunable values
        $technologies = [
            [
                'name' => '暖洋洋产量提升',
                'description' => '本赛季提高暖洋洋资源的产出效率',
                'category' => 'resource',
                'base_effect' => 0.05,
                'base_cost' => ['warm' => 1000, 'cold' => 1000, 'green' => 1000, 'day' => 1000],
                'level_coefficient' => 0.5,
                'max_level' => 10,
                'scope' => 'seasonal',
                'effect_key' => 'resource_production_warm'
            ],
            [
                'name' => '冷冰冰产量提升',
                'description' => '本赛季提高冷冰冰资源的产出效率',
                'category' => 'resource',
                'base_effect' => 0.05,
                'base_cost' => ['warm' => 1000, 'cold' => 1000, 'green' => 1000, 'day' => 1000],
                'level_coefficient' => 0.5,
                'max_level' => 10,
                'scope' => 'seasonal',
                'effect_key' => 'resource_production_cold'
            ],
            [
                'name' => '郁萌萌产量提升',
                'description' => '本赛季提高郁萌萌资源的产出效率',
                'category' => 'resource',
                'base_effect' => 0.05,
                'base_cost' => ['warm' => 1000, 'cold' => 1000, 'green' => 1000, 'day' => 1000],
                'level_coefficient' => 0.5,
                'max_level' => 10,
                'scope' => 'seasonal',
                'effect_key' => 'resource_production_green'
            ],
            [
                'name' => '昼闪闪产量提升',
                'description' => '本赛季提高昼闪闪资源的产出效率',
                'category' => 'resource',
                'base_effect' => 0.05,
                'base_cost' => ['warm' => 1000, 'cold' => 1000, 'green' => 1000, 'day' => 1000],
                'level_coefficient' => 0.5,
                'max_level' => 10,
                'scope' => 'seasonal',
                'effect_key' => 'resource_production_day'
            ],
            [
                'name' => '资源存储提升',
                'description' => '本赛季提高四种赛季资源的存储上限',
                'category' => 'resource',
                'base_effect' => 0.10,
                'base_cost' => ['warm' => 1000, 'cold' => 1000, 'green' => 1000, 'day' => 1000],
                'level_coefficient' => 0.3,
                'max_level' => 10,
                'scope' => 'seasonal',
                'effect_key' => 'resource_storage'
            ],
            [
                'name' => '训练调度',
                'description' => '本赛季缩短士兵训练时间',
                'category' => 'soldier',
                'base_effect' => 0.03,
                'base_cost' => ['warm' => 1200, 'cold' => 1200, 'green' => 1200, 'day' => 1200],
                'level_coefficient' => 0.5,
                'max_level' => 10,
                'scope' => 'seasonal',
                'effect_key' => 'training_speed'
            ],
            [
                'name' => '军势演算',
                'description' => '本赛季提高士兵攻击力',
                'category' => 'soldier',
                'base_effect' => 0.03,
                'base_cost' => ['warm' => 1500, 'cold' => 1500, 'green' => 1500, 'day' => 1500],
                'level_coefficient' => 0.5,
                'max_level' => 10,
                'scope' => 'seasonal',
                'effect_key' => 'soldier_attack'
            ],
            [
                'name' => '防阵演算',
                'description' => '本赛季提高士兵防御力',
                'category' => 'soldier',
                'base_effect' => 0.03,
                'base_cost' => ['warm' => 1500, 'cold' => 1500, 'green' => 1500, 'day' => 1500],
                'level_coefficient' => 0.5,
                'max_level' => 10,
                'scope' => 'seasonal',
                'effect_key' => 'soldier_defense'
            ],
            [
                'name' => '城防工学',
                'description' => '本赛季提高城池综合防御力',
                'category' => 'city',
                'base_effect' => 0.04,
                'base_cost' => ['warm' => 1500, 'cold' => 1500, 'green' => 1500, 'day' => 1500],
                'level_coefficient' => 0.5,
                'max_level' => 10,
                'scope' => 'seasonal',
                'effect_key' => 'city_defense'
            ],
            [
                'name' => '永久建筑统筹',
                'description' => '跨赛季永久缩短建造与设施升级时间',
                'category' => 'governor',
                'base_effect' => 0.01,
                'base_cost' => ['bright' => 2000, 'night' => 500],
                'level_coefficient' => 0.75,
                'max_level' => 10,
                'scope' => 'permanent',
                'effect_key' => 'build_speed'
            ],
            [
                'name' => '永久回路扩容',
                'description' => '跨赛季永久提高思考回路持有上限',
                'category' => 'governor',
                'base_effect' => 1.0,
                'base_cost' => ['bright' => 2500, 'night' => 750],
                'level_coefficient' => 0.75,
                'max_level' => 10,
                'scope' => 'permanent',
                'effect_key' => 'circuit_capacity'
            ],
            [
                'name' => '永久编制扩张',
                'description' => '跨赛季永久提高武将编制COST上限',
                'category' => 'governor',
                'base_effect' => 0.5,
                'base_cost' => ['bright' => 3000, 'night' => 1000],
                'level_coefficient' => 0.75,
                'max_level' => 10,
                'scope' => 'permanent',
                'effect_key' => 'general_cost_capacity'
            ],
            [
                'name' => '永久据点许可',
                'description' => '跨赛季永久提高分基地数量上限',
                'category' => 'governor',
                'base_effect' => 1.0,
                'base_cost' => ['bright' => 5000, 'night' => 1500],
                'level_coefficient' => 1.0,
                'max_level' => 5,
                'scope' => 'permanent',
                'effect_key' => 'subbase_capacity'
            ]
        ];
        
        foreach ($technologies as $technology) {
            if (!self::upsertTechnologyDefinition($technology)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * 写入或更新默认科技定义 / Insert or update a default technology definition
     * @param array $technology 科技定义 / Technology definition
     * @return bool 是否成功 / Whether the write succeeded
     */
    private static function upsertTechnologyDefinition(array $technology) {
        if (!TechnologyEffectService::isCostPolicyValid(
            $technology['scope'],
            $technology['base_cost']
        )) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $baseCostJson = json_encode(
            $technology['base_cost'],
            JSON_UNESCAPED_UNICODE
        );
        if ($baseCostJson === false) {
            return false;
        }

        $query = "INSERT INTO technologies
                     (name, description, category, base_effect, base_cost,
                      level_coefficient, max_level, scope, effect_key)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    category = VALUES(category),
                    base_effect = VALUES(base_effect),
                    base_cost = VALUES(base_cost),
                    level_coefficient = VALUES(level_coefficient),
                    max_level = VALUES(max_level),
                    scope = VALUES(scope),
                    effect_key = VALUES(effect_key)";
        $stmt = $db->prepare($query);
        $name = (string) $technology['name'];
        $description = (string) $technology['description'];
        $category = (string) $technology['category'];
        $baseEffect = (float) $technology['base_effect'];
        $levelCoefficient = (float) $technology['level_coefficient'];
        $maxLevel = (int) $technology['max_level'];
        $scope = (string) $technology['scope'];
        $effectKey = (string) $technology['effect_key'];
        $stmt->bind_param(
            'sssdsdiss',
            $name,
            $description,
            $category,
            $baseEffect,
            $baseCostJson,
            $levelCoefficient,
            $maxLevel,
            $scope,
            $effectKey
        );
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }
}

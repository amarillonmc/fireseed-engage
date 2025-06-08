<?php
// 种火集结号 - 用户科技类

class UserTechnology {
    private $db;
    private $userId;
    private $techId;
    private $level;
    private $researchTime;
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $userId 用户ID
     * @param int $techId 科技ID
     */
    public function __construct($userId, $techId) {
        $this->db = Database::getInstance()->getConnection();
        $this->userId = $userId;
        $this->techId = $techId;
        $this->loadUserTechnologyData();
    }
    
    /**
     * 加载用户科技数据
     */
    private function loadUserTechnologyData() {
        $query = "SELECT * FROM user_technologies WHERE user_id = ? AND tech_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $this->userId, $this->techId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $this->level = $data['level'];
            $this->researchTime = $data['research_time'];
            $this->isValid = true;
        } else {
            // 如果没有记录，创建一个等级为0的记录
            $this->level = 0;
            $this->researchTime = null;
            $this->isValid = false;
        }
        
        $stmt->close();
    }
    
    /**
     * 检查用户科技是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 获取用户ID
     * @return int
     */
    public function getUserId() {
        return $this->userId;
    }
    
    /**
     * 获取科技ID
     * @return int
     */
    public function getTechId() {
        return $this->techId;
    }
    
    /**
     * 获取科技等级
     * @return int
     */
    public function getLevel() {
        return $this->level;
    }
    
    /**
     * 获取研究完成时间
     * @return string|null
     */
    public function getResearchTime() {
        return $this->researchTime;
    }
    
    /**
     * 检查是否正在研究
     * @return bool
     */
    public function isResearching() {
        if (!$this->researchTime) {
            return false;
        }
        
        $researchTime = strtotime($this->researchTime);
        $now = time();
        
        return $now < $researchTime;
    }
    
    /**
     * 开始研究科技
     * @param int $cityId 城池ID（用于检查研究所等级）
     * @return bool
     */
    public function startResearch($cityId) {
        // 检查是否已经在研究
        if ($this->isResearching()) {
            return false;
        }
        
        // 获取科技信息
        $technology = new Technology($this->techId);
        if (!$technology->isValid()) {
            return false;
        }
        
        // 检查是否已达到最高等级
        if ($this->level >= $technology->getMaxLevel()) {
            return false;
        }
        
        // 检查研究所等级是否足够
        $researchLabs = Facility::getCityFacilitiesByType($cityId, 'research_lab');
        if (empty($researchLabs)) {
            return false; // 没有研究所
        }
        
        $researchLabLevel = $researchLabs[0]->getLevel();
        $requiredLevel = $this->level + 1;
        
        if ($researchLabLevel < $requiredLevel) {
            return false; // 研究所等级不够
        }
        
        // 检查资源是否足够
        $upgradeCost = $technology->getUpgradeCostAtLevel($this->level);
        $resource = new Resource($this->userId);
        
        if (!$resource->isValid()) {
            return false;
        }
        
        foreach ($upgradeCost as $resourceType => $cost) {
            if ($resource->getResourceByType($resourceType) < $cost) {
                return false; // 资源不足
            }
        }
        
        // 扣除资源
        foreach ($upgradeCost as $resourceType => $cost) {
            $resource->subtractResourceByType($resourceType, $cost);
        }
        
        // 计算研究时间（基础时间：60秒 * (等级+1)）
        $researchDuration = 60 * ($this->level + 1);
        $researchTime = date('Y-m-d H:i:s', time() + $researchDuration);
        
        // 更新或插入记录
        if ($this->isValid) {
            $query = "UPDATE user_technologies SET research_time = ? WHERE user_id = ? AND tech_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('sii', $researchTime, $this->userId, $this->techId);
        } else {
            $query = "INSERT INTO user_technologies (user_id, tech_id, level, research_time) VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iiis', $this->userId, $this->techId, $this->level, $researchTime);
        }
        
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->researchTime = $researchTime;
            $this->isValid = true;
            return true;
        }
        
        return false;
    }
    
    /**
     * 完成研究
     * @return bool
     */
    public function completeResearch() {
        if (!$this->researchTime) {
            return false;
        }
        
        $researchTime = strtotime($this->researchTime);
        $now = time();
        
        // 检查研究是否已完成
        if ($now < $researchTime) {
            return false;
        }
        
        // 升级等级
        $newLevel = $this->level + 1;
        
        // 更新数据库
        $query = "UPDATE user_technologies SET level = ?, research_time = NULL WHERE user_id = ? AND tech_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iii', $newLevel, $this->userId, $this->techId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->level = $newLevel;
            $this->researchTime = null;
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取用户的所有科技
     * @param int $userId 用户ID
     * @return array
     */
    public static function getUserTechnologies($userId) {
        $db = Database::getInstance()->getConnection();
        
        // 获取所有科技
        $technologies = Technology::getAllTechnologies();
        $userTechnologies = [];
        
        foreach ($technologies as $technology) {
            $userTech = new UserTechnology($userId, $technology->getTechId());
            $userTechnologies[] = [
                'technology' => $technology,
                'user_tech' => $userTech
            ];
        }
        
        return $userTechnologies;
    }
    
    /**
     * 获取用户指定类别的科技
     * @param int $userId 用户ID
     * @param string $category 科技类别
     * @return array
     */
    public static function getUserTechnologiesByCategory($userId, $category) {
        $technologies = Technology::getTechnologiesByCategory($category);
        $userTechnologies = [];
        
        foreach ($technologies as $technology) {
            $userTech = new UserTechnology($userId, $technology->getTechId());
            $userTechnologies[] = [
                'technology' => $technology,
                'user_tech' => $userTech
            ];
        }
        
        return $userTechnologies;
    }
    
    /**
     * 检查并完成所有已完成的研究
     * @return array 完成的研究列表
     */
    public static function checkAndCompleteResearch() {
        $db = Database::getInstance()->getConnection();
        $now = date('Y-m-d H:i:s');
        
        // 查找所有已完成研究的科技
        $query = "SELECT user_id, tech_id FROM user_technologies WHERE research_time IS NOT NULL AND research_time <= ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $now);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $completedResearch = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $userTech = new UserTechnology($row['user_id'], $row['tech_id']);
                if ($userTech->completeResearch()) {
                    $technology = new Technology($row['tech_id']);
                    $completedResearch[] = [
                        'user_id' => $row['user_id'],
                        'tech_id' => $row['tech_id'],
                        'tech_name' => $technology->getName(),
                        'level' => $userTech->getLevel()
                    ];
                }
            }
        }
        
        $stmt->close();
        return $completedResearch;
    }
    
    /**
     * 获取用户科技的效果加成
     * @param int $userId 用户ID
     * @param string $category 科技类别
     * @return array 效果加成数组
     */
    public static function getUserTechnologyEffects($userId, $category = null) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT ut.tech_id, ut.level, t.name, t.category, t.base_effect, t.level_coefficient 
                  FROM user_technologies ut 
                  JOIN technologies t ON ut.tech_id = t.tech_id 
                  WHERE ut.user_id = ? AND ut.level > 0";
        
        $params = [$userId];
        $types = 'i';
        
        if ($category) {
            $query .= " AND t.category = ?";
            $params[] = $category;
            $types .= 's';
        }
        
        $stmt = $db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $effects = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $effectValue = $row['base_effect'] * (1 + $row['level'] * $row['level_coefficient']);
                $effects[] = [
                    'tech_id' => $row['tech_id'],
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'level' => $row['level'],
                    'effect_value' => $effectValue
                ];
            }
        }
        
        $stmt->close();
        return $effects;
    }
}

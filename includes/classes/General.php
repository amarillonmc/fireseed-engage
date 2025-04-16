<?php
// 种火集结号 - 武将类

class General {
    private $db;
    private $generalId;
    private $ownerId;
    private $name;
    private $rarity;
    private $level;
    private $experience;
    private $leadership;
    private $strength;
    private $intelligence;
    private $politics;
    private $charm;
    private $leadershipGrowth;
    private $strengthGrowth;
    private $intelligenceGrowth;
    private $politicsGrowth;
    private $charmGrowth;
    private $skillPoints;
    private $isActive;
    private $createdAt;
    private $skills = [];
    private $assignment = null;
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $generalId 武将ID
     */
    public function __construct($generalId = null) {
        $this->db = Database::getInstance()->getConnection();
        
        if ($generalId !== null) {
            $this->generalId = $generalId;
            $this->loadGeneralData();
        }
    }
    
    /**
     * 加载武将数据
     */
    private function loadGeneralData() {
        $query = "SELECT * FROM generals WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->generalId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $generalData = $result->fetch_assoc();
            $this->ownerId = $generalData['owner_id'];
            $this->name = $generalData['name'];
            $this->rarity = $generalData['rarity'];
            $this->level = $generalData['level'];
            $this->experience = $generalData['experience'];
            $this->leadership = $generalData['leadership'];
            $this->strength = $generalData['strength'];
            $this->intelligence = $generalData['intelligence'];
            $this->politics = $generalData['politics'];
            $this->charm = $generalData['charm'];
            $this->leadershipGrowth = $generalData['leadership_growth'];
            $this->strengthGrowth = $generalData['strength_growth'];
            $this->intelligenceGrowth = $generalData['intelligence_growth'];
            $this->politicsGrowth = $generalData['politics_growth'];
            $this->charmGrowth = $generalData['charm_growth'];
            $this->skillPoints = $generalData['skill_points'];
            $this->isActive = $generalData['is_active'];
            $this->createdAt = $generalData['created_at'];
            $this->isValid = true;
            
            // 加载武将技能
            $this->loadGeneralSkills();
            
            // 加载武将分配信息
            $this->loadGeneralAssignment();
        }
        
        $stmt->close();
    }
    
    /**
     * 加载武将技能
     */
    private function loadGeneralSkills() {
        $query = "SELECT * FROM general_skills WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->generalId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $skill = new GeneralSkill($row['skill_id']);
                if ($skill->isValid()) {
                    $this->skills[] = $skill;
                }
            }
        }
        
        $stmt->close();
    }
    
    /**
     * 加载武将分配信息
     */
    private function loadGeneralAssignment() {
        $query = "SELECT * FROM general_assignments WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->generalId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $this->assignment = new GeneralAssignment($row['assignment_id']);
        }
        
        $stmt->close();
    }
    
    /**
     * 创建新武将
     * @param int $ownerId 拥有者ID
     * @param string $name 武将名称
     * @param string $rarity 武将稀有度
     * @param array $attributes 武将属性
     * @return bool|int 成功返回武将ID，失败返回false
     */
    public function createGeneral($ownerId, $name, $rarity, $attributes) {
        // 检查参数
        if (empty($name) || empty($rarity) || empty($attributes)) {
            return false;
        }
        
        // 检查稀有度是否有效
        $validRarities = ['common', 'uncommon', 'rare', 'epic', 'legendary'];
        if (!in_array($rarity, $validRarities)) {
            return false;
        }
        
        // 检查属性是否完整
        $requiredAttributes = ['leadership', 'strength', 'intelligence', 'politics', 'charm', 
                              'leadership_growth', 'strength_growth', 'intelligence_growth', 
                              'politics_growth', 'charm_growth'];
        foreach ($requiredAttributes as $attr) {
            if (!isset($attributes[$attr])) {
                return false;
            }
        }
        
        // 创建武将记录
        $query = "INSERT INTO generals (owner_id, name, rarity, leadership, strength, intelligence, politics, charm, 
                 leadership_growth, strength_growth, intelligence_growth, politics_growth, charm_growth) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('issiiiiddddd', $ownerId, $name, $rarity, 
                         $attributes['leadership'], $attributes['strength'], $attributes['intelligence'], 
                         $attributes['politics'], $attributes['charm'], 
                         $attributes['leadership_growth'], $attributes['strength_growth'], 
                         $attributes['intelligence_growth'], $attributes['politics_growth'], 
                         $attributes['charm_growth']);
        $result = $stmt->execute();
        
        if (!$result) {
            $stmt->close();
            return false;
        }
        
        $generalId = $this->db->insert_id;
        $stmt->close();
        
        // 设置对象属性
        $this->generalId = $generalId;
        $this->ownerId = $ownerId;
        $this->name = $name;
        $this->rarity = $rarity;
        $this->level = 1;
        $this->experience = 0;
        $this->leadership = $attributes['leadership'];
        $this->strength = $attributes['strength'];
        $this->intelligence = $attributes['intelligence'];
        $this->politics = $attributes['politics'];
        $this->charm = $attributes['charm'];
        $this->leadershipGrowth = $attributes['leadership_growth'];
        $this->strengthGrowth = $attributes['strength_growth'];
        $this->intelligenceGrowth = $attributes['intelligence_growth'];
        $this->politicsGrowth = $attributes['politics_growth'];
        $this->charmGrowth = $attributes['charm_growth'];
        $this->skillPoints = 0;
        $this->isActive = 1;
        $this->createdAt = date('Y-m-d H:i:s');
        $this->isValid = true;
        
        return $generalId;
    }
    
    /**
     * 升级武将
     * @return bool 是否成功
     */
    public function levelUp() {
        if (!$this->isValid) {
            return false;
        }
        
        // 计算升级所需经验
        $requiredExp = $this->getRequiredExperience();
        
        // 检查经验是否足够
        if ($this->experience < $requiredExp) {
            return false;
        }
        
        // 减少经验值
        $this->experience -= $requiredExp;
        
        // 增加等级
        $newLevel = $this->level + 1;
        
        // 计算属性增长
        $newLeadership = $this->leadership + $this->calculateAttributeGrowth($this->leadershipGrowth);
        $newStrength = $this->strength + $this->calculateAttributeGrowth($this->strengthGrowth);
        $newIntelligence = $this->intelligence + $this->calculateAttributeGrowth($this->intelligenceGrowth);
        $newPolitics = $this->politics + $this->calculateAttributeGrowth($this->politicsGrowth);
        $newCharm = $this->charm + $this->calculateAttributeGrowth($this->charmGrowth);
        
        // 增加技能点
        $newSkillPoints = $this->skillPoints + $this->calculateSkillPointsGain();
        
        // 更新数据库
        $query = "UPDATE generals SET level = ?, experience = ?, leadership = ?, strength = ?, 
                 intelligence = ?, politics = ?, charm = ?, skill_points = ? 
                 WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iiiiiiiii', $newLevel, $this->experience, $newLeadership, $newStrength, 
                         $newIntelligence, $newPolitics, $newCharm, $newSkillPoints, $this->generalId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            // 更新对象属性
            $this->level = $newLevel;
            $this->leadership = $newLeadership;
            $this->strength = $newStrength;
            $this->intelligence = $newIntelligence;
            $this->politics = $newPolitics;
            $this->charm = $newCharm;
            $this->skillPoints = $newSkillPoints;
            return true;
        }
        
        return false;
    }
    
    /**
     * 计算属性增长
     * @param float $growthRate 成长率
     * @return int 属性增长值
     */
    private function calculateAttributeGrowth($growthRate) {
        // 基础增长
        $baseGrowth = floor($growthRate);
        
        // 随机增长
        $randomGrowth = 0;
        $randomChance = ($growthRate - $baseGrowth) * 100;
        if (mt_rand(1, 100) <= $randomChance) {
            $randomGrowth = 1;
        }
        
        return $baseGrowth + $randomGrowth;
    }
    
    /**
     * 计算技能点获得
     * @return int 技能点数量
     */
    private function calculateSkillPointsGain() {
        // 根据稀有度和等级计算技能点
        $rarityMultiplier = 1;
        switch ($this->rarity) {
            case 'legendary':
                $rarityMultiplier = 3;
                break;
            case 'epic':
                $rarityMultiplier = 2;
                break;
            case 'rare':
                $rarityMultiplier = 1.5;
                break;
            case 'uncommon':
                $rarityMultiplier = 1.2;
                break;
            default:
                $rarityMultiplier = 1;
        }
        
        // 基础技能点 + 等级加成 + 稀有度加成
        return 1 + floor($this->level / 5) + floor($rarityMultiplier);
    }
    
    /**
     * 获取升级所需经验
     * @return int 所需经验值
     */
    public function getRequiredExperience() {
        return 100 * $this->level * (1 + $this->level * 0.1);
    }
    
    /**
     * 添加经验值
     * @param int $amount 经验值数量
     * @return bool 是否成功
     */
    public function addExperience($amount) {
        if (!$this->isValid || $amount <= 0) {
            return false;
        }
        
        // 增加经验值
        $newExperience = $this->experience + $amount;
        
        // 更新数据库
        $query = "UPDATE generals SET experience = ? WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newExperience, $this->generalId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            // 更新对象属性
            $this->experience = $newExperience;
            
            // 检查是否可以升级
            $levelsGained = 0;
            while ($this->experience >= $this->getRequiredExperience()) {
                if ($this->levelUp()) {
                    $levelsGained++;
                } else {
                    break;
                }
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 学习技能
     * @param string $skillType 技能类型
     * @param string $skillName 技能名称
     * @param string $skillEffect 技能效果
     * @return bool 是否成功
     */
    public function learnSkill($skillType, $skillName, $skillEffect) {
        if (!$this->isValid || $this->skillPoints <= 0) {
            return false;
        }
        
        // 检查是否已有该技能
        foreach ($this->skills as $skill) {
            if ($skill->getSkillName() == $skillName) {
                return false;
            }
        }
        
        // 创建新技能
        $skill = new GeneralSkill();
        $skillId = $skill->createSkill($this->generalId, $skillType, $skillName, $skillEffect);
        
        if (!$skillId) {
            return false;
        }
        
        // 减少技能点
        $newSkillPoints = $this->skillPoints - 1;
        
        // 更新数据库
        $query = "UPDATE generals SET skill_points = ? WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newSkillPoints, $this->generalId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            // 更新对象属性
            $this->skillPoints = $newSkillPoints;
            $this->skills[] = $skill;
            return true;
        }
        
        return false;
    }
    
    /**
     * 升级技能
     * @param int $skillId 技能ID
     * @return bool 是否成功
     */
    public function upgradeSkill($skillId) {
        if (!$this->isValid || $this->skillPoints <= 0) {
            return false;
        }
        
        // 查找技能
        $targetSkill = null;
        foreach ($this->skills as $skill) {
            if ($skill->getSkillId() == $skillId) {
                $targetSkill = $skill;
                break;
            }
        }
        
        if (!$targetSkill) {
            return false;
        }
        
        // 检查技能等级是否已达最高
        if ($targetSkill->getSkillLevel() >= 5) {
            return false;
        }
        
        // 升级技能
        if (!$targetSkill->upgradeSkill()) {
            return false;
        }
        
        // 减少技能点
        $newSkillPoints = $this->skillPoints - 1;
        
        // 更新数据库
        $query = "UPDATE generals SET skill_points = ? WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newSkillPoints, $this->generalId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            // 更新对象属性
            $this->skillPoints = $newSkillPoints;
            return true;
        }
        
        return false;
    }
    
    /**
     * 分配武将
     * @param string $assignmentType 分配类型（city, army）
     * @param int $targetId 目标ID
     * @return bool 是否成功
     */
    public function assignGeneral($assignmentType, $targetId) {
        if (!$this->isValid) {
            return false;
        }
        
        // 检查分配类型是否有效
        $validTypes = ['city', 'army'];
        if (!in_array($assignmentType, $validTypes)) {
            return false;
        }
        
        // 检查目标是否存在
        if ($assignmentType == 'city') {
            $city = new City($targetId);
            if (!$city->isValid() || $city->getOwnerId() != $this->ownerId) {
                return false;
            }
            
            // 检查城池是否已达武将上限
            $cityGenerals = self::getCityGenerals($targetId);
            if (count($cityGenerals) >= $city->getLevel()) {
                return false;
            }
        } else if ($assignmentType == 'army') {
            $army = new Army($targetId);
            if (!$army->isValid() || $army->getOwnerId() != $this->ownerId) {
                return false;
            }
            
            // 检查军队是否已达武将上限
            $armyGenerals = self::getArmyGenerals($targetId);
            if (count($armyGenerals) >= 1 + $army->getLevel()) {
                return false;
            }
        }
        
        // 如果武将已分配，先取消分配
        if ($this->assignment) {
            $this->unassignGeneral();
        }
        
        // 创建新分配
        $assignment = new GeneralAssignment();
        $assignmentId = $assignment->createAssignment($this->generalId, $assignmentType, $targetId);
        
        if ($assignmentId) {
            $this->assignment = $assignment;
            return true;
        }
        
        return false;
    }
    
    /**
     * 取消分配
     * @return bool 是否成功
     */
    public function unassignGeneral() {
        if (!$this->isValid || !$this->assignment) {
            return false;
        }
        
        if ($this->assignment->cancelAssignment()) {
            $this->assignment = null;
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取武将加成
     * @param string $type 加成类型
     * @return array 加成数组
     */
    public function getBonus($type) {
        if (!$this->isValid) {
            return [];
        }
        
        $bonus = [];
        
        // 基础属性加成
        switch ($type) {
            case 'city':
                // 城池加成
                $bonus['defense'] = $this->intelligence * 0.5 + $this->politics * 0.3;
                $bonus['production'] = $this->politics * 0.5 + $this->charm * 0.3;
                break;
            case 'army':
                // 军队加成
                $bonus['attack'] = $this->strength * 0.5 + $this->leadership * 0.3;
                $bonus['defense'] = $this->intelligence * 0.3 + $this->leadership * 0.2;
                $bonus['speed'] = $this->strength * 0.2 + $this->charm * 0.1;
                break;
        }
        
        // 技能加成
        foreach ($this->skills as $skill) {
            if ($skill->getSkillType() == $type) {
                $skillEffect = $skill->getEffect();
                foreach ($skillEffect as $effectType => $effectValue) {
                    if (isset($bonus[$effectType])) {
                        $bonus[$effectType] += $effectValue;
                    } else {
                        $bonus[$effectType] = $effectValue;
                    }
                }
            }
        }
        
        return $bonus;
    }
    
    /**
     * 获取武将ID
     * @return int
     */
    public function getGeneralId() {
        return $this->generalId;
    }
    
    /**
     * 获取拥有者ID
     * @return int
     */
    public function getOwnerId() {
        return $this->ownerId;
    }
    
    /**
     * 获取武将名称
     * @return string
     */
    public function getName() {
        return $this->name;
    }
    
    /**
     * 获取武将稀有度
     * @return string
     */
    public function getRarity() {
        return $this->rarity;
    }
    
    /**
     * 获取武将等级
     * @return int
     */
    public function getLevel() {
        return $this->level;
    }
    
    /**
     * 获取武将经验值
     * @return int
     */
    public function getExperience() {
        return $this->experience;
    }
    
    /**
     * 获取武将统帅值
     * @return int
     */
    public function getLeadership() {
        return $this->leadership;
    }
    
    /**
     * 获取武将武力值
     * @return int
     */
    public function getStrength() {
        return $this->strength;
    }
    
    /**
     * 获取武将智力值
     * @return int
     */
    public function getIntelligence() {
        return $this->intelligence;
    }
    
    /**
     * 获取武将政治值
     * @return int
     */
    public function getPolitics() {
        return $this->politics;
    }
    
    /**
     * 获取武将魅力值
     * @return int
     */
    public function getCharm() {
        return $this->charm;
    }
    
    /**
     * 获取武将技能点
     * @return int
     */
    public function getSkillPoints() {
        return $this->skillPoints;
    }
    
    /**
     * 获取武将技能
     * @return array
     */
    public function getSkills() {
        return $this->skills;
    }
    
    /**
     * 获取武将分配信息
     * @return GeneralAssignment|null
     */
    public function getAssignment() {
        return $this->assignment;
    }
    
    /**
     * 检查武将是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 获取用户的所有武将
     * @param int $userId 用户ID
     * @return array 武将数组
     */
    public static function getUserGenerals($userId) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT general_id FROM generals WHERE owner_id = ? AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $generals = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $general = new General($row['general_id']);
                if ($general->isValid()) {
                    $generals[] = $general;
                }
            }
        }
        
        $stmt->close();
        return $generals;
    }
    
    /**
     * 获取城池的所有武将
     * @param int $cityId 城池ID
     * @return array 武将数组
     */
    public static function getCityGenerals($cityId) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT g.general_id FROM generals g
                  JOIN general_assignments a ON g.general_id = a.general_id
                  WHERE a.assignment_type = 'city' AND a.target_id = ? AND g.is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $generals = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $general = new General($row['general_id']);
                if ($general->isValid()) {
                    $generals[] = $general;
                }
            }
        }
        
        $stmt->close();
        return $generals;
    }
    
    /**
     * 获取军队的所有武将
     * @param int $armyId 军队ID
     * @return array 武将数组
     */
    public static function getArmyGenerals($armyId) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT g.general_id FROM generals g
                  JOIN general_assignments a ON g.general_id = a.general_id
                  WHERE a.assignment_type = 'army' AND a.target_id = ? AND g.is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $armyId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $generals = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $general = new General($row['general_id']);
                if ($general->isValid()) {
                    $generals[] = $general;
                }
            }
        }
        
        $stmt->close();
        return $generals;
    }
    
    /**
     * 随机生成武将
     * @param int $ownerId 拥有者ID
     * @param string $rarity 稀有度
     * @return bool|int 成功返回武将ID，失败返回false
     */
    public static function generateRandomGeneral($ownerId, $rarity = 'common') {
        // 检查稀有度是否有效
        $validRarities = ['common', 'uncommon', 'rare', 'epic', 'legendary'];
        if (!in_array($rarity, $validRarities)) {
            $rarity = 'common';
        }
        
        // 生成随机名称
        $firstNames = ['赵', '钱', '孙', '李', '周', '吴', '郑', '王', '冯', '陈', '褚', '卫', '蒋', '沈', '韩', '杨', '朱', '秦', '尤', '许'];
        $lastNames = ['云', '明', '辉', '杰', '峰', '强', '军', '平', '保', '东', '文', '辉', '力', '明', '永', '健', '世', '广', '志', '义'];
        $name = $firstNames[array_rand($firstNames)] . $lastNames[array_rand($lastNames)];
        
        // 生成随机属性
        $attributes = [];
        
        // 根据稀有度设置基础属性范围
        switch ($rarity) {
            case 'legendary':
                $minAttr = 80;
                $maxAttr = 100;
                $minGrowth = 2.0;
                $maxGrowth = 3.0;
                break;
            case 'epic':
                $minAttr = 60;
                $maxAttr = 80;
                $minGrowth = 1.5;
                $maxGrowth = 2.5;
                break;
            case 'rare':
                $minAttr = 40;
                $maxAttr = 60;
                $minGrowth = 1.0;
                $maxGrowth = 2.0;
                break;
            case 'uncommon':
                $minAttr = 20;
                $maxAttr = 40;
                $minGrowth = 0.5;
                $maxGrowth = 1.5;
                break;
            default:
                $minAttr = 10;
                $maxAttr = 30;
                $minGrowth = 0.3;
                $maxGrowth = 1.0;
        }
        
        // 生成随机属性
        $attributes['leadership'] = mt_rand($minAttr, $maxAttr);
        $attributes['strength'] = mt_rand($minAttr, $maxAttr);
        $attributes['intelligence'] = mt_rand($minAttr, $maxAttr);
        $attributes['politics'] = mt_rand($minAttr, $maxAttr);
        $attributes['charm'] = mt_rand($minAttr, $maxAttr);
        
        // 生成随机成长率
        $attributes['leadership_growth'] = round(mt_rand($minGrowth * 10, $maxGrowth * 10) / 10, 1);
        $attributes['strength_growth'] = round(mt_rand($minGrowth * 10, $maxGrowth * 10) / 10, 1);
        $attributes['intelligence_growth'] = round(mt_rand($minGrowth * 10, $maxGrowth * 10) / 10, 1);
        $attributes['politics_growth'] = round(mt_rand($minGrowth * 10, $maxGrowth * 10) / 10, 1);
        $attributes['charm_growth'] = round(mt_rand($minGrowth * 10, $maxGrowth * 10) / 10, 1);
        
        // 创建武将
        $general = new General();
        return $general->createGeneral($ownerId, $name, $rarity, $attributes);
    }
}

<?php
// 种火集结号 - 武将技能类

class GeneralSkill {
    private $db;
    private $skillId;
    private $generalId;
    private $skillType;
    private $skillName;
    private $skillLevel;
    private $skillEffect;
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $skillId 技能ID
     */
    public function __construct($skillId = null) {
        $this->db = Database::getInstance()->getConnection();
        
        if ($skillId !== null) {
            $this->skillId = $skillId;
            $this->loadSkillData();
        }
    }
    
    /**
     * 加载技能数据
     */
    private function loadSkillData() {
        $query = "SELECT * FROM general_skills WHERE skill_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->skillId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $skillData = $result->fetch_assoc();
            $this->generalId = $skillData['general_id'];
            $this->skillType = $skillData['skill_type'];
            $this->skillName = $skillData['skill_name'];
            $this->skillLevel = $skillData['skill_level'];
            $this->skillEffect = json_decode($skillData['skill_effect'], true);
            $this->isValid = true;
        }
        
        $stmt->close();
    }
    
    /**
     * 创建新技能
     * @param int $generalId 武将ID
     * @param string $skillType 技能类型
     * @param string $skillName 技能名称
     * @param string $skillEffect 技能效果
     * @return bool|int 成功返回技能ID，失败返回false
     */
    public function createSkill($generalId, $skillType, $skillName, $skillEffect) {
        // 检查参数
        if (empty($generalId) || empty($skillType) || empty($skillName) || empty($skillEffect)) {
            return false;
        }
        
        // 检查技能类型是否有效
        $validTypes = ['city', 'army', 'special'];
        if (!in_array($skillType, $validTypes)) {
            return false;
        }
        
        // 将技能效果转换为JSON
        $skillEffectJson = json_encode($skillEffect);
        
        // 创建技能记录
        $query = "INSERT INTO general_skills (general_id, skill_type, skill_name, skill_level, skill_effect) 
                 VALUES (?, ?, ?, 1, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isss', $generalId, $skillType, $skillName, $skillEffectJson);
        $result = $stmt->execute();
        
        if (!$result) {
            $stmt->close();
            return false;
        }
        
        $skillId = $this->db->insert_id;
        $stmt->close();
        
        // 设置对象属性
        $this->skillId = $skillId;
        $this->generalId = $generalId;
        $this->skillType = $skillType;
        $this->skillName = $skillName;
        $this->skillLevel = 1;
        $this->skillEffect = $skillEffect;
        $this->isValid = true;
        
        return $skillId;
    }
    
    /**
     * 升级技能
     * @return bool 是否成功
     */
    public function upgradeSkill() {
        if (!$this->isValid || $this->skillLevel >= 5) {
            return false;
        }
        
        // 增加技能等级
        $newLevel = $this->skillLevel + 1;
        
        // 增强技能效果
        $newEffect = $this->enhanceSkillEffect();
        $newEffectJson = json_encode($newEffect);
        
        // 更新数据库
        $query = "UPDATE general_skills SET skill_level = ?, skill_effect = ? WHERE skill_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isi', $newLevel, $newEffectJson, $this->skillId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            // 更新对象属性
            $this->skillLevel = $newLevel;
            $this->skillEffect = $newEffect;
            return true;
        }
        
        return false;
    }
    
    /**
     * 增强技能效果
     * @return array 新的技能效果
     */
    private function enhanceSkillEffect() {
        $newEffect = [];
        
        foreach ($this->skillEffect as $key => $value) {
            // 根据技能类型和等级增强效果
            $enhanceFactor = 1.0;
            
            switch ($this->skillType) {
                case 'city':
                    $enhanceFactor = 1.2; // 城池技能每级增强20%
                    break;
                case 'army':
                    $enhanceFactor = 1.3; // 军队技能每级增强30%
                    break;
                case 'special':
                    $enhanceFactor = 1.5; // 特殊技能每级增强50%
                    break;
            }
            
            $newEffect[$key] = $value * $enhanceFactor;
        }
        
        return $newEffect;
    }
    
    /**
     * 获取技能ID
     * @return int
     */
    public function getSkillId() {
        return $this->skillId;
    }
    
    /**
     * 获取武将ID
     * @return int
     */
    public function getGeneralId() {
        return $this->generalId;
    }
    
    /**
     * 获取技能类型
     * @return string
     */
    public function getSkillType() {
        return $this->skillType;
    }
    
    /**
     * 获取技能名称
     * @return string
     */
    public function getSkillName() {
        return $this->skillName;
    }
    
    /**
     * 获取技能等级
     * @return int
     */
    public function getSkillLevel() {
        return $this->skillLevel;
    }
    
    /**
     * 获取技能效果
     * @return array
     */
    public function getEffect() {
        return $this->skillEffect;
    }
    
    /**
     * 检查技能是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 获取武将的所有技能
     * @param int $generalId 武将ID
     * @return array 技能数组
     */
    public static function getGeneralSkills($generalId) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT skill_id FROM general_skills WHERE general_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $generalId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $skills = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $skill = new GeneralSkill($row['skill_id']);
                if ($skill->isValid()) {
                    $skills[] = $skill;
                }
            }
        }
        
        $stmt->close();
        return $skills;
    }
    
    /**
     * 获取随机技能
     * @param string $skillType 技能类型
     * @return array 技能信息
     */
    public static function getRandomSkill($skillType = null) {
        // 如果未指定技能类型，随机选择一种
        if ($skillType === null) {
            $skillTypes = ['city', 'army', 'special'];
            $skillType = $skillTypes[array_rand($skillTypes)];
        }
        
        // 根据技能类型选择技能
        $skills = [];
        
        switch ($skillType) {
            case 'city':
                $skills = [
                    ['name' => '资源增产', 'effect' => ['production' => 10]],
                    ['name' => '城防增强', 'effect' => ['defense' => 15]],
                    ['name' => '建造加速', 'effect' => ['build_speed' => 10]],
                    ['name' => '人口增长', 'effect' => ['population_growth' => 5]],
                    ['name' => '税收增加', 'effect' => ['tax' => 10]]
                ];
                break;
            case 'army':
                $skills = [
                    ['name' => '攻击增强', 'effect' => ['attack' => 15]],
                    ['name' => '防御增强', 'effect' => ['defense' => 15]],
                    ['name' => '行军加速', 'effect' => ['speed' => 10]],
                    ['name' => '士气提升', 'effect' => ['morale' => 10]],
                    ['name' => '伤害减免', 'effect' => ['damage_reduction' => 5]]
                ];
                break;
            case 'special':
                $skills = [
                    ['name' => '侦察', 'effect' => ['scout_range' => 2]],
                    ['name' => '伏击', 'effect' => ['ambush' => 20]],
                    ['name' => '外交', 'effect' => ['diplomacy' => 15]],
                    ['name' => '谋略', 'effect' => ['strategy' => 15]],
                    ['name' => '医术', 'effect' => ['healing' => 10]]
                ];
                break;
        }
        
        // 随机选择一个技能
        return $skills[array_rand($skills)];
    }
}

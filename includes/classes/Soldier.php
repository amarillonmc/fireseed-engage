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
        
        // 将训练中的士兵添加到现有士兵中
        $newQuantity = $this->quantity + $this->inTraining;
        
        $query = "UPDATE soldiers SET quantity = ?, in_training = 0, training_complete_time = NULL WHERE soldier_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newQuantity, $this->soldierId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->quantity = $newQuantity;
            $this->inTraining = 0;
            $this->trainingCompleteTime = null;
            return true;
        }
        
        return false;
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
        $stmt->bind_param('isiiss', $cityId, $type, $level, $quantity, $inTraining, $trainingCompleteTime);
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

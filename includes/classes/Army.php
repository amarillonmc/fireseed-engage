<?php
// 种火集结号 - 军队类

class Army {
    private $db;
    private $armyId;
    private $ownerId;
    private $name;
    private $status;
    private $currentX;
    private $currentY;
    private $targetX;
    private $targetY;
    private $departureTime;
    private $arrivalTime;
    private $returnTime;
    private $cityId;
    private $units = [];
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $armyId 军队ID
     */
    public function __construct($armyId = null) {
        $this->db = Database::getInstance()->getConnection();
        
        if ($armyId !== null) {
            $this->armyId = $armyId;
            $this->loadArmyData();
        }
    }
    
    /**
     * 加载军队数据
     */
    private function loadArmyData() {
        $query = "SELECT * FROM armies WHERE army_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->armyId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $armyData = $result->fetch_assoc();
            $this->ownerId = $armyData['owner_id'];
            $this->name = $armyData['name'];
            $this->status = $armyData['status'];
            $this->currentX = $armyData['current_x'];
            $this->currentY = $armyData['current_y'];
            $this->targetX = $armyData['target_x'];
            $this->targetY = $armyData['target_y'];
            $this->departureTime = $armyData['departure_time'];
            $this->arrivalTime = $armyData['arrival_time'];
            $this->returnTime = $armyData['return_time'];
            $this->cityId = $armyData['city_id'];
            $this->isValid = true;
            
            // 加载军队单位
            $this->loadArmyUnits();
        }
        
        $stmt->close();
    }
    
    /**
     * 加载军队单位
     */
    private function loadArmyUnits() {
        $query = "SELECT * FROM army_units WHERE army_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->armyId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $this->units[] = [
                    'army_unit_id' => $row['army_unit_id'],
                    'soldier_type' => $row['soldier_type'],
                    'level' => $row['level'],
                    'quantity' => $row['quantity']
                ];
            }
        }
        
        $stmt->close();
    }
    
    /**
     * 创建新军队
     * @param int $ownerId 拥有者ID
     * @param string $name 军队名称
     * @param int $cityId 城池ID
     * @param array $units 军队单位数组，格式为 [['soldier_type' => 'pawn', 'level' => 1, 'quantity' => 10], ...]
     * @return bool|int 成功返回军队ID，失败返回false
     */
    public function createArmy($ownerId, $name, $cityId, $units) {
        // 检查参数
        if (empty($name) || empty($units)) {
            return false;
        }
        
        // 获取城池信息
        $city = new City($cityId);
        if (!$city->isValid() || $city->getOwnerId() != $ownerId) {
            return false;
        }
        
        // 获取城池坐标
        $coordinates = $city->getCoordinates();
        $currentX = $coordinates[0];
        $currentY = $coordinates[1];
        
        // 创建军队记录
        $query = "INSERT INTO armies (owner_id, name, status, current_x, current_y, city_id) VALUES (?, ?, 'idle', ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isiis', $ownerId, $name, $currentX, $currentY, $cityId);
        $result = $stmt->execute();
        
        if (!$result) {
            $stmt->close();
            return false;
        }
        
        $armyId = $this->db->insert_id;
        $stmt->close();
        
        // 添加军队单位
        foreach ($units as $unit) {
            $soldierType = $unit['soldier_type'];
            $level = $unit['level'];
            $quantity = $unit['quantity'];
            
            // 检查城池中是否有足够的士兵
            $soldier = $city->getSoldierByType($soldierType);
            if (!$soldier || $soldier->getLevel() != $level || $soldier->getQuantity() < $quantity) {
                // 如果没有足够的士兵，删除已创建的军队记录
                $this->db->query("DELETE FROM armies WHERE army_id = $armyId");
                return false;
            }
            
            // 添加军队单位
            $query = "INSERT INTO army_units (army_id, soldier_type, level, quantity) VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('isii', $armyId, $soldierType, $level, $quantity);
            $result = $stmt->execute();
            $stmt->close();
            
            if (!$result) {
                // 如果添加失败，删除已创建的军队记录
                $this->db->query("DELETE FROM armies WHERE army_id = $armyId");
                return false;
            }
            
            // 减少城池中的士兵数量
            $soldier->reduceQuantity($quantity);
        }
        
        // 设置对象属性
        $this->armyId = $armyId;
        $this->ownerId = $ownerId;
        $this->name = $name;
        $this->status = 'idle';
        $this->currentX = $currentX;
        $this->currentY = $currentY;
        $this->cityId = $cityId;
        $this->units = $units;
        $this->isValid = true;
        
        return $armyId;
    }
    
    /**
     * 移动军队
     * @param int $targetX 目标X坐标
     * @param int $targetY 目标Y坐标
     * @return bool 是否成功
     */
    public function moveArmy($targetX, $targetY) {
        if (!$this->isValid || $this->status != 'idle') {
            return false;
        }
        
        // 检查坐标是否在地图范围内
        if ($targetX < 0 || $targetX >= MAP_WIDTH || $targetY < 0 || $targetY >= MAP_HEIGHT) {
            return false;
        }
        
        // 检查目标是否是当前位置
        if ($targetX == $this->currentX && $targetY == $this->currentY) {
            return false;
        }
        
        // 计算移动时间
        $distance = abs($targetX - $this->currentX) + abs($targetY - $this->currentY); // 曼哈顿距离
        $movementSpeed = $this->getMovementSpeed(); // 格/小时
        $movementTime = $distance / $movementSpeed * 3600; // 秒
        
        // 设置出发时间和到达时间
        $departureTime = date('Y-m-d H:i:s');
        $arrivalTime = date('Y-m-d H:i:s', time() + $movementTime);
        
        // 更新军队状态和目标位置
        $query = "UPDATE armies SET status = 'marching', target_x = ?, target_y = ?, departure_time = ?, arrival_time = ? WHERE army_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iissi', $targetX, $targetY, $departureTime, $arrivalTime, $this->armyId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->status = 'marching';
            $this->targetX = $targetX;
            $this->targetY = $targetY;
            $this->departureTime = $departureTime;
            $this->arrivalTime = $arrivalTime;
            return true;
        }
        
        return false;
    }
    
    /**
     * 攻击目标
     * @param string $targetType 目标类型（city, tile, army）
     * @param int $targetId 目标ID
     * @return bool|int 成功返回战斗ID，失败返回false
     */
    public function attackTarget($targetType, $targetId) {
        if (!$this->isValid || $this->status != 'idle') {
            return false;
        }
        
        // 根据目标类型获取目标信息
        $targetX = null;
        $targetY = null;
        
        switch ($targetType) {
            case 'city':
                $city = new City($targetId);
                if (!$city->isValid()) {
                    return false;
                }
                $coordinates = $city->getCoordinates();
                $targetX = $coordinates[0];
                $targetY = $coordinates[1];
                break;
            case 'tile':
                $tile = new Map($targetId);
                if (!$tile->isValid()) {
                    return false;
                }
                $targetX = $tile->getX();
                $targetY = $tile->getY();
                break;
            case 'army':
                $army = new Army($targetId);
                if (!$army->isValid()) {
                    return false;
                }
                $position = $army->getCurrentPosition();
                $targetX = $position[0];
                $targetY = $position[1];
                break;
            default:
                return false;
        }
        
        // 移动到目标位置
        if (!$this->moveArmy($targetX, $targetY)) {
            return false;
        }
        
        // 创建战斗记录（战斗将在军队到达后执行）
        $battle = new Battle();
        $battleId = $battle->createPendingBattle($this->armyId, $targetType, $targetId);
        
        return $battleId;
    }
    
    /**
     * 返回城池
     * @return bool 是否成功
     */
    public function returnToCity() {
        if (!$this->isValid || ($this->status != 'idle' && $this->status != 'marching')) {
            return false;
        }
        
        // 如果军队已经在城池中，无需返回
        if ($this->status == 'idle' && $this->cityId) {
            return true;
        }
        
        // 获取城池坐标
        $city = new City($this->cityId);
        if (!$city->isValid()) {
            return false;
        }
        $coordinates = $city->getCoordinates();
        $cityX = $coordinates[0];
        $cityY = $coordinates[1];
        
        // 计算返回时间
        $distance = abs($cityX - $this->currentX) + abs($cityY - $this->currentY); // 曼哈顿距离
        $movementSpeed = $this->getMovementSpeed(); // 格/小时
        $movementTime = $distance / $movementSpeed * 3600; // 秒
        
        // 设置返回时间
        $returnTime = date('Y-m-d H:i:s', time() + $movementTime);
        
        // 更新军队状态和返回时间
        $query = "UPDATE armies SET status = 'returning', target_x = ?, target_y = ?, return_time = ? WHERE army_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iisi', $cityX, $cityY, $returnTime, $this->armyId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->status = 'returning';
            $this->targetX = $cityX;
            $this->targetY = $cityY;
            $this->returnTime = $returnTime;
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取军队战斗力
     * @return int 战斗力
     */
    public function getCombatPower() {
        if (!$this->isValid) {
            return 0;
        }
        
        $totalPower = 0;
        
        foreach ($this->units as $unit) {
            $soldierType = $unit['soldier_type'];
            $level = $unit['level'];
            $quantity = $unit['quantity'];
            
            // 获取士兵基础攻击力和防御力
            $baseAttack = $this->getSoldierBaseAttack($soldierType);
            $baseDefense = $this->getSoldierBaseDefense($soldierType);
            
            // 计算士兵战斗力
            $soldierPower = ($baseAttack + $baseDefense) * $level * $quantity;
            $totalPower += $soldierPower;
        }
        
        return $totalPower;
    }
    
    /**
     * 获取军队移动速度
     * @return float 移动速度（格/小时）
     */
    public function getMovementSpeed() {
        if (!$this->isValid || empty($this->units)) {
            return 0;
        }
        
        $minSpeed = PHP_FLOAT_MAX;
        
        foreach ($this->units as $unit) {
            $soldierType = $unit['soldier_type'];
            $movementSpeed = $this->getSoldierMovementSpeed($soldierType);
            
            if ($movementSpeed < $minSpeed) {
                $minSpeed = $movementSpeed;
            }
        }
        
        return $minSpeed;
    }
    
    /**
     * 获取士兵基础攻击力
     * @param string $soldierType 士兵类型
     * @return int 基础攻击力
     */
    private function getSoldierBaseAttack($soldierType) {
        switch ($soldierType) {
            case 'pawn':
                return PAWN_ATTACK;
            case 'knight':
                return KNIGHT_ATTACK;
            case 'rook':
                return ROOK_ATTACK;
            case 'bishop':
                return BISHOP_ATTACK;
            case 'golem':
                return GOLEM_ATTACK;
            case 'scout':
                return SCOUT_ATTACK;
            default:
                return 0;
        }
    }
    
    /**
     * 获取士兵基础防御力
     * @param string $soldierType 士兵类型
     * @return int 基础防御力
     */
    private function getSoldierBaseDefense($soldierType) {
        switch ($soldierType) {
            case 'pawn':
                return PAWN_DEFENSE;
            case 'knight':
                return KNIGHT_DEFENSE;
            case 'rook':
                return ROOK_DEFENSE;
            case 'bishop':
                return BISHOP_DEFENSE;
            case 'golem':
                return GOLEM_DEFENSE;
            case 'scout':
                return SCOUT_DEFENSE;
            default:
                return 0;
        }
    }
    
    /**
     * 获取士兵移动速度
     * @param string $soldierType 士兵类型
     * @return float 移动速度（格/小时）
     */
    private function getSoldierMovementSpeed($soldierType) {
        switch ($soldierType) {
            case 'pawn':
                return 3600 / PAWN_MOVEMENT_SPEED;
            case 'knight':
                return 3600 / KNIGHT_MOVEMENT_SPEED;
            case 'rook':
                return 3600 / ROOK_MOVEMENT_SPEED;
            case 'bishop':
                return 3600 / BISHOP_MOVEMENT_SPEED;
            case 'golem':
                return 3600 / GOLEM_MOVEMENT_SPEED;
            case 'scout':
                return 3600 / SCOUT_MOVEMENT_SPEED;
            default:
                return 0;
        }
    }
    
    /**
     * 检查军队是否已到达目标
     * @return bool 是否已到达
     */
    public function checkArrival() {
        if (!$this->isValid || $this->status != 'marching' || !$this->arrivalTime) {
            return false;
        }
        
        $now = time();
        $arrivalTime = strtotime($this->arrivalTime);
        
        if ($now >= $arrivalTime) {
            // 更新军队位置和状态
            $query = "UPDATE armies SET current_x = target_x, current_y = target_y, status = 'idle' WHERE army_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->armyId);
            $result = $stmt->execute();
            $stmt->close();
            
            if ($result) {
                $this->currentX = $this->targetX;
                $this->currentY = $this->targetY;
                $this->status = 'idle';
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查军队是否已返回城池
     * @return bool 是否已返回
     */
    public function checkReturn() {
        if (!$this->isValid || $this->status != 'returning' || !$this->returnTime) {
            return false;
        }
        
        $now = time();
        $returnTime = strtotime($this->returnTime);
        
        if ($now >= $returnTime) {
            // 更新军队位置和状态
            $query = "UPDATE armies SET current_x = target_x, current_y = target_y, status = 'idle' WHERE army_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->armyId);
            $result = $stmt->execute();
            $stmt->close();
            
            if ($result) {
                $this->currentX = $this->targetX;
                $this->currentY = $this->targetY;
                $this->status = 'idle';
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 解散军队
     * @return bool 是否成功
     */
    public function disbandArmy() {
        if (!$this->isValid || $this->status != 'idle') {
            return false;
        }
        
        // 获取城池
        $city = new City($this->cityId);
        if (!$city->isValid()) {
            return false;
        }
        
        // 将士兵返回城池
        foreach ($this->units as $unit) {
            $soldierType = $unit['soldier_type'];
            $level = $unit['level'];
            $quantity = $unit['quantity'];
            
            // 获取城池中的士兵
            $soldier = $city->getSoldierByType($soldierType);
            
            if ($soldier && $soldier->getLevel() == $level) {
                // 增加城池中的士兵数量
                $soldier->addQuantity($quantity);
            } else {
                // 创建新的士兵记录
                $soldier = new Soldier();
                $soldier->createSoldier($this->cityId, $soldierType, $level, $quantity);
            }
        }
        
        // 删除军队记录
        $query = "DELETE FROM armies WHERE army_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->armyId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->isValid = false;
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取军队ID
     * @return int
     */
    public function getArmyId() {
        return $this->armyId;
    }
    
    /**
     * 获取军队名称
     * @return string
     */
    public function getName() {
        return $this->name;
    }
    
    /**
     * 获取军队状态
     * @return string
     */
    public function getStatus() {
        return $this->status;
    }
    
    /**
     * 获取军队单位
     * @return array
     */
    public function getUnits() {
        return $this->units;
    }
    
    /**
     * 获取军队拥有者ID
     * @return int
     */
    public function getOwnerId() {
        return $this->ownerId;
    }
    
    /**
     * 获取军队所属城池ID
     * @return int
     */
    public function getCityId() {
        return $this->cityId;
    }
    
    /**
     * 获取军队当前位置
     * @return array [x, y]
     */
    public function getCurrentPosition() {
        return [$this->currentX, $this->currentY];
    }
    
    /**
     * 获取军队目标位置
     * @return array [x, y]
     */
    public function getTargetPosition() {
        return [$this->targetX, $this->targetY];
    }
    
    /**
     * 获取军队出发时间
     * @return string
     */
    public function getDepartureTime() {
        return $this->departureTime;
    }
    
    /**
     * 获取军队到达时间
     * @return string
     */
    public function getArrivalTime() {
        return $this->arrivalTime;
    }
    
    /**
     * 获取军队返回时间
     * @return string
     */
    public function getReturnTime() {
        return $this->returnTime;
    }
    
    /**
     * 检查军队是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 获取用户的所有军队
     * @param int $userId 用户ID
     * @return array 军队数组
     */
    public static function getUserArmies($userId) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT army_id FROM armies WHERE owner_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $armies = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $army = new Army($row['army_id']);
                if ($army->isValid()) {
                    $armies[] = $army;
                }
            }
        }
        
        $stmt->close();
        return $armies;
    }
    
    /**
     * 检查所有行军中的军队
     * @return array 已到达的军队ID数组
     */
    public static function checkMarchingArmies() {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT army_id FROM armies WHERE status = 'marching' AND arrival_time <= NOW()";
        $result = $db->query($query);
        
        $arrivedArmies = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $army = new Army($row['army_id']);
                if ($army->isValid() && $army->checkArrival()) {
                    $arrivedArmies[] = $army->getArmyId();
                }
            }
        }
        
        return $arrivedArmies;
    }
    
    /**
     * 检查所有返回中的军队
     * @return array 已返回的军队ID数组
     */
    public static function checkReturningArmies() {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT army_id FROM armies WHERE status = 'returning' AND return_time <= NOW()";
        $result = $db->query($query);
        
        $returnedArmies = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $army = new Army($row['army_id']);
                if ($army->isValid() && $army->checkReturn()) {
                    $returnedArmies[] = $army->getArmyId();
                }
            }
        }
        
        return $returnedArmies;
    }
}

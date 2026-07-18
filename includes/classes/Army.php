<?php
// 种火集结号 - 军队类

class Army {
    /**
     * 军队百分比加成上限，防止异常技能数据产生无界数值 / Maximum army bonus percentage to bound malformed skill data
     */
    const MAX_ASSIGNED_GENERAL_BONUS_PERCENT = 1000;
    const MAX_DAMAGE_REDUCTION_PERCENT = 75;
    const MAX_SCOUT_RANGE_BONUS = 15;

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
    private $generalBonusCache = null;
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
        // 检查参数 / Validate input
        $name = trim((string) $name);
        $allowedTypes = ['pawn', 'knight', 'rook', 'bishop', 'golem', 'scout'];
        if ($name === '' || empty($units)) {
            return false;
        }
        
        // 获取城池信息
        $city = new City($cityId);
        if (!$city->isValid() || $city->getOwnerId() != $ownerId) {
            return false;
        }
        
        // 在写入前验证所有兵力，避免留下半成品军队 / Validate all units before writing
        $validatedUnits = [];
        $seenTypes = [];
        foreach ($units as $unit) {
            if (!isset($unit['soldier_type'], $unit['level'], $unit['quantity'])) {
                return false;
            }

            $soldierType = (string) $unit['soldier_type'];
            $level = (int) $unit['level'];
            $quantity = (int) $unit['quantity'];
            if (!in_array($soldierType, $allowedTypes, true)
                || $level < 1
                || $quantity < 1
                || isset($seenTypes[$soldierType])) {
                return false;
            }

            $soldier = $city->getSoldierByType($soldierType);
            if (!$soldier
                || (int) $soldier->getLevel() !== $level
                || (int) $soldier->getQuantity() < $quantity) {
                return false;
            }

            $seenTypes[$soldierType] = true;
            $validatedUnits[] = [
                'soldier_type' => $soldierType,
                'level' => $level,
                'quantity' => $quantity,
                'soldier' => $soldier
            ];
        }

        // 获取城池坐标 / Read the city coordinates
        $coordinates = $city->getCoordinates();
        $currentX = $coordinates[0];
        $currentY = $coordinates[1];

        $this->db->begin_transaction();

        try {
            lockSeasonForWorldAction($this->db);
            // 先锁定并重验城池归属与坐标，避免从已易主城池复制军队 / Lock and revalidate city ownership and coordinates before consuming troops
            $query = "SELECT owner_id, x, y
                      FROM cities
                      WHERE city_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $lockedCity = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$lockedCity || (int) $lockedCity['owner_id'] !== (int) $ownerId) {
                throw new RuntimeException('城池归属已经变化 / City ownership changed');
            }
            $currentX = (int) $lockedCity['x'];
            $currentY = (int) $lockedCity['y'];

            // 一次按主键顺序锁定全城兵力，避免多请求以不同兵种顺序形成死锁 / Lock every city troop row once in primary-key order to avoid cross-type deadlocks
            $query = "SELECT soldier_id, type, level, quantity
                      FROM soldiers
                      WHERE city_id = ?
                      ORDER BY soldier_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $soldiersByType = [];
            while ($result && ($soldierRow = $result->fetch_assoc())) {
                $soldiersByType[$soldierRow['type']] = $soldierRow;
            }
            $stmt->close();

            foreach ($validatedUnits as $index => $unit) {
                $soldierRow = isset($soldiersByType[$unit['soldier_type']])
                    ? $soldiersByType[$unit['soldier_type']]
                    : null;
                if (!$soldierRow
                    || (int) $soldierRow['level'] !== (int) $unit['level']
                    || (int) $soldierRow['quantity'] < (int) $unit['quantity']) {
                    throw new RuntimeException('城内可用兵力已经变化 / Available city troops changed');
                }
                $validatedUnits[$index]['soldier_id'] = (int) $soldierRow['soldier_id'];
            }

            // 创建军队记录 / Create the army record
            $query = "INSERT INTO armies (owner_id, name, status, current_x, current_y, city_id)
                      VALUES (?, ?, 'idle', ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('isiii', $ownerId, $name, $currentX, $currentY, $cityId);
            if (!$stmt->execute()) {
                throw new RuntimeException('创建军队失败 / Failed to create army');
            }

            $armyId = $this->db->insert_id;
            $stmt->close();

            // 添加军队单位并扣除城内兵力 / Add units and deduct the city garrison
            foreach ($validatedUnits as $unit) {
                $soldierType = $unit['soldier_type'];
                $level = $unit['level'];
                $quantity = $unit['quantity'];
                $query = "INSERT INTO army_units (army_id, soldier_type, level, quantity)
                          VALUES (?, ?, ?, ?)";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('isii', $armyId, $soldierType, $level, $quantity);
                if (!$stmt->execute()) {
                    throw new RuntimeException('添加军队单位失败 / Failed to add army unit');
                }
                $stmt->close();

                $query = "UPDATE soldiers
                          SET quantity = quantity - ?
                          WHERE soldier_id = ? AND level = ? AND quantity >= ?";
                $stmt = $this->db->prepare($query);
                $soldierId = (int) $unit['soldier_id'];
                $stmt->bind_param(
                    'iiii',
                    $quantity,
                    $soldierId,
                    $level,
                    $quantity
                );
                $deducted = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$deducted) {
                    throw new RuntimeException('扣除城内兵力失败 / Failed to deduct city soldiers');
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Army creation failed: ' . $e->getMessage());
            return false;
        }
        
        // 设置对象属性
        $this->armyId = $armyId;
        $this->ownerId = $ownerId;
        $this->name = $name;
        $this->status = 'idle';
        $this->currentX = $currentX;
        $this->currentY = $currentY;
        $this->cityId = $cityId;
        $this->units = [];
        foreach ($validatedUnits as $unit) {
            $this->units[] = [
                'soldier_type' => $unit['soldier_type'],
                'level' => $unit['level'],
                'quantity' => $unit['quantity']
            ];
        }
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
        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);
            $moved = $this->moveArmyInTransaction($targetX, $targetY);
            if (!$moved) {
                $this->db->rollback();
                return false;
            }
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Army movement failed: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * 在调用方事务内派遣军队 / Dispatch an army inside the caller's transaction
     */
    private function moveArmyInTransaction($targetX, $targetY) {
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
        if ($movementSpeed <= 0) {
            return false;
        }
        $movementTime = $distance / $movementSpeed * 3600; // 秒
        
        // 设置出发时间和到达时间
        $departureTime = date('Y-m-d H:i:s');
        $arrivalTime = date('Y-m-d H:i:s', time() + $movementTime);
        
        // 用状态条件防止并发请求重复派遣 / Guard the update so concurrent requests cannot dispatch twice
        $query = "UPDATE armies AS moving_army
                  SET status = 'marching', target_x = ?, target_y = ?,
                      departure_time = ?, arrival_time = ?, return_time = NULL
                  WHERE moving_army.army_id = ? AND moving_army.status = 'idle'
                    AND NOT EXISTS (
                        SELECT 1 FROM battles pending_battle
                        WHERE pending_battle.attacker_army_id = moving_army.army_id
                          AND pending_battle.result = 'pending'
                    )";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iissi', $targetX, $targetY, $departureTime, $arrivalTime, $this->armyId);
        $result = $stmt->execute() && $stmt->affected_rows === 1;
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
        $targetOwnerId = null;
        
        switch ($targetType) {
            case 'city':
                $city = new City($targetId);
                if (!$city->isValid()) {
                    return false;
                }
                $coordinates = $city->getCoordinates();
                $targetX = $coordinates[0];
                $targetY = $coordinates[1];
                $targetOwnerId = (int) $city->getOwnerId();
                break;
            case 'tile':
                $tile = new Map($targetId);
                if (!$tile->isValid()) {
                    return false;
                }
                $targetX = $tile->getX();
                $targetY = $tile->getY();
                $targetOwnerId = $tile->getOwnerId() === null
                    ? null
                    : (int) $tile->getOwnerId();
                break;
            case 'army':
                $army = new Army($targetId);
                if (!$army->isValid()) {
                    return false;
                }
                $position = $army->getCurrentPosition();
                $targetX = $position[0];
                $targetY = $position[1];
                $targetOwnerId = (int) $army->getOwnerId();
                break;
            default:
                return false;
        }
        
        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);
            // 玩家锁先于附属、军队与目标实体，防止迁城或主城失守期间插入陈旧战斗。 / Lock users before vassalage, armies, and targets so relocation or capital capture cannot admit a stale battle.
            $combatUserIds = [(int) $this->ownerId];
            if ($targetOwnerId !== null) {
                $combatUserIds[] = (int) $targetOwnerId;
            }
            $combatUserIds = array_values(array_unique($combatUserIds));
            sort($combatUserIds, SORT_NUMERIC);
            foreach ($combatUserIds as $combatUserId) {
                $query = "SELECT user_id
                          FROM users
                          WHERE user_id = ?
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $combatUserId);
                $stmt->execute();
                $userResult = $stmt->get_result();
                $userLocked = $userResult
                    && $userResult->num_rows === 1;
                $stmt->close();
                if (!$userLocked) {
                    throw new RuntimeException(
                        '攻击方或目标玩家已经不存在 / An attacking or target player no longer exists'
                    );
                }
            }

            // 重新锁定军队并确保一军只有一个待结算战斗 / Re-lock the army and allow only one pending battle per army
            $query = "SELECT status, current_x, current_y
                      FROM armies
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $lockedArmy = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$lockedArmy || $lockedArmy['status'] !== 'idle') {
                throw new RuntimeException('军队不再待命 / Army is no longer idle');
            }

            $query = "SELECT battle_id
                      FROM battles
                      WHERE attacker_army_id = ? AND result = 'pending'
                      LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $hasPendingBattle = $result && $result->num_rows > 0;
            $stmt->close();
            if ($hasPendingBattle) {
                throw new RuntimeException('军队已有待结算战斗 / Army already has a pending battle');
            }

            // 锁定并重读权威目标坐标，随后锁定相邻控制点完成事务内边界重验 / Lock and reload authoritative target coordinates, then lock an adjacent control point for transactional boundary validation
            if ($targetType === 'city') {
                $query = "SELECT x, y, owner_id
                          FROM cities
                          WHERE city_id = ?
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $targetId);
                $stmt->execute();
                $targetResult = $stmt->get_result();
                $targetRow = $targetResult ? $targetResult->fetch_assoc() : null;
                $stmt->close();
            } elseif ($targetType === 'tile') {
                $query = "SELECT x, y, owner_id
                          FROM map_tiles
                          WHERE tile_id = ?
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $targetId);
                $stmt->execute();
                $targetResult = $stmt->get_result();
                $targetRow = $targetResult ? $targetResult->fetch_assoc() : null;
                $stmt->close();
            } else {
                if ((int) $targetId === (int) $this->armyId) {
                    throw new RuntimeException('军队不能攻击自身 / An army cannot attack itself');
                }
                $query = "SELECT current_x AS x, current_y AS y,
                                 status, owner_id
                          FROM armies
                          WHERE army_id = ?
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $targetId);
                $stmt->execute();
                $targetResult = $stmt->get_result();
                $targetRow = $targetResult ? $targetResult->fetch_assoc() : null;
                $stmt->close();
                if ($targetRow && $targetRow['status'] !== 'idle') {
                    $targetRow = null;
                }
            }
            if (!$targetRow) {
                throw new RuntimeException('攻击目标已经失效或移动 / Attack target is stale or has moved');
            }
            $authoritativeTargetOwnerId = $targetRow['owner_id'] === null
                ? null
                : (int) $targetRow['owner_id'];
            if ($authoritativeTargetOwnerId !== $targetOwnerId) {
                throw new RuntimeException(
                    '攻击目标拥有权已经变化 / Attack target ownership changed'
                );
            }
            $targetX = (int) $targetRow['x'];
            $targetY = (int) $targetRow['y'];
            if ($targetRow['owner_id'] !== null) {
                $allianceService = new AllianceService();
                if (!$allianceService->canUsersFight(
                    (int) $this->ownerId,
                    (int) $targetRow['owner_id']
                )) {
                    throw new RuntimeException(
                        '不能攻击自己或同势力成员 / Cannot attack yourself or a member of the same force'
                    );
                }
            }
            if (!Map::isAdjacentToUserControl(
                (int) $this->ownerId,
                $targetX,
                $targetY,
                true
            )) {
                throw new RuntimeException(
                    '只能攻击与己方普通领地或城池曼哈顿相邻的目标'
                );
            }

            $this->status = $lockedArmy['status'];
            $this->currentX = (int) $lockedArmy['current_x'];
            $this->currentY = (int) $lockedArmy['current_y'];
            $alreadyAtTarget = (int) $targetX === (int) $this->currentX
                && (int) $targetY === (int) $this->currentY;
            if (!$alreadyAtTarget
                && !$this->moveArmyInTransaction($targetX, $targetY)) {
                throw new RuntimeException('无法派遣军队 / Unable to dispatch army');
            }

            // 行军与待处理战斗必须同时提交，防止留下无战斗的行军 / Commit the march and pending battle together
            $battle = new Battle();
            $battleId = $battle->createPendingBattle(
                $this->armyId,
                $targetType,
                $targetId
            );
            if (!$battleId) {
                throw new RuntimeException('无法建立待处理战斗 / Unable to create pending battle');
            }

            $this->db->commit();
            return $battleId;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Army attack dispatch failed: ' . $exception->getMessage());
            return false;
        }
    }
    
    /**
     * 返回城池
     * @return bool 是否成功
     */
    public function returnToCity() {
        if (!$this->isValid || ($this->status != 'idle' && $this->status != 'marching')) {
            return false;
        }
        
        // 获取城池坐标
        $city = new City($this->cityId);
        if (!$city->isValid()) {
            return false;
        }
        $coordinates = $city->getCoordinates();
        $cityX = $coordinates[0];
        $cityY = $coordinates[1];

        // 只有确实位于所属城池时才无需返程 / Skip return only when the army is physically at its home city
        if ($this->status === 'idle'
            && (int) $this->currentX === (int) $cityX
            && (int) $this->currentY === (int) $cityY) {
            $this->db->begin_transaction();
            try {
                lockSeasonForWorldAction($this->db);
                $query = "DELETE FROM battles
                          WHERE attacker_army_id = ? AND result = 'pending'";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $this->armyId);
                $result = $stmt->execute();
                $stmt->close();
                if (!$result) {
                    throw new RuntimeException('无法取消待处理战斗 / Unable to cancel pending battle');
                }
                $this->db->commit();
                return true;
            } catch (Throwable $exception) {
                $this->db->rollback();
                error_log('Idle army return failed: ' . $exception->getMessage());
                return false;
            }
        }
        
        // 计算返回时间
        $distance = abs($cityX - $this->currentX) + abs($cityY - $this->currentY); // 曼哈顿距离
        $movementSpeed = $this->getMovementSpeed(); // 格/小时
        if ($movementSpeed <= 0) {
            return false;
        }
        $movementTime = $distance / $movementSpeed * 3600; // 秒
        
        // 设置返回时间
        $returnTime = date('Y-m-d H:i:s', time() + $movementTime);
        
        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);
            // 撤回进攻时一并取消尚未结算的战斗 / Cancel unresolved battles when recalling an attacking army
            $query = "DELETE FROM battles
                      WHERE attacker_army_id = ? AND result = 'pending'";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->armyId);
            if (!$stmt->execute()) {
                throw new RuntimeException('无法取消待处理战斗 / Unable to cancel pending battle');
            }
            $stmt->close();

            $query = "UPDATE armies
                      SET status = 'returning', target_x = ?, target_y = ?,
                          return_time = ?, arrival_time = NULL
                      WHERE army_id = ? AND status IN ('idle', 'marching')";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iisi', $cityX, $cityY, $returnTime, $this->armyId);
            $result = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$result) {
                throw new RuntimeException('军队状态已经变化 / Army status changed');
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Army return failed: ' . $exception->getMessage());
            return false;
        }
        
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
        
        $totalAttack = 0.0;
        $totalDefense = 0.0;
        
        foreach ($this->units as $unit) {
            $soldierType = $unit['soldier_type'];
            $level = $unit['level'];
            $quantity = $unit['quantity'];
            
            // 获取士兵基础攻击力和防御力
            $baseAttack = $this->getSoldierBaseAttack($soldierType);
            $baseDefense = $this->getSoldierBaseDefense($soldierType);
            
            $totalAttack += $baseAttack * $level * $quantity;
            $totalDefense += $baseDefense * $level * $quantity;
        }

        // 每名存活且已编入本军的武将只结算一次军队加成 / Apply each living assigned general's army bonus exactly once
        $bonuses = $this->getActiveGeneralBonuses();
        $attackPower = $totalAttack * (1 + $bonuses['attack'] / 100);
        $defensePower = $totalDefense * (1 + $bonuses['defense'] / 100);

        return (int) round($attackPower + $defensePower);
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
        
        $bonuses = $this->getActiveGeneralBonuses();
        return $minSpeed * (1 + $bonuses['speed'] / 100);
    }

    /**
     * 获取存活随军武将提供的减伤百分比 / Get damage reduction from living assigned generals
     * @return float 0至75的减伤百分比 / Damage-reduction percentage from 0 to 75
     */
    public function getDamageReduction() {
        if (!$this->isValid) {
            return 0.0;
        }

        $bonuses = $this->getActiveGeneralBonuses();
        return min(
            self::MAX_DAMAGE_REDUCTION_PERCENT,
            max(0.0, (float) $bonuses['damage_reduction'])
        );
    }

    /**
     * 获取存活随军武将提供的侦察范围点数 / Get scout-range points from living assigned generals
     * @return float 0至15的侦察范围点数 / Scout-range points from 0 to 15
     */
    public function getScoutRangeBonus() {
        if (!$this->isValid) {
            return 0.0;
        }

        $bonuses = $this->getActiveGeneralBonuses();
        return min(
            self::MAX_SCOUT_RANGE_BONUS,
            max(0.0, (float) $bonuses['scout_range'])
        );
    }

    /**
     * 汇总本军存活武将的军队加成 / Aggregate army bonuses from living assigned generals
     * @return array 攻击、守备与速度百分比 / Attack, defense, and speed percentages
     */
    private function getActiveGeneralBonuses() {
        if ($this->generalBonusCache !== null) {
            return $this->generalBonusCache;
        }

        $bonuses = [
            'attack' => 0.0,
            'defense' => 0.0,
            'speed' => 0.0,
            'damage_reduction' => 0.0,
            'scout_range' => 0.0
        ];
        $caps = [
            'attack' => self::MAX_ASSIGNED_GENERAL_BONUS_PERCENT,
            'defense' => self::MAX_ASSIGNED_GENERAL_BONUS_PERCENT,
            'speed' => self::MAX_ASSIGNED_GENERAL_BONUS_PERCENT,
            'damage_reduction' => self::MAX_DAMAGE_REDUCTION_PERCENT,
            'scout_range' => self::MAX_SCOUT_RANGE_BONUS
        ];
        foreach (General::getArmyGenerals($this->armyId) as $general) {
            if (!$general->isValid()
                || (int) $general->getOwnerId() !== (int) $this->ownerId
                || (int) $general->getHp() <= 0) {
                continue;
            }

            $generalBonus = $general->getBonus('army');
            foreach (array_keys($bonuses) as $type) {
                if (isset($generalBonus[$type]) && is_numeric($generalBonus[$type])) {
                    $bonuses[$type] += max(0.0, (float) $generalBonus[$type]);
                    $bonuses[$type] = min(
                        $caps[$type],
                        $bonuses[$type]
                    );
                }
            }
        }

        $this->generalBonusCache = $bonuses;
        return $bonuses;
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
            // 状态与到期时间都作为条件，防止旧对象覆盖撤回或冻结状态 / Guard status and due time so a stale object cannot overwrite recall or freeze state
            $query = "UPDATE armies
                      SET current_x = target_x, current_y = target_y,
                          status = 'idle', departure_time = NULL,
                          arrival_time = NULL, return_time = NULL
                      WHERE army_id = ? AND status = 'marching'
                        AND arrival_time IS NOT NULL
                        AND arrival_time <= NOW()";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->armyId);
            $result = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            
            if ($result) {
                $this->currentX = $this->targetX;
                $this->currentY = $this->targetY;
                $this->status = 'idle';
                $this->departureTime = null;
                $this->arrivalTime = null;
                $this->returnTime = null;
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
            // 状态与到期时间都作为条件，防止旧对象覆盖其他调度 / Guard status and due time so stale return checks cannot overwrite another dispatch
            $query = "UPDATE armies
                      SET current_x = target_x, current_y = target_y,
                          status = 'idle', departure_time = NULL,
                          arrival_time = NULL, return_time = NULL
                      WHERE army_id = ? AND status = 'returning'
                        AND return_time IS NOT NULL
                        AND return_time <= NOW()";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->armyId);
            $result = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            
            if ($result) {
                $this->currentX = $this->targetX;
                $this->currentY = $this->targetY;
                $this->status = 'idle';
                $this->departureTime = null;
                $this->arrivalTime = null;
                $this->returnTime = null;
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

        $city = new City($this->cityId);
        if (!$city->isValid() || (int) $city->getOwnerId() !== (int) $this->ownerId) {
            return false;
        }
        $cityCoordinates = $city->getCoordinates();

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);
            // 锁定军队并拒绝仍有关联战斗的重复解散 / Lock the army and reject duplicate disbandment or unresolved combat
            $query = "SELECT a.status, a.current_x, a.current_y,
                             EXISTS(
                                 SELECT 1 FROM battles b
                                 WHERE b.attacker_army_id = a.army_id
                                   AND b.result = 'pending'
                             ) AS has_pending_battle
                      FROM armies a
                      WHERE a.army_id = ? AND a.owner_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $this->armyId, $this->ownerId);
            $stmt->execute();
            $result = $stmt->get_result();
            $armyRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$armyRow
                || $armyRow['status'] !== 'idle'
                || (int) $armyRow['current_x'] !== (int) $cityCoordinates[0]
                || (int) $armyRow['current_y'] !== (int) $cityCoordinates[1]
                || (int) $armyRow['has_pending_battle'] !== 0) {
                $this->db->rollback();
                return false;
            }

            // 在返还士兵前锁定并重验所属城池，防止把兵力写入已经易主的城池 / Lock and revalidate the home city before returning troops so soldiers cannot enter a captured city
            $query = "SELECT owner_id, x, y
                      FROM cities
                      WHERE city_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $lockedCity = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$lockedCity
                || (int) $lockedCity['owner_id'] !== (int) $this->ownerId
                || (int) $lockedCity['x'] !== (int) $armyRow['current_x']
                || (int) $lockedCity['y'] !== (int) $armyRow['current_y']) {
                $this->db->rollback();
                return false;
            }

            $query = "SELECT soldier_type, level, quantity
                      FROM army_units
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $units = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $units[] = $row;
            }
            $stmt->close();

            // 同兵种返回城池并保留两边较高等级 / Return each type and preserve the higher of the city and army levels
            foreach ($units as $unit) {
                $query = "INSERT INTO soldiers
                             (city_id, type, level, quantity, in_training)
                          VALUES (?, ?, ?, ?, 0)
                          ON DUPLICATE KEY UPDATE
                              level = GREATEST(level, VALUES(level)),
                              quantity = quantity + VALUES(quantity)";
                $stmt = $this->db->prepare($query);
                $soldierType = $unit['soldier_type'];
                $level = (int) $unit['level'];
                $quantity = (int) $unit['quantity'];
                $stmt->bind_param(
                    'isii',
                    $this->cityId,
                    $soldierType,
                    $level,
                    $quantity
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException('无法返还士兵 / Failed to return soldiers');
                }
                $stmt->close();
            }

            $query = "DELETE FROM general_assignments
                      WHERE assignment_type = 'army' AND target_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->armyId);
            if (!$stmt->execute()) {
                throw new RuntimeException('无法解除武将分配 / Failed to unassign generals');
            }
            $stmt->close();

            $query = "DELETE FROM armies WHERE army_id = ? AND owner_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $this->armyId, $this->ownerId);
            $deleted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$deleted) {
                throw new RuntimeException('军队状态已经变化 / Army state changed');
            }

            $this->db->commit();
            $this->isValid = false;
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Army disbandment failed: ' . $exception->getMessage());
            return false;
        }
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
     * 获取军队中最高的士兵等级 / Get the highest soldier level in the army
     * @return int 军队等级 / Army level
     */
    public function getLevel() {
        $level = 1;
        foreach ($this->units as $unit) {
            $level = max($level, (int) $unit['level']);
        }

        return $level;
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

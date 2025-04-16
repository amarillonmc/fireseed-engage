<?php
// 种火集结号 - 战斗类

class Battle {
    private $db;
    private $battleId;
    private $attackerArmyId;
    private $defenderArmyId;
    private $defenderCityId;
    private $defenderTileId;
    private $battleTime;
    private $result;
    private $attackerLosses;
    private $defenderLosses;
    private $rewards;
    private $isValid = false;

    /**
     * 构造函数
     * @param int $battleId 战斗ID
     */
    public function __construct($battleId = null) {
        $this->db = Database::getInstance()->getConnection();

        if ($battleId !== null) {
            $this->battleId = $battleId;
            $this->loadBattleData();
        }
    }

    /**
     * 加载战斗数据
     */
    private function loadBattleData() {
        $query = "SELECT * FROM battles WHERE battle_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->battleId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $battleData = $result->fetch_assoc();
            $this->attackerArmyId = $battleData['attacker_army_id'];
            $this->defenderArmyId = $battleData['defender_army_id'];
            $this->defenderCityId = $battleData['defender_city_id'];
            $this->defenderTileId = $battleData['defender_tile_id'];
            $this->battleTime = $battleData['battle_time'];
            $this->result = $battleData['result'];
            $this->attackerLosses = $battleData['attacker_losses'];
            $this->defenderLosses = $battleData['defender_losses'];
            $this->rewards = $battleData['rewards'];
            $this->isValid = true;
        }

        $stmt->close();
    }

    /**
     * 创建待处理的战斗
     * @param int $attackerArmyId 攻击方军队ID
     * @param string $defenderType 防守方类型（city, tile, army）
     * @param int $defenderId 防守方ID
     * @return bool|int 成功返回战斗ID，失败返回false
     */
    public function createPendingBattle($attackerArmyId, $defenderType, $defenderId) {
        // 检查攻击方军队
        $attackerArmy = new Army($attackerArmyId);
        if (!$attackerArmy->isValid()) {
            return false;
        }

        // 根据防守方类型设置防守方ID
        $defenderArmyId = null;
        $defenderCityId = null;
        $defenderTileId = null;

        switch ($defenderType) {
            case 'army':
                $defenderArmyId = $defenderId;
                break;
            case 'city':
                $defenderCityId = $defenderId;
                break;
            case 'tile':
                $defenderTileId = $defenderId;
                break;
            default:
                return false;
        }

        // 创建战斗记录
        $query = "INSERT INTO battles (attacker_army_id, defender_army_id, defender_city_id, defender_tile_id, battle_time, result, attacker_losses, defender_losses, rewards)
                  VALUES (?, ?, ?, ?, NOW(), 'pending', NULL, NULL, NULL)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iiii', $attackerArmyId, $defenderArmyId, $defenderCityId, $defenderTileId);
        $result = $stmt->execute();

        if (!$result) {
            $stmt->close();
            return false;
        }

        $battleId = $this->db->insert_id;
        $stmt->close();

        // 设置对象属性
        $this->battleId = $battleId;
        $this->attackerArmyId = $attackerArmyId;
        $this->defenderArmyId = $defenderArmyId;
        $this->defenderCityId = $defenderCityId;
        $this->defenderTileId = $defenderTileId;
        $this->battleTime = date('Y-m-d H:i:s');
        $this->isValid = true;

        return $battleId;
    }

    /**
     * 执行战斗
     * @return bool 是否成功
     */
    public function executeBattle() {
        if (!$this->isValid) {
            return false;
        }

        // 获取攻击方军队
        $attackerArmy = new Army($this->attackerArmyId);
        if (!$attackerArmy->isValid()) {
            return false;
        }

        // 获取防守方信息
        $defenderType = null;
        $defender = null;

        if ($this->defenderArmyId) {
            $defenderType = 'army';
            $defender = new Army($this->defenderArmyId);
        } elseif ($this->defenderCityId) {
            $defenderType = 'city';
            $defender = new City($this->defenderCityId);
        } elseif ($this->defenderTileId) {
            $defenderType = 'tile';
            $defender = new Map($this->defenderTileId);
        }

        if (!$defender || !$defender->isValid()) {
            return false;
        }

        // 计算战斗结果
        $attackerPower = $attackerArmy->getCombatPower();
        $defenderPower = $this->getDefenderPower($defenderType, $defender);

        $battleResult = $this->calculateBattleResult($attackerPower, $defenderPower);

        // 计算损失
        $attackerLossPercentage = $this->calculateLossPercentage($battleResult, 'attacker');
        $defenderLossPercentage = $this->calculateLossPercentage($battleResult, 'defender');

        $attackerLosses = $this->calculateLosses($attackerArmy, $attackerLossPercentage);
        $defenderLosses = $this->calculateDefenderLosses($defenderType, $defender, $defenderLossPercentage);

        // 计算奖励
        $rewards = $this->calculateRewards($defenderType, $defender, $battleResult);

        // 更新战斗记录
        $query = "UPDATE battles SET result = ?, attacker_losses = ?, defender_losses = ?, rewards = ? WHERE battle_id = ?";
        $stmt = $this->db->prepare($query);
        $attackerLossesJson = json_encode($attackerLosses);
        $defenderLossesJson = json_encode($defenderLosses);
        $rewardsJson = json_encode($rewards);
        $stmt->bind_param('ssssi', $battleResult, $attackerLossesJson, $defenderLossesJson, $rewardsJson, $this->battleId);
        $result = $stmt->execute();
        $stmt->close();

        if (!$result) {
            return false;
        }

        // 更新对象属性
        $this->result = $battleResult;
        $this->attackerLosses = $attackerLossesJson;
        $this->defenderLosses = $defenderLossesJson;
        $this->rewards = $rewardsJson;

        // 应用战斗结果
        $this->applyBattleResults($attackerArmy, $defenderType, $defender, $battleResult, $attackerLosses, $defenderLosses, $rewards);

        return true;
    }

    /**
     * 获取防守方战斗力
     * @param string $defenderType 防守方类型
     * @param object $defender 防守方对象
     * @return int 战斗力
     */
    private function getDefenderPower($defenderType, $defender) {
        switch ($defenderType) {
            case 'army':
                return $defender->getCombatPower();
            case 'city':
                // 使用城池的getDefensePower方法获取防御力
                $cityPower = $defender->getDefensePower();

                // 应用防御策略加成
                $defenseBonus = $defender->getDefenseStrategyBonus()[0];
                $cityPower = $cityPower * $defenseBonus;

                return $cityPower;
            case 'tile':
                // 地图格子防御力
                if ($defender->getType() == 'npc_fort') {
                    // NPC城池防御力 = NPC等级 * 200 + NPC驻军数量 * 10
                    return $defender->getNpcLevel() * 200 + $defender->getNpcGarrison() * 10;
                } elseif ($defender->getType() == 'resource') {
                    // 资源点防御力 = 50
                    return 50;
                } else {
                    return 0;
                }
            default:
                return 0;
        }
    }

    /**
     * 计算战斗结果
     * @param int $attackerPower 攻击方战斗力
     * @param int $defenderPower 防守方战斗力
     * @return string 战斗结果
     */
    private function calculateBattleResult($attackerPower, $defenderPower) {
        if ($attackerPower > $defenderPower * 1.5) {
            return 'attacker_win_big'; // 攻击方大胜
        } elseif ($attackerPower > $defenderPower) {
            return 'attacker_win'; // 攻击方小胜
        } elseif ($attackerPower * 1.5 < $defenderPower) {
            return 'defender_win_big'; // 防守方大胜
        } elseif ($attackerPower < $defenderPower) {
            return 'defender_win'; // 防守方小胜
        } else {
            return 'draw'; // 平局
        }
    }

    /**
     * 计算损失百分比
     * @param string $battleResult 战斗结果
     * @param string $side 一方（attacker, defender）
     * @return float 损失百分比
     */
    private function calculateLossPercentage($battleResult, $side) {
        switch ($battleResult) {
            case 'attacker_win_big':
                return $side == 'attacker' ? 0.05 : 0.5; // 攻击方损失5%，防守方损失50%
            case 'attacker_win':
                return $side == 'attacker' ? 0.1 : 0.3; // 攻击方损失10%，防守方损失30%
            case 'defender_win_big':
                return $side == 'attacker' ? 0.5 : 0.05; // 攻击方损失50%，防守方损失5%
            case 'defender_win':
                return $side == 'attacker' ? 0.3 : 0.1; // 攻击方损失30%，防守方损失10%
            case 'draw':
                return 0.2; // 双方损失20%
            default:
                return 0.1; // 默认损失10%
        }
    }

    /**
     * 计算军队损失
     * @param Army $army 军队对象
     * @param float $lossPercentage 损失百分比
     * @return array 损失数组
     */
    private function calculateLosses($army, $lossPercentage) {
        $units = $army->getUnits();
        $losses = [];

        foreach ($units as $unit) {
            $soldierType = $unit['soldier_type'];
            $level = $unit['level'];
            $quantity = $unit['quantity'];

            $lossQuantity = ceil($quantity * $lossPercentage);

            if ($lossQuantity > 0) {
                $losses[] = [
                    'soldier_type' => $soldierType,
                    'level' => $level,
                    'quantity' => $lossQuantity
                ];
            }
        }

        return $losses;
    }

    /**
     * 计算防守方损失
     * @param string $defenderType 防守方类型
     * @param object $defender 防守方对象
     * @param float $lossPercentage 损失百分比
     * @return array 损失数组
     */
    private function calculateDefenderLosses($defenderType, $defender, $lossPercentage) {
        switch ($defenderType) {
            case 'army':
                return $this->calculateLosses($defender, $lossPercentage);
            case 'city':
                // 城池损失 = 城池耐久度减少
                $durability = $defender->getDurability();
                $durabilityLoss = ceil($durability * $lossPercentage);

                return [
                    'durability_loss' => $durabilityLoss
                ];
            case 'tile':
                // 地图格子损失
                if ($defender->getType() == 'resource') {
                    // 资源点损失 = 资源量减少
                    $resourceAmount = $defender->getResourceAmount();
                    $resourceLoss = ceil($resourceAmount * $lossPercentage);

                    return [
                        'resource_loss' => $resourceLoss
                    ];
                } else {
                    return [];
                }
            default:
                return [];
        }
    }

    /**
     * 计算奖励
     * @param string $defenderType 防守方类型
     * @param object $defender 防守方对象
     * @param string $battleResult 战斗结果
     * @return array 奖励数组
     */
    private function calculateRewards($defenderType, $defender, $battleResult) {
        // 如果攻击方失败，没有奖励
        if ($battleResult == 'defender_win' || $battleResult == 'defender_win_big') {
            return [];
        }

        $rewards = [];

        switch ($defenderType) {
            case 'army':
                // 击败敌方军队的奖励
                $rewards['circuit_points'] = 5; // 获得5点思考回路
                break;
            case 'city':
                // 攻占城池的奖励
                if ($battleResult == 'attacker_win_big') {
                    // 大胜可以获得城池资源的30%
                    $rewards['resources'] = [
                        'bright' => ceil($defender->getResource()->getBrightCrystal() * 0.3),
                        'warm' => ceil($defender->getResource()->getWarmCrystal() * 0.3),
                        'cold' => ceil($defender->getResource()->getColdCrystal() * 0.3),
                        'green' => ceil($defender->getResource()->getGreenCrystal() * 0.3),
                        'day' => ceil($defender->getResource()->getDayCrystal() * 0.3),
                        'night' => ceil($defender->getResource()->getNightCrystal() * 0.3)
                    ];
                    $rewards['circuit_points'] = 10; // 获得10点思考回路

                    // 大胜可以降低城池耐久度30%
                    $rewards['durability_reduction'] = ceil($defender->getMaxDurability() * 0.3);

                    // 如果城池不是主城，大胜可以占领城池
                    if (!$defender->isMainCity()) {
                        $rewards['capture_city'] = true;
                    }
                } else {
                    // 小胜可以获得城池资源的10%
                    $rewards['resources'] = [
                        'bright' => ceil($defender->getResource()->getBrightCrystal() * 0.1),
                        'warm' => ceil($defender->getResource()->getWarmCrystal() * 0.1),
                        'cold' => ceil($defender->getResource()->getColdCrystal() * 0.1),
                        'green' => ceil($defender->getResource()->getGreenCrystal() * 0.1),
                        'day' => ceil($defender->getResource()->getDayCrystal() * 0.1),
                        'night' => ceil($defender->getResource()->getNightCrystal() * 0.1)
                    ];
                    $rewards['circuit_points'] = 5; // 获得5点思考回路

                    // 小胜可以降低城池耐久度10%
                    $rewards['durability_reduction'] = ceil($defender->getMaxDurability() * 0.1);
                }
                break;
            case 'tile':
                // 攻占地图格子的奖励
                if ($defender->getType() == 'npc_fort') {
                    // 攻占NPC城池的奖励
                    $npcLevel = $defender->getNpcLevel();
                    $rewards['resources'] = [
                        'bright' => $npcLevel * 100,
                        'warm' => $npcLevel * 100,
                        'cold' => $npcLevel * 100,
                        'green' => $npcLevel * 100,
                        'day' => $npcLevel * 50,
                        'night' => $npcLevel * 50
                    ];
                    $rewards['circuit_points'] = $npcLevel * 2; // 获得NPC等级*2点思考回路
                } elseif ($defender->getType() == 'resource') {
                    // 攻占资源点的奖励
                    $rewards['tile_control'] = [
                        'tile_id' => $defender->getTileId(),
                        'type' => $defender->getType(),
                        'subtype' => $defender->getSubtype()
                    ];
                }
                break;
        }

        return $rewards;
    }

    /**
     * 应用战斗结果
     * @param Army $attackerArmy 攻击方军队
     * @param string $defenderType 防守方类型
     * @param object $defender 防守方对象
     * @param string $battleResult 战斗结果
     * @param array $attackerLosses 攻击方损失
     * @param array $defenderLosses 防守方损失
     * @param array $rewards 奖励
     */
    private function applyBattleResults($attackerArmy, $defenderType, $defender, $battleResult, $attackerLosses, $defenderLosses, $rewards) {
        // 应用攻击方损失
        $this->applyArmyLosses($attackerArmy, $attackerLosses);

        // 应用防守方损失
        switch ($defenderType) {
            case 'army':
                $this->applyArmyLosses($defender, $defenderLosses);
                break;
            case 'city':
                if (isset($defenderLosses['durability_loss'])) {
                    $defender->reduceDurability($defenderLosses['durability_loss']);
                }

                // 如果有耐久度减少奖励，应用到城池
                if (isset($rewards['durability_reduction'])) {
                    $defender->reduceDurability($rewards['durability_reduction']);
                }

                // 如果可以占领城池，则将城池转给攻击方
                if (isset($rewards['capture_city']) && $rewards['capture_city'] && !$defender->isMainCity()) {
                    // 更新城池拥有者
                    $query = "UPDATE cities SET owner_id = ? WHERE city_id = ?";
                    $stmt = $this->db->prepare($query);
                    $stmt->bind_param('ii', $attackerArmy->getOwnerId(), $defender->getCityId());
                    $stmt->execute();
                    $stmt->close();

                    // 更新地图格子拥有者
                    $coordinates = $defender->getCoordinates();
                    $tile = new Map();
                    if ($tile->loadByCoordinates($coordinates[0], $coordinates[1])) {
                        $tile->setOwner($attackerArmy->getOwnerId());
                    }
                }
                break;
            case 'tile':
                if ($defender->getType() == 'resource' && isset($defenderLosses['resource_loss'])) {
                    $newResourceAmount = $defender->getResourceAmount() - $defenderLosses['resource_loss'];
                    $defender->setResourceAmount(max(0, $newResourceAmount));
                } elseif ($defender->getType() == 'npc_fort' && ($battleResult == 'attacker_win' || $battleResult == 'attacker_win_big')) {
                    // 设置NPC城池重生时间
                    $npcLevel = $defender->getNpcLevel();
                    $respawnHours = 6 * pow(2, $npcLevel - 1); // 1级:6小时, 2级:12小时, 3级:24小时, 4级:48小时, 5级:96小时
                    $respawnTime = date('Y-m-d H:i:s', time() + $respawnHours * 3600);
                    $defender->setNpcRespawnTime($respawnTime);

                    // 设置NPC城池拥有者为攻击方
                    $defender->setOwner($attackerArmy->getOwnerId());
                }
                break;
        }

        // 应用奖励
        if ($battleResult == 'attacker_win' || $battleResult == 'attacker_win_big') {
            $attackerUser = new User($attackerArmy->getOwnerId());

            // 增加思考回路
            if (isset($rewards['circuit_points'])) {
                $attackerUser->addCircuitPoints($rewards['circuit_points']);
            }

            // 增加资源
            if (isset($rewards['resources'])) {
                $attackerResource = new Resource($attackerArmy->getOwnerId());

                foreach ($rewards['resources'] as $type => $amount) {
                    $attackerResource->addResourceByType($type, $amount);
                }
            }

            // 获得地图格子控制权
            if (isset($rewards['tile_control'])) {
                $tile = new Map($rewards['tile_control']['tile_id']);
                $tile->setOwner($attackerArmy->getOwnerId());
            }
        }
    }

    /**
     * 应用军队损失
     * @param Army $army 军队对象
     * @param array $losses 损失数组
     */
    private function applyArmyLosses($army, $losses) {
        $units = $army->getUnits();

        foreach ($losses as $loss) {
            $soldierType = $loss['soldier_type'];
            $level = $loss['level'];
            $lossQuantity = $loss['quantity'];

            // 更新军队单位数量
            foreach ($units as $key => $unit) {
                if ($unit['soldier_type'] == $soldierType && $unit['level'] == $level) {
                    $newQuantity = $unit['quantity'] - $lossQuantity;

                    if ($newQuantity <= 0) {
                        // 如果单位数量为0，删除该单位
                        $query = "DELETE FROM army_units WHERE army_unit_id = ?";
                        $stmt = $this->db->prepare($query);
                        $stmt->bind_param('i', $unit['army_unit_id']);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        // 更新单位数量
                        $query = "UPDATE army_units SET quantity = ? WHERE army_unit_id = ?";
                        $stmt = $this->db->prepare($query);
                        $stmt->bind_param('ii', $newQuantity, $unit['army_unit_id']);
                        $stmt->execute();
                        $stmt->close();
                    }

                    break;
                }
            }
        }
    }

    /**
     * 获取战斗ID
     * @return int
     */
    public function getBattleId() {
        return $this->battleId;
    }

    /**
     * 获取攻击方军队ID
     * @return int
     */
    public function getAttackerArmyId() {
        return $this->attackerArmyId;
    }

    /**
     * 获取防守方军队ID
     * @return int|null
     */
    public function getDefenderArmyId() {
        return $this->defenderArmyId;
    }

    /**
     * 获取防守方城池ID
     * @return int|null
     */
    public function getDefenderCityId() {
        return $this->defenderCityId;
    }

    /**
     * 获取防守方地图格子ID
     * @return int|null
     */
    public function getDefenderTileId() {
        return $this->defenderTileId;
    }

    /**
     * 获取战斗时间
     * @return string
     */
    public function getBattleTime() {
        return $this->battleTime;
    }

    /**
     * 获取战斗结果
     * @return string
     */
    public function getResult() {
        return $this->result;
    }

    /**
     * 获取攻击方损失
     * @return array
     */
    public function getAttackerLosses() {
        return json_decode($this->attackerLosses, true);
    }

    /**
     * 获取防守方损失
     * @return array
     */
    public function getDefenderLosses() {
        return json_decode($this->defenderLosses, true);
    }

    /**
     * 获取奖励
     * @return array
     */
    public function getRewards() {
        return json_decode($this->rewards, true);
    }

    /**
     * 检查战斗是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }

    /**
     * 获取用户的战斗记录
     * @param int $userId 用户ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array 战斗记录数组
     */
    public static function getUserBattles($userId, $limit = 10, $offset = 0) {
        $db = Database::getInstance()->getConnection();

        $query = "SELECT b.* FROM battles b
                  JOIN armies a ON b.attacker_army_id = a.army_id
                  WHERE a.owner_id = ?
                  ORDER BY b.battle_time DESC
                  LIMIT ? OFFSET ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('iii', $userId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $battles = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $battle = new Battle($row['battle_id']);
                if ($battle->isValid()) {
                    $battles[] = $battle;
                }
            }
        }

        $stmt->close();
        return $battles;
    }

    /**
     * 获取用户的战斗记录总数
     * @param int $userId 用户ID
     * @return int 战斗记录总数
     */
    public static function getUserBattlesCount($userId) {
        $db = Database::getInstance()->getConnection();

        $query = "SELECT COUNT(*) as count FROM battles b
                  JOIN armies a ON b.attacker_army_id = a.army_id
                  WHERE a.owner_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $count = 0;

        if ($result && $row = $result->fetch_assoc()) {
            $count = $row['count'];
        }

        $stmt->close();
        return $count;
    }

    /**
     * 检查待处理的战斗
     * @return array 已处理的战斗ID数组
     */
    public static function checkPendingBattles() {
        $db = Database::getInstance()->getConnection();

        $query = "SELECT b.battle_id FROM battles b
                  JOIN armies a ON b.attacker_army_id = a.army_id
                  WHERE b.result = 'pending' AND a.status = 'idle'";
        $result = $db->query($query);

        $processedBattles = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $battle = new Battle($row['battle_id']);
                if ($battle->isValid() && $battle->executeBattle()) {
                    $processedBattles[] = $battle->getBattleId();
                }
            }
        }

        return $processedBattles;
    }
}

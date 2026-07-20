<?php
// 种火集结号 - 游戏配置管理类

class GameConfig {
    private $db;
    private static $cache = [];
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 获取配置值
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get($key, $default = null) {
        // 先检查缓存
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        
        $db = Database::getInstance()->getConnection();
        $query = "SELECT `value` FROM game_config WHERE `key` = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $value = $row['value'];
            
            // 尝试转换数据类型
            if (is_numeric($value)) {
                $value = strpos($value, '.') !== false ? floatval($value) : intval($value);
            } elseif (in_array(strtolower($value), ['true', 'false'])) {
                $value = strtolower($value) === 'true';
            }
            
            // 缓存结果
            self::$cache[$key] = $value;
            $stmt->close();
            return $value;
        }
        
        $stmt->close();
        return $default;
    }
    
    /**
     * 设置配置值 / Set a configuration value
     * @param string $key 配置键 / Configuration key
     * @param mixed $value 配置值 / Configuration value
     * @param string $description 描述 / Description
     * @param string|null $category 分类；更新时为空则保留原分类 / Category; null preserves it on update
     * @return bool
     */
    public function set($key, $value, $description = null, $category = null) {
        $mapCapacityKeys = [
            'map_resource_tile_ratio',
            'map_npc_fort_tile_ratio',
            'max_players'
        ];
        if (!in_array($key, $mapCapacityKeys, true)) {
            return $this->setUnchecked(
                $key,
                $value,
                $description,
                $category
            );
        }

        $previousCache = self::$cache;
        if (!$this->db->begin_transaction()) {
            return false;
        }
        $success = $this->validateMapCapacityChanges([$key => $value])
            && $this->setUnchecked(
                $key,
                $value,
                $description,
                $category
            );
        if (!$success || !$this->db->commit()) {
            $this->db->rollback();
            self::$cache = $previousCache;
            return false;
        }
        return true;
    }

    /**
     * 保存已经完成组合校验的配置 / Persist a configuration after combined validation
     * @param string $key 配置键 / Configuration key
     * @param mixed $value 配置值 / Configuration value
     * @param string|null $description 描述 / Description
     * @param string|null $category 分类 / Category
     * @return bool
     */
    private function setUnchecked(
        $key,
        $value,
        $description = null,
        $category = null
    ) {
        // 转换值为字符串
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        } else {
            $value = strval($value);
        }
        
        // 检查配置是否存在
        $checkQuery = "SELECT config_id, is_constant FROM game_config WHERE `key` = ?";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bind_param('s', $key);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult && $checkResult->num_rows > 0) {
            $row = $checkResult->fetch_assoc();
            $checkStmt->close();
            
            // 检查是否为常量
            if ($row['is_constant']) {
                return false; // 常量不能修改
            }
            
            // 更新现有配置
            $updateQuery = "UPDATE game_config SET `value` = ?, `description` = COALESCE(?, `description`), `category` = COALESCE(?, `category`) WHERE `key` = ?";
            $updateStmt = $this->db->prepare($updateQuery);
            $updateStmt->bind_param('ssss', $value, $description, $category, $key);
            $result = $updateStmt->execute();
            $updateStmt->close();
        } else {
            $checkStmt->close();
            
            // 插入新配置
            $insertCategory = $category === null ? 'general' : $category;
            $insertQuery = "INSERT INTO game_config (`key`, `value`, `description`, `category`, `is_constant`) VALUES (?, ?, ?, ?, 0)";
            $insertStmt = $this->db->prepare($insertQuery);
            $insertStmt->bind_param(
                'ssss',
                $key,
                $value,
                $description,
                $insertCategory
            );
            $result = $insertStmt->execute();
            $insertStmt->close();
        }
        
        // 更新缓存
        if ($result) {
            // 重新解析值
            if (is_numeric($value)) {
                $value = strpos($value, '.') !== false ? floatval($value) : intval($value);
            } elseif (in_array(strtolower($value), ['true', 'false'])) {
                $value = strtolower($value) === 'true';
            }
            
            self::$cache[$key] = $value;
        }
        
        return $result;
    }
    
    /**
     * 获取所有配置
     * @param string $category 分类过滤
     * @return array
     */
    public function getAll($category = null) {
        $query = "SELECT * FROM game_config";
        $params = [];
        $types = '';
        
        if ($category) {
            $query .= " WHERE category = ?";
            $params[] = $category;
            $types = 's';
        }
        
        $query .= " ORDER BY category, `key`";
        
        $stmt = $this->db->prepare($query);
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $configs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $configs[] = $row;
            }
        }
        
        $stmt->close();
        return $configs;
    }
    
    /**
     * 获取所有分类
     * @return array
     */
    public function getCategories() {
        $query = "SELECT DISTINCT category FROM game_config ORDER BY category";
        $result = $this->db->query($query);
        
        $categories = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $categories[] = $row['category'];
            }
        }
        
        return $categories;
    }
    
    /**
     * 删除配置
     * @param string $key 配置键
     * @return bool
     */
    public function delete($key) {
        // 检查是否为常量
        $checkQuery = "SELECT is_constant FROM game_config WHERE `key` = ?";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bind_param('s', $key);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult && $checkResult->num_rows > 0) {
            $row = $checkResult->fetch_assoc();
            $checkStmt->close();
            
            if ($row['is_constant']) {
                return false; // 常量不能删除
            }
        } else {
            $checkStmt->close();
            return false; // 配置不存在
        }
        
        $deleteQuery = "DELETE FROM game_config WHERE `key` = ? AND is_constant = 0";
        $deleteStmt = $this->db->prepare($deleteQuery);
        $deleteStmt->bind_param('s', $key);
        $result = $deleteStmt->execute();
        $deleteStmt->close();
        
        // 清除缓存
        if ($result && isset(self::$cache[$key])) {
            unset(self::$cache[$key]);
        }
        
        return $result;
    }
    
    /**
     * 批量更新配置 / Update configurations atomically
     * @param array $configs 配置数组 [key => value] / Configuration map
     * @return bool
     */
    public function batchUpdate($configs) {
        $previousCache = self::$cache;
        if (!$this->db->begin_transaction()) {
            return false;
        }
        $success = $this->validateMapCapacityChanges($configs);
        
        if ($success) {
            foreach ($configs as $key => $value) {
                if (!$this->setUnchecked($key, $value)) {
                    $success = false;
                    break;
                }
            }
        }

        $playerLimitKeys = [
            'initial_max_circuit_points',
            'initial_max_general_cost',
            'initial_subbase_limit'
        ];
        if ($success
            && !empty(array_intersect(array_keys($configs), $playerLimitKeys))
            && (!class_exists('TechnologyEffectService')
                || !TechnologyEffectService
                    ::synchronizeAllPlayerLimitsInCurrentTransaction())) {
            $success = false;
        }
        
        if (!$success || !$this->db->commit()) {
            $this->db->rollback();
            self::$cache = $previousCache;
            return false;
        }

        return true;
    }
    
    /**
     * 重置配置到默认值
     * @param string $category 分类（可选）
     * @return bool
     */
    public function resetToDefaults($category = null) {
        // 这里可以定义默认配置值
        $defaults = [
            'new_player_registration' => 1,
            'maintenance_mode' => 0,
            'initial_bright_crystal' => 1000,
            'initial_warm_crystal' => 1000,
            'initial_cold_crystal' => 1000,
            'initial_green_crystal' => 1000,
            'initial_day_crystal' => 1000,
            'initial_night_crystal' => 1000,
            'season_start_bright_grant' => 1000,
            'season_start_night_grant' => 1000,
            'resource_production_rate' => 1.0,
            'persistent_resource_production_multiplier' => 0.2,
            'building_speed_multiplier' => 1.0,
            'research_speed_multiplier' => 1.0,
            'training_speed_multiplier' => 1.0,
            'battle_damage_multiplier' => 1.0,
            'army_movement_speed' => 1.0,
            'general_recruitment_cost_multiplier' => 1.0,
            'initial_circuit_points' => 1,
            'initial_max_circuit_points' => 10,
            'initial_max_general_cost' => 10.0,
            'initial_subbase_limit' => 1,
            'resource_territory_occupation_cost' => 2,
            'map_resource_tile_ratio' => 0.50,
            'map_resource_amount_min' => 5000,
            'map_resource_amount_max' => 10000,
            'map_resource_weight_bright' => 4,
            'map_resource_weight_warm' => 23,
            'map_resource_weight_cold' => 23,
            'map_resource_weight_green' => 23,
            'map_resource_weight_day' => 23,
            'map_resource_weight_night' => 4,
            'map_npc_fort_tile_ratio' => 0.25,
            'map_npc_fort_weight_level_1' => 27,
            'map_npc_fort_weight_level_2' => 20,
            'map_npc_fort_weight_level_3' => 15,
            'map_npc_fort_weight_level_4' => 12,
            'map_npc_fort_weight_level_5' => 9,
            'map_npc_fort_weight_level_6' => 7,
            'map_npc_fort_weight_level_7' => 5,
            'map_npc_fort_weight_level_8' => 3,
            'map_npc_fort_weight_level_9' => 2,
            'vassal_release_resource_rate' => 0.70,
            'vassal_release_relocation_mode' => 'outer',
            'vassal_release_lose_all_territory' => 1,
            'vassal_release_refund_circuit' => 1,
            'image_display_mode' => 'image'
        ];
        
        $selectedDefaults = [];
        foreach ($defaults as $key => $value) {
            if ($category) {
                // 检查配置是否属于指定分类
                $checkQuery = "SELECT category FROM game_config WHERE `key` = ?";
                $checkStmt = $this->db->prepare($checkQuery);
                $checkStmt->bind_param('s', $key);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkResult && $checkResult->num_rows > 0) {
                    $row = $checkResult->fetch_assoc();
                    if ($row['category'] !== $category) {
                        $checkStmt->close();
                        continue;
                    }
                }
                $checkStmt->close();
            }
            
            $selectedDefaults[$key] = $value;
        }

        return empty($selectedDefaults)
            || $this->batchUpdate($selectedDefaults);
    }
    
    /**
     * 清除缓存
     */
    public static function clearCache() {
        self::$cache = [];
    }

    /**
     * 按整数配额判断地图内容能否保留所需空地 / Check integer world quotas against required empty capacity
     * @param mixed $resourceRatio 资源点占比 / Resource-node ratio
     * @param mixed $npcFortRatio NPC据点占比 / NPC-fort ratio
     * @param mixed $requiredEmptyTiles 必须保留的空地数 / Required empty tiles
     * @param mixed $totalTileCount 地图总格数 / Total map tiles
     * @return bool
     */
    public static function areMapTileRatiosValid(
        $resourceRatio,
        $npcFortRatio,
        $requiredEmptyTiles,
        $totalTileCount
    ) {
        if (!is_numeric($resourceRatio)
            || !is_numeric($npcFortRatio)
            || !is_numeric($requiredEmptyTiles)
            || !is_numeric($totalTileCount)) {
            return false;
        }

        $resourceRatio = (float) $resourceRatio;
        $npcFortRatio = (float) $npcFortRatio;
        $requiredEmptyTiles = max(1, (int) $requiredEmptyTiles);
        $totalTileCount = (int) $totalTileCount;
        if ($resourceRatio < 0.0
            || $resourceRatio > 1.0
            || $npcFortRatio < 0.0
            || $npcFortRatio > 1.0
            || $totalTileCount <= $requiredEmptyTiles) {
            return false;
        }

        $occupiedTileCount = (int) floor(
            $totalTileCount * $resourceRatio
        ) + (int) floor($totalTileCount * $npcFortRatio);
        return $occupiedTileCount
            <= $totalTileCount - $requiredEmptyTiles;
    }

    /**
     * 以数据库现值和账号数校验地图容量变更 / Validate map capacity changes against stored values and account count
     * @param array $configs 待保存配置 / Pending configurations
     * @return bool
     */
    private function validateMapCapacityChanges($configs) {
        $mapCapacityKeys = [
            'map_resource_tile_ratio',
            'map_npc_fort_tile_ratio',
            'max_players'
        ];
        if (empty(array_intersect(
            array_keys((array) $configs),
            $mapCapacityKeys
        ))) {
            return true;
        }

        $capacity = [
            'map_resource_tile_ratio' => 0.50,
            'map_npc_fort_tile_ratio' => 0.25,
            'max_players' => 1000
        ];
        $query = "SELECT `key`, `value`
                  FROM game_config
                  WHERE `key` IN (
                    'map_resource_tile_ratio',
                    'map_npc_fort_tile_ratio',
                    'max_players'
                  )
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            return false;
        }
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $capacity[$row['key']] = $row['value'];
        }
        $stmt->close();
        $storedMaxPlayers = max(1, (int) $capacity['max_players']);

        foreach ($mapCapacityKeys as $key) {
            if (array_key_exists($key, $configs)) {
                $capacity[$key] = $configs[$key];
            }
        }
        if (!is_numeric($capacity['max_players'])
            || (int) $capacity['max_players'] < 1) {
            return false;
        }

        $query = "SELECT COUNT(*) AS total FROM users";
        $stmt = $this->db->prepare($query);
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            return false;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return false;
        }
        $playerCapacity = max(
            (int) $capacity['max_players'],
            (int) $row['total']
        );
        $requiredEmptyTiles = $playerCapacity
            + (int) WORLD_SPECIAL_SITE_COUNT;
        $totalTileCount = (int) MAP_WIDTH * (int) MAP_HEIGHT;
        if (!self::areMapTileRatiosValid(
            $capacity['map_resource_tile_ratio'],
            $capacity['map_npc_fort_tile_ratio'],
            $requiredEmptyTiles,
            $totalTileCount
        )) {
            return false;
        }

        $proposedMaxPlayers = (int) $capacity['max_players'];
        $raisesRegistrationCap = array_key_exists(
            'max_players',
            (array) $configs
        ) && $proposedMaxPlayers > $storedMaxPlayers;
        if (!$raisesRegistrationCap) {
            return true;
        }

        // 注册上限立即生效，因此提高上限时还要核对当前世界，而不能只看下一张地图 / Registration caps apply immediately, so increases must fit the current world rather than only the next generation
        $query = "SELECT
                    (SELECT COUNT(*) FROM map_tiles) AS total_tiles,
                    (
                      SELECT COUNT(*)
                      FROM map_tiles
                      WHERE type = 'empty' AND owner_id IS NULL
                    ) AS empty_tiles,
                    (
                      SELECT COUNT(DISTINCT owner_id)
                      FROM cities
                      WHERE is_main_city = 1
                    ) AS placed_players";
        $stmt = $this->db->prepare($query);
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            return false;
        }
        $result = $stmt->get_result();
        $world = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$world) {
            return false;
        }
        if ((int) $world['total_tiles'] > 0
            && $proposedMaxPlayers
                > (int) $world['placed_players']
                    + (int) $world['empty_tiles']) {
            return false;
        }

        return true;
    }
    
    /**
     * 验证配置值
     * @param string $key 配置键
     * @param mixed $value 配置值
     * @return bool
     */
    public function validateConfig($key, $value) {
        // 定义验证规则
        $validationRules = [
            'max_players' => ['type' => 'int', 'min' => 1, 'max' => 10000],
            'new_player_registration' => ['type' => 'bool'],
            'maintenance_mode' => ['type' => 'bool'],
            'initial_bright_crystal' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'initial_warm_crystal' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'initial_cold_crystal' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'initial_green_crystal' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'initial_day_crystal' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'initial_night_crystal' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'season_start_bright_grant' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'season_start_night_grant' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'resource_production_rate' => ['type' => 'float', 'min' => 0.1, 'max' => 10.0],
            'persistent_resource_production_multiplier' => [
                'type' => 'float',
                'min' => 0.0,
                'max' => 1.0
            ],
            'building_speed_multiplier' => ['type' => 'float', 'min' => 0.1, 'max' => 10.0],
            'upgrade_speed_multiplier' => [
                'type' => 'float',
                'min' => 0.1,
                'max' => 10.0
            ],
            'research_speed_multiplier' => ['type' => 'float', 'min' => 0.1, 'max' => 10.0],
            'training_speed_multiplier' => ['type' => 'float', 'min' => 0.1, 'max' => 10.0],
            'battle_damage_multiplier' => ['type' => 'float', 'min' => 0.1, 'max' => 5.0],
            'army_movement_speed' => ['type' => 'float', 'min' => 0.1, 'max' => 10.0],
            'initial_circuit_points' => [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000
            ],
            'initial_max_circuit_points' => [
                'type' => 'int',
                'min' => 1,
                'max' => 1000000
            ],
            'initial_max_general_cost' => [
                'type' => 'float',
                'min' => 0.0,
                'max' => 1000000.0
            ],
            'initial_subbase_limit' => [
                'type' => 'int',
                'min' => 0,
                'max' => 10000
            ],
            'resource_territory_occupation_cost' => [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000
            ],
            'map_resource_tile_ratio' => [
                'type' => 'float',
                'min' => 0.0,
                'max' => 1.0
            ],
            'map_resource_amount_min' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'map_resource_amount_max' => [
                'type' => 'int',
                'min' => 0,
                'max' => 2000000000
            ],
            'map_resource_weight_bright' => [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000
            ],
            'map_resource_weight_warm' => [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000
            ],
            'map_resource_weight_cold' => [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000
            ],
            'map_resource_weight_green' => [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000
            ],
            'map_resource_weight_day' => [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000
            ],
            'map_resource_weight_night' => [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000
            ],
            'map_npc_fort_tile_ratio' => [
                'type' => 'float',
                'min' => 0.0,
                'max' => 1.0
            ],
            'victory_condition_days' => ['type' => 'int', 'min' => 1, 'max' => 365],
            'vassal_release_resource_rate' => ['type' => 'float', 'min' => 0.0, 'max' => 1.0],
            'vassal_release_relocation_mode' => [
                'type' => 'enum',
                'values' => ['outer', 'middle', 'subbase']
            ],
            'vassal_release_lose_all_territory' => ['type' => 'bool'],
            'vassal_release_refund_circuit' => ['type' => 'bool'],
            'image_display_mode' => [
                'type' => 'enum',
                'values' => ['image', 'emoji_fallback']
            ]
        ];
        for ($level = 1; $level <= 9; $level++) {
            $validationRules['map_npc_fort_weight_level_' . $level] = [
                'type' => 'int',
                'min' => 0,
                'max' => 1000000
            ];
        }
        
        if (!isset($validationRules[$key])) {
            return true; // 没有验证规则的配置默认通过
        }
        
        $rule = $validationRules[$key];
        
        // 类型验证
        switch ($rule['type']) {
            case 'int':
                if (!is_numeric($value) || intval($value) != $value) {
                    return false;
                }
                $value = intval($value);
                break;
            case 'float':
                if (!is_numeric($value)) {
                    return false;
                }
                $value = floatval($value);
                break;
            case 'bool':
                if (!in_array(strtolower((string) $value), ['0', '1', 'true', 'false'], true)) {
                    return false;
                }
                break;
            case 'enum':
                if (!in_array((string) $value, $rule['values'], true)) {
                    return false;
                }
                break;
        }
        
        // 范围验证
        if (isset($rule['min']) && $value < $rule['min']) {
            return false;
        }
        if (isset($rule['max']) && $value > $rule['max']) {
            return false;
        }
        
        return true;
    }
}

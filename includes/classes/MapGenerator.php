<?php
// 种火集结号 - 地图生成器类 / Fireseed Engage - map generator

class MapGenerator {
    private $db;
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 生成新地图 / Generate a new map
     * @param bool $clearExisting 是否清除现有地图 / Whether to replace an existing map
     * @return bool|string 成功返回true，失败返回错误信息 / True on success or an error message
     */
    public function generateMap($clearExisting = false) {
        $this->db->begin_transaction();

        try {
            $query = "SELECT COUNT(*) AS count FROM map_tiles";
            $result = executePreparedSql($this->db, $query);
            if (!$result) {
                throw new RuntimeException(
                    '无法检查现有地图 / Failed to inspect the existing map'
                );
            }
            $row = $result->fetch_assoc();
            $hasExistingMap = $row && (int) $row['count'] > 0;
            if ($hasExistingMap && !$clearExisting) {
                $this->db->rollback();
                return '地图已存在，请先清除现有地图或设置clearExisting参数为true';
            }
            if ($hasExistingMap) {
                // 删除与重建必须处于同一事务，任何生成失败都会恢复旧世界。
                // Deletion and regeneration share one transaction so every
                // generation failure restores the former world.
                $this->clearExistingWorld();
            } else {
                $this->assertNoExistingCities();
            }

            $this->generateWorldContents();
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            return '生成地图失败: ' . $e->getMessage();
        }
    }

    /**
     * 在调用者事务中重建整个世界 / Regenerate the world inside the caller's transaction
     *
     * 调用者负责开始、提交和回滚事务。本方法绝不自行提交，因此可安全地与赛季
     * 资源、城池及进度重置保持原子性。
     * The caller owns begin, commit, and rollback. This method never commits,
     * allowing season resources, cities, progress, and world replacement to
     * remain atomic.
     *
     * @return void
     */
    public function regenerateMapInCurrentTransaction() {
        $this->clearExistingWorld();
        $this->generateWorldContents();
    }

    /**
     * 生成世界内容 / Generate all world contents
     * @return void
     */
    private function generateWorldContents() {
        $this->generateEmptyTiles();
        $this->generateResourcePoints();
        $this->generateNpcForts();
        $this->generateSpecialPoints();
    }

    /**
     * 清除现有世界 / Clear the existing world
     * @return void
     */
    private function clearExistingWorld() {
        $this->assertNoExistingCities();
        $this->deleteWorldSitesIfPresent();
        if (!executePreparedSql($this->db, "DELETE FROM map_tiles")) {
            throw new RuntimeException(
                '清除现有地图失败 / Failed to clear the existing map'
            );
        }
    }

    /**
     * 拒绝会孤立现有城池的地图替换 / Reject a world replacement that would orphan cities
     * @return void
     */
    private function assertNoExistingCities() {
        // 城池以坐标关联地图而非外键；先拒绝会制造孤儿城池的独立清图。
        // Cities reference the map by coordinates rather than a foreign key,
        // so reject standalone clears that would orphan live cities.
        $result = executePreparedSql(
            $this->db,
            "SELECT COUNT(*) AS count FROM cities"
        );
        if (!$result) {
            throw new RuntimeException(
                '无法检查现有城池 / Failed to inspect existing cities'
            );
        }
        $row = $result->fetch_assoc();
        if ($row && (int) $row['count'] > 0) {
            throw new RuntimeException(
                '存在玩家城池，必须通过赛季重建替换世界 / Player cities exist; rebuild the world through the season lifecycle'
            );
        }
    }

    /**
     * 生成空地 / Generate empty tiles
     * @return void
     */
    private function generateEmptyTiles() {
        // 新世界从第一刻起全图可见。 / A new world is visible in full from its first moment.
        $query = "INSERT INTO map_tiles (x, y, type, is_visible) VALUES ";
        $values = [];
        
        for ($x = 0; $x < MAP_WIDTH; $x++) {
            for ($y = 0; $y < MAP_HEIGHT; $y++) {
                $values[] = "($x, $y, 'empty', 1)";
                
                // 每1000个格子执行一次插入，避免插入语句过长。 / Insert in chunks to bound statement size.
                if (count($values) >= 1000) {
                    $insertQuery = $query . implode(',', $values);
                    if (!executePreparedSql($this->db, $insertQuery)) {
                        throw new RuntimeException(
                            '生成空地图格失败 / Failed to generate empty tiles'
                        );
                    }
                    $values = [];
                }
            }
        }
        
        // 插入剩余的格子。 / Insert the final partial chunk.
        if (!empty($values)) {
            $insertQuery = $query . implode(',', $values);
            if (!executePreparedSql($this->db, $insertQuery)) {
                throw new RuntimeException(
                    '生成空地图格失败 / Failed to generate empty tiles'
                );
            }
        }
    }
    
    /**
     * 生成资源点 / Generate resource points
     * @return void
     */
    private function generateResourcePoints() {
        // 亮、夜是可跨赛季积累的稀有资源，默认权重明显低于四种赛季资源。
        // Bright and night persist across seasons, so their default node
        // weights are intentionally much lower than the four seasonal types.
        $defaultWeights = [
            'bright' => 4,
            'warm' => 23,
            'cold' => 23,
            'green' => 23,
            'day' => 23,
            'night' => 4
        ];
        $weights = [];
        foreach ($defaultWeights as $type => $defaultWeight) {
            $weights[$type] = max(
                0,
                min(
                    1000000,
                    (int) $this->readNumericConfig(
                        'map_resource_weight_' . $type,
                        $defaultWeight
                    )
                )
            );
        }

        // 资源点约占地图的一半；权重只负责六系之间的份额。 / Nodes cover half the map; weights split that total among types.
        $totalResourcePoints = (int) floor((MAP_WIDTH * MAP_HEIGHT) * 0.50);
        $quotas = self::calculateWeightedQuotas(
            $totalResourcePoints,
            $weights
        );

        foreach ($quotas as $type => $quota) {
            if ($quota <= 0) {
                continue;
            }
            $escapedType = $this->db->real_escape_string($type);
            $query = "UPDATE map_tiles
                      SET type = 'resource', subtype = '$escapedType',
                          resource_amount = FLOOR(5000 + RAND() * 5001)
                      WHERE type = 'empty'
                      ORDER BY RAND()
                      LIMIT $quota";
            if (!executePreparedSql($this->db, $query)) {
                throw new RuntimeException('生成资源点失败 / Failed to generate resource points');
            }
        }
    }

    /**
     * 按非负权重完整分配整数配额 / Allocate an integer total across non-negative weights
     * @param int $total 总配额 / Total quota
     * @param array $weights 名称到权重 / Name-to-weight map
     * @return array 名称到整数配额 / Name-to-integer quota map
     */
    public static function calculateWeightedQuotas($total, $weights) {
        $total = max(0, (int) $total);
        $normalized = [];
        foreach ((array) $weights as $name => $weight) {
            $normalized[(string) $name] = max(0.0, (float) $weight);
        }
        if (empty($normalized)) {
            return [];
        }

        $weightTotal = array_sum($normalized);
        if ($weightTotal <= 0) {
            foreach ($normalized as $name => $weight) {
                $normalized[$name] = 1.0;
            }
            $weightTotal = (float) count($normalized);
        }

        $quotas = [];
        $fractions = [];
        $assigned = 0;
        foreach ($normalized as $name => $weight) {
            $exact = $total * $weight / $weightTotal;
            $quota = (int) floor($exact);
            $quotas[$name] = $quota;
            $fractions[$name] = $exact - $quota;
            $assigned += $quota;
        }

        // 最大余数法保证配额和精确等于总数，并以名称稳定打破平局。
        // The largest-remainder method preserves the exact total and uses
        // names as a deterministic tie breaker.
        $names = array_keys($normalized);
        usort($names, function($left, $right) use ($fractions) {
            if ($fractions[$left] === $fractions[$right]) {
                return strcmp($left, $right);
            }
            return $fractions[$left] < $fractions[$right] ? 1 : -1;
        });
        $remaining = $total - $assigned;
        for ($index = 0; $index < $remaining; $index++) {
            $name = $names[$index % count($names)];
            $quotas[$name]++;
        }

        return $quotas;
    }

    /**
     * 读取数值配置 / Read a numeric configuration value
     * @param string $key 配置键 / Configuration key
     * @param int|float $default 默认值 / Default value
     * @return int|float 数值 / Numeric value
     */
    private function readNumericConfig($key, $default) {
        $query = "SELECT `value` FROM game_config WHERE `key` = ?";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException(
                '无法读取地图配置 / Failed to prepare map configuration'
            );
        }
        $stmt->bind_param('s', $key);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法读取地图配置 / Failed to read map configuration'
            );
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row && is_numeric($row['value'])
            ? $row['value'] + 0
            : $default;
    }
    
    /**
     * 生成NPC城池
     */
    private function generateNpcForts() {
        // NPC据点约占地图四分之一 / NPC forts cover about one quarter of the map
        $npcFortCount = (int) floor((MAP_WIDTH * MAP_HEIGHT) * 0.25);

        // 九级据点以低等级为主 / Nine fort levels are weighted toward lower levels
        $levelDistribution = [
            1 => 0.27,
            2 => 0.20,
            3 => 0.15,
            4 => 0.12,
            5 => 0.09,
            6 => 0.07,
            7 => 0.05,
            8 => 0.03,
            9 => 0.02
        ];

        $assigned = 0;
        foreach ($levelDistribution as $level => $probability) {
            $quota = $level === 9
                ? $npcFortCount - $assigned
                : (int) floor($npcFortCount * $probability);
            $assigned += $quota;
            $garrison = (int) round(
                NPC_FORT_BASE_GARRISON * pow(NPC_FORT_GARRISON_COEFFICIENT, $level - 1)
            );
            $query = "UPDATE map_tiles
                      SET type = 'npc_fort', subtype = 'data_fort',
                          npc_level = $level, npc_garrison = $garrison
                      WHERE type = 'empty'
                      ORDER BY RAND()
                      LIMIT $quota";
            if (!executePreparedSql($this->db, $query)) {
                throw new RuntimeException('生成NPC据点失败 / Failed to generate NPC forts');
            }
        }
    }
    
    /**
     * 生成特殊地点
     */
    private function generateSpecialPoints() {
        // 银白之孔固定在地图中央 / Place the Silver Hole at the map center
        $this->upsertSpecialTile(
            MAP_CENTER_X,
            MAP_CENTER_Y,
            'silver_hole',
            'silver_hole',
            '银白之孔',
            1000000000,
            1000000000
        );

        // 十二门以本企划既有命名围绕中心分布 / Place the Twelve Gateways using the local fiction names
        $gateways = [
            ['minjing', '明京 Minjing'],
            ['ninghai', '宁海 Ninghai'],
            ['wuyue', '五岳 Wuyue'],
            ['luhai', '陆合 Luhai'],
            ['misawa', '米萨瓦 Misawa'],
            ['kanata', '卡拉塔 Kanata'],
            ['yozora', '约左拉 Yozora'],
            ['naomi', '娜奥美 Naomi'],
            ['minster', '明斯特尔 Minster'],
            ['elise', '艾尔利斯 Elise'],
            ['redknife', '雷德奈芙 Redknife'],
            ['caeperra', '开里培拉 Caeperra']
        ];
        $radius = (int) floor(min(MAP_WIDTH, MAP_HEIGHT) * 0.35);
        foreach ($gateways as $index => $gateway) {
            // 数组依次对应1点至12点，3点方向为数学零度 / Entries map from one to twelve o'clock, with three o'clock at zero radians
            $clockHour = $index + 1;
            $angle = deg2rad(($clockHour - 3) * 30);
            $x = (int) round(MAP_CENTER_X + cos($angle) * $radius);
            $y = (int) round(MAP_CENTER_Y + sin($angle) * $radius);
            $garrison = (int) round(
                NPC_FORT_BASE_GARRISON * pow(NPC_FORT_GARRISON_COEFFICIENT, 9)
            );
            $durability = (int) round(
                NPC_FORT_BASE_DURABILITY * pow(NPC_FORT_LEVEL_COEFFICIENT, 9)
            );
            $this->upsertSpecialTile(
                $x,
                $y,
                'gateway',
                'gateway_' . $gateway[0],
                $gateway[1],
                $durability,
                $garrison
            );
        }
    }

    /**
     * 写入特殊地点及其赛季状态 / Store a special tile and its season state
     * @param int $x X坐标 / X coordinate
     * @param int $y Y坐标 / Y coordinate
     * @param string $siteType 地点类型 / Site type
     * @param string $siteCode 地点代码 / Site code
     * @param string $displayName 显示名称 / Display name
     * @param int $durability 耐久 / Durability
     * @param int $garrison 驻军 / Garrison
     * @return void
     */
    private function upsertSpecialTile($x, $y, $siteType, $siteCode, $displayName, $durability, $garrison) {
        $tileType = $siteType === 'silver_hole' ? 'special' : 'npc_fort';
        $tileSubtype = $siteType === 'gateway' ? $siteCode : $siteType;
        $npcLevel = $siteType === 'gateway' ? 10 : null;
        $query = "UPDATE map_tiles
                  SET type = ?, subtype = ?, owner_id = NULL, resource_amount = NULL,
                      npc_level = ?, npc_garrison = ?
                  WHERE x = ? AND y = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'ssiiii',
            $tileType,
            $tileSubtype,
            $npcLevel,
            $garrison,
            $x,
            $y
        );
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('写入特殊地点失败 / Failed to store special tile');
        }
        $stmt->close();

        if (!$this->worldSitesTableExists()) {
            return;
        }

        $query = "SELECT tile_id FROM map_tiles WHERE x = ? AND y = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $x, $y);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('找不到特殊地点格子 / Special tile was not found');
        }

        $tileId = (int) $row['tile_id'];
        $query = "INSERT INTO world_sites
                    (tile_id, site_code, site_type, display_name, max_durability, durability)
                  VALUES (?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                    tile_id = VALUES(tile_id), display_name = VALUES(display_name),
                    max_durability = VALUES(max_durability), durability = VALUES(durability),
                    owner_id = NULL, captured_at = NULL, occupation_started_at = NULL";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'isssii',
            $tileId,
            $siteCode,
            $siteType,
            $displayName,
            $durability,
            $durability
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('写入世界地点状态失败 / Failed to store world-site state');
        }
        $stmt->close();
    }

    /**
     * 检查扩展地点表是否存在 / Check whether the expansion site table exists
     * @return bool 是否存在 / Whether the table exists
     */
    private function worldSitesTableExists() {
        $result = executePreparedSql(
            $this->db,
            "SHOW TABLES LIKE 'world_sites'"
        );
        if ($result === false) {
            throw new RuntimeException(
                '无法检查世界地点表 / Failed to inspect the world-sites table'
            );
        }
        return $result && $result->num_rows > 0;
    }

    /**
     * 在清图前删除扩展地点 / Delete expansion sites before clearing tiles
     * @return void
     */
    private function deleteWorldSitesIfPresent() {
        if ($this->worldSitesTableExists()
            && !executePreparedSql($this->db, "DELETE FROM world_sites")) {
            throw new RuntimeException(
                '清除世界地点失败 / Failed to clear world sites'
            );
        }
    }
    
    /**
     * 根据分布概率获取随机等级
     * @param array $distribution 分布概率数组，格式为 [level => probability]
     * @return int 随机等级
     */
    private function getRandomLevelByDistribution($distribution) {
        $rand = mt_rand() / mt_getrandmax(); // 0-1之间的随机数
        $cumulativeProbability = 0;
        
        foreach ($distribution as $level => $probability) {
            $cumulativeProbability += $probability;
            
            if ($rand <= $cumulativeProbability) {
                return $level;
            }
        }
        
        // 默认返回最低等级
        return min(array_keys($distribution));
    }
    
    /**
     * 重置地图
     * @return bool 是否成功
     */
    public function resetMap() {
        $this->db->begin_transaction();
        try {
            $this->clearExistingWorld();
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Map reset failed: ' . $exception->getMessage());
            return false;
        }
    }
    
    /**
     * 获取地图统计信息
     * @return array 统计信息
     */
    public function getMapStatistics() {
        $statistics = [
            'total_tiles' => 0,
            'empty_tiles' => 0,
            'resource_points' => [
                'total' => 0,
                'bright' => 0,
                'warm' => 0,
                'cold' => 0,
                'green' => 0,
                'day' => 0,
                'night' => 0
            ],
            'npc_forts' => [
                'total' => 0,
                'level_1' => 0,
                'level_2' => 0,
                'level_3' => 0,
                'level_4' => 0,
                'level_5' => 0,
                'level_6' => 0,
                'level_7' => 0,
                'level_8' => 0,
                'level_9' => 0,
                'level_10' => 0
            ],
            'player_cities' => 0,
            'special_points' => [
                'total' => 0,
                'silver_hole' => 0
            ]
        ];
        
        // 获取总格子数
        $query = "SELECT COUNT(*) as count FROM map_tiles";
        $result = executePreparedSql($this->db, $query);
        $row = $result->fetch_assoc();
        $statistics['total_tiles'] = $row['count'];
        
        // 获取空地数量
        $query = "SELECT COUNT(*) as count FROM map_tiles WHERE type = 'empty'";
        $result = executePreparedSql($this->db, $query);
        $row = $result->fetch_assoc();
        $statistics['empty_tiles'] = $row['count'];
        
        // 获取资源点数量
        $query = "SELECT COUNT(*) as count FROM map_tiles WHERE type = 'resource'";
        $result = executePreparedSql($this->db, $query);
        $row = $result->fetch_assoc();
        $statistics['resource_points']['total'] = $row['count'];
        
        // 获取各类型资源点数量
        $resourceTypes = ['bright', 'warm', 'cold', 'green', 'day', 'night'];
        foreach ($resourceTypes as $type) {
            $query = "SELECT COUNT(*) as count FROM map_tiles WHERE type = 'resource' AND subtype = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('s', $type);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $statistics['resource_points'][$type] = $row['count'];
            $stmt->close();
        }
        
        // 获取NPC城池数量
        $query = "SELECT COUNT(*) as count FROM map_tiles WHERE type = 'npc_fort'";
        $result = executePreparedSql($this->db, $query);
        $row = $result->fetch_assoc();
        $statistics['npc_forts']['total'] = $row['count'];
        
        // 获取各等级NPC城池数量
        for ($level = 1; $level <= 10; $level++) {
            $query = "SELECT COUNT(*) as count FROM map_tiles WHERE type = 'npc_fort' AND npc_level = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $level);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $statistics['npc_forts']['level_' . $level] = $row['count'];
            $stmt->close();
        }
        
        // 获取玩家城池数量
        $query = "SELECT COUNT(*) as count FROM map_tiles WHERE type = 'player_city'";
        $result = executePreparedSql($this->db, $query);
        $row = $result->fetch_assoc();
        $statistics['player_cities'] = $row['count'];
        
        // 获取特殊地点数量
        $query = "SELECT COUNT(*) as count FROM map_tiles WHERE type = 'special'";
        $result = executePreparedSql($this->db, $query);
        $row = $result->fetch_assoc();
        $statistics['special_points']['total'] = $row['count'];
        
        // 获取银白之孔数量
        $query = "SELECT COUNT(*) as count FROM map_tiles WHERE type = 'special' AND subtype = 'silver_hole'";
        $result = executePreparedSql($this->db, $query);
        $row = $result->fetch_assoc();
        $statistics['special_points']['silver_hole'] = $row['count'];
        
        return $statistics;
    }
}

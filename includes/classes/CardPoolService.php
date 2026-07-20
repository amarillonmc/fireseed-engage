<?php
// 种火集结号 - 武将与技能卡池服务 / Fireseed Engage - General and skill-card pool service

/**
 * 管理已发布卡池、抽取权重、成本与历史快照所需的运行时数据
 * Manages published pools, draw weights, costs, and runtime data used by history snapshots
 */
class CardPoolService {
    private const POOL_TYPES = ['general', 'skill'];
    private const RESOURCE_COST_KEYS = [
        'bright' => 'bright_crystal',
        'warm' => 'warm_crystal',
        'cold' => 'cold_crystal',
        'green' => 'green_crystal',
        'day' => 'day_crystal',
        'night' => 'night_crystal'
    ];
    private const WALLET_COST_KEYS = [
        'skill_points' => 'skill_points',
        'merit_points' => 'merit_points',
        'arena_tokens' => 'arena_tokens'
    ];
    private const LEGACY_POOL_CODES = [
        'general' => [
            'normal' => 'general_normal',
            'advanced' => 'general_advanced',
            'resonance' => 'general_resonance'
        ],
        'skill' => [
            'default' => 'skill_standard',
            'standard' => 'skill_standard'
        ]
    ];

    private $db;

    /**
     * 创建卡池服务 / Creates the card-pool service
     *
     * @param mixed $db 可选数据库连接 / Optional database connection
     */
    public function __construct($db = null) {
        $this->db = $db ?: Database::getInstance()->getConnection();
    }

    /**
     * 获取当前开放且配置有效的卡池 / Gets currently open pools with valid configurations
     *
     * @param string $poolType 卡池类型 / Pool type
     * @return array 结构化卡池结果 / Structured pool result
     */
    public function getAvailablePools($poolType): array {
        $normalizedType = self::normalizePoolType($poolType);

        try {
            $query = "SELECT pool_id, pool_code, pool_type, name, description,
                             cost_json, allowed_counts_json, status, starts_at,
                             ends_at, sort_order, revision, created_at, updated_at,
                             NOW() AS database_now
                      FROM card_pools
                      WHERE pool_type = ?
                        AND status = 'published'
                        AND (starts_at IS NULL OR starts_at <= NOW())
                        AND (ends_at IS NULL OR ends_at > NOW())
                      ORDER BY sort_order, pool_id";
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new RuntimeException(
                    '无法读取开放卡池 / Unable to read available pools'
                );
            }

            $stmt->bind_param('s', $normalizedType);
            $this->executeOrFail(
                $stmt,
                '无法读取开放卡池 / Unable to read available pools'
            );
            $result = $stmt->get_result();
            $rows = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $rows[] = $row;
            }
            $stmt->close();

            $pools = [];
            $warnings = [];
            foreach ($rows as $row) {
                try {
                    $entries = $this->loadPoolEntries(
                        (int) $row['pool_id'],
                        $normalizedType,
                        false
                    );
                    $pools[] = $this->normalizePoolRow($row, $entries);
                } catch (DomainException | InvalidArgumentException $exception) {
                    // 配置失效的已发布池不应静默改变概率 / A published pool with invalid resources must not silently change odds
                    $warnings[] = sprintf(
                        '%s：%s',
                        (string) $row['name'],
                        $exception->getMessage()
                    );
                }
            }

            return [
                'success' => true,
                'message' => empty($warnings)
                    ? '开放卡池读取成功 / Available pools loaded'
                    : '部分卡池因配置失效而暂停 / Some pools are unavailable because their configuration is invalid',
                'pools' => $pools,
                'warnings' => $warnings
            ];
        } catch (Throwable $exception) {
            error_log(
                'CardPoolService::getAvailablePools failed: '
                . $exception->getMessage()
            );

            return [
                'success' => false,
                'message' => '开放卡池读取失败 / Failed to load available pools',
                'pools' => [],
                'warnings' => []
            ];
        }
    }

    /**
     * 在抽取事务中锁定并校验一个卡池 / Locks and validates one pool inside a draw transaction
     *
     * @param string $poolType 卡池类型 / Pool type
     * @param mixed $identifier 卡池ID、代码或旧渠道名 / Pool ID, code, or legacy channel name
     * @param int $count 抽取次数 / Draw count
     * @return array 卡池与成员快照 / Pool and entry snapshot
     */
    public function lockPoolForDraw($poolType, $identifier, $count): array {
        $normalizedType = self::normalizePoolType($poolType);
        $normalizedCount = self::normalizeDrawCount($count);
        $resolvedIdentifier = self::resolvePoolIdentifier(
            $normalizedType,
            $identifier
        );

        if (is_int($resolvedIdentifier)) {
            $query = "SELECT pool_id, pool_code, pool_type, name, description,
                             cost_json, allowed_counts_json, status, starts_at,
                             ends_at, sort_order, revision, created_at, updated_at,
                             NOW() AS database_now
                      FROM card_pools
                      WHERE pool_id = ? AND pool_type = ?
                      LIMIT 1 FOR UPDATE";
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new RuntimeException(
                    '无法锁定卡池 / Unable to lock the card pool'
                );
            }
            $stmt->bind_param('is', $resolvedIdentifier, $normalizedType);
        } else {
            $query = "SELECT pool_id, pool_code, pool_type, name, description,
                             cost_json, allowed_counts_json, status, starts_at,
                             ends_at, sort_order, revision, created_at, updated_at,
                             NOW() AS database_now
                      FROM card_pools
                      WHERE pool_code = ? AND pool_type = ?
                      LIMIT 1 FOR UPDATE";
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new RuntimeException(
                    '无法锁定卡池 / Unable to lock the card pool'
                );
            }
            $stmt->bind_param('ss', $resolvedIdentifier, $normalizedType);
        }

        $this->executeOrFail(
            $stmt,
            '无法锁定卡池 / Unable to lock the card pool'
        );
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new DomainException('卡池不存在 / Card pool does not exist');
        }
        if ((string) $row['status'] !== 'published') {
            throw new DomainException(
                '卡池尚未发布或已归档 / Card pool is not published'
            );
        }

        $databaseNow = (string) $row['database_now'];
        if (!empty($row['starts_at'])
            && (string) $row['starts_at'] > $databaseNow) {
            throw new DomainException(
                '卡池尚未开放 / Card pool has not opened yet'
            );
        }
        if (!empty($row['ends_at'])
            && (string) $row['ends_at'] <= $databaseNow) {
            throw new DomainException(
                '卡池已经关闭 / Card pool is closed'
            );
        }

        $allowedCounts = self::normalizeAllowedCounts(
            (string) $row['allowed_counts_json']
        );
        if (!in_array($normalizedCount, $allowedCounts, true)) {
            throw new DomainException(
                '该卡池不支持所选抽取次数 / This pool does not support the selected draw count'
            );
        }

        $entries = $this->loadPoolEntries(
            (int) $row['pool_id'],
            $normalizedType,
            true
        );

        return $this->normalizePoolRow($row, $entries);
    }

    /**
     * 按整数权重选择一个卡池成员 / Selects one pool entry using integer weights
     *
     * @param array $entries 卡池成员 / Pool entries
     * @param int|null $roll 可选确定性权重落点 / Optional deterministic weight roll
     * @return array 被选成员及权重快照 / Selected entry and weight snapshot
     */
    public static function selectWeightedEntry(array $entries, $roll = null): array {
        $totalWeight = 0;
        foreach ($entries as $entry) {
            $weight = isset($entry['weight']) && is_numeric($entry['weight'])
                ? (int) $entry['weight']
                : 0;
            if ($weight <= 0) {
                throw new InvalidArgumentException(
                    '卡池权重必须为正整数 / Pool weights must be positive integers'
                );
            }
            if ($totalWeight > PHP_INT_MAX - $weight) {
                throw new OverflowException(
                    '卡池总权重超出安全范围 / Total pool weight exceeds the safe range'
                );
            }
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0) {
            throw new DomainException(
                '卡池没有可抽取成员 / Card pool has no drawable entries'
            );
        }

        if ($roll === null) {
            $normalizedRoll = random_int(1, $totalWeight);
        } else {
            if (!is_numeric($roll)
                || (float) $roll !== (float) (int) $roll) {
                throw new InvalidArgumentException(
                    '权重落点必须是整数 / Weight roll must be an integer'
                );
            }
            $normalizedRoll = (int) $roll;
            if ($normalizedRoll < 1 || $normalizedRoll > $totalWeight) {
                throw new InvalidArgumentException(
                    '权重落点超出卡池范围 / Weight roll is outside the pool range'
                );
            }
        }

        $cumulative = 0;
        foreach ($entries as $entry) {
            $entryWeight = (int) $entry['weight'];
            $cumulative += $entryWeight;
            if ($normalizedRoll <= $cumulative) {
                $entry['entry_weight'] = $entryWeight;
                $entry['total_weight'] = $totalWeight;
                $entry['weight_roll'] = $normalizedRoll;
                return $entry;
            }
        }

        throw new LogicException(
            '无法根据卡池权重选择成员 / Unable to select a pool entry from its weights'
        );
    }

    /**
     * 标准化每次抽取成本 / Normalizes a per-draw cost bundle
     *
     * @param mixed $cost 成本数组或JSON / Cost array or JSON
     * @return array 标准化成本 / Normalized cost
     */
    public static function normalizeCostBundle($cost): array {
        if (is_string($cost)) {
            $trimmedCost = trim($cost);
            if ($trimmedCost === ''
                || substr($trimmedCost, 0, 1) !== '{'
                || substr($trimmedCost, -1) !== '}') {
                throw new InvalidArgumentException(
                    '卡池成本必须是JSON对象 / Pool cost must be a JSON object'
                );
            }
            $decoded = json_decode($trimmedCost, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(
                    '卡池成本必须是JSON对象 / Pool cost must be a JSON object'
                );
            }
            $cost = $decoded;
        }
        if (!is_array($cost)) {
            throw new InvalidArgumentException(
                '卡池成本必须是对象 / Pool cost must be an object'
            );
        }

        $validKeys = array_merge(
            array_keys(self::RESOURCE_COST_KEYS),
            ['circuit_points'],
            array_keys(self::WALLET_COST_KEYS)
        );
        $normalized = [];
        foreach ($cost as $key => $amount) {
            $normalizedKey = strtolower(trim((string) $key));
            if (!in_array($normalizedKey, $validKeys, true)) {
                throw new InvalidArgumentException(
                    '卡池成本包含不支持的资源 / Pool cost contains an unsupported resource'
                );
            }
            if (!is_numeric($amount)
                || (float) $amount !== (float) (int) $amount
                || (int) $amount < 0
                || (int) $amount > 2147483647) {
                throw new InvalidArgumentException(
                    '卡池成本必须是非负整数 / Pool costs must be non-negative integers'
                );
            }
            if ((int) $amount > 0) {
                $normalized[$normalizedKey] = (int) $amount;
            }
        }

        $ordered = [];
        foreach ($validKeys as $key) {
            if (isset($normalized[$key])) {
                $ordered[$key] = $normalized[$key];
            }
        }

        return $ordered;
    }

    /**
     * 按卡池类型执行增值货币边界 / Enforce value-currency boundaries by pool type
     * @param string $poolType 卡池类型 / Pool type
     * @param mixed $cost 成本数组或JSON / Cost array or JSON
     * @return array 标准化成本 / Normalized cost
     */
    public static function normalizePoolCostBundle($poolType, $cost): array {
        $normalizedType = self::normalizePoolType($poolType);
        $normalizedCost = self::normalizeCostBundle($cost);
        $allowedKey = $normalizedType === 'general' ? 'bright' : 'night';

        if (count($normalizedCost) !== 1
            || !isset($normalizedCost[$allowedKey])
            || (int) $normalizedCost[$allowedKey] <= 0) {
            throw new InvalidArgumentException(
                $normalizedType === 'general'
                    ? '武将卡池只能消耗亮晶晶 / General pools may consume only Bright Crystals'
                    : '技能卡池只能消耗夜静静 / Skill pools may consume only Night Crystals'
            );
        }

        return $normalizedCost;
    }

    /**
     * 标准化卡池允许的抽取次数 / Normalizes allowed draw counts
     *
     * @param mixed $counts 次数数组或JSON / Count array or JSON
     * @return array 正序唯一次数 / Sorted unique counts
     */
    public static function normalizeAllowedCounts($counts): array {
        if (is_string($counts)) {
            $decoded = json_decode($counts, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(
                    '允许次数必须是JSON数组 / Allowed counts must be a JSON array'
                );
            }
            $counts = $decoded;
        }
        if (!is_array($counts) || empty($counts)) {
            throw new InvalidArgumentException(
                '卡池至少需要一种抽取次数 / A pool needs at least one draw count'
            );
        }

        $normalized = [];
        foreach ($counts as $count) {
            if (!is_numeric($count)
                || (float) $count !== (float) (int) $count
                || (int) $count < 1
                || (int) $count > 100) {
                throw new InvalidArgumentException(
                    '抽取次数必须是1至100的整数 / Draw counts must be integers from 1 to 100'
                );
            }
            $normalized[(int) $count] = (int) $count;
        }
        ksort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }

    /**
     * 计算多次抽取的总成本 / Calculates the total cost for multiple draws
     *
     * @param array $unitCost 每次成本 / Per-draw cost
     * @param int $count 抽取次数 / Draw count
     * @return array 总成本 / Total cost
     */
    public static function multiplyCost(array $unitCost, $count): array {
        $normalizedCost = self::normalizeCostBundle($unitCost);
        $normalizedCount = self::normalizeDrawCount($count);
        $total = [];

        foreach ($normalizedCost as $key => $amount) {
            if ($amount > intdiv(2147483647, $normalizedCount)) {
                throw new OverflowException(
                    '卡池总成本超出安全范围 / Total pool cost exceeds the safe range'
                );
            }
            $total[$key] = $amount * $normalizedCount;
        }

        return $total;
    }

    /**
     * 在调用者事务内锁定并扣除游戏内抽取成本 / Locks and consumes earned draw costs inside the caller transaction
     *
     * @param int $userId 玩家ID / User ID
     * @param array $unitCost 每次成本 / Per-draw cost
     * @param int $count 抽取次数 / Draw count
     * @return array 实际总成本 / Actual total cost
     */
    public function consumeCost($userId, array $unitCost, $count): array {
        $normalizedUserId = (int) $userId;
        if ($normalizedUserId <= 0) {
            throw new DomainException('玩家无效 / Invalid user');
        }

        $totalCost = self::multiplyCost($unitCost, $count);
        $query = "SELECT user_id, circuit_points
                  FROM users
                  WHERE user_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException(
                '无法锁定玩家成本 / Unable to lock user draw costs'
            );
        }
        $stmt->bind_param('i', $normalizedUserId);
        $this->executeOrFail(
            $stmt,
            '无法锁定玩家成本 / Unable to lock user draw costs'
        );
        $userResult = $stmt->get_result();
        $userRow = $userResult ? $userResult->fetch_assoc() : null;
        $stmt->close();
        if (!$userRow) {
            throw new DomainException('玩家不存在 / User does not exist');
        }
        if (($totalCost['circuit_points'] ?? 0)
            > (int) $userRow['circuit_points']) {
            throw new DomainException(
                '思考回路不足 / Insufficient circuit points'
            );
        }

        $resourceRow = null;
        if (self::bundleUsesKeys(
            $totalCost,
            array_keys(self::RESOURCE_COST_KEYS)
        )) {
            $query = "SELECT bright_crystal, warm_crystal, cold_crystal,
                             green_crystal, day_crystal, night_crystal
                      FROM resources
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new RuntimeException(
                    '无法锁定抽取资源 / Unable to lock draw resources'
                );
            }
            $stmt->bind_param('i', $normalizedUserId);
            $this->executeOrFail(
                $stmt,
                '无法锁定抽取资源 / Unable to lock draw resources'
            );
            $resourceResult = $stmt->get_result();
            $resourceRow = $resourceResult
                ? $resourceResult->fetch_assoc()
                : null;
            $stmt->close();
            if (!$resourceRow) {
                throw new DomainException(
                    '玩家资源记录不存在 / User resource record does not exist'
                );
            }
            foreach (self::RESOURCE_COST_KEYS as $costKey => $column) {
                if (($totalCost[$costKey] ?? 0) > (int) $resourceRow[$column]) {
                    throw new DomainException(
                        '抽取资源不足 / Insufficient draw resources'
                    );
                }
            }
        }

        $walletRow = null;
        if (self::bundleUsesKeys(
            $totalCost,
            array_keys(self::WALLET_COST_KEYS)
        )) {
            $query = "INSERT IGNORE INTO gameplay_wallets (user_id) VALUES (?)";
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new RuntimeException(
                    '无法初始化玩法钱包 / Unable to initialize gameplay wallet'
                );
            }
            $stmt->bind_param('i', $normalizedUserId);
            $this->executeOrFail(
                $stmt,
                '无法初始化玩法钱包 / Unable to initialize gameplay wallet'
            );
            $stmt->close();

            $query = "SELECT skill_points, merit_points, arena_tokens
                      FROM gameplay_wallets
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new RuntimeException(
                    '无法锁定玩法钱包 / Unable to lock gameplay wallet'
                );
            }
            $stmt->bind_param('i', $normalizedUserId);
            $this->executeOrFail(
                $stmt,
                '无法锁定玩法钱包 / Unable to lock gameplay wallet'
            );
            $walletResult = $stmt->get_result();
            $walletRow = $walletResult ? $walletResult->fetch_assoc() : null;
            $stmt->close();
            if (!$walletRow) {
                throw new RuntimeException(
                    '玩法钱包不存在 / Gameplay wallet does not exist'
                );
            }
            foreach (self::WALLET_COST_KEYS as $costKey => $column) {
                if (($totalCost[$costKey] ?? 0) > (int) $walletRow[$column]) {
                    throw new DomainException(
                        '玩法代币不足 / Insufficient gameplay currency'
                    );
                }
            }
        }

        if ($resourceRow !== null) {
            $query = "UPDATE resources
                      SET bright_crystal = bright_crystal - ?,
                          warm_crystal = warm_crystal - ?,
                          cold_crystal = cold_crystal - ?,
                          green_crystal = green_crystal - ?,
                          day_crystal = day_crystal - ?,
                          night_crystal = night_crystal - ?
                      WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new RuntimeException(
                    '无法扣除抽取资源 / Unable to consume draw resources'
                );
            }
            $bright = (int) ($totalCost['bright'] ?? 0);
            $warm = (int) ($totalCost['warm'] ?? 0);
            $cold = (int) ($totalCost['cold'] ?? 0);
            $green = (int) ($totalCost['green'] ?? 0);
            $day = (int) ($totalCost['day'] ?? 0);
            $night = (int) ($totalCost['night'] ?? 0);
            $stmt->bind_param(
                'iiiiiii',
                $bright,
                $warm,
                $cold,
                $green,
                $day,
                $night,
                $normalizedUserId
            );
            $this->executeOrFail(
                $stmt,
                '无法扣除抽取资源 / Unable to consume draw resources'
            );
            $stmt->close();
        }

        if (($totalCost['circuit_points'] ?? 0) > 0) {
            $query = "UPDATE users
                      SET circuit_points = circuit_points - ?
                      WHERE user_id = ? AND circuit_points >= ?";
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new RuntimeException(
                    '无法扣除思考回路 / Unable to consume circuit points'
                );
            }
            $circuitCost = (int) $totalCost['circuit_points'];
            $stmt->bind_param(
                'iii',
                $circuitCost,
                $normalizedUserId,
                $circuitCost
            );
            $this->executeOrFail(
                $stmt,
                '无法扣除思考回路 / Unable to consume circuit points'
            );
            $affectedRows = $stmt->affected_rows;
            $stmt->close();
            if ($affectedRows !== 1) {
                throw new DomainException(
                    '思考回路不足 / Insufficient circuit points'
                );
            }
        }

        if ($walletRow !== null) {
            $query = "UPDATE gameplay_wallets
                      SET skill_points = skill_points - ?,
                          merit_points = merit_points - ?,
                          arena_tokens = arena_tokens - ?
                      WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new RuntimeException(
                    '无法扣除玩法代币 / Unable to consume gameplay currency'
                );
            }
            $skillPoints = (int) ($totalCost['skill_points'] ?? 0);
            $meritPoints = (int) ($totalCost['merit_points'] ?? 0);
            $arenaTokens = (int) ($totalCost['arena_tokens'] ?? 0);
            $stmt->bind_param(
                'iiii',
                $skillPoints,
                $meritPoints,
                $arenaTokens,
                $normalizedUserId
            );
            $this->executeOrFail(
                $stmt,
                '无法扣除玩法代币 / Unable to consume gameplay currency'
            );
            $stmt->close();
        }

        return $totalCost;
    }

    /**
     * 将卡池代码映射为兼容的招募历史类型 / Maps a pool code to a compatible recruitment-history type
     *
     * @param string $poolCode 卡池代码 / Pool code
     * @return string 历史类型 / History type
     */
    public static function getRecruitmentHistoryType($poolCode): string {
        $normalizedCode = strtolower(trim((string) $poolCode));
        $mapping = [
            'general_normal' => 'normal',
            'general_advanced' => 'advanced',
            'general_resonance' => 'resonance'
        ];

        return $mapping[$normalizedCode] ?? 'pool';
    }

    /**
     * 读取并验证卡池成员 / Loads and validates pool entries
     *
     * @param int $poolId 卡池ID / Pool ID
     * @param string $poolType 卡池类型 / Pool type
     * @param bool $forUpdate 是否锁定成员和目录行 / Whether to lock entries and catalog rows
     * @return array 有效成员 / Valid entries
     */
    private function loadPoolEntries($poolId, $poolType, $forUpdate): array {
        if ($poolType === 'general') {
            $query = "SELECT entry.general_id AS resource_id, entry.weight,
                             entry.is_featured, g.general_id,
                             catalog.template_code,
                             (SELECT gs.skill_name
                                FROM general_skills gs
                               WHERE gs.general_id = g.general_id
                               ORDER BY gs.slot, gs.skill_id
                               LIMIT 1) AS skill_name,
                             g.owner_id, g.name, g.source,
                             g.rarity, g.cost, g.element,
                             g.level, g.hp, g.max_hp,
                             g.attack, g.defense, g.speed,
                             g.intelligence, g.is_active
                      FROM general_pool_entries entry
                      LEFT JOIN generals g
                        ON g.general_id = entry.general_id
                      LEFT JOIN general_template_catalog catalog
                        ON catalog.general_id = g.general_id
                      WHERE entry.pool_id = ?
                      ORDER BY entry.is_featured DESC,
                               FIELD(g.rarity, 'P', 'SS', 'S', 'A', 'B'),
                               g.name, entry.general_id";
        } else {
            $query = "SELECT entry.card_id AS resource_id, entry.weight,
                             entry.is_featured, card.card_id, card.card_code,
                             card.name, card.description, card.rarity,
                             card.element, card.activation_type, card.category,
                             card.effect_json, card.base_cooldown,
                             card.max_level, card.is_active
                      FROM skill_pool_entries entry
                      LEFT JOIN skill_card_catalog card
                        ON card.card_id = entry.card_id
                      WHERE entry.pool_id = ?
                      ORDER BY entry.is_featured DESC,
                               FIELD(card.rarity, 'P', 'SS', 'S', 'A', 'B'),
                               card.name, entry.card_id";
        }
        if ($forUpdate) {
            $query .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException(
                '无法读取卡池成员 / Unable to read pool entries'
            );
        }
        $stmt->bind_param('i', $poolId);
        $this->executeOrFail(
            $stmt,
            '无法读取卡池成员 / Unable to read pool entries'
        );
        $result = $stmt->get_result();
        $entries = [];
        $totalWeight = 0;

        while ($result && ($row = $result->fetch_assoc())) {
            $weight = (int) $row['weight'];
            if ($weight <= 0) {
                $stmt->close();
                throw new DomainException(
                    '卡池存在非正权重 / Pool contains a non-positive weight'
                );
            }
            if (empty($row['resource_id'])
                || (int) $row['is_active'] !== 1
                || ($poolType === 'general'
                    && ((int) $row['owner_id'] !== 0
                        || empty($row['template_code'])))) {
                $stmt->close();
                throw new DomainException(
                    '卡池包含不存在、停用、非公共或未登记模板代码的资源'
                    . ' / Pool contains a missing, inactive, non-public,'
                    . ' or uncatalogued resource'
                );
            }
            if ($totalWeight > PHP_INT_MAX - $weight) {
                $stmt->close();
                throw new DomainException(
                    '卡池总权重超出安全范围 / Total pool weight exceeds the safe range'
                );
            }

            $row['resource_id'] = (int) $row['resource_id'];
            $row['weight'] = $weight;
            $row['is_featured'] = (int) $row['is_featured'];
            if ($poolType === 'general') {
                foreach ([
                    'general_id',
                    'owner_id',
                    'level',
                    'hp',
                    'max_hp',
                    'attack',
                    'defense',
                    'speed',
                    'intelligence',
                    'is_active'
                ] as $field) {
                    $row[$field] = (int) $row[$field];
                }
                $row['cost'] = (float) $row['cost'];
                $row['template_code'] = (string) $row['template_code'];
                $row['skill_name'] = isset($row['skill_name'])
                    ? (string) $row['skill_name']
                    : '';
            } else {
                foreach ([
                    'card_id',
                    'base_cooldown',
                    'max_level',
                    'is_active'
                ] as $field) {
                    $row[$field] = (int) $row[$field];
                }
            }

            $totalWeight += $weight;
            $entries[] = $row;
        }
        $stmt->close();

        if (empty($entries)) {
            throw new DomainException(
                '卡池没有可抽取成员 / Card pool has no drawable entries'
            );
        }

        foreach ($entries as &$entry) {
            $entry['probability'] = round(
                $entry['weight'] * 100 / $totalWeight,
                6
            );
        }
        unset($entry);

        return $entries;
    }

    /**
     * 标准化卡池行并生成公开概率 / Normalizes a pool row and builds published probabilities
     *
     * @param array $row 卡池行 / Pool row
     * @param array $entries 卡池成员 / Pool entries
     * @return array 标准化卡池 / Normalized pool
     */
    private function normalizePoolRow(array $row, array $entries): array {
        $totalWeight = 0;
        $rarityWeights = [];
        foreach ($entries as $entry) {
            $weight = (int) $entry['weight'];
            $totalWeight += $weight;
            $rarity = (string) $entry['rarity'];
            $rarityWeights[$rarity] = ($rarityWeights[$rarity] ?? 0) + $weight;
        }

        $rarityProbabilities = [];
        foreach (['B', 'A', 'S', 'SS', 'P'] as $rarity) {
            if (!empty($rarityWeights[$rarity])) {
                $rarityProbabilities[$rarity] = round(
                    $rarityWeights[$rarity] * 100 / $totalWeight,
                    6
                );
            }
        }

        return [
            'pool_id' => (int) $row['pool_id'],
            'pool_code' => (string) $row['pool_code'],
            'pool_type' => (string) $row['pool_type'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'cost' => self::normalizePoolCostBundle(
                (string) $row['pool_type'],
                (string) $row['cost_json']
            ),
            'allowed_counts' => self::normalizeAllowedCounts(
                (string) $row['allowed_counts_json']
            ),
            'status' => (string) $row['status'],
            'starts_at' => $row['starts_at'],
            'ends_at' => $row['ends_at'],
            'sort_order' => (int) $row['sort_order'],
            'revision' => (int) $row['revision'],
            'total_weight' => $totalWeight,
            'rarity_probabilities' => $rarityProbabilities,
            'entries' => $entries
        ];
    }

    /**
     * 标准化卡池类型 / Normalizes a pool type
     *
     * @param mixed $poolType 卡池类型 / Pool type
     * @return string 标准化类型 / Normalized type
     */
    private static function normalizePoolType($poolType): string {
        $normalizedType = strtolower(trim((string) $poolType));
        if (!in_array($normalizedType, self::POOL_TYPES, true)) {
            throw new InvalidArgumentException(
                '卡池类型无效 / Invalid pool type'
            );
        }

        return $normalizedType;
    }

    /**
     * 标准化一次抽取数量 / Normalizes one draw count
     *
     * @param mixed $count 抽取数量 / Draw count
     * @return int 标准化数量 / Normalized count
     */
    private static function normalizeDrawCount($count): int {
        if (!is_numeric($count)
            || (float) $count !== (float) (int) $count
            || (int) $count < 1
            || (int) $count > 100) {
            throw new InvalidArgumentException(
                '抽取次数必须是1至100的整数 / Draw count must be an integer from 1 to 100'
            );
        }

        return (int) $count;
    }

    /**
     * 解析卡池ID、代码与旧渠道别名 / Resolves a pool ID, code, or legacy channel alias
     *
     * @param string $poolType 卡池类型 / Pool type
     * @param mixed $identifier 原始标识 / Raw identifier
     * @return int|string 已解析标识 / Resolved identifier
     */
    private static function resolvePoolIdentifier($poolType, $identifier) {
        if (is_int($identifier)
            || (is_string($identifier)
                && preg_match('/^[1-9][0-9]*$/D', $identifier))) {
            $poolId = (int) $identifier;
            if ($poolId <= 0) {
                throw new InvalidArgumentException(
                    '卡池ID无效 / Invalid pool ID'
                );
            }
            return $poolId;
        }

        $poolCode = strtolower(trim((string) $identifier));
        if (isset(self::LEGACY_POOL_CODES[$poolType][$poolCode])) {
            $poolCode = self::LEGACY_POOL_CODES[$poolType][$poolCode];
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $poolCode)) {
            throw new InvalidArgumentException(
                '卡池代码无效 / Invalid pool code'
            );
        }

        return $poolCode;
    }

    /**
     * 判断成本包是否使用任一指定键 / Checks whether a cost bundle uses any specified key
     *
     * @param array $bundle 成本包 / Cost bundle
     * @param array $keys 待检查键 / Keys to inspect
     * @return bool 是否使用 / Whether any key is used
     */
    private static function bundleUsesKeys(array $bundle, array $keys): bool {
        foreach ($keys as $key) {
            if (($bundle[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 执行预处理语句或抛出异常 / Executes a prepared statement or throws
     *
     * @param mixed $stmt 预处理语句 / Prepared statement
     * @param string $message 失败信息 / Failure message
     * @return void
     */
    private function executeOrFail($stmt, $message): void {
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($message . ': ' . $error);
        }
    }
}

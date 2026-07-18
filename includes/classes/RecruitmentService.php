<?php
// 种火集结号 - 武将招募服务 / Fireseed Engage - General recruitment service

/**
 * 管理公共武将池、初始选择与非付费招募 / Manages the public general pool, starter choices, and non-paid recruitment
 */
class RecruitmentService {
    private const STARTER_LIMIT = 5;
    private const RARITIES = ['B', 'A', 'S', 'SS', 'P'];
    private const DUPLICATE_SKILL_POINTS = [
        'B' => 1,
        'A' => 2,
        'S' => 5,
        'SS' => 10,
        'P' => 20
    ];

    private $db;
    private $cardPoolService;

    /**
     * 创建武将招募服务 / Creates the general recruitment service
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->cardPoolService = new CardPoolService($this->db);
    }

    /**
     * 获取启用中的公共武将模板 / Gets active public general templates
     *
     * @param string|null $rarity 可选稀有度筛选 / Optional rarity filter
     * @return array 结构化武将池结果 / Structured general-pool result
     */
    public function getPool($rarity = null): array {
        $normalizedRarity = $rarity === null
            ? null
            : strtoupper(trim((string) $rarity));

        if ($normalizedRarity !== null
            && !in_array($normalizedRarity, self::RARITIES, true)) {
            return $this->result(
                false,
                '稀有度无效 / Invalid rarity'
            );
        }

        try {
            $query = "SELECT g.general_id, catalog.template_code,
                             (SELECT gs.skill_name
                                FROM general_skills gs
                               WHERE gs.general_id = g.general_id
                               ORDER BY gs.slot, gs.skill_id
                               LIMIT 1) AS skill_name,
                             g.name, g.source, g.rarity,
                             g.cost, g.element, g.level,
                             g.hp, g.max_hp, g.attack,
                             g.defense, g.speed,
                             g.intelligence, g.is_active
                      FROM generals g
                      INNER JOIN general_template_catalog catalog
                        ON catalog.general_id = g.general_id
                      WHERE g.owner_id = 0
                        AND g.is_active = 1";

            if ($normalizedRarity !== null) {
                $query .= " AND g.rarity = ?";
            }

            $query .= " ORDER BY FIELD(g.rarity, 'P', 'SS', 'S', 'A', 'B'),
                                g.name, g.general_id";
            $stmt = $this->db->prepare($query);

            if (!$stmt) {
                throw new RuntimeException('无法读取公共武将池 / Unable to read the public general pool');
            }

            if ($normalizedRarity !== null) {
                $stmt->bind_param('s', $normalizedRarity);
            }

            $this->executeOrFail(
                $stmt,
                '无法读取公共武将池 / Unable to read the public general pool'
            );
            $result = $stmt->get_result();
            $generals = [];

            while ($result && ($row = $result->fetch_assoc())) {
                $generals[] = $this->normalizeGeneralRow($row);
            }

            $stmt->close();

            return $this->result(
                true,
                '公共武将池读取成功 / Public general pool loaded',
                $generals
            );
        } catch (Throwable $e) {
            error_log('RecruitmentService::getPool failed: ' . $e->getMessage());

            return $this->result(
                false,
                '公共武将池读取失败 / Failed to load the public general pool'
            );
        }
    }

    /**
     * 获取当前开放的武将卡池 / Gets currently open general pools
     *
     * @return array 结构化卡池结果 / Structured pool result
     */
    public function getDrawPools(): array {
        return $this->cardPoolService->getAvailablePools('general');
    }

    /**
     * 获取玩家剩余的初始武将选择次数 / Gets a player's remaining starter-general choices
     *
     * @param int $userId 玩家ID / User ID
     * @return int 剩余次数 / Remaining choices
     */
    public function starterRemaining($userId): int {
        $normalizedUserId = (int) $userId;

        if ($normalizedUserId <= 0) {
            return 0;
        }

        $query = "SELECT COUNT(*) AS choice_count
                  FROM recruitment_history
                  WHERE user_id = ? AND recruit_type = 'starter'";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $normalizedUserId);

        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $used = $row ? (int) $row['choice_count'] : self::STARTER_LIMIT;

        return max(0, self::STARTER_LIMIT - $used);
    }

    /**
     * 免费选择一名未重复的初始武将模板 / Selects one non-duplicate starter template for free
     *
     * @param int $userId 玩家ID / User ID
     * @param int $templateGeneralId 公共模板武将ID / Public template general ID
     * @return array 结构化选择结果 / Structured selection result
     */
    public function selectStarter($userId, $templateGeneralId): array {
        $normalizedUserId = (int) $userId;
        $normalizedTemplateId = (int) $templateGeneralId;

        if ($normalizedUserId <= 0 || $normalizedTemplateId <= 0) {
            return $this->result(
                false,
                '玩家或模板武将无效 / Invalid user or general template'
            );
        }

        $transactionStarted = false;

        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockUser($normalizedUserId);

            if ($this->starterRemainingLocked($normalizedUserId) <= 0) {
                throw new DomainException(
                    '初始武将选择次数已用完 / No starter choices remain'
                );
            }

            if ($this->hasStarterTemplateLocked(
                $normalizedUserId,
                $normalizedTemplateId
            )) {
                throw new DomainException(
                    '同一初始武将模板不能重复选择 / The same starter template cannot be selected twice'
                );
            }

            $template = $this->getTemplate($normalizedTemplateId);

            if ($template === null) {
                throw new DomainException(
                    '公共武将模板不存在或未启用 / Public general template does not exist or is inactive'
                );
            }
            if (!in_array(
                (string) $template['rarity'],
                ['S', 'SS', 'P'],
                true
            )) {
                throw new DomainException(
                    '初始选择仅限S、SS或P级模板 / Starter choices are limited to S, SS, or P templates'
                );
            }
            if ($this->getOwnedGeneralForTemplateLocked(
                $normalizedUserId,
                $normalizedTemplateId
            ) !== null) {
                throw new DomainException(
                    '你已通过其他方式获得该模板，请选择另一名初始武将'
                    . ' / You already own this template; choose a different starter'
                );
            }

            $general = $this->cloneTemplate(
                $normalizedUserId,
                $template,
                'starter'
            );
            $this->commitTransaction();
            $transactionStarted = false;

            return $this->result(
                true,
                '初始武将选择成功 / Starter general selected',
                [$general],
                [
                    'starter_remaining' => max(
                        0,
                        $this->starterRemaining($normalizedUserId)
                    )
                ]
            );
        } catch (DomainException $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            return $this->result(false, $e->getMessage());
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            error_log('RecruitmentService::selectStarter failed: ' . $e->getMessage());

            return $this->result(
                false,
                '初始武将选择失败 / Failed to select a starter general'
            );
        }
    }

    /**
     * 使用游戏内资源进行武将招募 / Recruits generals with earned in-game resources
     *
     * @param int $userId 玩家ID / User ID
     * @param mixed $poolIdentifier 卡池ID、代码或旧渠道名 / Pool ID, code, or legacy channel name
     * @param int $count 招募次数 / Draw count
     * @return array 结构化招募结果 / Structured recruitment result
     */
    public function recruit($userId, $poolIdentifier, $count = 1): array {
        $normalizedUserId = (int) $userId;
        $normalizedCount = is_numeric($count) ? (int) $count : 0;

        if ($normalizedUserId <= 0) {
            return $this->result(false, '玩家无效 / Invalid user');
        }

        if (!is_numeric($count)
            || (float) $count !== (float) $normalizedCount
            || $normalizedCount < 1
            || $normalizedCount > 100) {
            return $this->result(
                false,
                '招募次数无效 / Invalid recruitment count'
            );
        }

        $transactionStarted = false;

        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockUser($normalizedUserId);
            $pool = $this->cardPoolService->lockPoolForDraw(
                'general',
                $poolIdentifier,
                $normalizedCount
            );
            $cost = $this->cardPoolService->consumeCost(
                $normalizedUserId,
                $pool['cost'],
                $normalizedCount
            );
            $recruitType = CardPoolService::getRecruitmentHistoryType(
                $pool['pool_code']
            );

            $generals = [];

            for ($index = 0; $index < $normalizedCount; $index++) {
                $selectedEntry = CardPoolService::selectWeightedEntry(
                    $pool['entries']
                );
                $template = $selectedEntry;

                $ownedGeneral = $this->getOwnedGeneralForTemplateLocked(
                    $normalizedUserId,
                    (int) $template['general_id']
                );
                if ($ownedGeneral !== null) {
                    $general = $this->convertDuplicateTemplate(
                        $normalizedUserId,
                        $template,
                        $ownedGeneral,
                        $recruitType,
                        $pool,
                        $selectedEntry
                    );
                } else {
                    $general = $this->cloneTemplate(
                        $normalizedUserId,
                        $template,
                        $recruitType,
                        $pool,
                        $selectedEntry
                    );
                    $general['duplicate'] = false;
                    $general['duplicate_skill_points'] = 0;
                }
                // 保留旧返回字段以兼容现有页面 / Preserve the legacy response field for existing pages
                $general['rolled_rarity'] = (string) $template['rarity'];
                $generals[] = $general;
            }

            $this->commitTransaction();
            $transactionStarted = false;

            return $this->result(
                true,
                '武将招募成功 / General recruitment successful',
                $generals,
                [
                    'recruit_type' => $recruitType,
                    'count' => $normalizedCount,
                    'cost' => $cost,
                    'pool' => [
                        'pool_id' => (int) $pool['pool_id'],
                        'pool_code' => (string) $pool['pool_code'],
                        'name' => (string) $pool['name'],
                        'revision' => (int) $pool['revision']
                    ]
                ]
            );
        } catch (DomainException | InvalidArgumentException $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            return $this->result(false, $e->getMessage());
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            error_log('RecruitmentService::recruit failed: ' . $e->getMessage());

            return $this->result(
                false,
                '武将招募失败，未扣除任何资源 / Recruitment failed and no resources were consumed'
            );
        }
    }

    /**
     * 开始事务并验证结果 / Starts a transaction and validates the result
     */
    private function beginTransaction(): void {
        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('无法开始事务 / Unable to start transaction');
        }
    }

    /**
     * 提交事务并验证结果 / Commits a transaction and validates the result
     */
    private function commitTransaction(): void {
        if (!$this->db->commit()) {
            throw new RuntimeException('无法提交事务 / Unable to commit transaction');
        }
    }

    /**
     * 锁定并验证玩家 / Locks and validates a user
     *
     * @param int $userId 玩家ID / User ID
     * @return array 玩家行 / User row
     */
    private function lockUser($userId): array {
        $query = "SELECT user_id, circuit_points
                  FROM users
                  WHERE user_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法锁定玩家 / Unable to lock user');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail($stmt, '无法锁定玩家 / Unable to lock user');
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new DomainException('玩家不存在 / User does not exist');
        }

        return $row;
    }

    /**
     * 在玩家锁保护下计算剩余初始选择次数 / Calculates remaining starter choices while the user lock is held
     *
     * @param int $userId 玩家ID / User ID
     * @return int 剩余次数 / Remaining choices
     */
    private function starterRemainingLocked($userId): int {
        $query = "SELECT COUNT(*) AS choice_count
                  FROM recruitment_history
                  WHERE user_id = ? AND recruit_type = 'starter'";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException(
                '无法读取初始武将选择记录 / Unable to read starter-choice history'
            );
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail(
            $stmt,
            '无法读取初始武将选择记录 / Unable to read starter-choice history'
        );
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $used = $row ? (int) $row['choice_count'] : self::STARTER_LIMIT;

        return max(0, self::STARTER_LIMIT - $used);
    }

    /**
     * 检查初始模板是否已经被玩家选择 / Checks whether a starter template was already selected by the user
     *
     * @param int $userId 玩家ID / User ID
     * @param int $templateGeneralId 模板武将ID / Template general ID
     * @return bool 是否已选择 / Whether it was already selected
     */
    private function hasStarterTemplateLocked($userId, $templateGeneralId): bool {
        $query = "SELECT recruitment_id
                  FROM recruitment_history
                  WHERE user_id = ?
                    AND template_general_id = ?
                    AND recruit_type = 'starter'
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException(
                '无法检查初始武将选择记录 / Unable to check starter-choice history'
            );
        }

        $stmt->bind_param('ii', $userId, $templateGeneralId);
        $this->executeOrFail(
            $stmt,
            '无法检查初始武将选择记录 / Unable to check starter-choice history'
        );
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * 读取指定公共模板 / Reads a public template
     *
     * @param int $templateGeneralId 模板武将ID / Template general ID
     * @return array|null 模板行 / Template row
     */
    private function getTemplate($templateGeneralId) {
        $query = "SELECT g.general_id, catalog.template_code,
                         (SELECT gs.skill_name
                            FROM general_skills gs
                           WHERE gs.general_id = g.general_id
                           ORDER BY gs.slot, gs.skill_id
                           LIMIT 1) AS skill_name,
                         g.name, g.source, g.rarity,
                         g.cost, g.element, g.level,
                         g.hp, g.max_hp, g.attack,
                         g.defense, g.speed,
                         g.intelligence, g.is_active
                  FROM generals g
                  INNER JOIN general_template_catalog catalog
                    ON catalog.general_id = g.general_id
                  WHERE g.general_id = ?
                    AND g.owner_id = 0
                    AND g.is_active = 1";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法读取武将模板 / Unable to read general template');
        }

        $stmt->bind_param('i', $templateGeneralId);
        $this->executeOrFail(
            $stmt,
            '无法读取武将模板 / Unable to read general template'
        );
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    /**
     * 查找玩家已拥有的同模板武将并锁定记录 / Find and lock a general already owned from the same template
     * @param int $userId 玩家ID / User ID
     * @param int $templateGeneralId 模板武将ID / Template general ID
     * @return array|null 已拥有武将或空值 / Existing general or null
     */
    private function getOwnedGeneralForTemplateLocked(
        $userId,
        $templateGeneralId
    ) {
        $query = "SELECT g.general_id, g.name, g.source, g.rarity, g.cost,
                         g.element, g.level, g.hp, g.max_hp, g.attack,
                         g.defense, g.speed, g.intelligence
                  FROM recruitment_history history
                  INNER JOIN generals g
                    ON g.general_id = history.general_id
                   AND g.owner_id = ?
                  WHERE history.user_id = ?
                    AND history.template_general_id = ?
                  ORDER BY history.recruitment_id
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException(
                '无法检查重复武将 / Unable to check duplicate generals'
            );
        }
        $stmt->bind_param('iii', $userId, $userId, $templateGeneralId);
        $this->executeOrFail(
            $stmt,
            '无法检查重复武将 / Unable to check duplicate generals'
        );
        $result = $stmt->get_result();
        $general = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $general ?: null;
    }

    /**
     * 将重复模板转化为技能点并保留抽取历史 / Convert a duplicate template into skill points and preserve draw history
     * @param int $userId 玩家ID / User ID
     * @param array $template 模板数据 / Template data
     * @param array $ownedGeneral 已拥有武将 / Existing general
     * @param string $recruitType 招募类型 / Recruitment type
     * @param array|null $pool 卡池快照 / Pool snapshot
     * @param array|null $poolEntry 成员权重快照 / Entry weight snapshot
     * @return array 转化结果 / Conversion result
     */
    private function convertDuplicateTemplate(
        $userId,
        $template,
        $ownedGeneral,
        $recruitType,
        $pool = null,
        $poolEntry = null
    ) {
        $rarity = (string) $template['rarity'];
        $skillPoints = isset(self::DUPLICATE_SKILL_POINTS[$rarity])
            ? self::DUPLICATE_SKILL_POINTS[$rarity]
            : 1;
        $query = "INSERT INTO gameplay_wallets (user_id, skill_points)
                  VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE
                    skill_points = LEAST(
                      2147483647,
                      skill_points + VALUES(skill_points)
                    )";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException(
                '无法转化重复武将 / Unable to convert duplicate general'
            );
        }
        $stmt->bind_param('ii', $userId, $skillPoints);
        $this->executeOrFail(
            $stmt,
            '无法转化重复武将 / Unable to convert duplicate general'
        );
        $stmt->close();

        $generalId = (int) $ownedGeneral['general_id'];
        $this->recordRecruitment(
            $userId,
            (int) $template['general_id'],
            $generalId,
            $recruitType,
            $rarity,
            $pool,
            $poolEntry
        );
        $this->recordGameplayEvent(
            $userId,
            'general_recruited',
            1,
            'general',
            $generalId
        );
        $this->recordGameplayEvent(
            $userId,
            'general_duplicate_converted',
            $skillPoints,
            'general',
            $generalId
        );

        $ownedGeneral['template_general_id'] = (int) $template['general_id'];
        $ownedGeneral['template_code'] = (string) $template['template_code'];
        $ownedGeneral['skill_name'] = isset($template['skill_name'])
            ? (string) $template['skill_name']
            : '';
        $ownedGeneral['recruit_type'] = $recruitType;
        $ownedGeneral['duplicate'] = true;
        $ownedGeneral['duplicate_skill_points'] = $skillPoints;
        return $ownedGeneral;
    }

    /**
     * 克隆模板、固有技能并记录招募与事件 / Clones a template and inherent skills, then records recruitment and its event
     *
     * @param int $userId 玩家ID / User ID
     * @param array $template 模板行 / Template row
     * @param string $recruitType 招募类型 / Recruitment type
     * @param array|null $pool 卡池快照 / Pool snapshot
     * @param array|null $poolEntry 成员权重快照 / Entry weight snapshot
     * @return array 新武将数据 / New general data
     */
    private function cloneTemplate(
        $userId,
        array $template,
        $recruitType,
        $pool = null,
        $poolEntry = null
    ): array {
        $query = "INSERT INTO generals
                    (owner_id, name, source, rarity, cost, element, level, hp,
                     max_hp, attack, defense, speed, intelligence, is_active)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法克隆武将模板 / Unable to clone general template');
        }

        $name = (string) $template['name'];
        $source = (string) $template['source'];
        $rarity = (string) $template['rarity'];
        $cost = (float) $template['cost'];
        $element = (string) $template['element'];
        $level = max(1, (int) $template['level']);
        $maxHp = max(1, (int) $template['max_hp']);
        $hp = $maxHp;
        $attack = max(0, (int) $template['attack']);
        $defense = max(0, (int) $template['defense']);
        $speed = max(0, (int) $template['speed']);
        $intelligence = max(0, (int) $template['intelligence']);
        $isActive = 1;
        $stmt->bind_param(
            'isssdsiiiiiiii',
            $userId,
            $name,
            $source,
            $rarity,
            $cost,
            $element,
            $level,
            $hp,
            $maxHp,
            $attack,
            $defense,
            $speed,
            $intelligence,
            $isActive
        );
        $this->executeOrFail(
            $stmt,
            '无法克隆武将模板 / Unable to clone general template'
        );
        $generalId = (int) $this->db->insert_id;
        $stmt->close();

        if ($generalId <= 0) {
            throw new RuntimeException('克隆武将模板未返回ID / Cloned general did not return an ID');
        }

        $this->copyInherentSkills(
            (int) $template['general_id'],
            $generalId
        );
        $this->recordRecruitment(
            $userId,
            (int) $template['general_id'],
            $generalId,
            $recruitType,
            $rarity,
            $pool,
            $poolEntry
        );
        $this->recordGameplayEvent(
            $userId,
            'general_recruited',
            1,
            'general',
            $generalId
        );

        return [
            'general_id' => $generalId,
            'template_general_id' => (int) $template['general_id'],
            'template_code' => (string) $template['template_code'],
            'name' => $name,
            'source' => $source,
            'rarity' => $rarity,
            'cost' => $cost,
            'element' => $element,
            'level' => $level,
            'hp' => $hp,
            'max_hp' => $maxHp,
            'attack' => $attack,
            'defense' => $defense,
            'speed' => $speed,
            'intelligence' => $intelligence,
            'skill_name' => isset($template['skill_name'])
                ? (string) $template['skill_name']
                : '',
            'recruit_type' => $recruitType
        ];
    }

    /**
     * 复制模板的零号槽固有技能 / Copies slot-zero inherent skills from a template
     *
     * @param int $templateGeneralId 模板武将ID / Template general ID
     * @param int $generalId 新武将ID / New general ID
     */
    private function copyInherentSkills($templateGeneralId, $generalId): void {
        $query = "SELECT gs.skill_type, gs.skill_name, gs.slot,
                         gs.skill_level, gs.skill_effect, esc.card_id
                  FROM general_skills gs
                  LEFT JOIN equipped_skill_cards esc
                    ON esc.skill_id = gs.skill_id
                  WHERE gs.general_id = ? AND gs.slot = 0
                  ORDER BY gs.skill_id";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法读取固有技能 / Unable to read inherent skills');
        }

        $stmt->bind_param('i', $templateGeneralId);
        $this->executeOrFail(
            $stmt,
            '无法读取固有技能 / Unable to read inherent skills'
        );
        $result = $stmt->get_result();
        $skills = [];

        while ($result && ($row = $result->fetch_assoc())) {
            $skills[] = $row;
        }

        $stmt->close();

        foreach ($skills as $skill) {
            $insert = "INSERT INTO general_skills
                         (general_id, skill_type, skill_name, slot, skill_level, skill_effect)
                       VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($insert);

            if (!$stmt) {
                throw new RuntimeException('无法复制固有技能 / Unable to copy inherent skill');
            }

            $skillType = (string) $skill['skill_type'];
            $skillName = (string) $skill['skill_name'];
            $slot = 0;
            $skillLevel = max(1, (int) $skill['skill_level']);
            $skillEffect = (string) $skill['skill_effect'];
            $stmt->bind_param(
                'issiis',
                $generalId,
                $skillType,
                $skillName,
                $slot,
                $skillLevel,
                $skillEffect
            );
            $this->executeOrFail(
                $stmt,
                '无法复制固有技能 / Unable to copy inherent skill'
            );
            $newSkillId = (int) $this->db->insert_id;
            $stmt->close();

            if (!empty($skill['card_id'])) {
                $mapping = "INSERT INTO equipped_skill_cards (skill_id, card_id)
                            VALUES (?, ?)";
                $stmt = $this->db->prepare($mapping);
                if (!$stmt) {
                    throw new RuntimeException(
                        '无法映射固有技能卡 / Unable to map inherent skill card'
                    );
                }
                $cardId = (int) $skill['card_id'];
                $stmt->bind_param('ii', $newSkillId, $cardId);
                $this->executeOrFail(
                    $stmt,
                    '无法映射固有技能卡 / Unable to map inherent skill card'
                );
                $stmt->close();
            }
        }
    }

    /**
     * 写入招募历史 / Writes recruitment history
     *
     * @param int $userId 玩家ID / User ID
     * @param int $templateGeneralId 模板武将ID / Template general ID
     * @param int $generalId 新武将ID / New general ID
     * @param string $recruitType 招募类型 / Recruitment type
     * @param string $rarity 实际稀有度 / Actual rarity
     * @param array|null $pool 卡池快照 / Pool snapshot
     * @param array|null $poolEntry 成员权重快照 / Entry weight snapshot
     */
    private function recordRecruitment(
        $userId,
        $templateGeneralId,
        $generalId,
        $recruitType,
        $rarity,
        $pool = null,
        $poolEntry = null
    ): void {
        $query = "INSERT INTO recruitment_history
                    (user_id, template_general_id, general_id, recruit_type,
                     rarity, pool_id, pool_code_snapshot, pool_revision,
                     entry_weight, total_weight, cost_json)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法记录招募历史 / Unable to record recruitment history');
        }

        $poolId = is_array($pool) ? (int) $pool['pool_id'] : null;
        $poolCode = is_array($pool) ? (string) $pool['pool_code'] : null;
        $poolRevision = is_array($pool) ? (int) $pool['revision'] : null;
        $entryWeight = is_array($poolEntry)
            ? (int) $poolEntry['entry_weight']
            : null;
        $totalWeight = is_array($poolEntry)
            ? (int) $poolEntry['total_weight']
            : null;
        $costJson = is_array($pool)
            ? json_encode(
                $pool['cost'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
            : null;
        if (is_array($pool) && $costJson === false) {
            $stmt->close();
            throw new RuntimeException(
                '无法记录卡池成本快照 / Unable to encode pool cost snapshot'
            );
        }

        $stmt->bind_param(
            'iiissisiiis',
            $userId,
            $templateGeneralId,
            $generalId,
            $recruitType,
            $rarity,
            $poolId,
            $poolCode,
            $poolRevision,
            $entryWeight,
            $totalWeight,
            $costJson
        );
        $this->executeOrFail(
            $stmt,
            '无法记录招募历史 / Unable to record recruitment history'
        );
        $stmt->close();
    }

    /**
     * 写入统一玩法事件 / Writes a unified gameplay event
     *
     * @param int $userId 玩家ID / User ID
     * @param string $eventType 事件类型 / Event type
     * @param int $eventValue 事件值 / Event value
     * @param string $referenceType 引用类型 / Reference type
     * @param int $referenceId 引用ID / Reference ID
     */
    private function recordGameplayEvent(
        $userId,
        $eventType,
        $eventValue,
        $referenceType,
        $referenceId
    ): void {
        $query = "INSERT INTO gameplay_events
                    (user_id, event_type, event_value, reference_type, reference_id)
                  VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法记录玩法事件 / Unable to record gameplay event');
        }

        $stmt->bind_param(
            'isisi',
            $userId,
            $eventType,
            $eventValue,
            $referenceType,
            $referenceId
        );
        $this->executeOrFail(
            $stmt,
            '无法记录玩法事件 / Unable to record gameplay event'
        );
        $stmt->close();
    }

    /**
     * 执行预处理语句或抛出异常 / Executes a prepared statement or throws
     *
     * @param mysqli_stmt $stmt 预处理语句 / Prepared statement
     * @param string $message 失败信息 / Failure message
     */
    private function executeOrFail($stmt, $message): void {
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($message . ': ' . $error);
        }
    }

    /**
     * 标准化武将池行的数据类型 / Normalizes data types in a general-pool row
     *
     * @param array $row 数据库行 / Database row
     * @return array 标准化行 / Normalized row
     */
    private function normalizeGeneralRow(array $row): array {
        $integerFields = [
            'general_id',
            'level',
            'hp',
            'max_hp',
            'attack',
            'defense',
            'speed',
            'intelligence',
            'is_active'
        ];

        foreach ($integerFields as $field) {
            $row[$field] = (int) $row[$field];
        }

        $row['cost'] = (float) $row['cost'];
        $row['template_code'] = (string) $row['template_code'];
        $row['skill_name'] = isset($row['skill_name'])
            ? (string) $row['skill_name']
            : '';

        return $row;
    }

    /**
     * 构建一致的服务结果 / Builds a consistent service result
     *
     * @param bool $success 是否成功 / Whether the operation succeeded
     * @param string $message 结果信息 / Result message
     * @param array $generals 武将列表 / General list
     * @param array $extra 附加字段 / Extra fields
     * @return array 结构化结果 / Structured result
     */
    private function result(
        $success,
        $message,
        array $generals = [],
        array $extra = []
    ): array {
        return array_merge(
            [
                'success' => (bool) $success,
                'message' => (string) $message,
                'generals' => $generals
            ],
            $extra
        );
    }
}

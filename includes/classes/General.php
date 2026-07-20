<?php
// 种火集结号 - 武将类

class General {
    private $db;
    private $generalId;
    private $ownerId;
    private $name;
    private $source;
    private $templateCode;
    private $rarity;
    private $cost;
    private $element;
    private $level;
    private $hp;
    private $maxHp;
    private $attack;
    private $defense;
    private $speed;
    private $intelligence;
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
        $query = "SELECT g.*,
                         COALESCE(
                           direct_catalog.template_code,
                           (
                             SELECT source_catalog.template_code
                             FROM recruitment_history history
                             INNER JOIN general_template_catalog source_catalog
                               ON source_catalog.general_id = history.template_general_id
                             WHERE history.general_id = g.general_id
                             ORDER BY history.recruitment_id
                             LIMIT 1
                           )
                         ) AS template_code
                  FROM generals g
                  LEFT JOIN general_template_catalog direct_catalog
                    ON direct_catalog.general_id = g.general_id
                  WHERE g.general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->generalId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $generalData = $result->fetch_assoc();
            $this->ownerId = $generalData['owner_id'];
            $this->name = $generalData['name'];
            $this->source = $generalData['source'];
            $this->templateCode = isset($generalData['template_code'])
                && trim((string) $generalData['template_code']) !== ''
                ? (string) $generalData['template_code']
                : null;
            $this->rarity = $generalData['rarity'];
            $this->cost = $generalData['cost'];
            $this->element = $generalData['element'];
            $this->level = $generalData['level'];
            $this->hp = $generalData['hp'];
            $this->maxHp = $generalData['max_hp'];
            $this->attack = $generalData['attack'];
            $this->defense = $generalData['defense'];
            $this->speed = $generalData['speed'];
            $this->intelligence = $generalData['intelligence'];
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
     * @param string $source 武将来源
     * @param string $rarity 武将稀有度
     * @param float $cost 武将COST
     * @param string $element 武将元素
     * @param array $attributes 武将属性
     * @return bool|int 成功返回武将ID，失败返回false
     */
    public function createGeneral($ownerId, $name, $source, $rarity, $cost, $element, $attributes) {
        // 检查参数
        if (empty($name) || empty($rarity) || empty($element) || empty($attributes)) {
            return false;
        }

        // 检查稀有度是否有效
        $validRarities = ['B', 'A', 'S', 'SS', 'P'];
        if (!in_array($rarity, $validRarities)) {
            return false;
        }

        // 检查元素是否有效
        $validElements = ['亮晶晶', '暖洋洋', '冷冰冰', '郁萌萌', '昼闪闪', '夜静静'];
        if (!in_array($element, $validElements)) {
            return false;
        }

        // 检查属性是否完整
        $requiredAttributes = ['attack', 'defense', 'speed', 'intelligence'];
        foreach ($requiredAttributes as $attr) {
            if (!isset($attributes[$attr])) {
                return false;
            }
        }

        // 创建武将记录
        $query = "INSERT INTO generals (owner_id, name, source, rarity, cost, element, hp, max_hp, attack, defense, speed, intelligence)
                 VALUES (?, ?, ?, ?, ?, ?, 100, 100, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isssdsiiiii', $ownerId, $name, $source, $rarity, $cost, $element,
                         $attributes['attack'], $attributes['defense'], $attributes['speed'], $attributes['intelligence']);
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
        $this->source = $source;
        $this->templateCode = null;
        $this->rarity = $rarity;
        $this->cost = $cost;
        $this->element = $element;
        $this->level = 1;
        $this->hp = 100;
        $this->maxHp = 100;
        $this->attack = $attributes['attack'];
        $this->defense = $attributes['defense'];
        $this->speed = $attributes['speed'];
        $this->intelligence = $attributes['intelligence'];
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

        // 增加等级
        $newLevel = $this->level + 1;

        // 计算新属性
        $newAttack = $this->calculateNewAttribute('attack');
        $newDefense = $this->calculateNewAttribute('defense');
        $newSpeed = $this->calculateNewAttribute('speed');
        $newIntelligence = $this->calculateNewAttribute('intelligence');

        // 更新数据库
        $query = "UPDATE generals SET level = ?, attack = ?, defense = ?, speed = ?, intelligence = ? WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iiiiii', $newLevel, $newAttack, $newDefense, $newSpeed, $newIntelligence, $this->generalId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            // 更新对象属性
            $this->level = $newLevel;
            $this->attack = $newAttack;
            $this->defense = $newDefense;
            $this->speed = $newSpeed;
            $this->intelligence = $newIntelligence;
            return true;
        }

        return false;
    }

    /**
     * 计算升级后的属性值
     * @param string $attribute 属性名称
     * @return int 新属性值
     */
    private function calculateNewAttribute($attribute) {
        return self::calculateLevelUpAttribute(
            $this->$attribute,
            $this->cost,
            $attribute === 'speed'
        );
    }

    /**
     * 以稳定的逐级成长率计算下一属性 / Calculate the next attribute with stable per-level growth
     * @param int $currentValue 当前属性 / Current attribute
     * @param float $cost 武将COST / General cost
     * @param bool $isSpeed 是否为速度 / Whether this is speed
     * @return int 下一属性 / Next attribute
     */
    public static function calculateLevelUpAttribute(
        $currentValue,
        $cost,
        $isSpeed = false
    ) {
        $safeValue = max(0, (int) $currentValue);
        $safeCost = min(4.0, max(0.0, (float) $cost));
        $growthRate = $isSpeed
            ? 0.01 + $safeCost * 0.0025
            : 0.02 + $safeCost * 0.005;
        $grownValue = max(
            $safeValue + 1,
            (int) round($safeValue * (1 + $growthRate))
        );
        $hardCap = defined('GENERAL_ATTRIBUTE_HARD_CAP')
            ? (int) GENERAL_ATTRIBUTE_HARD_CAP
            : 2000000000;

        return min($hardCap, $grownValue);
    }

    /**
     * 获取升级费用
     * @return int 升级费用
     */
    public function getUpgradeCost() {
        $baseCost = 100; // 基础升级费用
        $levelFactor = 1 + $this->level * 0.5;
        $costFactor = 1 + $this->cost * 0.5;

        return round($baseCost * $levelFactor * $costFactor);
    }

    /**
     * 添加技能卡牌
     * @param string $skillName 技能名称
     * @param int $slot 技能槽位（0为自带技能，1-2为装备技能）
     * @param array $skillEffect 技能效果
     * @return bool 是否成功
     */
    public function addSkillCard($skillName, $slot, $skillEffect) {
        if (!$this->isValid) {
            return false;
        }

        // 检查槽位是否有效
        if ($slot < 0 || $slot > 2) {
            return false;
        }

        // 检查槽位是否已有技能
        foreach ($this->skills as $skill) {
            if ($skill->getSlot() == $slot) {
                // 如果是自带技能槽（0），不允许替换
                if ($slot == 0) {
                    return false;
                }

                // 如果是装备技能槽（1-2），先移除旧技能
                $skill->removeSkill();
                break;
            }
        }

        // 创建新技能
        $skillType = ($slot == 0) ? '自带' : '装备';
        $skill = new GeneralSkill();
        $skillId = $skill->createSkill($this->generalId, $skillName, $skillType, $slot, $skillEffect);

        if (!$skillId) {
            return false;
        }

        // 添加到技能列表
        $this->skills[] = $skill;
        return true;
    }

    /**
     * 移除技能卡牌
     * @param int $slot 技能槽位
     * @return bool 是否成功
     */
    public function removeSkillCard($slot) {
        if (!$this->isValid) {
            return false;
        }

        // 自带技能不能移除
        if ($slot == 0) {
            return false;
        }

        // 查找技能
        foreach ($this->skills as $key => $skill) {
            if ($skill->getSlot() == $slot) {
                if ($skill->removeSkill()) {
                    unset($this->skills[$key]);
                    $this->skills = array_values($this->skills); // 重新索引数组
                    return true;
                }
                break;
            }
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

        // 检查分配类型是否有效 / Validate the assignment type
        $validTypes = ['city', 'army'];
        $targetId = (int) $targetId;
        if (!in_array($assignmentType, $validTypes, true) || $targetId <= 0) {
            return false;
        }

        if (!$this->db->begin_transaction()) {
            return false;
        }

        try {
            lockSeasonForWorldAction($this->db);

            // 玩家锁串行化同一账号的全部编制请求，并固定永久科研提供的COST上限 / The player lock serializes roster changes for one account and fixes the permanent-research COST cap
            $query = "SELECT max_general_cost
                      FROM users
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $ownerId = (int) $this->ownerId;
            $stmt->bind_param('i', $ownerId);
            $stmt->execute();
            $result = $stmt->get_result();
            $owner = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$owner) {
                throw new RuntimeException(
                    '武将所有者不存在 / General owner does not exist'
                );
            }

            if ($assignmentType === 'city') {
                $query = "SELECT owner_id, level
                          FROM cities
                          WHERE city_id = ?
                          FOR UPDATE";
            } else {
                $query = "SELECT owner_id, level
                          FROM armies
                          WHERE army_id = ?
                          FOR UPDATE";
            }
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $result = $stmt->get_result();
            $target = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$target || (int) $target['owner_id'] !== $ownerId) {
                throw new RuntimeException(
                    '编制目标已经失效或易主 / Assignment target is stale or changed owners'
                );
            }
            // 在目标之后锁内重验武将，和战斗的目标到武将锁序保持一致 / Revalidate and lock the general after the target to match combat's target-to-general order
            $query = "SELECT owner_id, cost, is_active
                      FROM generals
                      WHERE general_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $generalId = (int) $this->generalId;
            $stmt->bind_param('i', $generalId);
            $stmt->execute();
            $result = $stmt->get_result();
            $lockedGeneral = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$lockedGeneral
                || (int) $lockedGeneral['owner_id'] !== $ownerId
                || (int) $lockedGeneral['is_active'] !== 1) {
                throw new RuntimeException(
                    '武将状态已经变化 / General state has changed'
                );
            }

            $query = "SELECT assignment_id, assignment_type, target_id
                      FROM general_assignments
                      WHERE general_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $generalId);
            $stmt->execute();
            $result = $stmt->get_result();
            $existingAssignment = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($existingAssignment
                && $existingAssignment['assignment_type'] === $assignmentType
                && (int) $existingAssignment['target_id'] === $targetId) {
                self::enforceAssignmentLimitsInCurrentTransaction(
                    $assignmentType,
                    $targetId,
                    $ownerId,
                    'reject'
                );
                $assignmentId = (int) $existingAssignment['assignment_id'];
                if (!$this->db->commit()) {
                    throw new RuntimeException(
                        '提交武将编制失败 / Failed to commit general assignment'
                    );
                }
                $this->assignment = new GeneralAssignment($assignmentId);
                return true;
            }

            if ($existingAssignment) {
                $query = "DELETE FROM general_assignments
                          WHERE assignment_id = ? AND general_id = ?";
                $stmt = $this->db->prepare($query);
                $existingAssignmentId =
                    (int) $existingAssignment['assignment_id'];
                $stmt->bind_param(
                    'ii',
                    $existingAssignmentId,
                    $generalId
                );
                $deleted = $stmt->execute()
                    && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$deleted) {
                    throw new RuntimeException(
                        '旧武将编制已经变化 / Former general assignment changed'
                    );
                }
            }

            $query = "INSERT INTO general_assignments
                         (general_id, assignment_type, target_id)
                      VALUES (?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'isi',
                $generalId,
                $assignmentType,
                $targetId
            );
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException(
                    '创建武将编制失败 / Failed to create general assignment'
                );
            }
            $assignmentId = (int) $this->db->insert_id;
            $stmt->close();

            // 所有直接和批量转移路径共用同一编制上限裁决 / Direct and bulk transfer paths share one authoritative roster-limit decision
            self::enforceAssignmentLimitsInCurrentTransaction(
                $assignmentType,
                $targetId,
                $ownerId,
                'reject'
            );

            if (!$this->db->commit()) {
                throw new RuntimeException(
                    '提交武将编制失败 / Failed to commit general assignment'
                );
            }
            $this->assignment = new GeneralAssignment($assignmentId);
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log(
                'General assignment failed: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * 在现有事务内统一执行目标编制人数与COST上限 / Enforce target headcount and COST limits in the current transaction
     *
     * 调用者必须先按“赛季、玩家、目标”顺序取得必要锁。reject 模式让整个
     * 操作回滚；unassign 模式优先保留原有编制，并解除超限转入武将的分配。
     * The caller must already follow the season, player, and target lock order.
     * Reject mode aborts the operation; unassign mode preserves the existing
     * roster first and removes overflow transfers from assignment.
     *
     * @param string $assignmentType 分配类型 / Assignment type
     * @param int $targetId 目标ID / Target ID
     * @param int $ownerId 玩家ID / Owner ID
     * @param string $overflowMode reject或unassign / reject or unassign
     * @param array $preferredOverflowAssignmentIds 优先解除的转入分配ID / Transferred assignment IDs to remove first
     * @return array 被解除分配的武将ID / Unassigned general IDs
     */
    public static function enforceAssignmentLimitsInCurrentTransaction(
        $assignmentType,
        $targetId,
        $ownerId,
        $overflowMode = 'reject',
        $preferredOverflowAssignmentIds = []
    ) {
        if (!in_array($assignmentType, ['city', 'army'], true)
            || !in_array($overflowMode, ['reject', 'unassign'], true)
            || (int) $targetId <= 0
            || (int) $ownerId <= 0) {
            throw new InvalidArgumentException(
                '编制上限校验参数无效 / Invalid roster-limit arguments'
            );
        }

        $db = Database::getInstance()->getConnection();
        $targetId = (int) $targetId;
        $ownerId = (int) $ownerId;

        $query = "SELECT max_general_cost
                  FROM users
                  WHERE user_id = ?
                  FOR UPDATE";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $owner = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$owner) {
            throw new RuntimeException(
                '编制所属玩家不存在 / Roster owner does not exist'
            );
        }

        if ($assignmentType === 'city') {
            $query = "SELECT owner_id, level
                      FROM cities
                      WHERE city_id = ?
                      FOR UPDATE";
        } else {
            $query = "SELECT owner_id, level
                      FROM armies
                      WHERE army_id = ?
                      FOR UPDATE";
        }
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $target = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$target || (int) $target['owner_id'] !== $ownerId) {
            throw new RuntimeException(
                '编制目标已经失效或易主 / Roster target is stale or changed owners'
            );
        }

        $assignmentLimit = $assignmentType === 'city'
            ? max(0, (int) $target['level'])
            : max(0, 1 + (int) $target['level']);
        $costLimit = max(0.0, (float) $owner['max_general_cost']);

        $query = "SELECT ga.assignment_id, g.general_id, g.owner_id,
                         g.cost, g.is_active
                  FROM general_assignments AS ga
                  INNER JOIN generals AS g
                    ON g.general_id = ga.general_id
                  WHERE ga.assignment_type = ?
                    AND ga.target_id = ?
                  ORDER BY ga.assignment_id
                  FOR UPDATE";
        $stmt = $db->prepare($query);
        $stmt->bind_param('si', $assignmentType, $targetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $roster = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $roster[] = $row;
        }
        $stmt->close();

        $overflowAssignmentIds = self::calculateOverflowAssignmentIds(
            $roster,
            $assignmentLimit,
            $costLimit,
            $ownerId,
            $preferredOverflowAssignmentIds
        );
        $rosterByAssignmentId = [];
        foreach ($roster as $row) {
            $rosterByAssignmentId[(int) $row['assignment_id']] = $row;
        }

        if (empty($overflowAssignmentIds)) {
            return [];
        }
        if ($overflowMode === 'reject') {
            throw new RuntimeException(
                '目标编制超过武将人数或COST上限 / Target roster exceeds its headcount or COST limit'
            );
        }

        $unassignedGeneralIds = [];
        foreach ($overflowAssignmentIds as $assignmentId) {
            if (!isset($rosterByAssignmentId[$assignmentId])) {
                throw new RuntimeException(
                    '超限武将编制数据无效 / Invalid overflow roster data'
                );
            }
            $row = $rosterByAssignmentId[$assignmentId];
            $query = "DELETE FROM general_assignments
                      WHERE assignment_id = ?
                        AND assignment_type = ?
                        AND target_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param(
                'isi',
                $assignmentId,
                $assignmentType,
                $targetId
            );
            $deleted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$deleted) {
                throw new RuntimeException(
                    '解除超限武将分配失败 / Failed to unassign an overflow general'
                );
            }
            $unassignedGeneralIds[] = (int) $row['general_id'];
        }

        return $unassignedGeneralIds;
    }

    /**
     * 计算必须解除的超限分配 / Calculate assignments that must be removed for overflow
     * @param array $roster 编制行 / Roster rows
     * @param int $assignmentLimit 人数上限 / Headcount limit
     * @param float $costLimit COST上限 / COST limit
     * @param int $ownerId 玩家ID / Owner ID
     * @param array $preferredOverflowAssignmentIds 优先解除的转入分配 / Transfer assignments to remove first
     * @return array 超限分配ID / Overflow assignment IDs
     */
    public static function calculateOverflowAssignmentIds(
        $roster,
        $assignmentLimit,
        $costLimit,
        $ownerId,
        $preferredOverflowAssignmentIds = []
    ) {
        $assignmentLimit = max(0, (int) $assignmentLimit);
        $costLimit = max(0.0, (float) $costLimit);
        $ownerId = (int) $ownerId;
        $preferredOverflow = [];
        foreach ((array) $preferredOverflowAssignmentIds as $assignmentId) {
            $assignmentId = (int) $assignmentId;
            if ($assignmentId > 0) {
                $preferredOverflow[$assignmentId] = true;
            }
        }

        $orderedRoster = array_values((array) $roster);
        // 先接受原编制，再按分配ID接受新转入武将 / Accept the original roster first, then transfers by assignment ID
        usort(
            $orderedRoster,
            function ($left, $right) use ($preferredOverflow) {
                $leftId = (int) $left['assignment_id'];
                $rightId = (int) $right['assignment_id'];
                $leftTransferred = isset($preferredOverflow[$leftId])
                    ? 1
                    : 0;
                $rightTransferred = isset($preferredOverflow[$rightId])
                    ? 1
                    : 0;
                if ($leftTransferred !== $rightTransferred) {
                    return $leftTransferred <=> $rightTransferred;
                }
                return $leftId <=> $rightId;
            }
        );

        $acceptedCount = 0;
        $acceptedCost = 0.0;
        $overflowAssignmentIds = [];
        foreach ($orderedRoster as $row) {
            $assignmentId = (int) $row['assignment_id'];
            if ((int) $row['owner_id'] !== $ownerId) {
                $overflowAssignmentIds[] = $assignmentId;
                continue;
            }
            if ((int) $row['is_active'] !== 1) {
                continue;
            }

            $generalCost = max(0.0, (float) $row['cost']);
            if ($acceptedCount >= $assignmentLimit
                || $acceptedCost + $generalCost > $costLimit + 0.000001) {
                $overflowAssignmentIds[] = $assignmentId;
                continue;
            }
            $acceptedCount++;
            $acceptedCost += $generalCost;
        }

        return $overflowAssignmentIds;
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
     * 获取武将加成 / Gets this general's bonuses
     * @param string $type 加成类型 / Bonus domain
     * @param array $context 玩法上下文 / Gameplay context
     * @return array 加成数组 / Bonus values
     */
    public function getBonus($type, array $context = []) {
        if (!$this->isValid) {
            return [];
        }

        $bonus = [];

        // 基础属性加成
        switch ($type) {
            case 'city':
                // 城池加成
                $bonus['defense'] = $this->intelligence * 0.5 + $this->defense * 0.3;
                $bonus['production'] = $this->intelligence * 0.5;
                break;
            case 'army':
                // 军队加成
                $bonus['attack'] = $this->attack * 0.5;
                $bonus['defense'] = $this->defense * 0.3;
                $bonus['speed'] = $this->speed * 0.2;
                break;
        }

        // 技能加成 / Apply passive or currently active skill effects
        foreach ($this->skills as $skill) {
            $skillEffect = $this->getApplicableSkillEffect(
                $skill,
                $context
            );
            $bonus = self::mergeSkillBonusEffects(
                $bonus,
                $skillEffect
            );
        }

        return $bonus;
    }

    /**
     * 将单项技能效果合入武将加成 / Merges one skill's effects into a general's bonuses
     * @param array $bonus 已累计加成 / Accumulated bonuses
     * @param array $skillEffect 待合并技能效果 / Skill effects to merge
     * @return array 合并后的加成 / Merged bonuses
     */
    public static function mergeSkillBonusEffects(
        array $bonus,
        array $skillEffect
    ) {
        foreach ($skillEffect as $effectType => $effectValue) {
            if (!is_numeric($effectValue)) {
                continue;
            }

            $value = (float) $effectValue;
            $isMultiplier = strpos(
                (string) $effectType,
                '_multiplier'
            ) !== false;
            if (isset($bonus[$effectType])) {
                $bonus[$effectType] = $isMultiplier
                    ? (float) $bonus[$effectType] * $value
                    : (float) $bonus[$effectType] + $value;
            } else {
                $bonus[$effectType] = $value;
            }
        }

        return $bonus;
    }

    /**
     * 获取当前可生效的技能效果 / Get skill effects that currently apply
     * @param GeneralSkill $skill 武将技能 / General skill
     * @param array $context 玩法上下文 / Gameplay context
     * @return array 可应用效果 / Applicable effects
     */
    private function getApplicableSkillEffect(
        $skill,
        array $context = []
    ) {
        $baseEffect = $skill->getEffect();
        $baseEffect = is_array($baseEffect) ? $baseEffect : [];

        $query = "SELECT mapped.card_id, card.activation_type, card.is_active,
                         card.max_level,
                         card.effect_json AS catalog_effect_json,
                         active.effect_json AS active_effect_json,
                         active.expires_at
                  FROM equipped_skill_cards mapped
                  LEFT JOIN skill_card_catalog card
                    ON card.card_id = mapped.card_id
                  LEFT JOIN active_skill_effects active
                    ON active.skill_id = mapped.skill_id
                    AND active.user_id = ?
                    AND active.expires_at > NOW()
                  WHERE mapped.skill_id = ?";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $this->ownerId, $skill->getSkillId());
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            if (in_array(
                strtolower(trim((string) $skill->getSkillType())),
                ['主动', '主动技能', 'active'],
                true
            )) {
                return [];
            }
            if (SkillDefinitionValidator::isStructured($baseEffect)) {
                return $this->evaluateStructuredSkillEffect(
                    $baseEffect,
                    $skill->getSkillLevel(),
                    $skill->getSkillLevel(),
                    $context
                );
            }
            return $this->scalePassiveEffect(
                $baseEffect,
                $skill->getSkillLevel()
            );
        }
        if ((int) $row['is_active'] !== 1) {
            return [];
        }
        if ($row['activation_type'] === 'active') {
            $activeEffect = $row['active_effect_json']
                ? json_decode($row['active_effect_json'], true)
                : [];
            if (!is_array($activeEffect)) {
                return [];
            }
            if (SkillDefinitionValidator::isStructured($activeEffect)) {
                return $this->evaluateStructuredSkillEffect(
                    $activeEffect,
                    1,
                    1,
                    $context,
                    true
                );
            }
            return $activeEffect;
        }

        $passiveDefinition = self::selectPassiveEffectDefinition(
            $row['catalog_effect_json'],
            $baseEffect,
            true
        );
        $maximumSkillLevel = max(1, (int) $row['max_level']);
        $effectiveSkillLevel = SkillValueResolver::clampSkillLevel(
            $skill->getSkillLevel(),
            $maximumSkillLevel
        );
        if (SkillDefinitionValidator::isStructured($passiveDefinition)) {
            return $this->evaluateStructuredSkillEffect(
                $passiveDefinition,
                $effectiveSkillLevel,
                $maximumSkillLevel,
                $context
            );
        }

        return $this->scalePassiveEffect(
            $passiveDefinition,
            $effectiveSkillLevel
        );
    }

    /**
     * 求值第二版结构化技能并在错误时安全关闭 / Evaluates a version-two skill and fails closed on errors
     * @param array $definition 技能定义 / Skill definition
     * @param int $skillLevel 技能等级 / Skill level
     * @param int $maxLevel 最高等级 / Maximum level
     * @param array $context 玩法上下文 / Gameplay context
     * @param bool $allowSnapshot 是否为受信任活动效果快照 / Whether this is a trusted active-effect snapshot
     * @return array 已编译修正 / Compiled modifiers
     */
    private function evaluateStructuredSkillEffect(
        array $definition,
        $skillLevel,
        $maxLevel,
        array $context,
        $allowSnapshot = false
    ) {
        $context['skill_level'] = max(1, (int) $skillLevel);
        $context['max_level'] = max(1, (int) $maxLevel);
        $context['general_cost'] = max(0.0, (float) $this->cost);
        $context['general_intelligence'] = max(
            0,
            (int) $this->intelligence
        );
        $context['general_stats'] = [
            'attack' => max(0, (int) $this->attack),
            'defense' => max(0, (int) $this->defense),
            'speed' => max(0, (int) $this->speed),
            'intelligence' => max(0, (int) $this->intelligence)
        ];

        $evaluation = SkillEffectEngine::evaluate(
            $definition,
            $context,
            $allowSnapshot === true
        );
        return $evaluation['valid']
            ? $evaluation['modifiers']
            : [];
    }

    /**
     * 选择映射技能的权威效果定义 / Select the authoritative effect definition for a mapped skill
     * @param mixed $catalogEffectJson 目录效果JSON / Catalog effect JSON
     * @param array $legacyEffect 旧技能快照 / Legacy skill snapshot
     * @param bool $hasCatalogMapping 是否存在目录映射 / Whether a catalog mapping exists
     * @return array 权威效果定义 / Authoritative effect definition
     */
    public static function selectPassiveEffectDefinition(
        $catalogEffectJson,
        array $legacyEffect,
        $hasCatalogMapping
    ) {
        if (!$hasCatalogMapping) {
            return $legacyEffect;
        }

        if (!is_string($catalogEffectJson)
            || trim($catalogEffectJson) === '') {
            return [];
        }

        $catalogEffect = json_decode($catalogEffectJson, true);
        return is_array($catalogEffect) ? $catalogEffect : [];
    }

    /**
     * 解析按等级或COST变化的被动效果描述符 / Resolve a level- or COST-scaled passive-effect descriptor
     * @param mixed $descriptor 效果描述符 / Effect descriptor
     * @param int $skillLevel 技能等级 / Skill level
     * @param mixed $generalCost 武将COST / General COST
     * @return float|null 合法效果值，非法时为空 / Valid effect value, or null when invalid
     */
    public static function resolvePassiveEffectDescriptor(
        $descriptor,
        $skillLevel,
        $generalCost
    ) {
        if (!is_array($descriptor)
            || !isset($descriptor['mode'], $descriptor['values'])
            || !is_string($descriptor['mode'])
            || !is_array($descriptor['values'])) {
            return null;
        }

        $mode = $descriptor['mode'];
        if (!in_array(
            $mode,
            ['level_values', 'cost_level_values'],
            true
        )) {
            return null;
        }

        $levelIndex = max(1, (int) $skillLevel) - 1;
        if (!array_key_exists($levelIndex, $descriptor['values'])) {
            return null;
        }

        $levelValue = $descriptor['values'][$levelIndex];
        if ((!is_int($levelValue) && !is_float($levelValue))
            || !is_finite((float) $levelValue)
            || (float) $levelValue < 0.0) {
            return null;
        }

        $resolvedValue = (float) $levelValue;
        if ($mode === 'cost_level_values') {
            if (!is_numeric($generalCost)
                || !is_finite((float) $generalCost)
                || (float) $generalCost < 0.0) {
                return null;
            }
            $resolvedValue *= (float) $generalCost;
        }

        if (!is_finite($resolvedValue) || $resolvedValue < 0.0) {
            return null;
        }

        return $resolvedValue;
    }

    /**
     * 缩放一组被动效果值 / Scale a passive-effect set
     * @param array $effect 基础效果 / Base effects
     * @param int $skillLevel 技能等级 / Skill level
     * @param int $intelligence 武将智力 / General intelligence
     * @param mixed $generalCost 武将COST / General COST
     * @return array 缩放后的效果 / Scaled effects
     */
    public static function scalePassiveEffectValues(
        array $effect,
        $skillLevel,
        $intelligence,
        $generalCost
    ) {
        $levelFactor = 1 + max(1, (int) $skillLevel) * 0.2;
        $intelligenceFactor = 1
            + max(0, (int) $intelligence) * 0.01;
        $scaled = [];

        foreach ($effect as $effectType => $effectValue) {
            if ($effectType === 'duration') {
                continue;
            }

            // 旧平面数值继续使用既有等级与智力缩放 / Keep legacy flat numbers on their existing level-and-intelligence scaling
            if (is_numeric($effectValue)) {
                $scaled[$effectType] = round(
                    (float) $effectValue
                        * $levelFactor
                        * $intelligenceFactor,
                    2
                );
                continue;
            }

            $resolvedValue = self::resolvePassiveEffectDescriptor(
                $effectValue,
                $skillLevel,
                $generalCost
            );
            if ($resolvedValue !== null) {
                $scaled[$effectType] = round($resolvedValue, 2);
            }
        }

        return $scaled;
    }

    /**
     * 按技能等级与智力缩放被动效果 / Scale passive effects by skill level and intelligence
     * @param array $effect 基础效果 / Base effects
     * @param int $skillLevel 技能等级 / Skill level
     * @return array 缩放后的效果 / Scaled effects
     */
    private function scalePassiveEffect($effect, $skillLevel) {
        return self::scalePassiveEffectValues(
            is_array($effect) ? $effect : [],
            $skillLevel,
            $this->intelligence,
            $this->cost
        );
    }

    /**
     * 汇总当前可生效技能中的指定数值 / Sum one numeric key across currently applicable skills
     * @param string $effectType 效果键 / Effect key
     * @param float $maximum 最大安全值 / Safe maximum
     * @param array $context 玩法上下文 / Gameplay context
     * @return float 非负汇总值 / Non-negative total
     */
    public function getSkillEffectTotal(
        $effectType,
        $maximum = 100.0,
        array $context = []
    ) {
        if (!$this->isValid) {
            return 0.0;
        }

        $effectType = trim((string) $effectType);
        $maximum = max(0.0, (float) $maximum);
        if ($effectType === '' || $maximum <= 0.0) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($this->skills as $skill) {
            $effect = $this->getApplicableSkillEffect($skill, $context);
            if (!isset($effect[$effectType])
                || !is_numeric($effect[$effectType])) {
                continue;
            }
            $value = (float) $effect[$effectType];
            if (!is_finite($value) || $value <= 0.0) {
                continue;
            }
            $total = min($maximum, $total + $value);
        }

        return $total;
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
     * 获取武将来源
     * @return string
     */
    public function getSource() {
        return $this->source;
    }

    /**
     * 获取权威武将模板代码 / Gets the authoritative general template code
     * @return string|null 模板代码或空值 / Template code or null
     */
    public function getTemplateCode() {
        return $this->templateCode;
    }

    /**
     * 获取武将稀有度
     * @return string
     */
    public function getRarity() {
        return $this->rarity;
    }

    /**
     * 获取武将COST
     * @return float
     */
    public function getCost() {
        return $this->cost;
    }

    /**
     * 获取武将元素
     * @return string
     */
    public function getElement() {
        return $this->element;
    }

    /**
     * 获取武将等级
     * @return int
     */
    public function getLevel() {
        return $this->level;
    }

    /**
     * 获取武将HP
     * @return int
     */
    public function getHp() {
        return $this->hp;
    }

    /**
     * 获取武将最大HP
     * @return int
     */
    public function getMaxHp() {
        return $this->maxHp;
    }

    /**
     * 获取武将攻击力
     * @return int
     */
    public function getAttack() {
        return $this->attack;
    }

    /**
     * 获取武将守备力
     * @return int
     */
    public function getDefense() {
        return $this->defense;
    }

    /**
     * 获取武将速度
     * @return int
     */
    public function getSpeed() {
        return $this->speed;
    }

    /**
     * 获取武将智力
     * @return int
     */
    public function getIntelligence() {
        return $this->intelligence;
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
    public static function generateRandomGeneral($ownerId, $rarity = 'B') {
        // 检查稀有度是否有效
        $validRarities = ['B', 'A', 'S', 'SS', 'P'];
        if (!in_array($rarity, $validRarities)) {
            $rarity = 'B';
        }

        // 生成随机名称
        $firstNames = ['赵', '钱', '孙', '李', '周', '吴', '郑', '王', '冯', '陈', '褚', '卫', '蒋', '沈', '韩', '杨', '朱', '秦', '尤', '许'];
        $lastNames = ['云', '明', '辉', '杰', '峰', '强', '军', '平', '保', '东', '文', '辉', '力', '明', '永', '健', '世', '广', '志', '义'];
        $name = $firstNames[array_rand($firstNames)] . $lastNames[array_rand($lastNames)];

        // 生成随机来源
        $source = '原创角色';

        // 生成随机COST
        $cost = 0;
        switch ($rarity) {
            case 'P':
                $cost = 4.0;
                break;
            case 'SS':
                $cost = 3.5;
                break;
            case 'S':
                $cost = 3.0;
                break;
            case 'A':
                $cost = 2.0;
                break;
            case 'B':
                $cost = 1.0;
                break;
        }

        // 生成随机元素
        $elements = ['亮晶晶', '暖洋洋', '冷冰冰', '郁萌萌', '昼闪闪', '夜静静'];
        $element = $elements[array_rand($elements)];

        // 生成随机属性
        $attributes = [];

        // 根据元素设置属性倾向
        switch ($element) {
            case '亮晶晶': // 内政型
                $attributes['attack'] = mt_rand(10, 20);
                $attributes['defense'] = mt_rand(70, 80);
                $attributes['speed'] = mt_rand(40, 50);
                $attributes['intelligence'] = mt_rand(90, 100);
                break;
            case '暖洋洋': // 速攻型
                $attributes['attack'] = mt_rand(90, 100);
                $attributes['defense'] = mt_rand(10, 20);
                $attributes['speed'] = mt_rand(70, 80);
                $attributes['intelligence'] = mt_rand(40, 50);
                break;
            case '冷冰冰': // 防御型
                $attributes['attack'] = mt_rand(40, 50);
                $attributes['defense'] = mt_rand(90, 100);
                $attributes['speed'] = mt_rand(10, 20);
                $attributes['intelligence'] = mt_rand(40, 50);
                break;
            case '郁萌萌': // 强攻型
                $attributes['attack'] = mt_rand(90, 100);
                $attributes['defense'] = mt_rand(10, 20);
                $attributes['speed'] = mt_rand(70, 80);
                $attributes['intelligence'] = mt_rand(40, 50);
                break;
            case '昼闪闪': // 辅助型
                $attributes['attack'] = mt_rand(10, 20);
                $attributes['defense'] = mt_rand(40, 50);
                $attributes['speed'] = mt_rand(70, 80);
                $attributes['intelligence'] = mt_rand(90, 100);
                break;
            case '夜静静': // 特殊型
                $attributes['attack'] = mt_rand(10, 20);
                $attributes['defense'] = mt_rand(70, 80);
                $attributes['speed'] = mt_rand(40, 50);
                $attributes['intelligence'] = mt_rand(90, 100);
                break;
        }

        // 根据稀有度调整属性
        $rarityMultiplier = 1;
        switch ($rarity) {
            case 'P':
                $rarityMultiplier = 1.5;
                break;
            case 'SS':
                $rarityMultiplier = 1.3;
                break;
            case 'S':
                $rarityMultiplier = 1.2;
                break;
            case 'A':
                $rarityMultiplier = 1.1;
                break;
        }

        foreach ($attributes as $key => $value) {
            $attributes[$key] = round($value * $rarityMultiplier);
        }

        // 创建武将
        $general = new General();
        $generalId = $general->createGeneral($ownerId, $name, $source, $rarity, $cost, $element, $attributes);

        if ($generalId) {
            // 添加自带技能
            $skillName = self::getRandomSkillName($element);
            $skillEffect = self::getRandomSkillEffect($element);
            $general->addSkillCard($skillName, 0, $skillEffect);
        }

        return $generalId;
    }

    /**
     * 获取随机技能名称
     * @param string $element 元素类型
     * @return string 技能名称
     */
    public static function getRandomSkillName($element) {
        $skillNames = [
            '亮晶晶' => ['资源加速', '士兵训练加速', '资源爆发', '建筑加速', '税收增加'],
            '暖洋洋' => ['行军加速', '闪电行军', '火焰打击', '战斗爆发', '士气提升'],
            '冷冰冰' => ['防御强化', '铁壁防御', '冰霜护盾', '反击强化', '伤害减免'],
            '郁萌萌' => ['攻击强化', '战斗爆发', '自然之力', '生命汲取', '暴击强化'],
            '昼闪闪' => ['治疗', '光明祝福', '士气提升', '防御强化', '攻击强化'],
            '夜静静' => ['侦察强化', '隐匿行军', '暗影打击', '伏击强化', '夜视能力']
        ];

        $elementSkills = isset($skillNames[$element]) ? $skillNames[$element] : $skillNames['亮晶晶'];
        return $elementSkills[array_rand($elementSkills)];
    }

    /**
     * 获取随机技能效果
     * @param string $element 元素类型
     * @return array 技能效果
     */
    public static function getRandomSkillEffect($element) {
        $skillEffects = [
            '亮晶晶' => [
                ['production' => 10],
                ['build_speed' => 15],
                ['tax' => 10],
                ['population_growth' => 5],
                ['resource_capacity' => 10]
            ],
            '暖洋洋' => [
                ['attack' => 15],
                ['speed' => 10],
                ['morale' => 10],
                ['critical_hit' => 5],
                ['damage' => 10]
            ],
            '冷冰冰' => [
                ['defense' => 15],
                ['damage_reduction' => 10],
                ['counter_attack' => 5],
                ['shield' => 10],
                ['resistance' => 10]
            ],
            '郁萌萌' => [
                ['attack' => 15],
                ['critical_hit' => 10],
                ['life_steal' => 5],
                ['damage' => 10],
                ['penetration' => 5]
            ],
            '昼闪闪' => [
                ['healing' => 10],
                ['morale' => 10],
                ['defense' => 10],
                ['attack' => 10],
                ['buff_duration' => 15]
            ],
            '夜静静' => [
                ['scout_range' => 15],
                ['ambush' => 10],
                ['stealth' => 10],
                ['night_vision' => 10],
                ['detection' => 15]
            ]
        ];

        $elementEffects = isset($skillEffects[$element]) ? $skillEffects[$element] : $skillEffects['亮晶晶'];
        return $elementEffects[array_rand($elementEffects)];
    }

    /**
     * 设置武将名称
     * @param string $name 武将名称
     */
    public function setName($name) {
        $this->name = $name;
    }
    
    /**
     * 设置武将来源
     * @param string $source 武将来源
     */
    public function setSource($source) {
        $this->source = $source;
    }
    
    /**
     * 设置武将稀有度
     * @param string $rarity 武将稀有度
     */
    public function setRarity($rarity) {
        $this->rarity = $rarity;
    }
    
    /**
     * 设置武将COST
     * @param float $cost 武将COST
     */
    public function setCost($cost) {
        $this->cost = $cost;
    }
    
    /**
     * 设置武将元素
     * @param string $element 武将元素
     */
    public function setElement($element) {
        $this->element = $element;
    }
    
    /**
     * 设置攻击力
     * @param int $attack 攻击力
     */
    public function setAttack($attack) {
        $this->attack = $attack;
    }
    
    /**
     * 设置防御力
     * @param int $defense 防御力
     */
    public function setDefense($defense) {
        $this->defense = $defense;
    }
    
    /**
     * 设置速度
     * @param int $speed 速度
     */
    public function setSpeed($speed) {
        $this->speed = $speed;
    }
    
    /**
     * 设置智力
     * @param int $intelligence 智力
     */
    public function setIntelligence($intelligence) {
        $this->intelligence = $intelligence;
    }
    
    /**
     * 保存武将信息
     * @return bool 是否成功
     */
    public function save() {
        if (!$this->isValid) {
            return false;
        }
        
        $query = "UPDATE generals SET name = ?, source = ?, rarity = ?, cost = ?, element = ?, attack = ?, defense = ?, speed = ?, intelligence = ? WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('sssdsiiiii', $this->name, $this->source, $this->rarity, $this->cost, $this->element, $this->attack, $this->defense, $this->speed, $this->intelligence, $this->generalId);
        $result = $stmt->execute();
        $success = $result !== false;
        $stmt->close();
        
        return $success;
    }
    
    /**
     * 删除武将
     * @return bool 是否成功
     */
    public function delete() {
        if (!$this->isValid) {
            return false;
        }
        
        // 开始事务 / Start the transaction.
        if (!$this->db->begin_transaction()) {
            return false;
        }
        
        try {
            // 公共模板删除与卡池后台共享事务边界 / Public-template deletion shares the pool-administration transaction boundary.
            if ((int) $this->ownerId === 0) {
                lockResourceAdministrationBoundary($this->db);
            }

            // 删除武将的技能 / Delete the general's skills.
            $query = "DELETE FROM general_skills WHERE general_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->generalId);
            $stmt->execute();
            $stmt->close();
            
            // 删除武将的分配记录 / Delete the general's assignments.
            $query = "DELETE FROM general_assignments WHERE general_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->generalId);
            $stmt->execute();
            $stmt->close();
            
            // 删除武将 / Delete the general.
            $query = "DELETE FROM generals WHERE general_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->generalId);
            $result = $stmt->execute();
            $stmt->close();
            
            if ($result) {
                $this->db->commit();
                $this->isValid = false;
                return true;
            } else {
                $this->db->rollback();
                return false;
            }
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }
}

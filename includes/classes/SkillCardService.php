<?php
// 种火集结号 - 技能卡服务 / Fireseed Engage - Skill card service

/**
 * 管理技能卡目录、抽取、装备、升级与主动发动 / Manages the skill-card catalog, draws, equipment, upgrades, and active use
 */
class SkillCardService {
    private const RARITIES = ['B', 'A', 'S', 'SS', 'P'];

    private $db;
    private $cardPoolService;

    /**
     * 创建技能卡服务 / Creates the skill-card service
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->cardPoolService = new CardPoolService($this->db);
    }

    /**
     * 获取启用中的技能卡目录 / Gets the active skill-card catalog
     *
     * @param string|null $rarity 可选稀有度筛选 / Optional rarity filter
     * @return array 结构化目录结果 / Structured catalog result
     */
    public function getCatalog($rarity = null): array {
        $normalizedRarity = $rarity === null
            ? null
            : strtoupper(trim((string) $rarity));

        if ($normalizedRarity !== null
            && !in_array($normalizedRarity, self::RARITIES, true)) {
            return $this->result(false, '稀有度无效 / Invalid rarity');
        }

        try {
            $query = "SELECT card_id, card_code, name, description, rarity,
                             element, activation_type, category, effect_json,
                             base_cooldown, max_level, is_active
                      FROM skill_card_catalog
                      WHERE is_active = 1";

            if ($normalizedRarity !== null) {
                $query .= " AND rarity = ?";
            }

            $query .= " ORDER BY FIELD(rarity, 'P', 'SS', 'S', 'A', 'B'), name, card_id";
            $stmt = $this->db->prepare($query);

            if (!$stmt) {
                throw new RuntimeException('无法读取技能卡目录 / Unable to read skill-card catalog');
            }

            if ($normalizedRarity !== null) {
                $stmt->bind_param('s', $normalizedRarity);
            }

            $this->executeOrFail(
                $stmt,
                '无法读取技能卡目录 / Unable to read skill-card catalog'
            );
            $result = $stmt->get_result();
            $cards = [];

            while ($result && ($row = $result->fetch_assoc())) {
                $cards[] = $this->normalizeCardRow($row);
            }

            $stmt->close();

            return $this->result(
                true,
                '技能卡目录读取成功 / Skill-card catalog loaded',
                $cards
            );
        } catch (Throwable $e) {
            error_log('SkillCardService::getCatalog failed: ' . $e->getMessage());

            return $this->result(
                false,
                '技能卡目录读取失败 / Failed to load the skill-card catalog'
            );
        }
    }

    /**
     * 获取当前开放的技能卡池 / Gets currently open skill-card pools
     *
     * @return array 结构化卡池结果 / Structured pool result
     */
    public function getDrawPools(): array {
        return $this->cardPoolService->getAvailablePools('skill');
    }

    /**
     * 获取玩家技能卡库存 / Gets a player's skill-card inventory
     *
     * @param int $userId 玩家ID / User ID
     * @return array 结构化库存结果 / Structured inventory result
     */
    public function getInventory($userId): array {
        $normalizedUserId = (int) $userId;

        if ($normalizedUserId <= 0) {
            return $this->result(false, '玩家无效 / Invalid user');
        }

        try {
            $query = "SELECT c.card_id, c.card_code, c.name, c.description,
                             c.rarity, c.element, c.activation_type, c.category,
                             c.effect_json, c.base_cooldown, c.max_level,
                             c.is_active, i.quantity
                      FROM user_skill_cards i
                      JOIN skill_card_catalog c ON c.card_id = i.card_id
                      WHERE i.user_id = ? AND i.quantity > 0
                      ORDER BY FIELD(c.rarity, 'P', 'SS', 'S', 'A', 'B'),
                               c.name, c.card_id";
            $stmt = $this->db->prepare($query);

            if (!$stmt) {
                throw new RuntimeException('无法读取技能卡库存 / Unable to read skill-card inventory');
            }

            $stmt->bind_param('i', $normalizedUserId);
            $this->executeOrFail(
                $stmt,
                '无法读取技能卡库存 / Unable to read skill-card inventory'
            );
            $result = $stmt->get_result();
            $cards = [];

            while ($result && ($row = $result->fetch_assoc())) {
                $cards[] = $this->normalizeCardRow($row);
            }

            $stmt->close();

            return $this->result(
                true,
                '技能卡库存读取成功 / Skill-card inventory loaded',
                $cards
            );
        } catch (Throwable $e) {
            error_log('SkillCardService::getInventory failed: ' . $e->getMessage());

            return $this->result(
                false,
                '技能卡库存读取失败 / Failed to load skill-card inventory'
            );
        }
    }

    /**
     * 从已发布卡池抽取技能卡 / Draws skill cards from a published pool
     *
     * @param int $userId 玩家ID / User ID
     * @param int $count 抽取次数 / Draw count
     * @param mixed $poolIdentifier 卡池ID、代码或旧渠道名 / Pool ID, code, or legacy channel name
     * @return array 结构化抽取结果 / Structured draw result
     */
    public function draw($userId, $count = 1, $poolIdentifier = 'default'): array {
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
                '抽取次数无效 / Invalid draw count'
            );
        }

        $transactionStarted = false;

        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockUser($normalizedUserId);
            $pool = $this->cardPoolService->lockPoolForDraw(
                'skill',
                $poolIdentifier,
                $normalizedCount
            );
            $totalCost = $this->cardPoolService->consumeCost(
                $normalizedUserId,
                $pool['cost'],
                $normalizedCount
            );
            $cards = [];

            for ($index = 0; $index < $normalizedCount; $index++) {
                $selectedEntry = CardPoolService::selectWeightedEntry(
                    $pool['entries']
                );
                $card = $selectedEntry;

                $this->increaseInventory(
                    $normalizedUserId,
                    (int) $card['card_id'],
                    1
                );
                $this->recordDraw(
                    $normalizedUserId,
                    (int) $card['card_id'],
                    (string) $card['rarity'],
                    $pool,
                    $selectedEntry
                );
                $this->recordGameplayEvent(
                    $normalizedUserId,
                    'skill_card_drawn',
                    1,
                    'skill_card',
                    (int) $card['card_id']
                );
                $normalizedCard = $this->normalizeCardRow($card);
                // 保留旧返回字段以兼容现有页面 / Preserve the legacy response field for existing pages
                $normalizedCard['rolled_rarity'] = (string) $card['rarity'];
                $cards[] = $normalizedCard;
            }

            $this->commitTransaction();
            $transactionStarted = false;

            return $this->result(
                true,
                '技能卡抽取成功 / Skill-card draw successful',
                $cards,
                [
                    'count' => $normalizedCount,
                    'cost' => $totalCost,
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

            error_log('SkillCardService::draw failed: ' . $e->getMessage());

            return $this->result(
                false,
                '技能卡抽取失败，未扣除夜静静 / Skill-card draw failed and no night crystals were consumed'
            );
        }
    }

    /**
     * 将库存技能卡装备到武将的一号或二号槽 / Equips an inventory card into general slot one or two
     *
     * @param int $userId 玩家ID / User ID
     * @param int $generalId 武将ID / General ID
     * @param int $cardId 技能卡ID / Skill card ID
     * @param int $slot 装备槽位 / Equipment slot
     * @return array 结构化装备结果 / Structured equip result
     */
    public function equip($userId, $generalId, $cardId, $slot): array {
        $normalizedUserId = (int) $userId;
        $normalizedGeneralId = (int) $generalId;
        $normalizedCardId = (int) $cardId;
        $normalizedSlot = (int) $slot;

        if ($normalizedUserId <= 0
            || $normalizedGeneralId <= 0
            || $normalizedCardId <= 0) {
            return $this->result(false, '装备参数无效 / Invalid equip parameters');
        }

        if (!in_array($normalizedSlot, [1, 2], true)) {
            return $this->result(
                false,
                '技能卡只能装备到一号或二号槽 / Skill cards may only be equipped in slot one or two'
            );
        }

        $transactionStarted = false;

        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockUser($normalizedUserId);
            $this->lockOwnedGeneral($normalizedUserId, $normalizedGeneralId);
            $existingSkills = $this->getSlotSkillsLocked(
                $normalizedGeneralId,
                $normalizedSlot
            );

            if (count($existingSkills) === 1
                && (int) $existingSkills[0]['equipped_card_id'] === $normalizedCardId) {
                $this->commitTransaction();
                $transactionStarted = false;

                return $this->result(
                    true,
                    '该技能卡已装备在目标槽位 / This card is already equipped in the target slot',
                    [],
                    [
                        'skill' => $this->normalizeSkillRow($existingSkills[0]),
                        'replaced_card_ids' => []
                    ]
                );
            }

            $card = $this->getCatalogCardLocked($normalizedCardId);

            if ($card === null) {
                throw new DomainException('技能卡不存在 / Skill card does not exist');
            }

            $inventoryQuantity = $this->getInventoryQuantityLocked(
                $normalizedUserId,
                $normalizedCardId
            );
            $returnedRequestedCards = 0;

            foreach ($existingSkills as $existingSkill) {
                if ((int) $existingSkill['equipped_card_id'] === $normalizedCardId) {
                    $returnedRequestedCards++;
                }
            }

            if ($inventoryQuantity + $returnedRequestedCards <= 0) {
                throw new DomainException('技能卡库存不足 / Insufficient skill-card inventory');
            }

            $replacedCardIds = [];

            foreach ($existingSkills as $existingSkill) {
                $oldCardId = (int) $existingSkill['equipped_card_id'];

                if ($oldCardId > 0) {
                    $this->increaseInventory($normalizedUserId, $oldCardId, 1);
                    $replacedCardIds[] = $oldCardId;
                }

                $this->deleteGeneralSkill((int) $existingSkill['skill_id']);
            }

            $this->decreaseInventory(
                $normalizedUserId,
                $normalizedCardId,
                1
            );
            $skillId = $this->createEquippedSkill(
                $normalizedGeneralId,
                $normalizedSlot,
                $card
            );
            $this->mapEquippedCard($skillId, $normalizedCardId);
            $this->recordGameplayEvent(
                $normalizedUserId,
                'skill_card_equipped',
                1,
                'skill',
                $skillId
            );
            $this->commitTransaction();
            $transactionStarted = false;

            return $this->result(
                true,
                '技能卡装备成功 / Skill card equipped',
                [],
                [
                    'skill' => [
                        'skill_id' => $skillId,
                        'general_id' => $normalizedGeneralId,
                        'card_id' => $normalizedCardId,
                        'skill_name' => (string) $card['name'],
                        'skill_type' => '装备',
                        'slot' => $normalizedSlot,
                        'skill_level' => 1,
                        'skill_effect' => $this->decodeEffect(
                            (string) $card['effect_json']
                        )
                    ],
                    'replaced_card_ids' => $replacedCardIds
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

            error_log('SkillCardService::equip failed: ' . $e->getMessage());

            return $this->result(
                false,
                '技能卡装备失败 / Failed to equip skill card'
            );
        }
    }

    /**
     * 卸下武将指定槽位的技能卡并返还库存 / Unequips cards from a general slot and returns them to inventory
     *
     * @param int $userId 玩家ID / User ID
     * @param int $generalId 武将ID / General ID
     * @param int $slot 装备槽位 / Equipment slot
     * @return array 结构化卸下结果 / Structured unequip result
     */
    public function unequip($userId, $generalId, $slot): array {
        $normalizedUserId = (int) $userId;
        $normalizedGeneralId = (int) $generalId;
        $normalizedSlot = (int) $slot;

        if ($normalizedUserId <= 0 || $normalizedGeneralId <= 0) {
            return $this->result(false, '卸下参数无效 / Invalid unequip parameters');
        }

        if (!in_array($normalizedSlot, [1, 2], true)) {
            return $this->result(
                false,
                '只能卸下一号或二号槽 / Only slot one or two may be unequipped'
            );
        }

        $transactionStarted = false;

        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockUser($normalizedUserId);
            $this->lockOwnedGeneral($normalizedUserId, $normalizedGeneralId);
            $skills = $this->getSlotSkillsLocked(
                $normalizedGeneralId,
                $normalizedSlot
            );

            if (empty($skills)) {
                throw new DomainException('目标技能槽为空 / Target skill slot is empty');
            }

            $returnedCardIds = [];

            foreach ($skills as $skill) {
                $cardId = (int) $skill['equipped_card_id'];

                if ($cardId > 0) {
                    $this->increaseInventory($normalizedUserId, $cardId, 1);
                    $returnedCardIds[] = $cardId;
                }

                $this->deleteGeneralSkill((int) $skill['skill_id']);
            }

            $this->recordGameplayEvent(
                $normalizedUserId,
                'skill_card_unequipped',
                count($returnedCardIds),
                'general',
                $normalizedGeneralId
            );
            $this->commitTransaction();
            $transactionStarted = false;

            return $this->result(
                true,
                '技能卡已卸下并返还库存 / Skill card unequipped and returned to inventory',
                [],
                [
                    'general_id' => $normalizedGeneralId,
                    'slot' => $normalizedSlot,
                    'returned_card_ids' => $returnedCardIds
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

            error_log('SkillCardService::unequip failed: ' . $e->getMessage());

            return $this->result(
                false,
                '技能卡卸下失败 / Failed to unequip skill card'
            );
        }
    }

    /**
     * 消耗技能点升级已装备的技能卡 / Upgrades an equipped skill card by consuming skill points
     *
     * @param int $userId 玩家ID / User ID
     * @param int $skillId 武将技能ID / General skill ID
     * @return array 结构化升级结果 / Structured upgrade result
     */
    public function upgrade($userId, $skillId): array {
        $normalizedUserId = (int) $userId;
        $normalizedSkillId = (int) $skillId;

        if ($normalizedUserId <= 0 || $normalizedSkillId <= 0) {
            return $this->result(false, '技能升级参数无效 / Invalid skill-upgrade parameters');
        }

        $transactionStarted = false;

        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockUser($normalizedUserId);
            $skill = $this->getOwnedMappedSkillLocked(
                $normalizedUserId,
                $normalizedSkillId
            );

            if ($skill === null) {
                throw new DomainException(
                    '技能不存在、未装备或不属于玩家 / Skill does not exist, is not equipped, or is not owned'
                );
            }

            $currentLevel = max(1, (int) $skill['skill_level']);
            $maxLevel = max(1, (int) $skill['max_level']);

            if ($currentLevel >= $maxLevel) {
                throw new DomainException('技能已达到最高等级 / Skill is already at maximum level');
            }

            $upgradeCost = $currentLevel * 10;
            $this->ensureWallet($normalizedUserId);
            $skillPoints = $this->getSkillPointsLocked($normalizedUserId);

            if ($skillPoints < $upgradeCost) {
                throw new DomainException('技能点不足 / Insufficient skill points');
            }

            $walletUpdate = "UPDATE gameplay_wallets
                             SET skill_points = skill_points - ?
                             WHERE user_id = ?";
            $stmt = $this->db->prepare($walletUpdate);

            if (!$stmt) {
                throw new RuntimeException('无法扣除技能点 / Unable to consume skill points');
            }

            $stmt->bind_param('ii', $upgradeCost, $normalizedUserId);
            $this->executeOrFail(
                $stmt,
                '无法扣除技能点 / Unable to consume skill points'
            );
            $stmt->close();
            $newLevel = $currentLevel + 1;
            $skillUpdate = "UPDATE general_skills
                            SET skill_level = ?
                            WHERE skill_id = ?";
            $stmt = $this->db->prepare($skillUpdate);

            if (!$stmt) {
                throw new RuntimeException('无法升级技能 / Unable to upgrade skill');
            }

            $stmt->bind_param('ii', $newLevel, $normalizedSkillId);
            $this->executeOrFail($stmt, '无法升级技能 / Unable to upgrade skill');
            $stmt->close();
            $this->recordSkillPointsSpent(
                (int) $skill['general_id'],
                $upgradeCost
            );
            $this->recordGameplayEvent(
                $normalizedUserId,
                'skill_upgraded',
                1,
                'skill',
                $normalizedSkillId
            );
            $this->commitTransaction();
            $transactionStarted = false;

            return $this->result(
                true,
                '技能升级成功 / Skill upgraded',
                [],
                [
                    'skill_id' => $normalizedSkillId,
                    'card_id' => (int) $skill['card_id'],
                    'skill_level' => $newLevel,
                    'max_level' => $maxLevel,
                    'cost' => ['skill_points' => $upgradeCost],
                    'remaining_skill_points' => $skillPoints - $upgradeCost
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

            error_log('SkillCardService::upgrade failed: ' . $e->getMessage());

            return $this->result(false, '技能升级失败 / Failed to upgrade skill');
        }
    }

    /**
     * 发动主动技能并记录冷却 / Activates an active skill and records its cooldown
     *
     * @param int $userId 玩家ID / User ID
     * @param int $skillId 武将技能ID / General skill ID
     * @return array 结构化发动结果 / Structured activation result
     */
    public function activate($userId, $skillId): array {
        $normalizedUserId = (int) $userId;
        $normalizedSkillId = (int) $skillId;

        if ($normalizedUserId <= 0 || $normalizedSkillId <= 0) {
            return $this->result(false, '技能发动参数无效 / Invalid skill-activation parameters');
        }

        $transactionStarted = false;

        try {
            $this->beginTransaction();
            $transactionStarted = true;
            $this->lockUser($normalizedUserId);
            $skill = $this->getOwnedMappedSkillLocked(
                $normalizedUserId,
                $normalizedSkillId
            );

            if ($skill === null) {
                throw new DomainException(
                    '技能不存在、未装备或不属于玩家 / Skill does not exist, is not equipped, or is not owned'
                );
            }

            if ((string) $skill['activation_type'] !== 'active'
                || (int) $skill['is_active'] !== 1) {
                throw new DomainException('只有启用的主动技能可以发动 / Only enabled active skills may be activated');
            }
            if ((int) $skill['hp'] <= 0) {
                throw new DomainException(
                    'HP为零的武将无法发动技能 / A general at zero HP cannot activate skills'
                );
            }

            $readyAt = $this->getCooldownReadyAtLocked(
                $normalizedUserId,
                $normalizedSkillId
            );
            $now = time();

            if ($readyAt !== null && strtotime($readyAt) > $now) {
                throw new DomainException(
                    '技能仍在冷却中，冷却结束时间：' . $readyAt
                    . ' / Skill is on cooldown until ' . $readyAt
                );
            }

            $baseEffects = $this->decodeEffect((string) $skill['effect_json']);
            $general = new General((int) $skill['general_id']);
            $skillPower = $general->isValid()
                && (int) $general->getOwnerId() === $normalizedUserId
                ? $general->getSkillEffectTotal('skill_power', 100.0)
                : 0.0;
            $calculatedEffects = $this->calculateEffects(
                $baseEffects,
                max(1, (int) $skill['skill_level']),
                (int) $skill['intelligence'],
                $skillPower
            );
            $applied = [];

            if (isset($calculatedEffects['all_resources'])) {
                $amount = max(0, (int) round($calculatedEffects['all_resources']));
                $this->grantAllResources($normalizedUserId, $amount);
                $calculatedEffects['all_resources'] = $amount;
                $applied['all_resources'] = $amount;
            }

            if (isset($calculatedEffects['healing'])) {
                $healing = max(0, (int) round($calculatedEffects['healing']));
                $actualHealing = min(
                    $healing,
                    max(0, (int) $skill['max_hp'] - (int) $skill['hp'])
                );

                if ($actualHealing > 0) {
                    $newHp = (int) $skill['hp'] + $actualHealing;
                    $update = "UPDATE generals SET hp = ? WHERE general_id = ?";
                    $stmt = $this->db->prepare($update);

                    if (!$stmt) {
                        throw new RuntimeException('无法恢复武将HP / Unable to heal general HP');
                    }

                    $generalId = (int) $skill['general_id'];
                    $stmt->bind_param('ii', $newHp, $generalId);
                    $this->executeOrFail(
                        $stmt,
                        '无法恢复武将HP / Unable to heal general HP'
                    );
                    $stmt->close();
                }

                $calculatedEffects['healing'] = $healing;
                $applied['healing'] = $actualHealing;
            }

            $duration = isset($calculatedEffects['duration'])
                ? max(0, (int) $calculatedEffects['duration'])
                : 0;
            $temporaryEffects = $calculatedEffects;
            unset(
                $temporaryEffects['duration'],
                $temporaryEffects['all_resources'],
                $temporaryEffects['healing']
            );
            if ($duration > 0 && !empty($temporaryEffects)) {
                $expiresAt = date('Y-m-d H:i:s', $now + $duration);
                $this->setActiveEffect(
                    $normalizedUserId,
                    (int) $skill['general_id'],
                    $normalizedSkillId,
                    $temporaryEffects,
                    $expiresAt
                );
                $applied['temporary_effects'] = $temporaryEffects;
                $applied['expires_at'] = $expiresAt;
            }

            $cooldownSeconds = $this->calculateCooldownSeconds(
                (int) $skill['base_cooldown']
            );
            $newReadyAt = date('Y-m-d H:i:s', $now + $cooldownSeconds);
            $this->setCooldown(
                $normalizedUserId,
                $normalizedSkillId,
                $newReadyAt
            );
            $this->recordGameplayEvent(
                $normalizedUserId,
                'skill_activated',
                1,
                'skill',
                $normalizedSkillId
            );
            $this->commitTransaction();
            $transactionStarted = false;

            return $this->result(
                true,
                '主动技能发动成功 / Active skill activated',
                [],
                [
                    'skill_id' => $normalizedSkillId,
                    'general_id' => (int) $skill['general_id'],
                    'card_id' => (int) $skill['card_id'],
                    'effects' => $calculatedEffects,
                    'applied' => $applied,
                    'cooldown_seconds' => $cooldownSeconds,
                    'ready_at' => $newReadyAt
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

            error_log('SkillCardService::activate failed: ' . $e->getMessage());

            return $this->result(false, '技能发动失败 / Failed to activate skill');
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
     */
    private function lockUser($userId): void {
        $query = "SELECT user_id FROM users WHERE user_id = ? FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法锁定玩家 / Unable to lock user');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail($stmt, '无法锁定玩家 / Unable to lock user');
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            throw new DomainException('玩家不存在 / User does not exist');
        }
    }

    /**
     * 锁定并验证玩家拥有的武将 / Locks and validates a general owned by the user
     *
     * @param int $userId 玩家ID / User ID
     * @param int $generalId 武将ID / General ID
     * @return array 武将行 / General row
     */
    private function lockOwnedGeneral($userId, $generalId): array {
        $query = "SELECT general_id, owner_id, hp, max_hp, intelligence
                  FROM generals
                  WHERE general_id = ? AND owner_id = ? AND is_active = 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法锁定武将 / Unable to lock general');
        }

        $stmt->bind_param('ii', $generalId, $userId);
        $this->executeOrFail($stmt, '无法锁定武将 / Unable to lock general');
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new DomainException('武将不存在或不属于玩家 / General does not exist or is not owned');
        }

        return $row;
    }

    /**
     * 增加技能卡库存 / Increases skill-card inventory
     *
     * @param int $userId 玩家ID / User ID
     * @param int $cardId 技能卡ID / Skill card ID
     * @param int $quantity 数量 / Quantity
     */
    private function increaseInventory($userId, $cardId, $quantity): void {
        $query = "INSERT INTO user_skill_cards (user_id, card_id, quantity)
                  VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法增加技能卡库存 / Unable to increase skill-card inventory');
        }

        $stmt->bind_param('iii', $userId, $cardId, $quantity);
        $this->executeOrFail(
            $stmt,
            '无法增加技能卡库存 / Unable to increase skill-card inventory'
        );
        $stmt->close();
    }

    /**
     * 减少技能卡库存 / Decreases skill-card inventory
     *
     * @param int $userId 玩家ID / User ID
     * @param int $cardId 技能卡ID / Skill card ID
     * @param int $quantity 数量 / Quantity
     */
    private function decreaseInventory($userId, $cardId, $quantity): void {
        $query = "UPDATE user_skill_cards
                  SET quantity = quantity - ?
                  WHERE user_id = ? AND card_id = ? AND quantity >= ?";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法减少技能卡库存 / Unable to decrease skill-card inventory');
        }

        $stmt->bind_param('iiii', $quantity, $userId, $cardId, $quantity);
        $this->executeOrFail(
            $stmt,
            '无法减少技能卡库存 / Unable to decrease skill-card inventory'
        );
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if ($affectedRows !== 1) {
            throw new DomainException('技能卡库存不足 / Insufficient skill-card inventory');
        }
    }

    /**
     * 锁定并读取技能卡库存数量 / Locks and reads a skill-card inventory quantity
     *
     * @param int $userId 玩家ID / User ID
     * @param int $cardId 技能卡ID / Skill card ID
     * @return int 库存数量 / Inventory quantity
     */
    private function getInventoryQuantityLocked($userId, $cardId): int {
        $query = "SELECT quantity
                  FROM user_skill_cards
                  WHERE user_id = ? AND card_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法锁定技能卡库存 / Unable to lock skill-card inventory');
        }

        $stmt->bind_param('ii', $userId, $cardId);
        $this->executeOrFail(
            $stmt,
            '无法锁定技能卡库存 / Unable to lock skill-card inventory'
        );
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? max(0, (int) $row['quantity']) : 0;
    }

    /**
     * 锁定并读取目录技能卡 / Locks and reads a catalog card
     *
     * @param int $cardId 技能卡ID / Skill card ID
     * @return array|null 技能卡行 / Skill-card row
     */
    private function getCatalogCardLocked($cardId) {
        $query = "SELECT card_id, card_code, name, description, rarity,
                         element, activation_type, category, effect_json,
                         base_cooldown, max_level, is_active
                  FROM skill_card_catalog
                  WHERE card_id = ? AND is_active = 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法读取技能卡 / Unable to read skill card');
        }

        $stmt->bind_param('i', $cardId);
        $this->executeOrFail($stmt, '无法读取技能卡 / Unable to read skill card');
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    /**
     * 锁定指定技能槽内的所有记录及其卡牌映射 / Locks all records and card mappings in a skill slot
     *
     * @param int $generalId 武将ID / General ID
     * @param int $slot 技能槽 / Skill slot
     * @return array 技能行 / Skill rows
     */
    private function getSlotSkillsLocked($generalId, $slot): array {
        $query = "SELECT gs.skill_id, gs.general_id, gs.skill_type,
                         gs.skill_name, gs.slot, gs.skill_level, gs.skill_effect,
                         esc.card_id AS equipped_card_id
                  FROM general_skills gs
                  LEFT JOIN equipped_skill_cards esc ON esc.skill_id = gs.skill_id
                  WHERE gs.general_id = ? AND gs.slot = ?
                  ORDER BY gs.skill_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法读取技能槽 / Unable to read skill slot');
        }

        $stmt->bind_param('ii', $generalId, $slot);
        $this->executeOrFail($stmt, '无法读取技能槽 / Unable to read skill slot');
        $result = $stmt->get_result();
        $skills = [];

        while ($result && ($row = $result->fetch_assoc())) {
            $skills[] = $row;
        }

        $stmt->close();

        return $skills;
    }

    /**
     * 删除武将技能，映射与冷却由外键级联清理 / Deletes a general skill; mappings and cooldowns cascade through foreign keys
     *
     * @param int $skillId 武将技能ID / General skill ID
     */
    private function deleteGeneralSkill($skillId): void {
        $query = "DELETE FROM general_skills WHERE skill_id = ?";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法删除旧技能 / Unable to delete old skill');
        }

        $stmt->bind_param('i', $skillId);
        $this->executeOrFail($stmt, '无法删除旧技能 / Unable to delete old skill');
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if ($affectedRows !== 1) {
            throw new RuntimeException('旧技能不存在 / Old skill does not exist');
        }
    }

    /**
     * 创建装备型武将技能 / Creates an equipped general skill
     *
     * @param int $generalId 武将ID / General ID
     * @param int $slot 技能槽 / Skill slot
     * @param array $card 目录卡牌行 / Catalog-card row
     * @return int 新技能ID / New skill ID
     */
    private function createEquippedSkill($generalId, $slot, array $card): int {
        $query = "INSERT INTO general_skills
                    (general_id, skill_type, skill_name, slot, skill_level, skill_effect)
                  VALUES (?, '装备', ?, ?, 1, ?)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法创建装备技能 / Unable to create equipped skill');
        }

        $skillName = (string) $card['name'];
        $skillEffect = (string) $card['effect_json'];
        $stmt->bind_param(
            'isis',
            $generalId,
            $skillName,
            $slot,
            $skillEffect
        );
        $this->executeOrFail(
            $stmt,
            '无法创建装备技能 / Unable to create equipped skill'
        );
        $skillId = (int) $this->db->insert_id;
        $stmt->close();

        if ($skillId <= 0) {
            throw new RuntimeException('装备技能未返回ID / Equipped skill did not return an ID');
        }

        return $skillId;
    }

    /**
     * 将武将技能映射回目录卡牌 / Maps a general skill back to its catalog card
     *
     * @param int $skillId 武将技能ID / General skill ID
     * @param int $cardId 技能卡ID / Skill card ID
     */
    private function mapEquippedCard($skillId, $cardId): void {
        $query = "INSERT INTO equipped_skill_cards (skill_id, card_id)
                  VALUES (?, ?)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法创建技能卡映射 / Unable to create equipped-card mapping');
        }

        $stmt->bind_param('ii', $skillId, $cardId);
        $this->executeOrFail(
            $stmt,
            '无法创建技能卡映射 / Unable to create equipped-card mapping'
        );
        $stmt->close();
    }

    /**
     * 锁定并读取属于玩家的卡牌映射技能 / Locks and reads a mapped skill owned by the user
     *
     * @param int $userId 玩家ID / User ID
     * @param int $skillId 武将技能ID / General skill ID
     * @return array|null 技能与卡牌行 / Skill and card row
     */
    private function getOwnedMappedSkillLocked($userId, $skillId) {
        $query = "SELECT gs.skill_id, gs.general_id, gs.skill_name,
                         gs.skill_type, gs.slot, gs.skill_level, gs.skill_effect,
                         g.hp, g.max_hp, g.intelligence,
                         esc.card_id, c.card_code, c.name AS card_name,
                         c.activation_type, c.effect_json, c.base_cooldown,
                         c.max_level, c.is_active
                  FROM general_skills gs
                  JOIN generals g ON g.general_id = gs.general_id
                  JOIN equipped_skill_cards esc ON esc.skill_id = gs.skill_id
                  JOIN skill_card_catalog c ON c.card_id = esc.card_id
                  WHERE gs.skill_id = ? AND g.owner_id = ? AND g.is_active = 1
                    AND c.is_active = 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法读取装备技能 / Unable to read equipped skill');
        }

        $stmt->bind_param('ii', $skillId, $userId);
        $this->executeOrFail(
            $stmt,
            '无法读取装备技能 / Unable to read equipped skill'
        );
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    /**
     * 确保玩家钱包存在 / Ensures the player's gameplay wallet exists
     *
     * @param int $userId 玩家ID / User ID
     */
    private function ensureWallet($userId): void {
        $query = "INSERT IGNORE INTO gameplay_wallets (user_id) VALUES (?)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法初始化玩法钱包 / Unable to initialize gameplay wallet');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail(
            $stmt,
            '无法初始化玩法钱包 / Unable to initialize gameplay wallet'
        );
        $stmt->close();
    }

    /**
     * 锁定并读取技能点 / Locks and reads skill points
     *
     * @param int $userId 玩家ID / User ID
     * @return int 技能点 / Skill points
     */
    private function getSkillPointsLocked($userId): int {
        $query = "SELECT skill_points
                  FROM gameplay_wallets
                  WHERE user_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法读取技能点 / Unable to read skill points');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail($stmt, '无法读取技能点 / Unable to read skill points');
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('玩法钱包不存在 / Gameplay wallet does not exist');
        }

        return max(0, (int) $row['skill_points']);
    }

    /**
     * 累计武将已消费技能点 / Accumulates skill points spent by a general
     *
     * @param int $generalId 武将ID / General ID
     * @param int $amount 消费数量 / Spent amount
     */
    private function recordSkillPointsSpent($generalId, $amount): void {
        $query = "INSERT INTO general_progression
                    (general_id, skill_points_spent)
                  VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE
                    skill_points_spent = skill_points_spent + VALUES(skill_points_spent)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法记录技能点消费 / Unable to record spent skill points');
        }

        $stmt->bind_param('ii', $generalId, $amount);
        $this->executeOrFail(
            $stmt,
            '无法记录技能点消费 / Unable to record spent skill points'
        );
        $stmt->close();
    }

    /**
     * 锁定并读取技能冷却 / Locks and reads a skill cooldown
     *
     * @param int $userId 玩家ID / User ID
     * @param int $skillId 武将技能ID / General skill ID
     * @return string|null 冷却结束时间 / Cooldown-ready time
     */
    private function getCooldownReadyAtLocked($userId, $skillId) {
        $query = "SELECT ready_at
                  FROM skill_cooldowns
                  WHERE skill_id = ? AND user_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法读取技能冷却 / Unable to read skill cooldown');
        }

        $stmt->bind_param('ii', $skillId, $userId);
        $this->executeOrFail(
            $stmt,
            '无法读取技能冷却 / Unable to read skill cooldown'
        );
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? (string) $row['ready_at'] : null;
    }

    /**
     * 写入或刷新技能冷却 / Writes or refreshes a skill cooldown
     *
     * @param int $userId 玩家ID / User ID
     * @param int $skillId 武将技能ID / General skill ID
     * @param string $readyAt 冷却结束时间 / Cooldown-ready time
     */
    private function setCooldown($userId, $skillId, $readyAt): void {
        $query = "INSERT INTO skill_cooldowns (skill_id, user_id, ready_at)
                  VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE ready_at = VALUES(ready_at)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法记录技能冷却 / Unable to record skill cooldown');
        }

        $stmt->bind_param('iis', $skillId, $userId, $readyAt);
        $this->executeOrFail(
            $stmt,
            '无法记录技能冷却 / Unable to record skill cooldown'
        );
        $stmt->close();
    }

    /**
     * 写入有时限的主动技能效果 / Store a time-limited active skill effect
     *
     * @param int $userId 玩家ID / User ID
     * @param int $generalId 武将ID / General ID
     * @param int $skillId 技能ID / Skill ID
     * @param array $effects 已计算效果 / Calculated effects
     * @param string $expiresAt 失效时间 / Expiration time
     */
    private function setActiveEffect(
        $userId,
        $generalId,
        $skillId,
        array $effects,
        $expiresAt
    ): void {
        $effectJson = json_encode($effects, JSON_UNESCAPED_UNICODE);
        if ($effectJson === false) {
            throw new RuntimeException(
                '无法编码主动技能效果 / Unable to encode active skill effects'
            );
        }

        $query = "INSERT INTO active_skill_effects
                    (skill_id, user_id, general_id, effect_json,
                     activated_at, expires_at)
                  VALUES (?, ?, ?, ?, NOW(), ?)
                  ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    general_id = VALUES(general_id),
                    effect_json = VALUES(effect_json),
                    activated_at = NOW(),
                    expires_at = VALUES(expires_at)";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException(
                '无法准备主动技能效果 / Unable to prepare active skill effect'
            );
        }
        $stmt->bind_param(
            'iiiss',
            $skillId,
            $userId,
            $generalId,
            $effectJson,
            $expiresAt
        );
        $this->executeOrFail(
            $stmt,
            '无法记录主动技能效果 / Unable to store active skill effect'
        );
        $stmt->close();
    }

    /**
     * 根据全局修正计算实际冷却秒数 / Calculates actual cooldown seconds from the global modifier
     *
     * @param int $baseCooldown 基础冷却秒数 / Base cooldown seconds
     * @return int 实际冷却秒数 / Actual cooldown seconds
     */
    private function calculateCooldownSeconds($baseCooldown): int {
        $modifier = isset($GLOBALS['SKILL_COOLDOWN_MODIFIER'])
            && is_numeric($GLOBALS['SKILL_COOLDOWN_MODIFIER'])
            ? (float) $GLOBALS['SKILL_COOLDOWN_MODIFIER']
            : 1.0;

        if (!is_finite($modifier) || $modifier < 0.0) {
            $modifier = 1.0;
        }

        return max(0, (int) round(max(0, $baseCooldown) * $modifier));
    }

    /**
     * 按技能等级与武将智力计算效果 / Calculates effects from skill level and general intelligence
     *
     * @param array $baseEffects 基础效果 / Base effects
     * @param int $level 技能等级 / Skill level
     * @param int $intelligence 武将智力 / General intelligence
     * @param float $skillPower 技能威力百分比 / Skill-power percentage
     * @return array 计算后的效果 / Calculated effects
     */
    private function calculateEffects(
        array $baseEffects,
        $level,
        $intelligence,
        $skillPower = 0.0
    ): array {
        $levelFactor = 1 + max(0, $level) * 0.2;
        $intelligenceFactor = 1 + max(0, $intelligence) * 0.01;
        $skillPower = is_numeric($skillPower) && is_finite((float) $skillPower)
            ? max(0.0, min(100.0, (float) $skillPower))
            : 0.0;
        $skillPowerFactor = 1 + $skillPower / 100;
        $effects = [];

        foreach ($baseEffects as $effectType => $baseValue) {
            if ($effectType === 'duration' || !is_numeric($baseValue)) {
                $effects[$effectType] = $baseValue;
                continue;
            }

            $effects[$effectType] = round(
                (float) $baseValue
                    * $levelFactor
                    * $intelligenceFactor
                    * $skillPowerFactor,
                2
            );
        }

        return $effects;
    }

    /**
     * 立即向六种资源发放相同数量 / Immediately grants the same amount of all six resources
     *
     * @param int $userId 玩家ID / User ID
     * @param int $amount 每种资源数量 / Amount of each resource
     */
    private function grantAllResources($userId, $amount): void {
        $query = "SELECT resource_id
                  FROM resources
                  WHERE user_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法锁定玩家资源 / Unable to lock user resources');
        }

        $stmt->bind_param('i', $userId);
        $this->executeOrFail(
            $stmt,
            '无法锁定玩家资源 / Unable to lock user resources'
        );
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            throw new DomainException('玩家资源记录不存在 / User resource record does not exist');
        }

        $update = "UPDATE resources
                   SET bright_crystal = bright_crystal + ?,
                       warm_crystal = warm_crystal + ?,
                       cold_crystal = cold_crystal + ?,
                       green_crystal = green_crystal + ?,
                       day_crystal = day_crystal + ?,
                       night_crystal = night_crystal + ?
                   WHERE user_id = ?";
        $stmt = $this->db->prepare($update);

        if (!$stmt) {
            throw new RuntimeException('无法发放技能资源 / Unable to grant skill resources');
        }

        $stmt->bind_param(
            'iiiiiii',
            $amount,
            $amount,
            $amount,
            $amount,
            $amount,
            $amount,
            $userId
        );
        $this->executeOrFail(
            $stmt,
            '无法发放技能资源 / Unable to grant skill resources'
        );
        $stmt->close();
    }

    /**
     * 写入技能卡抽取历史 / Writes skill-card draw history
     *
     * @param int $userId 玩家ID / User ID
     * @param int $cardId 技能卡ID / Skill card ID
     * @param string $rarity 实际稀有度 / Actual rarity
     * @param array $pool 卡池快照 / Pool snapshot
     * @param array $poolEntry 成员权重快照 / Entry weight snapshot
     */
    private function recordDraw(
        $userId,
        $cardId,
        $rarity,
        array $pool,
        array $poolEntry
    ): void {
        $query = "INSERT INTO skill_draw_history
                    (user_id, card_id, rarity, cost_night, pool_id,
                     pool_code_snapshot, pool_revision, entry_weight,
                     total_weight, cost_json)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);

        if (!$stmt) {
            throw new RuntimeException('无法记录技能卡抽取 / Unable to record skill-card draw');
        }

        $costNight = (int) ($pool['cost']['night'] ?? 0);
        $poolId = (int) $pool['pool_id'];
        $poolCode = (string) $pool['pool_code'];
        $poolRevision = (int) $pool['revision'];
        $entryWeight = (int) $poolEntry['entry_weight'];
        $totalWeight = (int) $poolEntry['total_weight'];
        $costJson = json_encode(
            $pool['cost'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($costJson === false) {
            $stmt->close();
            throw new RuntimeException(
                '无法记录卡池成本快照 / Unable to encode pool cost snapshot'
            );
        }
        $stmt->bind_param(
            'iisiisiiis',
            $userId,
            $cardId,
            $rarity,
            $costNight,
            $poolId,
            $poolCode,
            $poolRevision,
            $entryWeight,
            $totalWeight,
            $costJson
        );
        $this->executeOrFail(
            $stmt,
            '无法记录技能卡抽取 / Unable to record skill-card draw'
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
     * 解析并验证技能效果JSON / Decodes and validates skill-effect JSON
     *
     * @param string $effectJson 技能效果JSON / Skill-effect JSON
     * @return array 技能效果 / Skill effects
     */
    private function decodeEffect($effectJson): array {
        $effect = json_decode($effectJson, true);

        if (!is_array($effect) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('技能效果数据无效 / Invalid skill-effect data');
        }

        return $effect;
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
     * 标准化技能卡行 / Normalizes a skill-card row
     *
     * @param array $row 数据库行 / Database row
     * @return array 标准化技能卡 / Normalized skill card
     */
    private function normalizeCardRow(array $row): array {
        $row['card_id'] = (int) $row['card_id'];
        $row['base_cooldown'] = (int) $row['base_cooldown'];
        $row['max_level'] = (int) $row['max_level'];
        $row['is_active'] = (int) $row['is_active'];

        if (isset($row['quantity'])) {
            $row['quantity'] = (int) $row['quantity'];
        }

        $row['effect'] = $this->decodeEffect((string) $row['effect_json']);
        unset($row['effect_json']);

        return $row;
    }

    /**
     * 标准化武将技能行 / Normalizes a general-skill row
     *
     * @param array $row 数据库行 / Database row
     * @return array 标准化技能 / Normalized skill
     */
    private function normalizeSkillRow(array $row): array {
        return [
            'skill_id' => (int) $row['skill_id'],
            'general_id' => (int) $row['general_id'],
            'card_id' => (int) $row['equipped_card_id'],
            'skill_name' => (string) $row['skill_name'],
            'skill_type' => (string) $row['skill_type'],
            'slot' => (int) $row['slot'],
            'skill_level' => (int) $row['skill_level'],
            'skill_effect' => $this->decodeEffect(
                (string) $row['skill_effect']
            )
        ];
    }

    /**
     * 构建一致的服务结果 / Builds a consistent service result
     *
     * @param bool $success 是否成功 / Whether the operation succeeded
     * @param string $message 结果信息 / Result message
     * @param array $cards 技能卡列表 / Skill-card list
     * @param array $extra 附加字段 / Extra fields
     * @return array 结构化结果 / Structured result
     */
    private function result(
        $success,
        $message,
        array $cards = [],
        array $extra = []
    ): array {
        return array_merge(
            [
                'success' => (bool) $success,
                'message' => (string) $message,
                'cards' => $cards
            ],
            $extra
        );
    }
}

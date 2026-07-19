<?php
// 种火集结号 - 战斗类

class Battle {
    private $db;
    private $battleId;
    private $attackerArmyId;
    private $attackerPowerSnapshot = 0;
    private $attackerDamageReductionSnapshot = 0.0;
    private $attackerCompositionSnapshot = null;
    private $attackerNameSnapshot = null;
    private $defenderArmyId;
    private $defenderCityId;
    private $defenderTileId;
    private $battleTime;
    private $result;
    private $attackerLosses;
    private $defenderLosses;
    private $rewards;
    private $tableAvailability = [];
    private $lockedWorldSite = false;
    private $lockedSeasonId = null;
    private $lockedForceOwnerIds = [];
    private $participantSnapshotLoaded = false;
    private $participantSnapshot = null;
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
            $this->attackerPowerSnapshot = isset($battleData['attacker_power_snapshot'])
                ? (int) $battleData['attacker_power_snapshot']
                : 0;
            $this->attackerDamageReductionSnapshot = isset($battleData['attacker_damage_reduction_snapshot'])
                ? (float) $battleData['attacker_damage_reduction_snapshot']
                : 0.0;
            $this->attackerCompositionSnapshot = isset($battleData['attacker_composition_snapshot'])
                ? $battleData['attacker_composition_snapshot']
                : null;
            $this->attackerNameSnapshot = isset($battleData['attacker_name_snapshot'])
                ? $battleData['attacker_name_snapshot']
                : null;
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

        $battleContext = $this->buildAttackerBattleContext(
            $attackerArmy,
            $defenderType,
            $defenderId
        );
        if ($battleContext === null) {
            return false;
        }

        // 保存出发时攻击快照，确保行军期间成长或编成变化不会改写战果 / Save the departure snapshot so growth or composition changes during travel cannot rewrite combat
        $attackerPowerSnapshot = max(
            0,
            (int) $attackerArmy->getCombatPower($battleContext)
        );
        $attackerDamageReductionSnapshot = max(
            0.0,
            min(
                Army::MAX_DAMAGE_REDUCTION_PERCENT,
                (float) $attackerArmy->getDamageReduction(
                    $battleContext
                )
            )
        );
        $attackerComposition = [];
        foreach ($attackerArmy->getUnits() as $unit) {
            if ((int) $unit['quantity'] <= 0) {
                continue;
            }
            $attackerComposition[] = [
                'army_unit_id' => (int) $unit['army_unit_id'],
                'soldier_type' => $unit['soldier_type'],
                'level' => (int) $unit['level'],
                'quantity' => (int) $unit['quantity']
            ];
        }
        if ($attackerPowerSnapshot <= 0 || empty($attackerComposition)) {
            return false;
        }
        $armyModifiers = $attackerArmy->getSkillModifiers(
            $battleContext
        );
        $siegeModifierSnapshot = [
            'siege_damage_percent' => isset(
                $armyModifiers['siege_damage_percent']
            )
                ? (float) $armyModifiers['siege_damage_percent']
                : 0.0,
            'siege_damage_flat' => isset(
                $armyModifiers['siege_damage_flat']
            )
                ? (float) $armyModifiers['siege_damage_flat']
                : 0.0,
            'siege_damage_multiplier' => isset(
                $armyModifiers['siege_damage_multiplier']
            )
                ? (float) $armyModifiers['siege_damage_multiplier']
                : 1.0
        ];
        $attackerCompositionJson = json_encode(
            [
                'schema_version' => 2,
                'units' => $attackerComposition,
                'skill_modifiers' => $siegeModifierSnapshot,
                'battle_context' => [
                    'distance' => max(
                        0,
                        (int) $battleContext['distance']
                    )
                ]
            ],
            JSON_UNESCAPED_UNICODE
        );
        if ($attackerCompositionJson === false) {
            return false;
        }
        $attackerNameSnapshot = $attackerArmy->getName();

        // 创建战斗记录 / Create the pending battle
        $query = "INSERT INTO battles
                     (attacker_army_id, attacker_power_snapshot,
                      attacker_damage_reduction_snapshot,
                      attacker_composition_snapshot, attacker_name_snapshot,
                      defender_army_id, defender_city_id, defender_tile_id,
                      battle_time, result, attacker_losses,
                      defender_losses, rewards)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', NULL, NULL, NULL)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iidssiii',
            $attackerArmyId,
            $attackerPowerSnapshot,
            $attackerDamageReductionSnapshot,
            $attackerCompositionJson,
            $attackerNameSnapshot,
            $defenderArmyId,
            $defenderCityId,
            $defenderTileId
        );
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
        $this->attackerPowerSnapshot = $attackerPowerSnapshot;
        $this->attackerDamageReductionSnapshot = $attackerDamageReductionSnapshot;
        $this->attackerCompositionSnapshot = $attackerCompositionJson;
        $this->attackerNameSnapshot = $attackerNameSnapshot;
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
        if (!$this->isValid || $this->result !== 'pending') {
            return false;
        }

        $attackerUserId = null;
        $defenderUserId = null;
        $battleResult = null;
        $territoryCaptured = false;
        $this->lockedForceOwnerIds = [];
        $this->db->begin_transaction();

        try {
            // 赛季重置采用season→battle顺序；战斗结算必须保持相同顺序 / Season reset locks season then battle; battle resolution must use the same order
            $this->lockCurrentSeasonForScoring();

            // 战斗记录锁定后，后续实体按固定顺序锁定并重新加载 / After locking the battle, lock and reload combatants in a fixed order
            $query = "SELECT * FROM battles WHERE battle_id = ? FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->battleId);
            $stmt->execute();
            $result = $stmt->get_result();
            $battleRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$battleRow || $battleRow['result'] !== 'pending') {
                $this->db->rollback();
                return false;
            }

            $this->attackerArmyId = (int) $battleRow['attacker_army_id'];
            $this->defenderArmyId = $battleRow['defender_army_id'] === null
                ? null
                : (int) $battleRow['defender_army_id'];
            $this->defenderCityId = $battleRow['defender_city_id'] === null
                ? null
                : (int) $battleRow['defender_city_id'];
            $this->defenderTileId = $battleRow['defender_tile_id'] === null
                ? null
                : (int) $battleRow['defender_tile_id'];
            $departureComposition = $this->decodeAttackerCompositionSnapshot(
                isset($battleRow['attacker_composition_snapshot'])
                    ? $battleRow['attacker_composition_snapshot']
                    : null
            );
            $departureSkillModifiers =
                $this->decodeAttackerSkillModifierSnapshot(
                    isset($battleRow['attacker_composition_snapshot'])
                        ? $battleRow['attacker_composition_snapshot']
                        : null
                );
            $departureBattleDistance =
                self::decodeAttackerBattleDistanceSnapshot(
                    isset($battleRow['attacker_composition_snapshot'])
                        ? $battleRow['attacker_composition_snapshot']
                        : null
                );
            $useDepartureSnapshot = isset($battleRow['attacker_power_snapshot'])
                && (int) $battleRow['attacker_power_snapshot'] > 0
                && !empty($departureComposition);

            $defenderType = $this->resolveDefenderType();
            if ($defenderType === null) {
                throw new RuntimeException('战斗目标无效 / Invalid battle target');
            }
            $ownerSnapshot = $this->readCombatOwnerIds($defenderType);
            $forceChainUserIds = [$ownerSnapshot['attacker_user_id']];
            if ($ownerSnapshot['defender_user_id'] !== null) {
                $vassalService = new VassalService();
                $combatUserIds = [
                    $ownerSnapshot['attacker_user_id'],
                    $ownerSnapshot['defender_user_id']
                ];
                $forceChainUserIds =
                    $vassalService->getEffectiveForceChainUserIds(
                        $combatUserIds
                    );
            }
            $this->lockUserRows($forceChainUserIds);
            if ($ownerSnapshot['defender_user_id'] !== null) {
                // 锁住预读链后再次解析；若此前链条已变化，则回滚并由下一轮重试。
                // Resolve again after locking the previewed chain; if it changed
                // beforehand, roll back for a clean retry instead of adding
                // out-of-order user locks.
                $verifiedForceChainUserIds =
                    $vassalService->getEffectiveForceChainUserIdsForUpdate(
                        $combatUserIds,
                        $forceChainUserIds
                    );
                if (!empty(array_diff(
                    $verifiedForceChainUserIds,
                    $forceChainUserIds
                ))) {
                    throw new RuntimeException(
                        '有效势力链已经变化，请重试战斗 / Effective-force chain changed; retry the battle'
                    );
                }
                // 附属关系先于联盟关系锁定，因为它会覆盖有效势力归属。 / Lock vassalage before alliance membership because it overrides effective-force ownership.
                $vassalService->lockRelationsForUsers(
                    $verifiedForceChainUserIds
                );
                $this->lockAllianceMemberships(
                    $verifiedForceChainUserIds
                );
                $this->lockedForceOwnerIds =
                    $vassalService->getEffectiveForceOwnerIdsForUpdate(
                        $combatUserIds,
                        $forceChainUserIds
                    );
            }
            $this->lockCombatState($defenderType);

            // 所有相关行已锁定，现在重新构造对象以丢弃事务前快照 / Recreate objects after locking to discard pre-transaction snapshots
            $attackerArmy = new Army($this->attackerArmyId);
            if ($defenderType === 'army') {
                $defender = new Army($this->defenderArmyId);
            } elseif ($defenderType === 'city') {
                $defender = new City($this->defenderCityId);
            } else {
                $defender = new Map($this->defenderTileId);
            }
            if (!$attackerArmy->isValid()
                || $attackerArmy->getStatus() !== 'idle'
                || !$defender->isValid()) {
                $this->cancelPendingBattleLocked();
                $this->db->commit();
                return false;
            }
            if ($defenderType === 'army' && $defender->getStatus() !== 'idle') {
                $this->cancelPendingBattleLocked();
                $this->db->commit();
                return false;
            }
            if ($defenderType === 'tile'
                && ($this->lockedWorldSite
                    || !in_array(
                        $defender->getType(),
                        ['empty', 'resource', 'npc_fort'],
                        true
                    )
                    || (in_array($defender->getType(), ['empty', 'resource'], true)
                        && $defender->getOwnerId() === null))) {
                $this->cancelPendingBattleLocked();
                $this->db->commit();
                return false;
            }

            $attackerPosition = $attackerArmy->getCurrentPosition();
            $defenderPosition = $this->getCombatantPosition($defenderType, $defender);
            if (!$defenderPosition
                || (int) $attackerPosition[0] !== (int) $defenderPosition[0]
                || (int) $attackerPosition[1] !== (int) $defenderPosition[1]) {
                $this->cancelPendingBattleLocked();
                $this->db->commit();
                return false;
            }

            $attackerUserId = (int) $attackerArmy->getOwnerId();
            $defenderUserId = $this->getDefenderOwnerId($defenderType, $defender);
            if ($attackerUserId !== $ownerSnapshot['attacker_user_id']
                || $defenderUserId !== $ownerSnapshot['defender_user_id']) {
                throw new RuntimeException('参战方归属已经变化 / Combatant ownership changed');
            }
            if ($defenderUserId !== null) {
                $attackerForceOwnerId = isset(
                    $this->lockedForceOwnerIds[$attackerUserId]
                )
                    ? (int) $this->lockedForceOwnerIds[$attackerUserId]
                    : null;
                $defenderForceOwnerId = isset(
                    $this->lockedForceOwnerIds[$defenderUserId]
                )
                    ? (int) $this->lockedForceOwnerIds[$defenderUserId]
                    : null;
                if ($attackerForceOwnerId === null
                    || $defenderForceOwnerId === null
                    || $attackerForceOwnerId === $defenderForceOwnerId) {
                    $this->cancelPendingBattleLocked();
                    $this->db->commit();
                    return false;
                }
            }

            // 新战斗始终按出发快照结算；旧迁移记录的零快照才回退实时军队 / New battles resolve from departure snapshots; only legacy zero snapshots fall back to the live army
            $attackerUnits = $useDepartureSnapshot
                ? $departureComposition
                : $this->getCombatComposition('army', $attackerArmy);
            $defenderUnits = $this->getCombatComposition($defenderType, $defender);
            $attackerContext = [
                'phase' => 'battle',
                'side' => 'attack',
                'target_tags' => $this->getDefenderTargetTags(
                    $defenderType,
                    $defender
                ),
                'distance' => $departureBattleDistance
            ];
            $rawAttackerPower = $useDepartureSnapshot
                ? (int) $battleRow['attacker_power_snapshot']
                : $attackerArmy->getCombatPower($attackerContext);
            $defenderContext = [
                'phase' => $defenderType === 'city'
                    ? 'city_defense'
                    : 'battle',
                'side' => 'defense',
                'target_tags' => ['army', 'player'],
                'distance' => $departureBattleDistance
            ];
            $rawDefenderPower = $this->getDefenderPower(
                $defenderType,
                $defender,
                $defenderUnits,
                $defenderContext
            );
            if (empty($attackerUnits)
                || $rawAttackerPower <= 0
                || ($defenderType === 'army' && empty($defenderUnits))) {
                $this->cancelPendingBattleLocked();
                $this->db->commit();
                return false;
            }
            $attackerCounter = $this->calculateCompositionCounterMultiplier(
                $attackerUnits,
                $defenderUnits
            );
            $defenderCounter = $this->calculateCompositionCounterMultiplier(
                $defenderUnits,
                $attackerUnits
            );
            $attackerPower = (int) round($rawAttackerPower * $attackerCounter);
            $defenderPower = (int) round($rawDefenderPower * $defenderCounter);
            $battleResult = GameRules::calculateBattleOutcome(
                $attackerPower,
                $defenderPower
            );

            $lossRates = GameRules::getBattleLossRates($battleResult);
            $attackerDamageReduction = $useDepartureSnapshot
                ? (float) $battleRow['attacker_damage_reduction_snapshot']
                : $attackerArmy->getDamageReduction($attackerContext);
            $defenderDamageReduction = $defenderType === 'army'
                ? $defender->getDamageReduction($defenderContext)
                : 0.0;
            $attackerLossRate = $this->applyDamageReduction(
                $lossRates['attacker'],
                $attackerDamageReduction
            );
            $defenderLossRate = $this->applyDamageReduction(
                $lossRates['defender'],
                $defenderDamageReduction
            );
            $attackerLosses = $this->calculateCompositionLosses(
                $attackerUnits,
                $attackerLossRate
            );
            $defenderLosses = $this->calculateDefenderLosses(
                $defenderType,
                $defender,
                $defenderLossRate
            );
            $rewards = $this->calculateRewards(
                $defenderType,
                $defender,
                $battleResult
            );
            if ($defenderType === 'tile'
                && isset($rewards['tile_control'])
                && $this->hasCompositionSurvivors(
                    $defenderUnits,
                    isset($defenderLosses['soldier_losses'])
                        ? $defenderLosses['soldier_losses']
                        : []
                )) {
                // 领地守军尚存时不能转移控制权 / Territory control cannot transfer while its garrison survives
                unset($rewards['tile_control']);
            }

            $captivePlan = $this->buildCaptivePlan(
                $battleResult,
                $attackerUserId,
                $defenderUserId,
                $attackerLosses,
                $this->getCapturableDefenderLosses(
                    $defenderType,
                    $defenderLosses
                )
            );
            if (!empty($captivePlan['units'])) {
                $rewards['captives'] = $captivePlan;
            }

            $generalHpLosses = [
                'attacker' => $this->applyAssignedGeneralHpDamage(
                    'army',
                    $attackerArmy->getArmyId(),
                    $attackerLossRate
                ),
                'defender' => in_array($defenderType, ['army', 'city'], true)
                    ? $this->applyAssignedGeneralHpDamage(
                        $defenderType,
                        $defenderType === 'army'
                            ? $defender->getArmyId()
                            : $defender->getCityId(),
                        $defenderLossRate
                    )
                    : []
            ];
            if (!empty($generalHpLosses['attacker'])
                || !empty($generalHpLosses['defender'])) {
                $rewards['general_hp_losses'] = $generalHpLosses;
            }

            $territoryCaptured = $this->applyBattleResults(
                $attackerArmy,
                $defenderType,
                $defender,
                $battleResult,
                $attackerLosses,
                $defenderLosses,
                $rewards,
                $attackerUnits,
                $useDepartureSnapshot
                    ? $departureSkillModifiers
                    : $attackerArmy->getSkillModifiers($attackerContext)
            );

            $counterDetails = [
                'raw_attacker_power' => $rawAttackerPower,
                'raw_defender_power' => $rawDefenderPower,
                'attacker_multiplier' => $attackerCounter,
                'defender_multiplier' => $defenderCounter,
                'attacker_composition' => $attackerUnits,
                'defender_composition' => $defenderUnits,
                'attacker_damage_reduction' => $attackerDamageReduction,
                'defender_damage_reduction' => $defenderDamageReduction,
                'attacker_loss_rate' => $attackerLossRate,
                'defender_loss_rate' => $defenderLossRate,
                'general_hp_losses' => $generalHpLosses,
                'outcome' => $battleResult
            ];
            $this->recordBattleParticipantIfAvailable(
                $attackerUserId,
                $defenderUserId,
                $attackerPower,
                $defenderPower,
                $counterDetails
            );

            $this->persistCaptivesIfAvailable($captivePlan);
            $this->updateSeasonScoresInTransaction(
                $battleResult,
                $attackerUserId,
                $defenderUserId,
                $territoryCaptured
            );

            $attackerLossesJson = json_encode($attackerLosses, JSON_UNESCAPED_UNICODE);
            $defenderLossesJson = json_encode($defenderLosses, JSON_UNESCAPED_UNICODE);
            $rewardsJson = json_encode($rewards, JSON_UNESCAPED_UNICODE);
            $query = "UPDATE battles
                      SET result = ?, attacker_losses = ?,
                          defender_losses = ?, rewards = ?
                      WHERE battle_id = ? AND result = 'pending'";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'ssssi',
                $battleResult,
                $attackerLossesJson,
                $defenderLossesJson,
                $rewardsJson,
                $this->battleId
            );
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException('战斗记录已经变化 / Battle row changed');
            }

            $this->db->commit();

            $this->result = $battleResult;
            $this->attackerLosses = $attackerLossesJson;
            $this->defenderLosses = $defenderLossesJson;
            $this->rewards = $rewardsJson;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Legacy battle resolution failed: ' . $exception->getMessage());
            return false;
        }

        // 任务事件必须在主事务提交后记录，避免ProgressService嵌套事务 / Record quest events after commit to avoid nested ProgressService transactions
        $this->recordPostCommitBattleEvents(
            $battleResult,
            $attackerUserId,
            $defenderUserId,
            $territoryCaptured
        );
        return true;
    }

    /**
     * 从锁定的战斗记录解析目标类型 / Resolve the target type from the locked battle row
     */
    private function resolveDefenderType() {
        if ($this->defenderArmyId !== null) {
            return 'army';
        }
        if ($this->defenderCityId !== null) {
            return 'city';
        }
        if ($this->defenderTileId !== null) {
            return 'tile';
        }
        return null;
    }

    /**
     * 在加锁前读取参战用户，用于建立统一用户锁序 / Read participant users before locking so every battle uses one user-lock order
     */
    private function readCombatOwnerIds($defenderType) {
        $query = "SELECT owner_id FROM armies WHERE army_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->attackerArmyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $attacker = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$attacker) {
            throw new RuntimeException('攻击军队已经不存在 / Attacking army no longer exists');
        }

        $defenderUserId = null;
        if ($defenderType === 'army') {
            $query = "SELECT owner_id FROM armies WHERE army_id = ?";
            $defenderId = $this->defenderArmyId;
        } elseif ($defenderType === 'city') {
            $query = "SELECT owner_id FROM cities WHERE city_id = ?";
            $defenderId = $this->defenderCityId;
        } else {
            $query = "SELECT owner_id FROM map_tiles WHERE tile_id = ?";
            $defenderId = $this->defenderTileId;
        }
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $defenderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $defender = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$defender) {
            throw new RuntimeException('防守目标已经不存在 / Defending target no longer exists');
        }
        if ($defender['owner_id'] !== null) {
            $defenderUserId = (int) $defender['owner_id'];
        }

        return [
            'attacker_user_id' => (int) $attacker['owner_id'],
            'defender_user_id' => $defenderUserId
        ];
    }

    /**
     * 按用户ID顺序锁定参战势力链玩家 / Lock battle force-chain users in ascending user-ID order
     * @param array $userIds 玩家ID / User IDs
     * @return void
     */
    private function lockUserRows($userIds) {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $userIds),
            function ($userId) {
                return $userId > 0;
            }
        )));
        sort($userIds, SORT_NUMERIC);
        foreach ($userIds as $userId) {
            $query = "SELECT user_id
                      FROM users
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $locked = $result && $result->num_rows === 1;
            $stmt->close();
            if (!$locked) {
                throw new RuntimeException('参战用户已经不存在 / Battle user no longer exists');
            }
        }
    }

    /**
     * 以固定顺序锁定战斗实体、兵力与随军武将 / Lock combatants, troops, and assigned generals in a fixed order
     */
    private function lockCombatState($defenderType) {
        $armyIds = [$this->attackerArmyId];
        if ($defenderType === 'army') {
            $armyIds[] = $this->defenderArmyId;
        } elseif ($defenderType === 'city') {
            // 在锁城前收集并锁定所有关联军队，避免与 army→city 的解散事务形成反向等待 / Collect and lock every linked army before the city to avoid reversing disband's army-to-city order
            $query = "SELECT owner_id, x, y
                      FROM cities
                      WHERE city_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->defenderCityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $cityPreview = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$cityPreview) {
                throw new RuntimeException('目标城池已经不存在 / Target city no longer exists');
            }

            $query = "SELECT army_id
                      FROM armies
                      WHERE owner_id = ? AND city_id = ?
                      ORDER BY army_id";
            $stmt = $this->db->prepare($query);
            $cityOwnerId = (int) $cityPreview['owner_id'];
            $stmt->bind_param(
                'ii',
                $cityOwnerId,
                $this->defenderCityId
            );
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $armyIds[] = (int) $row['army_id'];
            }
            $stmt->close();
        }
        $armyIds = array_values(array_unique(array_map('intval', $armyIds)));
        sort($armyIds, SORT_NUMERIC);

        foreach ($armyIds as $armyId) {
            $query = "SELECT army_id FROM armies
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $locked = $result && $result->num_rows === 1;
            $stmt->close();
            if (!$locked) {
                throw new RuntimeException('战斗军队已经不存在 / A combat army no longer exists');
            }
        }

        foreach ($armyIds as $armyId) {
            $query = "SELECT army_unit_id FROM army_units
                      WHERE army_id = ?
                      ORDER BY army_unit_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $stmt->get_result();
            $stmt->close();
        }

        if ($defenderType === 'city') {
            $query = "SELECT city_id, owner_id, x, y
                      FROM cities
                      WHERE city_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->defenderCityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $cityRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$cityRow) {
                throw new RuntimeException('目标城池已经不存在 / Target city no longer exists');
            }
            if ((int) $cityRow['owner_id'] !== (int) $cityPreview['owner_id']
                || (int) $cityRow['x'] !== (int) $cityPreview['x']
                || (int) $cityRow['y'] !== (int) $cityPreview['y']) {
                throw new RuntimeException('目标城池已经变化 / Target city changed');
            }

            $query = "SELECT soldier_id FROM soldiers
                      WHERE city_id = ?
                      ORDER BY soldier_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->defenderCityId);
            $stmt->execute();
            $stmt->get_result();
            $stmt->close();

            // 城池转移会同步修改地图格，必须一并锁定 / Lock the city map tile because capture updates it too
            $query = "SELECT tile_id FROM map_tiles
                      WHERE x = ? AND y = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $cityX = (int) $cityRow['x'];
            $cityY = (int) $cityRow['y'];
            $stmt->bind_param('ii', $cityX, $cityY);
            $stmt->execute();
            $stmt->get_result();
            $stmt->close();

            // 战前把城内待命旧主军队原子并入驻军，使其兵力与武将参与本次防守 / Atomically merge eligible idle former-owner armies into the city garrison before defense is calculated
            $this->mergeIdleCityArmiesIntoGarrison(
                $this->defenderCityId,
                (int) $cityRow['owner_id'],
                $cityX,
                $cityY
            );
        } elseif ($defenderType === 'tile') {
            $query = "SELECT tile_id FROM map_tiles
                      WHERE tile_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->defenderTileId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tileExists = $result && $result->num_rows === 1;
            $stmt->close();
            if (!$tileExists) {
                throw new RuntimeException('目标地图格已经不存在 / Target tile no longer exists');
            }

            if ($this->isOptionalTableAvailable('territory_garrisons')) {
                $query = "SELECT garrison_id FROM territory_garrisons
                          WHERE tile_id = ?
                          ORDER BY garrison_id
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $this->defenderTileId);
                $stmt->execute();
                $stmt->get_result();
                $stmt->close();
            }
            if ($this->isOptionalTableAvailable('world_sites')) {
                $query = "SELECT site_id FROM world_sites
                          WHERE tile_id = ?
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $this->defenderTileId);
                $stmt->execute();
                $result = $stmt->get_result();
                $this->lockedWorldSite = $result && $result->num_rows > 0;
                $stmt->close();
            }
        }

        $this->lockAssignedGeneralRows($defenderType);
    }

    /**
     * 将目标城内无待处理战斗的待命军队并入城防 / Merge idle home-city armies without pending battles into the city defense
     */
    private function mergeIdleCityArmiesIntoGarrison(
        $cityId,
        $ownerId,
        $cityX,
        $cityY
    ) {
        $query = "SELECT a.army_id
                  FROM armies a
                  WHERE a.owner_id = ? AND a.city_id = ?
                    AND a.status = 'idle'
                    AND a.current_x = ? AND a.current_y = ?
                    AND a.army_id <> ?
                    AND NOT EXISTS (
                        SELECT 1
                        FROM battles pending_battle
                        WHERE (pending_battle.attacker_army_id = a.army_id
                               OR pending_battle.defender_army_id = a.army_id)
                          AND pending_battle.result = 'pending'
                    )
                  ORDER BY a.army_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iiiii',
            $ownerId,
            $cityId,
            $cityX,
            $cityY,
            $this->attackerArmyId
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $armyIds = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $armyIds[] = (int) $row['army_id'];
        }
        $stmt->close();

        foreach ($armyIds as $armyId) {
            $query = "SELECT army_unit_id, soldier_type, level, quantity
                      FROM army_units
                      WHERE army_id = ?
                      ORDER BY army_unit_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $units = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $units[] = $row;
            }
            $stmt->close();

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
                $quantity = max(0, (int) $unit['quantity']);
                $stmt->bind_param(
                    'isii',
                    $cityId,
                    $soldierType,
                    $level,
                    $quantity
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('无法并入城防兵力 / Failed to merge army troops into city defense');
                }
                $stmt->close();
            }

            // 先锁定该军武将，再把分配目标改为城池 / Lock army generals before changing their assignment target to the city
            $query = "SELECT g.general_id
                      FROM general_assignments ga
                      INNER JOIN generals g ON g.general_id = ga.general_id
                      WHERE ga.assignment_type = 'army' AND ga.target_id = ?
                      ORDER BY g.general_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $stmt->get_result();
            $stmt->close();

            $query = "UPDATE general_assignments
                      SET assignment_type = 'city', target_id = ?
                      WHERE assignment_type = 'army' AND target_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $cityId, $armyId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法并入驻城武将 / Failed to merge army generals into city defense');
            }
            $stmt->close();

            $query = "DELETE FROM armies
                      WHERE army_id = ? AND owner_id = ?
                        AND status = 'idle'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM battles pending_battle
                            WHERE (pending_battle.attacker_army_id = armies.army_id
                                   OR pending_battle.defender_army_id = armies.army_id)
                              AND pending_battle.result = 'pending'
                        )";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $armyId, $ownerId);
            $deleted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$deleted) {
                throw new RuntimeException('城内军队状态已经变化 / Home-city army state changed');
            }
        }
    }

    /**
     * 按武将ID锁定参战方的分配、武将与成长记录 / Lock assignments, generals, and progression rows by general ID
     */
    private function lockAssignedGeneralRows($defenderType) {
        if ($defenderType === 'army') {
            $query = "SELECT g.general_id
                      FROM general_assignments ga
                      INNER JOIN generals g ON g.general_id = ga.general_id
                      WHERE ga.assignment_type = 'army'
                        AND ga.target_id IN (?, ?)
                      ORDER BY g.general_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'ii',
                $this->attackerArmyId,
                $this->defenderArmyId
            );
        } elseif ($defenderType === 'city') {
            $query = "SELECT g.general_id
                      FROM general_assignments ga
                      INNER JOIN generals g ON g.general_id = ga.general_id
                      WHERE (ga.assignment_type = 'army' AND ga.target_id = ?)
                         OR (ga.assignment_type = 'city' AND ga.target_id = ?)
                      ORDER BY g.general_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'ii',
                $this->attackerArmyId,
                $this->defenderCityId
            );
        } else {
            $query = "SELECT g.general_id
                      FROM general_assignments ga
                      INNER JOIN generals g ON g.general_id = ga.general_id
                      WHERE ga.assignment_type = 'army' AND ga.target_id = ?
                      ORDER BY g.general_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $this->attackerArmyId);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $generalIds = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $generalIds[] = (int) $row['general_id'];
        }
        $stmt->close();

        if (!$this->isOptionalTableAvailable('general_progression')) {
            return;
        }
        foreach ($generalIds as $generalId) {
            $query = "SELECT general_id FROM general_progression
                      WHERE general_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $generalId);
            $stmt->execute();
            $stmt->get_result();
            $stmt->close();
        }
    }

    /**
     * 取消已锁定的过期待处理战斗 / Cancel a locked stale pending battle
     */
    private function cancelPendingBattleLocked() {
        $query = "DELETE FROM battles
                  WHERE battle_id = ? AND result = 'pending'";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->battleId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法取消过期战斗 / Failed to cancel stale battle');
        }
        $stmt->close();
    }

    /**
     * 将武将减伤应用到损失率 / Apply general damage reduction to a troop-loss rate
     */
    private function applyDamageReduction($lossRate, $damageReduction) {
        $rate = max(0.0, min(1.0, (float) $lossRate));
        $reduction = max(
            0.0,
            min(Army::MAX_DAMAGE_REDUCTION_PERCENT, (float) $damageReduction)
        );
        return max(0.0, $rate * (1 - $reduction / 100));
    }

    /**
     * 按用户ID锁定势力链联盟关系，防止结算途中阵营变化 / Lock force-chain memberships by user ID to prevent mid-resolution force changes
     * @param array $userIds 玩家ID / User IDs
     * @return void
     */
    private function lockAllianceMemberships($userIds) {
        if (!$this->isOptionalTableAvailable('alliance_members')) {
            return;
        }
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $userIds),
            function ($userId) {
                return $userId > 0;
            }
        )));
        sort($userIds, SORT_NUMERIC);
        foreach ($userIds as $userId) {
            $query = "SELECT member_id
                      FROM alliance_members
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->get_result();
            $stmt->close();
        }
    }

    /**
     * 按有效损失率扣除参战武将HP并重置恢复计时 / Damage assigned generals by effective loss rate and reset recovery timing
     * @return array 武将HP损失摘要 / General HP-loss summary
     */
    private function applyAssignedGeneralHpDamage(
        $assignmentType,
        $targetId,
        $lossRate
    ) {
        $rate = max(0.0, min(0.50, (float) $lossRate));
        if ($rate <= 0.0
            || !in_array($assignmentType, ['army', 'city'], true)
            || (int) $targetId <= 0) {
            return [];
        }

        $query = "SELECT g.general_id, g.name, g.hp, g.max_hp
                  FROM general_assignments ga
                  INNER JOIN generals g ON g.general_id = ga.general_id
                  WHERE ga.assignment_type = ? AND ga.target_id = ?
                    AND g.is_active = 1 AND g.hp > 0
                  ORDER BY g.general_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('si', $assignmentType, $targetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $generals = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $generals[] = $row;
        }
        $stmt->close();

        $losses = [];
        foreach ($generals as $general) {
            $currentHp = max(0, (int) $general['hp']);
            $maxHp = max(1, (int) $general['max_hp']);
            $hpLoss = min($currentHp, max(1, (int) ceil($maxHp * $rate)));
            $newHp = $currentHp - $hpLoss;
            $query = "UPDATE generals
                      SET hp = ?
                      WHERE general_id = ? AND hp = ? AND hp > 0";
            $stmt = $this->db->prepare($query);
            $generalId = (int) $general['general_id'];
            $stmt->bind_param('iii', $newHp, $generalId, $currentHp);
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException('武将HP已经变化 / General HP changed');
            }

            if ($this->isOptionalTableAvailable('general_progression')) {
                $query = "INSERT INTO general_progression
                             (general_id, last_hp_recovery)
                          VALUES (?, NOW())
                          ON DUPLICATE KEY UPDATE last_hp_recovery = NOW()";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $generalId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('无法重置武将恢复时间 / Failed to reset HP recovery timing');
                }
                $stmt->close();
            }

            $losses[] = [
                'general_id' => $generalId,
                'name' => $general['name'],
                'hp_lost' => $hpLoss,
                'remaining_hp' => $newHp
            ];
        }

        return $losses;
    }

    /**
     * 获取防守方战斗力 / Gets defender combat power
     * @param string $defenderType 防守方类型 / Defender type
     * @param object $defender 防守方对象 / Defender
     * @param array $composition 防守方兵力 / Defender composition
     * @param array $context 防守上下文 / Defense context
     * @return int 战斗力 / Combat power
     */
    private function getDefenderPower(
        $defenderType,
        $defender,
        $composition = [],
        array $context = []
    ) {
        switch ($defenderType) {
            case 'army':
                return $defender->getCombatPower($context);
            case 'city':
                // 城池方法已经包含守军、耐久、策略与驻城武将，不能重复结算 / City power already includes troops, durability, strategy, and assigned generals
                return $defender->getDefensePower($context);
            case 'tile':
                // 地图格子防御力
                if ($defender->getType() == 'npc_fort') {
                    // NPC城池防御力 = NPC等级 * 200 + NPC驻军数量 * 10
                    return $defender->getNpcLevel() * 200 + $defender->getNpcGarrison() * 10;
                } elseif (in_array($defender->getType(), ['empty', 'resource'], true)) {
                    // 资源点保留少量基础防御，空地与两类领地都结算真实驻军 / Resource tiles retain a small base defense while both ordinary tile types use their real garrisons
                    $baseDefense = $defender->getType() === 'resource' ? 50 : 0;
                    return $baseDefense
                        + $this->calculateCompositionDefensePower($composition);
                } else {
                    return 0;
                }
            default:
                return 0;
        }
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
                return $this->calculateCompositionLosses(
                    $this->getCombatComposition('army', $defender),
                    $lossPercentage
                );
            case 'city':
                // 城池耐久只由战后存活锤子兵造成，兵力阶段仅计算守军损失 / City durability is damaged only by surviving golems after troop resolution
                return [
                    'soldier_losses' => $this->calculateCompositionLosses(
                        $this->getCombatComposition('city', $defender),
                        $lossPercentage
                    )
                ];
            case 'tile':
                // 地图格子损失
                if ($defender->getType() == 'resource') {
                    // 资源点损失 = 资源量减少
                    $resourceAmount = $defender->getResourceAmount();
                    $resourceLoss = ceil($resourceAmount * $lossPercentage);

                    return [
                        'resource_loss' => $resourceLoss,
                        'soldier_losses' => $this->calculateCompositionLosses(
                            $this->getCombatComposition('tile', $defender),
                            $lossPercentage
                        )
                    ];
                } elseif ($defender->getType() == 'empty') {
                    return [
                        'soldier_losses' => $this->calculateCompositionLosses(
                            $this->getCombatComposition('tile', $defender),
                            $lossPercentage
                        )
                    ];
                } elseif ($defender->getType() == 'npc_fort') {
                    $garrison = max(0, (int) $defender->getNpcGarrison());
                    return [
                        'npc_garrison_loss' => GameRules::calculateBattleLosses(
                            $garrison,
                            $lossPercentage
                        )
                    ];
                }
                return [];
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
        // 只有攻击方获胜才发放奖励，平局也不能获得攻城收益 / Award rewards only for attacker victories; draws grant no siege rewards
        if (!in_array($battleResult, ['attacker_win', 'attacker_win_big'], true)) {
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
                }
                // 副城易主或主城附属都必须先清空防御并耗尽耐久。 / Both secondary-city transfer and main-city subjugation require cleared defenses and depleted durability.
                $rewards['capture_city'] = true;
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
                } elseif (in_array($defender->getType(), ['empty', 'resource'], true)) {
                    // 攻占普通领地的奖励 / Ordinary-territory control reward
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
     * @param array $attackerComposition 攻击方编成快照 / Attacker composition snapshot
     * @param array $attackerSkillModifiers 攻城修正快照 / Siege-modifier snapshot
     */
    private function applyBattleResults(
        $attackerArmy,
        $defenderType,
        $defender,
        $battleResult,
        $attackerLosses,
        &$defenderLosses,
        &$rewards,
        $attackerComposition,
        $attackerSkillModifiers = []
    ) {
        $territoryCaptured = false;
        $this->applyArmyLosses($attackerArmy, $attackerLosses);
        $attackerWon = in_array(
            $battleResult,
            ['attacker_win', 'attacker_win_big'],
            true
        );
        $attackerId = (int) $attackerArmy->getOwnerId();

        switch ($defenderType) {
            case 'army':
                $this->applyArmyLosses($defender, $defenderLosses);
                break;
            case 'city':
                $oldOwnerId = (int) $defender->getOwnerId();
                $cityId = (int) $defender->getCityId();
                if (!empty($defenderLosses['soldier_losses'])) {
                    $this->applyCitySoldierLosses(
                        $defender,
                        $defenderLosses['soldier_losses']
                    );
                }

                $cityDefenseCleared = $this->isCityDefenseCleared(
                    $cityId,
                    $oldOwnerId
                );
                $durabilityDamage = 0;
                if ($attackerWon && $cityDefenseCleared) {
                    $durabilityDamage = $this->calculateSurvivingGolemSiegeDamage(
                        $attackerComposition,
                        $attackerLosses,
                        $attackerSkillModifiers
                    );
                }
                $durabilityDamage = min(
                    max(0, (int) $defender->getDurability()),
                    $durabilityDamage
                );
                if ($durabilityDamage > 0) {
                    $query = "UPDATE cities
                              SET durability = GREATEST(0, durability - ?)
                              WHERE city_id = ?";
                    $stmt = $this->db->prepare($query);
                    $stmt->bind_param('ii', $durabilityDamage, $cityId);
                    $damaged = $stmt->execute() && $stmt->affected_rows === 1;
                    if (!$damaged) {
                        $stmt->close();
                        throw new RuntimeException('无法更新城池耐久 / Failed to update city durability');
                    }
                    $stmt->close();
                    $defenderLosses['durability_loss'] = $durabilityDamage;
                }

                if (!empty($rewards['capture_city'])
                    && (!$attackerWon
                        || !$cityDefenseCleared
                        || !$this->isCityDurabilityDepleted($cityId))) {
                    // 士兵、武将或耐久尚存时只造成损害，不转移城池 / Damage alone does not transfer a city while troops, generals, or durability remain
                    unset($rewards['capture_city']);
                }

                if (!empty($rewards['capture_city'])
                    && $defender->isMainCity()) {
                    // 主城物理所有权始终保留给原玩家；这里只改变附属关系或执行救出。 / A main city always remains physically owned by its player; this branch only changes vassalage or performs rescue.
                    $vassalService = new VassalService();
                    $rewards['main_city_resolution'] =
                        $vassalService->resolveMainCityCaptureInTransaction(
                            $attackerId,
                            $oldOwnerId,
                            $this->battleId,
                            $this->lockedForceOwnerIds
                        );
                    $query = "UPDATE cities
                              SET durability = max_durability
                              WHERE city_id = ? AND owner_id = ?
                                AND is_main_city = 1";
                    $stmt = $this->db->prepare($query);
                    $stmt->bind_param('ii', $cityId, $oldOwnerId);
                    $restored = $stmt->execute()
                        && $stmt->affected_rows === 1;
                    $stmt->close();
                    if (!$restored) {
                        throw new RuntimeException(
                            '无法恢复主城耐久 / Failed to restore main-city durability'
                        );
                    }
                    unset($rewards['capture_city']);
                } elseif (!empty($rewards['capture_city'])) {
                    if (!$this->consumeTerritoryOccupationCost($attackerId)) {
                        // 战斗胜利仍成立，但思考回路不足时不转移控制权 / Victory still stands, but insufficient circuit points prevent ownership transfer
                        unset($rewards['capture_city']);
                        $rewards['occupation_blocked'] = true;
                    } else {
                        $query = "UPDATE cities
                              SET owner_id = ?
                              WHERE city_id = ? AND owner_id = ?
                                AND is_main_city = 0";
                        $stmt = $this->db->prepare($query);
                        $stmt->bind_param(
                            'iii',
                            $attackerId,
                            $cityId,
                            $oldOwnerId
                        );
                        $captured = $stmt->execute() && $stmt->affected_rows === 1;
                        $stmt->close();
                        if (!$captured) {
                            throw new RuntimeException('城池拥有权已经变化 / City ownership changed');
                        }

                        $coordinates = $defender->getCoordinates();
                        $query = "UPDATE map_tiles
                              SET owner_id = ?
                              WHERE x = ? AND y = ? AND owner_id = ?";
                        $stmt = $this->db->prepare($query);
                        $cityX = (int) $coordinates[0];
                        $cityY = (int) $coordinates[1];
                        $stmt->bind_param(
                            'iiii',
                            $attackerId,
                            $cityX,
                            $cityY,
                            $oldOwnerId
                        );
                        $mapped = $stmt->execute() && $stmt->affected_rows === 1;
                        $stmt->close();
                        if (!$mapped) {
                            throw new RuntimeException('城池地图拥有权已经变化 / City map ownership changed');
                        }

                        // 仍以失守城池为归属的旧主军队改挂旧主主城，避免引用敌方城池 / Re-home remaining former-owner armies to their main city so they never reference an enemy city
                        $this->rerouteFormerOwnerArmies(
                            $oldOwnerId,
                            $cityId
                        );

                        // 城池失守后解除原驻城武将，避免恢复后仍占用敌方城池 / Unassign former city generals so recovered units do not remain attached to an enemy city
                        $query = "DELETE FROM general_assignments
                              WHERE assignment_type = 'city' AND target_id = ?";
                        $stmt = $this->db->prepare($query);
                        $stmt->bind_param('i', $cityId);
                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new RuntimeException('无法解除失守城池武将 / Failed to unassign captured-city generals');
                        }
                        $stmt->close();
                        $territoryCaptured = true;
                    }
                }

                // 资源行最后按用户ID锁定并做真实转移，避免先锁资源再锁城池 / Lock resource rows last by user ID and transfer actual loot without minting
                if ($attackerWon && isset($rewards['resources'])) {
                    $rewards['resources'] = $this->transferCityResources(
                        $attackerId,
                        $oldOwnerId,
                        $rewards['resources']
                    );
                }
                break;
            case 'tile':
                if (!empty($defenderLosses['soldier_losses'])) {
                    $this->applyTileGarrisonLosses(
                        (int) $defender->getTileId(),
                        (int) $defender->getOwnerId(),
                        $defenderLosses['soldier_losses']
                    );
                }
                if ($defender->getType() == 'resource' && isset($defenderLosses['resource_loss'])) {
                    $query = "UPDATE map_tiles
                              SET resource_amount = GREATEST(
                                  0,
                                  resource_amount - ?
                              )
                              WHERE tile_id = ?";
                    $stmt = $this->db->prepare($query);
                    $resourceLoss = max(0, (int) $defenderLosses['resource_loss']);
                    $tileId = (int) $defender->getTileId();
                    $stmt->bind_param('ii', $resourceLoss, $tileId);
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new RuntimeException('无法更新资源点 / Failed to update resource tile');
                    }
                    $stmt->close();
                } elseif ($defender->getType() == 'npc_fort') {
                    $garrisonBefore = max(0, (int) $defender->getNpcGarrison());
                    $garrisonLoss = isset($defenderLosses['npc_garrison_loss'])
                        ? max(
                            0,
                            min(
                                $garrisonBefore,
                                (int) $defenderLosses['npc_garrison_loss']
                            )
                        )
                        : 0;
                    $tileId = (int) $defender->getTileId();
                    if ($garrisonLoss > 0) {
                        $query = "UPDATE map_tiles
                                  SET npc_garrison = npc_garrison - ?
                                  WHERE tile_id = ? AND npc_garrison = ?
                                    AND npc_garrison >= ?";
                        $stmt = $this->db->prepare($query);
                        $stmt->bind_param(
                            'iiii',
                            $garrisonLoss,
                            $tileId,
                            $garrisonBefore,
                            $garrisonLoss
                        );
                        $reduced = $stmt->execute()
                            && $stmt->affected_rows === 1;
                        $stmt->close();
                        if (!$reduced) {
                            throw new RuntimeException('NPC驻军已经变化 / NPC garrison changed');
                        }
                    } elseif ($garrisonBefore > 0) {
                        // 非零驻军必须产生至少一名损失，否则视为无效结算 / A non-empty NPC garrison must take at least one casualty
                        throw new RuntimeException('NPC驻军损失无效 / Invalid NPC garrison loss');
                    }

                    $garrisonRemaining = $garrisonBefore - $garrisonLoss;
                    if ($attackerWon && $garrisonRemaining === 0) {
                        $npcLevel = max(1, (int) $defender->getNpcLevel());
                        $respawnHours = 6 * pow(2, $npcLevel - 1);
                        $respawnTime = date(
                            'Y-m-d H:i:s',
                            time() + $respawnHours * 3600
                        );
                        $query = "UPDATE map_tiles
                                  SET npc_garrison = 0,
                                      npc_respawn_time = ?, owner_id = ?
                                  WHERE tile_id = ? AND npc_garrison = 0";
                        $stmt = $this->db->prepare($query);
                        $stmt->bind_param(
                            'sii',
                            $respawnTime,
                            $attackerId,
                            $tileId
                        );
                        $captured = $stmt->execute()
                            && $stmt->affected_rows === 1;
                        $stmt->close();
                        if (!$captured) {
                            throw new RuntimeException('无法占领NPC据点 / Failed to capture NPC fort');
                        }
                        $territoryCaptured = true;
                    } else {
                        // 未清空驻军时不产生系统资源或回路奖励 / Partial NPC damage grants no system resources or circuit points
                        unset($rewards['resources'], $rewards['circuit_points']);
                    }
                }
                break;
        }

        if ($attackerWon) {
            if (isset($rewards['circuit_points'])) {
                $query = "UPDATE users
                          SET circuit_points = GREATEST(
                              circuit_points,
                              LEAST(
                                  max_circuit_points,
                                  circuit_points + ?
                              )
                          )
                          WHERE user_id = ?";
                $stmt = $this->db->prepare($query);
                $circuitPoints = max(0, (int) $rewards['circuit_points']);
                $stmt->bind_param('ii', $circuitPoints, $attackerId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('无法发放思考回路 / Failed to award circuit points');
                }
                $stmt->close();
            }

            if ($defenderType !== 'city' && isset($rewards['resources'])) {
                $columns = [
                    'bright' => 'bright_crystal',
                    'warm' => 'warm_crystal',
                    'cold' => 'cold_crystal',
                    'green' => 'green_crystal',
                    'day' => 'day_crystal',
                    'night' => 'night_crystal'
                ];
                foreach ($rewards['resources'] as $type => $amount) {
                    if (!isset($columns[$type]) || (int) $amount <= 0) {
                        continue;
                    }
                    $column = $columns[$type];
                    $query = "UPDATE resources
                              SET {$column} = LEAST(
                                  2147483647,
                                  {$column} + ?
                              )
                              WHERE user_id = ?";
                    $stmt = $this->db->prepare($query);
                    $rewardAmount = (int) $amount;
                    $stmt->bind_param('ii', $rewardAmount, $attackerId);
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new RuntimeException('无法发放战斗资源 / Failed to award battle resources');
                    }
                    $stmt->close();
                }
            }

            if (isset($rewards['tile_control'])) {
                $tileId = (int) $rewards['tile_control']['tile_id'];
                $oldOwnerId = (int) $defender->getOwnerId();
                if ($this->isOptionalTableAvailable('territory_garrisons')) {
                    $query = "DELETE FROM territory_garrisons
                              WHERE tile_id = ? AND quantity = 0";
                    $stmt = $this->db->prepare($query);
                    $stmt->bind_param('i', $tileId);
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new RuntimeException('无法清理空领地驻军 / Failed to clean empty territory garrisons');
                    }
                    $stmt->close();
                }
                if (!$this->consumeTerritoryOccupationCost($attackerId)) {
                    // 兵力胜利不等于自动占领；费用不足时保留原领主 / A military victory does not transfer ownership when the occupation cost is unpaid
                    unset($rewards['tile_control']);
                    $rewards['occupation_blocked'] = true;
                    return $territoryCaptured;
                }
                $query = "UPDATE map_tiles
                          SET owner_id = ?,
                              last_collection_time = CASE
                                WHEN type = 'resource' THEN NOW()
                                ELSE last_collection_time
                              END
                          WHERE tile_id = ? AND owner_id = ?
                            AND type IN ('empty', 'resource')";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param(
                    'iii',
                    $attackerId,
                    $tileId,
                    $oldOwnerId
                );
                $captured = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$captured) {
                    throw new RuntimeException('资源点拥有权已经变化 / Resource-tile ownership changed');
                }
                $territoryCaptured = true;
            }
        }

        return $territoryCaptured;
    }

    /**
     * 判断城内兵力与存活驻城武将是否已经清零 / Determine whether city troops and living assigned generals are cleared
     */
    private function isCityDefenseCleared($cityId, $defenderUserId) {
        $query = "SELECT COALESCE(SUM(quantity), 0) AS remaining
                  FROM soldiers
                  WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $soldiers = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($soldiers && (int) $soldiers['remaining'] > 0) {
            return false;
        }

        $query = "SELECT COUNT(*) AS remaining
                  FROM general_assignments ga
                  INNER JOIN generals g ON g.general_id = ga.general_id
                  WHERE ga.assignment_type = 'city' AND ga.target_id = ?
                    AND g.owner_id = ? AND g.is_active = 1 AND g.hp > 0";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $cityId, $defenderUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        $generals = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return !$generals || (int) $generals['remaining'] === 0;
    }

    /**
     * 判断城池耐久是否已经归零 / Determine whether city durability is depleted
     */
    private function isCityDurabilityDepleted($cityId) {
        $query = "SELECT durability FROM cities WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $city = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $city && (int) $city['durability'] <= 0;
    }

    /**
     * 按战后存活锤子兵计算唯一一次攻城耐久伤害 / Calculate the single city-durability hit from surviving golems
     */
    private function calculateSurvivingGolemSiegeDamage(
        $attackerComposition,
        $attackerLosses,
        $skillModifiers = []
    ) {
        $lossesById = [];
        $legacyLosses = [];
        foreach ($attackerLosses as $loss) {
            if (isset($loss['army_unit_id'])) {
                $lossesById[(int) $loss['army_unit_id']] = max(
                    0,
                    (int) $loss['quantity']
                );
            } else {
                $key = $loss['soldier_type'] . ':' . (int) $loss['level'];
                $legacyLosses[$key] = isset($legacyLosses[$key])
                    ? $legacyLosses[$key] + max(0, (int) $loss['quantity'])
                    : max(0, (int) $loss['quantity']);
            }
        }

        $damage = 0.0;
        foreach ($attackerComposition as $unit) {
            if ($unit['soldier_type'] !== 'golem') {
                continue;
            }
            $quantity = max(0, (int) $unit['quantity']);
            if (isset($unit['army_unit_id'])
                && isset($lossesById[(int) $unit['army_unit_id']])) {
                $quantity -= $lossesById[(int) $unit['army_unit_id']];
            } else {
                $key = 'golem:' . (int) $unit['level'];
                $deduction = isset($legacyLosses[$key])
                    ? min($quantity, $legacyLosses[$key])
                    : 0;
                $quantity -= $deduction;
                $legacyLosses[$key] = isset($legacyLosses[$key])
                    ? $legacyLosses[$key] - $deduction
                    : 0;
            }
            if ($quantity > 0) {
                $damage += GOLEM_CITY_ATTACK
                    * max(1, (int) $unit['level'])
                    * $quantity;
            }
        }
        return self::applySiegeDamageModifiers(
            $damage,
            is_array($skillModifiers) ? $skillModifiers : []
        );
    }

    /**
     * 对基础攻城伤害应用百分比、倍率和固定值 / Applies percentage, multiplier, and flat siege modifiers
     * @param mixed $baseDamage 基础伤害 / Base damage
     * @param array $modifiers 攻城修正 / Siege modifiers
     * @return int 安全整数伤害 / Safe integer damage
     */
    public static function applySiegeDamageModifiers(
        $baseDamage,
        array $modifiers
    ) {
        $base = is_numeric($baseDamage)
            && is_finite((float) $baseDamage)
            ? max(0.0, (float) $baseDamage)
            : 0.0;
        if ($base <= 0.0) {
            return 0;
        }
        $percent = isset($modifiers['siege_damage_percent'])
            && is_numeric($modifiers['siege_damage_percent'])
            && is_finite((float) $modifiers['siege_damage_percent'])
            ? min(
                1000.0,
                max(0.0, (float) $modifiers['siege_damage_percent'])
            )
            : 0.0;
        $multiplier = isset($modifiers['siege_damage_multiplier'])
            && is_numeric($modifiers['siege_damage_multiplier'])
            && is_finite((float) $modifiers['siege_damage_multiplier'])
            ? min(
                10.0,
                max(0.0, (float) $modifiers['siege_damage_multiplier'])
            )
            : 1.0;
        $flat = isset($modifiers['siege_damage_flat'])
            && is_numeric($modifiers['siege_damage_flat'])
            && is_finite((float) $modifiers['siege_damage_flat'])
            ? min(
                1000000000.0,
                max(0.0, (float) $modifiers['siege_damage_flat'])
            )
            : 0.0;
        $damage = $base * (1.0 + $percent / 100.0)
            * $multiplier
            + $flat;

        return (int) min(
            2147483647,
            max(0, round($damage))
        );
    }

    /**
     * 原子扣除实际易主所需思考回路 / Atomically consume the circuit-point cost for an actual ownership transfer
     */
    private function consumeTerritoryOccupationCost($userId) {
        $cost = max(0, (int) TERRITORY_OCCUPATION_COST);
        if ($cost === 0) {
            return true;
        }

        $query = "UPDATE users
                  SET circuit_points = circuit_points - ?
                  WHERE user_id = ? AND circuit_points >= ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iii', $cost, $userId, $cost);
        $consumed = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        return $consumed;
    }

    /**
     * 将失守副城关联的旧主军队改挂旧主主城 / Re-home armies linked to a captured secondary city under the former owner's main city
     */
    private function rerouteFormerOwnerArmies($formerOwnerId, $capturedCityId) {
        $query = "SELECT city_id
                  FROM cities
                  WHERE owner_id = ? AND is_main_city = 1
                    AND city_id <> ?
                  ORDER BY city_id
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $formerOwnerId, $capturedCityId);
        $stmt->execute();
        $result = $stmt->get_result();
        $mainCity = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$mainCity) {
            throw new RuntimeException('原领主主城不存在 / Former owner has no main city');
        }

        $mainCityId = (int) $mainCity['city_id'];
        $query = "UPDATE armies
                  SET city_id = ?
                  WHERE owner_id = ? AND city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iii',
            $mainCityId,
            $formerOwnerId,
            $capturedCityId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法迁移旧主军队归属 / Failed to re-home former-owner armies');
        }
        $stmt->close();
    }

    /**
     * 锁定双方资源并返回实际转移量 / Lock both resource rows and return the amounts actually transferred
     */
    private function transferCityResources(
        $attackerUserId,
        $defenderUserId,
        $requestedRewards
    ) {
        if ((int) $attackerUserId === (int) $defenderUserId) {
            return [];
        }

        $lowerUserId = min((int) $attackerUserId, (int) $defenderUserId);
        $upperUserId = max((int) $attackerUserId, (int) $defenderUserId);
        $query = "SELECT user_id, bright_crystal, warm_crystal,
                         cold_crystal, green_crystal, day_crystal,
                         night_crystal
                  FROM resources
                  WHERE user_id IN (?, ?)
                  ORDER BY user_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $lowerUserId, $upperUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        $resourceRows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $resourceRows[(int) $row['user_id']] = $row;
        }
        $stmt->close();
        if (!isset(
            $resourceRows[(int) $attackerUserId],
            $resourceRows[(int) $defenderUserId]
        )) {
            throw new RuntimeException('无法锁定双方资源 / Failed to lock both resource rows');
        }

        $columns = [
            'bright' => 'bright_crystal',
            'warm' => 'warm_crystal',
            'cold' => 'cold_crystal',
            'green' => 'green_crystal',
            'day' => 'day_crystal',
            'night' => 'night_crystal'
        ];
        $capacity = max(
            0,
            (int) Resource::getUserResourceStorageCapacity($attackerUserId)
        );
        $actualRewards = [];
        foreach ($columns as $type => $column) {
            $requested = isset($requestedRewards[$type])
                ? max(0, (int) $requestedRewards[$type])
                : 0;
            $defenderAvailable = max(
                0,
                (int) $resourceRows[(int) $defenderUserId][$column]
            );
            $attackerCurrent = max(
                0,
                (int) $resourceRows[(int) $attackerUserId][$column]
            );
            $storageRoom = max(0, $capacity - $attackerCurrent);
            $actual = min($requested, $defenderAvailable, $storageRoom);
            if ($actual <= 0) {
                continue;
            }

            $query = "UPDATE resources
                      SET {$column} = {$column} - ?
                      WHERE user_id = ? AND {$column} >= ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iii',
                $actual,
                $defenderUserId,
                $actual
            );
            $deducted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$deducted) {
                throw new RuntimeException('防守资源已经变化 / Defender resources changed');
            }

            $query = "UPDATE resources
                      SET {$column} = {$column} + ?
                      WHERE user_id = ? AND {$column} <= ?";
            $stmt = $this->db->prepare($query);
            $maximumBeforeAdd = $capacity - $actual;
            $stmt->bind_param(
                'iii',
                $actual,
                $attackerUserId,
                $maximumBeforeAdd
            );
            $credited = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$credited) {
                throw new RuntimeException('攻击方仓储已经变化 / Attacker storage changed');
            }

            $resourceRows[(int) $defenderUserId][$column] -= $actual;
            $resourceRows[(int) $attackerUserId][$column] += $actual;
            $actualRewards[$type] = $actual;
        }
        return $actualRewards;
    }

    /**
     * 构建出发时技能条件上下文 / Builds the departure-time skill-condition context
     * @param Army $attackerArmy 攻击军队 / Attacking army
     * @param string $defenderType 目标类型 / Target type
     * @param int $defenderId 目标ID / Target ID
     * @return array|null 上下文或空 / Context or null
     */
    private function buildAttackerBattleContext(
        $attackerArmy,
        $defenderType,
        $defenderId
    ) {
        if ($defenderType === 'city') {
            $defender = new City((int) $defenderId);
            $position = $defender->isValid()
                ? $defender->getCoordinates()
                : null;
        } elseif ($defenderType === 'army') {
            $defender = new Army((int) $defenderId);
            $position = $defender->isValid()
                ? $defender->getCurrentPosition()
                : null;
        } elseif ($defenderType === 'tile') {
            $defender = new Map((int) $defenderId);
            $position = $defender->isValid()
                ? [$defender->getX(), $defender->getY()]
                : null;
        } else {
            return null;
        }
        if (!$position || count($position) !== 2) {
            return null;
        }

        $attackerPosition = $attackerArmy->getCurrentPosition();
        $distance = abs(
            (int) $position[0] - (int) $attackerPosition[0]
        ) + abs(
            (int) $position[1] - (int) $attackerPosition[1]
        );

        return [
            'phase' => 'battle',
            'side' => 'attack',
            'target_tags' => $this->getDefenderTargetTags(
                $defenderType,
                $defender
            ),
            'distance' => $distance
        ];
    }

    /**
     * 获取技能条件使用的目标标签 / Gets target tags used by skill conditions
     * @param string $defenderType 防守类型 / Defender type
     * @param object $defender 防守实体 / Defender entity
     * @return array 目标标签 / Target tags
     */
    private function getDefenderTargetTags(
        $defenderType,
        $defender
    ) {
        if ($defenderType === 'city') {
            return ['city', 'structure', 'player'];
        }
        if ($defenderType === 'army') {
            return ['army', 'player'];
        }
        if ($defenderType !== 'tile') {
            return [];
        }

        $tags = ['tile'];
        if ($defender->getType() === 'npc_fort') {
            $tags[] = 'npc';
            $tags[] = 'structure';
        } elseif ($defender->getOwnerId() !== null) {
            $tags[] = 'player';
        }

        return $tags;
    }

    /**
     * 安全解码出发时战斗距离 / Safely decodes the departure-time battle distance
     * @param mixed $json 编成快照JSON / Composition snapshot JSON
     * @return int 非负且数据库安全的距离 / Non-negative, database-safe distance
     */
    public static function decodeAttackerBattleDistanceSnapshot($json) {
        if (!is_string($json) || $json === '') {
            return 0;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)
            || !isset($decoded['schema_version'])
            || (int) $decoded['schema_version'] !== 2
            || !isset($decoded['battle_context'])
            || !is_array($decoded['battle_context'])
            || !array_key_exists(
                'distance',
                $decoded['battle_context']
            )) {
            return 0;
        }

        $distance = $decoded['battle_context']['distance'];
        if (is_int($distance)) {
            $numericDistance = (float) $distance;
        } elseif (is_string($distance)
            && preg_match('/^(0|[1-9][0-9]*)$/D', $distance)) {
            $numericDistance = (float) $distance;
        } else {
            return 0;
        }
        if (!is_finite($numericDistance) || $numericDistance < 0.0) {
            return 0;
        }

        return (int) min(2147483647.0, $numericDistance);
    }

    /**
     * 解码并验证出发时攻击编成 / Decode and validate the departure attacker composition
     */
    private function decodeAttackerCompositionSnapshot($json) {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $units = isset($decoded['schema_version'])
            && (int) $decoded['schema_version'] === 2
            && isset($decoded['units'])
            && is_array($decoded['units'])
            ? $decoded['units']
            : $decoded;
        $allowedTypes = ['pawn', 'knight', 'rook', 'bishop', 'golem', 'scout'];
        $composition = [];
        foreach ($units as $unit) {
            if (!is_array($unit)
                || !isset(
                    $unit['army_unit_id'],
                    $unit['soldier_type'],
                    $unit['level'],
                    $unit['quantity']
                )
                || (int) $unit['army_unit_id'] <= 0
                || !in_array($unit['soldier_type'], $allowedTypes, true)
                || (int) $unit['level'] <= 0
                || (int) $unit['quantity'] <= 0) {
                return [];
            }
            $composition[] = [
                'army_unit_id' => (int) $unit['army_unit_id'],
                'soldier_type' => $unit['soldier_type'],
                'level' => (int) $unit['level'],
                'quantity' => (int) $unit['quantity']
            ];
        }
        return $composition;
    }

    /**
     * 解码并限制出发时攻城修正 / Decodes and bounds departure-time siege modifiers
     * @param mixed $json 编成快照JSON / Composition snapshot JSON
     * @return array 攻城修正 / Siege modifiers
     */
    private function decodeAttackerSkillModifierSnapshot($json) {
        $defaults = [
            'siege_damage_percent' => 0.0,
            'siege_damage_flat' => 0.0,
            'siege_damage_multiplier' => 1.0
        ];
        if (!is_string($json) || $json === '') {
            return $defaults;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)
            || !isset($decoded['skill_modifiers'])
            || !is_array($decoded['skill_modifiers'])) {
            return $defaults;
        }

        $modifiers = $decoded['skill_modifiers'];
        foreach ($defaults as $key => $default) {
            if (!isset($modifiers[$key])
                || !is_numeric($modifiers[$key])
                || !is_finite((float) $modifiers[$key])
                || (float) $modifiers[$key] < 0.0) {
                continue;
            }
            $maximum = $key === 'siege_damage_flat'
                ? 1000000000.0
                : ($key === 'siege_damage_multiplier'
                    ? 10.0
                    : 1000.0);
            $defaults[$key] = min(
                $maximum,
                (float) $modifiers[$key]
            );
        }

        return $defaults;
    }

    /**
     * 应用军队损失
     * @param Army $army 军队对象
     * @param array $losses 损失数组
     */
    private function applyArmyLosses($army, $losses) {
        $units = $army->getUnits();
        $unitsById = [];
        foreach ($units as $unit) {
            $unitsById[(int) $unit['army_unit_id']] = $unit;
        }

        foreach ($losses as $loss) {
            $armyUnitId = isset($loss['army_unit_id'])
                ? (int) $loss['army_unit_id']
                : 0;
            $unit = $armyUnitId > 0 && isset($unitsById[$armyUnitId])
                ? $unitsById[$armyUnitId]
                : null;

            // 旧战报损失没有主键时仅在唯一兵种等级匹配时兼容 / Legacy losses without a primary key are accepted only when type and level identify one row
            if (!$unit) {
                $matches = [];
                foreach ($units as $candidate) {
                    if ($candidate['soldier_type'] === $loss['soldier_type']
                        && (int) $candidate['level'] === (int) $loss['level']) {
                        $matches[] = $candidate;
                    }
                }
                if (count($matches) === 1) {
                    $unit = $matches[0];
                    $armyUnitId = (int) $unit['army_unit_id'];
                }
            }
            if (!$unit) {
                throw new RuntimeException('损失兵种已经不存在 / Loss unit no longer exists');
            }

            $lossQuantity = max(
                0,
                min((int) $loss['quantity'], (int) $unit['quantity'])
            );
            if ($lossQuantity <= 0) {
                continue;
            }
            $query = "UPDATE army_units
                      SET quantity = quantity - ?
                      WHERE army_unit_id = ? AND army_id = ?
                        AND soldier_type = ? AND level = ?
                        AND quantity >= ?";
            $stmt = $this->db->prepare($query);
            $armyId = (int) $army->getArmyId();
            $soldierType = $unit['soldier_type'];
            $level = (int) $unit['level'];
            $stmt->bind_param(
                'iiisii',
                $lossQuantity,
                $armyUnitId,
                $armyId,
                $soldierType,
                $level,
                $lossQuantity
            );
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException('军队兵力已经变化 / Army unit quantity changed');
            }

            $query = "DELETE FROM army_units
                      WHERE army_unit_id = ? AND army_id = ?
                        AND quantity = 0";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $armyUnitId, $armyId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法清理空兵种 / Failed to remove empty army unit');
            }
            $stmt->close();
        }
    }

    /**
     * 获取战斗对象当前坐标 / Get a combatant's current coordinates
     * @return array|null 坐标或空值 / Coordinates or null
     */
    private function getCombatantPosition($defenderType, $combatant) {
        if ($defenderType === 'army') {
            return $combatant->getCurrentPosition();
        }
        if ($defenderType === 'city') {
            return $combatant->getCoordinates();
        }
        if ($defenderType === 'tile') {
            return [$combatant->getX(), $combatant->getY()];
        }
        return null;
    }

    /**
     * 读取可参与克制计算的兵种编成 / Read a unit composition suitable for counter calculations
     * @param string $defenderType 对象类型 / Object type
     * @param object $combatant 战斗对象 / Combatant object
     * @return array 标准化兵种编成 / Normalized unit composition
     */
    private function getCombatComposition($defenderType, $combatant) {
        $composition = [];
        if ($defenderType === 'army') {
            foreach ($combatant->getUnits() as $unit) {
                if ((int) $unit['quantity'] > 0) {
                    $entry = [
                        'soldier_type' => $unit['soldier_type'],
                        'level' => (int) $unit['level'],
                        'quantity' => (int) $unit['quantity']
                    ];
                    if (isset($unit['army_unit_id'])) {
                        $entry['army_unit_id'] = (int) $unit['army_unit_id'];
                    }
                    $composition[] = $entry;
                }
            }
            return $composition;
        }

        if ($defenderType === 'city') {
            foreach ($combatant->getSoldiers() as $soldier) {
                if ($soldier->getQuantity() > 0) {
                    $composition[] = [
                        'soldier_type' => $soldier->getType(),
                        'level' => (int) $soldier->getLevel(),
                        'quantity' => (int) $soldier->getQuantity()
                    ];
                }
            }
            return $composition;
        }

        if ($defenderType === 'tile'
            && in_array($combatant->getType(), ['empty', 'resource'], true)
            && $this->isOptionalTableAvailable('territory_garrisons')) {
            $query = "SELECT soldier_type, level, quantity
                      FROM territory_garrisons
                      WHERE tile_id = ? AND owner_id = ? AND quantity > 0
                      ORDER BY garrison_id";
            $stmt = $this->db->prepare($query);
            $tileId = (int) $combatant->getTileId();
            $ownerId = (int) $combatant->getOwnerId();
            $stmt->bind_param('ii', $tileId, $ownerId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $composition[] = [
                    'soldier_type' => $row['soldier_type'],
                    'level' => (int) $row['level'],
                    'quantity' => (int) $row['quantity']
                ];
            }
            $stmt->close();
        }

        return $composition;
    }

    /**
     * 计算一方编成对另一方编成的加权克制倍率 / Calculate one composition's weighted counter multiplier against another
     */
    private function calculateCompositionCounterMultiplier($attackers, $defenders) {
        if (empty($attackers) || empty($defenders)) {
            return 1.0;
        }

        $weightedMultiplier = 0.0;
        $totalWeight = 0.0;
        foreach ($attackers as $attacker) {
            $attackerWeight = max(1, (int) $attacker['level'])
                * max(0, (int) $attacker['quantity']);
            foreach ($defenders as $defender) {
                $defenderWeight = max(1, (int) $defender['level'])
                    * max(0, (int) $defender['quantity']);
                $pairWeight = $attackerWeight * $defenderWeight;
                if ($pairWeight <= 0) {
                    continue;
                }
                $multiplier = GameRules::getUnitCounterMultiplier(
                    $attacker['soldier_type'],
                    $defender['soldier_type']
                );
                $weightedMultiplier += $pairWeight * $multiplier;
                $totalWeight += $pairWeight;
            }
        }

        return $totalWeight > 0 ? $weightedMultiplier / $totalWeight : 1.0;
    }

    /**
     * 按损失率计算标准化编成损失 / Calculate normalized composition losses by rate
     */
    private function calculateCompositionLosses($composition, $lossRate) {
        $losses = [];
        foreach ($composition as $unit) {
            $quantity = GameRules::calculateBattleLosses(
                (int) $unit['quantity'],
                $lossRate
            );
            if ($quantity > 0) {
                $loss = [
                    'soldier_type' => $unit['soldier_type'],
                    'level' => (int) $unit['level'],
                    'quantity' => $quantity
                ];
                if (isset($unit['army_unit_id'])) {
                    $loss['army_unit_id'] = (int) $unit['army_unit_id'];
                }
                $losses[] = $loss;
            }
        }
        return $losses;
    }

    /**
     * 计算标准化编成的守备战力 / Calculate defensive power for a normalized composition
     * @param array $composition 标准化兵种编成 / Normalized unit composition
     * @return int 守备战力 / Defensive power
     */
    private function calculateCompositionDefensePower($composition) {
        $baseDefense = [
            'pawn' => PAWN_DEFENSE,
            'knight' => KNIGHT_DEFENSE,
            'rook' => ROOK_DEFENSE,
            'bishop' => BISHOP_DEFENSE,
            'golem' => GOLEM_DEFENSE,
            'scout' => SCOUT_DEFENSE
        ];
        $power = 0;
        foreach ($composition as $unit) {
            $soldierType = $unit['soldier_type'];
            if (!isset($baseDefense[$soldierType])) {
                continue;
            }
            $power += $baseDefense[$soldierType]
                * max(1, (int) $unit['level'])
                * max(0, (int) $unit['quantity']);
        }
        return $power;
    }

    /**
     * 判断扣除损失后是否仍有存活编成 / Determine whether a composition survives after its losses
     * @param array $composition 战前编成 / Pre-battle composition
     * @param array $losses 战斗损失 / Battle losses
     * @return bool 是否仍有存活士兵 / Whether troops remain alive
     */
    private function hasCompositionSurvivors($composition, $losses) {
        $lossByUnit = [];
        foreach ($losses as $loss) {
            $key = isset($loss['army_unit_id'])
                ? 'id:' . (int) $loss['army_unit_id']
                : $loss['soldier_type'] . ':' . (int) $loss['level'];
            $lossByUnit[$key] = isset($lossByUnit[$key])
                ? $lossByUnit[$key] + max(0, (int) $loss['quantity'])
                : max(0, (int) $loss['quantity']);
        }

        foreach ($composition as $unit) {
            $key = isset($unit['army_unit_id'])
                ? 'id:' . (int) $unit['army_unit_id']
                : $unit['soldier_type'] . ':' . (int) $unit['level'];
            $remaining = max(0, (int) $unit['quantity'])
                - (isset($lossByUnit[$key]) ? $lossByUnit[$key] : 0);
            if ($remaining > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 应用城池守军损失 / Apply city-garrison losses
     */
    private function applyCitySoldierLosses($city, $losses) {
        $cityId = (int) $city->getCityId();
        foreach ($losses as $loss) {
            $soldierType = $loss['soldier_type'];
            $level = (int) $loss['level'];
            $lossQuantity = max(0, (int) $loss['quantity']);
            if ($lossQuantity <= 0) {
                continue;
            }

            // 使用相对扣减及快照条件，避免并发结算覆盖兵力 / Use a relative, snapshot-guarded decrement to prevent concurrent overwrite
            $query = "UPDATE soldiers
                      SET quantity = quantity - ?
                      WHERE city_id = ? AND type = ? AND level = ?
                        AND quantity >= ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iisii',
                $lossQuantity,
                $cityId,
                $soldierType,
                $level,
                $lossQuantity
            );
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException('城池守军已经变化 / City garrison quantity changed');
            }
        }
    }

    /**
     * 应用领地驻军损失 / Apply territory-garrison losses
     * @param int $tileId 地图格ID / Map-tile ID
     * @param int $ownerId 驻军拥有者ID / Garrison owner ID
     * @param array $losses 损失编成 / Loss composition
     * @return void
     */
    private function applyTileGarrisonLosses($tileId, $ownerId, $losses) {
        foreach ($losses as $loss) {
            $soldierType = $loss['soldier_type'];
            $level = (int) $loss['level'];
            $lossQuantity = max(0, (int) $loss['quantity']);
            if ($lossQuantity <= 0) {
                continue;
            }

            $query = "UPDATE territory_garrisons
                      SET quantity = quantity - ?
                      WHERE tile_id = ? AND owner_id = ?
                        AND soldier_type = ? AND level = ?
                        AND quantity >= ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iiisii',
                $lossQuantity,
                $tileId,
                $ownerId,
                $soldierType,
                $level,
                $lossQuantity
            );
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException('领地驻军已经变化 / Territory garrison quantity changed');
            }

            $query = "DELETE FROM territory_garrisons
                      WHERE tile_id = ? AND owner_id = ? AND soldier_type = ?
                        AND level = ? AND quantity = 0";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iisi',
                $tileId,
                $ownerId,
                $soldierType,
                $level
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法清理空领地驻军 / Failed to remove empty territory garrison');
            }
            $stmt->close();
        }
    }

    /**
     * 获取防守玩家ID / Resolve the defending user ID
     * @return int|null 防守玩家ID / Defender user ID
     */
    private function getDefenderOwnerId($defenderType, $defender) {
        if (in_array($defenderType, ['army', 'city', 'tile'], true)) {
            $ownerId = $defender->getOwnerId();
            return $ownerId === null ? null : (int) $ownerId;
        }
        return null;
    }

    /**
     * 生成玩家战斗的俘虏分配计划 / Build a captive allocation plan for a player battle
     */
    private function buildCaptivePlan(
        $outcome,
        $attackerUserId,
        $defenderUserId,
        $attackerLosses,
        $defenderLosses
    ) {
        $emptyPlan = [
            'owner_id' => null,
            'source_user_id' => null,
            'total' => 0,
            'units' => []
        ];
        if ($defenderUserId === null
            || (int) $defenderUserId === (int) $attackerUserId
            || $outcome === 'draw') {
            return $emptyPlan;
        }

        $attackerWon = strpos($outcome, 'attacker_win') === 0;
        $defenderWon = strpos($outcome, 'defender_win') === 0;
        if (!$attackerWon && !$defenderWon) {
            return $emptyPlan;
        }

        $captorSide = $attackerWon ? 'attacker' : 'defender';
        // 俘虏只能来自已从败方扣除的损失，不能复制幸存兵力 / Captives come only from deducted losses and never duplicate survivors
        $defeatedUnits = $attackerWon ? $defenderLosses : $attackerLosses;
        $eligibleUnits = [];
        $eligibleTotal = 0;
        foreach ($defeatedUnits as $unit) {
            if ($unit['soldier_type'] === 'scout' || (int) $unit['quantity'] <= 0) {
                continue;
            }
            $eligibleUnits[] = $unit;
            $eligibleTotal += (int) $unit['quantity'];
        }

        $captiveTotal = GameRules::calculateCaptiveCount(
            $eligibleTotal,
            $outcome,
            $captorSide
        );
        if ($captiveTotal <= 0 || $eligibleTotal <= 0) {
            return $emptyPlan;
        }

        // 使用最大余数法按败军编成分配俘虏 / Distribute captives proportionally with the largest-remainder method
        $allocations = [];
        $allocated = 0;
        foreach ($eligibleUnits as $index => $unit) {
            $exact = $captiveTotal * ((int) $unit['quantity'] / $eligibleTotal);
            $quantity = min((int) $unit['quantity'], (int) floor($exact));
            $allocations[$index] = [
                'soldier_type' => $unit['soldier_type'],
                'level' => (int) $unit['level'],
                'quantity' => $quantity,
                'remainder' => $exact - floor($exact),
                'capacity' => (int) $unit['quantity']
            ];
            $allocated += $quantity;
        }
        uasort($allocations, function($left, $right) {
            return $left['remainder'] === $right['remainder']
                ? 0
                : ($left['remainder'] > $right['remainder'] ? -1 : 1);
        });
        while ($allocated < $captiveTotal) {
            $changed = false;
            foreach ($allocations as &$allocation) {
                if ($allocation['quantity'] < $allocation['capacity']) {
                    $allocation['quantity']++;
                    $allocated++;
                    $changed = true;
                    if ($allocated >= $captiveTotal) {
                        break;
                    }
                }
            }
            unset($allocation);
            if (!$changed) {
                break;
            }
        }

        $units = [];
        foreach ($allocations as $allocation) {
            if ($allocation['quantity'] > 0) {
                $units[] = [
                    'soldier_type' => $allocation['soldier_type'],
                    'level' => $allocation['level'],
                    'quantity' => $allocation['quantity']
                ];
            }
        }

        return [
            'owner_id' => $attackerWon ? $attackerUserId : $defenderUserId,
            'source_user_id' => $attackerWon ? $defenderUserId : $attackerUserId,
            'total' => $allocated,
            'units' => $units
        ];
    }

    /**
     * 从不同防守对象的损失结构中提取可俘虏士兵 / Extract capturable soldiers from each defender loss shape
     */
    private function getCapturableDefenderLosses($defenderType, $defenderLosses) {
        if ($defenderType === 'army') {
            return is_array($defenderLosses) ? $defenderLosses : [];
        }
        if (in_array($defenderType, ['city', 'tile'], true)
            && isset($defenderLosses['soldier_losses'])
            && is_array($defenderLosses['soldier_losses'])) {
            return $defenderLosses['soldier_losses'];
        }
        return [];
    }

    /**
     * 在扩展表存在时保存俘虏，且同一战斗只保存一次 / Persist captives when available and never duplicate one battle's capture
     */
    private function persistCaptivesIfAvailable($plan) {
        if (empty($plan['units']) || !$this->isOptionalTableAvailable('prisoners')) {
            return;
        }

        $query = "SELECT prisoner_id FROM prisoners WHERE battle_id = ? LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->battleId);
        $stmt->execute();
        $result = $stmt->get_result();
        $alreadyRecorded = $result && $result->num_rows > 0;
        $stmt->close();
        if ($alreadyRecorded) {
            return;
        }

        foreach ($plan['units'] as $unit) {
            $query = "INSERT INTO prisoners
                         (owner_id, source_user_id, battle_id,
                          soldier_type, level, quantity)
                      VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $ownerId = (int) $plan['owner_id'];
            $sourceUserId = (int) $plan['source_user_id'];
            $soldierType = $unit['soldier_type'];
            $level = (int) $unit['level'];
            $quantity = (int) $unit['quantity'];
            $stmt->bind_param(
                'iiisii',
                $ownerId,
                $sourceUserId,
                $this->battleId,
                $soldierType,
                $level,
                $quantity
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('写入俘虏失败 / Failed to persist captives');
            }
            $stmt->close();
        }
    }

    /**
     * 在扩展表存在时写入兵种克制快照 / Record a counter snapshot when the expansion table is available
     */
    private function recordBattleParticipantIfAvailable(
        $attackerUserId,
        $defenderUserId,
        $attackerPower,
        $defenderPower,
        $counterDetails
    ) {
        if (!$this->isOptionalTableAvailable('battle_participants')) {
            return;
        }

        $detailsJson = json_encode($counterDetails, JSON_UNESCAPED_UNICODE);
        $query = "INSERT INTO battle_participants
                     (battle_id, attacker_user_id, defender_user_id,
                      attacker_power, defender_power, counter_details)
                  VALUES (?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                      attacker_power = VALUES(attacker_power),
                      defender_power = VALUES(defender_power),
                      counter_details = VALUES(counter_details)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iiiiis',
            $this->battleId,
            $attackerUserId,
            $defenderUserId,
            $attackerPower,
            $defenderPower,
            $detailsJson
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('写入战斗快照失败 / Failed to record battle snapshot');
        }
        $stmt->close();
    }

    /**
     * 在战斗事务内增加当前赛季分数 / Add current-season scores inside the battle transaction
     * @param string $battleResult 战斗结果 / Battle outcome
     * @param int $attackerUserId 攻击玩家ID / Attacking user ID
     * @param int|null $defenderUserId 防守玩家ID / Defending user ID
     * @param bool $territoryCaptured 是否实际转移领地 / Whether territory ownership actually changed
     * @return void
     */
    private function updateSeasonScoresInTransaction(
        $battleResult,
        $attackerUserId,
        $defenderUserId,
        $territoryCaptured
    ) {
        if ($this->lockedSeasonId === null) {
            return;
        }

        $scoreDeltas = [];
        $attackerUserId = (int) $attackerUserId;
        if (strpos($battleResult, 'attacker_win') === 0) {
            $scoreDeltas[$attackerUserId] = [
                'territory' => 0,
                'battle' => 1
            ];
        } elseif ($defenderUserId !== null
            && strpos($battleResult, 'defender_win') === 0) {
            $defenderUserId = (int) $defenderUserId;
            $scoreDeltas[$defenderUserId] = [
                'territory' => 0,
                'battle' => 1
            ];
        }

        if ($territoryCaptured) {
            if (!isset($scoreDeltas[$attackerUserId])) {
                $scoreDeltas[$attackerUserId] = [
                    'territory' => 0,
                    'battle' => 0
                ];
            }
            $scoreDeltas[$attackerUserId]['territory']++;
            if ($defenderUserId !== null
                && (int) $defenderUserId !== $attackerUserId) {
                $defenderUserId = (int) $defenderUserId;
                if (!isset($scoreDeltas[$defenderUserId])) {
                    $scoreDeltas[$defenderUserId] = [
                        'territory' => 0,
                        'battle' => 0
                    ];
                }
                // 易主时扣除原领主的净领地分，避免反复争夺制造总分 / Remove the former owner's net territory point so repeated captures cannot create aggregate score
                $scoreDeltas[$defenderUserId]['territory']--;
            }
        }
        if (empty($scoreDeltas)) {
            return;
        }

        $seasonId = (int) $this->lockedSeasonId;
        ksort($scoreDeltas, SORT_NUMERIC);
        foreach ($scoreDeltas as $userId => $delta) {
            $territoryDelta = max(-1, min(1, (int) $delta['territory']));
            $territoryScore = max(0, $territoryDelta);
            $battleScore = max(0, (int) $delta['battle']);
            $query = "INSERT INTO season_scores
                         (season_id, user_id, territory_score, battle_score)
                      VALUES (?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE
                        territory_score = LEAST(
                            2147483647,
                            GREATEST(0, territory_score + ?)
                        ),
                        battle_score = LEAST(
                            2147483647,
                            battle_score + VALUES(battle_score)
                        )";
            $stmt = $this->db->prepare($query);
            $userId = (int) $userId;
            $stmt->bind_param(
                'iiiii',
                $seasonId,
                $userId,
                $territoryScore,
                $battleScore,
                $territoryDelta
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法更新赛季分数 / Failed to update season score');
            }
            $stmt->close();
        }
    }

    /**
     * 在其他战斗实体前锁定当前赛季 / Lock the current season before the other combat entities
     * @return void
     */
    private function lockCurrentSeasonForScoring() {
        $this->lockedSeasonId = null;
        if (!$this->isOptionalTableAvailable('seasons')) {
            return;
        }
        lockSeasonForWorldAction($this->db);
        if (!$this->isOptionalTableAvailable('season_scores')) {
            return;
        }

        $query = "SELECT season_id
                  FROM seasons
                  WHERE status IN ('active', 'victory_countdown')
                  ORDER BY season_number DESC
                  LIMIT 1
                  LOCK IN SHARE MODE";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $season = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($season) {
            $this->lockedSeasonId = (int) $season['season_id'];
        }
    }

    /**
     * 主战斗事务提交后记录任务与成就事件 / Record quest and achievement events after the battle transaction commits
     * @param string $battleResult 战斗结果 / Battle outcome
     * @param int $attackerUserId 攻击玩家ID / Attacking user ID
     * @param int|null $defenderUserId 防守玩家ID / Defending user ID
     * @param bool $territoryCaptured 是否实际转移领地 / Whether territory ownership actually changed
     * @return void
     */
    private function recordPostCommitBattleEvents(
        $battleResult,
        $attackerUserId,
        $defenderUserId,
        $territoryCaptured
    ) {
        try {
            $progressService = new ProgressService();
            $attackerUserId = (int) $attackerUserId;
            $progressService->recordEvent(
                $attackerUserId,
                'battle_completed',
                1,
                'battle',
                $this->battleId
            );

            if ($defenderUserId !== null) {
                $progressService->recordEvent(
                    (int) $defenderUserId,
                    'battle_completed',
                    1,
                    'battle',
                    $this->battleId
                );
            }

            if (strpos($battleResult, 'attacker_win') === 0) {
                $progressService->recordEvent(
                    $attackerUserId,
                    'battle_won',
                    1,
                    'battle',
                    $this->battleId
                );
            } elseif ($defenderUserId !== null
                && strpos($battleResult, 'defender_win') === 0) {
                $progressService->recordEvent(
                    (int) $defenderUserId,
                    'battle_won',
                    1,
                    'battle',
                    $this->battleId
                );
            }

            if ($territoryCaptured) {
                $progressService->recordEvent(
                    $attackerUserId,
                    'territory_captured',
                    1,
                    'battle',
                    $this->battleId
                );
            }
        } catch (Throwable $exception) {
            // 进度账本失败不能回滚已完成战斗 / Progress-ledger failure must not roll back a completed battle
            error_log('Post-battle progress recording failed: ' . $exception->getMessage());
        }
    }

    /**
     * 检查可选扩展表是否存在 / Check whether an optional expansion table exists
     */
    private function isOptionalTableAvailable($tableName) {
        if (array_key_exists($tableName, $this->tableAvailability)) {
            return $this->tableAvailability[$tableName];
        }

        $query = "SELECT 1
                  FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?
                  LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $result = $stmt->get_result();
        $available = $result && $result->num_rows > 0;
        $stmt->close();
        $this->tableAvailability[$tableName] = $available;
        return $available;
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
     * 获取出发时保存的攻击军名，旧记录回退当前军队 / Get the departure army name, falling back to the live army for legacy records
     */
    public function getAttackerName() {
        if (is_string($this->attackerNameSnapshot)
            && $this->attackerNameSnapshot !== '') {
            return $this->attackerNameSnapshot;
        }
        if ($this->attackerArmyId !== null) {
            $army = new Army($this->attackerArmyId);
            if ($army->isValid()) {
                return $army->getName();
            }
        }
        return '未知军队';
    }

    /**
     * 获取出发时攻击战力 / Get the attacker power captured at departure
     */
    public function getAttackerPowerSnapshot() {
        return max(0, (int) $this->attackerPowerSnapshot);
    }

    /**
     * 获取结算参与方快照 / Get the resolved participant snapshot
     * @return array|null 参与方快照 / Participant snapshot
     */
    public function getParticipantSnapshot() {
        if ($this->participantSnapshotLoaded) {
            return $this->participantSnapshot;
        }
        $this->participantSnapshotLoaded = true;
        if (!$this->isOptionalTableAvailable('battle_participants')) {
            return null;
        }

        $query = "SELECT attacker_user_id, defender_user_id,
                         attacker_power, defender_power, counter_details
                  FROM battle_participants
                  WHERE battle_id = ?
                  LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->battleId);
        $stmt->execute();
        $result = $stmt->get_result();
        $snapshot = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($snapshot) {
            $snapshot['attacker_user_id'] = (int) $snapshot['attacker_user_id'];
            $snapshot['defender_user_id'] = $snapshot['defender_user_id'] === null
                ? null
                : (int) $snapshot['defender_user_id'];
            $snapshot['attacker_power'] = (int) $snapshot['attacker_power'];
            $snapshot['defender_power'] = (int) $snapshot['defender_power'];
            $snapshot['counter_details'] = $snapshot['counter_details'] === null
                ? null
                : json_decode($snapshot['counter_details'], true);
        }
        $this->participantSnapshot = $snapshot;
        return $this->participantSnapshot;
    }

    /**
     * 按结算参与方快照检查战报权限 / Authorize a report from its resolved participant snapshot
     */
    public function canUserView($userId) {
        $userId = (int) $userId;
        $participant = $this->getParticipantSnapshot();
        if ($participant) {
            return $participant['attacker_user_id'] === $userId
                || $participant['defender_user_id'] === $userId;
        }

        // 旧记录只回退不会易主的军队实体；待处理记录可使用当前目标归属 / Legacy settled records fall back only to non-transferable army ownership; pending records may use current target ownership
        foreach ([$this->attackerArmyId, $this->defenderArmyId] as $armyId) {
            if ($armyId === null) {
                continue;
            }
            $army = new Army($armyId);
            if ($army->isValid() && (int) $army->getOwnerId() === $userId) {
                return true;
            }
        }
        if ($this->result !== 'pending') {
            return false;
        }
        if ($this->defenderCityId !== null) {
            $city = new City($this->defenderCityId);
            return $city->isValid() && (int) $city->getOwnerId() === $userId;
        }
        if ($this->defenderTileId !== null) {
            $tile = new Map($this->defenderTileId);
            return $tile->isValid() && (int) $tile->getOwnerId() === $userId;
        }
        return false;
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

        $query = "SELECT b.battle_id
                  FROM battles b
                  LEFT JOIN battle_participants bp
                    ON bp.battle_id = b.battle_id
                  LEFT JOIN armies attacker_army
                    ON attacker_army.army_id = b.attacker_army_id
                  LEFT JOIN armies defender_army
                    ON defender_army.army_id = b.defender_army_id
                  LEFT JOIN cities defender_city
                    ON defender_city.city_id = b.defender_city_id
                  LEFT JOIN map_tiles defender_tile
                    ON defender_tile.tile_id = b.defender_tile_id
                  WHERE bp.attacker_user_id = ?
                     OR bp.defender_user_id = ?
                     OR (
                        bp.battle_id IS NULL
                        AND (
                            attacker_army.owner_id = ?
                            OR defender_army.owner_id = ?
                            OR (
                                b.result = 'pending'
                                AND (
                                    defender_city.owner_id = ?
                                    OR defender_tile.owner_id = ?
                                )
                            )
                        )
                     )
                  ORDER BY b.battle_time DESC
                  LIMIT ? OFFSET ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param(
            'iiiiiiii',
            $userId,
            $userId,
            $userId,
            $userId,
            $userId,
            $userId,
            $limit,
            $offset
        );
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

        $query = "SELECT COUNT(DISTINCT b.battle_id) AS count
                  FROM battles b
                  LEFT JOIN battle_participants bp
                    ON bp.battle_id = b.battle_id
                  LEFT JOIN armies attacker_army
                    ON attacker_army.army_id = b.attacker_army_id
                  LEFT JOIN armies defender_army
                    ON defender_army.army_id = b.defender_army_id
                  LEFT JOIN cities defender_city
                    ON defender_city.city_id = b.defender_city_id
                  LEFT JOIN map_tiles defender_tile
                    ON defender_tile.tile_id = b.defender_tile_id
                  WHERE bp.attacker_user_id = ?
                     OR bp.defender_user_id = ?
                     OR (
                        bp.battle_id IS NULL
                        AND (
                            attacker_army.owner_id = ?
                            OR defender_army.owner_id = ?
                            OR (
                                b.result = 'pending'
                                AND (
                                    defender_city.owner_id = ?
                                    OR defender_tile.owner_id = ?
                                )
                            )
                        )
                     )";
        $stmt = $db->prepare($query);
        $stmt->bind_param(
            'iiiiii',
            $userId,
            $userId,
            $userId,
            $userId,
            $userId,
            $userId
        );
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

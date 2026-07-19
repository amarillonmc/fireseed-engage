<?php
// 种火集结号 - 附属、救出与主动脱离服务 / Fireseed Engage - Vassalage, rescue, and voluntary release service

class VassalService {
    private $db;

    private const RESOURCE_COLUMNS = [
        'bright' => 'bright_crystal',
        'warm' => 'warm_crystal',
        'cold' => 'cold_crystal',
        'green' => 'green_crystal',
        'day' => 'day_crystal',
        'night' => 'night_crystal'
    ];
    private const RATE_SCALE = 1000000;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 将后台比例收束到合法范围 / Normalize an administration rate to its legal range
     * @param mixed $rate 后台比例 / Administration rate
     * @return float 合法比例 / Legal rate
     */
    public static function normalizeReleaseRate($rate) {
        if (!is_numeric($rate)) {
            return 0.70;
        }

        return max(0.0, min(1.0, (float) $rate));
    }

    /**
     * 按六系当前余额向下取整计算贡金 / Calculate floor-rounded tribute from all six live balances
     * @param array $resources 六系余额 / Six resource balances
     * @param mixed $rate 缴纳比例 / Tribute rate
     * @return array 六系贡金 / Six-resource tribute
     */
    public static function calculateTribute($resources, $rate) {
        $rate = self::normalizeReleaseRate($rate);
        $scaledRate = (int) round($rate * self::RATE_SCALE);
        $tribute = [];
        foreach (self::RESOURCE_COLUMNS as $type => $column) {
            $balance = 0;
            if (isset($resources[$type])) {
                $balance = max(0, (int) $resources[$type]);
            } elseif (isset($resources[$column])) {
                $balance = max(0, (int) $resources[$column]);
            }
            // 定点拆分避免0.70等浮点数在floor前落到整数下方。 / Fixed-point decomposition prevents values such as 0.70 from slipping below an integer before floor.
            $wholeUnits = intdiv($balance, self::RATE_SCALE);
            $remainder = $balance % self::RATE_SCALE;
            $tribute[$type] = $wholeUnits * $scaledRate
                + intdiv(
                    $remainder * $scaledRate,
                    self::RATE_SCALE
                );
        }

        return $tribute;
    }

    /**
     * 读取有效附属关系 / Read an active vassal relation
     * @param int $userId 玩家ID / User ID
     * @return array|null 有效关系或空值 / Active relation or null
     */
    public function getActiveRelation($userId) {
        $relation = $this->readActiveRelation((int) $userId, false);
        if (!$relation) {
            return null;
        }

        $effectiveOwnerId = $this->resolveEffectiveForceOwnerId(
            (int) $relation['lord_id'],
            [(int) $userId => true]
        );
        if ($effectiveOwnerId !== null
            && $effectiveOwnerId !== (int) $relation['overlord_id']) {
            $query = "SELECT username FROM users WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $effectiveOwnerId);
            $stmt->execute();
            $result = $stmt->get_result();
            $owner = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($owner) {
                $relation['overlord_id'] = $effectiveOwnerId;
                $relation['overlord_name'] = $owner['username'];
            }
        }

        return $relation;
    }

    /**
     * 判断玩家当前是否为附属 / Determine whether a player is currently a vassal
     * @param int $userId 玩家ID / User ID
     * @return bool 是否附属 / Whether vassalized
     */
    public function isVassalized($userId) {
        return $this->getActiveRelation((int) $userId) !== null;
    }

    /**
     * 读取玩家当前有效势力领袖 / Read the player's current effective-force owner
     * @param int $userId 玩家ID / User ID
     * @return int|null 势力领袖ID或空值 / Effective-force owner ID or null
     */
    public function getEffectiveForceOwnerId($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return null;
        }

        $owners = $this->getEffectiveForceOwnerIds([$userId]);
        return isset($owners[$userId]) ? (int) $owners[$userId] : null;
    }

    /**
     * 批量解析玩家沿任意深宗主链后的有效势力领袖 / Resolve effective-force owners through arbitrarily deep lord chains in batch
     * @param array $userIds 玩家ID / User IDs
     * @return array 以玩家ID为键的势力领袖ID / Force-owner IDs keyed by user ID
     */
    public function getEffectiveForceOwnerIds($userIds) {
        $graph = $this->loadEffectiveForceGraph($userIds);
        $normalizedIds = $graph['requested_ids'];
        if (empty($normalizedIds)) {
            return [];
        }

        $memoizedOwners = [];
        $owners = [];
        foreach ($normalizedIds as $userId) {
            $ownerId = $this->resolveEffectiveForceOwnerFromGraph(
                $userId,
                $graph['relations'],
                $graph['base_owners'],
                $memoizedOwners,
                []
            );
            $owners[$userId] = $ownerId !== null ? $ownerId : $userId;
        }

        return $owners;
    }

    /**
     * 收集有效势力解析涉及的全部宗主链玩家 / Collect every user participating in effective-force resolution chains
     * @param array $userIds 起始玩家ID / Starting user IDs
     * @return array 已排序且去重的链上玩家ID / Sorted unique chain user IDs
     */
    public function getEffectiveForceChainUserIds($userIds) {
        $graph = $this->loadEffectiveForceGraph($userIds);
        $chainUserIds = array_map(
            'intval',
            array_keys($graph['discovered_users'])
        );
        sort($chainUserIds, SORT_NUMERIC);

        return $chainUserIds;
    }

    /**
     * 以当前读复核已锁定用户覆盖的有效势力链 / Revalidate the force chain with current reads limited to already locked users
     *
     * 新发现但尚未锁定的玩家只会出现在返回值中，不会继续读取其关系；
     * 调用方据此回滚并按完整新链重试，避免破坏全局用户锁顺序。
     * Newly discovered but unlocked users are returned without traversing their
     * relations, allowing the caller to roll back and retry with a complete,
     * correctly ordered user-lock set.
     *
     * @param array $userIds 起始玩家ID / Starting user IDs
     * @param array $lockedUserIds 已按ID锁定的玩家 / Users already locked by ID
     * @return array 当前读发现的链上玩家ID / Chain user IDs found by current reads
     */
    public function getEffectiveForceChainUserIdsForUpdate(
        $userIds,
        $lockedUserIds
    ) {
        $graph = $this->loadEffectiveForceGraph(
            $userIds,
            true,
            $lockedUserIds
        );
        $chainUserIds = array_map(
            'intval',
            array_keys($graph['discovered_users'])
        );
        sort($chainUserIds, SORT_NUMERIC);

        return $chainUserIds;
    }

    /**
     * 以当前读解析已完整锁定链的有效势力领袖 / Resolve force owners with current reads after the complete chain is locked
     * @param array $userIds 起始玩家ID / Starting user IDs
     * @param array $lockedUserIds 已按ID锁定的玩家 / Users already locked by ID
     * @return array 以玩家ID为键的势力领袖ID / Force-owner IDs keyed by user ID
     */
    public function getEffectiveForceOwnerIdsForUpdate(
        $userIds,
        $lockedUserIds
    ) {
        $graph = $this->loadEffectiveForceGraph(
            $userIds,
            true,
            $lockedUserIds
        );
        $lockedLookup = [];
        foreach ((array) $lockedUserIds as $lockedUserId) {
            $lockedUserId = (int) $lockedUserId;
            if ($lockedUserId > 0) {
                $lockedLookup[$lockedUserId] = true;
            }
        }
        if (!empty(array_diff_key(
            $graph['discovered_users'],
            $lockedLookup
        ))) {
            throw new RuntimeException(
                '有效势力链尚未完整锁定 / Effective-force chain is not fully locked'
            );
        }

        $memoizedOwners = [];
        $owners = [];
        foreach ($graph['requested_ids'] as $userId) {
            $ownerId = $this->resolveEffectiveForceOwnerFromGraph(
                $userId,
                $graph['relations'],
                $graph['base_owners'],
                $memoizedOwners,
                []
            );
            $owners[$userId] = $ownerId !== null ? $ownerId : $userId;
        }

        return $owners;
    }

    /**
     * 批量装载有效势力关系图 / Load the effective-force relation graph in batches
     * @param array $userIds 起始玩家ID / Starting user IDs
     * @param bool $forUpdate 是否使用当前加锁读 / Whether to use locking current reads
     * @param array|null $allowedUserIds 允许继续遍历的已锁定玩家 / Locked users allowed for traversal
     * @return array 关系图与已检查玩家 / Relation graph and checked users
     */
    private function loadEffectiveForceGraph(
        $userIds,
        $forUpdate = false,
        $allowedUserIds = null
    ) {
        $normalizedIds = [];
        foreach ((array) $userIds as $userId) {
            $userId = (int) $userId;
            if ($userId > 0) {
                $normalizedIds[$userId] = $userId;
            }
        }
        if (empty($normalizedIds)) {
            return [
                'requested_ids' => [],
                'relations' => [],
                'base_owners' => [],
                'discovered_users' => []
            ];
        }

        $allowedUsers = null;
        if (is_array($allowedUserIds)) {
            $allowedUsers = [];
            foreach ($allowedUserIds as $allowedUserId) {
                $allowedUserId = (int) $allowedUserId;
                if ($allowedUserId > 0) {
                    $allowedUsers[$allowedUserId] = true;
                }
            }
        }

        $relations = [];
        $baseOwners = [];
        $relationChecked = [];
        $baseOwnerChecked = [];
        $discoveredUsers = $normalizedIds;
        $pendingIds = [];
        foreach ($normalizedIds as $normalizedId) {
            if ($allowedUsers === null
                || isset($allowedUsers[$normalizedId])) {
                $pendingIds[] = $normalizedId;
            }
        }

        while (!empty($pendingIds)) {
            $relationCandidates = [];
            foreach ($pendingIds as $pendingId) {
                $pendingId = (int) $pendingId;
                if ($pendingId > 0 && !isset($relationChecked[$pendingId])) {
                    $relationChecked[$pendingId] = true;
                    $relationCandidates[$pendingId] = $pendingId;
                }
            }
            if (empty($relationCandidates)) {
                break;
            }

            $batchRelations = $this->readActiveRelationsForUsers(
                array_values($relationCandidates),
                $forUpdate
            );
            foreach ($batchRelations as $vassalId => $relation) {
                $relations[(int) $vassalId] = $relation;
            }

            $nextIds = [];
            $baseCandidates = [];
            foreach ($relationCandidates as $candidateId) {
                if (isset($relations[$candidateId])) {
                    $lordId = (int) $relations[$candidateId]['lord_id'];
                    if ($lordId > 0) {
                        $discoveredUsers[$lordId] = $lordId;
                    }
                    if ($lordId > 0
                        && !isset($relationChecked[$lordId])
                        && ($allowedUsers === null
                            || isset($allowedUsers[$lordId]))) {
                        $nextIds[$lordId] = $lordId;
                    }
                } elseif (!isset($baseOwnerChecked[$candidateId])) {
                    $baseOwnerChecked[$candidateId] = true;
                    $baseCandidates[$candidateId] = $candidateId;
                }
            }

            if (!empty($baseCandidates)) {
                $batchBaseOwners = $this->readAllianceForceOwnersForUsers(
                    array_values($baseCandidates),
                    $forUpdate
                );
                foreach ($baseCandidates as $candidateId) {
                    $baseOwnerId = isset($batchBaseOwners[$candidateId])
                        ? (int) $batchBaseOwners[$candidateId]
                        : (int) $candidateId;
                    $baseOwners[$candidateId] = $baseOwnerId;
                    if ($baseOwnerId > 0) {
                        $discoveredUsers[$baseOwnerId] = $baseOwnerId;
                    }
                    if ($baseOwnerId > 0
                        && $baseOwnerId !== (int) $candidateId
                        && !isset($relationChecked[$baseOwnerId])
                        && ($allowedUsers === null
                            || isset($allowedUsers[$baseOwnerId]))) {
                        $nextIds[$baseOwnerId] = $baseOwnerId;
                    }
                }
            }

            $pendingIds = array_values($nextIds);
        }

        return [
            'requested_ids' => $normalizedIds,
            'relations' => $relations,
            'base_owners' => $baseOwners,
            'discovered_users' => $discoveredUsers
        ];
    }

    /**
     * 判断两名玩家是否属于同一有效势力 / Determine whether two players share an effective force
     * @param int $firstUserId 第一玩家 / First player
     * @param int $secondUserId 第二玩家 / Second player
     * @return bool 是否同势力 / Whether in the same force
     */
    public function areUsersInSameForce($firstUserId, $secondUserId) {
        $firstUserId = (int) $firstUserId;
        $secondUserId = (int) $secondUserId;
        if ($firstUserId <= 0 || $secondUserId <= 0) {
            return false;
        }
        if ($firstUserId === $secondUserId) {
            return true;
        }

        $owners = $this->getEffectiveForceOwnerIds([
            $firstUserId,
            $secondUserId
        ]);

        return isset($owners[$firstUserId], $owners[$secondUserId])
            && (int) $owners[$firstUserId] === (int) $owners[$secondUserId];
    }

    /**
     * 判断两名玩家能否发生世界敌对行为 / Determine whether two users may perform a hostile world action
     * @param int $attackerId 攻击者 / Attacker
     * @param int $defenderId 防守者 / Defender
     * @return bool 是否允许 / Whether allowed
     */
    public function canUsersFight($attackerId, $defenderId) {
        $attackerId = (int) $attackerId;
        $defenderId = (int) $defenderId;

        return $attackerId > 0
            && $defenderId > 0
            && $attackerId !== $defenderId
            && !$this->areUsersInSameForce($attackerId, $defenderId);
    }

    /**
     * 在主城战事务内完成征服、改宗或救出 / Resolve conquest, supersession, or rescue inside a main-city battle transaction
     *
     * 调用方必须已经锁定赛季、战斗、双方用户、附属关系和联盟身份。
     * The caller must already hold season, battle, user, vassalage, and
     * alliance-membership locks.
     *
     * @param int $attackerId 攻击玩家 / Attacking player
     * @param int $defenderId 主城玩家 / Main-city player
     * @param int $battleId 战斗ID / Battle ID
     * @param array|null $validatedForceOwnerIds 锁内当前读势力归属 / Force owners validated by locking current reads
     * @return array 结算摘要 / Resolution summary
     */
    public function resolveMainCityCaptureInTransaction(
        $attackerId,
        $defenderId,
        $battleId,
        $validatedForceOwnerIds = null
    ) {
        $attackerId = (int) $attackerId;
        $defenderId = (int) $defenderId;
        $battleId = (int) $battleId;
        if ($attackerId <= 0
            || $defenderId <= 0
            || $battleId <= 0
            || $attackerId === $defenderId) {
            throw new InvalidArgumentException(
                '主城附属参数无效 / Invalid main-city vassalage parameters'
            );
        }

        $activeRelation = $this->readActiveRelation($defenderId, true);
        if ($activeRelation
            && $this->isRescueAttacker($attackerId, $activeRelation)) {
            $restoration = $this->restorePreviousAlliance($activeRelation);
            $query = "UPDATE vassal_relations
                      SET status = 'rescued', ended_by_user_id = ?,
                          ended_at = NOW()
                      WHERE relation_id = ? AND status = 'active'";
            $stmt = $this->db->prepare($query);
            $relationId = (int) $activeRelation['relation_id'];
            $stmt->bind_param('ii', $attackerId, $relationId);
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException(
                    '附属关系已经变化 / Vassal relation changed'
                );
            }

            $this->recordGameplayEvent(
                $defenderId,
                'vassal_rescued',
                1,
                'battle',
                $battleId
            );
            $this->recordGameplayEvent(
                $attackerId,
                'vassal_rescue_completed',
                1,
                'battle',
                $battleId
            );

            return [
                'type' => 'rescued',
                'relation_id' => $relationId,
                'vassal_id' => $defenderId,
                'rescuer_id' => $attackerId,
                'restored_alliance' => $restoration['restored'],
                'alliance_name' => $restoration['alliance_name']
            ];
        }

        $validatedForceOwnerIds = is_array($validatedForceOwnerIds)
            ? $validatedForceOwnerIds
            : [];
        $hasValidatedOwners = isset(
            $validatedForceOwnerIds[$attackerId],
            $validatedForceOwnerIds[$defenderId]
        );
        $canFight = $hasValidatedOwners
            ? (int) $validatedForceOwnerIds[$attackerId]
                !== (int) $validatedForceOwnerIds[$defenderId]
            : $this->canUsersFight($attackerId, $defenderId);
        if (!$canFight) {
            throw new RuntimeException(
                '同势力不能征服主城 / The same force cannot subjugate a main city'
            );
        }

        if ($activeRelation) {
            $previous = [
                'force_owner_id' => (int) $activeRelation['previous_force_owner_id'],
                'alliance_id' => $activeRelation['previous_alliance_id'] === null
                    ? null
                    : (int) $activeRelation['previous_alliance_id'],
                'role' => $activeRelation['previous_alliance_role'],
                'contribution' => (int) $activeRelation['previous_alliance_contribution'],
                'joined_at' => $activeRelation['previous_alliance_joined_at']
            ];
            $query = "UPDATE vassal_relations
                      SET status = 'replaced', ended_by_user_id = ?,
                          ended_at = NOW()
                      WHERE relation_id = ? AND status = 'active'";
            $stmt = $this->db->prepare($query);
            $relationId = (int) $activeRelation['relation_id'];
            $stmt->bind_param('ii', $attackerId, $relationId);
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException(
                    '旧附属关系已经变化 / Previous vassal relation changed'
                );
            }
        } else {
            $membership = $this->readMembershipForUpdate($defenderId);
            $previous = $this->snapshotPreviousForce($defenderId, $membership);
            if ($membership) {
                $this->detachAllianceMembership($membership);
            } else {
                $this->cancelPendingAllianceApplications($defenderId);
            }
        }

        $overlordId = $hasValidatedOwners
            ? (int) $validatedForceOwnerIds[$attackerId]
            : $this->getEffectiveForceOwnerIdForUpdate($attackerId);
        if ($overlordId === null || $overlordId === $defenderId) {
            throw new RuntimeException(
                '宗主势力无效 / Invalid overlord force'
            );
        }

        $query = "INSERT INTO vassal_relations
                     (vassal_id, lord_id, overlord_id,
                      previous_force_owner_id, previous_alliance_id,
                      previous_alliance_role, previous_alliance_contribution,
                      previous_alliance_joined_at, capture_battle_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $previousAllianceId = $previous['alliance_id'];
        $previousRole = $previous['role'];
        $previousContribution = (int) $previous['contribution'];
        $previousJoinedAt = $previous['joined_at'];
        $previousForceOwnerId = (int) $previous['force_owner_id'];
        $stmt->bind_param(
            'iiiiisisi',
            $defenderId,
            $attackerId,
            $overlordId,
            $previousForceOwnerId,
            $previousAllianceId,
            $previousRole,
            $previousContribution,
            $previousJoinedAt,
            $battleId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法建立附属关系 / Failed to create vassal relation'
            );
        }
        $relationId = (int) $this->db->insert_id;
        $stmt->close();

        if ($activeRelation) {
            $this->copyRescueEligibility(
                (int) $activeRelation['relation_id'],
                $relationId
            );
        } else {
            $this->snapshotRescueEligibility(
                $relationId,
                $defenderId,
                $previous['alliance_id']
            );
        }

        $this->recordGameplayEvent(
            $defenderId,
            'vassal_subjugated',
            1,
            'battle',
            $battleId
        );
        $this->recordGameplayEvent(
            $attackerId,
            'vassal_acquired',
            1,
            'battle',
            $battleId
        );

        return [
            'type' => $activeRelation ? 're_subjugated' : 'subjugated',
            'relation_id' => $relationId,
            'vassal_id' => $defenderId,
            'lord_id' => $attackerId,
            'overlord_id' => $overlordId
        ];
    }

    /**
     * 获取玩家附属与脱离概览 / Get a player's vassalage and release overview
     * @param int $userId 玩家ID / User ID
     * @return array 概览 / Overview
     */
    public function getOverview($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return $this->failure('玩家参数无效 / Invalid player');
        }

        $relation = $this->getActiveRelation($userId);
        $settings = $this->getReleaseSettings();
        $payments = array_fill_keys(array_keys(self::RESOURCE_COLUMNS), 0);
        if ($relation) {
            $query = "SELECT bright_crystal, warm_crystal, cold_crystal,
                             green_crystal, day_crystal, night_crystal
                      FROM resources
                      WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $resources = $result ? $result->fetch_assoc() : [];
            $stmt->close();
            $payments = self::calculateTribute(
                $resources,
                $settings['resource_rate']
            );
        }

        $query = "SELECT
                    SUM(CASE WHEN type IN ('empty','resource') THEN 1 ELSE 0 END)
                      AS ordinary_territory_count,
                    SUM(CASE WHEN type = 'player_city' THEN 1 ELSE 0 END)
                      AS city_tile_count
                  FROM map_tiles
                  WHERE owner_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $territory = $result ? $result->fetch_assoc() : [];
        $stmt->close();

        $query = "SELECT COUNT(*) AS sub_base_count
                  FROM cities
                  WHERE owner_id = ? AND is_main_city = 0";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subBase = $result ? $result->fetch_assoc() : ['sub_base_count' => 0];
        $stmt->close();

        return [
            'success' => true,
            'message' => $relation
                ? '附属状态已加载 / Vassal status loaded'
                : '当前为独立状态 / Currently independent',
            'relation' => $relation,
            'settings' => $settings,
            'tribute' => $payments,
            'ordinary_territory_count' => isset($territory['ordinary_territory_count'])
                ? (int) $territory['ordinary_territory_count']
                : 0,
            'city_tile_count' => isset($territory['city_tile_count'])
                ? (int) $territory['city_tile_count']
                : 0,
            'sub_base_count' => (int) $subBase['sub_base_count']
        ];
    }

    /**
     * 缴纳贡金并主动脱离 / Pay tribute and voluntarily leave vassalage
     * @param int $userId 附属玩家 / Vassal player
     * @return array 操作结果 / Operation result
     */
    public function redeem($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return $this->failure('玩家参数无效 / Invalid player');
        }
        if (function_exists('isSeasonGameplayFrozen')
            && isSeasonGameplayFrozen()) {
            return $this->failure(
                '赛季结算冻结期间不能脱离附属 / Vassal release is unavailable during season settlement'
            );
        }

        $relationPreview = $this->getActiveRelation($userId);
        if (!$relationPreview) {
            return $this->failure(
                '当前没有需要解除的附属关系 / No active vassal relation'
            );
        }
        $settings = $this->getReleaseSettings();
        $lordId = (int) $relationPreview['lord_id'];

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);
            // 待处理战斗必须先于用户和世界实体按ID锁定并取消。 / Lock and cancel pending battles by ID before users and world entities.
            $this->cancelPendingUserBattles($userId);
            $this->lockUserRows([$userId, $lordId]);

            $relation = $this->readActiveRelation($userId, true);
            if (!$relation || (int) $relation['lord_id'] !== $lordId) {
                throw new RuntimeException(
                    '附属关系已经变化 / Vassal relation changed'
                );
            }

            $relocation = $this->relocateAfterReleaseLocked(
                $userId,
                $settings
            );
            // 资源延后到城市、军队和地图之后锁定，以匹配战斗结算锁序。 / Lock resources after cities, armies, and map state to match battle-resolution ordering.
            $resourceRows = $this->lockResourceRows($userId, $lordId);
            $tribute = self::calculateTribute(
                $resourceRows[$userId],
                $settings['resource_rate']
            );
            $this->transferTributeLocked(
                $userId,
                $lordId,
                $resourceRows,
                $tribute
            );
            $paymentJson = json_encode($tribute, JSON_UNESCAPED_UNICODE);
            if ($paymentJson === false) {
                throw new RuntimeException(
                    '无法记录贡金 / Failed to encode tribute'
                );
            }

            $query = "UPDATE vassal_relations
                      SET status = 'redeemed', ended_by_user_id = ?,
                          release_payment_json = ?,
                          release_destination = ?,
                          refunded_circuit_points = ?,
                          ended_at = NOW()
                      WHERE relation_id = ? AND status = 'active'";
            $stmt = $this->db->prepare($query);
            $destination = $relocation['destination'];
            $refundedCircuit = (int) $relocation['refunded_circuit_points'];
            $relationId = (int) $relation['relation_id'];
            $stmt->bind_param(
                'issii',
                $userId,
                $paymentJson,
                $destination,
                $refundedCircuit,
                $relationId
            );
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException(
                    '附属关系已经变化 / Vassal relation changed'
                );
            }

            $this->removeEmptyPreviousAlliance($relation);
            $this->recordGameplayEvent(
                $userId,
                'vassal_redeemed',
                1,
                'vassal_relation',
                $relationId
            );
            $this->recordGameplayEvent(
                $lordId,
                'vassal_tribute_received',
                1,
                'vassal_relation',
                $relationId
            );

            $this->db->commit();

            return [
                'success' => true,
                'message' => '贡金已缴纳，主城已迁移并恢复独立 / Tribute paid; the main city relocated and independence was restored',
                'tribute' => $tribute,
                'destination' => $destination,
                'destination_x' => (int) $relocation['x'],
                'destination_y' => (int) $relocation['y'],
                'removed_territories' => (int) $relocation['removed_territories'],
                'removed_sub_bases' => (int) $relocation['removed_sub_bases'],
                'refunded_circuit_points' => $refundedCircuit
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Vassal release failed: ' . $exception->getMessage());
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * 读取后台脱离策略 / Read administration-controlled release rules
     * @return array 脱离策略 / Release rules
     */
    public function getReleaseSettings() {
        $mode = (string) GameConfig::get(
            'vassal_release_relocation_mode',
            'outer'
        );
        if (!in_array($mode, ['outer', 'middle', 'subbase'], true)) {
            $mode = 'outer';
        }

        return [
            'resource_rate' => self::normalizeReleaseRate(
                GameConfig::get('vassal_release_resource_rate', 0.70)
            ),
            'relocation_mode' => $mode,
            'lose_all_territory' => $this->configBoolean(
                GameConfig::get('vassal_release_lose_all_territory', 1)
            ),
            'refund_circuit' => $this->configBoolean(
                GameConfig::get('vassal_release_refund_circuit', 1)
            )
        ];
    }

    /**
     * 锁定指定玩家的附属关系，供战斗固定锁序调用 / Lock vassal rows for battle's stable lock order
     * @param array $userIds 玩家ID列表 / User IDs
     * @return void
     */
    public function lockRelationsForUsers($userIds) {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $userIds),
            function($userId) {
                return $userId > 0;
            }
        )));
        sort($userIds, SORT_NUMERIC);
        foreach ($userIds as $userId) {
            $query = "SELECT relation_id
                      FROM vassal_relations
                      WHERE vassal_id = ? AND status = 'active'
                      ORDER BY relation_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->get_result();
            $stmt->close();
        }
    }

    /**
     * 读取附属关系及显示名称 / Read a vassal relation with display names
     * @param int $userId 玩家ID / User ID
     * @param bool $forUpdate 是否加锁 / Whether to lock
     * @return array|null 关系或空值 / Relation or null
     */
    private function readActiveRelation($userId, $forUpdate) {
        if ($forUpdate) {
            // 事务路径只锁关系本身，避免联表FOR UPDATE打乱用户锁顺序。 / Transactional reads lock only the relation row so joined user rows cannot reverse the user-lock order.
            $query = "SELECT *
                      FROM vassal_relations
                      WHERE vassal_id = ? AND status = 'active'
                      ORDER BY relation_id DESC
                      LIMIT 1
                      FOR UPDATE";
        } else {
            $query = "SELECT vr.*, lord.username AS lord_name,
                             overlord.username AS overlord_name,
                             previous_owner.username AS previous_force_owner_name,
                             alliance.name AS previous_alliance_name,
                             alliance.tag AS previous_alliance_tag
                      FROM vassal_relations vr
                      INNER JOIN users lord ON lord.user_id = vr.lord_id
                      INNER JOIN users overlord ON overlord.user_id = vr.overlord_id
                      INNER JOIN users previous_owner
                        ON previous_owner.user_id = vr.previous_force_owner_id
                      LEFT JOIN alliances alliance
                        ON alliance.alliance_id = vr.previous_alliance_id
                      WHERE vr.vassal_id = ? AND vr.status = 'active'
                      ORDER BY vr.relation_id DESC
                      LIMIT 1";
        }
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $relation = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $relation ?: null;
    }

    /**
     * 批量读取指定玩家的有效附属关系 / Read active vassal relations for users in batches
     * @param array $userIds 玩家ID / User IDs
     * @param bool $forUpdate 是否使用当前加锁读 / Whether to use a locking current read
     * @return array 以附属玩家ID为键的关系 / Relations keyed by vassal ID
     */
    private function readActiveRelationsForUsers(
        $userIds,
        $forUpdate = false
    ) {
        $relations = [];
        foreach (array_chunk(array_values($userIds), 500) as $chunk) {
            if (empty($chunk)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $lockClause = $forUpdate ? ' FOR UPDATE' : '';
            $query = "SELECT vassal_id, lord_id, overlord_id
                      FROM vassal_relations
                      WHERE status = 'active'
                        AND vassal_id IN ({$placeholders})
                      ORDER BY relation_id DESC{$lockClause}";
            $stmt = $this->db->prepare($query);
            $parameters = array_map('intval', $chunk);
            $types = str_repeat('i', count($parameters));
            $stmt->bind_param($types, ...$parameters);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $vassalId = (int) $row['vassal_id'];
                if (!isset($relations[$vassalId])) {
                    $relations[$vassalId] = [
                        'lord_id' => (int) $row['lord_id'],
                        'overlord_id' => (int) $row['overlord_id']
                    ];
                }
            }
            $stmt->close();
        }

        return $relations;
    }

    /**
     * 批量读取无附属玩家的联盟势力领袖 / Read alliance force owners for non-vassal users in batches
     * @param array $userIds 玩家ID / User IDs
     * @param bool $forUpdate 是否使用当前加锁读 / Whether to use a locking current read
     * @return array 以玩家ID为键的基础势力领袖 / Base force owners keyed by user ID
     */
    private function readAllianceForceOwnersForUsers(
        $userIds,
        $forUpdate = false
    ) {
        $owners = [];
        foreach (array_chunk(array_values($userIds), 500) as $chunk) {
            if (empty($chunk)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $lockClause = $forUpdate ? ' FOR UPDATE' : '';
            $query = "SELECT am.user_id,
                             COALESCE(a.leader_id, am.user_id) AS force_owner_id
                      FROM alliance_members am
                      LEFT JOIN alliances a ON a.alliance_id = am.alliance_id
                      WHERE am.user_id IN ({$placeholders}){$lockClause}";
            $stmt = $this->db->prepare($query);
            $parameters = array_map('intval', $chunk);
            $types = str_repeat('i', count($parameters));
            $stmt->bind_param($types, ...$parameters);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $owners[(int) $row['user_id']] =
                    (int) $row['force_owner_id'];
            }
            $stmt->close();
        }

        return $owners;
    }

    /**
     * 在已批量装载的关系图中递归解析势力领袖 / Recursively resolve a force owner in the batch-loaded relation graph
     * @param int $userId 玩家ID / User ID
     * @param array $relations 附属关系图 / Vassal relation graph
     * @param array $baseOwners 联盟基础归属 / Alliance base ownership
     * @param array $memoizedOwners 已解析结果 / Memoized results
     * @param array $visiting 当前递归路径 / Current recursion path
     * @return int|null 势力领袖或循环标记 / Force owner or cycle marker
     */
    private function resolveEffectiveForceOwnerFromGraph(
        $userId,
        $relations,
        $baseOwners,
        &$memoizedOwners,
        $visiting
    ) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return null;
        }
        if (isset($memoizedOwners[$userId])) {
            return (int) $memoizedOwners[$userId];
        }
        if (isset($visiting[$userId])) {
            return null;
        }
        $visiting[$userId] = true;

        if (isset($relations[$userId])) {
            $relation = $relations[$userId];
            $ownerId = $this->resolveEffectiveForceOwnerFromGraph(
                (int) $relation['lord_id'],
                $relations,
                $baseOwners,
                $memoizedOwners,
                $visiting
            );
            if ($ownerId === null) {
                $ownerId = (int) $relation['overlord_id'];
            }
        } else {
            $baseOwnerId = isset($baseOwners[$userId])
                ? (int) $baseOwners[$userId]
                : $userId;
            if ($baseOwnerId !== $userId) {
                $ownerId = $this->resolveEffectiveForceOwnerFromGraph(
                    $baseOwnerId,
                    $relations,
                    $baseOwners,
                    $memoizedOwners,
                    $visiting
                );
                if ($ownerId === null) {
                    $ownerId = $baseOwnerId;
                }
            } else {
                $ownerId = $userId;
            }
        }

        $memoizedOwners[$userId] = (int) $ownerId;
        return (int) $ownerId;
    }

    /**
     * 沿直接宗主链解析当前势力，联盟换主后也能即时生效 / Resolve the live force through direct lords so alliance leadership changes apply immediately
     * @param int $userId 玩家ID / User ID
     * @param array $visited 已访问玩家 / Visited users
     * @return int|null 势力领袖 / Force owner
     */
    private function resolveEffectiveForceOwnerId($userId, $visited) {
        $userId = (int) $userId;
        if ($userId <= 0 || isset($visited[$userId])) {
            return null;
        }
        $visited[$userId] = true;

        $relation = $this->readActiveRelation($userId, false);
        if ($relation) {
            $resolved = $this->resolveEffectiveForceOwnerId(
                (int) $relation['lord_id'],
                $visited
            );
            return $resolved !== null
                ? $resolved
                : (int) $relation['overlord_id'];
        }

        $query = "SELECT COALESCE(a.leader_id, am.user_id) AS force_owner_id
                  FROM alliance_members am
                  LEFT JOIN alliances a ON a.alliance_id = am.alliance_id
                  WHERE am.user_id = ?
                  LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row && $row['force_owner_id'] !== null
            ? (int) $row['force_owner_id']
            : $userId;
    }

    /**
     * 锁定读取联盟身份 / Read and lock an alliance membership
     * @param int $userId 玩家ID / User ID
     * @return array|null 身份或空值 / Membership or null
     */
    private function readMembershipForUpdate($userId) {
        $query = "SELECT am.member_id, am.alliance_id, am.user_id,
                         am.role, am.contribution, am.joined_at
                  FROM alliance_members am
                  WHERE am.user_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $membership = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $membership ?: null;
    }

    /**
     * 快照原势力和联盟身份 / Snapshot the original force and membership
     * @param int $userId 玩家ID / User ID
     * @param array|null $membership 联盟身份 / Alliance membership
     * @return array 快照 / Snapshot
     */
    private function snapshotPreviousForce($userId, $membership) {
        if (!$membership) {
            return [
                'force_owner_id' => (int) $userId,
                'alliance_id' => null,
                'role' => null,
                'contribution' => 0,
                'joined_at' => null
            ];
        }

        $allianceId = (int) $membership['alliance_id'];
        $query = "SELECT leader_id
                  FROM alliances
                  WHERE alliance_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $allianceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $alliance = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return [
            'force_owner_id' => $alliance && $alliance['leader_id'] !== null
                ? (int) $alliance['leader_id']
                : (int) $userId,
            'alliance_id' => $allianceId,
            'role' => (string) $membership['role'],
            'contribution' => (int) $membership['contribution'],
            'joined_at' => $membership['joined_at']
        ];
    }

    /**
     * 征服时暂停原联盟身份并安全移交盟主 / Suspend the original membership and safely transfer leadership on conquest
     * @param array $membership 已锁定身份 / Locked membership
     * @return void
     */
    private function detachAllianceMembership($membership) {
        $allianceId = (int) $membership['alliance_id'];
        $userId = (int) $membership['user_id'];
        if ($membership['role'] === 'leader') {
            $query = "SELECT member_id, user_id
                      FROM alliance_members
                      WHERE alliance_id = ? AND user_id <> ?
                      ORDER BY FIELD(role, 'officer', 'member'),
                               joined_at, member_id
                      LIMIT 1
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $allianceId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $successor = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($successor) {
                $successorId = (int) $successor['user_id'];
                $query = "UPDATE alliances
                          SET leader_id = ?
                          WHERE alliance_id = ? AND leader_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param(
                    'iii',
                    $successorId,
                    $allianceId,
                    $userId
                );
                $updated = $stmt->execute()
                    && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$updated) {
                    throw new RuntimeException(
                        '无法移交原联盟盟主 / Failed to transfer original alliance leadership'
                    );
                }

                $query = "UPDATE alliance_members
                          SET role = 'leader'
                          WHERE member_id = ?";
                $stmt = $this->db->prepare($query);
                $successorMemberId = (int) $successor['member_id'];
                $stmt->bind_param('i', $successorMemberId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException(
                        '无法更新原联盟盟主身份 / Failed to update successor membership'
                    );
                }
                $stmt->close();
            } else {
                $query = "UPDATE alliances
                          SET leader_id = NULL
                          WHERE alliance_id = ? AND leader_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $allianceId, $userId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException(
                        '无法暂停空联盟 / Failed to suspend the empty alliance'
                    );
                }
                $stmt->close();
            }
        }

        $query = "DELETE FROM alliance_members
                  WHERE member_id = ? AND user_id = ?";
        $stmt = $this->db->prepare($query);
        $memberId = (int) $membership['member_id'];
        $stmt->bind_param('ii', $memberId, $userId);
        $deleted = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$deleted) {
            throw new RuntimeException(
                '无法暂停原联盟身份 / Failed to suspend original membership'
            );
        }

        // 附属玩家不再占用原联盟协同作战的军队名额。 / A vassal must no longer reserve armies in the former alliance's operations.
        $query = "DELETE FROM alliance_operation_armies
                  WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法解除原联盟军队预约 / Failed to release former alliance armies'
            );
        }
        $stmt->close();

        $this->cancelPendingAllianceApplications($userId);
    }

    /**
     * 征服时关闭尚未处理的联盟申请 / Close unresolved alliance applications on conquest
     * @param int $userId 玩家ID / User ID
     * @return void
     */
    private function cancelPendingAllianceApplications($userId) {
        $query = "UPDATE alliance_applications
                  SET status = 'cancelled', resolved_at = NOW()
                  WHERE user_id = ? AND status = 'pending'";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法关闭联盟申请 / Failed to close alliance applications'
            );
        }
        $stmt->close();
    }

    /**
     * 首次失守时固化原联盟中可救出的成员 / Freeze eligible rescuers from the former alliance on first capture
     * @param int $relationId 新附属关系ID / New relation ID
     * @param int $vassalId 被征服玩家ID / Vassal user ID
     * @param int|null $allianceId 原联盟ID / Former alliance ID
     * @return void
     */
    private function snapshotRescueEligibility(
        $relationId,
        $vassalId,
        $allianceId
    ) {
        if ($allianceId === null) {
            return;
        }

        $query = "INSERT INTO vassal_rescue_eligibility
                     (relation_id, eligible_user_id)
                  SELECT ?, am.user_id
                  FROM alliance_members am
                  WHERE am.alliance_id = ? AND am.user_id <> ?";
        $stmt = $this->db->prepare($query);
        $relationId = (int) $relationId;
        $vassalId = (int) $vassalId;
        $allianceId = (int) $allianceId;
        $stmt->bind_param(
            'iii',
            $relationId,
            $allianceId,
            $vassalId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法保存救出资格快照 / Failed to snapshot rescue eligibility'
            );
        }
        $stmt->close();
    }

    /**
     * 再征服时把首次失守的救出名单复制到新关系 / Copy the first-capture rescue roster to a superseding relation
     * @param int $sourceRelationId 原关系ID / Source relation ID
     * @param int $targetRelationId 新关系ID / Target relation ID
     * @return void
     */
    private function copyRescueEligibility(
        $sourceRelationId,
        $targetRelationId
    ) {
        $query = "INSERT INTO vassal_rescue_eligibility
                     (relation_id, eligible_user_id)
                  SELECT ?, eligible_user_id
                  FROM vassal_rescue_eligibility
                  WHERE relation_id = ?";
        $stmt = $this->db->prepare($query);
        $sourceRelationId = (int) $sourceRelationId;
        $targetRelationId = (int) $targetRelationId;
        $stmt->bind_param(
            'ii',
            $targetRelationId,
            $sourceRelationId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法沿用救出资格快照 / Failed to copy rescue eligibility'
            );
        }
        $stmt->close();
    }

    /**
     * 判断攻击者是否属于被征服者的原势力 / Determine whether the attacker belongs to the vassal's original force
     * @param int $attackerId 攻击者 / Attacker
     * @param array $relation 附属关系 / Vassal relation
     * @return bool 是否可救出 / Whether rescue is allowed
     */
    private function isRescueAttacker($attackerId, $relation) {
        if ((int) $attackerId <= 0
            || (int) $attackerId === (int) $relation['vassal_id']) {
            return false;
        }

        $query = "SELECT 1
                  FROM vassal_rescue_eligibility
                  WHERE relation_id = ? AND eligible_user_id = ?
                  LIMIT 1";
        $stmt = $this->db->prepare($query);
        $relationId = (int) $relation['relation_id'];
        $attackerId = (int) $attackerId;
        $stmt->bind_param('ii', $relationId, $attackerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $matches = $result && $result->num_rows > 0;
        $stmt->close();

        return $matches;
    }

    /**
     * 救出时恢复原联盟身份 / Restore the original alliance membership after rescue
     * @param array $relation 附属关系 / Vassal relation
     * @return array 恢复摘要 / Restoration summary
     */
    private function restorePreviousAlliance($relation) {
        if ($relation['previous_alliance_id'] === null) {
            return ['restored' => false, 'alliance_name' => null];
        }

        $allianceId = (int) $relation['previous_alliance_id'];
        $query = "SELECT alliance_id, name, leader_id
                  FROM alliances
                  WHERE alliance_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $allianceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $alliance = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$alliance) {
            return ['restored' => false, 'alliance_name' => null];
        }

        $vassalId = (int) $relation['vassal_id'];
        $existing = $this->readMembershipForUpdate($vassalId);
        if ($existing) {
            return [
                'restored' => (int) $existing['alliance_id'] === $allianceId,
                'alliance_name' => (string) $alliance['name']
            ];
        }

        $role = $relation['previous_alliance_role'];
        if (!in_array($role, ['leader', 'officer', 'member'], true)) {
            $role = 'member';
        }
        if ($role === 'leader' && $alliance['leader_id'] !== null) {
            $role = 'officer';
        }
        if ($alliance['leader_id'] === null) {
            $role = 'leader';
            $query = "UPDATE alliances
                      SET leader_id = ?
                      WHERE alliance_id = ? AND leader_id IS NULL";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $vassalId, $allianceId);
            $updated = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException(
                    '无法恢复原联盟盟主 / Failed to restore original alliance leadership'
                );
            }
        }

        $contribution = max(
            0,
            (int) $relation['previous_alliance_contribution']
        );
        $joinedAt = $relation['previous_alliance_joined_at']
            ?: date('Y-m-d H:i:s');
        $query = "INSERT INTO alliance_members
                     (alliance_id, user_id, role, contribution, joined_at)
                  VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iisis',
            $allianceId,
            $vassalId,
            $role,
            $contribution,
            $joinedAt
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法恢复原联盟身份 / Failed to restore original alliance membership'
            );
        }
        $stmt->close();

        return [
            'restored' => true,
            'alliance_name' => (string) $alliance['name']
        ];
    }

    /**
     * 在锁内读取攻击者有效势力领袖 / Read an attacker's effective-force owner under lock
     * @param int $userId 玩家ID / User ID
     * @return int|null 势力领袖 / Force owner
     */
    private function getEffectiveForceOwnerIdForUpdate($userId) {
        $relation = $this->readActiveRelation((int) $userId, true);
        if ($relation) {
            $resolved = $this->resolveEffectiveForceOwnerId(
                (int) $relation['lord_id'],
                [(int) $userId => true]
            );
            return $resolved !== null
                ? $resolved
                : (int) $relation['overlord_id'];
        }

        $membership = $this->readMembershipForUpdate((int) $userId);
        if (!$membership) {
            return (int) $userId;
        }

        $allianceId = (int) $membership['alliance_id'];
        $query = "SELECT leader_id
                  FROM alliances
                  WHERE alliance_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $allianceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $alliance = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $alliance && $alliance['leader_id'] !== null
            ? (int) $alliance['leader_id']
            : (int) $userId;
    }

    /**
     * 按ID顺序锁定用户 / Lock users in ascending ID order
     * @param array $userIds 玩家ID / User IDs
     * @return void
     */
    private function lockUserRows($userIds) {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
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
                throw new RuntimeException(
                    '玩家已经不存在 / Player no longer exists'
                );
            }
        }
    }

    /**
     * 按玩家ID锁定双方资源 / Lock both resource rows in user-ID order
     * @param int $firstUserId 第一玩家 / First player
     * @param int $secondUserId 第二玩家 / Second player
     * @return array 资源行 / Resource rows
     */
    private function lockResourceRows($firstUserId, $secondUserId) {
        $lowerId = min((int) $firstUserId, (int) $secondUserId);
        $upperId = max((int) $firstUserId, (int) $secondUserId);
        $query = "SELECT user_id, bright_crystal, warm_crystal,
                         cold_crystal, green_crystal, day_crystal,
                         night_crystal
                  FROM resources
                  WHERE user_id IN (?, ?)
                  ORDER BY user_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $lowerId, $upperId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[(int) $row['user_id']] = $row;
        }
        $stmt->close();
        if (!isset($rows[$lowerId], $rows[$upperId])) {
            throw new RuntimeException(
                '无法锁定双方资源 / Failed to lock both resource rows'
            );
        }

        return $rows;
    }

    /**
     * 精确转移贡金；贡金不受仓库软上限影响但不得溢出整数 / Transfer exact tribute, bypassing soft storage limits without integer overflow
     * @param int $vassalId 附属玩家 / Vassal
     * @param int $lordId 宗主玩家 / Lord
     * @param array $resourceRows 已锁定资源 / Locked resources
     * @param array $tribute 贡金 / Tribute
     * @return void
     */
    private function transferTributeLocked(
        $vassalId,
        $lordId,
        $resourceRows,
        $tribute
    ) {
        foreach (self::RESOURCE_COLUMNS as $type => $column) {
            $amount = isset($tribute[$type])
                ? max(0, (int) $tribute[$type])
                : 0;
            $lordBalance = max(
                0,
                (int) $resourceRows[(int) $lordId][$column]
            );
            if ($lordBalance > 2147483647 - $amount) {
                throw new RuntimeException(
                    '宗主资源已达整数上限，暂时无法接收贡金 / The lord cannot receive tribute without integer overflow'
                );
            }
            if ($amount <= 0) {
                continue;
            }

            $query = "UPDATE resources
                      SET {$column} = {$column} - ?
                      WHERE user_id = ? AND {$column} >= ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iii', $amount, $vassalId, $amount);
            $deducted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$deducted) {
                throw new RuntimeException(
                    '附属资源已经变化 / Vassal resources changed'
                );
            }

            $query = "UPDATE resources
                      SET {$column} = {$column} + ?
                      WHERE user_id = ? AND {$column} <= ?";
            $stmt = $this->db->prepare($query);
            $maximumBeforeAdd = 2147483647 - $amount;
            $stmt->bind_param(
                'iii',
                $amount,
                $lordId,
                $maximumBeforeAdd
            );
            $credited = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$credited) {
                throw new RuntimeException(
                    '宗主资源已经变化 / Lord resources changed'
                );
            }
        }
    }

    /**
     * 在脱离事务内迁城并按配置清算领地 / Relocate the main city and settle territory inside the release transaction
     * @param int $userId 玩家ID / User ID
     * @param array $settings 后台设置 / Administration settings
     * @return array 迁移摘要 / Relocation summary
     */
    private function relocateAfterReleaseLocked($userId, $settings) {
        // 军队锁必须先于城池锁，匹配现有军队解散和守城结算顺序。 / Lock armies before cities to match existing disband and city-defense ordering.
        $this->lockUserArmies($userId);

        $query = "SELECT city_id, name, x, y, level, durability,
                         max_durability, is_main_city
                  FROM cities
                  WHERE owner_id = ?
                  ORDER BY city_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $cities = [];
        $mainCity = null;
        $subBases = [];
        while ($result && ($city = $result->fetch_assoc())) {
            $cities[] = $city;
            if ((int) $city['is_main_city'] === 1) {
                $mainCity = $city;
            } else {
                $subBases[] = $city;
            }
        }
        $stmt->close();
        if (!$mainCity) {
            throw new RuntimeException(
                '主城不存在 / Main city does not exist'
            );
        }
        $this->lockCityDependents($cities, $userId);

        $requestedMode = $settings['relocation_mode'];
        $effectiveMode = $requestedMode;
        $newMainCity = $mainCity;
        $oldMainCityId = (int) $mainCity['city_id'];
        $promotedExistingSubBase = false;
        if ($requestedMode === 'subbase' && !empty($subBases)) {
            $promotedExistingSubBase = true;
            $selectedIndex = random_int(0, count($subBases) - 1);
            $newMainCity = $subBases[$selectedIndex];
            $newMainCityId = (int) $newMainCity['city_id'];

            $query = "UPDATE cities
                      SET is_main_city = 0
                      WHERE city_id = ? AND owner_id = ?
                        AND is_main_city = 1";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $oldMainCityId, $userId);
            $demoted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$demoted) {
                throw new RuntimeException(
                    '旧主城状态已经变化 / Previous main-city state changed'
                );
            }

            $query = "UPDATE cities
                      SET is_main_city = 1, durability = max_durability
                      WHERE city_id = ? AND owner_id = ?
                        AND is_main_city = 0";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $newMainCityId, $userId);
            $promoted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$promoted) {
                throw new RuntimeException(
                    '分基地状态已经变化 / Sub-base state changed'
                );
            }

            $this->setCityTileSubtype(
                (int) $mainCity['x'],
                (int) $mainCity['y'],
                $userId,
                'sub_base'
            );
            $this->setCityTileSubtype(
                (int) $newMainCity['x'],
                (int) $newMainCity['y'],
                $userId,
                null
            );
        } else {
            if ($requestedMode === 'subbase') {
                $effectiveMode = 'outer';
            }
            $destinationTile = $this->lockRandomRelocationTile($effectiveMode);
            $newX = (int) $destinationTile['x'];
            $newY = (int) $destinationTile['y'];
            $oldX = (int) $mainCity['x'];
            $oldY = (int) $mainCity['y'];

            $query = "SELECT tile_id
                      FROM map_tiles
                      WHERE x = ? AND y = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $oldX, $oldY);
            $stmt->execute();
            $oldTileResult = $stmt->get_result();
            $oldTile = $oldTileResult
                ? $oldTileResult->fetch_assoc()
                : null;
            $stmt->close();
            if (!$oldTile) {
                throw new RuntimeException(
                    '旧主城地图格不存在 / Previous main-city tile is missing'
                );
            }

            $query = "UPDATE cities
                      SET x = ?, y = ?, durability = max_durability
                      WHERE city_id = ? AND owner_id = ?
                        AND is_main_city = 1";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iiii',
                $newX,
                $newY,
                $oldMainCityId,
                $userId
            );
            $moved = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$moved) {
                throw new RuntimeException(
                    '主城位置已经变化 / Main-city location changed'
                );
            }

            $this->clearCityTile((int) $oldTile['tile_id'], $userId);
            $query = "UPDATE map_tiles
                      SET type = 'player_city', subtype = NULL,
                          owner_id = ?, resource_amount = NULL,
                          npc_level = NULL, npc_garrison = 0,
                          npc_respawn_time = NULL,
                          last_collection_time = NULL,
                          occupation_circuit_cost = 0,
                          is_visible = 1
                      WHERE tile_id = ? AND type = 'empty'
                        AND owner_id IS NULL";
            $stmt = $this->db->prepare($query);
            $destinationTileId = (int) $destinationTile['tile_id'];
            $stmt->bind_param('ii', $userId, $destinationTileId);
            $occupied = $stmt->execute()
                && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$occupied) {
                throw new RuntimeException(
                    '迁移目的地已经变化 / Relocation destination changed'
                );
            }

            $newMainCity['x'] = $newX;
            $newMainCity['y'] = $newY;
            $newMainCity['durability'] = $newMainCity['max_durability'];
        }

        $newMainCityId = (int) $newMainCity['city_id'];
        $newX = (int) $newMainCity['x'];
        $newY = (int) $newMainCity['y'];
        $removedTerritories = 0;
        $removedResourceTerritories = 0;
        $removedSubBases = 0;
        $refundedCircuit = 0;

        if (!empty($settings['lose_all_territory'])) {
            $this->mergeTerritoryGarrisonsIntoCity($userId, $newMainCityId);
            $this->mergeOtherCitySoldiers(
                $userId,
                $newMainCityId
            );
            $this->reassignCityGenerals($userId, $newMainCityId);
            $this->recallAllArmies(
                $userId,
                $newMainCityId,
                $newX,
                $newY
            );

            foreach ($cities as $city) {
                $cityId = (int) $city['city_id'];
                if ($cityId === $newMainCityId) {
                    continue;
                }
                $query = "SELECT tile_id
                          FROM map_tiles
                          WHERE x = ? AND y = ?
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $cityX = (int) $city['x'];
                $cityY = (int) $city['y'];
                $stmt->bind_param('ii', $cityX, $cityY);
                $stmt->execute();
                $tileResult = $stmt->get_result();
                $tile = $tileResult ? $tileResult->fetch_assoc() : null;
                $stmt->close();
                if ($tile) {
                    $this->clearCityTile((int) $tile['tile_id'], $userId);
                }
                $removedSubBases++;
            }

            $query = "DELETE FROM cities
                      WHERE owner_id = ? AND city_id <> ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $userId, $newMainCityId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    '无法清理旧分基地 / Failed to remove former sub-bases'
                );
            }
            $stmt->close();

            $query = "SELECT
                        SUM(
                          CASE WHEN type IN ('empty','resource')
                            THEN 1 ELSE 0 END
                        ) AS territory_count,
                        SUM(
                          CASE WHEN type = 'resource'
                            THEN 1 ELSE 0 END
                        ) AS resource_territory_count,
                        SUM(
                          CASE WHEN type = 'resource'
                            THEN occupation_circuit_cost ELSE 0 END
                        ) AS refundable_circuit_points,
                        SUM(
                          CASE WHEN type = 'npc_fort'
                            THEN 1 ELSE 0 END
                        ) AS npc_territory_count
                      FROM map_tiles
                      WHERE owner_id = ?
                        AND type IN ('empty','resource','npc_fort')
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            $removedTerritories = $row
                ? max(0, (int) $row['territory_count'])
                : 0;
            $removedResourceTerritories = $row
                ? max(0, (int) $row['resource_territory_count'])
                : 0;
            $resourceCircuitInvestment = $row
                ? max(0, (int) $row['refundable_circuit_points'])
                : 0;
            $removedNpcTerritories = $row
                ? max(0, (int) $row['npc_territory_count'])
                : 0;

            $query = "DELETE FROM territory_garrisons
                      WHERE owner_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    '无法清理领地驻军 / Failed to remove territory garrisons'
                );
            }
            $stmt->close();

            $query = "UPDATE world_sites
                      SET owner_id = NULL, durability = max_durability,
                          captured_at = NULL,
                          occupation_started_at = NULL
                      WHERE owner_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    '无法清理特殊地点 / Failed to clear world-site control'
                );
            }
            $stmt->close();

            $query = "UPDATE map_tiles
                      SET owner_id = NULL,
                          occupation_circuit_cost = 0,
                          last_collection_time = CASE
                            WHEN type = 'resource' THEN NULL
                            ELSE last_collection_time
                          END
                      WHERE owner_id = ?
                        AND type IN ('empty','resource','npc_fort','special')";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    '无法清理领地 / Failed to clear territory'
                );
            }
            $stmt->close();

            // 分基地沿用其转化前普通领地的净分；原始主城本身从未产生领地分。 / Sub-bases retain the net point of their source territory, while the original main city never produced one.
            $removedScoredSubBases = max(
                0,
                count($subBases) - ($promotedExistingSubBase ? 1 : 0)
            );
            $this->reduceSeasonTerritoryScore(
                $userId,
                $removedTerritories
                    + $removedNpcTerritories
                    + $removedScoredSubBases
            );
            if (!empty($settings['refund_circuit'])
                && $removedResourceTerritories > 0
                && $resourceCircuitInvestment > 0) {
                $refundedCircuit = $this->refundTerritoryCircuit(
                    $userId,
                    $resourceCircuitInvestment
                );
            }
        } else {
            $query = "UPDATE armies army
                      INNER JOIN cities home
                        ON home.city_id = army.city_id
                       AND home.owner_id = army.owner_id
                      SET army.status = 'idle',
                          army.current_x = home.x,
                          army.current_y = home.y,
                          army.target_x = NULL, army.target_y = NULL,
                          army.departure_time = NULL,
                          army.arrival_time = NULL,
                          army.return_time = NULL
                      WHERE army.owner_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    '无法召回主城军队 / Failed to recall main-city armies'
                );
            }
            $stmt->close();
        }

        $destination = $effectiveMode === 'subbase'
            ? 'subbase:' . $newMainCityId
            : $effectiveMode . ':' . $newX . ',' . $newY;

        return [
            'destination' => $destination,
            'x' => $newX,
            'y' => $newY,
            'removed_territories' => $removedTerritories,
            'removed_sub_bases' => $removedSubBases,
            'refunded_circuit_points' => $refundedCircuit
        ];
    }

    /**
     * 取消会因迁城失效的待处理战斗 / Cancel pending battles invalidated by relocation
     * @param int $userId 玩家ID / User ID
     * @return void
     */
    private function cancelPendingUserBattles($userId) {
        $query = "SELECT battle.battle_id, battle.attacker_army_id
                  FROM battles battle
                  LEFT JOIN armies attacker
                    ON attacker.army_id = battle.attacker_army_id
                  LEFT JOIN armies defender_army
                    ON defender_army.army_id = battle.defender_army_id
                  LEFT JOIN cities defender_city
                    ON defender_city.city_id = battle.defender_city_id
                  LEFT JOIN map_tiles defender_tile
                    ON defender_tile.tile_id = battle.defender_tile_id
                  WHERE battle.result = 'pending'
                    AND (
                      attacker.owner_id = ?
                      OR defender_army.owner_id = ?
                      OR defender_city.owner_id = ?
                      OR defender_tile.owner_id = ?
                    )
                  ORDER BY battle.battle_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iiii',
            $userId,
            $userId,
            $userId,
            $userId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法读取迁城相关战斗 / Failed to read relocation battles'
            );
        }
        $result = $stmt->get_result();
        $battleIds = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $battleIds[] = [
                'battle_id' => (int) $row['battle_id'],
                'attacker_army_id' => $row['attacker_army_id'] === null
                    ? null
                    : (int) $row['attacker_army_id']
            ];
        }
        $stmt->close();

        foreach ($battleIds as $battleEntry) {
            $battleId = (int) $battleEntry['battle_id'];
            $query = "SELECT battle_id
                      FROM battles
                      WHERE battle_id = ? AND result = 'pending'
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $battleId);
            $stmt->execute();
            $result = $stmt->get_result();
            $pending = $result && $result->num_rows === 1;
            $stmt->close();
            if (!$pending) {
                continue;
            }

            $query = "DELETE FROM battles
                      WHERE battle_id = ? AND result = 'pending'";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $battleId);
            $deleted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$deleted) {
                throw new RuntimeException(
                    '无法取消迁城相关战斗 / Failed to cancel relocation battle'
                );
            }

            if ($battleEntry['attacker_army_id'] !== null) {
                // 外来进攻军不能因目标迁城而永久留在无战斗的行军状态。 / An incoming army must not remain marching forever after relocation removes its battle.
                $query = "UPDATE armies
                          SET status = 'idle',
                              target_x = NULL, target_y = NULL,
                              departure_time = NULL, arrival_time = NULL,
                              return_time = NULL
                          WHERE army_id = ? AND owner_id <> ?";
                $stmt = $this->db->prepare($query);
                $attackerArmyId =
                    (int) $battleEntry['attacker_army_id'];
                $stmt->bind_param('ii', $attackerArmyId, $userId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException(
                        '无法停止迁城相关进攻军 / Failed to stop a relocation attacker'
                    );
                }
                $stmt->close();
            }
        }
    }

    /**
     * 按军队ID锁定玩家军队与编成 / Lock a player's armies and compositions by army ID
     * @param int $userId 玩家ID / User ID
     * @return void
     */
    private function lockUserArmies($userId) {
        $query = "SELECT army_id
                  FROM armies
                  WHERE owner_id = ?
                  ORDER BY army_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $armyIds = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $armyIds[] = (int) $row['army_id'];
        }
        $stmt->close();

        foreach ($armyIds as $armyId) {
            $query = "SELECT army_unit_id
                      FROM army_units
                      WHERE army_id = ?
                      ORDER BY army_unit_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $stmt->get_result();
            $stmt->close();
        }
    }

    /**
     * 按城市顺序锁定士兵与驻城武将 / Lock soldiers and assigned city generals in city order
     * @param array $cities 已锁定城市 / Locked cities
     * @param int $userId 玩家ID / User ID
     * @return void
     */
    private function lockCityDependents($cities, $userId) {
        foreach ($cities as $city) {
            $cityId = (int) $city['city_id'];
            $query = "SELECT soldier_id
                      FROM soldiers
                      WHERE city_id = ?
                      ORDER BY soldier_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $cityId);
            $stmt->execute();
            $stmt->get_result();
            $stmt->close();
        }

        $query = "SELECT ga.assignment_id
                  FROM general_assignments ga
                  INNER JOIN generals g ON g.general_id = ga.general_id
                  INNER JOIN cities c ON c.city_id = ga.target_id
                  WHERE ga.assignment_type = 'city'
                    AND g.owner_id = ? AND c.owner_id = ?
                  ORDER BY ga.assignment_id
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $stmt->get_result();
        $stmt->close();
    }

    /**
     * 锁定随机安全迁城格 / Lock a random safe relocation tile
     * @param string $mode outer或middle / Outer or middle
     * @return array 地图格 / Map tile
     */
    private function lockRandomRelocationTile($mode) {
        $mode = $mode === 'middle' ? 'middle' : 'outer';
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $coordinates = $this->randomRegionCoordinates($mode);
            $query = "SELECT mt.tile_id, mt.x, mt.y
                      FROM map_tiles mt
                      WHERE mt.x = ? AND mt.y = ?
                        AND mt.type = 'empty' AND mt.owner_id IS NULL
                        AND NOT EXISTS (
                          SELECT 1 FROM cities c
                          WHERE c.x = mt.x AND c.y = mt.y
                        )
                        AND NOT EXISTS (
                          SELECT 1 FROM world_sites ws
                          WHERE ws.tile_id = mt.tile_id
                        )
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $x = (int) $coordinates[0];
            $y = (int) $coordinates[1];
            $stmt->bind_param('ii', $x, $y);
            $stmt->execute();
            $result = $stmt->get_result();
            $tile = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($tile) {
                return $tile;
            }
        }

        $outerX = max(1, (int) floor(MAP_WIDTH * 0.20));
        $outerY = max(1, (int) floor(MAP_HEIGHT * 0.20));
        $innerLeft = max($outerX + 1, (int) floor(MAP_WIDTH * 0.35));
        $innerTop = max($outerY + 1, (int) floor(MAP_HEIGHT * 0.35));
        $innerRight = min(MAP_WIDTH - 1, (int) ceil(MAP_WIDTH * 0.65));
        $innerBottom = min(MAP_HEIGHT - 1, (int) ceil(MAP_HEIGHT * 0.65));
        if ($mode === 'middle') {
            $regionSql = "mt.x >= {$outerX}
                          AND mt.x < " . (MAP_WIDTH - $outerX) . "
                          AND mt.y >= {$outerY}
                          AND mt.y < " . (MAP_HEIGHT - $outerY) . "
                          AND (
                            mt.x < {$innerLeft}
                            OR mt.x >= {$innerRight}
                            OR mt.y < {$innerTop}
                            OR mt.y >= {$innerBottom}
                          )";
        } else {
            $regionSql = "(mt.x < {$outerX}
                          OR mt.x >= " . (MAP_WIDTH - $outerX) . "
                          OR mt.y < {$outerY}
                          OR mt.y >= " . (MAP_HEIGHT - $outerY) . ")";
        }

        $query = "SELECT mt.tile_id, mt.x, mt.y
                  FROM map_tiles mt
                  WHERE mt.type = 'empty' AND mt.owner_id IS NULL
                    AND {$regionSql}
                    AND NOT EXISTS (
                      SELECT 1 FROM cities c
                      WHERE c.x = mt.x AND c.y = mt.y
                    )
                    AND NOT EXISTS (
                      SELECT 1 FROM world_sites ws
                      WHERE ws.tile_id = mt.tile_id
                    )
                  ORDER BY RAND()
                  LIMIT 1
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $tile = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$tile) {
            throw new RuntimeException(
                '所选迁移区域没有安全空位 / No safe tile is available in the selected relocation region'
            );
        }

        return $tile;
    }

    /**
     * 从外围或中围生成随机坐标 / Generate random coordinates in the outer or middle band
     * @param string $mode 区域模式 / Region mode
     * @return array 坐标 / Coordinates
     */
    private function randomRegionCoordinates($mode) {
        $side = random_int(0, 3);
        if ($mode === 'middle') {
            $outerX = max(1, (int) floor(MAP_WIDTH * 0.20));
            $outerY = max(1, (int) floor(MAP_HEIGHT * 0.20));
            $innerX = max($outerX, (int) floor(MAP_WIDTH * 0.35));
            $innerY = max($outerY, (int) floor(MAP_HEIGHT * 0.35));
            if ($side === 0) {
                return [
                    random_int($outerX, max($outerX, $innerX - 1)),
                    random_int($outerY, MAP_HEIGHT - $outerY - 1)
                ];
            }
            if ($side === 1) {
                return [
                    random_int(MAP_WIDTH - $innerX, MAP_WIDTH - $outerX - 1),
                    random_int($outerY, MAP_HEIGHT - $outerY - 1)
                ];
            }
            if ($side === 2) {
                return [
                    random_int($outerX, MAP_WIDTH - $outerX - 1),
                    random_int($outerY, max($outerY, $innerY - 1))
                ];
            }

            return [
                random_int($outerX, MAP_WIDTH - $outerX - 1),
                random_int(MAP_HEIGHT - $innerY, MAP_HEIGHT - $outerY - 1)
            ];
        }

        $bandX = max(1, (int) floor(MAP_WIDTH * 0.20));
        $bandY = max(1, (int) floor(MAP_HEIGHT * 0.20));
        if ($side === 0) {
            return [
                random_int(0, $bandX - 1),
                random_int(0, MAP_HEIGHT - 1)
            ];
        }
        if ($side === 1) {
            return [
                random_int(MAP_WIDTH - $bandX, MAP_WIDTH - 1),
                random_int(0, MAP_HEIGHT - 1)
            ];
        }
        if ($side === 2) {
            return [
                random_int(0, MAP_WIDTH - 1),
                random_int(0, $bandY - 1)
            ];
        }

        return [
            random_int(0, MAP_WIDTH - 1),
            random_int(MAP_HEIGHT - $bandY, MAP_HEIGHT - 1)
        ];
    }

    /**
     * 设置城市地图格的主副城标记 / Set the map subtype for a main city or sub-base
     * @param int $x X坐标 / X coordinate
     * @param int $y Y坐标 / Y coordinate
     * @param int $userId 玩家ID / User ID
     * @param string|null $subtype 子类型 / Subtype
     * @return void
     */
    private function setCityTileSubtype($x, $y, $userId, $subtype) {
        $query = "UPDATE map_tiles
                  SET subtype = ?
                  WHERE x = ? AND y = ? AND owner_id = ?
                    AND type = 'player_city'";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('siii', $subtype, $x, $y, $userId);
        $updated = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$updated) {
            throw new RuntimeException(
                '城市地图标记已经变化 / City map marker changed'
            );
        }
    }

    /**
     * 将旧城市格恢复为无主空地 / Restore a former city tile to unowned empty terrain
     * @param int $tileId 地图格ID / Tile ID
     * @param int $userId 原玩家 / Former player
     * @return void
     */
    private function clearCityTile($tileId, $userId) {
        $query = "UPDATE map_tiles
                  SET type = 'empty', subtype = NULL, owner_id = NULL,
                      resource_amount = NULL, npc_level = NULL,
                      npc_garrison = 0, npc_respawn_time = NULL,
                      last_collection_time = NULL,
                      collection_efficiency = 100,
                      occupation_circuit_cost = 0
                  WHERE tile_id = ? AND owner_id = ?
                    AND type = 'player_city'";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $tileId, $userId);
        $updated = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$updated) {
            throw new RuntimeException(
                '旧城市地图格已经变化 / Former city tile changed'
            );
        }
    }

    /**
     * 把领地驻军并回新主城 / Merge territory garrisons into the new main city
     * @param int $userId 玩家ID / User ID
     * @param int $cityId 新主城ID / New main-city ID
     * @return void
     */
    private function mergeTerritoryGarrisonsIntoCity($userId, $cityId) {
        $query = "INSERT INTO soldiers
                     (city_id, type, level, quantity, in_training)
                  SELECT ?, soldier_type, MAX(level),
                         LEAST(2147483647, SUM(quantity)), 0
                  FROM territory_garrisons
                  WHERE owner_id = ?
                  GROUP BY soldier_type
                  ON DUPLICATE KEY UPDATE
                    level = GREATEST(level, VALUES(level)),
                    quantity = LEAST(
                      2147483647,
                      quantity + VALUES(quantity)
                    )";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $cityId, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法返还领地驻军 / Failed to return territory garrisons'
            );
        }
        $stmt->close();
    }

    /**
     * 把其余城市士兵并回新主城 / Merge soldiers from all other cities into the new main city
     * @param int $userId 玩家ID / User ID
     * @param int $cityId 新主城ID / New main-city ID
     * @return void
     */
    private function mergeOtherCitySoldiers($userId, $cityId) {
        $query = "INSERT INTO soldiers
                     (city_id, type, level, quantity, in_training,
                      training_complete_time)
                  SELECT ?, s.type, MAX(s.level),
                         LEAST(2147483647, SUM(s.quantity)),
                         LEAST(2147483647, SUM(s.in_training)),
                         MAX(s.training_complete_time)
                  FROM soldiers s
                  INNER JOIN cities c ON c.city_id = s.city_id
                  WHERE c.owner_id = ? AND c.city_id <> ?
                  GROUP BY s.type
                  ON DUPLICATE KEY UPDATE
                    level = GREATEST(level, VALUES(level)),
                    quantity = LEAST(
                      2147483647,
                      quantity + VALUES(quantity)
                    ),
                    in_training = LEAST(
                      2147483647,
                      in_training + VALUES(in_training)
                    ),
                    training_complete_time = CASE
                      WHEN training_complete_time IS NULL
                        THEN VALUES(training_complete_time)
                      WHEN VALUES(training_complete_time) IS NULL
                        THEN training_complete_time
                      ELSE GREATEST(
                        training_complete_time,
                        VALUES(training_complete_time)
                      )
                    END
                    ";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iii', $cityId, $userId, $cityId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法合并城市士兵 / Failed to merge city soldiers'
            );
        }
        $stmt->close();
    }

    /**
     * 把即将删除城市的驻城武将改派新主城 / Reassign generals from removed cities to the new main city
     * @param int $userId 玩家ID / User ID
     * @param int $cityId 新主城ID / New main-city ID
     * @return void
     */
    private function reassignCityGenerals($userId, $cityId) {
        $query = "UPDATE general_assignments ga
                  INNER JOIN generals g ON g.general_id = ga.general_id
                  INNER JOIN cities c ON c.city_id = ga.target_id
                  SET ga.target_id = ?
                  WHERE ga.assignment_type = 'city'
                    AND g.owner_id = ? AND c.owner_id = ?
                    AND c.city_id <> ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'iiii',
            $cityId,
            $userId,
            $userId,
            $cityId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法改派驻城武将 / Failed to reassign city generals'
            );
        }
        $stmt->close();
    }

    /**
     * 召回全部军队并重挂新主城 / Recall all armies and re-home them to the new main city
     * @param int $userId 玩家ID / User ID
     * @param int $cityId 新主城ID / New main-city ID
     * @param int $x X坐标 / X coordinate
     * @param int $y Y坐标 / Y coordinate
     * @return void
     */
    private function recallAllArmies($userId, $cityId, $x, $y) {
        $query = "UPDATE armies
                  SET city_id = ?, status = 'idle',
                      current_x = ?, current_y = ?,
                      target_x = NULL, target_y = NULL,
                      departure_time = NULL, arrival_time = NULL,
                      return_time = NULL
                  WHERE owner_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iiii', $cityId, $x, $y, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法召回全部军队 / Failed to recall all armies'
            );
        }
        $stmt->close();
    }

    /**
     * 扣减失去领地对应的净赛季分 / Remove net season score for forfeited territory
     * @param int $userId 玩家ID / User ID
     * @param int $territoryCount 已记录的资源地回路投入 / Recorded resource-territory Circuit investment
     * @return void
     */
    private function reduceSeasonTerritoryScore($userId, $territoryCount) {
        $territoryCount = max(0, (int) $territoryCount);
        if ($territoryCount <= 0) {
            return;
        }

        $query = "UPDATE season_scores score
                  INNER JOIN seasons season
                    ON season.season_id = score.season_id
                  SET score.territory_score = GREATEST(
                    0,
                    score.territory_score - ?
                  )
                  WHERE score.user_id = ?
                    AND season.ended_at IS NULL
                    AND season.status IN ('active','victory_countdown')";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $territoryCount, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法扣减赛季领地分 / Failed to reduce season territory score'
            );
        }
        $stmt->close();
    }

    /**
     * 全额返还已释放普通领地占用的思考回路 / Fully refund Circuit Points committed to released ordinary territory
     *
     * 返还属于归还既有投入，不是新产出；因此可以暂时超过常规持有上限。
     * 后续产出仍会等待余额重新低于上限，且任何整数溢出都会回滚整笔脱离。
     * A refund restores an existing investment rather than producing new points,
     * so it may temporarily exceed the normal holding cap. Future production
     * still waits until the balance falls below the cap, and integer overflow
     * rolls back the entire release.
     *
     * @param int $userId 玩家ID / User ID
     * @param int $circuitInvestment 已记录的资源地回路投入 / Recorded resource-tile Circuit investment
     * @return int 全额返还数量 / Full refunded amount
     */
    private function refundTerritoryCircuit($userId, $circuitInvestment) {
        $requested = min(
            2147483647,
            max(0, (int) $circuitInvestment)
        );
        if ($requested <= 0) {
            return 0;
        }

        $query = "SELECT circuit_points
                  FROM users
                  WHERE user_id = ?
                  FOR UPDATE";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$user) {
            throw new RuntimeException(
                '玩家已经不存在 / Player no longer exists'
            );
        }
        $current = max(0, (int) $user['circuit_points']);
        if ($current > 2147483647 - $requested) {
            throw new RuntimeException(
                '思考回路无法全额返还且不溢出整数 / Circuit Points cannot be fully refunded without integer overflow'
            );
        }

        $query = "UPDATE users
                  SET circuit_points = circuit_points + ?
                  WHERE user_id = ? AND circuit_points <= ?";
        $stmt = $this->db->prepare($query);
        $maximumBeforeAdd = 2147483647 - $requested;
        $stmt->bind_param(
            'iii',
            $requested,
            $userId,
            $maximumBeforeAdd
        );
        $refunded = $stmt->execute() && $stmt->affected_rows === 1;
        if (!$refunded) {
            $stmt->close();
            throw new RuntimeException(
                '无法返还思考回路 / Failed to refund Circuit Points'
            );
        }
        $stmt->close();

        return $requested;
    }

    /**
     * 主动脱离后删除因原盟主被俘而留下的空联盟 / Remove an empty alliance left behind by a captured sole leader
     * @param array $relation 已结束关系 / Ended relation
     * @return void
     */
    private function removeEmptyPreviousAlliance($relation) {
        if ($relation['previous_alliance_id'] === null) {
            return;
        }
        $allianceId = (int) $relation['previous_alliance_id'];
        $query = "DELETE alliance
                  FROM alliances alliance
                  LEFT JOIN alliance_members member
                    ON member.alliance_id = alliance.alliance_id
                  WHERE alliance.alliance_id = ?
                    AND alliance.leader_id IS NULL
                    AND member.member_id IS NULL";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $allianceId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法清理空联盟 / Failed to remove empty alliance'
            );
        }
        $stmt->close();
    }

    /**
     * 转换后台布尔值 / Convert an administration boolean
     * @param mixed $value 配置值 / Configuration value
     * @return bool 布尔值 / Boolean value
     */
    private function configBoolean($value) {
        return in_array(
            strtolower((string) $value),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    /**
     * 写入玩法事件 / Record a gameplay event
     * @param int $userId 玩家ID / User ID
     * @param string $eventType 事件类型 / Event type
     * @param int $eventValue 事件数值 / Event value
     * @param string $referenceType 引用类型 / Reference type
     * @param int $referenceId 引用ID / Reference ID
     * @return void
     */
    private function recordGameplayEvent(
        $userId,
        $eventType,
        $eventValue,
        $referenceType,
        $referenceId
    ) {
        $query = "INSERT INTO gameplay_events
                     (user_id, event_type, event_value,
                      reference_type, reference_id)
                  VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'isisi',
            $userId,
            $eventType,
            $eventValue,
            $referenceType,
            $referenceId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法记录附属事件 / Failed to record vassalage event'
            );
        }
        $stmt->close();
    }

    /**
     * 构造失败结果 / Build a failure result
     * @param string $message 消息 / Message
     * @return array 失败结果 / Failure result
     */
    private function failure($message) {
        return [
            'success' => false,
            'message' => (string) $message
        ];
    }
}

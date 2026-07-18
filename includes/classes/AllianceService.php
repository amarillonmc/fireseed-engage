<?php
// 种火集结号 - 联盟与协同作战服务 / Fireseed Engage - Alliance and cooperative operation service

class AllianceService {
    private $db;
    private const DAILY_AID_LIMIT = 1000000;

    private const RESOURCE_COLUMNS = [
        'bright' => 'bright_crystal',
        'warm' => 'warm_crystal',
        'cold' => 'cold_crystal',
        'green' => 'green_crystal',
        'day' => 'day_crystal',
        'night' => 'night_crystal'
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 获取用户的联盟身份 / Get a user's alliance membership
     * @param int $userId 用户 ID / User ID
     * @return array|null 联盟身份或空值 / Membership or null
     */
    public function getMembership($userId) {
        $query = "SELECT am.member_id, am.alliance_id, am.user_id, am.role, am.contribution,
                         am.joined_at, a.name AS alliance_name, a.tag AS alliance_tag
                  FROM alliance_members am
                  INNER JOIN alliances a ON a.alliance_id = am.alliance_id
                  WHERE am.user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $membership = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $membership ?: null;
    }

    /**
     * 列出可申请的联盟 / List alliances available for application
     * @param int $limit 最大数量 / Maximum count
     * @return array 联盟列表 / Alliance list
     */
    public function listAlliances($limit = 50) {
        $limit = max(1, min(100, (int) $limit));
        $query = "SELECT a.alliance_id, a.name, a.tag, a.description, a.level, a.experience,
                         a.created_at, u.username AS leader_name, COUNT(am.member_id) AS member_count
                  FROM alliances a
                  LEFT JOIN users u ON u.user_id = a.leader_id
                  LEFT JOIN alliance_members am ON am.alliance_id = a.alliance_id
                  GROUP BY a.alliance_id, a.name, a.tag, a.description, a.level, a.experience,
                           a.created_at, u.username
                  ORDER BY a.level DESC, member_count DESC, a.created_at ASC
                  LIMIT ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $alliances = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $alliances[] = $row;
        }
        $stmt->close();

        return $alliances;
    }

    /**
     * 创建联盟并将创建者设为盟主 / Create an alliance and make the creator its leader
     * @return array 操作结果 / Operation result
     */
    public function createAlliance($userId, $name, $tag, $description = '') {
        $name = trim((string) $name);
        $tag = strtoupper(trim((string) $tag));
        $description = trim((string) $description);

        $vassalService = new VassalService();
        if ($vassalService->isVassalized($userId)) {
            return $this->failure(
                '附属状态下不能创建联盟，请先获得救出或主动脱离。'
            );
        }

        if ($this->textLength($name) < 2 || $this->textLength($name) > 40) {
            return $this->failure('联盟名称须为 2 至 40 个字符。');
        }
        if (!preg_match('/^[\p{L}\p{N}_-]{2,12}$/u', $tag)) {
            return $this->failure('联盟简称须为 2 至 12 个字母、数字、下划线或连字符。');
        }
        if ($this->textLength($description) > 500) {
            return $this->failure('联盟简介不能超过 500 个字符。');
        }

        $this->db->begin_transaction();
        try {
            // 联盟身份变更与主城征服共用用户→附属→成员锁序。 / Alliance membership changes share the user-to-vassal-to-membership lock order with capital conquest.
            $this->lockUserRows([$userId]);
            $vassalService->lockRelationsForUsers([$userId]);
            if ($vassalService->isVassalized($userId)) {
                $this->db->rollback();
                return $this->failure(
                    '附属状态下不能创建联盟，请先获得救出或主动脱离。'
                );
            }
            $membership = $this->getMembershipForUpdate($userId);
            if ($membership !== null) {
                $this->db->rollback();
                return $this->failure('你已经加入了一个联盟。');
            }

            $query = "INSERT INTO alliances (name, tag, description, leader_id)
                      VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('sssi', $name, $tag, $description, $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                $this->db->rollback();
                return $this->failure('联盟名称或简称已被使用。');
            }
            $allianceId = (int) $this->db->insert_id;
            $stmt->close();

            $query = "INSERT INTO alliance_members (alliance_id, user_id, role)
                      VALUES (?, ?, 'leader')";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $allianceId, $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                $this->db->rollback();
                return $this->failure('无法建立盟主成员记录。');
            }
            $stmt->close();

            $this->recordGameplayEvent($userId, 'alliance_joined', $allianceId);
            $this->db->commit();

            return $this->success('联盟创建成功。', ['alliance_id' => $allianceId]);
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Alliance creation failed: ' . $exception->getMessage());
            return $this->failure('联盟创建失败，请稍后再试。');
        }
    }

    /**
     * 申请加入联盟 / Apply to join an alliance
     * @return array 操作结果 / Operation result
     */
    public function applyToAlliance($userId, $allianceId, $message = '') {
        $allianceId = (int) $allianceId;
        $message = trim((string) $message);
        if ($allianceId <= 0) {
            return $this->failure('请选择有效的联盟。');
        }
        if ($this->textLength($message) > 255) {
            return $this->failure('申请留言不能超过 255 个字符。');
        }
        $this->db->begin_transaction();
        try {
            $this->lockUserRows([$userId]);
            $vassalService = new VassalService();
            $vassalService->lockRelationsForUsers([$userId]);
            if ($vassalService->isVassalized($userId)) {
                $this->db->rollback();
                return $this->failure(
                    '附属状态下不能申请联盟，请先获得救出或主动脱离。'
                );
            }
            if ($this->getMembershipForUpdate($userId) !== null) {
                $this->db->rollback();
                return $this->failure('你已经加入了一个联盟。');
            }

            $query = "SELECT alliance_id
                      FROM alliances
                      WHERE alliance_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $allianceId);
            $stmt->execute();
            $result = $stmt->get_result();
            $allianceExists = $result && $result->num_rows === 1;
            $stmt->close();
            if (!$allianceExists) {
                $this->db->rollback();
                return $this->failure('目标联盟不存在。');
            }

            $query = "INSERT INTO alliance_applications
                         (alliance_id, user_id, message, status,
                          created_at, resolved_at)
                      VALUES (?, ?, ?, 'pending', NOW(), NULL)
                      ON DUPLICATE KEY UPDATE
                         message = VALUES(message), status = 'pending',
                         created_at = NOW(), resolved_at = NULL";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iis', $allianceId, $userId, $message);
            $success = $stmt->execute();
            $stmt->close();
            if (!$success) {
                throw new RuntimeException(
                    '无法提交加入申请 / Failed to submit alliance application'
                );
            }
            $this->db->commit();

            return $this->success('加入申请已提交。');
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log(
                'Alliance application failed: ' . $exception->getMessage()
            );
            return $this->failure('无法提交加入申请。');
        }
    }

    /**
     * 审核联盟申请 / Resolve an alliance application
     * @param string $decision accepted 或 rejected / accepted or rejected
     * @return array 操作结果 / Operation result
     */
    public function resolveApplication($actorId, $applicationId, $decision) {
        $actorId = (int) $actorId;
        $applicationId = (int) $applicationId;
        $decision = (string) $decision;
        if ($actorId <= 0
            || $applicationId <= 0
            || !in_array($decision, ['accepted', 'rejected'], true)) {
            return $this->failure('无效的审核请求。');
        }

        // 先只读申请者ID，再在事务内按玩家ID排序取得权威锁。 / Preview the applicant ID, then acquire authoritative transaction locks in user-ID order.
        $query = "SELECT user_id
                  FROM alliance_applications
                  WHERE application_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $applicationPreview = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$applicationPreview) {
            return $this->failure('申请不存在或不属于你的联盟。');
        }
        $applicantId = (int) $applicationPreview['user_id'];

        $this->db->begin_transaction();
        try {
            $participantIds = array_values(array_unique([
                $actorId,
                $applicantId
            ]));
            sort($participantIds, SORT_NUMERIC);
            $this->lockUserRows($participantIds);
            $vassalService = new VassalService();
            $vassalService->lockRelationsForUsers($participantIds);
            $memberships = [];
            foreach ($participantIds as $participantId) {
                $memberships[$participantId] =
                    $this->getMembershipForUpdate($participantId);
            }
            $actorMembership = $memberships[$actorId];
            if ($actorMembership === null || !in_array($actorMembership['role'], ['leader', 'officer'], true)) {
                $this->db->rollback();
                return $this->failure('只有盟主或干部可以审核申请。');
            }

            $query = "SELECT application_id, alliance_id, user_id, status
                      FROM alliance_applications
                      WHERE application_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $applicationId);
            $stmt->execute();
            $result = $stmt->get_result();
            $application = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$application
                || (int) $application['user_id'] !== $applicantId
                || (int) $application['alliance_id']
                    !== (int) $actorMembership['alliance_id']) {
                $this->db->rollback();
                return $this->failure('申请不存在或不属于你的联盟。');
            }
            if ($application['status'] !== 'pending') {
                $this->db->rollback();
                return $this->failure('该申请已经处理。');
            }

            if ($decision === 'accepted') {
                if ($vassalService->isVassalized($applicantId)) {
                    $this->setApplicationStatus($applicationId, 'rejected');
                    $this->db->commit();
                    return $this->failure(
                        '申请者已处于附属状态，申请已关闭。'
                    );
                }
                if ($memberships[$applicantId] !== null) {
                    $this->setApplicationStatus($applicationId, 'rejected');
                    $this->db->commit();
                    return $this->failure('申请者已经加入了其他联盟，申请已关闭。');
                }

                $query = "INSERT INTO alliance_members (alliance_id, user_id, role)
                          VALUES (?, ?, 'member')";
                $stmt = $this->db->prepare($query);
                $allianceId = (int) $actorMembership['alliance_id'];
                $stmt->bind_param('ii', $allianceId, $applicantId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    $this->db->rollback();
                    return $this->failure('无法接纳该申请者。');
                }
                $stmt->close();

                $this->setApplicationStatus($applicationId, 'accepted');
                $query = "UPDATE alliance_applications
                          SET status = 'cancelled', resolved_at = NOW()
                          WHERE user_id = ? AND status = 'pending' AND application_id <> ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $applicantId, $applicationId);
                $stmt->execute();
                $stmt->close();
                $this->recordGameplayEvent($applicantId, 'alliance_joined', $allianceId);
            } else {
                $this->setApplicationStatus($applicationId, 'rejected');
            }

            $this->db->commit();
            return $this->success($decision === 'accepted' ? '申请已批准。' : '申请已拒绝。');
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Alliance application resolution failed: ' . $exception->getMessage());
            return $this->failure('申请审核失败，请稍后再试。');
        }
    }

    /**
     * 离开联盟；唯一盟主离开时解散联盟 / Leave an alliance; dissolve it when the leader is alone
     * @return array 操作结果 / Operation result
     */
    public function leaveAlliance($userId) {
        $userId = (int) $userId;
        $this->db->begin_transaction();
        try {
            // 与战斗势力链复核共享用户→附属→盟籍锁序，盟主移交不能绕过用户锁。 / Share the user-to-vassal-to-membership order with battle force-chain validation so leadership transfer cannot bypass the user lock.
            $this->lockUserRows([$userId]);
            $this->lockRelationsForUsers([$userId]);
            $membership = $this->getMembershipForUpdate($userId);
            if ($membership === null) {
                $this->db->rollback();
                return $this->failure('你尚未加入联盟。');
            }

            $allianceId = (int) $membership['alliance_id'];
            if ($membership['role'] === 'leader') {
                $query = "SELECT COUNT(*) AS member_count
                          FROM alliance_members
                          WHERE alliance_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $allianceId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : ['member_count' => 0];
                $stmt->close();

                if ((int) $row['member_count'] === 1) {
                    $query = "DELETE FROM alliances WHERE alliance_id = ? AND leader_id = ?";
                    $stmt = $this->db->prepare($query);
                    $stmt->bind_param('ii', $allianceId, $userId);
                    $success = $stmt->execute() && $stmt->affected_rows === 1;
                    $stmt->close();
                    if (!$success) {
                        $this->db->rollback();
                        return $this->failure('无法解散联盟。');
                    }
                    $this->db->commit();
                    return $this->success('联盟已解散。');
                }

                // 优先将盟主职位移交给最早加入的干部 / Prefer the longest-serving officer as successor
                $query = "SELECT user_id
                          FROM alliance_members
                          WHERE alliance_id = ? AND user_id <> ?
                          ORDER BY FIELD(role, 'officer', 'member'), joined_at, member_id
                          LIMIT 1
                          FOR UPDATE";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $allianceId, $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $successor = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                if (!$successor) {
                    $this->db->rollback();
                    return $this->failure('无法选择新的盟主。');
                }

                $successorId = (int) $successor['user_id'];
                $query = "UPDATE alliances SET leader_id = ?
                          WHERE alliance_id = ? AND leader_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('iii', $successorId, $allianceId, $userId);
                $success = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$success) {
                    $this->db->rollback();
                    return $this->failure('无法移交盟主职位。');
                }

                $query = "UPDATE alliance_members SET role = 'leader'
                          WHERE alliance_id = ? AND user_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $allianceId, $successorId);
                $success = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$success) {
                    $this->db->rollback();
                    return $this->failure('无法更新新盟主的身份。');
                }

                $query = "DELETE FROM alliance_members WHERE alliance_id = ? AND user_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $allianceId, $userId);
                $success = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$success) {
                    $this->db->rollback();
                    return $this->failure('无法离开联盟。');
                }
                $this->db->commit();
                return $this->success('你已离开联盟，盟主职位已自动移交。');
            }

            $query = "DELETE FROM alliance_members WHERE alliance_id = ? AND user_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $allianceId, $userId);
            $success = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$success) {
                $this->db->rollback();
                return $this->failure('无法离开联盟。');
            }

            $this->db->commit();
            return $this->success('你已离开联盟。');
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Alliance leave failed: ' . $exception->getMessage());
            return $this->failure('离开联盟失败，请稍后再试。');
        }
    }

    /**
     * 由盟主任命或撤销干部 / Let the leader appoint or demote an officer
     * @return array 操作结果 / Operation result
     */
    public function setMemberRole($actorId, $memberUserId, $role) {
        $memberUserId = (int) $memberUserId;
        $role = (string) $role;
        if ($memberUserId <= 0 || !in_array($role, ['officer', 'member'], true)) {
            return $this->failure('成员或身份无效。');
        }
        if ($memberUserId === (int) $actorId) {
            return $this->failure('盟主不能通过该操作更改自己的身份。');
        }

        $this->db->begin_transaction();
        try {
            $actorMembership = $this->getMembershipForUpdate($actorId);
            $targetMembership = $this->getMembershipForUpdate($memberUserId);
            if ($actorMembership === null || $actorMembership['role'] !== 'leader') {
                $this->db->rollback();
                return $this->failure('只有盟主可以调整成员身份。');
            }
            if ($targetMembership === null
                || (int) $targetMembership['alliance_id'] !== (int) $actorMembership['alliance_id']) {
                $this->db->rollback();
                return $this->failure('目标用户不是本联盟成员。');
            }

            $query = "UPDATE alliance_members SET role = ?
                      WHERE alliance_id = ? AND user_id = ? AND role <> 'leader'";
            $stmt = $this->db->prepare($query);
            $allianceId = (int) $actorMembership['alliance_id'];
            $stmt->bind_param('sii', $role, $allianceId, $memberUserId);
            $success = $stmt->execute();
            $stmt->close();
            if (!$success) {
                $this->db->rollback();
                return $this->failure('无法更新成员身份。');
            }

            $this->db->commit();
            return $this->success($role === 'officer' ? '该成员已被任命为干部。' : '该成员已恢复为普通成员。');
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Alliance role update failed: ' . $exception->getMessage());
            return $this->failure('成员身份更新失败，请稍后再试。');
        }
    }

    /**
     * 向同盟成员转移资源 / Transfer resources to a member of the same alliance
     * @return array 操作结果 / Operation result
     */
    public function sendAid($senderId, $receiverId, $resourceType, $amount) {
        $receiverId = (int) $receiverId;
        $amount = (int) $amount;
        $resourceType = (string) $resourceType;
        if ($receiverId <= 0 || $receiverId === (int) $senderId) {
            return $this->failure('请选择另一名联盟成员。');
        }
        if (!isset(self::RESOURCE_COLUMNS[$resourceType])) {
            return $this->failure('资源类型无效。');
        }
        if ($amount <= 0 || $amount > 1000000) {
            return $this->failure('单次援助数量须为 1 至 1,000,000。');
        }

        $column = self::RESOURCE_COLUMNS[$resourceType];
        $this->db->begin_transaction();
        try {
            // 按用户 ID 顺序加锁以降低互助交易死锁风险 / Lock by user ID to reduce aid-transfer deadlocks
            $firstUserId = min((int) $senderId, $receiverId);
            $secondUserId = max((int) $senderId, $receiverId);
            $firstMembership = $this->getMembershipForUpdate($firstUserId);
            $secondMembership = $this->getMembershipForUpdate($secondUserId);
            $membershipByUser = [
                $firstUserId => $firstMembership,
                $secondUserId => $secondMembership
            ];
            $senderMembership = $membershipByUser[(int) $senderId];
            $receiverMembership = $membershipByUser[$receiverId];
            if ($senderMembership === null || $receiverMembership === null
                || (int) $senderMembership['alliance_id'] !== (int) $receiverMembership['alliance_id']) {
                $this->db->rollback();
                return $this->failure('资源援助只能发送给同一联盟的成员。');
            }

            // 每日总量封顶，并按成员对的净流向结算贡献 / Cap daily aid and score contribution from the net flow between this member pair
            $query = "SELECT
                        COALESCE(SUM(
                          CASE
                            WHEN sender_id = ? AND receiver_id = ? THEN amount
                            ELSE 0
                          END
                        ), 0) AS pair_sent_today,
                        COALESCE(SUM(
                          CASE
                            WHEN sender_id = ? AND receiver_id = ? THEN amount
                            ELSE 0
                          END
                        ), 0) AS pair_received_today,
                        COALESCE(SUM(
                          CASE WHEN sender_id = ? THEN amount ELSE 0 END
                        ), 0) AS sent_today
                      FROM alliance_aid_log
                      WHERE alliance_id = ?
                        AND (
                          sender_id IN (?, ?)
                          OR receiver_id IN (?, ?)
                        )
                        AND created_at >= CURDATE()
                        AND created_at < DATE_ADD(
                          CURDATE(),
                          INTERVAL 1 DAY
                        )";
            $stmt = $this->db->prepare($query);
            $allianceId = (int) $senderMembership['alliance_id'];
            $stmt->bind_param(
                'iiiiiiiiii',
                $senderId,
                $receiverId,
                $receiverId,
                $senderId,
                $senderId,
                $allianceId,
                $senderId,
                $receiverId,
                $senderId,
                $receiverId
            );
            $stmt->execute();
            $result = $stmt->get_result();
            $aidRow = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            $sentToday = $aidRow ? (int) $aidRow['sent_today'] : 0;
            if ($sentToday + $amount > self::DAILY_AID_LIMIT) {
                $this->db->rollback();
                return $this->failure('今日资源援助总量已达到 1,000,000。');
            }

            // 返还援助先抵销原发送者贡献，只有超过反向援助的净额才产生新贡献 / Returned aid first cancels the original sender's score; only excess net aid earns new score
            $pairSentToday = $aidRow ? (int) $aidRow['pair_sent_today'] : 0;
            $pairReceivedToday = $aidRow ? (int) $aidRow['pair_received_today'] : 0;
            $priorPairNet = $pairSentToday - $pairReceivedToday;
            $contributionClawback = $priorPairNet < 0
                ? min($amount, -$priorPairNet)
                : 0;
            $contributionAward = max(0, $amount + min(0, $priorPairNet));

            $query = "SELECT user_id, {$column} AS amount
                      FROM resources
                      WHERE user_id IN (?, ?)
                      ORDER BY user_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $firstUserId, $secondUserId);
            $stmt->execute();
            $result = $stmt->get_result();
            $resourceRows = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $resourceRows[(int) $row['user_id']] = (int) $row['amount'];
            }
            $stmt->close();

            if (!isset($resourceRows[(int) $senderId], $resourceRows[$receiverId])) {
                $this->db->rollback();
                return $this->failure('发送者或接收者的资源记录不存在。');
            }
            if ($resourceRows[(int) $senderId] < $amount) {
                $this->db->rollback();
                return $this->failure('可用资源不足。');
            }

            $query = "UPDATE resources
                      SET {$column} = {$column} - ?
                      WHERE user_id = ? AND {$column} >= ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iii', $amount, $senderId, $amount);
            $success = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$success) {
                $this->db->rollback();
                return $this->failure('资源余额已经变化，请重试。');
            }

            $query = "UPDATE resources
                      SET {$column} = {$column} + ?
                      WHERE user_id = ? AND {$column} <= 2147483647 - ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iii', $amount, $receiverId, $amount);
            $success = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$success) {
                $this->db->rollback();
                return $this->failure('接收者资源更新失败。');
            }

            $query = "INSERT INTO alliance_aid_log
                         (alliance_id, sender_id, receiver_id, resource_type, amount)
                      VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iiisi', $allianceId, $senderId, $receiverId, $resourceType, $amount);
            $success = $stmt->execute();
            $stmt->close();
            if (!$success) {
                $this->db->rollback();
                return $this->failure('无法记录本次援助。');
            }

            if ($contributionAward > 0) {
                $query = "UPDATE alliance_members
                          SET contribution = contribution + ?
                          WHERE alliance_id = ? AND user_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('iii', $contributionAward, $allianceId, $senderId);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    $stmt->close();
                    $this->db->rollback();
                    return $this->failure('无法结算援助贡献。');
                }
                $stmt->close();
            }

            if ($contributionClawback > 0) {
                $query = "UPDATE alliance_members
                          SET contribution = GREATEST(0, contribution - ?)
                          WHERE alliance_id = ? AND user_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('iii', $contributionClawback, $allianceId, $receiverId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    $this->db->rollback();
                    return $this->failure('无法抵销返还援助的贡献。');
                }
                $stmt->close();
            }

            $this->db->commit();
            return $this->success(
                $contributionClawback > 0
                    ? '资源援助已送达；同一成员对只按当日净援助计算贡献。'
                    : '资源援助已送达。'
            );
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Alliance aid failed: ' . $exception->getMessage());
            return $this->failure('资源援助失败，请稍后再试。');
        }
    }

    /**
     * 建立联盟协同作战 / Create an alliance cooperative operation
     * @return array 操作结果 / Operation result
     */
    public function createOperation(
        $userId,
        $title,
        $targetType,
        $targetId,
        $targetX,
        $targetY,
        $launchAt
    ) {
        if (isSeasonGameplayFrozen()) {
            return $this->failure(getSeasonGameplayFreezeMessage());
        }

        $title = trim((string) $title);
        $targetType = (string) $targetType;
        $targetId = (int) $targetId;
        $launchTimestamp = strtotime((string) $launchAt);
        $mapWidth = defined('MAP_WIDTH') ? (int) MAP_WIDTH : 512;
        $mapHeight = defined('MAP_HEIGHT') ? (int) MAP_HEIGHT : 512;

        if ($this->textLength($title) < 2 || $this->textLength($title) > 100) {
            return $this->failure('行动标题须为 2 至 100 个字符。');
        }
        if (!in_array($targetType, ['tile', 'city', 'army'], true) || $targetId <= 0) {
            return $this->failure('行动目标无效。');
        }
        if ($launchTimestamp === false || $launchTimestamp <= time() || $launchTimestamp > time() + 604800) {
            return $this->failure('出发时间须在未来七天之内。');
        }

        $this->db->begin_transaction();
        try {
            // 事务内赛季锁是冻结切换与行动写入的最终边界 / The in-transaction season lock is authoritative against freeze transitions
            lockSeasonForWorldAction($this->db);

            $membership = $this->getMembershipForUpdate($userId);
            if ($membership === null) {
                $this->db->rollback();
                return $this->failure('只有联盟成员可以建立协同作战。');
            }
            if (!$this->canDispatchToOperationTarget(
                ['target_type' => $targetType, 'target_id' => $targetId],
                (int) $userId
            )) {
                $this->db->rollback();
                return $this->failure(
                    '目标不存在、属于友方，或必须通过其他玩法进攻。'
                );
            }

            // 坐标只能由目标实体派生，不能信任客户端展示值 / Derive coordinates from the target entity and never trust client display values
            $targetCoordinates = $this->getOperationTargetCoordinates(
                $targetType,
                $targetId
            );
            if ($targetCoordinates === null) {
                $this->db->rollback();
                return $this->failure('无法读取行动目标坐标。');
            }
            $targetX = (int) $targetCoordinates[0];
            $targetY = (int) $targetCoordinates[1];
            if ($targetX < 0
                || $targetX >= $mapWidth
                || $targetY < 0
                || $targetY >= $mapHeight) {
                $this->db->rollback();
                return $this->failure('目标坐标超出地图范围。');
            }

            $launchDate = date('Y-m-d H:i:s', $launchTimestamp);
            $allianceId = (int) $membership['alliance_id'];
            $query = "INSERT INTO alliance_operations
                         (alliance_id, creator_id, title, target_type, target_id,
                          target_x, target_y, launch_at, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iissiiis',
                $allianceId,
                $userId,
                $title,
                $targetType,
                $targetId,
                $targetX,
                $targetY,
                $launchDate
            );
            $success = $stmt->execute();
            $operationId = $success ? (int) $this->db->insert_id : 0;
            $stmt->close();
            if (!$success) {
                throw new RuntimeException(
                    '无法写入协同作战 / Failed to insert alliance operation'
                );
            }

            $this->db->commit();
            return $this->success(
                '协同作战已经建立。',
                ['operation_id' => $operationId]
            );
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log(
                'Alliance operation creation failed: '
                . $exception->getMessage()
            );
            return $this->failure(
                isSeasonGameplayFrozen()
                    ? getSeasonGameplayFreezeMessage()
                    : '无法建立协同作战。'
            );
        }
    }

    /**
     * 使用自己空闲的军队加入协同作战 / Join an operation with an owned idle army
     * @return array 操作结果 / Operation result
     */
    public function joinOperation($userId, $operationId, $armyId) {
        if (isSeasonGameplayFrozen()) {
            return $this->failure(getSeasonGameplayFreezeMessage());
        }

        $operationId = (int) $operationId;
        $armyId = (int) $armyId;
        if ($operationId <= 0 || $armyId <= 0) {
            return $this->failure('行动或军队无效。');
        }

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            $membership = $this->getMembershipForUpdate($userId);
            if ($membership === null) {
                $this->db->rollback();
                return $this->failure('只有联盟成员可以加入协同作战。');
            }

            $query = "SELECT operation_id, alliance_id, status, launch_at
                      FROM alliance_operations
                      WHERE operation_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $operationId);
            $stmt->execute();
            $result = $stmt->get_result();
            $operation = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$operation || (int) $operation['alliance_id'] !== (int) $membership['alliance_id']) {
                $this->db->rollback();
                return $this->failure('行动不存在或不属于你的联盟。');
            }
            if ($operation['status'] !== 'open' || strtotime($operation['launch_at']) <= time()) {
                $this->db->rollback();
                return $this->failure('该行动已经关闭报名。');
            }

            $query = "SELECT army_id, owner_id, status
                      FROM armies
                      WHERE army_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $armyId);
            $stmt->execute();
            $result = $stmt->get_result();
            $army = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$army || (int) $army['owner_id'] !== (int) $userId || $army['status'] !== 'idle') {
                $this->db->rollback();
                return $this->failure('只能派遣自己拥有的空闲军队。');
            }

            $query = "INSERT INTO alliance_operation_armies (operation_id, army_id, user_id)
                      VALUES (?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iii', $operationId, $armyId, $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                $this->db->rollback();
                return $this->failure('该军队已经参加其他协同作战。');
            }
            $stmt->close();

            $this->db->commit();
            return $this->success('军队已加入协同作战。');
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Alliance operation join failed: ' . $exception->getMessage());
            return $this->failure(
                isSeasonGameplayFrozen()
                    ? getSeasonGameplayFreezeMessage()
                    : '加入协同作战失败，请稍后再试。'
            );
        }
    }

    /**
     * 获取成员可派遣的空闲军队 / Get a member's eligible idle armies
     * @return array 军队列表 / Army list
     */
    public function getEligibleArmies($userId) {
        $this->releaseClosedOperationArmies();
        $query = "SELECT a.army_id, a.name, a.current_x, a.current_y,
                         COALESCE(SUM(au.quantity), 0) AS unit_count
                  FROM armies a
                  LEFT JOIN army_units au ON au.army_id = a.army_id
                  LEFT JOIN alliance_operation_armies aoa ON aoa.army_id = a.army_id
                  WHERE a.owner_id = ? AND a.status = 'idle' AND aoa.army_id IS NULL
                  GROUP BY a.army_id, a.name, a.current_x, a.current_y
                  ORDER BY a.army_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $armies = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $armies[] = $row;
        }
        $stmt->close();

        return $armies;
    }

    /**
     * 派遣到达集合时间的联盟行动 / Dispatch alliance operations whose launch time has arrived
     * @return array 处理汇总 / Processing summary
     */
    public function processDueOperations() {
        if (isSeasonGameplayFrozen()) {
            return $this->success(
                '赛季结算冻结期间暂停派遣协同作战。 / Cooperative dispatch is paused during season settlement.',
                ['processed' => 0, 'dispatched' => 0]
            );
        }

        $this->releaseClosedOperationArmies();
        $query = "SELECT operation_id
                  FROM alliance_operations
                  WHERE status = 'launched'
                     OR (status = 'open' AND launch_at <= NOW())
                  ORDER BY launch_at, operation_id
                  LIMIT 50";
        $result = executePreparedSql($this->db, $query);
        if (!$result) {
            return $this->failure('无法读取到期协同作战。');
        }

        $operationIds = [];
        while ($row = $result->fetch_assoc()) {
            $operationIds[] = (int) $row['operation_id'];
        }

        $processed = 0;
        $dispatched = 0;
        foreach ($operationIds as $operationId) {
            $summary = $this->dispatchDueOperation($operationId);
            if (!$summary['processed']) {
                continue;
            }
            $processed++;
            $dispatched += (int) $summary['dispatched'];
        }

        return $this->success(
            '到期协同作战已处理。',
            [
                'processed' => $processed,
                'dispatched' => $dispatched
            ]
        );
    }

    /**
     * 领取一个行动并派遣仍有效的参战军队 / Claim one operation and dispatch its still-valid armies
     * @param int $operationId 行动ID / Operation ID
     * @return array 处理结果 / Processing result
     */
    private function dispatchDueOperation($operationId) {
        $participants = [];
        $operation = null;
        $this->db->begin_transaction();

        try {
            lockSeasonForWorldAction($this->db);

            $query = "SELECT operation_id, alliance_id, target_type,
                             target_id, launch_at, status,
                             launch_at <= NOW() AS launch_due
                      FROM alliance_operations
                      WHERE operation_id = ?
                        AND status IN ('open', 'launched')
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $operationId);
            $stmt->execute();
            $result = $stmt->get_result();
            $operation = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$operation
                || ($operation['status'] === 'open'
                    && (int) $operation['launch_due'] !== 1)) {
                $this->db->rollback();
                return ['processed' => false, 'dispatched' => 0];
            }

            if ($operation['status'] === 'open') {
                $query = "UPDATE alliance_operations
                          SET status = 'launched'
                          WHERE operation_id = ? AND status = 'open'";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('i', $operationId);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException('无法启动协同作战。');
                }
                $stmt->close();
            }

            // 只领取仍属于该联盟且仍拥有军队的报名 / Claim only current alliance members who still own the army
            $query = "SELECT aoa.army_id, aoa.user_id
                      FROM alliance_operation_armies aoa
                      INNER JOIN alliance_members am
                        ON am.user_id = aoa.user_id
                       AND am.alliance_id = ?
                      INNER JOIN armies a
                        ON a.army_id = aoa.army_id
                       AND a.owner_id = aoa.user_id
                      WHERE aoa.operation_id = ?
                      ORDER BY aoa.army_id";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'ii',
                $operation['alliance_id'],
                $operationId
            );
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $participants[] = $row;
            }
            $stmt->close();
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log(
                'Alliance operation launch failed: '
                . $exception->getMessage()
            );
            return ['processed' => false, 'dispatched' => 0];
        }

        $dispatched = 0;
        foreach ($participants as $participant) {
            $userId = (int) $participant['user_id'];
            if (!$this->canDispatchToOperationTarget($operation, $userId)) {
                continue;
            }
            $army = new Army((int) $participant['army_id']);
            if (!$army->isValid()
                || (int) $army->getOwnerId() !== $userId
                || $army->getStatus() !== 'idle'
                || $army->getCombatPower() <= 0) {
                continue;
            }
            if ($army->attackTarget(
                $operation['target_type'],
                (int) $operation['target_id']
            )) {
                $dispatched++;
            }
        }

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            $query = "DELETE FROM alliance_operation_armies
                      WHERE operation_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $operationId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法释放协同作战军队。');
            }
            $stmt->close();

            $query = "UPDATE alliance_operations
                      SET status = 'completed'
                      WHERE operation_id = ? AND status = 'launched'";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $operationId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法完成协同作战。');
            }
            $stmt->close();
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log(
                'Alliance operation cleanup failed: '
                . $exception->getMessage()
            );
            return ['processed' => false, 'dispatched' => $dispatched];
        }

        return ['processed' => true, 'dispatched' => $dispatched];
    }

    /**
     * 校验行动目标仍可由该成员攻击 / Validate that a member may still attack an operation target
     * @param array $operation 行动数据 / Operation row
     * @param int $userId 玩家ID / User ID
     * @return bool 是否允许派遣 / Whether dispatch is allowed
     */
    private function canDispatchToOperationTarget($operation, $userId) {
        $targetType = (string) $operation['target_type'];
        $targetId = (int) $operation['target_id'];
        $ownerId = null;

        if ($targetType === 'tile') {
            $query = "SELECT tile.owner_id, tile.type, site.site_id
                      FROM map_tiles tile
                      LEFT JOIN world_sites site
                        ON site.tile_id = tile.tile_id
                      WHERE tile.tile_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $result = $stmt->get_result();
            $target = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$target
                || $target['site_id'] !== null
                || !in_array($target['type'], ['resource', 'npc_fort'], true)
                || ($target['type'] === 'resource'
                    && $target['owner_id'] === null)) {
                return false;
            }
            $ownerId = $target['owner_id'];
        } elseif ($targetType === 'city') {
            $query = "SELECT owner_id FROM cities WHERE city_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $result = $stmt->get_result();
            $target = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$target) {
                return false;
            }
            $ownerId = $target['owner_id'];
        } elseif ($targetType === 'army') {
            $query = "SELECT owner_id, status FROM armies WHERE army_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $result = $stmt->get_result();
            $target = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$target || $target['status'] !== 'idle') {
                return false;
            }
            $ownerId = $target['owner_id'];
        } else {
            return false;
        }

        return $ownerId === null
            || $this->canUsersFight($userId, (int) $ownerId);
    }

    /**
     * 从目标实体读取权威坐标 / Read authoritative coordinates from a target entity
     * @param string $targetType 目标类型 / Target type
     * @param int $targetId 目标ID / Target ID
     * @return array|null 坐标或空值 / Coordinates or null
     */
    private function getOperationTargetCoordinates($targetType, $targetId) {
        if ($targetType === 'tile') {
            $query = "SELECT x, y FROM map_tiles WHERE tile_id = ?";
        } elseif ($targetType === 'city') {
            $query = "SELECT x, y FROM cities WHERE city_id = ?";
        } elseif ($targetType === 'army') {
            $query = "SELECT current_x AS x, current_y AS y
                      FROM armies WHERE army_id = ?";
        } else {
            return null;
        }

        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $target = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $target
            ? [(int) $target['x'], (int) $target['y']]
            : null;
    }

    /**
     * 释放已关闭行动残留的军队报名 / Release stale army registrations from closed operations
     * @return void
     */
    private function releaseClosedOperationArmies() {
        $query = "DELETE registered
                  FROM alliance_operation_armies registered
                  INNER JOIN alliance_operations ao
                    ON ao.operation_id = registered.operation_id
                  WHERE ao.status IN ('completed', 'cancelled')";
        if (!executePreparedSql($this->db, $query)) {
            error_log('Failed to release closed alliance-operation armies');
        }
    }

    /**
     * 获取联盟页面所需的受保护详情 / Get protected alliance overview data
     * @return array|null 联盟详情或空值 / Alliance overview or null
     */
    public function getAllianceOverview($userId) {
        $membership = $this->getMembership($userId);
        if ($membership === null) {
            return null;
        }
        $allianceId = (int) $membership['alliance_id'];

        $query = "SELECT a.alliance_id, a.name, a.tag, a.description, a.level,
                         a.experience, a.created_at, u.username AS leader_name
                  FROM alliances a
                  LEFT JOIN users u ON u.user_id = a.leader_id
                  WHERE a.alliance_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $allianceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $alliance = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$alliance) {
            return null;
        }

        $query = "SELECT am.user_id, am.role, am.contribution, am.joined_at, u.username
                  FROM alliance_members am
                  INNER JOIN users u ON u.user_id = am.user_id
                  WHERE am.alliance_id = ?
                  ORDER BY FIELD(am.role, 'leader', 'officer', 'member'), am.contribution DESC";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $allianceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $members = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $members[] = $row;
        }
        $stmt->close();

        $applications = [];
        if (in_array($membership['role'], ['leader', 'officer'], true)) {
            $query = "SELECT aa.application_id, aa.user_id, aa.message, aa.created_at, u.username
                      FROM alliance_applications aa
                      INNER JOIN users u ON u.user_id = aa.user_id
                      WHERE aa.alliance_id = ? AND aa.status = 'pending'
                      ORDER BY aa.created_at";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $allianceId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $applications[] = $row;
            }
            $stmt->close();
        }

        $query = "SELECT ao.operation_id, ao.creator_id, ao.title, ao.target_type,
                         ao.target_id, ao.target_x, ao.target_y, ao.launch_at,
                         ao.status, u.username AS creator_name,
                         COUNT(aoa.army_id) AS army_count
                  FROM alliance_operations ao
                  INNER JOIN users u ON u.user_id = ao.creator_id
                  LEFT JOIN alliance_operation_armies aoa ON aoa.operation_id = ao.operation_id
                  WHERE ao.alliance_id = ?
                  GROUP BY ao.operation_id, ao.creator_id, ao.title, ao.target_type,
                           ao.target_id, ao.target_x, ao.target_y, ao.launch_at,
                           ao.status, u.username
                  ORDER BY FIELD(ao.status, 'open', 'launched', 'completed', 'cancelled'),
                           ao.launch_at DESC
                  LIMIT 30";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $allianceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $operations = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $operations[] = $row;
        }
        $stmt->close();

        return [
            'membership' => $membership,
            'alliance' => $alliance,
            'members' => $members,
            'applications' => $applications,
            'operations' => $operations
        ];
    }

    /**
     * 判断两名玩家是否属于同一联盟 / Determine whether two users share an alliance
     */
    public function areUsersAllied($firstUserId, $secondUserId) {
        $firstUserId = (int) $firstUserId;
        $secondUserId = (int) $secondUserId;
        if ($firstUserId <= 0 || $secondUserId <= 0) {
            return false;
        }
        if ($firstUserId === $secondUserId) {
            return true;
        }

        $query = "SELECT 1
                  FROM alliance_members first_member
                  INNER JOIN alliance_members second_member
                     ON second_member.alliance_id = first_member.alliance_id
                  WHERE first_member.user_id = ? AND second_member.user_id = ?
                  LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $firstUserId, $secondUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        $allied = $result && $result->num_rows > 0;
        $stmt->close();

        return $allied;
    }

    /**
     * 判断两名玩家之间是否允许敌对行为 / Determine whether hostility is allowed between two users
     */
    public function canUsersFight($attackerId, $defenderId) {
        // 附属关系覆盖普通联盟关系，所有世界敌友判断都使用有效势力。 / Vassalage overrides ordinary alliance membership, so all world hostility uses effective forces.
        $vassalService = new VassalService();
        return $vassalService->canUsersFight($attackerId, $defenderId);
    }

    /**
     * 按玩家ID顺序锁定联盟身份变更参与者 / Lock alliance-membership participants in user-ID order
     * @param array $userIds 玩家ID / User IDs
     * @return void
     */
    private function lockUserRows($userIds) {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        sort($userIds, SORT_NUMERIC);
        foreach ($userIds as $userId) {
            if ($userId <= 0) {
                throw new InvalidArgumentException(
                    '联盟玩家参数无效 / Invalid alliance user'
                );
            }
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
                    '联盟玩家已经不存在 / Alliance user no longer exists'
                );
            }
        }
    }

    /**
     * 锁定并读取成员身份 / Lock and read a membership row
     */
    private function getMembershipForUpdate($userId) {
        $query = "SELECT member_id, alliance_id, user_id, role, contribution
                  FROM alliance_members
                  WHERE user_id = ?
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
     * 更新申请状态 / Update an application status
     */
    private function setApplicationStatus($applicationId, $status) {
        $query = "UPDATE alliance_applications
                  SET status = ?, resolved_at = NOW()
                  WHERE application_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('si', $status, $applicationId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * 检查联盟是否存在 / Check whether an alliance exists
     */
    private function allianceExists($allianceId) {
        $query = "SELECT alliance_id FROM alliances WHERE alliance_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $allianceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * 记录联盟相关玩法事件 / Record an alliance gameplay event
     */
    private function recordGameplayEvent($userId, $eventType, $referenceId) {
        $query = "INSERT INTO gameplay_events
                     (user_id, event_type, event_value, reference_type, reference_id)
                  VALUES (?, ?, 1, 'alliance', ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isi', $userId, $eventType, $referenceId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * 获取 UTF-8 文本长度 / Get UTF-8 text length
     */
    private function textLength($text) {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * 构造成功结果 / Build a successful result
     */
    private function success($message, $data = []) {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }

    /**
     * 构造失败结果 / Build a failed result
     */
    private function failure($message) {
        return ['success' => false, 'message' => $message, 'data' => []];
    }
}

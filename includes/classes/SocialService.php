<?php
// 种火集结号 - 邮件、聊天与好友服务 / Fireseed Engage - Mail, chat, and friendship service

class SocialService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 向指定用户名发送站内邮件 / Send in-game mail to a username
     * @return array 操作结果 / Operation result
     */
    public function sendMail($senderId, $receiverUsername, $subject, $body) {
        $receiverUsername = trim((string) $receiverUsername);
        $subject = trim((string) $subject);
        $body = trim((string) $body);

        if ($receiverUsername === '' || $this->textLength($receiverUsername) > 50) {
            return $this->failure('收件人用户名无效。');
        }
        if ($this->textLength($subject) < 1 || $this->textLength($subject) > 120) {
            return $this->failure('邮件主题须为 1 至 120 个字符。');
        }
        if ($this->textLength($body) < 1 || $this->textLength($body) > 5000) {
            return $this->failure('邮件正文须为 1 至 5,000 个字符。');
        }

        $receiverId = $this->findUserIdByUsername($receiverUsername);
        if ($receiverId === null) {
            return $this->failure('收件人不存在。');
        }
        if ($receiverId === (int) $senderId) {
            return $this->failure('不能给自己发送邮件。');
        }

        $query = "INSERT INTO messages
                     (sender_id, receiver_id, subject, body, message_type)
                  VALUES (?, ?, ?, ?, 'player')";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iiss', $senderId, $receiverId, $subject, $body);
        $success = $stmt->execute();
        $messageId = $success ? (int) $this->db->insert_id : 0;
        $stmt->close();

        return $success
            ? $this->success('邮件已发送。', ['message_id' => $messageId])
            : $this->failure('邮件发送失败。');
    }

    /**
     * 获取收件箱 / Get a user's inbox
     * @return array 邮件列表 / Message list
     */
    public function getInbox($userId, $limit = 50) {
        $limit = max(1, min(100, (int) $limit));
        $query = "SELECT m.message_id, m.sender_id, m.receiver_id, m.subject, m.body,
                         m.message_type, m.is_read, m.sent_at,
                         COALESCE(u.username, '系统') AS sender_name
                  FROM messages m
                  LEFT JOIN users u ON u.user_id = m.sender_id
                  WHERE m.receiver_id = ?
                  ORDER BY m.sent_at DESC, m.message_id DESC
                  LIMIT ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $messages[] = $row;
        }
        $stmt->close();

        return $messages;
    }

    /**
     * 获取已发送邮件 / Get a user's sent mail
     * @return array 邮件列表 / Message list
     */
    public function getSentMail($userId, $limit = 30) {
        $limit = max(1, min(100, (int) $limit));
        $query = "SELECT m.message_id, m.receiver_id, m.subject, m.body,
                         m.message_type, m.is_read, m.sent_at, u.username AS receiver_name
                  FROM messages m
                  INNER JOIN users u ON u.user_id = m.receiver_id
                  WHERE m.sender_id = ?
                  ORDER BY m.sent_at DESC, m.message_id DESC
                  LIMIT ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $messages[] = $row;
        }
        $stmt->close();

        return $messages;
    }

    /**
     * 读取一封属于当前收件人的邮件并标记已读 / Read owned mail and mark it as read
     * @return array|null 邮件或空值 / Message or null
     */
    public function readMail($userId, $messageId) {
        $messageId = (int) $messageId;
        if ($messageId <= 0) {
            return null;
        }

        $this->db->begin_transaction();
        try {
            $query = "SELECT m.message_id, m.sender_id, m.receiver_id, m.subject, m.body,
                             m.message_type, m.is_read, m.sent_at,
                             COALESCE(u.username, '系统') AS sender_name
                      FROM messages m
                      LEFT JOIN users u ON u.user_id = m.sender_id
                      WHERE m.message_id = ? AND m.receiver_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $messageId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $message = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$message) {
                $this->db->rollback();
                return null;
            }

            if ((int) $message['is_read'] === 0) {
                $query = "UPDATE messages SET is_read = 1
                          WHERE message_id = ? AND receiver_id = ?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $messageId, $userId);
                $stmt->execute();
                $stmt->close();
                $message['is_read'] = 1;
            }
            $this->db->commit();

            return $message;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Mail read failed: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * 向世界或联盟频道发送聊天消息 / Send a message to world or alliance chat
     * @return array 操作结果 / Operation result
     */
    public function sendChatMessage($senderId, $channelType, $body) {
        $channelType = (string) $channelType;
        $body = trim((string) $body);
        if (!in_array($channelType, ['world', 'alliance'], true)) {
            return $this->failure('聊天频道无效。');
        }
        if ($this->textLength($body) < 1 || $this->textLength($body) > 500) {
            return $this->failure('聊天内容须为 1 至 500 个字符。');
        }

        $channelId = null;
        if ($channelType === 'alliance') {
            $channelId = $this->getAllianceId($senderId);
            if ($channelId === null) {
                return $this->failure('只有联盟成员可以使用联盟频道。');
            }
        }

        $query = "INSERT INTO chat_messages
                     (sender_id, channel_type, channel_id, body)
                  VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('isis', $senderId, $channelType, $channelId, $body);
        $success = $stmt->execute();
        $chatId = $success ? (int) $this->db->insert_id : 0;
        $stmt->close();

        return $success
            ? $this->success('消息已发送。', ['chat_id' => $chatId])
            : $this->failure('聊天消息发送失败。');
    }

    /**
     * 获取世界或当前联盟的聊天记录 / Get world or current-alliance chat history
     * @return array 聊天记录 / Chat history
     */
    public function getChatMessages($userId, $channelType, $limit = 50) {
        $channelType = (string) $channelType;
        $limit = max(1, min(100, (int) $limit));
        if ($channelType === 'world') {
            $query = "SELECT cm.chat_id, cm.sender_id, cm.body, cm.sent_at,
                             COALESCE(u.username, '系统') AS sender_name
                      FROM chat_messages cm
                      LEFT JOIN users u ON u.user_id = cm.sender_id
                      WHERE cm.channel_type = 'world' AND cm.channel_id IS NULL
                      ORDER BY cm.sent_at DESC, cm.chat_id DESC
                      LIMIT ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $limit);
        } elseif ($channelType === 'alliance') {
            $allianceId = $this->getAllianceId($userId);
            if ($allianceId === null) {
                return [];
            }
            $query = "SELECT cm.chat_id, cm.sender_id, cm.body, cm.sent_at,
                             COALESCE(u.username, '系统') AS sender_name
                      FROM chat_messages cm
                      LEFT JOIN users u ON u.user_id = cm.sender_id
                      WHERE cm.channel_type = 'alliance' AND cm.channel_id = ?
                      ORDER BY cm.sent_at DESC, cm.chat_id DESC
                      LIMIT ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $allianceId, $limit);
        } else {
            return [];
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $messages[] = $row;
        }
        $stmt->close();

        return array_reverse($messages);
    }

    /**
     * 发送好友申请 / Send a friend request
     * @return array 操作结果 / Operation result
     */
    public function sendFriendRequest($requesterId, $addresseeUsername) {
        $addresseeUsername = trim((string) $addresseeUsername);
        if ($addresseeUsername === '' || $this->textLength($addresseeUsername) > 50) {
            return $this->failure('用户名无效。');
        }

        $addresseeId = $this->findUserIdByUsername($addresseeUsername);
        if ($addresseeId === null) {
            return $this->failure('目标用户不存在。');
        }
        if ($addresseeId === (int) $requesterId) {
            return $this->failure('不能向自己发送好友申请。');
        }

        $this->db->begin_transaction();
        try {
            // 按用户 ID 顺序锁定双方，防止双向申请竞态 / Lock both users by ID to prevent reciprocal-request races
            $firstUserId = min((int) $requesterId, $addresseeId);
            $secondUserId = max((int) $requesterId, $addresseeId);
            $query = "SELECT user_id
                      FROM users
                      WHERE user_id IN (?, ?)
                      ORDER BY user_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $firstUserId, $secondUserId);
            $stmt->execute();
            $stmt->close();

            $query = "SELECT friendship_id, requester_id, addressee_id, status
                      FROM friendships
                      WHERE (requester_id = ? AND addressee_id = ?)
                         OR (requester_id = ? AND addressee_id = ?)
                      ORDER BY friendship_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iiii',
                $requesterId,
                $addresseeId,
                $addresseeId,
                $requesterId
            );
            $stmt->execute();
            $result = $stmt->get_result();
            $existing = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($existing) {
                if ($existing['status'] === 'accepted') {
                    $this->db->rollback();
                    return $this->failure('你们已经是好友。');
                }
                if ($existing['status'] === 'blocked') {
                    $this->db->rollback();
                    return $this->failure('当前无法发送好友申请。');
                }
                if ($existing['status'] === 'pending') {
                    $this->db->rollback();
                    return $this->failure(
                        (int) $existing['requester_id'] === (int) $requesterId
                            ? '好友申请已经等待处理。'
                            : '对方已经向你发出申请，请在待处理列表中回应。'
                    );
                }
                // 被拒申请可由任一方重开，并以本次申请者为发起人 / Either party may reopen a rejected pair, with the current applicant as requester
                $query = "UPDATE friendships
                          SET requester_id = ?, addressee_id = ?,
                              status = 'pending',
                              created_at = NOW(), updated_at = NOW()
                          WHERE friendship_id = ? AND status = 'rejected'";
                $stmt = $this->db->prepare($query);
                $friendshipId = (int) $existing['friendship_id'];
                $stmt->bind_param(
                    'iii',
                    $requesterId,
                    $addresseeId,
                    $friendshipId
                );
                $success = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
            } else {
                $query = "INSERT INTO friendships (requester_id, addressee_id, status)
                          VALUES (?, ?, 'pending')";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $requesterId, $addresseeId);
                $success = $stmt->execute();
                $stmt->close();
            }

            if (!$success) {
                $this->db->rollback();
                return $this->failure('好友申请发送失败。');
            }

            $this->db->commit();
            return $this->success('好友申请已发送。');
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Friend request failed: ' . $exception->getMessage());
            return $this->failure('好友申请发送失败，请稍后再试。');
        }
    }

    /**
     * 接受或拒绝收到的好友申请 / Accept or reject an incoming friend request
     * @return array 操作结果 / Operation result
     */
    public function respondToFriendRequest($userId, $friendshipId, $decision) {
        $friendshipId = (int) $friendshipId;
        $decision = (string) $decision;
        if ($friendshipId <= 0 || !in_array($decision, ['accepted', 'rejected'], true)) {
            return $this->failure('无效的好友申请操作。');
        }

        $query = "UPDATE friendships
                  SET status = ?, updated_at = NOW()
                  WHERE friendship_id = ? AND addressee_id = ? AND status = 'pending'";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('sii', $decision, $friendshipId, $userId);
        $success = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();

        if (!$success) {
            return $this->failure('申请不存在、已经处理或不属于你。');
        }

        return $this->success($decision === 'accepted' ? '好友申请已接受。' : '好友申请已拒绝。');
    }

    /**
     * 获取好友及待处理申请 / Get friends and pending requests
     * @return array 好友状态数据 / Friendship state
     */
    public function getFriendshipState($userId) {
        $query = "SELECT f.friendship_id,
                         CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END AS friend_id,
                         CASE WHEN f.requester_id = ? THEN addressee.username ELSE requester.username END AS username,
                         f.updated_at
                  FROM friendships f
                  INNER JOIN users requester ON requester.user_id = f.requester_id
                  INNER JOIN users addressee ON addressee.user_id = f.addressee_id
                  WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'
                  ORDER BY username";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iiii', $userId, $userId, $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $friends = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $friends[] = $row;
        }
        $stmt->close();

        $query = "SELECT f.friendship_id, f.requester_id, u.username, f.created_at
                  FROM friendships f
                  INNER JOIN users u ON u.user_id = f.requester_id
                  WHERE f.addressee_id = ? AND f.status = 'pending'
                  ORDER BY f.created_at";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $incoming = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $incoming[] = $row;
        }
        $stmt->close();

        $query = "SELECT f.friendship_id, f.addressee_id, u.username, f.created_at
                  FROM friendships f
                  INNER JOIN users u ON u.user_id = f.addressee_id
                  WHERE f.requester_id = ? AND f.status = 'pending'
                  ORDER BY f.created_at";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $outgoing = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $outgoing[] = $row;
        }
        $stmt->close();

        return [
            'friends' => $friends,
            'incoming' => $incoming,
            'outgoing' => $outgoing
        ];
    }

    /**
     * 按用户名查找用户 ID / Find a user ID by username
     */
    private function findUserIdByUsername($username) {
        $query = "SELECT user_id FROM users WHERE username = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? (int) $row['user_id'] : null;
    }

    /**
     * 获取用户当前联盟 ID / Get the user's current alliance ID
     */
    private function getAllianceId($userId) {
        $query = "SELECT alliance_id FROM alliance_members WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? (int) $row['alliance_id'] : null;
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

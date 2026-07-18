<?php
// 种火集结号 - 分基地服务 / Fireseed Engage - Sub-base service

class SubBaseService {
    private $db;
    private $resourceTypes = [
        'bright',
        'warm',
        'cold',
        'green',
        'day',
        'night'
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 按玩家等级计算分基地上限 / Calculate the sub-base limit from player level
     *
     * 本项目采用每级一个分基地、最低一个的兼容规则，以衔接现有等级系统。
     * This project uses one sub-base per level, with a minimum of one, to fit the
     * existing level system.
     *
     * @param int $level 玩家等级 / Player level
     * @return int 分基地上限 / Sub-base limit
     */
    public static function getSubBaseLimit($level) {
        return max(1, (int) $level);
    }

    /**
     * 获取分基地、候选资源点与上限概览 / Get sub-bases, candidate resource tiles, and limit overview
     * @param int $userId 玩家ID / User ID
     * @return array 概览 / Overview
     */
    public function getOverview($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return $this->failure('玩家参数无效 / Invalid player');
        }

        $query = "SELECT level
                  FROM users
                  WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$user) {
            return $this->failure('玩家不存在 / Player does not exist');
        }

        $query = "SELECT c.city_id, c.name, c.x, c.y, c.level,
                         c.durability, c.max_durability,
                         mt.subtype AS map_subtype
                  FROM cities c
                  LEFT JOIN map_tiles mt
                    ON mt.x = c.x AND mt.y = c.y
                  WHERE c.owner_id = ? AND c.is_main_city = 0
                  ORDER BY c.city_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subBases = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $subBases[] = [
                'city_id' => (int) $row['city_id'],
                'name' => (string) $row['name'],
                'x' => (int) $row['x'],
                'y' => (int) $row['y'],
                'level' => max(1, (int) $row['level']),
                'durability' => max(0, (int) $row['durability']),
                'max_durability' => max(1, (int) $row['max_durability']),
                'map_subtype' => $row['map_subtype']
            ];
        }
        $stmt->close();

        $limit = self::getSubBaseLimit((int) $user['level']);
        $currentCount = count($subBases);
        $query = "SELECT mt.tile_id, mt.x, mt.y, mt.subtype,
                         mt.resource_amount,
                         (
                             SELECT COUNT(*)
                             FROM territory_garrisons tg
                             WHERE tg.tile_id = mt.tile_id
                               AND tg.quantity > 0
                         ) AS garrison_count,
                         (
                             SELECT c.city_id
                             FROM cities c
                             WHERE c.x = mt.x AND c.y = mt.y
                             LIMIT 1
                         ) AS city_id
                  FROM map_tiles mt
                  WHERE mt.owner_id = ? AND mt.type = 'resource'
                  ORDER BY mt.x, mt.y, mt.tile_id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $candidates = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $reason = '';
            if ($row['city_id'] !== null) {
                $reason = '该坐标已有城池 / A city already occupies these coordinates';
            } elseif ((int) $row['garrison_count'] > 0) {
                $reason = '请先撤回该资源点驻军 / Withdraw this resource tile garrison first';
            } elseif (!in_array($row['subtype'], $this->resourceTypes, true)) {
                $reason = '资源系数据无效 / Invalid resource affinity';
            } elseif ($currentCount >= $limit) {
                $reason = '分基地数量已达当前等级上限 / Current level limit reached';
            }

            $candidates[] = [
                'tile_id' => (int) $row['tile_id'],
                'x' => (int) $row['x'],
                'y' => (int) $row['y'],
                'resource_type' => (string) $row['subtype'],
                'resource_amount' => max(0, (int) $row['resource_amount']),
                'garrison_count' => max(0, (int) $row['garrison_count']),
                'can_create' => $reason === '',
                'reason' => $reason === ''
                    ? '可以改建 / Ready to convert'
                    : $reason
            ];
        }
        $stmt->close();

        return [
            'success' => true,
            'message' => '分基地概览已加载 / Sub-base overview loaded',
            'level' => max(1, (int) $user['level']),
            'limit' => $limit,
            'current_count' => $currentCount,
            'available_slots' => max(0, $limit - $currentCount),
            'sub_bases' => $subBases,
            'candidates' => $candidates
        ];
    }

    /**
     * 将一个无驻军的自有资源点改建为分基地 / Convert an owned ungarrisoned resource tile into a sub-base
     * @param int $userId 玩家ID / User ID
     * @param int $tileId 资源点ID / Resource tile ID
     * @param mixed $name 分基地名称 / Sub-base name
     * @return array 创建结果 / Creation result
     */
    public function createSubBase($userId, $tileId, $name) {
        $userId = (int) $userId;
        $tileId = (int) $tileId;
        $name = $this->normalizeName($name);
        if ($userId <= 0 || $tileId <= 0) {
            return $this->failure('分基地参数无效 / Invalid sub-base parameters');
        }
        if ($name === '') {
            return $this->failure('分基地名称不能为空 / Sub-base name is required');
        }

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            // 固定锁序为赛季、玩家、地图格、驻军、坐标城池、玩家分基地 / Lock in season, user, tile, garrison, coordinate-city, and user-sub-base order
            $query = "SELECT user_id, level, circuit_points, max_circuit_points
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
                throw new RuntimeException('玩家不存在 / Player does not exist');
            }

            $query = "SELECT tile_id, x, y, type, subtype, owner_id
                      FROM map_tiles
                      WHERE tile_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $tileId);
            $stmt->execute();
            $result = $stmt->get_result();
            $tile = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$tile
                || (int) $tile['owner_id'] !== $userId
                || $tile['type'] !== 'resource') {
                throw new RuntimeException(
                    '只能改建自己拥有的资源点 / Only an owned resource tile can be converted'
                );
            }
            if (!in_array($tile['subtype'], $this->resourceTypes, true)) {
                throw new RuntimeException('资源系数据无效 / Invalid resource affinity');
            }

            $query = "SELECT garrison_id
                      FROM territory_garrisons
                      WHERE tile_id = ? AND quantity > 0
                      ORDER BY garrison_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $tileId);
            $stmt->execute();
            $result = $stmt->get_result();
            $hasGarrison = $result && $result->num_rows > 0;
            $stmt->close();
            if ($hasGarrison) {
                throw new RuntimeException(
                    '请先撤回该资源点驻军 / Withdraw the resource tile garrison first'
                );
            }

            $x = (int) $tile['x'];
            $y = (int) $tile['y'];
            $query = "SELECT city_id
                      FROM cities
                      WHERE x = ? AND y = ?
                      LIMIT 1
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $x, $y);
            $stmt->execute();
            $result = $stmt->get_result();
            $hasCity = $result && $result->num_rows > 0;
            $stmt->close();
            if ($hasCity) {
                throw new RuntimeException(
                    '该坐标已有城池 / A city already occupies these coordinates'
                );
            }

            $query = "SELECT city_id
                      FROM cities
                      WHERE owner_id = ? AND is_main_city = 0
                      ORDER BY city_id
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $subBaseCount = $result ? $result->num_rows : 0;
            $stmt->close();
            $limit = self::getSubBaseLimit((int) $user['level']);
            if ($subBaseCount >= $limit) {
                throw new RuntimeException(
                    '分基地数量已达当前等级上限 / Current level limit reached'
                );
            }

            $level = 1;
            $durability = 3000;
            $isMainCity = 0;
            $query = "INSERT INTO cities
                         (name, owner_id, x, y, level, durability,
                          max_durability, is_main_city)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'siiiiiii',
                $name,
                $userId,
                $x,
                $y,
                $level,
                $durability,
                $durability,
                $isMainCity
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('无法创建分基地 / Failed to create sub-base');
            }
            $cityId = (int) $this->db->insert_id;
            $stmt->close();

            $query = "UPDATE map_tiles
                      SET type = 'player_city', subtype = 'sub_base',
                          resource_amount = NULL, npc_level = NULL,
                          npc_garrison = 0, npc_respawn_time = NULL,
                          last_collection_time = NULL, is_visible = 1
                      WHERE tile_id = ? AND owner_id = ? AND type = 'resource'";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ii', $tileId, $userId);
            $converted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$converted) {
                throw new RuntimeException(
                    '资源点状态已经变化 / Resource tile state changed'
                );
            }

            $this->insertFacility($cityId, 'governor_office', null, 12, 12);
            $this->insertFacility(
                $cityId,
                'resource_production',
                (string) $tile['subtype'],
                1,
                1
            );

            $refundCost = max(0, (int) TERRITORY_OCCUPATION_COST);
            $refund = $refundCost;
            if ($refundCost > 0) {
                if ((int) $user['circuit_points']
                    > 2147483647 - $refundCost) {
                    throw new RuntimeException(
                        '思考回路无法全额返还且不溢出整数 / Circuit Points cannot be fully refunded without integer overflow'
                    );
                }
                $query = "UPDATE users
                          SET circuit_points = circuit_points + ?
                          WHERE user_id = ? AND circuit_points <= ?";
                $stmt = $this->db->prepare($query);
                $maximumBeforeAdd = 2147483647 - $refundCost;
                $stmt->bind_param(
                    'iii',
                    $refundCost,
                    $userId,
                    $maximumBeforeAdd
                );
                $refunded = $stmt->execute()
                    && $stmt->affected_rows === 1;
                if (!$refunded) {
                    $stmt->close();
                    throw new RuntimeException(
                        '无法返还思考回路 / Failed to refund circuit points'
                    );
                }
                $stmt->close();
            }

            $this->recordGameplayEvent(
                $userId,
                'sub_base_created',
                1,
                'city',
                $cityId
            );

            $this->db->commit();
            return [
                'success' => true,
                'message' => '资源点已改建为分基地 / Resource tile converted into a sub-base',
                'city_id' => $cityId,
                'tile_id' => $tileId,
                'name' => $name,
                'resource_type' => (string) $tile['subtype'],
                'refunded_circuit_points' => $refund,
                'circuit_points' =>
                    (int) $user['circuit_points'] + $refundCost,
                'max_circuit_points' => (int) $user['max_circuit_points'],
                'limit' => $limit,
                'current_count' => $subBaseCount + 1
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Sub-base creation failed: ' . $exception->getMessage());
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * 插入一个即时完成的初始设施 / Insert one instantly completed initial facility
     * @param int $cityId 城池ID / City ID
     * @param string $type 设施类型 / Facility type
     * @param string|null $subtype 设施子类型 / Facility subtype
     * @param int $xPos 横坐标 / X position
     * @param int $yPos 纵坐标 / Y position
     * @return void
     */
    private function insertFacility($cityId, $type, $subtype, $xPos, $yPos) {
        $level = 1;
        $query = "INSERT INTO facilities
                     (city_id, type, subtype, level, x_pos, y_pos)
                  VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param(
            'issiii',
            $cityId,
            $type,
            $subtype,
            $level,
            $xPos,
            $yPos
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException(
                '无法创建分基地初始设施 / Failed to create initial sub-base facility'
            );
        }
        $stmt->close();
    }

    /**
     * 在当前事务内记录玩法事件 / Record a gameplay event in the current transaction
     * @param int $userId 玩家ID / User ID
     * @param string $eventType 事件类型 / Event type
     * @param int $eventValue 事件值 / Event value
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
                '无法记录分基地事件 / Failed to record sub-base event'
            );
        }
        $stmt->close();
    }

    /**
     * 规范化单行分基地名称 / Normalize a one-line sub-base name
     * @param mixed $name 原始名称 / Raw name
     * @return string 规范化名称 / Normalized name
     */
    private function normalizeName($name) {
        if (!is_scalar($name)) {
            return '';
        }

        $normalized = normalizeTextInput((string) $name, 50);
        $normalized = str_replace(
            ['<', '>', '&', '"', "'"],
            '',
            $normalized
        );
        $withoutControls = preg_replace(
            '/[\x00-\x1F\x7F]+/u',
            ' ',
            $normalized
        );
        if ($withoutControls === null) {
            $withoutControls = $normalized;
        }
        $collapsed = preg_replace('/\s+/u', ' ', $withoutControls);

        return trim($collapsed === null ? $withoutControls : $collapsed);
    }

    /**
     * 构造失败结果 / Build a failure result
     * @param string $message 提示 / Message
     * @return array 失败结果 / Failure result
     */
    private function failure($message) {
        return [
            'success' => false,
            'message' => (string) $message
        ];
    }
}

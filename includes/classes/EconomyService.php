<?php
// 种火集结号 - 非付费经济与奖励服务 / Fireseed Engage - Non-paid economy and reward service

class EconomyService {
    private $db;

    /**
     * 构造函数 / Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 获取游戏内钱包 / Get the earned-currency wallet
     * @param int $userId 用户ID / User ID
     * @return array 钱包数据 / Wallet data
     */
    public function getWallet($userId) {
        $this->ensureWallet($userId);
        $query = "SELECT skill_points, merit_points, arena_tokens
                  FROM gameplay_wallets WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $wallet = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $wallet ?: [
            'skill_points' => 0,
            'merit_points' => 0,
            'arena_tokens' => 0
        ];
    }

    /**
     * 获取玩家道具 / Get a player's item inventory
     * @param int $userId 用户ID / User ID
     * @return array 道具代码到数量的映射 / Item-code to quantity map
     */
    public function getItems($userId) {
        $query = "SELECT item_code, quantity FROM user_items
                  WHERE user_id = ? AND quantity > 0 ORDER BY item_code";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $items[$row['item_code']] = (int) $row['quantity'];
            }
        }
        $stmt->close();

        return $items;
    }

    /**
     * 获取非付费商店目录 / Get the non-paid shop catalog
     * @param int $userId 用户ID / User ID
     * @return array 商店项目 / Shop entries
     */
    public function getShopCatalog($userId) {
        $query = "SELECT shop_item_id, item_code, name, description, cost_json,
                         grant_json, daily_limit
                  FROM shop_catalog WHERE is_active = 1 ORDER BY shop_item_id";
        $result = executePreparedSql($this->db, $query);
        $items = [];
        if (!$result) {
            return $items;
        }

        while ($row = $result->fetch_assoc()) {
            $row['cost'] = decodeJsonObject($row['cost_json']);
            $row['grant'] = decodeJsonObject($row['grant_json']);
            $row['purchased_today'] = $this->getPurchasedToday(
                $userId,
                (int) $row['shop_item_id']
            );
            $items[] = $row;
        }

        return $items;
    }

    /**
     * 购买一个非付费商店项目 / Purchase a non-paid shop entry
     * @param int $userId 用户ID / User ID
     * @param int $shopItemId 商店项目ID / Shop item ID
     * @param int $quantity 数量 / Quantity
     * @return array 操作结果 / Operation result
     */
    public function purchase($userId, $shopItemId, $quantity = 1) {
        $userId = (int) $userId;
        $shopItemId = (int) $shopItemId;
        $quantity = (int) $quantity;
        if ($userId < 1 || $shopItemId < 1 || $quantity < 1 || $quantity > 10) {
            return ['success' => false, 'message' => '购买参数无效'];
        }

        $this->db->begin_transaction();

        try {
            $query = "SELECT * FROM shop_catalog
                      WHERE shop_item_id = ? AND is_active = 1 FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $shopItemId);
            $stmt->execute();
            $result = $stmt->get_result();
            $item = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$item) {
                throw new RuntimeException('商店项目不存在');
            }

            if ($item['daily_limit'] !== null) {
                $purchased = $this->getPurchasedToday($userId, $shopItemId);
                if ($purchased + $quantity > (int) $item['daily_limit']) {
                    throw new RuntimeException('已超过该项目的每日兑换上限');
                }
            }

            $cost = self::multiplyBundle(decodeJsonObject($item['cost_json']), $quantity);
            $grant = self::multiplyBundle(decodeJsonObject($item['grant_json']), $quantity);
            self::deductCostInTransaction($this->db, $userId, $cost);
            self::applyRewardInTransaction($this->db, $userId, $grant);

            $costJson = json_encode($cost, JSON_UNESCAPED_UNICODE);
            $query = "INSERT INTO shop_purchases
                        (user_id, shop_item_id, quantity, cost_json)
                      VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iiis', $userId, $shopItemId, $quantity, $costJson);
            if (!$stmt->execute()) {
                throw new RuntimeException('记录兑换失败');
            }
            $stmt->close();

            $this->db->commit();
            return [
                'success' => true,
                'message' => '兑换成功',
                'item_name' => $item['name'],
                'grant' => $grant
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 直接发放奖励 / Grant a reward bundle
     * @param int $userId 用户ID / User ID
     * @param array $reward 奖励包 / Reward bundle
     * @return bool 是否成功 / Whether the grant succeeded
     */
    public function grantReward($userId, $reward) {
        $this->db->begin_transaction();

        try {
            self::applyRewardInTransaction($this->db, (int) $userId, $reward);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('Reward grant failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 在现有事务内发放奖励 / Apply a reward inside an existing transaction
     * @param mysqli $db 数据库连接 / Database connection
     * @param int $userId 用户ID / User ID
     * @param array $reward 奖励包 / Reward bundle
     * @return void
     */
    public static function applyRewardInTransaction($db, $userId, $reward) {
        if (!is_array($reward)) {
            throw new InvalidArgumentException('奖励格式无效');
        }

        $resourceColumns = [
            'bright' => 'bright_crystal',
            'warm' => 'warm_crystal',
            'cold' => 'cold_crystal',
            'green' => 'green_crystal',
            'day' => 'day_crystal',
            'night' => 'night_crystal',
            'bright_crystal' => 'bright_crystal',
            'warm_crystal' => 'warm_crystal',
            'cold_crystal' => 'cold_crystal',
            'green_crystal' => 'green_crystal',
            'day_crystal' => 'day_crystal',
            'night_crystal' => 'night_crystal'
        ];
        $resources = isset($reward['resources']) && is_array($reward['resources'])
            ? $reward['resources']
            : [];
        foreach ($resourceColumns as $rewardKey => $column) {
            if (isset($reward[$rewardKey])) {
                $resources[$rewardKey] = $reward[$rewardKey];
            }
        }
        foreach ($resources as $type => $amount) {
            if (!isset($resourceColumns[$type]) || (int) $amount < 0) {
                throw new InvalidArgumentException('奖励资源类型或数量无效');
            }
            $column = $resourceColumns[$type];
            $normalizedAmount = (int) $amount;
            $query = "UPDATE resources SET $column = $column + ? WHERE user_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $normalizedAmount, $userId);
            if (!$stmt->execute()) {
                throw new RuntimeException('发放资源奖励失败');
            }
            $stmt->close();
        }

        if (isset($reward['circuit_points'])) {
            $amount = max(0, (int) $reward['circuit_points']);
            $query = "UPDATE users
                      SET circuit_points = GREATEST(
                        circuit_points,
                        LEAST(max_circuit_points, circuit_points + ?)
                      )
                      WHERE user_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $amount, $userId);
            if (!$stmt->execute()) {
                throw new RuntimeException('发放思考回路失败');
            }
            $stmt->close();
        }

        if (isset($reward['wallet']) && is_array($reward['wallet'])) {
            self::ensureWalletInTransaction($db, $userId);
            $walletColumns = [
                'skill_points' => 'skill_points',
                'merit_points' => 'merit_points',
                'arena_tokens' => 'arena_tokens'
            ];
            foreach ($reward['wallet'] as $type => $amount) {
                if (!isset($walletColumns[$type]) || (int) $amount < 0) {
                    throw new InvalidArgumentException('钱包奖励类型或数量无效');
                }
                $column = $walletColumns[$type];
                $normalizedAmount = (int) $amount;
                $query = "UPDATE gameplay_wallets
                          SET $column = $column + ? WHERE user_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param('ii', $normalizedAmount, $userId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('发放钱包奖励失败');
                }
                $stmt->close();
            }
        }

        if (isset($reward['items']) && is_array($reward['items'])) {
            foreach ($reward['items'] as $itemCode => $amount) {
                $normalizedCode = normalizeTextInput($itemCode, 64);
                $normalizedAmount = (int) $amount;
                if ($normalizedCode === '' || $normalizedAmount < 0) {
                    throw new InvalidArgumentException('道具奖励无效');
                }
                $query = "INSERT INTO user_items (user_id, item_code, quantity)
                          VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)";
                $stmt = $db->prepare($query);
                $stmt->bind_param('isi', $userId, $normalizedCode, $normalizedAmount);
                if (!$stmt->execute()) {
                    throw new RuntimeException('发放道具奖励失败');
                }
                $stmt->close();
            }
        }
    }

    /**
     * 在现有事务内扣除成本 / Deduct a cost inside an existing transaction
     * @param mysqli $db 数据库连接 / Database connection
     * @param int $userId 用户ID / User ID
     * @param array $cost 成本包 / Cost bundle
     * @return void
     */
    public static function deductCostInTransaction($db, $userId, $cost) {
        if (!is_array($cost)) {
            throw new InvalidArgumentException('成本格式无效');
        }

        $resourceColumns = [
            'bright' => 'bright_crystal',
            'warm' => 'warm_crystal',
            'cold' => 'cold_crystal',
            'green' => 'green_crystal',
            'day' => 'day_crystal',
            'night' => 'night_crystal'
        ];
        $walletColumns = [
            'skill_points' => 'skill_points',
            'merit_points' => 'merit_points',
            'arena_tokens' => 'arena_tokens'
        ];

        foreach ($cost as $type => $amount) {
            $normalizedAmount = (int) $amount;
            if ($normalizedAmount < 0) {
                throw new InvalidArgumentException('成本数量无效');
            }

            if (isset($resourceColumns[$type])) {
                $column = $resourceColumns[$type];
                $query = "UPDATE resources SET $column = $column - ?
                          WHERE user_id = ? AND $column >= ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param('iii', $normalizedAmount, $userId, $normalizedAmount);
            } elseif ($type === 'circuit_points') {
                $query = "UPDATE users SET circuit_points = circuit_points - ?
                          WHERE user_id = ? AND circuit_points >= ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param('iii', $normalizedAmount, $userId, $normalizedAmount);
            } elseif (isset($walletColumns[$type])) {
                self::ensureWalletInTransaction($db, $userId);
                $column = $walletColumns[$type];
                $query = "UPDATE gameplay_wallets SET $column = $column - ?
                          WHERE user_id = ? AND $column >= ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param('iii', $normalizedAmount, $userId, $normalizedAmount);
            } else {
                throw new InvalidArgumentException('未知成本类型');
            }

            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('资源或代币不足');
            }
            $stmt->close();
        }
    }

    /**
     * 确保钱包记录存在 / Ensure that a wallet row exists
     * @param int $userId 用户ID / User ID
     * @return void
     */
    private function ensureWallet($userId) {
        self::ensureWalletInTransaction($this->db, (int) $userId);
    }

    /**
     * 在当前连接上确保钱包存在 / Ensure a wallet on the current connection
     * @param mysqli $db 数据库连接 / Database connection
     * @param int $userId 用户ID / User ID
     * @return void
     */
    private static function ensureWalletInTransaction($db, $userId) {
        $query = "INSERT IGNORE INTO gameplay_wallets (user_id) VALUES (?)";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            throw new RuntimeException('初始化游戏钱包失败');
        }
        $stmt->close();
    }

    /**
     * 获取今日兑换数量 / Get today's purchased quantity
     * @param int $userId 用户ID / User ID
     * @param int $shopItemId 商店项目ID / Shop item ID
     * @return int 已兑换数量 / Purchased quantity
     */
    private function getPurchasedToday($userId, $shopItemId) {
        $query = "SELECT COALESCE(SUM(quantity), 0) AS purchased
                  FROM shop_purchases
                  WHERE user_id = ? AND shop_item_id = ?
                    AND created_at >= CURDATE()
                    AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $userId, $shopItemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? (int) $row['purchased'] : 0;
    }

    /**
     * 按数量放大奖励或成本包 / Multiply a reward or cost bundle
     * @param array $bundle 原始包 / Original bundle
     * @param int $quantity 倍数 / Multiplier
     * @return array 放大后的包 / Multiplied bundle
     */
    private static function multiplyBundle($bundle, $quantity) {
        $result = [];
        foreach ($bundle as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::multiplyBundle($value, $quantity);
            } elseif (is_numeric($value)) {
                $result[$key] = (int) $value * $quantity;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}

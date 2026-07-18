<?php
// 种火集结号 - 辅助函数

/**
 * 格式化数字
 * @param int $number 要格式化的数字
 * @return string 格式化后的数字
 */
function formatNumber($number) {
    return number_format($number);
}

/**
 * 格式化时间
 * @param int $seconds 秒数
 * @return string 格式化后的时间（HH:MM:SS）
 */
function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

/**
 * 获取士兵名称
 * @param string $type 士兵类型
 * @return string 士兵名称
 */
function getSoldierName($type) {
    switch ($type) {
        case 'pawn':
            return '兵卒';
        case 'knight':
            return '骑士';
        case 'rook':
            return '城壁';
        case 'bishop':
            return '主教';
        case 'golem':
            return '锤子兵';
        case 'scout':
            return '侦察兵';
        default:
            return '未知士兵';
    }
}

/**
 * 获取设施名称
 * @param string $type 设施类型
 * @param string $subtype 设施子类型
 * @return string 设施名称
 */
function getFacilityName($type, $subtype = null) {
    switch ($type) {
        case 'resource_production':
            switch ($subtype) {
                case 'bright':
                    return '亮晶晶产出点';
                case 'warm':
                    return '暖洋洋产出点';
                case 'cold':
                    return '冷冰冰产出点';
                case 'green':
                    return '郁萌萌产出点';
                case 'day':
                    return '昼闪闪产出点';
                case 'night':
                    return '夜静静产出点';
                default:
                    return '资源产出点';
            }
        case 'governor_office':
            return '总督府';
        case 'barracks':
            return '兵营';
        case 'research_lab':
            return '研究所';
        case 'dormitory':
            return '宿舍';
        case 'storage':
            return '贮存所';
        case 'watchtower':
            return '瞭望台';
        case 'workshop':
            return '工程所';
        default:
            return '未知设施';
    }
}

/**
 * 获取资源名称
 * @param string $type 资源类型
 * @return string 资源名称
 */
function getResourceName($type) {
    switch ($type) {
        case 'bright':
            return '亮晶晶';
        case 'warm':
            return '暖洋洋';
        case 'cold':
            return '冷冰冰';
        case 'green':
            return '郁萌萌';
        case 'day':
            return '昼闪闪';
        case 'night':
            return '夜静静';
        default:
            return '未知资源';
    }
}

/**
 * 计算两点之间的距离
 * @param int $x1 起点X坐标
 * @param int $y1 起点Y坐标
 * @param int $x2 终点X坐标
 * @param int $y2 终点Y坐标
 * @return float 距离
 */
function calculateDistance($x1, $y1, $x2, $y2) {
    return sqrt(pow($x2 - $x1, 2) + pow($y2 - $y1, 2));
}

/**
 * 生成随机字符串
 * @param int $length 字符串长度
 * @return string 随机字符串
 */
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * 转义HTML输出 / Escape a value for HTML output
 * @param mixed $value 待输出值 / Value to render
 * @return string 已转义文本 / Escaped text
 */
function escapeHtml($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 获取当前会话的CSRF令牌 / Get the current session CSRF token
 * @return string CSRF令牌 / CSRF token
 */
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * 渲染CSRF隐藏字段 / Render a CSRF hidden input
 * @return string HTML字段 / HTML input
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . escapeHtml(getCsrfToken()) . '">';
}

/**
 * 验证请求携带的CSRF令牌 / Validate a request CSRF token
 * @param string|null $token 请求令牌 / Request token
 * @return bool 是否有效 / Whether the token is valid
 */
function validateCsrfToken($token = null) {
    $requestToken = $token;
    if ($requestToken === null) {
        $requestToken = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    }

    return isset($_SESSION['csrf_token'])
        && is_string($requestToken)
        && hash_equals($_SESSION['csrf_token'], $requestToken);
}

/**
 * 要求当前请求使用POST且通过CSRF校验 / Require POST with a valid CSRF token
 * @return bool 是否通过 / Whether validation passed
 */
function isValidPostRequest() {
    return isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && validateCsrfToken();
}

/**
 * 获取当前赛季对城池与地图操作的锁定状态 / Get the current season lock for city and map actions
 * @return array 锁定状态、重置时间与提示 / Lock state, reset time, and message
 */
function getSeasonGameplayLockState() {
    static $loaded = false;
    static $state = null;

    if ($loaded) {
        return $state;
    }
    $loaded = true;
    $state = [
        'frozen' => false,
        'reset_at' => null,
        'message' => ''
    ];

    try {
        $db = Database::getInstance()->getConnection();
        $query = "SELECT status, reset_at
                  FROM seasons
                  WHERE ended_at IS NULL
                  ORDER BY season_number DESC
                  LIMIT 1";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            return $state;
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return $state;
        }
        $result = $stmt->get_result();
        $season = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($season && $season['status'] === 'reset_pending') {
            $state['frozen'] = true;
            $state['reset_at'] = $season['reset_at'];
            $state['message'] = '本轮已经结束，城池与地图操作将在新赛季开始后恢复'
                . ($season['reset_at']
                    ? '（预计 ' . $season['reset_at'] . '）'
                    : '')
                . ' / This round has ended; city and map actions resume with the next season.';
        }
    } catch (Throwable $exception) {
        // 旧数据库尚未迁移时保持可用，并记录诊断信息 / Keep legacy installations usable before migration and log diagnostics
        error_log('Unable to read season gameplay lock: ' . $exception->getMessage());
    }

    return $state;
}

/**
 * 判断城池与地图操作是否处于赛季冻结期 / Determine whether city and map actions are season-frozen
 * @return bool 是否冻结 / Whether actions are frozen
 */
function isSeasonGameplayFrozen() {
    $state = getSeasonGameplayLockState();

    return !empty($state['frozen']);
}

/**
 * 获取赛季冻结提示 / Get the season-freeze message
 * @return string 双语提示 / Bilingual message
 */
function getSeasonGameplayFreezeMessage() {
    $state = getSeasonGameplayLockState();

    return !empty($state['message'])
        ? (string) $state['message']
        : '当前无法进行城池或地图操作 / City and map actions are currently unavailable.';
}

/**
 * 在世界操作事务中锁定当前赛季并拒绝冻结期写入 / Lock the current season in a world-action transaction and reject frozen writes
 *
 * 调用方必须已经开启事务；赛季锁始终先于玩家、地图、城池和军队锁，
 * 使冻结切换与玩家操作形成单一的先后顺序。
 * The caller must already have an open transaction. The season lock always
 * precedes user, map, city, and army locks so freeze transitions and player
 * actions have one authoritative ordering.
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @return void
 * @throws RuntimeException 无法读取赛季或当前处于冻结期 / Season read failure or active freeze
 */
function lockSeasonForWorldAction($db) {
    $query = "SELECT status
              FROM seasons
              WHERE ended_at IS NULL
              ORDER BY season_number DESC
              LIMIT 1
              LOCK IN SHARE MODE";
    $stmt = $db->prepare($query);
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) {
            $stmt->close();
        }
        throw new RuntimeException(
            '无法锁定当前赛季 / Failed to lock the current season'
        );
    }
    $result = $stmt->get_result();
    $season = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($season && $season['status'] === 'reset_pending') {
        throw new RuntimeException(
            '本轮已经结束，城池与地图操作将在新赛季开始后恢复'
            . ' / This round has ended; city and map actions resume with the next season.'
        );
    }
}

/**
 * 锁定资源目录与卡池后台的共享事务边界 / Lock the shared catalog-and-pool administration boundary
 *
 * 调用方必须已经开启事务，并在锁定任何卡池或目录行之前调用本函数。
 * The caller must already have a transaction open and invoke this function
 * before locking any pool or catalog row.
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @return void
 * @throws RuntimeException 互斥锁缺失或无法取得 / Mutex missing or unavailable
 */
function lockResourceAdministrationBoundary($db) {
    $query = "SELECT lock_name
              FROM resource_admin_locks
              WHERE lock_name = 'catalog_pools'
              FOR UPDATE";
    $stmt = $db->prepare($query);
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) {
            $stmt->close();
        }
        throw new RuntimeException(
            '无法锁定资源目录与卡池管理边界'
            . ' / Failed to lock the catalog-and-pool administration boundary'
        );
    }

    $result = $stmt->get_result();
    $lock = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$lock) {
        throw new RuntimeException(
            '资源管理互斥锁尚未安装，请先执行最新数据库升级'
            . ' / Resource administration mutex is not installed'
        );
    }
}

/**
 * 限制并清理单行文本输入 / Normalize and bound a single-line text input
 * @param mixed $value 输入值 / Input value
 * @param int $maxLength 最大字符数 / Maximum character count
 * @return string 已清理文本 / Normalized text
 */
function normalizeTextInput($value, $maxLength = 255) {
    $text = trim(str_replace(["\r", "\n"], ' ', (string) $value));
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    return substr($text, 0, $maxLength);
}

/**
 * 安全地解码JSON对象 / Safely decode a JSON object
 * @param string|null $json JSON文本 / JSON text
 * @return array 解码结果 / Decoded object
 */
function decodeJsonObject($json) {
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? $decoded : [];
}

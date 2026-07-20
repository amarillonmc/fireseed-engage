<?php
// 种火集结号 - 武将与技能卡池管理 / Fireseed Engage - general and skill-card pool administration
require_once '../includes/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid()) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
if (!$user->isAdmin()) {
    header('Location: ../index.php');
    exit;
}

$adminManager = new AdminManager($user);
if (!$adminManager->hasPermission('manage_card_pools')) {
    http_response_code(403);
    die('您没有权限访问卡池管理');
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

if (!empty($_SESSION['admin_card_pool_flash'])
    && is_array($_SESSION['admin_card_pool_flash'])) {
    $flash = $_SESSION['admin_card_pool_flash'];
    unset($_SESSION['admin_card_pool_flash']);
    $success = isset($flash['success']) && is_scalar($flash['success'])
        ? (string) $flash['success']
        : '';
}

/**
 * 读取标量表单值 / Reads a scalar form value
 *
 * @param array $input 表单输入 / Form input
 * @param string $key 字段名 / Field name
 * @param string $default 默认值 / Default value
 * @return string 标量文本 / Scalar text
 */
function adminPoolScalar(array $input, $key, $default = '') {
    return isset($input[$key]) && is_scalar($input[$key])
        ? (string) $input[$key]
        : $default;
}

/**
 * 计算UTF-8文本长度 / Counts UTF-8 text length
 *
 * @param string $value 文本 / Text
 * @return int 文本长度 / Text length
 */
function adminPoolTextLength($value) {
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

/**
 * 校验正整数ID / Validates a positive integer ID
 *
 * @param mixed $value 原始值 / Raw value
 * @param string $label 字段标签 / Field label
 * @return int 标准化ID / Normalized ID
 */
function adminPoolPositiveId($value, $label) {
    $normalized = is_scalar($value) ? $value : null;
    $id = filter_var(
        $normalized,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($id === false) {
        throw new InvalidArgumentException($label . '无效');
    }

    return (int) $id;
}

/**
 * 校验限定范围整数 / Validates an integer within a range
 *
 * @param mixed $value 原始值 / Raw value
 * @param int $minimum 最小值 / Minimum
 * @param int $maximum 最大值 / Maximum
 * @param string $label 字段标签 / Field label
 * @return int 标准化整数 / Normalized integer
 */
function adminPoolBoundedInteger($value, $minimum, $maximum, $label) {
    $normalized = is_scalar($value) ? $value : null;
    $integer = filter_var(
        $normalized,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]
    );
    if ($integer === false) {
        throw new InvalidArgumentException(
            $label . '必须是' . $minimum . '至' . $maximum . '之间的整数'
        );
    }

    return (int) $integer;
}

/**
 * 将datetime-local输入转换为数据库时间 / Converts datetime-local input to database time
 *
 * @param mixed $value 原始时间 / Raw datetime
 * @param string $label 字段标签 / Field label
 * @return string|null 数据库时间 / Database datetime
 */
function adminPoolNormalizeDateTime($value, $label) {
    if (!is_scalar($value)) {
        throw new InvalidArgumentException($label . '格式无效');
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $formats = ['!Y-m-d\TH:i', '!Y-m-d\TH:i:s'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $raw);
        $errors = DateTime::getLastErrors();
        $isClean = $errors === false
            || ((int) $errors['warning_count'] === 0
                && (int) $errors['error_count'] === 0);
        if ($date && $isClean) {
            $expectedFormat = $format === '!Y-m-d\TH:i'
                ? 'Y-m-d\TH:i'
                : 'Y-m-d\TH:i:s';
            if ($date->format($expectedFormat) === $raw) {
                return $date->format('Y-m-d H:i:s');
            }
        }
    }

    throw new InvalidArgumentException($label . '格式无效');
}

/**
 * 标准化卡池元数据 / Normalizes card-pool metadata
 *
 * @param array $input 表单输入 / Form input
 * @param bool $includeIdentity 是否包含不可变标识 / Whether immutable identity fields are included
 * @return array 标准化数据 / Normalized data
 */
function adminPoolValidateMetadata(array $input, $includeIdentity) {
    $name = trim(adminPoolScalar($input, 'name'));
    $description = trim(adminPoolScalar($input, 'description'));
    if ($name === '' || adminPoolTextLength($name) > 100) {
        throw new InvalidArgumentException('卡池名称必须为1至100个字符');
    }
    if (adminPoolTextLength($description) > 5000) {
        throw new InvalidArgumentException('卡池说明不能超过5000个字符');
    }

    $costRaw = trim(adminPoolScalar($input, 'cost_json', '{}'));
    if ($costRaw === ''
        || substr($costRaw, 0, 1) !== '{'
        || substr($costRaw, -1) !== '}') {
        throw new InvalidArgumentException('每抽成本必须是JSON对象');
    }
    $cost = CardPoolService::normalizeCostBundle($costRaw);
    $costJson = empty($cost)
        ? '{}'
        : json_encode(
            $cost,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    if ($costJson === false) {
        throw new InvalidArgumentException('每抽成本无法序列化');
    }

    $countsRaw = trim(adminPoolScalar($input, 'allowed_counts_json', '[1]'));
    if ($countsRaw === ''
        || substr($countsRaw, 0, 1) !== '['
        || substr($countsRaw, -1) !== ']') {
        throw new InvalidArgumentException('允许抽取次数必须是JSON数组');
    }
    $allowedCounts = CardPoolService::normalizeAllowedCounts($countsRaw);
    $countsJson = json_encode(
        $allowedCounts,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($countsJson === false) {
        throw new InvalidArgumentException('允许抽取次数无法序列化');
    }

    $startsAt = adminPoolNormalizeDateTime(
        adminPoolScalar($input, 'starts_at'),
        '开放时间'
    );
    $endsAt = adminPoolNormalizeDateTime(
        adminPoolScalar($input, 'ends_at'),
        '结束时间'
    );
    if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
        throw new InvalidArgumentException('结束时间必须晚于开放时间');
    }

    $data = [
        'name' => $name,
        'description' => $description,
        'cost_json' => $costJson,
        'allowed_counts_json' => $countsJson,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'sort_order' => adminPoolBoundedInteger(
            adminPoolScalar($input, 'sort_order', '0'),
            -100000,
            100000,
            '排序值'
        )
    ];

    if ($includeIdentity) {
        $poolCode = strtolower(trim(adminPoolScalar($input, 'pool_code')));
        $poolType = strtolower(trim(adminPoolScalar($input, 'pool_type')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $poolCode)) {
            throw new InvalidArgumentException(
                '卡池代码必须由1至64位小写字母、数字、下划线或短横线组成'
            );
        }
        if (!in_array($poolType, ['general', 'skill'], true)) {
            throw new InvalidArgumentException('卡池类型无效');
        }
        CardPoolService::normalizePoolCostBundle($poolType, $cost);
        $data['pool_code'] = $poolCode;
        $data['pool_type'] = $poolType;
    }

    return $data;
}

/**
 * 锁定并读取卡池 / Locks and loads a card pool
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param int $poolId 卡池ID / Pool ID
 * @return array 卡池数据 / Pool row
 */
function adminPoolLoadForUpdate($db, $poolId) {
    $query = "SELECT pool_id, pool_code, pool_type, name, description,
                     cost_json, allowed_counts_json, status, starts_at,
                     ends_at, sort_order, revision, created_by, updated_by,
                     created_at, updated_at
              FROM card_pools
              WHERE pool_id = ?
              LIMIT 1 FOR UPDATE";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法准备卡池锁定操作');
    }
    $stmt->bind_param('i', $poolId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法锁定卡池');
    }
    $result = $stmt->get_result();
    $pool = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$pool) {
        throw new DomainException('卡池不存在');
    }

    return $pool;
}

/**
 * 校验卡池编辑权限与状态 / Validates pool edit permission and status
 *
 * @param AdminManager $adminManager 管理员权限管理器 / Admin permission manager
 * @param array $pool 卡池数据 / Pool row
 * @return void
 */
function adminPoolRequireEditable($adminManager, array $pool) {
    if ($pool['status'] === 'archived') {
        throw new DomainException('归档卡池必须先恢复为草稿');
    }
    if ($pool['status'] === 'published') {
        if (!$adminManager->hasPermission('publish_card_pools')) {
            throw new DomainException('您没有权限修改已发布卡池');
        }
        return;
    }
    if (!$adminManager->hasPermission('edit_card_pools')) {
        throw new DomainException('您没有权限编辑卡池草稿');
    }
}

/**
 * 校验目录资源存在且可用 / Validates that a catalog resource exists and is active
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param string $poolType 卡池类型 / Pool type
 * @param int $resourceId 资源ID / Resource ID
 * @return array 资源数据 / Resource row
 */
function adminPoolLoadActiveResource($db, $poolType, $resourceId) {
    if ($poolType === 'general') {
        $query = "SELECT general_id AS resource_id, name, rarity, element
                  FROM generals
                  WHERE general_id = ? AND owner_id = 0 AND is_active = 1
                  LIMIT 1 FOR UPDATE";
    } elseif ($poolType === 'skill') {
        $query = "SELECT card_id AS resource_id, name, rarity, element
                  FROM skill_card_catalog
                  WHERE card_id = ? AND is_active = 1
                  LIMIT 1 FOR UPDATE";
    } else {
        throw new DomainException('卡池类型无效');
    }

    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法准备目录资源校验');
    }
    $stmt->bind_param('i', $resourceId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法校验目录资源');
    }
    $result = $stmt->get_result();
    $resource = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$resource) {
        throw new DomainException('所选资源不存在、已停用或不是公共资源');
    }

    return $resource;
}

/**
 * 校验卡池达到发布条件 / Validates that a pool is publishable
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param array $pool 卡池数据 / Pool row
 * @return void
 */
function adminPoolValidatePublishable($db, array $pool) {
    CardPoolService::normalizePoolCostBundle(
        (string) $pool['pool_type'],
        (string) $pool['cost_json']
    );
    CardPoolService::normalizeAllowedCounts(
        (string) $pool['allowed_counts_json']
    );
    if (!empty($pool['starts_at'])
        && !empty($pool['ends_at'])
        && (string) $pool['starts_at'] >= (string) $pool['ends_at']) {
        throw new DomainException('结束时间必须晚于开放时间');
    }

    $poolId = (int) $pool['pool_id'];
    if ($pool['pool_type'] === 'general') {
        $query = "SELECT entry.weight,
                         g.general_id AS resource_id,
                         g.owner_id, g.is_active
                  FROM general_pool_entries entry
                  LEFT JOIN generals g
                    ON g.general_id = entry.general_id
                  WHERE entry.pool_id = ?
                  FOR UPDATE";
    } elseif ($pool['pool_type'] === 'skill') {
        $query = "SELECT entry.weight, card.card_id AS resource_id,
                         0 AS owner_id, card.is_active
                  FROM skill_pool_entries entry
                  LEFT JOIN skill_card_catalog card
                    ON card.card_id = entry.card_id
                  WHERE entry.pool_id = ?
                  FOR UPDATE";
    } else {
        throw new DomainException('卡池类型无效');
    }

    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法准备卡池发布校验');
    }
    $stmt->bind_param('i', $poolId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法校验卡池发布条件');
    }
    $result = $stmt->get_result();
    $entryCount = 0;
    $hasInvalidEntry = false;
    while ($result && ($entry = $result->fetch_assoc())) {
        $entryCount++;
        if ((int) $entry['weight'] <= 0
            || empty($entry['resource_id'])
            || (int) $entry['is_active'] !== 1
            || ($pool['pool_type'] === 'general'
                && (int) $entry['owner_id'] !== 0)) {
            $hasInvalidEntry = true;
        }
    }
    $stmt->close();
    if ($entryCount < 1) {
        throw new DomainException('卡池至少需要一个可抽取成员');
    }
    if ($hasInvalidEntry) {
        throw new DomainException(
            '卡池包含无效权重、停用资源、缺失资源或非公共武将'
        );
    }
}

/**
 * 更新卡池修订号与审计字段 / Updates the pool revision and audit fields
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param int $poolId 卡池ID / Pool ID
 * @param int $adminId 管理员ID / Admin ID
 * @param bool $incrementRevision 是否递增修订号 / Whether to increment the revision
 * @return void
 */
function adminPoolTouch($db, $poolId, $adminId, $incrementRevision) {
    $revisionDelta = $incrementRevision ? 1 : 0;
    $query = "UPDATE card_pools
              SET revision = revision + ?, updated_by = ?,
                  updated_at = CURRENT_TIMESTAMP
              WHERE pool_id = ?";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法准备卡池审计更新');
    }
    $stmt->bind_param('iii', $revisionDelta, $adminId, $poolId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法更新卡池修订信息');
    }
    $stmt->close();
}

/**
 * 比较元数据是否发生变化 / Compares whether metadata changed
 *
 * @param array $pool 原卡池 / Existing pool
 * @param array $data 新数据 / New data
 * @return bool 是否变化 / Whether values changed
 */
function adminPoolMetadataChanged(array $pool, array $data) {
    foreach ([
        'name',
        'description',
        'cost_json',
        'allowed_counts_json',
        'starts_at',
        'ends_at'
    ] as $field) {
        $oldValue = $pool[$field] === null ? null : (string) $pool[$field];
        $newValue = $data[$field] === null ? null : (string) $data[$field];
        if ($oldValue !== $newValue) {
            return true;
        }
    }

    return (int) $pool['sort_order'] !== (int) $data['sort_order'];
}

/**
 * 读取一个成员配置 / Loads one stored pool entry
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param string $poolType 卡池类型 / Pool type
 * @param int $poolId 卡池ID / Pool ID
 * @param int $resourceId 资源ID / Resource ID
 * @return array|null 成员数据 / Entry row
 */
function adminPoolLoadEntryForUpdate(
    $db,
    $poolType,
    $poolId,
    $resourceId
) {
    if ($poolType === 'general') {
        $query = "SELECT weight, is_featured
                  FROM general_pool_entries
                  WHERE pool_id = ? AND general_id = ?
                  LIMIT 1 FOR UPDATE";
    } elseif ($poolType === 'skill') {
        $query = "SELECT weight, is_featured
                  FROM skill_pool_entries
                  WHERE pool_id = ? AND card_id = ?
                  LIMIT 1 FOR UPDATE";
    } else {
        throw new DomainException('卡池类型无效');
    }

    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法准备卡池成员锁定');
    }
    $stmt->bind_param('ii', $poolId, $resourceId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法锁定卡池成员');
    }
    $result = $stmt->get_result();
    $entry = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $entry ?: null;
}

/**
 * 读取卡池成员数量 / Counts entries in one pool
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param string $poolType 卡池类型 / Pool type
 * @param int $poolId 卡池ID / Pool ID
 * @return int 成员数量 / Entry count
 */
function adminPoolEntryCount($db, $poolType, $poolId) {
    if ($poolType === 'general') {
        $query = 'SELECT COUNT(*) AS total FROM general_pool_entries WHERE pool_id = ?';
    } elseif ($poolType === 'skill') {
        $query = 'SELECT COUNT(*) AS total FROM skill_pool_entries WHERE pool_id = ?';
    } else {
        throw new DomainException('卡池类型无效');
    }

    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法准备卡池成员计数');
    }
    $stmt->bind_param('i', $poolId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法读取卡池成员数量');
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int) $row['total'] : 0;
}

/**
 * 将数据库时间转换为datetime-local值 / Converts a database datetime for datetime-local
 *
 * @param mixed $value 数据库时间 / Database datetime
 * @return string 表单时间 / Form datetime
 */
function adminPoolDateTimeLocal($value) {
    if ($value === null || $value === '') {
        return '';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
}

/**
 * 美化JSON以便编辑 / Pretty-prints JSON for editing
 *
 * @param mixed $value JSON文本 / JSON text
 * @return string 美化文本 / Pretty JSON
 */
function adminPoolPrettyJson($value) {
    $decoded = json_decode((string) $value, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return (string) $value;
    }
    $encoded = json_encode(
        $decoded,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    return $encoded === false ? (string) $value : $encoded;
}

/**
 * 保存成功消息并重定向 / Stores a success message and redirects
 *
 * @param string $message 成功消息 / Success message
 * @param int|null $poolId 卡池ID / Pool ID
 * @return void
 */
function adminPoolRedirect($message, $poolId = null) {
    $_SESSION['admin_card_pool_flash'] = ['success' => $message];
    $location = 'card_pools.php';
    if ($poolId !== null && $poolId > 0) {
        $location .= '?pool_id=' . (int) $poolId;
    }
    header('Location: ' . $location);
    exit;
}

// 所有写操作都使用CSRF、行锁与事务 / All mutations use CSRF, row locks, and transactions
if (isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        http_response_code(403);
        $error = '请求安全令牌无效，请刷新页面后重试';
    } else {
        $transactionOpen = false;
        $action = adminPoolScalar($_POST, 'action');

        try {
            if ($action === 'create_pool') {
                if (!$adminManager->hasPermission('create_card_pools')) {
                    throw new DomainException('您没有权限创建卡池草稿');
                }
                $data = adminPoolValidateMetadata($_POST, true);
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始卡池创建事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);

                $query = "SELECT pool_id
                          FROM card_pools
                          WHERE pool_code = ?
                          LIMIT 1 FOR UPDATE";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new RuntimeException('无法准备卡池代码校验');
                }
                $stmt->bind_param('s', $data['pool_code']);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('无法校验卡池代码');
                }
                $existingCode = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($existingCode) {
                    throw new DomainException('卡池代码已存在');
                }

                $adminId = (int) $_SESSION['user_id'];
                $query = "INSERT INTO card_pools
                            (pool_code, pool_type, name, description,
                             cost_json, allowed_counts_json, status,
                             starts_at, ends_at, sort_order, revision,
                             created_by, updated_by)
                          VALUES
                            (?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, 1, ?, ?)";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new RuntimeException('无法准备卡池创建操作');
                }
                $stmt->bind_param(
                    'ssssssssiii',
                    $data['pool_code'],
                    $data['pool_type'],
                    $data['name'],
                    $data['description'],
                    $data['cost_json'],
                    $data['allowed_counts_json'],
                    $data['starts_at'],
                    $data['ends_at'],
                    $data['sort_order'],
                    $adminId,
                    $adminId
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('卡池创建失败');
                }
                $poolId = (int) $db->insert_id;
                $stmt->close();
                if (!$db->commit()) {
                    throw new RuntimeException('卡池创建事务提交失败');
                }
                $transactionOpen = false;
                $user->logAdminAction(
                    'create_card_pool',
                    'card_pool',
                    $poolId,
                    json_encode(
                        [
                            'pool_code' => $data['pool_code'],
                            'pool_type' => $data['pool_type']
                        ],
                        JSON_UNESCAPED_UNICODE
                    )
                );
                adminPoolRedirect('卡池草稿创建成功', $poolId);
            } elseif ($action === 'update_pool') {
                $poolId = adminPoolPositiveId(
                    $_POST['pool_id'] ?? null,
                    '卡池ID'
                );
                $data = adminPoolValidateMetadata($_POST, false);
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始卡池更新事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);
                $pool = adminPoolLoadForUpdate($db, $poolId);
                adminPoolRequireEditable($adminManager, $pool);
                CardPoolService::normalizePoolCostBundle(
                    (string) $pool['pool_type'],
                    $data['cost_json']
                );
                $changed = adminPoolMetadataChanged($pool, $data);

                if ($changed) {
                    $adminId = (int) $_SESSION['user_id'];
                    $revisionDelta = $pool['status'] === 'published' ? 1 : 0;
                    $query = "UPDATE card_pools
                              SET name = ?, description = ?, cost_json = ?,
                                  allowed_counts_json = ?, starts_at = ?,
                                  ends_at = ?, sort_order = ?,
                                  revision = revision + ?, updated_by = ?,
                                  updated_at = CURRENT_TIMESTAMP
                              WHERE pool_id = ?";
                    $stmt = $db->prepare($query);
                    if (!$stmt) {
                        throw new RuntimeException('无法准备卡池更新操作');
                    }
                    $stmt->bind_param(
                        'ssssssiiii',
                        $data['name'],
                        $data['description'],
                        $data['cost_json'],
                        $data['allowed_counts_json'],
                        $data['starts_at'],
                        $data['ends_at'],
                        $data['sort_order'],
                        $revisionDelta,
                        $adminId,
                        $poolId
                    );
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new RuntimeException('卡池元数据更新失败');
                    }
                    $stmt->close();
                }

                if (!$db->commit()) {
                    throw new RuntimeException('卡池更新事务提交失败');
                }
                $transactionOpen = false;
                if ($changed) {
                    $user->logAdminAction(
                        'update_card_pool',
                        'card_pool',
                        $poolId,
                        'Updated metadata; previous revision '
                        . (int) $pool['revision']
                    );
                    adminPoolRedirect('卡池元数据更新成功', $poolId);
                }
                adminPoolRedirect('卡池元数据没有变化', $poolId);
            } elseif ($action === 'upsert_entry') {
                $poolId = adminPoolPositiveId(
                    $_POST['pool_id'] ?? null,
                    '卡池ID'
                );
                $resourceId = adminPoolPositiveId(
                    $_POST['resource_id'] ?? null,
                    '资源ID'
                );
                $weight = adminPoolBoundedInteger(
                    $_POST['weight'] ?? null,
                    1,
                    2000000000,
                    '权重'
                );
                $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始成员更新事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);
                $pool = adminPoolLoadForUpdate($db, $poolId);
                adminPoolRequireEditable($adminManager, $pool);
                $resource = adminPoolLoadActiveResource(
                    $db,
                    $pool['pool_type'],
                    $resourceId
                );
                $existing = adminPoolLoadEntryForUpdate(
                    $db,
                    $pool['pool_type'],
                    $poolId,
                    $resourceId
                );
                $changed = !$existing
                    || (int) $existing['weight'] !== $weight
                    || (int) $existing['is_featured'] !== $isFeatured;

                if ($changed && $pool['pool_type'] === 'general') {
                    $query = "INSERT INTO general_pool_entries
                                (pool_id, general_id, weight, is_featured)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE
                                weight = VALUES(weight),
                                is_featured = VALUES(is_featured),
                                updated_at = CURRENT_TIMESTAMP";
                } elseif ($changed && $pool['pool_type'] === 'skill') {
                    $query = "INSERT INTO skill_pool_entries
                                (pool_id, card_id, weight, is_featured)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE
                                weight = VALUES(weight),
                                is_featured = VALUES(is_featured),
                                updated_at = CURRENT_TIMESTAMP";
                } else {
                    $query = null;
                }

                if ($query !== null) {
                    $stmt = $db->prepare($query);
                    if (!$stmt) {
                        throw new RuntimeException('无法准备卡池成员保存操作');
                    }
                    $stmt->bind_param(
                        'iiii',
                        $poolId,
                        $resourceId,
                        $weight,
                        $isFeatured
                    );
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new RuntimeException('卡池成员保存失败');
                    }
                    $stmt->close();
                    adminPoolTouch(
                        $db,
                        $poolId,
                        (int) $_SESSION['user_id'],
                        $pool['status'] === 'published'
                    );
                    if ($pool['status'] === 'published') {
                        adminPoolValidatePublishable($db, $pool);
                    }
                }

                if (!$db->commit()) {
                    throw new RuntimeException('成员更新事务提交失败');
                }
                $transactionOpen = false;
                if ($changed) {
                    $user->logAdminAction(
                        $existing ? 'update_card_pool_entry' : 'add_card_pool_entry',
                        'card_pool',
                        $poolId,
                        json_encode(
                            [
                                'resource_id' => $resourceId,
                                'resource_name' => $resource['name'],
                                'weight' => $weight,
                                'is_featured' => $isFeatured
                            ],
                            JSON_UNESCAPED_UNICODE
                        )
                    );
                    adminPoolRedirect('卡池成员保存成功', $poolId);
                }
                adminPoolRedirect('卡池成员没有变化', $poolId);
            } elseif ($action === 'remove_entry') {
                $poolId = adminPoolPositiveId(
                    $_POST['pool_id'] ?? null,
                    '卡池ID'
                );
                $resourceId = adminPoolPositiveId(
                    $_POST['resource_id'] ?? null,
                    '资源ID'
                );
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始成员移除事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);
                $pool = adminPoolLoadForUpdate($db, $poolId);
                adminPoolRequireEditable($adminManager, $pool);
                $existing = adminPoolLoadEntryForUpdate(
                    $db,
                    $pool['pool_type'],
                    $poolId,
                    $resourceId
                );
                if (!$existing) {
                    throw new DomainException('卡池成员不存在');
                }
                if ($pool['status'] === 'published'
                    && adminPoolEntryCount(
                        $db,
                        $pool['pool_type'],
                        $poolId
                    ) <= 1) {
                    throw new DomainException('已发布卡池必须保留至少一个成员');
                }

                if ($pool['pool_type'] === 'general') {
                    $query = "DELETE FROM general_pool_entries
                              WHERE pool_id = ? AND general_id = ?";
                } elseif ($pool['pool_type'] === 'skill') {
                    $query = "DELETE FROM skill_pool_entries
                              WHERE pool_id = ? AND card_id = ?";
                } else {
                    throw new DomainException('卡池类型无效');
                }
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new RuntimeException('无法准备卡池成员移除操作');
                }
                $stmt->bind_param('ii', $poolId, $resourceId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('卡池成员移除失败');
                }
                $affectedRows = $stmt->affected_rows;
                $stmt->close();
                if ($affectedRows !== 1) {
                    throw new DomainException('卡池成员不存在');
                }

                adminPoolTouch(
                    $db,
                    $poolId,
                    (int) $_SESSION['user_id'],
                    $pool['status'] === 'published'
                );
                if ($pool['status'] === 'published') {
                    adminPoolValidatePublishable($db, $pool);
                }
                if (!$db->commit()) {
                    throw new RuntimeException('成员移除事务提交失败');
                }
                $transactionOpen = false;
                $user->logAdminAction(
                    'remove_card_pool_entry',
                    'card_pool',
                    $poolId,
                    json_encode(
                        ['resource_id' => $resourceId],
                        JSON_UNESCAPED_UNICODE
                    )
                );
                adminPoolRedirect('卡池成员已移除，目录资源保持不变', $poolId);
            } elseif ($action === 'publish_pool') {
                if (!$adminManager->hasPermission('publish_card_pools')) {
                    throw new DomainException('您没有权限发布卡池');
                }
                $poolId = adminPoolPositiveId(
                    $_POST['pool_id'] ?? null,
                    '卡池ID'
                );
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始卡池发布事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);
                $pool = adminPoolLoadForUpdate($db, $poolId);
                if ($pool['status'] === 'archived') {
                    throw new DomainException('归档卡池必须先恢复为草稿');
                }
                if ($pool['status'] === 'published') {
                    throw new DomainException('卡池已经发布');
                }
                adminPoolValidatePublishable($db, $pool);

                $adminId = (int) $_SESSION['user_id'];
                $query = "UPDATE card_pools
                          SET status = 'published', revision = revision + 1,
                              updated_by = ?, updated_at = CURRENT_TIMESTAMP
                          WHERE pool_id = ?";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new RuntimeException('无法准备卡池发布操作');
                }
                $stmt->bind_param('ii', $adminId, $poolId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('卡池发布失败');
                }
                $stmt->close();
                if (!$db->commit()) {
                    throw new RuntimeException('卡池发布事务提交失败');
                }
                $transactionOpen = false;
                $user->logAdminAction(
                    'publish_card_pool',
                    'card_pool',
                    $poolId,
                    'Published revision ' . ((int) $pool['revision'] + 1)
                );
                adminPoolRedirect('卡池发布成功', $poolId);
            } elseif ($action === 'archive_pool') {
                if (!$adminManager->hasPermission('archive_card_pools')) {
                    throw new DomainException('您没有权限归档卡池');
                }
                $poolId = adminPoolPositiveId(
                    $_POST['pool_id'] ?? null,
                    '卡池ID'
                );
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始卡池归档事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);
                $pool = adminPoolLoadForUpdate($db, $poolId);
                if ($pool['status'] === 'archived') {
                    throw new DomainException('卡池已经归档');
                }
                $adminId = (int) $_SESSION['user_id'];
                $query = "UPDATE card_pools
                          SET status = 'archived', revision = revision + 1,
                              updated_by = ?, updated_at = CURRENT_TIMESTAMP
                          WHERE pool_id = ?";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new RuntimeException('无法准备卡池归档操作');
                }
                $stmt->bind_param('ii', $adminId, $poolId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('卡池归档失败');
                }
                $stmt->close();
                if (!$db->commit()) {
                    throw new RuntimeException('卡池归档事务提交失败');
                }
                $transactionOpen = false;
                $user->logAdminAction(
                    'archive_card_pool',
                    'card_pool',
                    $poolId,
                    'Archived from status: ' . $pool['status']
                );
                adminPoolRedirect('卡池已归档', $poolId);
            } elseif ($action === 'restore_pool') {
                if (!$adminManager->hasPermission('archive_card_pools')) {
                    throw new DomainException('您没有权限恢复卡池');
                }
                $poolId = adminPoolPositiveId(
                    $_POST['pool_id'] ?? null,
                    '卡池ID'
                );
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始卡池恢复事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);
                $pool = adminPoolLoadForUpdate($db, $poolId);
                if ($pool['status'] !== 'archived') {
                    throw new DomainException('只有归档卡池可以恢复');
                }
                $adminId = (int) $_SESSION['user_id'];
                $query = "UPDATE card_pools
                          SET status = 'draft', revision = revision + 1,
                              updated_by = ?, updated_at = CURRENT_TIMESTAMP
                          WHERE pool_id = ?";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new RuntimeException('无法准备卡池恢复操作');
                }
                $stmt->bind_param('ii', $adminId, $poolId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('卡池恢复失败');
                }
                $stmt->close();
                if (!$db->commit()) {
                    throw new RuntimeException('卡池恢复事务提交失败');
                }
                $transactionOpen = false;
                $user->logAdminAction(
                    'restore_card_pool',
                    'card_pool',
                    $poolId,
                    'Restored as draft'
                );
                adminPoolRedirect('卡池已恢复为草稿', $poolId);
            } else {
                throw new InvalidArgumentException('无法识别该操作');
            }
        } catch (DomainException $exception) {
            if ($transactionOpen) {
                $db->rollback();
            }
            $error = $exception->getMessage();
        } catch (InvalidArgumentException $exception) {
            if ($transactionOpen) {
                $db->rollback();
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $db->rollback();
            }
            error_log(
                'admin/card_pools.php mutation failed: '
                . $exception->getMessage()
            );
            $error = '卡池操作失败，请检查数据库迁移与数据状态后重试';
        }
    }
}

$typeFilter = strtolower(trim(adminPoolScalar($_GET, 'type')));
if (!in_array($typeFilter, ['', 'general', 'skill'], true)) {
    $typeFilter = '';
}
$statusFilter = strtolower(trim(adminPoolScalar($_GET, 'status')));
if (!in_array($statusFilter, ['', 'draft', 'published', 'archived'], true)) {
    $statusFilter = '';
}

$poolList = [];
$listQuery = "SELECT pool.pool_id, pool.pool_code, pool.pool_type, pool.name,
                     pool.status, pool.starts_at, pool.ends_at, pool.sort_order,
                     pool.revision, pool.updated_at,
                     CASE pool.pool_type
                         WHEN 'general' THEN (
                             SELECT COUNT(*)
                             FROM general_pool_entries general_entry
                             WHERE general_entry.pool_id = pool.pool_id
                         )
                         ELSE (
                             SELECT COUNT(*)
                             FROM skill_pool_entries skill_entry
                             WHERE skill_entry.pool_id = pool.pool_id
                         )
                     END AS entry_count
              FROM card_pools pool";
$listConditions = [];
if ($typeFilter !== '') {
    $listConditions[] = 'pool.pool_type = ?';
}
if ($statusFilter !== '') {
    $listConditions[] = 'pool.status = ?';
}
if (!empty($listConditions)) {
    $listQuery .= ' WHERE ' . implode(' AND ', $listConditions);
}
$listQuery .= " ORDER BY
                    FIELD(pool.status, 'published', 'draft', 'archived'),
                    pool.sort_order, pool.pool_id";
$stmt = $db->prepare($listQuery);
if ($stmt) {
    if ($typeFilter !== '' && $statusFilter !== '') {
        $stmt->bind_param('ss', $typeFilter, $statusFilter);
    } elseif ($typeFilter !== '') {
        $stmt->bind_param('s', $typeFilter);
    } elseif ($statusFilter !== '') {
        $stmt->bind_param('s', $statusFilter);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $poolList[] = $row;
        }
    } elseif ($error === '') {
        $error = '无法读取卡池列表';
    }
    $stmt->close();
} elseif ($error === '') {
    $error = '无法读取卡池列表，请先运行数据库迁移';
}

$selectedPoolId = 0;
if (isset($_GET['pool_id'])) {
    $selectedPoolId = filter_var(
        is_scalar($_GET['pool_id']) ? $_GET['pool_id'] : null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($selectedPoolId === false) {
        $selectedPoolId = 0;
        if ($error === '') {
            $error = '卡池ID无效';
        }
    }
}

$selectedPool = null;
$poolEntries = [];
$resourceChoices = [];
$totalWeight = 0;
if ($selectedPoolId > 0) {
    $query = "SELECT pool_id, pool_code, pool_type, name, description,
                     cost_json, allowed_counts_json, status, starts_at,
                     ends_at, sort_order, revision, created_by, updated_by,
                     created_at, updated_at
              FROM card_pools
              WHERE pool_id = ?
              LIMIT 1";
    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param('i', $selectedPoolId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $selectedPool = $result ? $result->fetch_assoc() : null;
        }
        $stmt->close();
    }
    if (!$selectedPool && $error === '') {
        $error = '卡池不存在';
    }
}

if ($selectedPool) {
    if ($selectedPool['pool_type'] === 'general') {
        $query = "SELECT entry.general_id AS resource_id, entry.weight,
                         entry.is_featured, entry.created_at, entry.updated_at,
                         g.name, g.rarity, g.element,
                         g.is_active, g.owner_id
                  FROM general_pool_entries entry
                  LEFT JOIN generals g
                    ON g.general_id = entry.general_id
                  WHERE entry.pool_id = ?
                  ORDER BY entry.is_featured DESC,
                           FIELD(g.rarity, 'P', 'SS', 'S', 'A', 'B'),
                           g.name, entry.general_id";
        $choiceQuery = "SELECT general_id AS resource_id, name, rarity, element
                        FROM generals
                        WHERE owner_id = 0 AND is_active = 1
                        ORDER BY FIELD(rarity, 'P', 'SS', 'S', 'A', 'B'),
                                 name, general_id";
    } else {
        $query = "SELECT entry.card_id AS resource_id, entry.weight,
                         entry.is_featured, entry.created_at, entry.updated_at,
                         card.name, card.rarity, card.element,
                         card.is_active, 0 AS owner_id
                  FROM skill_pool_entries entry
                  LEFT JOIN skill_card_catalog card
                    ON card.card_id = entry.card_id
                  WHERE entry.pool_id = ?
                  ORDER BY entry.is_featured DESC,
                           FIELD(card.rarity, 'P', 'SS', 'S', 'A', 'B'),
                           card.name, entry.card_id";
        $choiceQuery = "SELECT card_id AS resource_id, name, rarity, element
                        FROM skill_card_catalog
                        WHERE is_active = 1
                        ORDER BY FIELD(rarity, 'P', 'SS', 'S', 'A', 'B'),
                                 name, card_id";
    }

    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param('i', $selectedPoolId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $row['weight'] = (int) $row['weight'];
                $totalWeight += max(0, $row['weight']);
                $poolEntries[] = $row;
            }
        } elseif ($error === '') {
            $error = '无法读取卡池成员';
        }
        $stmt->close();
    }

    $stmt = $db->prepare($choiceQuery);
    if ($stmt) {
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $resourceChoices[] = $row;
            }
        } elseif ($error === '') {
            $error = '无法读取可用目录资源';
        }
        $stmt->close();
    }
}

$canCreate = $adminManager->hasPermission('create_card_pools');
$canEditSelected = $selectedPool
    && $selectedPool['status'] !== 'archived'
    && (
        ($selectedPool['status'] === 'draft'
            && $adminManager->hasPermission('edit_card_pools'))
        || ($selectedPool['status'] === 'published'
            && $adminManager->hasPermission('publish_card_pools'))
    );
$canPublish = $adminManager->hasPermission('publish_card_pools');
$canArchive = $adminManager->hasPermission('archive_card_pools');
$pageTitle = '卡池管理';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #f4f6f8; color: #263746; }
        .pool-admin { max-width: 1500px; margin: 0 auto; padding: 20px; }
        .pool-header {
            display: flex; justify-content: space-between; align-items: center;
            gap: 16px; padding: 22px; margin-bottom: 20px; color: #fff;
            border-radius: 10px; background: linear-gradient(135deg, #8e44ad, #4a235a);
        }
        .pool-header h1 { margin: 0 0 6px; font-size: 26px; }
        .pool-header p { margin: 0; opacity: .9; }
        .back-link {
            flex: 0 0 auto; padding: 9px 14px; color: #fff;
            text-decoration: none; border-radius: 6px;
            background: rgba(255,255,255,.18);
        }
        .notice {
            padding: 13px 16px; margin-bottom: 16px; border-radius: 7px;
            border: 1px solid transparent;
        }
        .notice.success { color: #186a3b; background: #eafaf1; border-color: #a9dfbf; }
        .notice.error { color: #922b21; background: #fdedec; border-color: #f5b7b1; }
        .admin-panel {
            padding: 20px; margin-bottom: 20px; background: #fff;
            border: 1px solid #e2e8ee; border-radius: 9px;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }
        .admin-panel h2, .admin-panel h3 { margin: 0 0 15px; color: #2c3e50; }
        .muted { color: #6f7f8d; }
        .help { margin: 7px 0 0; color: #72808e; font-size: 12px; line-height: 1.5; }
        .form-grid {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }
        .form-group { min-width: 0; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label {
            display: block; margin-bottom: 6px; color: #34495e; font-weight: 700;
        }
        input[type="text"], input[type="number"], input[type="datetime-local"],
        select, textarea {
            width: 100%; box-sizing: border-box; padding: 10px 11px;
            color: #263746; background: #fff; border: 1px solid #ccd5dd;
            border-radius: 6px; font: inherit;
        }
        textarea { min-height: 92px; resize: vertical; font-family: Consolas, monospace; }
        .button-row { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 16px; }
        .button {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 9px 14px; color: #fff; text-decoration: none;
            background: #8e44ad; border: 0; border-radius: 6px;
            cursor: pointer; font: inherit; font-weight: 700;
        }
        .button.secondary { background: #607d8b; }
        .button.success { background: #239b56; }
        .button.warning { background: #d68910; }
        .button.danger { background: #c0392b; }
        .button.small { padding: 7px 10px; font-size: 13px; }
        .layout {
            display: grid; grid-template-columns: minmax(330px, .85fr) minmax(0, 2fr);
            gap: 20px; align-items: start;
        }
        .filter-row {
            display: grid; grid-template-columns: 1fr 1fr auto; gap: 9px;
            align-items: end; margin-bottom: 14px;
        }
        .pool-list { display: grid; gap: 9px; }
        .pool-list-item {
            display: block; padding: 13px; color: #2c3e50;
            text-decoration: none; border: 1px solid #dfe6ec;
            border-radius: 7px; background: #fbfcfd;
        }
        .pool-list-item:hover, .pool-list-item.active {
            border-color: #8e44ad; background: #f7f0fa;
        }
        .pool-list-title {
            display: flex; justify-content: space-between; gap: 10px;
            margin-bottom: 6px; font-weight: 700;
        }
        .pool-list-meta { color: #72808e; font-size: 12px; line-height: 1.6; }
        .badge {
            display: inline-block; padding: 3px 8px; border-radius: 999px;
            color: #fff; background: #7f8c8d; font-size: 11px; white-space: nowrap;
        }
        .badge.draft { background: #d68910; }
        .badge.published { background: #239b56; }
        .badge.archived { background: #7f8c8d; }
        .badge.featured { background: #8e44ad; }
        .badge.invalid { background: #c0392b; }
        .identity {
            display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px; padding: 12px; margin-bottom: 16px;
            background: #f6f8fa; border-radius: 7px;
        }
        .identity strong { display: block; margin-top: 3px; overflow-wrap: anywhere; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 10px 8px; text-align: left; vertical-align: middle;
            border-bottom: 1px solid #e6ebef;
        }
        th { color: #526270; font-size: 13px; background: #f8fafb; }
        .entry-edit {
            display: grid; grid-template-columns: 110px auto auto; gap: 7px;
            align-items: center;
        }
        .entry-edit input[type="number"] { min-width: 90px; }
        .entry-edit label { white-space: nowrap; font-size: 12px; }
        .inline-form { display: inline; }
        .probability { font-variant-numeric: tabular-nums; font-weight: 700; }
        .empty-state { padding: 22px; text-align: center; color: #758594; }
        @media (max-width: 1000px) {
            .layout { grid-template-columns: 1fr; }
            .identity { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 650px) {
            .pool-header { align-items: flex-start; flex-direction: column; }
            .form-grid, .filter-row, .identity { grid-template-columns: 1fr; }
            .form-group.full { grid-column: auto; }
            .entry-edit { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="pool-admin">
        <header class="pool-header">
            <div>
                <h1>🎴 卡池与出率</h1>
                <p>目录资源与卡池成员相互独立；从卡池移除不会停用或删除卡片。</p>
            </div>
            <a href="resources.php" class="back-link">← 返回资源层</a>
        </header>

        <?php if ($success !== ''): ?>
            <div class="notice success"><?php echo escapeHtml($success); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="notice error"><?php echo escapeHtml($error); ?></div>
        <?php endif; ?>

        <?php if ($canCreate): ?>
            <details class="admin-panel"<?php echo empty($poolList) ? ' open' : ''; ?>>
                <summary><strong>创建卡池草稿</strong></summary>
                <form method="post" action="card_pools.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_pool">
                    <div class="form-grid" style="margin-top: 16px;">
                        <div class="form-group">
                            <label for="create_pool_code">卡池代码</label>
                            <input id="create_pool_code" name="pool_code" type="text"
                                   maxlength="64" required placeholder="general_standard">
                            <p class="help">创建后不可修改；用于运行时稳定引用。</p>
                        </div>
                        <div class="form-group">
                            <label for="create_pool_type">卡池类型</label>
                            <select id="create_pool_type" name="pool_type" required>
                                <option value="general">武将卡池</option>
                                <option value="skill">技能卡池</option>
                            </select>
                            <p class="help">创建后不可修改，防止成员表与历史记录错位。</p>
                        </div>
                        <div class="form-group full">
                            <label for="create_name">名称</label>
                            <input id="create_name" name="name" type="text"
                                   maxlength="100" required>
                        </div>
                        <div class="form-group full">
                            <label for="create_description">说明</label>
                            <textarea id="create_description" name="description"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="create_cost">每抽成本（JSON对象）</label>
                            <textarea id="create_cost" name="cost_json">{"bright": 100}</textarea>
                            <p class="help" id="create_cost_help">
                                武将卡池只能使用 bright（亮晶晶）。
                            </p>
                        </div>
                        <div class="form-group">
                            <label for="create_counts">允许抽取次数（JSON数组）</label>
                            <textarea id="create_counts" name="allowed_counts_json">[1, 10]</textarea>
                            <p class="help">可配置1至100的唯一正整数，例如单抽与十连。</p>
                        </div>
                        <div class="form-group">
                            <label for="create_starts">开放时间（可选）</label>
                            <input id="create_starts" name="starts_at" type="datetime-local">
                        </div>
                        <div class="form-group">
                            <label for="create_ends">结束时间（可选）</label>
                            <input id="create_ends" name="ends_at" type="datetime-local">
                        </div>
                        <div class="form-group">
                            <label for="create_sort">排序值</label>
                            <input id="create_sort" name="sort_order" type="number"
                                   min="-100000" max="100000" value="0" required>
                        </div>
                    </div>
                    <div class="button-row">
                        <button class="button" type="submit">创建草稿</button>
                    </div>
                </form>
            </details>
        <?php endif; ?>

        <div class="layout">
            <aside class="admin-panel">
                <h2>卡池列表</h2>
                <form class="filter-row" method="get" action="card_pools.php">
                    <div class="form-group">
                        <label for="filter_type">类型</label>
                        <select id="filter_type" name="type">
                            <option value="">全部</option>
                            <option value="general"<?php echo $typeFilter === 'general' ? ' selected' : ''; ?>>武将</option>
                            <option value="skill"<?php echo $typeFilter === 'skill' ? ' selected' : ''; ?>>技能</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="filter_status">状态</label>
                        <select id="filter_status" name="status">
                            <option value="">全部</option>
                            <option value="draft"<?php echo $statusFilter === 'draft' ? ' selected' : ''; ?>>草稿</option>
                            <option value="published"<?php echo $statusFilter === 'published' ? ' selected' : ''; ?>>已发布</option>
                            <option value="archived"<?php echo $statusFilter === 'archived' ? ' selected' : ''; ?>>已归档</option>
                        </select>
                    </div>
                    <button class="button secondary small" type="submit">筛选</button>
                </form>

                <div class="pool-list">
                    <?php foreach ($poolList as $pool): ?>
                        <?php
                        $listUrl = 'card_pools.php?pool_id=' . (int) $pool['pool_id'];
                        if ($typeFilter !== '') {
                            $listUrl .= '&type=' . rawurlencode($typeFilter);
                        }
                        if ($statusFilter !== '') {
                            $listUrl .= '&status=' . rawurlencode($statusFilter);
                        }
                        ?>
                        <a class="pool-list-item<?php echo (int) $pool['pool_id'] === $selectedPoolId ? ' active' : ''; ?>"
                           href="<?php echo escapeHtml($listUrl); ?>">
                            <div class="pool-list-title">
                                <span><?php echo escapeHtml($pool['name']); ?></span>
                                <span class="badge <?php echo escapeHtml($pool['status']); ?>">
                                    <?php
                                    echo $pool['status'] === 'published'
                                        ? '已发布'
                                        : ($pool['status'] === 'draft' ? '草稿' : '已归档');
                                    ?>
                                </span>
                            </div>
                            <div class="pool-list-meta">
                                <?php echo $pool['pool_type'] === 'general' ? '武将' : '技能'; ?>
                                · <?php echo escapeHtml($pool['pool_code']); ?>
                                · <?php echo number_format((int) $pool['entry_count']); ?> 项
                                · 修订 <?php echo number_format((int) $pool['revision']); ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($poolList)): ?>
                        <div class="empty-state">当前筛选下没有卡池。</div>
                    <?php endif; ?>
                </div>
            </aside>

            <section>
                <?php if (!$selectedPool): ?>
                    <div class="admin-panel empty-state">
                        从左侧选择卡池以编辑元数据、成员权重与发布状态。
                    </div>
                <?php else: ?>
                    <div class="admin-panel">
                        <div class="identity">
                            <div>
                                <span class="muted">卡池代码（不可变）</span>
                                <strong><?php echo escapeHtml($selectedPool['pool_code']); ?></strong>
                            </div>
                            <div>
                                <span class="muted">类型（不可变）</span>
                                <strong><?php echo $selectedPool['pool_type'] === 'general' ? '武将卡池' : '技能卡池'; ?></strong>
                            </div>
                            <div>
                                <span class="muted">状态</span>
                                <strong>
                                    <span class="badge <?php echo escapeHtml($selectedPool['status']); ?>">
                                        <?php
                                        echo $selectedPool['status'] === 'published'
                                            ? '已发布'
                                            : ($selectedPool['status'] === 'draft' ? '草稿' : '已归档');
                                        ?>
                                    </span>
                                </strong>
                            </div>
                            <div>
                                <span class="muted">修订号</span>
                                <strong><?php echo number_format((int) $selectedPool['revision']); ?></strong>
                            </div>
                        </div>

                        <h2>元数据与开放规则</h2>
                        <?php if ($selectedPool['status'] === 'published'): ?>
                            <p class="notice" style="background:#fff8e1;border-color:#ffe082;color:#795548;">
                                修改已发布卡池需要4级权限；实际变化会自动递增修订号。
                            </p>
                        <?php elseif ($selectedPool['status'] === 'archived'): ?>
                            <p class="notice" style="background:#f2f4f4;border-color:#d5dbdb;color:#566573;">
                                归档卡池为只读；恢复后会成为草稿，不会自动重新发布。
                            </p>
                        <?php endif; ?>
                        <form method="post" action="card_pools.php?pool_id=<?php echo (int) $selectedPool['pool_id']; ?>">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="update_pool">
                            <input type="hidden" name="pool_id"
                                   value="<?php echo (int) $selectedPool['pool_id']; ?>">
                            <div class="form-grid">
                                <div class="form-group full">
                                    <label for="edit_name">名称</label>
                                    <input id="edit_name" name="name" type="text"
                                           maxlength="100" required
                                           value="<?php echo escapeHtml($selectedPool['name']); ?>"
                                           <?php echo $canEditSelected ? '' : 'disabled'; ?>>
                                </div>
                                <div class="form-group full">
                                    <label for="edit_description">说明</label>
                                    <textarea id="edit_description" name="description"
                                              <?php echo $canEditSelected ? '' : 'disabled'; ?>><?php echo escapeHtml($selectedPool['description']); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="edit_cost">每抽成本（JSON对象）</label>
                                    <textarea id="edit_cost" name="cost_json"
                                              <?php echo $canEditSelected ? '' : 'disabled'; ?>><?php echo escapeHtml(adminPoolPrettyJson($selectedPool['cost_json'])); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="edit_counts">允许抽取次数（JSON数组）</label>
                                    <textarea id="edit_counts" name="allowed_counts_json"
                                              <?php echo $canEditSelected ? '' : 'disabled'; ?>><?php echo escapeHtml(adminPoolPrettyJson($selectedPool['allowed_counts_json'])); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="edit_starts">开放时间（可选）</label>
                                    <input id="edit_starts" name="starts_at" type="datetime-local"
                                           value="<?php echo escapeHtml(adminPoolDateTimeLocal($selectedPool['starts_at'])); ?>"
                                           <?php echo $canEditSelected ? '' : 'disabled'; ?>>
                                </div>
                                <div class="form-group">
                                    <label for="edit_ends">结束时间（可选）</label>
                                    <input id="edit_ends" name="ends_at" type="datetime-local"
                                           value="<?php echo escapeHtml(adminPoolDateTimeLocal($selectedPool['ends_at'])); ?>"
                                           <?php echo $canEditSelected ? '' : 'disabled'; ?>>
                                </div>
                                <div class="form-group">
                                    <label for="edit_sort">排序值</label>
                                    <input id="edit_sort" name="sort_order" type="number"
                                           min="-100000" max="100000" required
                                           value="<?php echo (int) $selectedPool['sort_order']; ?>"
                                           <?php echo $canEditSelected ? '' : 'disabled'; ?>>
                                </div>
                            </div>
                            <?php if ($canEditSelected): ?>
                                <div class="button-row">
                                    <button class="button" type="submit">保存元数据</button>
                                </div>
                            <?php endif; ?>
                        </form>

                        <div class="button-row">
                            <?php if ($selectedPool['status'] === 'draft' && $canPublish): ?>
                                <form class="inline-form" method="post" action="card_pools.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="publish_pool">
                                    <input type="hidden" name="pool_id"
                                           value="<?php echo (int) $selectedPool['pool_id']; ?>">
                                    <button class="button success" type="submit"
                                            onclick="return confirm('确认发布此卡池？发布前会校验全部成员。');">
                                        发布卡池
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($selectedPool['status'] !== 'archived' && $canArchive): ?>
                                <form class="inline-form" method="post" action="card_pools.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="archive_pool">
                                    <input type="hidden" name="pool_id"
                                           value="<?php echo (int) $selectedPool['pool_id']; ?>">
                                    <button class="button danger" type="submit"
                                            onclick="return confirm('确认归档此卡池？玩家将无法继续从该池抽取。');">
                                        归档卡池
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($selectedPool['status'] === 'archived' && $canArchive): ?>
                                <form class="inline-form" method="post" action="card_pools.php">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="restore_pool">
                                    <input type="hidden" name="pool_id"
                                           value="<?php echo (int) $selectedPool['pool_id']; ?>">
                                    <button class="button warning" type="submit">
                                        恢复为草稿
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="admin-panel">
                        <h2>卡池成员与有效出率</h2>
                        <p class="muted">
                            总权重 <?php echo number_format($totalWeight); ?>；
                            每项有效概率 = 该项权重 ÷ 总权重。精选标记只用于展示，不会额外改变概率。
                        </p>

                        <?php if ($canEditSelected): ?>
                            <form method="post" action="card_pools.php?pool_id=<?php echo (int) $selectedPool['pool_id']; ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="upsert_entry">
                                <input type="hidden" name="pool_id"
                                       value="<?php echo (int) $selectedPool['pool_id']; ?>">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="resource_id">
                                            <?php echo $selectedPool['pool_type'] === 'general' ? '武将卡' : '技能卡'; ?>
                                        </label>
                                        <select id="resource_id" name="resource_id" required>
                                            <option value="">请选择可用目录资源</option>
                                            <?php foreach ($resourceChoices as $choice): ?>
                                                <option value="<?php echo (int) $choice['resource_id']; ?>">
                                                    <?php
                                                    echo escapeHtml(
                                                        '[' . $choice['rarity'] . '] '
                                                        . $choice['name'] . ' · '
                                                        . $choice['element'] . ' · #'
                                                        . $choice['resource_id']
                                                    );
                                                    ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="entry_weight">整数权重</label>
                                        <input id="entry_weight" name="weight" type="number"
                                               min="1" max="2000000000" value="100" required>
                                    </div>
                                    <div class="form-group full">
                                        <label>
                                            <input name="is_featured" type="checkbox" value="1">
                                            标记为精选展示
                                        </label>
                                    </div>
                                </div>
                                <div class="button-row">
                                    <button class="button" type="submit">增加或更新成员</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="table-wrap" style="margin-top: 18px;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>资源</th>
                                        <th>稀有度 / 元素</th>
                                        <th>权重</th>
                                        <th>有效概率</th>
                                        <th>状态</th>
                                        <?php if ($canEditSelected): ?>
                                            <th>编辑</th>
                                            <th>移除</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($poolEntries as $entry): ?>
                                        <?php
                                        $resourceValid = !empty($entry['name'])
                                            && (int) $entry['is_active'] === 1
                                            && (
                                                $selectedPool['pool_type'] !== 'general'
                                                || (int) $entry['owner_id'] === 0
                                            );
                                        $probability = $totalWeight > 0
                                            ? ((int) $entry['weight'] * 100 / $totalWeight)
                                            : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <?php echo escapeHtml($entry['name'] ?: '资源已删除'); ?>
                                                </strong>
                                                <div class="muted">#<?php echo (int) $entry['resource_id']; ?></div>
                                            </td>
                                            <td>
                                                <?php echo escapeHtml(($entry['rarity'] ?: '—') . ' / ' . ($entry['element'] ?: '—')); ?>
                                            </td>
                                            <td><?php echo number_format((int) $entry['weight']); ?></td>
                                            <td class="probability">
                                                <?php echo number_format($probability, 6); ?>%
                                            </td>
                                            <td>
                                                <?php if ((int) $entry['is_featured'] === 1): ?>
                                                    <span class="badge featured">精选</span>
                                                <?php endif; ?>
                                                <?php if (!$resourceValid || (int) $entry['weight'] <= 0): ?>
                                                    <span class="badge invalid">发布无效</span>
                                                <?php else: ?>
                                                    <span class="badge published">可用</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($canEditSelected): ?>
                                                <td>
                                                    <form class="entry-edit" method="post"
                                                          action="card_pools.php?pool_id=<?php echo (int) $selectedPool['pool_id']; ?>">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="action" value="upsert_entry">
                                                        <input type="hidden" name="pool_id"
                                                               value="<?php echo (int) $selectedPool['pool_id']; ?>">
                                                        <input type="hidden" name="resource_id"
                                                               value="<?php echo (int) $entry['resource_id']; ?>">
                                                        <input name="weight" type="number"
                                                               min="1" max="2000000000" required
                                                               value="<?php echo (int) $entry['weight']; ?>">
                                                        <label>
                                                            <input name="is_featured" type="checkbox" value="1"
                                                                <?php echo (int) $entry['is_featured'] === 1 ? ' checked' : ''; ?>>
                                                            精选
                                                        </label>
                                                        <button class="button small" type="submit">保存</button>
                                                    </form>
                                                </td>
                                                <td>
                                                    <form class="inline-form" method="post"
                                                          action="card_pools.php?pool_id=<?php echo (int) $selectedPool['pool_id']; ?>">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="action" value="remove_entry">
                                                        <input type="hidden" name="pool_id"
                                                               value="<?php echo (int) $selectedPool['pool_id']; ?>">
                                                        <input type="hidden" name="resource_id"
                                                               value="<?php echo (int) $entry['resource_id']; ?>">
                                                        <button class="button danger small" type="submit"
                                                                onclick="return confirm('仅从卡池移除此成员，目录资源不会被停用。确认继续？');">
                                                            移除
                                                        </button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($poolEntries)): ?>
                                        <tr>
                                            <td colspan="<?php echo $canEditSelected ? 7 : 5; ?>"
                                                class="empty-state">
                                                卡池还没有成员；发布前至少需要增加一项。
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const typeField = document.getElementById('create_pool_type');
        const costField = document.getElementById('create_cost');
        const help = document.getElementById('create_cost_help');
        if (!typeField || !costField || !help) {
            return;
        }

        const defaults = {
            general: '{"bright": 100}',
            skill: '{"night": 100}'
        };
        let previousDefault = defaults[typeField.value] || defaults.general;

        function synchronizePoolCostRule() {
            const poolType = typeField.value === 'skill'
                ? 'skill'
                : 'general';
            const nextDefault = defaults[poolType];
            const currentCost = costField.value.trim();
            if (currentCost === '' || currentCost === previousDefault) {
                costField.value = nextDefault;
            }
            help.textContent = poolType === 'skill'
                ? '技能卡池只能使用 night（夜静静）。'
                : '武将卡池只能使用 bright（亮晶晶）。';
            previousDefault = nextDefault;
        }

        typeField.addEventListener('change', synchronizePoolCostRule);
        synchronizePoolCostRule();
    });
    </script>
</body>
</html>

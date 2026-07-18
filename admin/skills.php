<?php
// 种火集结号 - 技能卡目录管理页面 / Fireseed Engage - Skill-card catalog administration
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
if (!$adminManager->hasPermission('manage_skills')) {
    http_response_code(403);
    die('您没有权限访问此页面');
}

/**
 * 获取字符串长度并兼容未安装mbstring的环境 / Gets string length with an mbstring fallback
 *
 * @param string $value 待检查文本 / Text to inspect
 * @return int 字符数量 / Character count
 */
function adminSkillTextLength($value) {
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

/**
 * 将输入安全转换为字符串 / Safely converts input to a string
 *
 * @param mixed $value 输入值 / Input value
 * @return string 字符串值 / String value
 */
function adminSkillScalarText($value) {
    return is_scalar($value) ? (string) $value : '';
}

/**
 * 校验结构化等级曲线 / Validates structured level curves
 *
 * @param object $effectObject 技能效果对象 / Skill-effect object
 * @param int $maxLevel 技能最高等级 / Maximum skill level
 * @param string $activationType 发动类型 / Activation type
 * @return array 校验错误 / Validation errors
 */
function adminSkillValidateLevelCurves(
    $effectObject,
    $maxLevel,
    $activationType = 'passive'
) {
    $errors = [];
    foreach (get_object_vars($effectObject) as $effectKey => $effectValue) {
        if (is_array($effectValue)) {
            $errors[] = $effectKey . ' 必须是数值或等级曲线描述符对象';
            continue;
        }
        if (!is_object($effectValue)) {
            continue;
        }
        if ($activationType === 'active') {
            $errors[] = $effectKey . ' 的结构化等级曲线仅支持被动技能';
            continue;
        }

        $descriptor = get_object_vars($effectValue);
        if (!isset($descriptor['mode'])
            || !is_string($descriptor['mode'])
            || !in_array(
                $descriptor['mode'],
                ['level_values', 'cost_level_values'],
                true
            )
            || !isset($descriptor['values'])
            || !is_array($descriptor['values'])) {
            $errors[] = $effectKey . ' 的等级曲线描述符无效';
            continue;
        }
        if (count($descriptor['values']) < $maxLevel) {
            $errors[] = $effectKey . ' 的曲线长度必须覆盖最高等级';
            continue;
        }

        foreach ($descriptor['values'] as $curveValue) {
            if ((!is_int($curveValue) && !is_float($curveValue))
                || !is_finite((float) $curveValue)
                || (float) $curveValue < 0.0) {
                $errors[] = $effectKey . ' 的曲线值必须是非负有限数值';
                break;
            }
        }
    }

    return $errors;
}

/**
 * 验证并标准化技能卡目录输入 / Validates and normalizes skill-card catalog input
 *
 * @param array $input 表单输入 / Form input
 * @return array 包含数据和错误的数组 / Array containing data and errors
 */
function adminSkillValidateCatalogInput(array $input) {
    $rarities = ['B', 'A', 'S', 'SS', 'P'];
    $elements = ['亮晶晶', '暖洋洋', '冷冰冰', '郁萌萌', '昼闪闪', '夜静静'];
    $activationTypes = ['active', 'passive'];
    $categories = ['internal', 'march', 'attack', 'defense', 'support', 'special'];

    $cardCode = strtolower(trim(adminSkillScalarText($input['card_code'] ?? '')));
    $name = trim(str_replace(
        ["\r", "\n"],
        ' ',
        adminSkillScalarText($input['name'] ?? '')
    ));
    $description = trim(adminSkillScalarText($input['description'] ?? ''));
    $rarity = strtoupper(trim(adminSkillScalarText($input['rarity'] ?? '')));
    $element = trim(adminSkillScalarText($input['element'] ?? ''));
    $activationType = strtolower(trim(
        adminSkillScalarText($input['activation_type'] ?? '')
    ));
    $category = strtolower(trim(adminSkillScalarText($input['category'] ?? '')));
    $effectInput = trim(adminSkillScalarText($input['effect_json'] ?? ''));
    $cooldownInput = adminSkillScalarText($input['base_cooldown'] ?? '');
    $maxLevelInput = adminSkillScalarText($input['max_level'] ?? '');
    $isActiveInput = array_key_exists('is_active', $input)
        ? adminSkillScalarText($input['is_active'])
        : '0';
    $isActive = $isActiveInput === '1' ? 1 : 0;
    $errors = [];

    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $cardCode)) {
        $errors[] = '卡片代码须为1至64位小写字母、数字、下划线或连字符，并以字母或数字开头';
    }

    if (
        $name === ''
        || adminSkillTextLength($name) > 100
        || !preg_match('//u', $name)
    ) {
        $errors[] = '技能名称须为1至100个字符';
    }

    if (
        adminSkillTextLength($description) > 20000
        || strlen($description) > 60000
        || !preg_match('//u', $description)
    ) {
        $errors[] = '技能描述不能超过20000个字符';
    }

    if (!in_array($rarity, $rarities, true)) {
        $errors[] = '稀有度无效';
    }

    if (!in_array($element, $elements, true)) {
        $errors[] = '属性无效';
    }

    if (!in_array($activationType, $activationTypes, true)) {
        $errors[] = '发动类型无效';
    }

    if (!in_array($category, $categories, true)) {
        $errors[] = '技能分类无效';
    }

    if (!in_array($isActiveInput, ['0', '1'], true)) {
        $errors[] = '启用状态无效';
    }

    $baseCooldown = filter_var($cooldownInput, FILTER_VALIDATE_INT);
    if ($baseCooldown === false || $baseCooldown < 0 || $baseCooldown > 31536000) {
        $errors[] = '基础冷却须为0至31536000秒的整数';
    }

    $maxLevel = filter_var($maxLevelInput, FILTER_VALIDATE_INT);
    if ($maxLevel === false || $maxLevel < 1 || $maxLevel > 100) {
        $errors[] = '最高等级须为1至100的整数';
    }

    $effectJson = '';
    if ($effectInput === '' || strlen($effectInput) > 10000) {
        $errors[] = '效果JSON须为1至10000字节';
    } else {
        $effectObject = json_decode($effectInput);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($effectObject)) {
            $errors[] = '效果JSON必须是有效的JSON对象，例如 {"attack":10}';
        } else {
            $effectJson = json_encode(
                $effectObject,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($effectJson === false) {
                $errors[] = '效果JSON无法保存';
            }
            if ($maxLevel !== false
                && $maxLevel >= 1
                && $maxLevel <= 100) {
                $errors = array_merge(
                    $errors,
                    adminSkillValidateLevelCurves(
                        $effectObject,
                        (int) $maxLevel,
                        $activationType
                    )
                );
            }
        }
    }

    return [
        'data' => [
            'card_code' => $cardCode,
            'name' => $name,
            'description' => $description,
            'rarity' => $rarity,
            'element' => $element,
            'activation_type' => $activationType,
            'category' => $category,
            'effect_json' => $effectJson,
            'base_cooldown' => $baseCooldown === false ? 0 : $baseCooldown,
            'max_level' => $maxLevel === false ? 1 : $maxLevel,
            'is_active' => $isActive
        ],
        'errors' => $errors
    ];
}

/**
 * 锁定引用技能卡的全部卡池并返回已发布池 / Locks every referencing pool and returns published pools
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param int $cardId 技能卡ID / Skill-card ID
 * @return array 已发布卡池 / Published pools
 */
function adminSkillLoadPublishedPoolsForUpdate($db, $cardId) {
    $query = "SELECT pool.pool_id, pool.name, pool.revision, pool.status
              FROM card_pools pool
              JOIN skill_pool_entries entry
                ON entry.pool_id = pool.pool_id
              WHERE entry.card_id = ?
              ORDER BY pool.pool_id
              FOR UPDATE";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法锁定技能卡所属的已发布卡池');
    }
    $stmt->bind_param('i', $cardId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法锁定技能卡所属的已发布卡池');
    }

    $result = $stmt->get_result();
    $pools = [];
    while ($result && ($pool = $result->fetch_assoc())) {
        if ($pool['status'] === 'published') {
            $pools[] = $pool;
        }
    }
    $stmt->close();

    return $pools;
}

/**
 * 锁定并读取技能卡目录行 / Locks and reads a skill-card catalog row
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param int $cardId 技能卡ID / Skill-card ID
 * @return array|null 技能卡数据 / Skill-card data
 */
function adminSkillLoadCardForUpdate($db, $cardId) {
    $stmt = $db->prepare(
        'SELECT card_id, rarity, is_active
         FROM skill_card_catalog
         WHERE card_id = ?
         LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('无法锁定技能卡记录');
    }
    $stmt->bind_param('i', $cardId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法锁定技能卡记录');
    }

    $card = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $card ?: null;
}

/**
 * 递增受目录稀有度变化影响的卡池修订号 / Increments revisions for pools affected by a catalog-rarity change
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param array $pools 已锁定卡池 / Locked pools
 * @param int $adminId 管理员ID / Administrator ID
 * @return void
 */
function adminSkillTouchPublishedPools($db, array $pools, $adminId) {
    if (empty($pools)) {
        return;
    }

    $query = "UPDATE card_pools
              SET revision = revision + 1,
                  updated_by = ?
              WHERE pool_id = ?
                AND status = 'published'";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法更新技能卡所属卡池的修订号');
    }

    foreach ($pools as $pool) {
        $poolId = (int) $pool['pool_id'];
        $stmt->bind_param('ii', $adminId, $poolId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('无法更新技能卡所属卡池的修订号');
        }
    }
    $stmt->close();
}

/**
 * 构建保留搜索条件的分页链接 / Builds a pagination link preserving search
 *
 * @param int $page 页码 / Page number
 * @param string $search 搜索文本 / Search text
 * @return string 页面链接 / Page URL
 */
function adminSkillPageUrl($page, $search) {
    $query = ['page' => max(1, (int) $page)];
    if ($search !== '') {
        $query['search'] = $search;
    }

    return 'skills.php?' . http_build_query($query);
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        http_response_code(403);
        $error = '请求校验失败，请刷新页面后重试';
    } else {
        $action = adminSkillScalarText($_POST['action'] ?? '');
        $transactionOpen = false;

        try {
            if ($action === 'create_skill') {
                if (!$adminManager->hasPermission('create_skills')) {
                    throw new DomainException('您没有权限创建技能卡');
                }

                $validated = adminSkillValidateCatalogInput($_POST);
                if (!empty($validated['errors'])) {
                    throw new InvalidArgumentException(
                        implode('；', $validated['errors'])
                    );
                }

                $card = $validated['data'];
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始技能卡创建事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);
                $stmt = $db->prepare(
                    'INSERT INTO skill_card_catalog
                     (card_code, name, description, rarity, element,
                      activation_type, category, effect_json, base_cooldown,
                      max_level, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new RuntimeException('无法准备技能卡创建操作');
                }

                $stmt->bind_param(
                    'ssssssssiii',
                    $card['card_code'],
                    $card['name'],
                    $card['description'],
                    $card['rarity'],
                    $card['element'],
                    $card['activation_type'],
                    $card['category'],
                    $card['effect_json'],
                    $card['base_cooldown'],
                    $card['max_level'],
                    $card['is_active']
                );
                if (!$stmt->execute()) {
                    $statementError = $stmt->errno;
                    $stmt->close();
                    if ($statementError === 1062) {
                        throw new DomainException('卡片代码已存在');
                    }
                    throw new RuntimeException('技能卡创建失败');
                }

                $cardId = (int) $db->insert_id;
                $stmt->close();
                if (!$db->commit()) {
                    throw new RuntimeException('技能卡创建事务提交失败');
                }
                $transactionOpen = false;
                $success = '技能卡创建成功';
                $user->logAdminAction(
                    'create_skill_card',
                    'skill_card',
                    $cardId,
                    'Card code: ' . $card['card_code']
                );
            } elseif ($action === 'update_skill') {
                if (!$adminManager->hasPermission('edit_skills')) {
                    throw new DomainException('您没有权限编辑技能卡');
                }

                $cardId = filter_var(
                    adminSkillScalarText($_POST['card_id'] ?? ''),
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($cardId === false) {
                    throw new DomainException('技能卡不存在');
                }

                $validated = adminSkillValidateCatalogInput($_POST);
                if (!empty($validated['errors'])) {
                    throw new InvalidArgumentException(
                        implode('；', $validated['errors'])
                    );
                }

                $card = $validated['data'];
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始技能卡更新事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);
                $publishedPools = adminSkillLoadPublishedPoolsForUpdate(
                    $db,
                    $cardId
                );
                $existingCard = adminSkillLoadCardForUpdate($db, $cardId);
                if (!$existingCard) {
                    throw new DomainException('技能卡不存在');
                }

                $rarityChanged = (string) $existingCard['rarity']
                    !== (string) $card['rarity'];
                $activeStatusChanged = (int) $existingCard['is_active']
                    !== (int) $card['is_active'];
                if ($activeStatusChanged
                    && !$adminManager->hasPermission('delete_skills')) {
                    throw new DomainException(
                        '改变技能卡启用状态需要停用技能权限'
                    );
                }
                if ($activeStatusChanged && !empty($publishedPools)) {
                    throw new DomainException(
                        '该技能卡仍属于已发布卡池；请先从这些卡池移除或归档卡池'
                    );
                }
                if ($rarityChanged
                    && !empty($publishedPools)
                    && !$adminManager->hasPermission('publish_card_pools')) {
                    throw new DomainException(
                        '该技能卡属于已发布卡池；修改稀有度需要卡池发布权限'
                    );
                }

                $stmt = $db->prepare(
                    'UPDATE skill_card_catalog
                     SET card_code = ?, name = ?, description = ?, rarity = ?,
                         element = ?, activation_type = ?, category = ?,
                         effect_json = ?, base_cooldown = ?, max_level = ?,
                         is_active = ?
                     WHERE card_id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException('无法准备技能卡更新操作');
                }

                $stmt->bind_param(
                    'ssssssssiiii',
                    $card['card_code'],
                    $card['name'],
                    $card['description'],
                    $card['rarity'],
                    $card['element'],
                    $card['activation_type'],
                    $card['category'],
                    $card['effect_json'],
                    $card['base_cooldown'],
                    $card['max_level'],
                    $card['is_active'],
                    $cardId
                );
                if (!$stmt->execute()) {
                    $statementError = $stmt->errno;
                    $stmt->close();
                    if ($statementError === 1062) {
                        throw new DomainException('卡片代码已存在');
                    }
                    throw new RuntimeException('技能卡更新失败');
                }

                $stmt->close();
                if ($rarityChanged && !empty($publishedPools)) {
                    adminSkillTouchPublishedPools(
                        $db,
                        $publishedPools,
                        (int) $user->getUserId()
                    );
                }
                if (!$db->commit()) {
                    throw new RuntimeException('技能卡更新事务提交失败');
                }
                $transactionOpen = false;
                $success = '技能卡更新成功';
                $user->logAdminAction(
                    'update_skill_card',
                    'skill_card',
                    $cardId,
                    'Card code: ' . $card['card_code']
                );
                if ($rarityChanged) {
                    foreach ($publishedPools as $pool) {
                        $user->logAdminAction(
                            'revise_card_pool_from_skill_catalog',
                            'card_pool',
                            (int) $pool['pool_id'],
                            'Skill-card rarity changed: ' . $cardId
                        );
                    }
                }
            } elseif ($action === 'disable_skill') {
                if (!$adminManager->hasPermission('delete_skills')) {
                    throw new DomainException('您没有权限停用技能卡');
                }

                $cardId = filter_var(
                    adminSkillScalarText($_POST['card_id'] ?? ''),
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($cardId === false) {
                    throw new DomainException('技能卡不存在');
                }

                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始技能卡停用事务');
                }
                $transactionOpen = true;
                lockResourceAdministrationBoundary($db);
                $publishedPools = adminSkillLoadPublishedPoolsForUpdate(
                    $db,
                    $cardId
                );
                $existingCard = adminSkillLoadCardForUpdate($db, $cardId);
                if (!$existingCard) {
                    throw new DomainException('技能卡不存在');
                }
                if (!empty($publishedPools)) {
                    throw new DomainException(
                        '该技能卡仍属于已发布卡池；请先从这些卡池移除或归档卡池'
                    );
                }

                $stmt = $db->prepare(
                    'UPDATE skill_card_catalog SET is_active = 0 WHERE card_id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException('无法准备技能卡停用操作');
                }

                $stmt->bind_param('i', $cardId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('技能卡停用失败');
                }

                $stmt->close();
                if (!$db->commit()) {
                    throw new RuntimeException('技能卡停用事务提交失败');
                }
                $transactionOpen = false;
                $success = '技能卡已停用，玩家持有的卡片和装备记录均已保留';
                $user->logAdminAction(
                    'disable_skill_card',
                    'skill_card',
                    $cardId,
                    'Catalog card disabled'
                );
            } else {
                throw new InvalidArgumentException('未知的技能卡操作');
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
            error_log('admin/skills.php failed: ' . $exception->getMessage());
            $error = '技能卡操作失败，请检查数据库状态后重试';
        }
    }
}

$rawSearch = adminSkillScalarText($_GET['search'] ?? '');
$search = trim(str_replace(["\r", "\n"], ' ', $rawSearch));
if (adminSkillTextLength($search) > 100) {
    $search = function_exists('mb_substr')
        ? mb_substr($search, 0, 100, 'UTF-8')
        : substr($search, 0, 100);
}

$requestedPage = filter_var(
    adminSkillScalarText($_GET['page'] ?? '1'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$page = $requestedPage === false ? 1 : $requestedPage;
$limit = 20;
$totalCount = 0;
$skills = [];
$whereClause = '';
$searchLike = '';

if ($search !== '') {
    $whereClause = ' WHERE card_code LIKE ? OR name LIKE ? OR description LIKE ?';
    $searchLike = '%' . $search . '%';
}

try {
    $countStmt = $db->prepare(
        'SELECT COUNT(*) AS total FROM skill_card_catalog' . $whereClause
    );
    if (!$countStmt) {
        throw new RuntimeException('无法准备技能卡数量查询');
    }

    if ($search !== '') {
        $countStmt->bind_param(
            'sss',
            $searchLike,
            $searchLike,
            $searchLike
        );
    }

    if (!$countStmt->execute()) {
        $countStmt->close();
        throw new RuntimeException('无法读取技能卡数量');
    }

    $countRow = $countStmt->get_result()->fetch_assoc();
    $totalCount = (int) ($countRow['total'] ?? 0);
    $countStmt->close();

    $totalPages = max(1, (int) ceil($totalCount / $limit));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $limit;

    $listStmt = $db->prepare(
        'SELECT card_id, card_code, name, description, rarity, element,
                activation_type, category, effect_json, base_cooldown,
                max_level, is_active
         FROM skill_card_catalog'
        . $whereClause
        . " ORDER BY FIELD(rarity, 'P', 'SS', 'S', 'A', 'B'),
                    name, card_id
            LIMIT ? OFFSET ?"
    );
    if (!$listStmt) {
        throw new RuntimeException('无法准备技能卡列表查询');
    }

    if ($search !== '') {
        $listStmt->bind_param(
            'sssii',
            $searchLike,
            $searchLike,
            $searchLike,
            $limit,
            $offset
        );
    } else {
        $listStmt->bind_param('ii', $limit, $offset);
    }

    if (!$listStmt->execute()) {
        $listStmt->close();
        throw new RuntimeException('无法读取技能卡列表');
    }

    $listResult = $listStmt->get_result();
    while ($listResult && ($row = $listResult->fetch_assoc())) {
        $skills[] = $row;
    }
    $listStmt->close();
} catch (Throwable $exception) {
    error_log('admin/skills.php catalog read failed: ' . $exception->getMessage());
    if ($error === '') {
        $error = '无法读取技能卡目录，请确认玩法扩展数据库迁移已执行';
    }
    $totalPages = 1;
}

$rarityLabels = [
    'B' => 'B',
    'A' => 'A',
    'S' => 'S',
    'SS' => 'SS',
    'P' => 'P'
];
$elementLabels = [
    '亮晶晶' => '亮晶晶',
    '暖洋洋' => '暖洋洋',
    '冷冰冰' => '冷冰冰',
    '郁萌萌' => '郁萌萌',
    '昼闪闪' => '昼闪闪',
    '夜静静' => '夜静静'
];
$activationLabels = [
    'active' => '主动',
    'passive' => '被动'
];
$categoryLabels = [
    'internal' => '内政',
    'march' => '行军',
    'attack' => '攻击',
    'defense' => '防御',
    'support' => '支援',
    'special' => '特殊'
];
$pageTitle = '技能卡目录管理';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml(SITE_NAME); ?> - <?php echo escapeHtml($pageTitle); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-container { max-width: 1500px; margin: 0 auto; padding: 20px; }
        .admin-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding: 20px; border-radius: 8px;
            color: #fff; background: linear-gradient(135deg, #1abc9c, #16a085);
        }
        .admin-header h1 { margin: 0; font-size: 24px; }
        .back-link, .button {
            display: inline-block; padding: 9px 14px; border: 0; border-radius: 5px;
            color: #fff; text-decoration: none; cursor: pointer; font-weight: 700;
        }
        .back-link { background: rgba(255, 255, 255, .2); }
        .button-primary { background: #1abc9c; }
        .button-create { background: #27ae60; }
        .button-disable { background: #c0392b; }
        .panel {
            margin-bottom: 20px; padding: 20px; border-radius: 8px;
            background: #fff; box-shadow: 0 2px 4px rgba(0, 0, 0, .1);
        }
        .search-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .search-form input { flex: 1; min-width: 220px; }
        .form-control {
            width: 100%; padding: 9px; border: 1px solid #dfe6e9;
            border-radius: 5px; box-sizing: border-box;
        }
        .message { margin-bottom: 20px; padding: 14px; border-radius: 6px; }
        .message-error { color: #a92323; background: #ffebee; border-left: 4px solid #c62828; }
        .message-success { color: #236b2c; background: #e8f5e9; border-left: 4px solid #2e7d32; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px; border-bottom: 1px solid #ecf0f1; text-align: left; vertical-align: top; }
        th { white-space: nowrap; color: #2c3e50; background: #f8f9fa; }
        .muted { color: #7f8c8d; }
        .effect-preview {
            display: block; max-width: 300px; max-height: 75px; overflow: auto;
            white-space: pre-wrap; overflow-wrap: anywhere;
        }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .badge-active { color: #17642a; background: #d8f3dc; }
        .badge-disabled { color: #6c757d; background: #eceff1; }
        .row-actions { display: flex; gap: 6px; align-items: center; }
        .row-actions form { margin: 0; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .pagination a, .pagination span {
            padding: 7px 11px; border: 1px solid #ddd; border-radius: 4px;
            color: #2c3e50; text-decoration: none;
        }
        .pagination .current { color: #fff; border-color: #1abc9c; background: #1abc9c; }
        .modal {
            display: none; position: fixed; z-index: 1000; inset: 0;
            padding: 30px 12px; overflow-y: auto; background: rgba(0, 0, 0, .55);
        }
        .modal-content {
            width: min(760px, 100%); margin: 0 auto; padding: 22px;
            border-radius: 8px; box-sizing: border-box; background: #fff;
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .modal-header h2 { margin: 0; }
        .modal-close { border: 0; color: #666; background: transparent; cursor: pointer; font-size: 28px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; margin-bottom: 5px; color: #2c3e50; font-weight: 700; }
        .form-wide { grid-column: 1 / -1; }
        textarea.form-control { min-height: 100px; resize: vertical; }
        .checkbox-row { display: flex; gap: 8px; align-items: center; }
        .checkbox-row input { width: auto; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
        @media (max-width: 700px) {
            .admin-header { align-items: flex-start; gap: 12px; flex-direction: column; }
            .form-grid { grid-template-columns: 1fr; }
            .form-wide { grid-column: auto; }
        }
    </style>
</head>
<body>
    <div class="container">
        <main class="admin-container">
            <header class="admin-header">
                <div>
                    <h1>✨ 技能卡目录管理</h1>
                    <div>编辑抽取、装备与发动系统使用的通用技能卡</div>
                </div>
                <a href="index.php" class="back-link">← 返回管理后台</a>
            </header>

            <?php if ($error !== ''): ?>
                <div class="message message-error"><?php echo escapeHtml($error); ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="message message-success"><?php echo escapeHtml($success); ?></div>
            <?php endif; ?>

            <section class="panel">
                <form class="search-form" method="get" action="skills.php">
                    <input
                        class="form-control"
                        type="search"
                        name="search"
                        maxlength="100"
                        placeholder="搜索卡片代码、名称或描述"
                        value="<?php echo escapeHtml($search); ?>"
                    >
                    <button type="submit" class="button button-primary">搜索</button>
                    <?php if ($search !== ''): ?>
                        <a href="skills.php" class="button button-primary">清除</a>
                    <?php endif; ?>
                    <?php if ($adminManager->hasPermission('create_skills')): ?>
                        <button type="button" id="createCardButton" class="button button-create">+ 创建技能卡</button>
                    <?php endif; ?>
                </form>
            </section>

            <section class="panel">
                <h2>目录列表（共 <?php echo number_format($totalCount); ?> 张）</h2>
                <?php if (!empty($skills)): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID / 代码</th>
                                    <th>名称 / 描述</th>
                                    <th>稀有度 / 属性</th>
                                    <th>类型 / 分类</th>
                                    <th>效果JSON</th>
                                    <th>冷却 / 最高等级</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($skills as $skill): ?>
                                <tr>
                                    <td>
                                        <strong>#<?php echo (int) $skill['card_id']; ?></strong><br>
                                        <code><?php echo escapeHtml($skill['card_code']); ?></code>
                                    </td>
                                    <td>
                                        <strong><?php echo escapeHtml($skill['name']); ?></strong><br>
                                        <span class="muted"><?php echo escapeHtml($skill['description']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo escapeHtml($rarityLabels[$skill['rarity']] ?? $skill['rarity']); ?><br>
                                        <span class="muted"><?php echo escapeHtml($elementLabels[$skill['element']] ?? $skill['element']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo escapeHtml($activationLabels[$skill['activation_type']] ?? $skill['activation_type']); ?><br>
                                        <span class="muted"><?php echo escapeHtml($categoryLabels[$skill['category']] ?? $skill['category']); ?></span>
                                    </td>
                                    <td><code class="effect-preview"><?php echo escapeHtml($skill['effect_json']); ?></code></td>
                                    <td>
                                        <?php echo number_format((int) $skill['base_cooldown']); ?> 秒<br>
                                        <span class="muted">Lv.<?php echo (int) $skill['max_level']; ?></span>
                                    </td>
                                    <td>
                                        <?php if ((int) $skill['is_active'] === 1): ?>
                                            <span class="badge badge-active">启用</span>
                                        <?php else: ?>
                                            <span class="badge badge-disabled">停用</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <?php if ($adminManager->hasPermission('edit_skills')): ?>
                                                <button
                                                    type="button"
                                                    class="button button-primary edit-card-button"
                                                    data-card-id="<?php echo (int) $skill['card_id']; ?>"
                                                >编辑</button>
                                            <?php endif; ?>
                                            <?php if (
                                                (int) $skill['is_active'] === 1
                                                && $adminManager->hasPermission('delete_skills')
                                            ): ?>
                                                <form method="post" action="skills.php" class="disable-card-form">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="disable_skill">
                                                    <input type="hidden" name="card_id" value="<?php echo (int) $skill['card_id']; ?>">
                                                    <button type="submit" class="button button-disable">停用</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination" aria-label="技能卡目录分页">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo escapeHtml(adminSkillPageUrl($page - 1, $search)); ?>">上一页</a>
                            <?php endif; ?>
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            ?>
                            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                                <?php if ($pageNumber === $page): ?>
                                    <span class="current"><?php echo $pageNumber; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo escapeHtml(adminSkillPageUrl($pageNumber, $search)); ?>"><?php echo $pageNumber; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo escapeHtml(adminSkillPageUrl($page + 1, $search)); ?>">下一页</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="muted"><?php echo $search !== '' ? '未找到匹配的技能卡' : '目录中暂无技能卡'; ?></p>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <div id="skillCardModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">创建技能卡</h2>
                <button type="button" id="closeModalButton" class="modal-close" aria-label="关闭">&times;</button>
            </div>
            <form id="skillCardForm" method="post" action="skills.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" id="formAction" value="create_skill">
                <input type="hidden" name="card_id" id="cardId" value="">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="cardCode">卡片代码</label>
                        <input class="form-control" type="text" id="cardCode" name="card_code" maxlength="64" pattern="[a-z0-9][a-z0-9_-]{0,63}" required>
                    </div>
                    <div class="form-group">
                        <label for="cardName">技能名称</label>
                        <input class="form-control" type="text" id="cardName" name="name" maxlength="100" required>
                    </div>
                    <div class="form-group form-wide">
                        <label for="cardDescription">技能描述</label>
                        <textarea class="form-control" id="cardDescription" name="description" maxlength="20000"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="cardRarity">稀有度</label>
                        <select class="form-control" id="cardRarity" name="rarity" required>
                            <?php foreach ($rarityLabels as $value => $label): ?>
                                <option value="<?php echo escapeHtml($value); ?>"><?php echo escapeHtml($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="cardElement">属性</label>
                        <select class="form-control" id="cardElement" name="element" required>
                            <?php foreach ($elementLabels as $value => $label): ?>
                                <option value="<?php echo escapeHtml($value); ?>"><?php echo escapeHtml($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="activationType">发动类型</label>
                        <select class="form-control" id="activationType" name="activation_type" required>
                            <?php foreach ($activationLabels as $value => $label): ?>
                                <option value="<?php echo escapeHtml($value); ?>"><?php echo escapeHtml($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="cardCategory">分类</label>
                        <select class="form-control" id="cardCategory" name="category" required>
                            <?php foreach ($categoryLabels as $value => $label): ?>
                                <option value="<?php echo escapeHtml($value); ?>"><?php echo escapeHtml($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group form-wide">
                        <label for="effectJson">效果JSON对象</label>
                        <textarea class="form-control" id="effectJson" name="effect_json" maxlength="10000" spellcheck="false" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="baseCooldown">基础冷却（秒）</label>
                        <input class="form-control" type="number" id="baseCooldown" name="base_cooldown" min="0" max="31536000" step="1" required>
                    </div>
                    <div class="form-group">
                        <label for="maxLevel">最高等级</label>
                        <input class="form-control" type="number" id="maxLevel" name="max_level" min="1" max="100" step="1" required>
                    </div>
                    <div class="form-group form-wide checkbox-row">
                        <input type="checkbox" id="isActive" name="is_active" value="1">
                        <label for="isActive">启用技能卡（停用后不会再被抽取或新装备）</label>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" id="cancelModalButton" class="button button-primary">取消</button>
                    <button type="submit" class="button button-create">保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('skillCardModal');
            const form = document.getElementById('skillCardForm');
            const createButton = document.getElementById('createCardButton');
            const closeButton = document.getElementById('closeModalButton');
            const cancelButton = document.getElementById('cancelModalButton');

            // 打开已重置的创建表单 / Open a reset create form
            function openCreateModal() {
                form.reset();
                document.getElementById('modalTitle').textContent = '创建技能卡';
                document.getElementById('formAction').value = 'create_skill';
                document.getElementById('cardId').value = '';
                document.getElementById('cardRarity').value = 'B';
                document.getElementById('cardElement').value = '亮晶晶';
                document.getElementById('activationType').value = 'passive';
                document.getElementById('cardCategory').value = 'internal';
                document.getElementById('effectJson').value = '{"attack":10}';
                document.getElementById('baseCooldown').value = '0';
                document.getElementById('maxLevel').value = '5';
                document.getElementById('isActive').checked = true;
                modal.style.display = 'block';
            }

            // 从受权限保护的接口载入编辑数据 / Load edit data from the permission-protected endpoint
            function openEditModal(cardId) {
                fetch('../api/get_skill.php?card_id=' + encodeURIComponent(cardId), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(response) {
                    return response.json().then(function(data) {
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || '获取技能卡信息失败');
                        }
                        return data;
                    });
                })
                .then(function(data) {
                    const card = data.card;
                    document.getElementById('modalTitle').textContent = '编辑技能卡';
                    document.getElementById('formAction').value = 'update_skill';
                    document.getElementById('cardId').value = String(card.card_id);
                    document.getElementById('cardCode').value = card.card_code;
                    document.getElementById('cardName').value = card.name;
                    document.getElementById('cardDescription').value = card.description;
                    document.getElementById('cardRarity').value = card.rarity;
                    document.getElementById('cardElement').value = card.element;
                    document.getElementById('activationType').value = card.activation_type;
                    document.getElementById('cardCategory').value = card.category;
                    document.getElementById('effectJson').value = JSON.stringify(card.effect, null, 2);
                    document.getElementById('baseCooldown').value = String(card.base_cooldown);
                    document.getElementById('maxLevel').value = String(card.max_level);
                    document.getElementById('isActive').checked = Number(card.is_active) === 1;
                    modal.style.display = 'block';
                })
                .catch(function(error) {
                    window.alert(error.message || '获取技能卡信息失败');
                });
            }

            function closeModal() {
                modal.style.display = 'none';
            }

            if (createButton) {
                createButton.addEventListener('click', openCreateModal);
            }
            closeButton.addEventListener('click', closeModal);
            cancelButton.addEventListener('click', closeModal);

            document.querySelectorAll('.edit-card-button').forEach(function(button) {
                button.addEventListener('click', function() {
                    openEditModal(button.getAttribute('data-card-id'));
                });
            });

            document.querySelectorAll('.disable-card-form').forEach(function(disableForm) {
                disableForm.addEventListener('submit', function(event) {
                    if (!window.confirm('确定停用这张技能卡吗？玩家已持有和已装备的数据会保留。')) {
                        event.preventDefault();
                    }
                });
            });

            modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                    closeModal();
                }
            });
        });
    </script>
</body>
</html>
